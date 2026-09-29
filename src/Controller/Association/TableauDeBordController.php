<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Association\CarteVilles;
use App\Association\FluxArgent;
use App\Association\Perimetre;
use App\Association\Reversements;
use App\Repository\DepenseRepository;
use App\Association\TableauDeBordAssociation;
use App\Entity\Association;
use App\Entity\Utilisateur;
use App\Ville\AccueilVille;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * L'espace du bureau central : le tableau de bord de son association (F-28), calé sur l'artboard « 01 Tableau de bord »
 * du canevas Console, pour toute l'association ou pour la ville du périmètre (sélecteur de la barre latérale, ou ?ville=).
 * Le droit « piloter l'association » est vérifié sur l'association de l'adresse : un bureau central ne voit jamais celle
 * d'une autre.
 */
final class TableauDeBordController extends AbstractController
{
    public function __construct(
        private readonly Reversements $reversements,
        private readonly DepenseRepository $depenses,
        private readonly AccueilVille $accueilVille,
        private readonly FluxArgent $flux,
    ) {
    }

    #[Route('/associations/{slug}', name: 'association_tableau_de_bord', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    #[IsGranted(Permission::ESPACE_OUVRIR, subject: 'association')]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request, TableauDeBordAssociation $tableau, Perimetre $perimetre): Response
    {
        $aujourdhui = new \DateTimeImmutable();

        // L'exercice : celui en cours, ou un exercice passé choisi dans l'en-tête, jamais avant le premier exercice suivi.
        $exercices = $tableau->exercices($association, $aujourdhui);
        $anneeCourante = $exercices[0]['annee'];
        $anneeExercice = $request->query->getInt('exercice', $anneeCourante);
        if (!\in_array($anneeExercice, array_column($exercices, 'annee'), true)) {
            $anneeExercice = $anneeCourante;
        }

        // La ville du périmètre : les villes, « à traiter », l'activité et les chiffres se limitent à elle.
        $villesToutes = $tableau->villes($association);
        $villeChoisie = $perimetre->villeCourante($association, $request);

        // Un responsable de ville a son propre accueil : chiffres de sa ville, « À faire », derniers paiements.
        if (null !== $villeChoisie && !$this->isGranted(Permission::ASSOCIATION_PILOTER, $association)) {
            $moi = $this->getUser();

            return $this->render('association/tableau_de_bord_ville.html.twig', [
                'association' => $association,
                'ville' => $villeChoisie,
                'aujourdhui' => $aujourdhui,
                'exercices' => $exercices,
                'anneeExercice' => $anneeExercice,
                'exerciceCourant' => $anneeExercice === $anneeCourante,
                'prenom' => $moi instanceof Utilisateur ? $moi->getPrenom() : null,
                ...$this->accueilVille->construire($association, $villeChoisie, $aujourdhui, $anneeExercice),
            ]);
        }
        $synthese = $tableau->synthese($association, $aujourdhui);
        $aTraiter = $tableau->aTraiter($association, $aujourdhui);
        $evenements = $tableau->activite($association);
        if (null !== $villeChoisie) {
            $nom = $villeChoisie->getNom();
            $synthese = [...$synthese, 'villes' => 1, 'brouillons' => $villeChoisie->estBrouillon() ? 1 : 0, 'avancement' => $villeChoisie->estBrouillon() ? $villeChoisie->getEtapeAssistant()->avancement() : null];
            $aTraiter = array_values(array_filter($aTraiter, static fn (array $element): bool => null !== $element['ville'] && $element['ville']->getId() === $villeChoisie->getId()));
            $evenements = array_values(array_filter($evenements, static fn ($evenement): bool => $evenement->getCible() === $nom || ($evenement->getDetails()['ville'] ?? null) === $nom));
        }

        // Filtres communs à toute la page (F-32, 29 septembre 2026) : l'exercice et la ville (en-tête et périmètre), plus le
        // type de contribution ; la comparaison avec l'exercice précédent s'active en un clic (F-34).
        $type = \in_array($request->query->get('type'), ['cotisations', 'ponctuelles'], true) ? (string) $request->query->get('type') : 'tout';
        $anneePrecedente = $anneeExercice - 1 >= $association->getPremierExercice() ? $anneeExercice - 1 : null;
        $comparer = $request->query->getBoolean('comparer') && null !== $anneePrecedente;

        // Les reversements dus viennent du module Reversements : restant à reverser sur l'exercice, villes en retard.
        $finances = self::filtrerParType($tableau->finances($association, $villeChoisie, $aujourdhui, $anneeExercice), $type);
        $precedent = null !== $anneePrecedente && ($comparer || 'tout' !== $type) ? self::filtrerParType($tableau->finances($association, $villeChoisie, $aujourdhui, $anneePrecedente), $type) : null;
        if (null !== $precedent && 'tout' !== $type) {
            // La variation du chiffre « Collecté » suit le type choisi.
            $finances['collectePrecedent'] = $precedent['collecte'];
            $finances['variation'] = $precedent['collecte'] > 0 ? (int) round(($finances['collecte'] - $precedent['collecte']) / $precedent['collecte'] * 100) : 0;
        }
        if (!$comparer) {
            $precedent = null;
        }
        $villesFinances = $tableau->villesFinances($association, $aujourdhui, $anneeExercice);
        $reversements = $this->reversements->tableau($association, $aujourdhui, $anneeExercice, $villeChoisie);
        $finances['reversements'] = $reversements['totaux']['restant'];
        $finances['villesEnRetard'] = $reversements['compteurs'][Reversements::STATUT_EN_RETARD];
        foreach ($reversements['lignes'] as $ligne) {
            $id = (int) $ligne['ville']->getId();
            if (isset($villesFinances[$id])) {
                $villesFinances[$id]['reversement'] = $ligne['restant'];
            }
        }
        // Comparatif des villes (G-04, G-05) : encaissé, dépensé (dépenses payées de la ville sur l'exercice), reversé, taux à jour.
        [$debutExercice, $finExercice] = $tableau->bornesExercice($association, $anneeExercice);
        $depensesParCaisse = $this->depenses->totalPayeParCaisse($association, $debutExercice, $finExercice);
        $comparatif = [];
        foreach ($reversements['lignes'] as $ligne) {
            $id = (int) $ligne['ville']->getId();
            $chiffres = $villesFinances[$id] ?? ['collecte' => 0, 'pourcentageAJour' => 0, 'avecHistorique' => 0];
            $comparatif[] = [
                'ville' => $ligne['ville'],
                'collecte' => $chiffres['collecte'],
                'depense' => $depensesParCaisse[$id] ?? 0,
                'reverse' => $ligne['recu'],
                'pourcentageAJour' => $chiffres['pourcentageAJour'],
                'avecHistorique' => $chiffres['avecHistorique'],
            ];
        }
        usort($comparatif, static fn (array $a, array $b): int => $b['collecte'] <=> $a['collecte']);

        // G-02 (flux de l'argent) et G-03 (carte des villes), pour qui pilote l'association.
        $pilote = $this->isGranted(Permission::ASSOCIATION_PILOTER, $association);
        $flux = $pilote ? $this->flux->construire($association, $aujourdhui, $anneeExercice, $villeChoisie, $type) : null;
        $carte = null;
        if ($pilote && null !== $flux) {
            $membresParVille = [];
            foreach ($comparatif as $c) {
                $membresParVille[$c['ville']->getNom()] = $villesFinances[(int) $c['ville']->getId()]['adherents'] ?? 0;
            }
            $carte = CarteVilles::construire(array_map(static fn (array $l): array => [
                'ville' => $l['ville'],
                'valeur' => $l['cotisations'] + $l['ponctuelles'],
                'membres' => $membresParVille[$l['ville']] ?? 0,
            ], $flux['lignes']));
        }

        return $this->render('association/tableau_de_bord.html.twig', [
            'association' => $association,
            'aujourdhui' => $aujourdhui,
            'exercice' => $tableau->libelleExercice($association, $anneeExercice),
            'anneeExercice' => $anneeExercice,
            'exerciceCourant' => $anneeExercice === $anneeCourante,
            'exercices' => $exercices,
            'synthese' => $synthese,
            'finances' => $finances,
            'villesFinances' => $villesFinances,
            'comparatif' => $comparatif,
            'pilote' => $pilote,
            'vue' => 'cumul' === $request->query->get('vue') ? 'cumul' : 'mois',
            'type' => $type,
            'comparer' => $comparer,
            'precedent' => $precedent,
            'exercicePrecedent' => null === $anneePrecedente ? null : $tableau->libelleExercice($association, $anneePrecedente),
            'flux' => $flux,
            'carte' => $carte,
            'aTraiter' => $aTraiter,
            'villes' => null === $villeChoisie ? $villesToutes : [$villeChoisie],
            'villesToutes' => $villesToutes,
            'villeChoisie' => $villeChoisie,
            'evenements' => $evenements,
        ]);
    }

    /**
     * Le filtre de type appliqué aux chiffres d'encaissement : cotisations seules ou contributions ponctuelles seules.
     * Dépensé et solde restent ceux de toute la caisse (une dépense ne se rattache pas à un type de contribution).
     *
     * @param array<string, mixed> $finances
     *
     * @return array<string, mixed>
     */
    private static function filtrerParType(array $finances, string $type): array
    {
        if ('tout' === $type) {
            return $finances;
        }
        $garde = 'cotisations' === $type ? 'cotisations' : 'ponctuelles';
        $efface = 'cotisations' === $type ? 'ponctuelles' : 'cotisations';
        $finances['mois'] = array_map(static fn (array $m): array => [...$m, $efface => 0], $finances['mois']);
        $finances[$efface] = 0;
        $finances['collecte'] = $finances[$garde];

        return $finances;
    }
}
