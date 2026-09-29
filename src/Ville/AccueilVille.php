<?php

declare(strict_types=1);

namespace App\Ville;

use App\Association\Reversements;
use App\Association\TableauDeBordAssociation;
use App\Cotisation\Cotisations;
use App\Entity\Association;
use App\Entity\Depense;
use App\Entity\DepenseStatut;
use App\Entity\MembreStatut;
use App\Entity\Paiement;
use App\Entity\Ville;
use App\Relance\Relances;
use App\Repository\DepenseRepository;
use App\Repository\MembreRepository;
use App\Repository\PaiementRepository;
use App\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * L'accueil d'un responsable de ville (F-28, artboard « Accueil trésorière mobile » du canevas Console) : quatre
 * chiffres (collecté, membres à jour, impayés, solde de la caisse), la liste « À faire » adaptée aux droits de la
 * personne (inscriptions à valider, membres en retard, dépenses en attente, reversement à déclarer, cotisations à
 * ouvrir), et les derniers paiements. Rien n'est inventé : sans donnée, la liste est vide.
 *
 * @phpstan-type AFaire array{type: string, nombre: int, montant: ?int, date: ?\DateTimeImmutable, route: string, params: array<string, mixed>, action: string, agir: bool}
 */
final class AccueilVille
{
    public function __construct(
        private readonly TableauDeBordAssociation $tableau,
        private readonly Relances $relances,
        private readonly Reversements $reversements,
        private readonly Cotisations $cotisations,
        private readonly MembreRepository $membres,
        private readonly DepenseRepository $depenses,
        private readonly PaiementRepository $paiements,
        private readonly Security $securite,
    ) {
    }

    /**
     * @return array{finances: array<string, mixed>, membres: int, impayes: array{montant: int, membres: int}, aFaire: list<AFaire>, paiements: list<Paiement>, libelleExercice: string}
     */
    public function construire(Association $association, Ville $ville, \DateTimeImmutable $aujourdhui, int $anneeExercice): array
    {
        $finances = $this->tableau->finances($association, $ville, $aujourdhui, $anneeExercice);
        $parStatut = $this->membres->compterParStatut($ville);
        $impayes = $this->relances->impayes($association, $ville, $aujourdhui);
        $montantImpayes = array_sum(array_column($impayes, 'montant'));
        $reversements = $this->reversements->tableau($association, $aujourdhui, $anneeExercice, $ville);
        $anneeCivile = (int) $aujourdhui->format('Y');

        $aFaire = [];
        $peutGerer = $this->securite->isGranted(Permission::MEMBRE_GERER, $ville);
        $peutSaisir = $this->securite->isGranted(Permission::PAIEMENT_SAISIR, $ville);
        $peutDecider = $this->securite->isGranted(Permission::DEPENSE_VALIDER, $ville);

        $enAttente = $parStatut[MembreStatut::EnAttente->value] ?? 0;
        if ($enAttente > 0) {
            $aFaire[] = ['type' => 'inscriptions', 'nombre' => $enAttente, 'montant' => null, 'date' => null, 'route' => 'association_membres', 'params' => ['statut' => 'en-attente', 'annee' => 'toutes'], 'action' => $peutGerer ? 'valider' : 'voir', 'agir' => $peutGerer];
        }
        if ([] !== $impayes) {
            $aFaire[] = ['type' => 'impayes', 'nombre' => \count($impayes), 'montant' => $montantImpayes, 'date' => null, 'route' => 'association_impayes', 'params' => [], 'action' => $peutSaisir ? 'relancer' : 'voir', 'agir' => $peutSaisir];
        }
        $soumises = array_values(array_filter($this->depenses->lister($association, $ville), static fn (Depense $d): bool => DepenseStatut::Soumise === $d->getStatut()));
        if ([] !== $soumises) {
            $aFaire[] = ['type' => 'depenses', 'nombre' => \count($soumises), 'montant' => array_sum(array_map(static fn (Depense $d): int => $d->getMontant(), $soumises)), 'date' => null, 'route' => 'association_depenses', 'params' => ['statut' => 'a_valider'], 'action' => $peutDecider ? 'decider' : 'voir', 'agir' => $peutDecider];
        }
        if ($reversements['totaux']['restant'] > 0) {
            $aFaire[] = ['type' => 'reversement', 'nombre' => 1, 'montant' => $reversements['totaux']['restant'], 'date' => $reversements['dateLimite'], 'route' => $peutSaisir ? 'association_reversement_declarer' : 'association_reversements', 'params' => [], 'action' => $peutSaisir ? 'declarer' : 'voir', 'agir' => $peutSaisir];
        }
        if ($peutSaisir && null === $this->cotisations->pour($ville, $anneeCivile) && $ville->estActive() && $anneeCivile >= $association->getPremierExercice()) {
            $aFaire[] = ['type' => 'cotisations', 'nombre' => 1, 'montant' => null, 'date' => null, 'route' => 'association_cotisation_ouvrir', 'params' => ['annee' => $anneeCivile], 'action' => 'ouvrir', 'agir' => true];
        }

        return [
            'finances' => $finances,
            'membres' => ($parStatut[MembreStatut::Actif->value] ?? 0) + $enAttente,
            'impayes' => ['montant' => $montantImpayes, 'membres' => \count($impayes)],
            'aFaire' => $aFaire,
            'paiements' => $this->paiements->lister($association, $ville, null, null, 5),
            'libelleExercice' => $this->tableau->libelleExercice($association, $anneeExercice),
        ];
    }
}
