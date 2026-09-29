<?php

declare(strict_types=1);

namespace App\Association;

use App\Entity\AppelContribution;
use App\Entity\AppelStatut;
use App\Entity\ModeMontant;
use App\Entity\Association;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Repository\AppelContributionRepository;
use App\Repository\DepenseRepository;
use App\Repository\MembreRepository;

/**
 * Le rapport financier annuel pour l'assemblée générale (F-30, US-11), d'après la maquette « Rapport AG » : synthèse de
 * l'exercice (six chiffres, texte, entrées par type, dépenses par catégorie, faits marquants), graphiques
 * (encaissements par mois, dépenses par catégorie, membres à jour par ville), comptes des villes (membres à jour,
 * collecté, dépensé, reversé sur dû, solde de l'exercice) et appels. Tout vient des données de l'application :
 * cotisations des classeurs, dépenses payées, reversements, appels ; ce qui n'existe pas encore vaut zéro.
 *
 * Avec une ville (29 septembre 2026), le même rapport pour cette seule caisse : ses cotisations et ses contributions,
 * ses dépenses, ce qu'elle a reversé sur ce qu'elle doit, ses membres, et les appels qui la concernent avec ce qu'ils ont
 * collecté chez elle. Pas de ligne « bureau central ».
 *
 * @phpstan-type LigneVille array{ville: \App\Entity\Ville, membres: int, aJour: int, avecHistorique: int, collecte: int, depense: int, du: int, recu: int, solde: int}
 */
final class RapportAg
{
    public function __construct(
        private readonly TableauDeBordAssociation $tableau,
        private readonly Reversements $reversements,
        private readonly DepenseRepository $depenses,
        private readonly MembreRepository $membres,
        private readonly AppelContributionRepository $appels,
        private readonly FluxArgent $flux,
    ) {
    }

