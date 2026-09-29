<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Association;
use App\Entity\Foyer;
use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\Ville;
use PHPUnit\Framework\TestCase;

/** Membres et foyers d'une ville (F-42, F-09). */
final class MembreTest extends TestCase
{
    private Ville $lyon;

    protected function setUp(): void
    {
        $this->lyon = new Ville(new Association('Association de Moudery', 'moudery'), 'Lyon');
    }

    public function testUnMembreNaitActifDansSaVilleEtSonAssociation(): void
    {
        $membre = new Membre($this->lyon, ' Mamadou ', 'Diaby', 'Mamadou.Diaby@Example.org', '06 12 34 56 78');

        self::assertSame($this->lyon, $membre->getVille());
        self::assertSame($this->lyon->getAssociation(), $membre->getAssociation());
        self::assertSame('Mamadou', $membre->getPrenom());
        self::assertSame('Mamadou Diaby', $membre->getNomComplet());
        self::assertSame('MD', $membre->getInitiales());
        self::assertSame('mamadou.diaby@example.org', $membre->getEmail());
        self::assertSame('0612345678', $membre->getTelephone());
        self::assertSame('06 12 34 56 78', $membre->getTelephoneAffiche());
        self::assertSame(MembreStatut::Actif, $membre->getStatut());
        self::assertTrue($membre->estActif());
        self::assertSame(Membre::ORIGINE_SAISIE, $membre->getOrigine());
        self::assertNull($membre->getFoyer());
    }

    public function testLeContactEstFacultatifEtNormalise(): void
    {
        $membre = new Membre($this->lyon, 'Awa', 'Cissé', '  ', ' ');

        self::assertNull($membre->getEmail());
        self::assertNull($membre->getTelephone());
        self::assertNull($membre->getTelephoneAffiche());

        self::assertSame('+33612345678', Membre::normaliserTelephone('+33 6 12 34 56 78'));
        self::assertSame('+33612345678', (new Membre($this->lyon, 'Awa', 'Cissé', null, '+33 6 12 34 56 78'))->getTelephoneAffiche(), 'Un numéro international reste tel quel.');
        self::assertNull(Membre::normaliserTelephone('abc'));
    }

    public function testUnPrenomOuUnNomVideEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Membre($this->lyon, ' ', 'Diaby');
    }

    public function testLePremierMembreDUnFoyerEnEstLePayeur(): void
    {
        $foyer = new Foyer($this->lyon, ' Famille  Diaby ');
        $mamadou = new Membre($this->lyon, 'Mamadou', 'Diaby');
        $awa = new Membre($this->lyon, 'Awa', 'Diaby');

        self::assertSame('Famille Diaby', $foyer->getNom());
        self::assertNull($foyer->getPayeur());

        $mamadou->rejoindreFoyer($foyer);
        $awa->rejoindreFoyer($foyer);

        self::assertCount(2, $foyer->getMembres());
        self::assertSame($mamadou, $foyer->getPayeur());

        $foyer->designerPayeur($awa);
        self::assertSame($awa, $foyer->getPayeur());

        $awa->rejoindreFoyer(null);
        self::assertCount(1, $foyer->getMembres());
        self::assertSame($mamadou, $foyer->getPayeur(), 'Quand le payeur part, un autre membre du foyer prend le relais.');
        self::assertNull($awa->getFoyer());
    }

    public function testLePayeurEstForcementUnMembreDuFoyer(): void
    {
        $foyer = new Foyer($this->lyon, 'Famille Diaby');
        $etranger = new Membre($this->lyon, 'Seydou', 'Sylla');

        $this->expectException(\LogicException::class);

        $foyer->designerPayeur($etranger);
    }

    public function testUnFoyerEtSesMembresSontDeLaMemeVille(): void
    {
        $marseille = new Ville($this->lyon->getAssociation(), 'Marseille');
        $foyer = new Foyer($marseille, 'Famille Diaby');
        $membre = new Membre($this->lyon, 'Mamadou', 'Diaby');

        $this->expectException(\LogicException::class);

        $membre->rejoindreFoyer($foyer);
    }

    public function testChangerDeFoyerQuitteLAncien(): void
    {
        $diaby = new Foyer($this->lyon, 'Famille Diaby');
        $cisse = new Foyer($this->lyon, 'Famille Cissé');
        $membre = new Membre($this->lyon, 'Awa', 'Cissé');

        $membre->rejoindreFoyer($diaby);
        $membre->rejoindreFoyer($cisse);

        self::assertCount(0, $diaby->getMembres());
        self::assertNull($diaby->getPayeur());
        self::assertSame($cisse, $membre->getFoyer());
        self::assertSame($membre, $cisse->getPayeur());
    }

    public function testSortirRetireDuFoyer(): void
    {
        $foyer = new Foyer($this->lyon, 'Famille Diaby');
        $membre = new Membre($this->lyon, 'Mamadou', 'Diaby', null, null, MembreStatut::EnAttente);
        $membre->rejoindreFoyer($foyer);

        $membre->valider();
        self::assertSame(MembreStatut::Actif, $membre->getStatut());

        $membre->sortir();
        self::assertSame(MembreStatut::Sorti, $membre->getStatut());
        self::assertNull($membre->getFoyer());
        self::assertCount(0, $foyer->getMembres());
    }
}
