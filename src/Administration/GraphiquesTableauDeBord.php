<?php

declare(strict_types=1);

namespace App\Administration;

use App\Entity\AbonnementStatut;
use App\Entity\EtapeAssistant;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Les huit graphiques du tableau de bord, construits pour Chart.js à partir des séries de Statistiques.
 * Chaque graphique vient avec son tableau de données (en-têtes et lignes), affiché dépliable pour l'accessibilité.
 *
 * @phpstan-type Bloc array{chart: Chart, entetes: list<string>, lignes: list<list<string|int|float|null>>, unites: list<bool>}
 */
final class GraphiquesTableauDeBord
{
    /** Couleurs des tokens de app.css : accent, succès, attention, erreur, texte-2, texte-3, bordure. */
    private const string ACCENT = '#21705b';
    private const string ACCENT_DOUX = '#b9dfd0';
    private const string SUCCES = '#12925a';
    private const string ATTENTION = '#b45309';
    private const string ERREUR = '#d92d20';
    private const string NEUTRE = '#475467';
    private const string NEUTRE_CLAIR = '#98a2b3';
    private const string GRILLE = '#eef1ee';

    public function __construct(
        private readonly ChartBuilderInterface $constructeur,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    /**
     * @param array<string, mixed> $series les résultats de Statistiques, indexés par nom de graphique
     *
     * @return array<string, Bloc>
     */
    public function construire(Periode $periode, array $series): array
    {
        $libelles = $this->libelles($periode);

        return [
            'croissance' => $this->croissance($libelles, $series['croissance']),
            'revenu' => $this->revenu($libelles, $series['revenu']),
            'encaissements' => $this->encaissements($libelles, $series['encaissements']),
            'abonnements' => $this->abonnements($series['abonnements']),
            'villes' => $this->villes($series['villes']),
            'invitations' => $this->invitations($libelles, $series['invitations']),
            'comptes' => $this->comptes($series['comptes']),
            'activite' => $this->activite($libelles, $series['activite']),
        ];
    }

    /**
     * Le graphique d'activité seul, pour l'espace d'une association.
     *
     * @param array{connexions: list<int>, actions: list<int>} $serie
     *
     * @return Bloc
     */
    public function graphiqueActivite(Periode $periode, array $serie): array
    {
        return $this->activite($this->libelles($periode), $serie);
    }

    /** @return list<string> un libellé court par tranche, dans la langue de l'interface */
    public function libelles(Periode $periode): array
    {
        $motif = match ($periode->pas) {
            'mois' => 'MMM yy',
            default => 'd MMM',
        };
        $formateur = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, $motif);

        return array_map(static fn (array $tranche): string => (string) $formateur->format($tranche['debut']), $periode->tranches());
    }

