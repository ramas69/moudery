<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\RoleVille;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use PHPUnit\Framework\TestCase;

final class VilleTest extends TestCase
{
    private Association $association;

    protected function setUp(): void
    {
        $this->association = new Association('Association de Moudery', 'moudery');
    }

    public function testUneVilleNaitEnBrouillonALEtapeIdentite(): void
    {
        $ville = new Ville($this->association, 'Lyon');

        self::assertSame(VilleStatut::Brouillon, $ville->getStatut());
        self::assertTrue($ville->estBrouillon());
        self::assertSame(EtapeAssistant::Identite, $ville->getEtapeAssistant());
        self::assertCount(0, $ville->getInvitations());
    }

    public function testLeNomEstNormalise(): void
    {
        $ville = new Ville($this->association, "  Lyon   3e \n");

        self::assertSame('Lyon 3e', $ville->getNom());

        $ville->renommer(' Villeurbanne ');
        self::assertSame('Villeurbanne', $ville->getNom());
    }

    public function testDefinirUnResponsableCreeUneInvitationNonEnvoyee(): void
    {
        $ville = new Ville($this->association, 'Lyon');
        $ville->definirResponsable(RoleVille::Tresorier, ' Tresorier@Example.org ');

        $invitation = $ville->invitationPour(RoleVille::Tresorier);
        self::assertNotNull($invitation);
        self::assertSame('tresorier@example.org', $invitation->getEmail());
        self::assertSame($this->association, $invitation->getAssociation());
        self::assertFalse($invitation->estEnvoyee());
        self::assertNull($invitation->getToken());
    }

    public function testRedefinirUnResponsableMetAJourLInvitationExistante(): void
    {
        $ville = new Ville($this->association, 'Lyon');
        $ville->definirResponsable(RoleVille::President, 'ancien@example.org');
        $invitation = $ville->invitationPour(RoleVille::President);

        $ville->definirResponsable(RoleVille::President, 'nouveau@example.org');

        self::assertCount(1, $ville->getInvitations());
        self::assertSame($invitation, $ville->invitationPour(RoleVille::President), 'La même invitation est réutilisée.');
        self::assertSame('nouveau@example.org', $invitation->getEmail());
    }

    public function testUneAdresseVideRetireLInvitation(): void
    {
        $ville = new Ville($this->association, 'Lyon');
        $ville->definirResponsable(RoleVille::Secretaire, 'secretaire@example.org');
        $ville->definirResponsable(RoleVille::Secretaire, '   ');

        self::assertNull($ville->invitationPour(RoleVille::Secretaire));
        self::assertCount(0, $ville->getInvitations());

        $ville->definirResponsable(RoleVille::Secretaire, null);
        self::assertCount(0, $ville->getInvitations());
    }

    public function testUneMemePersonnePeutCumulerPlusieursRoles(): void
    {
        $ville = new Ville($this->association, 'Lyon');
        $ville->definirResponsable(RoleVille::Tresorier, 'cumul@example.org');
        $ville->definirResponsable(RoleVille::Secretaire, 'cumul@example.org');

        self::assertCount(2, $ville->getInvitations());
    }

    public function testLePointDeRepriseNeReculeJamais(): void
    {
        $ville = new Ville($this->association, 'Lyon');

        $ville->avancerA(EtapeAssistant::Membres);
        self::assertSame(EtapeAssistant::Membres, $ville->getEtapeAssistant());

        $ville->avancerA(EtapeAssistant::Identite);
        self::assertSame(EtapeAssistant::Membres, $ville->getEtapeAssistant());
    }

    public function testMarquerUneInvitationEnvoyeeLaRendValable7Jours(): void
    {
        $ville = new Ville($this->association, 'Lyon');
        $ville->definirResponsable(RoleVille::Tresorier, 'tresorier@example.org');
        $invitation = $ville->invitationPour(RoleVille::Tresorier);
        self::assertNotNull($invitation);

        $envoi = new \DateTimeImmutable('2026-10-01 10:00:00');
        $invitation->marquerEnvoyee($envoi);

        self::assertTrue($invitation->estEnvoyee());
        self::assertSame(64, \strlen((string) $invitation->getToken()));
        self::assertEquals(new \DateTimeImmutable('2026-10-08 10:00:00'), $invitation->getExpireLe());

        $invitation->changerEmail('autre@example.org');
        self::assertFalse($invitation->estEnvoyee(), 'Un nouveau destinataire rend l’envoi précédent caduc.');
        self::assertNull($invitation->getToken());
    }
}
