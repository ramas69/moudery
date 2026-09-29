<?php

declare(strict_types=1);

namespace App\Association;

use App\Entity\Association;
use App\Entity\Ville;

/**
 * G-02 « Où va chaque euro collecté ? » (29 septembre 2026) : un diagramme de flux (Sankey) en trois colonnes, calculé
 * ici et dessiné en SVG par `composants/flux.html.twig` (aucune librairie). À gauche, ce que les membres ont versé
 * (cotisations, contributions ponctuelles) ; au milieu, les caisses des villes ; à droite, ce que les villes en font :
 * dépenses payées, part due au bureau central, et ce qui reste dans leurs caisses. Les chiffres viennent des mêmes
 * services que le tableau de bord et le rapport d'AG (`TableauDeBordAssociation::finances()`, `Reversements::tableau()`),
 * donc les totaux sont identiques partout.
 *
 * Avec le filtre de type (cotisations seules ou contributions ponctuelles seules), la colonne de droite disparaît : les
 * dépenses et la part du central ne se ventilent pas par type de contribution.
 *
 * @phpstan-type Noeud array{cle: string, libelle: string, valeur: int, colonne: int, x: float, y: float, h: float}
 * @phpstan-type Lien array{source: string, cible: string, valeur: int, chemin: string, classe: string}
 */
final class FluxArgent
{
    public const LARGEUR = 760;
    public const HAUTEUR = 340;
    private const NOEUD = 14;
    private const ECART = 10;
    private const COLONNES = [180, 405, 600];

    public function __construct(
        private readonly TableauDeBordAssociation $tableau,
        private readonly Reversements $reversements,
    ) {
    }

    /**
     * @param 'tout'|'cotisations'|'ponctuelles' $type
     *
     * @return array{noeuds: list<Noeud>, liens: list<Lien>, total: int, sorties: bool, largeur: int, hauteur: int, lignes: list<array{ville: string, cotisations: int, ponctuelles: int, depense: int, du: int, reste: int}>}
     */
    public function construire(Association $association, \DateTimeImmutable $aujourdhui, int $annee, ?Ville $seule = null, string $type = 'tout'): array
    {
        $reversements = $this->reversements->tableau($association, $aujourdhui, $annee, $seule);
        $lignes = [];
        foreach ($reversements['lignes'] as $ligne) {
            $ville = $ligne['ville'];
            if (null !== $seule && $ville !== $seule) {
                continue;
            }
            $f = $this->tableau->finances($association, $ville, $aujourdhui, $annee);
            $cotisations = 'ponctuelles' === $type ? 0 : $f['cotisations'];
            $ponctuelles = 'cotisations' === $type ? 0 : $f['ponctuelles'];
            $depense = $f['depense'];
            $du = $ligne['du'];
            $lignes[] = [
                'ville' => $ville->getNom(),
                'cotisations' => $cotisations,
                'ponctuelles' => $ponctuelles,
                'depense' => $depense,
                'du' => $du,
                'reste' => max(0, $f['collecte'] - $depense - $du),
            ];
        }

        return self::geometrie($lignes, 'tout' === $type);
    }

