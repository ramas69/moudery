<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Abonnement\Catalogue;
use App\Entity\Association;
use App\Entity\Invitation;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Entity\Ville;
use App\Security\Role;
use PHPUnit\Framework\TestCase;

/** Invitation à créer son compte (F-02) : 7 jours, une seule fois. */
final class InvitationTest extends TestCase
{
    private Association $moudery;
    private \DateTimeImmutable $envoi;

    protected function setUp(): void
    {
        $this->moudery = new Association('Association de Moudery', 'moudery');
        $this->envoi = new \DateTimeImmutable('2026-10-01 10:00:00');
    }

    public function testUneInvitationVautSeptJours(): void
    {
        $invitation = new Invitation($this->moudery, Role::BureauCentral, ' Mamadou@Example.org ', 'empreinte', $this->envoi);

        self::assertSame('mamadou@example.org', $invitation->getEmail());
        self::assertEquals(new \DateTimeImmutable('2026-10-08 10:00:00'), $invitation->getExpireLe());
        self::assertTrue($invitation->estValide($this->envoi->modify('+6 days')));
        self::assertFalse($invitation->estValide($this->envoi->modify('+7 days')), 'Le lien expire au bout de 7 jours.');
        self::assertTrue($invitation->estExpiree($this->envoi->modify('+8 days')));
        self::assertNull($invitation->getVille(), 'Le bureau central couvre toute l’association.');
    }

    public function testUneInvitationNeSertQuUneFois(): void
    {
        $invitation = new Invitation($this->moudery, Role::BureauCentral, 'mamadou@example.org', 'empreinte', $this->envoi);
        $compte = new Utilisateur($this->moudery, 'mamadou@example.org', 'Mamadou', 'Diaby', 'hache', UtilisateurStatut::Actif);

        $invitation->accepter($compte, $this->envoi->modify('+1 day'));

        self::assertTrue($invitation->estAcceptee());
        self::assertSame($compte, $invitation->getCompte());
        self::assertFalse($invitation->estValide($this->envoi->modify('+1 day')));
        self::assertFalse($invitation->estExpiree($this->envoi->modify('+30 days')), 'Acceptée n’est pas expirée.');

        $this->expectException(\LogicException::class);
        $invitation->emettre('autre', $this->envoi->modify('+2 days'));
    }

    public function testUneInvitationExpireeSeRenvoieAvecUnNouveauLien(): void
    {
        $invitation = new Invitation($this->moudery, Role::BureauCentral, 'mamadou@example.org', 'empreinte', $this->envoi);
        $renvoi = $this->envoi->modify('+10 days');
        self::assertTrue($invitation->estExpiree($renvoi));

        $invitation->emettre('nouvelle-empreinte', $renvoi);

        self::assertTrue($invitation->estValide($renvoi->modify('+1 day')));
        self::assertEquals($renvoi->modify('+7 days'), $invitation->getExpireLe());
        self::assertEquals($renvoi, $invitation->getEnvoyeeLe());
    }

    public function testSeuleLInvitationDuBureauCentralDUneAssociationSansAbonnementOuvreLaSouscription(): void
    {
        $bureauCentral = new Invitation($this->moudery, Role::BureauCentral, 'mamadou@example.org', 'empreinte', $this->envoi);
        self::assertFalse($bureauCentral->ouvreLaSouscription(), 'Sans abonnement ouvert, rien à souscrire.');

        $this->moudery->ouvrirAbonnement();
        self::assertTrue($bureauCentral->ouvreLaSouscription());

        $lyon = new Ville($this->moudery, 'Lyon', 'lyon');
        $tresorier = new Invitation($this->moudery, Role::Tresorier, 'seydou@example.org', 'empreinte', $this->envoi, null, $lyon);
        self::assertFalse($tresorier->ouvreLaSouscription(), 'Un responsable de ville active seulement son compte.');

        $offre = Catalogue::offre(Catalogue::CODE_MENSUEL);
        self::assertNotNull($offre);
        $this->moudery->ouvrirAbonnement()->souscrire($offre, $this->envoi);
        self::assertFalse($bureauCentral->ouvreLaSouscription(), 'Une fois souscrit, le lien active simplement le compte.');
    }

    public function testLeRoleDoitCorrespondreAuPerimetre(): void
    {
        $lyon = new Ville($this->moudery, 'Lyon');

        $tresorier = new Invitation($this->moudery, Role::Tresorier, 'seydou@example.org', 'empreinte', $this->envoi, null, $lyon);
        self::assertSame($lyon, $tresorier->getVille());

        try {
            new Invitation($this->moudery, Role::Tresorier, 'seydou@example.org', 'empreinte', $this->envoi);
            self::fail('Un rôle de ville sans ville doit être refusé.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('sur une ville', $e->getMessage());
        }

        try {
            new Invitation($this->moudery, Role::BureauCentral, 'mamadou@example.org', 'empreinte', $this->envoi, null, $lyon);
            self::fail('Le bureau central ne s’invite pas sur une ville.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ne s\'attribue pas sur une ville', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        new Invitation($this->moudery, Role::SuperAdmin, 'admin@example.org', 'empreinte', $this->envoi);
    }
}