    /** @return array<string, mixed> */
    public function construire(Association $association, \DateTimeImmutable $aujourdhui, ?int $exercice = null, ?Ville $seule = null): array
    {
        $exercices = $this->tableau->exercices($association, $aujourdhui);
        $connus = array_column($exercices, 'annee');
        $annee = null !== $exercice && \in_array($exercice, $connus, true) ? $exercice : $connus[0];
        [$debut, $fin] = $this->reversements->bornes($association, $annee);

        $finances = $this->tableau->finances($association, $seule, $aujourdhui, $annee);
        $parVilleFinances = $this->tableau->villesFinances($association, $aujourdhui, $annee);
        $reversements = $this->reversements->tableau($association, $aujourdhui, $annee);
        $depensesParCaisse = $this->depenses->totalPayeParCaisse($association, $debut, $fin);
        $depensesParCategorie = $this->depenses->totalPayeParCategorie($association, $debut, $fin, $seule);
        $membresParVille = $this->membres->compterParVille($association, null);
        if (null !== $seule) {
            $membresParVille = [(int) $seule->getId() => $membresParVille[(int) $seule->getId()] ?? 0];
        }

        $duParVille = [];
        foreach ($reversements['lignes'] as $ligne) {
            $duParVille[(int) $ligne['ville']->getId()] = $ligne;
        }

        $villes = [];
        $totaux = ['membres' => 0, 'aJour' => 0, 'avecHistorique' => 0, 'collecte' => 0, 'depense' => 0, 'du' => 0, 'recu' => 0, 'solde' => 0];
        foreach ($this->tableau->villes($association) as $ville) {
            if (VilleStatut::Brouillon === $ville->getStatut() || (null !== $seule && $ville !== $seule)) {
                continue;
            }
            $id = (int) $ville->getId();
            $chiffres = $parVilleFinances[$id] ?? ['aJour' => 0, 'avecHistorique' => 0, 'collecte' => 0];
            $reversement = $duParVille[$id] ?? ['du' => 0, 'recu' => 0];
            $depense = $depensesParCaisse[$id] ?? 0;
            $ligne = [
                'ville' => $ville,
                'membres' => $membresParVille[$id] ?? 0,
                'aJour' => $chiffres['aJour'],
                'avecHistorique' => $chiffres['avecHistorique'],
                'collecte' => $chiffres['collecte'],
                'depense' => $depense,
                'du' => $reversement['du'],
                'recu' => $reversement['recu'],
                'solde' => $chiffres['collecte'] - $reversement['du'] - $depense,
            ];
            $villes[] = $ligne;
            foreach (array_keys($totaux) as $cle) {
                $totaux[$cle] += $ligne[$cle];
            }
        }
        $depenseCentral = null === $seule ? ($depensesParCaisse[DepenseRepository::CENTRAL] ?? 0) : 0;

        $appels = array_values(array_filter(
            $this->appels->findBy(['association' => $association], ['ouvertLe' => 'ASC']),
            static fn (AppelContribution $a): bool => AppelStatut::Brouillon !== $a->getStatut() && null !== $a->getOuvertLe() && $a->getOuvertLe() >= $debut && $a->getOuvertLe() <= $fin->setTime(23, 59, 59),
        ));
        $lignesAppels = [];
        foreach ($appels as $a) {
            $suivi = \App\Appel\Appels::suivi($a, $aujourdhui);
            if (null === $seule) {
                $lignesAppels[] = ['appel' => $a, 'echeances' => $suivi['echeances'], 'objectif' => $suivi['objectif'], 'collecte' => $suivi['collecte']];
                continue;
            }
            // Pour une ville : seulement les appels qui la concernent, avec ce qu'ils ont collecté chez elle.
            foreach ($suivi['parVille'] as $ligneVille) {
                if ($ligneVille['ville'] === $seule->getNom()) {
                    $montant = $a->getMontant();
                    $lignesAppels[] = ['appel' => $a, 'echeances' => $ligneVille['echeances'], 'objectif' => null !== $montant && ModeMontant::Fixe === $a->getMode() ? $montant * $ligneVille['echeances'] : null, 'collecte' => $ligneVille['collecte']];
                }
            }
        }

        // Entrées par type : les cotisations, puis chaque type d'appel lancé sur l'exercice avec ce qu'il a collecté.
        $entrees = [['libelle' => 'cotisations', 'montant' => $finances['cotisations']]];
        foreach ($lignesAppels as $ligne) {
            $nom = $ligne['appel']->getType()->getNom();
            $index = array_search($nom, array_column($entrees, 'libelle'), true);
            if (false === $index) {
                $entrees[] = ['libelle' => $nom, 'montant' => $ligne['collecte']];
            } else {
                $entrees[$index]['montant'] += $ligne['collecte'];
            }
        }

        $maximumCategorie = max([1, ...array_values($depensesParCategorie)]);

        return [
            'ville' => $seule,
            'exercice' => $annee,
            'libelle' => $this->tableau->libelleExercice($association, $annee),
            'exercices' => $exercices,
            'debut' => $debut,
            'fin' => $fin,
            'arreteLe' => $aujourdhui,
            'finances' => $finances,
            'membres' => array_sum($membresParVille),
            'nombreVilles' => \count($villes),
            'villesEnCreation' => null !== $seule ? 0 : \count(array_filter($this->tableau->villes($association), static fn ($v): bool => VilleStatut::Brouillon === $v->getStatut())),
            'reverse' => null === $seule ? $reversements['totaux']['recu'] : $totaux['recu'],
            'du' => null === $seule ? $reversements['totaux']['du'] : $totaux['du'],
            'entrees' => $entrees,
            'totalEntrees' => array_sum(array_column($entrees, 'montant')),
            'depensesParCategorie' => $depensesParCategorie,
            'maximumCategorie' => $maximumCategorie,
            'totalDepenses' => array_sum($depensesParCategorie),
            'depenseCentral' => $depenseCentral,
            'villes' => $villes,
            'totaux' => $totaux,
            'appels' => $lignesAppels,
            'flux' => $this->flux->construire($association, $aujourdhui, $annee, $seule),
        ];
    }
}
