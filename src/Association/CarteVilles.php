<?php

declare(strict_types=1);

namespace App\Association;

use App\Administration\Texte;

/**
 * G-03 « Quelles villes pèsent le plus ? » (29 septembre 2026) : une carte de France en SVG, une bulle par ville dont
 * la surface suit le montant collecté (ou le nombre de membres tant que rien n'est collecté). Pas de géocodage en ligne :
 * les coordonnées viennent d'un petit dictionnaire des villes où vit la diaspora (métropoles, Île-de-France), et une
 * ville inconnue est listée sous la carte, jamais placée au hasard. Le contour de la France est volontairement simplifié.
 *
 * @phpstan-type Bulle array{ville: string, valeur: int, membres: int, x: float, y: float, r: float}
 */
final class CarteVilles
{
    public const LARGEUR = 360;
    public const HAUTEUR = 350;
    private const MARGE = 10;
    private const LON_MIN = -5.2;
    private const LON_MAX = 9.7;
    private const LAT_MIN = 41.3;
    private const LAT_MAX = 51.2;
    private const RAYON_MIN = 5.0;
    private const RAYON_MAX = 30.0;

    /** Contour simplifié de la France métropolitaine (latitude, longitude), dans le sens des aiguilles d'une montre. */
    private const CONTOUR = [
        [51.05, 2.37], [50.95, 1.85], [50.73, 1.6], [50.06, 1.37], [49.49, 0.1], [49.35, -0.4], [49.67, -1.26], [49.72, -1.94],
        [48.84, -1.6], [48.65, -2.0], [48.82, -3.44], [48.39, -4.49], [48.04, -4.73], [47.75, -3.37], [47.27, -2.2], [46.5, -1.78],
        [46.16, -1.15], [45.62, -1.03], [44.66, -1.17], [43.48, -1.56], [43.36, -1.77], [43.16, -1.24], [42.85, 0.14], [42.6, 1.4],
        [42.46, 2.86], [42.44, 3.17], [42.7, 3.03], [43.18, 3.2], [43.4, 3.7], [43.53, 3.93], [43.35, 4.6], [43.3, 5.37],
        [43.1, 5.93], [43.27, 6.64], [43.55, 7.0], [43.7, 7.27], [43.78, 7.5], [44.4, 6.9], [45.1, 6.75], [45.83, 6.86],
        [46.2, 6.15], [46.5, 6.1], [47.5, 7.0], [47.59, 7.59], [48.57, 7.8], [48.97, 8.23], [49.2, 6.9], [49.5, 6.2],
        [49.52, 5.77], [50.14, 4.82], [50.0, 4.2], [50.28, 4.0], [50.4, 3.6], [50.7, 3.2], [51.09, 2.55],
    ];

    private const CORSE = [[43.0, 9.4], [42.6, 9.5], [41.9, 9.4], [41.37, 9.2], [41.6, 8.8], [42.0, 8.6], [42.4, 8.6], [42.7, 9.1]];

    /** Les villes connues (nom normalisé => latitude, longitude). */
    private const VILLES = [
        'paris' => [48.857, 2.352], 'lyon' => [45.764, 4.836], 'marseille' => [43.296, 5.37], 'toulouse' => [43.605, 1.444],
        'nice' => [43.71, 7.262], 'nantes' => [47.218, -1.554], 'strasbourg' => [48.573, 7.752], 'montpellier' => [43.611, 3.877],
        'bordeaux' => [44.838, -0.579], 'lille' => [50.629, 3.057], 'rennes' => [48.117, -1.678], 'reims' => [49.258, 4.032],
        'le havre' => [49.494, 0.108], 'saint etienne' => [45.44, 4.387], 'toulon' => [43.124, 5.928], 'grenoble' => [45.188, 5.724],
        'dijon' => [47.322, 5.041], 'angers' => [47.478, -0.563], 'nimes' => [43.837, 4.36], 'villeurbanne' => [45.767, 4.88],
        'le mans' => [48.006, 0.199], 'clermont ferrand' => [45.777, 3.087], 'aix en provence' => [43.529, 5.447], 'brest' => [48.39, -4.486],
        'tours' => [47.394, 0.685], 'amiens' => [49.894, 2.296], 'limoges' => [45.833, 1.262], 'rouen' => [49.443, 1.1],
        'orleans' => [47.903, 1.909], 'metz' => [49.119, 6.176], 'mulhouse' => [47.75, 7.335], 'caen' => [49.183, -0.371],
        'nancy' => [48.692, 6.184], 'perpignan' => [42.699, 2.895], 'besancon' => [47.238, 6.024], 'creil' => [49.259, 2.475],
        'montreuil' => [48.861, 2.443], 'saint denis' => [48.936, 2.357], 'aulnay sous bois' => [48.934, 2.497], 'argenteuil' => [48.947, 2.248],
        'evry' => [48.629, 2.441], 'mantes la jolie' => [48.99, 1.717], 'poitiers' => [46.58, 0.34], 'pau' => [43.295, -0.37],
        'la rochelle' => [46.16, -1.15], 'valence' => [44.933, 4.892], 'annecy' => [45.899, 6.129], 'troyes' => [48.297, 4.074],
        'chartres' => [48.447, 1.489], 'beauvais' => [49.43, 2.08], 'melun' => [48.539, 2.66], 'cergy' => [49.036, 2.063],
        'meaux' => [48.96, 2.88], 'bobigny' => [48.908, 2.44], 'nanterre' => [48.892, 2.207], 'creteil' => [48.79, 2.455],
        'versailles' => [48.8, 2.13], 'avignon' => [43.949, 4.806], 'saint nazaire' => [47.273, -2.214], 'dunkerque' => [51.034, 2.377],
        'calais' => [50.95, 1.858], 'colmar' => [48.079, 7.358], 'venissieux' => [45.697, 4.886], 'bron' => [45.738, 4.913],
        'vaulx en velin' => [45.778, 4.921], 'sarcelles' => [48.997, 2.379], 'garges les gonesse' => [48.973, 2.4], 'sevran' => [48.938, 2.527],
        'saint ouen' => [48.911, 2.333], 'aubervilliers' => [48.914, 2.382], 'ivry sur seine' => [48.813, 2.387], 'vitry sur seine' => [48.787, 2.392],
        'epinay sur seine' => [48.955, 2.309], 'les mureaux' => [48.991, 1.911], 'trappes' => [48.777, 2.001], 'corbeil essonnes' => [48.611, 2.482],
        'grigny' => [48.654, 2.393], 'massy' => [48.731, 2.271], 'bondy' => [48.902, 2.483], 'noisy le grand' => [48.849, 2.553],
        'toulouse le mirail' => [43.588, 1.401], 'marne la vallee' => [48.85, 2.64], 'montfermeil' => [48.898, 2.567], 'clichy sous bois' => [48.91, 2.546],
    ];