    /**
     * La géométrie du diagramme à partir des lignes par ville (public pour les tests).
     *
     * @param list<array{ville: string, cotisations: int, ponctuelles: int, depense: int, du: int, reste: int}> $lignes
     *
     * @return array{noeuds: list<Noeud>, liens: list<Lien>, total: int, sorties: bool, largeur: int, hauteur: int, lignes: list<array{ville: string, cotisations: int, ponctuelles: int, depense: int, du: int, reste: int}>}
     */
    public static function geometrie(array $lignes, bool $sorties): array
    {
        $entrees = ['cotisations' => 0, 'ponctuelles' => 0];
        $destinations = ['depense' => 0, 'du' => 0, 'reste' => 0];
        $villes = [];
        foreach ($lignes as $l) {
            $entrant = $l['cotisations'] + $l['ponctuelles'];
            $sortant = $sorties ? $l['depense'] + $l['du'] + $l['reste'] : 0;
            if (0 === $entrant && 0 === $sortant) {
                continue;
            }
            $villes[$l['ville']] = max($entrant, $sortant);
            $entrees['cotisations'] += $l['cotisations'];
            $entrees['ponctuelles'] += $l['ponctuelles'];
            if ($sorties) {
                foreach (array_keys($destinations) as $d) {
                    $destinations[$d] += $l[$d];
                }
            }
        }
        $entrees = array_filter($entrees);
        $destinations = array_filter($destinations);
        $total = array_sum($entrees);
        $vide = ['noeuds' => [], 'liens' => [], 'total' => $total, 'sorties' => $sorties, 'largeur' => self::LARGEUR, 'hauteur' => self::HAUTEUR, 'lignes' => $lignes];
        if ([] === $villes) {
            return $vide;
        }

        // Une échelle commune aux trois colonnes, pour que les bandes gardent la même épaisseur d'un bout à l'autre.
        $colonnes = [$entrees, $villes, $destinations];
        $maximum = 1;
        foreach ($colonnes as $c) {
            $utile = self::HAUTEUR - self::ECART * max(0, \count($c) - 1);
            $maximum = max($maximum, array_sum($c) / max(1, $utile));
        }
        $noeuds = [];
        foreach ($colonnes as $indice => $valeurs) {
            $hauteur = array_sum($valeurs) / $maximum + self::ECART * max(0, \count($valeurs) - 1);
            $y = (self::HAUTEUR - $hauteur) / 2;
            foreach ($valeurs as $cle => $valeur) {
                $h = max(2.0, $valeur / $maximum);
                $noeuds[$indice.':'.$cle] = ['cle' => (string) $cle, 'libelle' => (string) $cle, 'valeur' => $valeur, 'colonne' => $indice, 'x' => (float) self::COLONNES[$indice], 'y' => round($y, 2), 'h' => round($h, 2)];
                $y += $h + self::ECART;
            }
        }

        // Les liens, empilés dans l'ordre des nœuds à chaque extrémité.
        $curseurs = array_map(static fn (array $n): float => $n['y'], $noeuds);
        $liens = [];
        $ajouter = static function (string $de, string $vers, int $valeur, string $classe) use (&$liens, &$curseurs, $noeuds, $maximum): void {
            if ($valeur <= 0 || !isset($noeuds[$de], $noeuds[$vers])) {
                return;
            }
            $epaisseur = $valeur / $maximum;
            $x0 = $noeuds[$de]['x'] + self::NOEUD;
            $x1 = $noeuds[$vers]['x'];
            $y0 = $curseurs[$de];
            $y1 = $curseurs[$vers];
            $curseurs[$de] += $epaisseur;
            $curseurs[$vers] += $epaisseur;
            $xm = ($x0 + $x1) / 2;
            $f = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
            $chemin = \sprintf('M%s %sC%s %s %s %s %s %sL%s %sC%s %s %s %s %s %sZ',
                $f($x0), $f($y0), $f($xm), $f($y0), $f($xm), $f($y1), $f($x1), $f($y1),
                $f($x1), $f($y1 + $epaisseur), $f($xm), $f($y1 + $epaisseur), $f($xm), $f($y0 + $epaisseur), $f($x0), $f($y0 + $epaisseur));
            $liens[] = ['source' => $noeuds[$de]['cle'], 'cible' => $noeuds[$vers]['cle'], 'valeur' => $valeur, 'chemin' => $chemin, 'classe' => $classe];
        };
        foreach (['cotisations', 'ponctuelles'] as $entree) {
            foreach ($lignes as $l) {
                if (isset($villes[$l['ville']])) {
                    $ajouter('0:'.$entree, '1:'.$l['ville'], $l[$entree], 'flux__lien--'.$entree);
                }
            }
        }
        if ($sorties) {
            foreach ($lignes as $l) {
                if (!isset($villes[$l['ville']])) {
                    continue;
                }
                foreach (['depense', 'du', 'reste'] as $d) {
                    $ajouter('1:'.$l['ville'], '2:'.$d, $l[$d], 'flux__lien--'.$d);
                }
            }
        }

        return ['noeuds' => array_values($noeuds), 'liens' => $liens, 'total' => $total, 'sorties' => $sorties, 'largeur' => self::LARGEUR, 'hauteur' => self::HAUTEUR, 'lignes' => $lignes];
    }
}
