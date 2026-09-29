<?php

declare(strict_types=1);

namespace App\Tests\Administration;

use App\Administration\Periode;
use PHPUnit\Framework\TestCase;

/** La fenêtre de temps des graphiques : clés connues, tranches, dates libres, repérage d'une date. */
final class PeriodeTest extends TestCase
{
    private \DateTimeImmutable $aujourdhui;

    protected function setUp(): void
    {
        $this->aujourdhui = new \DateTimeImmutable('2026-09-27 14:00');
    }

    public function testDouzeMoisParDefaut(): void
    {
        $periode = Periode::depuis(null, null, null, $this->aujourdhui);

        self::assertSame('12m', $periode->cle);
        self::assertSame('mois', $periode->pas);
        self::assertSame('2025-10-01', $periode->debut->format('Y-m-d'));
        self::assertSame('2026-09-28', $periode->fin->format('Y-m-d'), 'La fin est exclue : demain à minuit.');
        $tranches = $periode->tranches();
        self::assertCount(12, $tranches);
        self::assertSame('2025-10', $tranches[0]['cle']);
        self::assertSame('2026-09', $tranches[11]['cle']);
        self::assertSame('2026-09-28', $tranches[11]['fin']->format('Y-m-d'), 'La dernière tranche s’arrête à la fin de la période.');
        self::assertSame('12m', Periode::depuis('n’importe quoi', null, null, $this->aujourdhui)->cle);
    }

    public function testTrenteJoursTroisMoisEtAnnee(): void
    {
        $trenteJours = Periode::depuis('30j', null, null, $this->aujourdhui);
        self::assertSame('jour', $trenteJours->pas);
        self::assertCount(30, $trenteJours->tranches());
        self::assertSame('2026-08-29', $trenteJours->debut->format('Y-m-d'));

        $troisMois = Periode::depuis('3m', null, null, $this->aujourdhui);
        self::assertSame('semaine', $troisMois->pas);
        self::assertSame('1', $troisMois->debut->format('N'), 'Les semaines commencent le lundi.');
        self::assertSame(14, \count($troisMois->tranches()));

        $annee = Periode::depuis('annee', null, null, $this->aujourdhui);
        self::assertSame('2026-01-01', $annee->debut->format('Y-m-d'));
        self::assertCount(9, $annee->tranches());
    }

    public function testLesDatesLibresChoisissentLeurPas(): void
    {
        $courte = Periode::depuis('libre', '2026-09-01', '2026-09-15', $this->aujourdhui);
        self::assertSame('jour', $courte->pas);
        self::assertCount(15, $courte->tranches(), 'Du 1er au 15 inclus.');

        $moyenne = Periode::depuis('libre', '2026-05-01', '2026-09-15', $this->aujourdhui);
        self::assertSame('semaine', $moyenne->pas);

        $longue = Periode::depuis('libre', '2024-01-01', '2026-09-15', $this->aujourdhui);
        self::assertSame('mois', $longue->pas);
        self::assertCount(33, $longue->tranches());

        $inversee = Periode::depuis('libre', '2026-09-15', '2026-09-01', $this->aujourdhui);
        self::assertSame('2026-09-16', $inversee->fin->format('Y-m-d'), 'Une fin avant le début donne un seul jour.');

        $invalide = Periode::depuis('libre', 'hier', '31/12/2026', $this->aujourdhui);
        self::assertSame('2025-09-01', $invalide->debut->format('Y-m-d'), 'Dates illisibles : douze mois glissants.');
    }

    public function testRepererUneDate(): void
    {
        $periode = Periode::depuis('12m', null, null, $this->aujourdhui);

        self::assertSame(0, $periode->indice(new \DateTimeImmutable('2025-10-15')));
        self::assertSame(11, $periode->indice(new \DateTimeImmutable('2026-09-27 23:00')));
        self::assertNull($periode->indice(new \DateTimeImmutable('2025-09-30')), 'Avant la période.');
        self::assertNull($periode->indice(new \DateTimeImmutable('2026-09-28')), 'La fin est exclue.');
        self::assertSame(362, $periode->nombreDeJours());
    }
}
