<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Adhesion;
use App\Entity\Association;
use App\Entity\Membre;
use App\Entity\Ville;
use PHPUnit\Framework\TestCase;

/** Adhésions par année, localité et année de naissance d'un membre : ce que les classeurs des villes savent et qu'on garde (F-45). */
final class AdhesionTest extends TestCase
{
    private Membre $membre;

    protected function setUp(): void
    {
        $this->membre = new Membre(new Ville(new Association('Association de Moudery', 'moudery'), 'Paris'), 'Mamadou', 'Diaby');
    }

    public function testUnMembreAdhereUneFoisParAnnee(): void
    {
        self::assertSame([], $this->membre->getAnneesAdhesion());

        $adhesion = $this->membre->adherer(2020, Membre::ORIGINE_IMPORT);
        $this->membre->adherer(2019, Membre::ORIGINE_IMPORT);
        self::assertSame($adhesion, $this->membre->adherer(2020), 'La même année redonnée ne crée rien.');

        self::assertSame([2019, 2020], $this->membre->getAnneesAdhesion());
        self::assertTrue($this->membre->estAdherent(2019));
        self::assertFalse($this->membre->estAdherent(2021));
        self::assertSame($this->membre->getVille(), $adhesion->getVille());
        self::assertSame($this->membre->getAssociation(), $adhesion->getAssociation());
        self::assertSame(Membre::ORIGINE_IMPORT, $adhesion->getOrigine());
    }

    public function testUneAnneeInvraisemblableEstRefusee(): void
    {
        self::assertFalse(Adhesion::anneeValide(1989));
        self::assertFalse(Adhesion::anneeValide((int) date('Y') + 2));
        self::assertTrue(Adhesion::anneeValide((int) date('Y')));

        $this->expectException(\InvalidArgumentException::class);
        $this->membre->adherer(1850);
    }

    public function testLaLocaliteEtLAnneeDeNaissanceSeRenseignentEtSEffacent(): void
    {
        $this->membre->definirLocalite('  Creil ');
        $this->membre->definirAnneeNaissance(1990);

        self::assertSame('Creil', $this->membre->getLocalite());
        self::assertSame(1990, $this->membre->getAnneeNaissance());
        self::assertSame(30, $this->membre->getAge(2020));

        $this->membre->definirLocalite('');
        $this->membre->definirAnneeNaissance(null);
        self::assertNull($this->membre->getLocalite());
        self::assertNull($this->membre->getAge());

        $this->expectException(\InvalidArgumentException::class);
        $this->membre->definirAnneeNaissance((int) date('Y') + 1);
    }

    public function testLHistoriqueDuClasseurSeCalculeSansJamaisEtreImporte(): void
    {
        $adhesion = $this->membre->adherer(2020, Membre::ORIGINE_IMPORT);
        self::assertFalse($adhesion->aUnHistorique());
        self::assertSame(0, $adhesion->getTotal());
        self::assertNull($adhesion->getReste());

        $adhesion->definirHistorique([1 => 1000, 2 => 1000, 3 => 'v', 4 => null, 9 => 1000, 12 => 500, 13 => 9999], 2000, null);

        self::assertTrue($adhesion->aUnHistorique());
        self::assertSame(1000, $adhesion->getMontantMois(1));
        self::assertNull($adhesion->getMontantMois(3));
        self::assertSame('V', $adhesion->getCodeMois(3), 'Le code du classeur est gardé, en capitales.');
        self::assertNull($adhesion->getCodeMois(1));
        self::assertNull($adhesion->getMontantMois(4));
        self::assertSame(3500, $adhesion->getTotal(), 'La somme des mois versés, sans le rapatriement.');
        self::assertSame(1000, $adhesion->getTarifMensuel(), 'Le montant le plus fréquent.');
        self::assertSame(12000, $adhesion->getAttendu());
        self::assertSame(8500, $adhesion->getReste());
        self::assertSame(2000, $adhesion->getRapatriement());
        self::assertNull($adhesion->getProjet());
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], array_keys($adhesion->getMois()));
        self::assertSame($adhesion, $this->membre->adhesionPour(2020));
        self::assertNull($this->membre->adhesionPour(2021));
    }
}
