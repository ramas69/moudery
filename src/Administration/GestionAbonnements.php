<?php

declare(strict_types=1);

namespace App\Administration;

use App\Abonnement\Offre;
use App\Entity\Abonnement;
use App\Entity\AbonnementStatut;
use App\Entity\Association;
use App\Entity\PaiementAbonnement;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Form\Model\AbonnementData;
use App\Journal\Journal;
use Doctrine\ORM\EntityManagerInterface;

/** Abonnements des associations, tenus à la main par le super-admin en attendant la facturation (hors V1). */
final class GestionAbonnements
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly Journal $journal)
    {
    }

    /** Toute association a un abonnement ; celles créées avant cette notion en reçoivent un, offert. */
    public function pour(Association $association): Abonnement
    {
        $abonnement = $association->getAbonnement();
        if (null === $abonnement) {
            $abonnement = $association->ouvrirAbonnement();
            $this->entityManager->persist($abonnement);
            $this->journal->consigner(TypeEvenement::AbonnementInitial, null, $association, null, Journal::instantane($abonnement));
            $this->entityManager->flush();
        }

        return $abonnement;
    }

    public function modifier(Abonnement $abonnement, AbonnementData $donnees, ?Utilisateur $par = null): void
    {
        \assert(null !== $donnees->statut && null !== $donnees->periodicite && null !== $donnees->debutLe);
        $abonnement->definir(
            (string) $donnees->formule,
            $donnees->statut,
            (int) round(((float) $donnees->montant) * 100),
            $donnees->periodicite,
            $donnees->debutLe,
            $donnees->prochaineEcheanceLe,
            $donnees->notes,
        );

        $this->journal->consigner(TypeEvenement::AbonnementModifie, $par, $abonnement->getAssociation(), null, Journal::instantane($abonnement));
        $this->entityManager->flush();
    }

    /** Un paiement reçu hors ligne : conservé dans l'historique des paiements, l'échéance avance d'une période. */
    public function marquerPaye(Abonnement $abonnement, ?Utilisateur $par = null): PaiementAbonnement
    {
        $quand = new \DateTimeImmutable();
        $echeanceCouverte = $abonnement->getProchaineEcheanceLe();
        $abonnement->marquerPaye($quand);

        $paiement = new PaiementAbonnement($abonnement, $abonnement->getMontant(), $quand, $echeanceCouverte, $par);
        $this->entityManager->persist($paiement);
        $this->journal->consigner(TypeEvenement::AbonnementPaye, $par, $abonnement->getAssociation(), null, Journal::instantane($abonnement) + ['paiement' => $paiement->getMontant()], $quand);
        $this->entityManager->flush();

        return $paiement;
    }

    public const NOTE_SIMULATION = 'Paiement en ligne simulé';

    /**
     * Le bureau central règle son abonnement en ligne, SIMULÉ en attendant la facturation réelle (décision de Rama du
     * 29 septembre 2026) : un abonnement « à souscrire » prend d'abord l'offre choisie, puis la période est payée
     * comme par « Marquer comme payé », avec la note {@see self::NOTE_SIMULATION}. Aucun argent ne circule.
     */
    public function payerEnLigne(Abonnement $abonnement, ?Offre $offre, Utilisateur $par, \DateTimeImmutable $quand): PaiementAbonnement
    {
        if ($abonnement->attendLaSouscription() || AbonnementStatut::Offert === $abonnement->getStatut()) {
            if (null === $offre) {
                throw new \InvalidArgumentException('Choisissez une offre.');
            }
            if ($abonnement->attendLaSouscription()) {
                $abonnement->souscrire($offre, $quand);
            } else {
                // Une association à qui l'abonnement était offert choisit de passer à la formule payante.
                $jour = $quand->setTime(0, 0);
                $abonnement->definir($offre->formule, AbonnementStatut::Actif, $offre->montant, $offre->periodicite, $jour, $jour, \sprintf('Passage à l’offre %s le %s.', $offre->formule, $jour->format('d/m/Y')));
            }
            $this->journal->consigner(TypeEvenement::AbonnementSouscrit, $par, $abonnement->getAssociation(), null, Journal::instantane($abonnement), $quand);
        }
        $echeanceCouverte = $abonnement->getProchaineEcheanceLe();
        $abonnement->marquerPaye($quand);
        $paiement = new PaiementAbonnement($abonnement, $abonnement->getMontant(), $quand, $echeanceCouverte, $par, self::NOTE_SIMULATION);
        $this->entityManager->persist($paiement);
        $this->journal->consigner(TypeEvenement::AbonnementPaye, $par, $abonnement->getAssociation(), null, Journal::instantane($abonnement) + ['paiement' => $paiement->getMontant(), 'simulation' => true], $quand);
        $this->entityManager->flush();

        return $paiement;
    }

    /**
     * La plateforme en chiffres : revenu mensuel récurrent et répartition des abonnements.
     *
     * @param list<Abonnement> $abonnements
     *
     * @return array{mensuel: int, annuel: int, aSouscrire: int, offerts: int, actifs: int, enRetard: int, resilies: int, echeancesProches: int, montantEnRetard: int, montantEcheancesProches: int}
     */
    public static function synthese(array $abonnements, \DateTimeImmutable $aujourdhui): array
    {
        $synthese = ['mensuel' => 0, 'annuel' => 0, 'aSouscrire' => 0, 'offerts' => 0, 'actifs' => 0, 'enRetard' => 0, 'resilies' => 0, 'echeancesProches' => 0, 'montantEnRetard' => 0, 'montantEcheancesProches' => 0];
        foreach ($abonnements as $abonnement) {
            $synthese['mensuel'] += $abonnement->montantMensuel();
            if ($abonnement->estEnRetard($aujourdhui)) {
                ++$synthese['enRetard'];
                $synthese['montantEnRetard'] += $abonnement->getMontant();
            } elseif (AbonnementStatut::Actif === $abonnement->getStatut()) {
                ++$synthese['actifs'];
            } elseif (AbonnementStatut::Offert === $abonnement->getStatut()) {
                ++$synthese['offerts'];
            } elseif ($abonnement->attendLaSouscription()) {
                ++$synthese['aSouscrire'];
            } else {
                ++$synthese['resilies'];
            }
            if ($abonnement->echeanceProche($aujourdhui)) {
                ++$synthese['echeancesProches'];
                $synthese['montantEcheancesProches'] += $abonnement->getMontant();
            }
        }
        $synthese['annuel'] = $synthese['mensuel'] * 12;

        return $synthese;
    }

    /**
     * Les encaissements attendus mois par mois : chaque abonnement facturable projette ses échéances à partir de la
     * prochaine ; ce qui est déjà en retard est attendu ce mois-ci, la suite repart d'aujourd'hui.
     *
     * @param list<Abonnement> $abonnements
     *
     * @return list<array{mois: \DateTimeImmutable, attendu: int, enRetard: int}>
     */
    public static function previsions(array $abonnements, \DateTimeImmutable $aujourdhui, int $horizon = 12): array
    {
        $jour = $aujourdhui->setTime(0, 0);
        $debut = $jour->modify('first day of this month');
        $fin = $debut->modify(\sprintf('+%d months', $horizon));
        $mois = [];
        for ($i = 0; $i < $horizon; ++$i) {
            $mois[] = ['mois' => $debut->modify(\sprintf('+%d months', $i)), 'attendu' => 0, 'enRetard' => 0];
        }

        foreach ($abonnements as $abonnement) {
            $echeance = $abonnement->getProchaineEcheanceLe();
            if (!$abonnement->estFacturable() || null === $echeance || 0 === $abonnement->getMontant()) {
                continue;
            }
            $intervalle = $abonnement->getPeriodicite()->intervalle();
            if ($abonnement->estEnRetard($aujourdhui)) {
                $mois[0]['enRetard'] += $abonnement->getMontant();
                $echeance = $jour->add($intervalle);
            }
            while ($echeance < $fin) {
                $index = ((int) $echeance->format('Y') - (int) $debut->format('Y')) * 12 + ((int) $echeance->format('n') - (int) $debut->format('n'));
                if ($index >= 0 && $index < $horizon) {
                    $mois[$index]['attendu'] += $abonnement->getMontant();
                }
                $echeance = $echeance->add($intervalle);
            }
        }

        return $mois;
    }
}
