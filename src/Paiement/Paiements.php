<?php

declare(strict_types=1);

namespace App\Paiement;

use App\Entity\Echeance;
use App\Entity\Cotisation;
use App\Entity\AppelContribution;
use App\Entity\Membre;
use App\Entity\MoyenPaiement;
use App\Entity\Paiement;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Journal\Journal;
use App\Repository\EcheanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Paiements manuels (F-17) : le trésorier ou le bureau central enregistre ce qu'un membre a versé en espèces, par
 * virement, chèque ou carte, sur une ou plusieurs de ses échéances dues. Chaque échéance passe à « payée », la case
 * du classeur se remplit pour une mensualité de cotisation, le membre reçoit un reçu par e-mail (F-18), et la part du
 * central s'ajoute au dû de la ville (F-20). Une erreur s'annule : le paiement reste dans l'historique, ses échéances
 * redeviennent dues.
 */
final class Paiements
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EcheanceRepository $echeances,
        private readonly Journal $journal,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param list<Echeance>  $echeances      les échéances dues du membre à solder, dans l'ordre choisi
     * @param array<int, int> $montantsLibres centimes par identifiant d'échéance pour les montants libres
     *
     * @return array{paiement: Paiement, recu: bool}
     */
    public function enregistrer(Membre $membre, array $echeances, MoyenPaiement $moyen, \DateTimeImmutable $recuLe, ?string $reference, ?string $note, ?Utilisateur $acteur, \DateTimeImmutable $quand, array $montantsLibres = []): array
    {
        if ([] === $echeances) {
            throw new \InvalidArgumentException('Choisissez au moins une échéance à payer.');
        }
        $total = 0;
        foreach ($echeances as $echeance) {
            if ($echeance->getMembre() !== $membre) {
                throw new \InvalidArgumentException('Une échéance appartient à un autre membre.');
            }
            if (!$echeance->estDue()) {
                throw new \LogicException('Une échéance choisie n\'est plus due.');
            }
            $montant = $montantsLibres[(int) $echeance->getId()] ?? $echeance->getMontant();
            if (null === $montant || $montant <= 0) {
                throw new \InvalidArgumentException('Indiquez le montant versé pour chaque échéance à montant libre.');
            }
            $total += $montant;
        }

        $paiement = new Paiement($membre, $total, $moyen, $recuLe, $reference, $note, $acteur, $quand);
        $this->entityManager->persist($paiement);
        $details = [];
        foreach ($echeances as $echeance) {
            $montant = $montantsLibres[(int) $echeance->getId()] ?? null;
            $echeance->payer($paiement, $quand, $montant);
            $paiement->getEcheances()->add($echeance);
            $this->synchroniserClasseur($echeance, $echeance->getMontant());
            $details[] = self::libelle($echeance);
        }
        $this->journal->consigner(TypeEvenement::PaiementEnregistre, $acteur, $membre->getAssociation(), $membre->getNomComplet(), [
            'ville' => $membre->getVille()->getNom(),
            'membre' => $membre->getNomComplet(),
            'montant' => $total,
            'moyen' => $moyen->value,
            'recu_le' => $recuLe->format('Y-m-d'),
            'reference' => $paiement->getReference(),
            'echeances' => $details,
        ], $quand);
        $this->entityManager->flush();

        $recu = false;
        if (null !== $membre->getEmail() && $membre->aConsentiEmail()) {
            $this->envoyerRecu($paiement);
            $recu = true;
        }

        return ['paiement' => $paiement, 'recu' => $recu];
    }

    /**
     * Le même paiement pour plusieurs membres d'un coup (la réunion mensuelle : vingt personnes qui règlent le mois) :
     * pour chaque membre, ses échéances dues qui correspondent aux cibles choisies (des mois d'une cotisation, des
     * appels) sont soldées par un paiement à lui ; un membre qui ne doit rien parmi ces cibles est ignoré, jamais
     * facturé deux fois. Les échéances à montant libre ne se règlent qu'une par une.
     *
     * @param list<Membre>                                                                                   $membres
     * @param list<array{type: 'cotisation', cotisation: Cotisation, mois: int}|array{type: 'appel', appel: AppelContribution}> $cibles
     *
     * @return array{paiements: int, montant: int, recus: int, ignores: list<string>}
     */
    public function enregistrerGroupe(array $membres, array $cibles, MoyenPaiement $moyen, \DateTimeImmutable $recuLe, ?string $reference, ?string $note, ?Utilisateur $acteur, \DateTimeImmutable $quand): array
    {
        $bilan = ['paiements' => 0, 'montant' => 0, 'recus' => 0, 'ignores' => []];
        foreach ($membres as $membre) {
            $choisies = array_values(array_filter(
                $this->echeances->duesPourMembre($membre),
                static fn (Echeance $e): bool => null !== $e->getMontant() && self::correspond($e, $cibles),
            ));
            if ([] === $choisies) {
                $bilan['ignores'][] = $membre->getNomComplet();
                continue;
            }
            $resultat = $this->enregistrer($membre, $choisies, $moyen, $recuLe, $reference, $note, $acteur, $quand);
            ++$bilan['paiements'];
            $bilan['montant'] += $resultat['paiement']->getMontant();
            if ($resultat['recu']) {
                ++$bilan['recus'];
            }
        }

        return $bilan;
    }

    /** @param list<array<string, mixed>> $cibles */
    private static function correspond(Echeance $echeance, array $cibles): bool
    {
        foreach ($cibles as $cible) {
            if ('cotisation' === $cible['type'] && $echeance->getCotisation() === $cible['cotisation'] && $echeance->getMois() === $cible['mois']) {
                return true;
            }
            if ('appel' === $cible['type'] && null !== $echeance->getAppel() && $echeance->getAppel() === $cible['appel']) {
                return true;
            }
        }

        return false;
    }

    /** Saisie erronée : le paiement est annulé, ses échéances redeviennent dues, les cases du classeur se vident. */
    public function annuler(Paiement $paiement, ?Utilisateur $acteur, \DateTimeImmutable $quand, ?string $motif = null): void
    {
        $paiement->annuler($quand);
        foreach ($paiement->getEcheances() as $echeance) {
            $echeance->remettreDue();
            $this->synchroniserClasseur($echeance, null);
        }
        $this->journal->consigner(TypeEvenement::PaiementAnnule, $acteur, $paiement->getAssociation(), $paiement->getMembre()->getNomComplet(), [
            'ville' => $paiement->getVille()->getNom(),
            'membre' => $paiement->getMembre()->getNomComplet(),
            'montant' => $paiement->getMontant(),
            'motif' => $motif,
        ], $quand);
        $this->entityManager->flush();
    }

    /** @return list<Echeance> */
    public function duesPour(Membre $membre): array
    {
        return $this->echeances->duesPourMembre($membre);
    }

    /** Une mensualité de cotisation payée (ou annulée) se reflète dans la ligne du classeur de l'adhérent. */
    private function synchroniserClasseur(Echeance $echeance, ?int $montant): void
    {
        $cotisation = $echeance->getCotisation();
        if (null === $cotisation || null === $echeance->getMois()) {
            return;
        }
        $adhesion = $echeance->getMembre()->adherer($cotisation->getAnnee());
        if (null === $adhesion->getId()) {
            $this->entityManager->persist($adhesion);
        }
        $adhesion->renseignerMois($echeance->getMois(), $montant);
    }

    private function envoyerRecu(Paiement $paiement): void
    {
        $membre = $paiement->getMembre();
        $this->mailer->send((new TemplatedEmail())
            ->to(new Address((string) $membre->getEmail(), $membre->getNomComplet()))
            ->subject(\sprintf('Reçu de paiement · %s', $membre->getAssociation()->getNom()))
            ->htmlTemplate('emails/recu.html.twig')
            ->textTemplate('emails/recu.txt.twig')
            ->context([
                'paiement' => $paiement,
                'prenom' => $membre->getPrenom(),
                'lien' => $this->urls->generate('connexion', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
    }

    /** @return array{type: string, libelle: string, montant: ?int} */
    public static function libelle(Echeance $echeance): array
    {
        $appel = $echeance->getAppel();
        if (null !== $appel) {
            return ['type' => 'appel', 'libelle' => $appel->getObjet(), 'montant' => $echeance->getMontant()];
        }

        return ['type' => 'cotisation', 'libelle' => \sprintf('%02d/%04d', $echeance->getMois(), $echeance->getAnnee()), 'montant' => $echeance->getMontant()];
    }
}
