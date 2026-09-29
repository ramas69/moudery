<?php

declare(strict_types=1);

namespace App\Cotisation;

use App\Appel\Appels;
use App\Association\TableauDeBordAssociation;
use App\Entity\Adhesion;
use App\Entity\Cotisation;
use App\Entity\Echeance;
use App\Entity\Membre;
use App\Entity\TypeContribution;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Journal\Journal;
use App\Repository\CotisationRepository;
use App\Repository\EcheanceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cotisations périodiques (F-12) : le bureau central ou le trésorier ouvre la cotisation d'une ville pour un exercice
 * (type, tarif mensuel, jour d'échéance) ; l'application crée alors, pour chaque adhérent concerné, la ligne du
 * classeur (l'adhésion de l'année, avec son tarif) et douze mensualités. Un mois déjà versé d'après le classeur
 * importé est payé d'emblée, sans paiement. La génération se rejoue sans doublon : les membres arrivés depuis
 * reçoivent leurs mensualités, les autres ne changent pas.
 */
final class Cotisations
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CotisationRepository $cotisations,
        private readonly EcheanceRepository $echeances,
        private readonly Appels $appels,
        private readonly TableauDeBordAssociation $tableau,
        private readonly Journal $journal,
    ) {
    }

    /** @return array{cotisation: Cotisation, membres: int, echeances: int, dejaVersees: int} */
    public function ouvrir(Ville $ville, TypeContribution $type, int $annee, int $montantMensuel, int $jourEcheance, ?Utilisateur $acteur, \DateTimeImmutable $quand): array
    {
        if (null !== $this->cotisations->pourVilleEtAnnee($ville, $annee)) {
            throw new \LogicException(\sprintf('La cotisation %d de %s est déjà ouverte.', $annee, $ville->getNom()));
        }
        $cotisation = new Cotisation($ville, $type, $annee, $montantMensuel, $jourEcheance, $acteur, $quand);
        $this->entityManager->persist($cotisation);
        $bilan = $this->generer($cotisation, $quand);
        $this->journal->consigner(TypeEvenement::CotisationOuverte, $acteur, $ville->getAssociation(), $ville->getNom(), [
            'ville' => $ville->getNom(),
            'annee' => $annee,
            'type' => $type->getCode(),
            'montant_mensuel' => $montantMensuel,
            'jour_echeance' => $jourEcheance,
            'membres' => $bilan['membres'],
            'echeances' => $bilan['echeances'],
        ], $quand);
        $this->entityManager->flush();

        return ['cotisation' => $cotisation] + $bilan;
    }

    /**
     * Crée ce qui manque : l'adhésion de l'année pour chaque membre concerné (avec le tarif), et une échéance par mois
     * de l'exercice. Ne touche à rien d'existant. À appeler après avoir ajouté des membres.
     *
     * @return array{membres: int, echeances: int, dejaVersees: int}
     */
    public function generer(Cotisation $cotisation, \DateTimeImmutable $quand): array
    {
        $ville = $cotisation->getVille();
        $membres = $this->appels->concernes($ville->getAssociation(), $ville, $cotisation->getType()->getUnite());
        $existantes = [];
        if (null !== $cotisation->getId()) {
            foreach ($this->echeances->listerPourCotisation($cotisation) as $echeance) {
                $existantes[(int) $echeance->getMembre()->getId()][(int) $echeance->getMois()] = $echeance;
            }
        }
        $moisExercice = $this->tableau->moisDeLExercice($ville->getAssociation(), $cotisation->getAnnee());

        $creees = 0;
        $dejaVersees = 0;
        foreach ($membres as $membre) {
            $adhesion = $membre->adherer($cotisation->getAnnee(), Membre::ORIGINE_GENERATION === $membre->getOrigine() ? Membre::ORIGINE_GENERATION : Membre::ORIGINE_SAISIE);
            if (null === $adhesion->getId()) {
                $this->entityManager->persist($adhesion);
            }
            if (null === $adhesion->getTarifMensuel()) {
                $adhesion->definirTarifMensuel($cotisation->getMontantMensuel());
            }
            foreach ($moisExercice as $m) {
                if (isset($existantes[(int) $membre->getId()][$m['mois']])) {
                    continue;
                }
                $echeance = Echeance::pourCotisation($cotisation, $membre, $m['mois'], $m['annee'], $quand);
                if ($adhesion->moisRenseigne($m['mois'])) {
                    // Versé avant l'application (classeur importé) : payé, sans paiement enregistré.
                    $echeance->payer(null, $quand, $adhesion->getMontantMois($m['mois']) ?: null);
                    ++$dejaVersees;
                }
                $this->entityManager->persist($echeance);
                ++$creees;
            }
        }

        return ['membres' => \count($membres), 'echeances' => $creees, 'dejaVersees' => $dejaVersees];
    }

    /** Un nouveau tarif ou jour d'échéance : seules les mensualités encore dues suivent, les lignes du classeur aussi. */
    public function modifierTarif(Cotisation $cotisation, int $montantMensuel, int $jourEcheance, ?Utilisateur $acteur, \DateTimeImmutable $quand): void
    {
        $avant = ['montant_mensuel' => $cotisation->getMontantMensuel(), 'jour_echeance' => $cotisation->getJourEcheance()];
        $cotisation->definirTarif($montantMensuel, $jourEcheance);
        foreach ($this->echeances->listerPourCotisation($cotisation) as $echeance) {
            $echeance->actualiserMontant($montantMensuel);
            if ($echeance->estDue() && null !== $echeance->getMois() && null !== $echeance->getAnnee()) {
                $echeance->reporterA($cotisation->dateLimitePour($echeance->getMois(), $echeance->getAnnee()));
            }
            $adhesion = $echeance->getMembre()->adhesionPour($cotisation->getAnnee());
            $adhesion?->definirTarifMensuel($montantMensuel);
        }
        $this->journal->consigner(TypeEvenement::CotisationModifiee, $acteur, $cotisation->getAssociation(), $cotisation->getVille()->getNom(), [
            'ville' => $cotisation->getVille()->getNom(),
            'annee' => $cotisation->getAnnee(),
            'avant' => $avant,
            'apres' => ['montant_mensuel' => $montantMensuel, 'jour_echeance' => $jourEcheance],
        ], $quand);
        $this->entityManager->flush();
    }

    /** @return array{dues: int, payees: int, annulees: int, enRetard: int, montantDu: int, montantPaye: int, montantEnRetard: int, membres: int} */
    public function synthese(Cotisation $cotisation, \DateTimeImmutable $aujourdhui): array
    {
        return $this->echeances->synthesePourCotisation($cotisation, $aujourdhui);
    }

    public function pour(Ville $ville, int $annee): ?Cotisation
    {
        return $this->cotisations->pourVilleEtAnnee($ville, $annee);
    }
}
