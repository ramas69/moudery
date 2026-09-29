<?php

declare(strict_types=1);

namespace App\Tests\Administration;

use App\Administration\TableauVilles;
use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use PHPUnit\Framework\TestCase;

/** Liste des villes : recherche sans accents, filtres, tri, regroupement, pagination, association désignée. */
final class TableauVillesTest extends TestCase
{
    private Association $moudery;
    private Association $bakel;
    /** @var list<Ville> */
    private array $villes;
    private \DateTimeImmutable $aujourdhui;

    protected function setUp(): void
    {
        $this->moudery = new Association('Association de Moudery', 'moudery');
        $this->moudery->definirVillage('Moudery');
        $this->bakel = new Association('Amicale de Bakel', 'bakel');
        $this->aujourdhui = new \DateTimeImmutable('2026-10-01 12:00');

        $lyon = new Ville($this->moudery, 'Lyon');
        $lyon->avancerA(EtapeAssistant::Activation);
        $lyon->changerStatut(VilleStatut::Active);
        $evry = new Ville($this->moudery, 'Évry');
        $evry->avancerA(EtapeAssistant::Membres);
        $rouen = new Ville($this->bakel, 'Rouen');
        $this->villes = [$lyon, $evry, $rouen];
    }

    public function testLaRechercheIgnoreLesAccentsEtCherchePartout(): void
    {
        self::assertSame(['Évry'], $this->noms(TableauVilles::filtrer($this->villes, 'evry', 'tous', $this->aujourdhui)));
        self::assertSame(['Lyon', 'Évry'], $this->noms(TableauVilles::filtrer($this->villes, 'MOUDERY', 'tous', $this->aujourdhui)), 'Le village compte aussi.');
        self::assertSame(['Rouen'], $this->noms(TableauVilles::filtrer($this->villes, 'amicale', 'tous', $this->aujourdhui)));
        self::assertSame([], $this->noms(TableauVilles::filtrer($this->villes, 'paris', 'tous', $this->aujourdhui)));
    }

    public function testLesStatutsEtLesBrouillonsBloques(): void
    {
        self::assertSame(['Lyon'], $this->noms(TableauVilles::filtrer($this->villes, '', 'active', $this->aujourdhui)));
        self::assertSame(['Évry', 'Rouen'], $this->noms(TableauVilles::filtrer($this->villes, '', 'brouillon', $this->aujourdhui)));
        self::assertSame([], $this->noms(TableauVilles::filtrer($this->villes, '', 'bloquees', $this->aujourdhui)), 'Tout juste créées : rien de bloqué.');

        $dansUnMois = $this->aujourdhui->modify('+31 days');
        self::assertSame(['Évry', 'Rouen'], $this->noms(TableauVilles::filtrer($this->villes, '', 'bloquees', $dansUnMois)), 'Un brouillon sans changement depuis 30 jours est bloqué ; une ville active jamais.');
        self::assertSame(['tous' => 3, 'brouillon' => 2, 'active' => 1, 'archivee' => 0, 'bloquees' => 2], TableauVilles::compter($this->villes, $dansUnMois));

        self::assertSame('tous', TableauVilles::statut('inconnu'));
        self::assertSame('bloquees', TableauVilles::statut('bloquees'));
    }

    public function testLeTriEtLeRegroupement(): void
    {
        self::assertSame(['Évry', 'Lyon', 'Rouen'], $this->noms(TableauVilles::trier($this->villes, 'ville', 'asc')), 'É se range avec E.');
        self::assertSame(['Rouen', 'Lyon', 'Évry'], $this->noms(TableauVilles::trier($this->villes, 'ville', 'desc')));
        self::assertSame(['Lyon', 'Évry', 'Rouen'], $this->noms(TableauVilles::trier($this->villes, 'etape', 'desc')), 'Activation, puis Membres, puis Identité.');
        self::assertSame(['Lyon', 'Évry', 'Rouen'], $this->noms(TableauVilles::trier($this->villes, 'statut', 'desc')), 'Statut décroissant, puis le nom croissant à égalité.');
        self::assertSame(['Rouen', 'Évry', 'Lyon'], $this->noms(TableauVilles::trier($this->villes, 'ville', 'asc', true)), 'Groupé : Amicale de Bakel avant Association de Moudery, puis le tri.');
        self::assertSame('association', TableauVilles::tri('n’importe quoi'));
        self::assertSame('asc', TableauVilles::sens('haut'));
    }

    public function testLaPagination(): void
    {
        $pagination = TableauVilles::paginer($this->villes, 1, 25);
        self::assertSame(['villes' => $this->villes, 'page' => 1, 'pages' => 1, 'total' => 3, 'de' => 1, 'a' => 3, 'parPage' => 25], $pagination);

        $villes = [];
        for ($i = 1; $i <= 60; ++$i) {
            $villes[] = new Ville($this->moudery, 'Ville '.$i);
        }
        $page = TableauVilles::paginer($villes, 3, 25);
        self::assertSame([3, 3, 60, 51, 60, 10], [$page['page'], $page['pages'], $page['total'], $page['de'], $page['a'], \count($page['villes'])]);
        self::assertSame('Ville 51', $page['villes'][0]->getNom());

        $auDela = TableauVilles::paginer($villes, 99, 50);
        self::assertSame(2, $auDela['page'], 'Une page hors limite ramène à la dernière.');
        self::assertSame(['villes' => [], 'page' => 1, 'pages' => 1, 'total' => 0, 'de' => 0, 'a' => 0, 'parPage' => 25], TableauVilles::paginer([], 1, 25));

        self::assertSame(25, TableauVilles::parPage('12'));
        self::assertSame(100, TableauVilles::parPage(100));
        self::assertSame(1, TableauVilles::page('-4'));
    }

    public function testLAssociationDesigneeParLeSelecteur(): void
    {
        $associations = [$this->bakel, $this->moudery];
        self::assertSame($this->moudery, TableauVilles::associationCorrespondante($associations, 'moudery'), 'Par identifiant.');
        self::assertSame($this->moudery, TableauVilles::associationCorrespondante($associations, ' association de MOUDERY '), 'Par nom, sans casse.');
        self::assertSame($this->bakel, TableauVilles::associationCorrespondante($associations, 'amic'), 'Par début de nom quand une seule correspond.');
        self::assertNull(TableauVilles::associationCorrespondante($associations, 'de'), 'Ambigu : aucune.');
        self::assertNull(TableauVilles::associationCorrespondante($associations, ''));
        self::assertNull(TableauVilles::associationCorrespondante($associations, 'Paris'));
    }

    /** @param list<Ville> $villes @return list<string> */
    private function noms(array $villes): array
    {
        return array_map(static fn (Ville $v): string => $v->getNom(), $villes);
    }
}