    /** @param list<string> $libelles @param array<string, list<int>> $s @return Bloc */
    private function croissance(array $libelles, array $s): array
    {
        $chart = $this->constructeur->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $libelles,
            'datasets' => [
                $this->barres($this->t('croissance.associations'), $s['associations'], self::ACCENT, 'y'),
                $this->barres($this->t('croissance.villes'), $s['villes'], self::SUCCES, 'y'),
                $this->barres($this->t('croissance.comptes'), $s['comptes'], self::NEUTRE_CLAIR, 'y'),
                ['type' => 'line', 'label' => $this->t('croissance.cumul_comptes'), 'data' => $s['cumulComptes'], 'borderColor' => self::NEUTRE, 'backgroundColor' => self::NEUTRE, 'yAxisID' => 'y2', 'tension' => 0.3, 'pointRadius' => 2, 'borderWidth' => 2],
            ],
        ]);
        $chart->setOptions($this->options([
            'y' => ['stacked' => true, 'position' => 'left', 'beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['color' => self::GRILLE]],
            'y2' => ['position' => 'right', 'beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['drawOnChartArea' => false]],
        ], true));

        return $this->bloc($chart, [$this->t('croissance.associations'), $this->t('croissance.villes'), $this->t('croissance.comptes'), $this->t('croissance.cumul_comptes')], $libelles, [$s['associations'], $s['villes'], $s['comptes'], $s['cumulComptes']], [false, false, false, false]);
    }

    /** @param list<string> $libelles @param array{mensuel: list<int>, payants: list<int>} $s @return Bloc */
    private function revenu(array $libelles, array $s): array
    {
        $chart = $this->constructeur->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $libelles,
            'datasets' => [
                ['label' => $this->t('revenu.mensuel'), 'data' => self::euros($s['mensuel']), 'borderColor' => self::ACCENT, 'backgroundColor' => 'rgba(47, 84, 235, 0.12)', 'fill' => true, 'tension' => 0.3, 'pointRadius' => 2, 'borderWidth' => 2, 'yAxisID' => 'y'],
                ['label' => $this->t('revenu.payants'), 'data' => $s['payants'], 'borderColor' => self::NEUTRE_CLAIR, 'backgroundColor' => self::NEUTRE_CLAIR, 'borderDash' => [4, 4], 'tension' => 0.3, 'pointRadius' => 2, 'borderWidth' => 2, 'yAxisID' => 'y2'],
            ],
        ]);
        $chart->setOptions($this->options([
            'y' => ['position' => 'left', 'beginAtZero' => true, 'title' => ['display' => true, 'text' => '€'], 'grid' => ['color' => self::GRILLE]],
            'y2' => ['position' => 'right', 'beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['drawOnChartArea' => false]],
        ]));

        return $this->bloc($chart, [$this->t('revenu.mensuel'), $this->t('revenu.payants')], $libelles, [self::euros($s['mensuel']), $s['payants']], [true, false]);
    }

    /** @param list<string> $libelles @param array{recus: list<int>, attendus: list<int>} $s @return Bloc */
    private function encaissements(array $libelles, array $s): array
    {
        $chart = $this->constructeur->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $libelles,
            'datasets' => [
                $this->barres($this->t('encaissements.recus'), self::euros($s['recus']), self::SUCCES, 'y'),
                $this->barres($this->t('encaissements.attendus'), self::euros($s['attendus']), self::ACCENT_DOUX, 'y', self::ACCENT),
            ],
        ]);
        $chart->setOptions($this->options(['y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => '€'], 'grid' => ['color' => self::GRILLE]]]));

        return $this->bloc($chart, [$this->t('encaissements.recus'), $this->t('encaissements.attendus')], $libelles, [self::euros($s['recus']), self::euros($s['attendus'])], [true, true]);
    }

    /** @param array<string, array{nombre: int, mensuel: int}> $s @return Bloc */
    private function abonnements(array $s): array
    {
        $couleurs = [
            AbonnementStatut::ASouscrire->value => self::ATTENTION,
            AbonnementStatut::Offert->value => self::NEUTRE_CLAIR,
            AbonnementStatut::Actif->value => self::SUCCES,
            AbonnementStatut::EnRetard->value => self::ERREUR,
            AbonnementStatut::Resilie->value => self::NEUTRE,
        ];
        $libelles = [];
        $nombres = [];
        $mensuels = [];
        $fonds = [];
        foreach ($s as $statut => $valeurs) {
            $libelles[] = $this->traducteur->trans('statut_abonnement.'.$statut);
            $nombres[] = $valeurs['nombre'];
            $mensuels[] = $valeurs['mensuel'] / 100;
            $fonds[] = $couleurs[$statut] ?? self::NEUTRE;
        }
        $chart = $this->constructeur->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData(['labels' => $libelles, 'datasets' => [['data' => $nombres, 'backgroundColor' => $fonds, 'borderWidth' => 2, 'borderColor' => '#ffffff']]]);
        $chart->setOptions(['responsive' => true, 'maintainAspectRatio' => false, 'cutout' => '62%', 'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['boxWidth' => 10, 'usePointStyle' => true]]]]);

        return $this->bloc($chart, [$this->t('abonnements.nombre'), $this->t('abonnements.mensuel')], $libelles, [$nombres, $mensuels], [false, true]);
    }

    /** @param array{etapes: array<int, int>, actives: int, archivees: int} $s @return Bloc */
    private function villes(array $s): array
    {
        $libelles = [];
        $valeurs = [];
        $fonds = [];
        foreach (EtapeAssistant::cases() as $etape) {
            $libelles[] = $etape->numero().' · '.$this->traducteur->trans('assistant_ville.etapes.'.$etape->value);
            $valeurs[] = $s['etapes'][$etape->numero()] ?? 0;
            $fonds[] = self::ACCENT;
        }
        $libelles[] = $this->traducteur->trans('assistant_ville.statut.active');
        $valeurs[] = $s['actives'];
        $fonds[] = self::SUCCES;
        $libelles[] = $this->traducteur->trans('assistant_ville.statut.archivee');
        $valeurs[] = $s['archivees'];
        $fonds[] = self::NEUTRE_CLAIR;

        $chart = $this->constructeur->createChart(Chart::TYPE_BAR);
        $chart->setData(['labels' => $libelles, 'datasets' => [['label' => $this->t('villes.nombre'), 'data' => $valeurs, 'backgroundColor' => $fonds, 'borderRadius' => 4, 'maxBarThickness' => 18]]]);
        $chart->setOptions(['indexAxis' => 'y', 'responsive' => true, 'maintainAspectRatio' => false, 'plugins' => ['legend' => ['display' => false]], 'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['color' => self::GRILLE]], 'y' => ['grid' => ['display' => false]]]]);

        return $this->bloc($chart, [$this->t('villes.nombre')], $libelles, [$valeurs], [false]);
    }

    /** @param list<string> $libelles @param array{envoyees: list<int>, acceptees: list<int>, expirees: list<int>} $s @return Bloc */
    private function invitations(array $libelles, array $s): array
    {
        $chart = $this->constructeur->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $libelles,
            'datasets' => [
                $this->barres($this->t('invitations.envoyees'), $s['envoyees'], self::ACCENT, 'y'),
                $this->barres($this->t('invitations.acceptees'), $s['acceptees'], self::SUCCES, 'y'),
                $this->barres($this->t('invitations.expirees'), $s['expirees'], self::ERREUR, 'y'),
            ],
        ]);
        $chart->setOptions($this->options(['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['color' => self::GRILLE]]]));

        return $this->bloc($chart, [$this->t('invitations.envoyees'), $this->t('invitations.acceptees'), $this->t('invitations.expirees')], $libelles, [$s['envoyees'], $s['acceptees'], $s['expirees']], [false, false, false]);
    }

    /** @param array{connectes: int, inactifs: int, jamaisConnectes: int, enAttente: int, desactives: int} $s @return Bloc */
    private function comptes(array $s): array
    {
        $libelles = [$this->t('comptes.connectes'), $this->t('comptes.inactifs'), $this->t('comptes.jamais_connectes'), $this->t('comptes.en_attente'), $this->t('comptes.desactives')];
        $valeurs = [$s['connectes'], $s['inactifs'], $s['jamaisConnectes'], $s['enAttente'], $s['desactives']];
        $chart = $this->constructeur->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData(['labels' => $libelles, 'datasets' => [['data' => $valeurs, 'backgroundColor' => [self::SUCCES, self::ATTENTION, self::NEUTRE_CLAIR, self::ACCENT, self::ERREUR], 'borderWidth' => 2, 'borderColor' => '#ffffff']]]);
        $chart->setOptions(['responsive' => true, 'maintainAspectRatio' => false, 'cutout' => '62%', 'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['boxWidth' => 10, 'usePointStyle' => true]]]]);

        return $this->bloc($chart, [$this->t('comptes.nombre')], $libelles, [$valeurs], [false]);
    }

    /** @param list<string> $libelles @param array{connexions: list<int>, actions: list<int>} $s @return Bloc */
    private function activite(array $libelles, array $s): array
    {
        $chart = $this->constructeur->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $libelles,
            'datasets' => [
                $this->barres($this->t('activite.connexions'), $s['connexions'], self::ACCENT, 'y'),
                $this->barres($this->t('activite.actions'), $s['actions'], self::NEUTRE_CLAIR, 'y'),
            ],
        ]);
        $chart->setOptions($this->options(['y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0], 'grid' => ['color' => self::GRILLE]]], true));

        return $this->bloc($chart, [$this->t('activite.connexions'), $this->t('activite.actions')], $libelles, [$s['connexions'], $s['actions']], [false, false]);
    }

    /** @param list<int|float> $donnees @return array<string, mixed> */
    private function barres(string $libelle, array $donnees, string $couleur, string $axe, ?string $bordure = null): array
    {
        return ['label' => $libelle, 'data' => $donnees, 'backgroundColor' => $couleur, 'borderColor' => $bordure ?? $couleur, 'borderWidth' => null === $bordure ? 0 : 1, 'borderRadius' => 3, 'maxBarThickness' => 22, 'yAxisID' => $axe];
    }

    /**
     * Les options communes : légende en bas, info-bulle par colonne, axes. Les axes verticaux s'appellent y et y2 :
     * Chart.js déduit l'orientation d'un axe de la première lettre de son identifiant.
     *
     * @param array<string, array<string, mixed>> $axesY
     *
     * @return array<string, mixed>
     */
    private function options(array $axesY, bool $empile = false): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['boxWidth' => 10, 'usePointStyle' => true]]],
            'scales' => ['x' => ['stacked' => $empile, 'grid' => ['display' => false]]] + $axesY,
        ];
    }

    /**
     * @param list<string> $entetes
     * @param list<string> $libelles
     * @param list<list<int|float>> $colonnes
     * @param list<bool>            $unites  vrai pour une colonne en euros
     *
     * @return Bloc
     */
    private function bloc(Chart $chart, array $entetes, array $libelles, array $colonnes, array $unites): array
    {
        $lignes = [];
        foreach ($libelles as $i => $libelle) {
            $ligne = [$libelle];
            foreach ($colonnes as $colonne) {
                $ligne[] = $colonne[$i] ?? 0;
            }
            $lignes[] = $ligne;
        }

        return ['chart' => $chart, 'entetes' => $entetes, 'lignes' => $lignes, 'unites' => $unites];
    }

    /** @param list<int> $centimes @return list<float> */
    private static function euros(array $centimes): array
    {
        return array_map(static fn (int $c): float => round($c / 100, 2), $centimes);
    }

    private function t(string $cle): string
    {
        return $this->traducteur->trans('administration.tableau_de_bord.graphiques.'.$cle);
    }
}
