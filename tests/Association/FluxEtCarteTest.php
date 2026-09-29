<?php

declare(strict_types=1);

namespace App\Tests\Association;

use App\Association\CarteVilles;
use App\Association\FluxArgent;
use PHPUnit\Framework\TestCase;

/** G-02 (flux de l'argent) et G-03 (carte des villes) : la géométrie calculée côté serveur. */
final class FluxEtCarteTest extends TestCase
{
    public function testLeFluxRelieEntreesVillesEtDestinations(): void
    {
        $flux = FluxArgent::geometrie([
            ['ville' => 'Lyon', 'cotisations' => 10000, 'ponctuelles' => 2000, 'depense' => 3000, 'du' => 3000, 'reste' => 6000],
            ['ville' => 'Paris', 'cotisations' => 5000, 'ponctuelles' => 0, 'depense' => 0, 'du' => 1500, 'reste' => 3500],
            ['ville' => 'Rouen', 'cotisations' => 0, 'ponctuelles' => 0, 'depense' => 0, 'du' => 0, 'reste' => 0],
        ], true);
        self::assertSame(17000, $flux['total']);
        $cles = array_map(static fn (array $n): string => $n['colonne'].':'.$n['cle'], $flux['noeuds']);
        self::assertSame(['0:cotisations', '0:ponctuelles', '1:Lyon', '1:Paris', '2:depense', '2:du', '2:reste'], $cles, 'Rouen, sans mouvement, n’apparaît pas.');
        self::assertCount(8, $flux['liens'], 'Lyon : 2 entrées, 3 sorties ; Paris : 1 entrée, 2 sorties.');
        foreach ($flux['noeuds'] as $n) {
            self::assertGreaterThanOrEqual(0, $n['y']);
            self::assertLessThanOrEqual(FluxArgent::HAUTEUR + 0.01, $n['y'] + $n['h']);
        }
        self::assertStringStartsWith('M', $flux['liens'][0]['chemin']);

        $entrees = FluxArgent::geometrie([['ville' => 'Lyon', 'cotisations' => 10000, 'ponctuelles' => 0, 'depense' => 3000, 'du' => 3000, 'reste' => 4000]], false);
        self::assertSame(['cotisations', 'Lyon'], array_column($entrees['noeuds'], 'cle'), 'Sans sorties : entrées et villes seulement.');
        self::assertSame([], FluxArgent::geometrie([], true)['noeuds']);
    }

    public function testLaCartePlaceLesVillesConnues(): void
    {
        $carte = CarteVilles::construire([
            ['ville' => 'Lyon', 'valeur' => 40000, 'membres' => 140],
            ['ville' => 'Le Mans', 'valeur' => 10000, 'membres' => 30],
            ['ville' => 'Moudéry', 'valeur' => 500, 'membres' => 3],
        ]);
        self::assertSame('collecte', $carte['mesure']);
        self::assertSame(['Lyon', 'Le Mans'], array_column($carte['bulles'], 'ville'), 'La plus grosse bulle d’abord.');
        self::assertSame(['Moudéry'], $carte['absentes']);
        [$lyon, $leMans] = $carte['bulles'];
        self::assertGreaterThan($leMans['r'], $lyon['r']);
        self::assertGreaterThan($leMans['x'], $lyon['x'], 'Lyon est à l’est du Mans.');
        self::assertGreaterThan($leMans['y'], $lyon['y'], 'Lyon est au sud du Mans.');
        self::assertNotNull(CarteVilles::coordonnees('SAINT-ÉTIENNE'));

        $sansCollecte = CarteVilles::construire([['ville' => 'Paris', 'valeur' => 0, 'membres' => 12]]);
        self::assertSame('membres', $sansCollecte['mesure']);
    }
}
