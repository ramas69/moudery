<?php

declare(strict_types=1);

namespace App\Appel;

use App\Entity\AppelContribution;
use App\Entity\Association;
use App\Entity\Echeance;
use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\ModeMontant;
use App\Entity\TypeContribution;
use App\Entity\TypeEvenement;
use App\Entity\UniteContribution;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Form\Model\AppelData;
use App\Journal\Journal;
use App\Repository\TypeContributionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les appels à contribution du bureau central (F-11, F-13) : types de l'association, membres concernés, chiffres de
 * « Ce qui va se passer », enregistrement du brouillon et ouverture (une échéance par membre ou payeur de foyer, un
 * e-mail à chacun de ceux qui ont une adresse et ont consenti). Rien n'est envoyé tant que l'appel est en brouillon.
 *
 * @phpstan-type Compteurs array{membres: int, emails: int}
 * @phpstan-type Statistiques array<string, array{personne: Compteurs, foyer: Compteurs}>
 */
final class Appels
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TypeContributionRepository $typesRepository,
        private readonly Journal $journal,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    /**
     * Les types de contribution de l'association, dans l'ordre ; les quatre types par défaut sont créés au premier usage.
     *
     * @return list<TypeContribution>
     */
    public function types(Association $association): array
    {
        $tous = $this->typesRepository->listerPour($association);
        if ([] !== $tous) {
            // Seuls les types actifs se proposent pour un nouvel appel (F-11) ; un appel passé garde son type archivé.
            return array_values(array_filter($tous, static fn (TypeContribution $t): bool => $t->estActif()));
        }
        $types = [];
        foreach (TypeContribution::PAR_DEFAUT as $i => $d) {
            $type = new TypeContribution($association, $d['code'], $d['nom'], UniteContribution::from($d['unite']), ModeMontant::from($d['mode']), $d['montant'], $d['taux'], $i);
            $this->em->persist($type);
            $types[] = $type;
        }
        $this->em->flush();

        return $types;
    }

    /** @param list<TypeContribution> $types */
    public static function typeParCode(array $types, ?string $code): ?TypeContribution
    {
        foreach ($types as $type) {
            if ($type->getCode() === $code) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Les villes qu'un appel peut viser : les villes actives de l'association (un brouillon est invisible des membres).
     *
     * @return list<Ville>
     */
    public function villes(Association $association): array
    {
        return $this->em->getRepository(Ville::class)->findBy(['association' => $association, 'statut' => VilleStatut::Active], ['nom' => 'ASC']);
    }

    /**
     * Les membres qui recevront une échéance : actifs, d'une ville active du périmètre ; par foyer, seul le payeur (ou,
     * à défaut, le premier membre du foyer) et les membres sans foyer.
     *
     * @return list<Membre>
     */
    public function concernes(Association $association, ?Ville $ville, UniteContribution $unite): array
    {
        $requete = $this->em->createQueryBuilder()
            ->select('m', 'f')
            ->from(Membre::class, 'm')
            ->innerJoin('m.ville', 'v')
            ->leftJoin('m.foyer', 'f')
            ->andWhere('m.association = :association')
            ->andWhere('m.statut = :actif')
            ->andWhere('v.statut = :active')
            ->setParameter('association', $association)
            ->setParameter('actif', MembreStatut::Actif)
            ->setParameter('active', VilleStatut::Active)
            ->orderBy('m.id', 'ASC');
        if (null !== $ville) {
            $requete->andWhere('m.ville = :ville')->setParameter('ville', $ville);
        }
        /** @var list<Membre> $membres */
        $membres = $requete->getQuery()->getResult();
        if (UniteContribution::Personne === $unite) {
            return $membres;
        }

        $retenus = [];
        $foyersVus = [];
        foreach ($membres as $membre) {
            $foyer = $membre->getFoyer();
            if (null === $foyer) {
                $retenus[] = $membre;
                continue;
            }
            $payeur = $foyer->getPayeur();
            $id = (int) $foyer->getId();
            if (isset($foyersVus[$id])) {
                continue;
            }
            if (null === $payeur || $payeur === $membre || MembreStatut::Actif !== $payeur->getStatut()) {
                $foyersVus[$id] = true;
                $retenus[] = $membre;
            }
        }

        return $retenus;
    }

    /**
     * Les compteurs de chaque périmètre (« toutes » et chaque ville active), par unité : de quoi calculer « Ce qui va se
     * passer » sans aller-retour quand on change de type ou de ville.
     *
     * @return Statistiques
     */
    public function statistiques(Association $association): array
    {
        $perimetres = [AppelData::TOUTES => null];
        foreach ($this->villes($association) as $ville) {
            $perimetres[(string) $ville->getId()] = $ville;
        }
        $statistiques = [];
        foreach ($perimetres as $cle => $ville) {
            foreach (UniteContribution::cases() as $unite) {
                $membres = $this->concernes($association, $ville, $unite);
                $statistiques[(string) $cle][$unite->value] = [
                    'membres' => \count($membres),
                    'emails' => \count(array_filter($membres, self::joignable(...))),
                ];
            }
        }

        return $statistiques;
    }

    /**
     * « Ce qui va se passer » pour ces réglages : membres concernés, échéances, objectif, part du central, e-mails.
     *
     * @param Statistiques $statistiques
     *
     * @return array{membres: int, emails: int, sansEmail: int, montant: ?int, objectif: ?int, partCentral: ?int, taux: int}
     */
    public static function synthese(array $statistiques, string $perimetre, UniteContribution $unite, ?int $montant, int $taux): array
    {
        $compteurs = $statistiques[$perimetre][$unite->value] ?? ['membres' => 0, 'emails' => 0];
        $objectif = null !== $montant && $montant > 0 ? $montant * $compteurs['membres'] : null;

        return [
            'membres' => $compteurs['membres'],
            'emails' => $compteurs['emails'],
            'sansEmail' => $compteurs['membres'] - $compteurs['emails'],
            'montant' => $montant,
            'objectif' => $objectif,
            'partCentral' => null !== $objectif ? (int) round($objectif * $taux / 100) : null,
            'taux' => $taux,
        ];
    }

    /**
     * Le suivi d'un appel lancé (F-14, G-09) : échéances, contributeurs (échéances payées), collecté, objectif, reste,
     * progression, part du bureau central, détail par ville et collecte jour par jour depuis l'ouverture.
     *
     * @return array{echeances: int, contributeurs: int, dues: int, annulees: int, enRetard: int, collecte: int, objectif: ?int, reste: ?int, progression: ?int, partCentral: int, parVille: list<array{ville: string, echeances: int, contributeurs: int, collecte: int}>, parJour: list<array{jour: \DateTimeImmutable, montant: int, cumul: int}>}
     */
    public static function suivi(AppelContribution $appel, \DateTimeImmutable $aujourdhui): array
    {
        $suivi = ['echeances' => 0, 'contributeurs' => 0, 'dues' => 0, 'annulees' => 0, 'enRetard' => 0, 'collecte' => 0];
        $parVille = [];
        $parJour = [];
        foreach ($appel->getEcheances() as $echeance) {
            \assert($echeance instanceof Echeance);
            $ville = $echeance->getMembre()->getVille()->getNom();
            $parVille[$ville] ??= ['ville' => $ville, 'echeances' => 0, 'contributeurs' => 0, 'collecte' => 0];
            if ($echeance->estPayee()) {
                ++$suivi['echeances'];
                ++$suivi['contributeurs'];
                $montant = $echeance->getMontant() ?? 0;
                $suivi['collecte'] += $montant;
                ++$parVille[$ville]['echeances'];
                ++$parVille[$ville]['contributeurs'];
                $parVille[$ville]['collecte'] += $montant;
                $jour = ($echeance->getPayeeLe() ?? $aujourdhui)->format('Y-m-d');
                $parJour[$jour] = ($parJour[$jour] ?? 0) + $montant;
            } elseif ($echeance->estDue()) {
                ++$suivi['echeances'];
                ++$suivi['dues'];
                ++$parVille[$ville]['echeances'];
                if ($echeance->estEnRetard($aujourdhui)) {
                    ++$suivi['enRetard'];
                }
            } else {
                ++$suivi['annulees'];
            }
        }
        ksort($parVille);
        $objectif = null !== $appel->getMontant() && $appel->getMontant() > 0 ? $appel->getMontant() * $suivi['echeances'] : null;

        // Jour par jour, de l'ouverture à aujourd'hui (ou à la date limite si elle est passée), avec le cumul.
        $jours = [];
        $debut = ($appel->getOuvertLe() ?? $aujourdhui)->setTime(0, 0);
        $fin = min($aujourdhui, max($appel->getDateLimite(), $debut))->setTime(0, 0);
        $cumul = 0;
        for ($jour = $debut; $jour <= $fin && \count($jours) < 120; $jour = $jour->modify('+1 day')) {
            $montant = $parJour[$jour->format('Y-m-d')] ?? 0;
            $cumul += $montant;
            $jours[] = ['jour' => $jour, 'montant' => $montant, 'cumul' => $cumul];
        }

        return [
            ...$suivi,
            'objectif' => $objectif,
            'reste' => null !== $objectif ? max(0, $objectif - $suivi['collecte']) : null,
            'progression' => null !== $objectif && $objectif > 0 ? (int) min(100, round($suivi['collecte'] / $objectif * 100)) : null,
            'partCentral' => (int) round($suivi['collecte'] * $appel->getTauxReversement() / 100),
            'parVille' => array_values($parVille),
            'parJour' => $jours,
        ];
    }

    public static function joignable(Membre $membre): bool
    {
        return null !== $membre->getEmail() && '' !== $membre->getEmail() && $membre->aConsentiEmail();
    }

    /** Applique le formulaire au brouillon (nouveau ou existant) et l'enregistre. */
    public function enregistrer(Association $association, ?AppelContribution $appel, AppelData $donnees, ?Utilisateur $acteur): AppelContribution
    {
        $types = $this->types($association);
        $type = self::typeParCode($types, $donnees->type) ?? throw new \InvalidArgumentException('Type de contribution inconnu.');
        $ville = null;
        if (AppelData::TOUTES !== $donnees->perimetre) {
            foreach ($this->villes($association) as $candidate) {
                if ((string) $candidate->getId() === $donnees->perimetre) {
                    $ville = $candidate;
                }
            }
        }
        $nouveau = null === $appel;
        $appel ??= new AppelContribution($association, $type, $acteur);
        $appel->definir(
            $type,
            (string) $donnees->objet,
            (string) $donnees->message,
            $donnees->mode,
            $donnees->montantEnCentimes() ?: null,
            $donnees->dateLimite ?? new \DateTimeImmutable('+7 days'),
            $ville,
            $donnees->taux ?? 0,
            $donnees->relancesAuto,
        );
        if ($nouveau) {
            $this->em->persist($appel);
        }
        $this->journal->consigner(TypeEvenement::AppelEnregistre, $acteur, $association, $appel->getObjet(), ['type' => $type->getCode(), 'ville' => $ville?->getNom()]);
        $this->em->flush();

        return $appel;
    }

    /**
     * Ouvre l'appel : une échéance par membre concerné, un e-mail à chacun de ceux qui sont joignables, le journal.
     *
     * @return array{echeances: int, emails: int}
     */
    public function lancer(AppelContribution $appel, ?Utilisateur $acteur, \DateTimeImmutable $quand): array
    {
        $membres = $this->concernes($appel->getAssociation(), $appel->getVille(), $appel->getType()->getUnite());
        if ([] === $membres) {
            throw new \LogicException('Aucun membre concerné : l\'appel ne peut pas être lancé.');
        }
        $appel->ouvrir($quand);
        $emails = 0;
        foreach ($membres as $membre) {
            $this->em->persist(new Echeance($appel, $membre, $quand));
        }
        $this->journal->consigner(TypeEvenement::AppelLance, $acteur, $appel->getAssociation(), $appel->getObjet(), [
            'type' => $appel->getType()->getCode(),
            'ville' => $appel->getVille()?->getNom(),
            'echeances' => \count($membres),
            'montant' => $appel->getMontant(),
        ], $quand);
        $this->em->flush();

        foreach ($membres as $membre) {
            if (!self::joignable($membre)) {
                continue;
            }
            $this->envoyer($appel, $membre);
            ++$emails;
        }

        return ['echeances' => \count($membres), 'emails' => $emails];
    }

    private function envoyer(AppelContribution $appel, Membre $membre): void
    {
        $this->mailer->send((new TemplatedEmail())
            ->to(new Address((string) $membre->getEmail(), $membre->getNomComplet()))
            ->subject($appel->getObjet())
            ->htmlTemplate('emails/appel.html.twig')
            ->textTemplate('emails/appel.txt.twig')
            ->context([
                'appel' => $appel,
                'prenom' => $membre->getPrenom(),
                'lien' => $this->urls->generate('connexion', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
    }
}