    /**
     * @param list<array{ville: string, valeur: int, membres: int}> $villes
     *
     * @return array{contour: string, corse: string, bulles: list<Bulle>, absentes: list<string>, mesure: 'collecte'|'membres', largeur: int, hauteur: int}
     */
    public static function construire(array $villes): array
    {
        $mesure = array_sum(array_column($villes, 'valeur')) > 0 ? 'collecte' : 'membres';
        $cle = 'collecte' === $mesure ? 'valeur' : 'membres';
        $maximum = max(1, ...array_map(static fn (array $v): int => $v[$cle], $villes ?: [['valeur' => 0, 'membres' => 0]]));
        $bulles = [];
        $absentes = [];
        foreach ($villes as $v) {
            $coordonnees = self::coordonnees($v['ville']);
            if (null === $coordonnees) {
                $absentes[] = $v['ville'];
                continue;
            }
            [$x, $y] = self::projeter($coordonnees[0], $coordonnees[1]);
            $bulles[] = [
                'ville' => $v['ville'],
                'valeur' => $v['valeur'],
                'membres' => $v['membres'],
                'x' => $x,
                'y' => $y,
                'r' => round(self::RAYON_MIN + (self::RAYON_MAX - self::RAYON_MIN) * sqrt($v[$cle] / $maximum), 2),
            ];
        }
        // Les grosses bulles d'abord : les petites restent visibles et cliquables par-dessus.
        usort($bulles, static fn (array $a, array $b): int => $b['r'] <=> $a['r']);

        return [
            'contour' => self::chemin(self::CONTOUR),
            'corse' => self::chemin(self::CORSE),
            'bulles' => $bulles,
            'absentes' => $absentes,
            'mesure' => $mesure,
            'largeur' => self::LARGEUR,
            'hauteur' => self::HAUTEUR,
        ];
    }

    /** @return array{0: float, 1: float}|null */
    public static function coordonnees(string $ville): ?array
    {
        $nom = trim((string) preg_replace('/[^a-z0-9]+/', ' ', Texte::normaliser($ville)));

        return self::VILLES[$nom] ?? null;
    }

    /** @return array{0: float, 1: float} */
    private static function projeter(float $lat, float $lon): array
    {
        $cos = cos(deg2rad(46.5));
        $largeurUtile = self::LARGEUR - 2 * self::MARGE;
        $hauteurUtile = self::HAUTEUR - 2 * self::MARGE;
        $echelle = min($largeurUtile / ((self::LON_MAX - self::LON_MIN) * $cos), $hauteurUtile / (self::LAT_MAX - self::LAT_MIN));

        return [
            round(self::MARGE + ($lon - self::LON_MIN) * $cos * $echelle, 2),
            round(self::MARGE + (self::LAT_MAX - $lat) * $echelle, 2),
        ];
    }

    /** @param list<array{0: float, 1: float}> $points */
    private static function chemin(array $points): string
    {
        $morceaux = [];
        foreach ($points as $i => [$lat, $lon]) {
            [$x, $y] = self::projeter($lat, $lon);
            $morceaux[] = (0 === $i ? 'M' : 'L').$x.' '.$y;
        }

        return implode('', $morceaux).'Z';
    }
}
