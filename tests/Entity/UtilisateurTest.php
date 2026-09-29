<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Association;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Entity\Ville;
use App\Security\Permission;
use App\Security\Role;
use PHPUnit\Framework\TestCase;

/** Comptes et affectations (F-03, F-07) : les droits découlent du rôle et de son périmètre. */
final class UtilisateurTest extends TestCase
{
    private Association $moudery;
    private Association $bakel;

    protected function setUp(): void
    {
        $this->moudery = new Association('Association de Moudery', 'moudery');
        $this->bakel = new Association('Association de Bakel', 'bakel');
    }

    public function testUnCompteNaitEnAttenteAvecUnEmailNormalise(): void
    {
        $compte = new Utilisateur($this->moudery, ' Awa.Cisse@Example.org ', ' Awa ', 'Cissé', 'hache');

        self::assertSame('awa.cisse@example.org', $compte->getEmail());
        self::assertSame('awa.cisse@example.org', $compte->getUserIdentifier());
        self::assertSame('Awa Cissé', $compte->getNomComplet());
        self::assertSame(UtilisateurStatut::EnAttente, $compte->getStatut());
        self::assertFalse($compte->estActif());
        self::assertSame(['ROLE_USER'], $compte->getRoles());

        $compte->activer();
        self::assertTrue($compte->estActif());
    }

    public function testLeSuperAdminAgitSurTouteLaPlateforme(): void
    {
        $compte = new Utilisateur(null, 'admin@example.org', 'Rama', 'Diallo', 'hache', UtilisateurStatut::Actif);
        $compte->affecter(Role::SuperAdmin);
        $lyon = new Ville($this->moudery, 'Lyon');

        self::assertNull($compte->getAssociation(), 'Le super-admin n’appartient à aucune association.');
        self::assertTrue($compte->aLaPermissionSurLaPlateforme(Permission::PLATEFORME_ADMINISTRER));
        self::assertTrue($compte->aLaPermissionSurLaPlateforme(Permission::ASSOCIATION_CREER));
        self::assertTrue($compte->aLaPermission(Permission::ASSOCIATION_CREER, $this->moudery));
        self::assertTrue($compte->aLaPermission(Permission::ASSOCIATION_CREER, $this->bakel), 'Son périmètre est toute la plateforme.');
        // Décision du 27 septembre 2026 : le super-admin peut tout consulter et tout modifier, y compris les villes.
        self::assertFalse($compte->aLaPermission(Permission::VILLE_CREER, $this->moudery), 'Seul le bureau central crée une ville, même le super-admin ne le fait pas.');
        self::assertTrue($compte->aLaPermission(Permission::VILLE_MODIFIER, $lyon));
        self::assertNull($compte->premiereAffectation(Role::SuperAdmin)?->getAssociation());
    }

    public function testUnCompteSansAssociationNeRecoitQueLeRoleSuperAdmin(): void
    {
        $compte = new Utilisateur(null, 'admin@example.org', 'Rama', 'Diallo', 'hache');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rattaché à une association');
        $compte->affecter(Role::BureauCentral);
    }

    public function testLeBureauCentralAgitSurTouteSonAssociationEtRienDAutre(): void
    {
        $compte = new Utilisateur($this->moudery, 'central@moudery.fr', 'Mamadou', 'Diaby', 'hache');
        $compte->affecter(Role::BureauCentral);
        $lyon = new Ville($this->moudery, 'Lyon');
        $dakar = new Ville($this->bakel, 'Dakar');

        self::assertTrue($compte->aLaPermission(Permission::VILLE_CREER, $this->moudery));
        self::assertTrue($compte->aLaPermission(Permission::VILLE_MODIFIER, $lyon));
        self::assertFalse($compte->aLaPermissionSurLaPlateforme(Permission::PLATEFORME_ADMINISTRER), 'Le bureau central n’administre pas la plateforme.');
        self::assertFalse($compte->aLaPermission(Permission::VILLE_CREER, $this->bakel), 'Une autre association est hors périmètre.');
        self::assertFalse($compte->aLaPermission(Permission::VILLE_MODIFIER, $dakar));
        self::assertTrue($compte->aLeRole(Role::BureauCentral));
    }

    public function testLeTresorierNAgitQueSurSaVille(): void
    {
        $lyon = new Ville($this->moudery, 'Lyon');
        $marseille = new Ville($this->moudery, 'Marseille');
        $compte = new Utilisateur($this->moudery, 'tresorier@example.org', 'Seydou', 'Diakité', 'hache');
        $compte->affecter(Role::Tresorier, $lyon);

        self::assertTrue($compte->aLaPermission(Permission::VILLE_MODIFIER, $lyon));
        self::assertFalse($compte->aLaPermission(Permission::VILLE_MODIFIER, $marseille), 'Le trésorier de Lyon n’a aucun accès à Marseille.');
        self::assertFalse($compte->aLaPermission(Permission::VILLE_CREER, $this->moudery), 'Seul le bureau central crée une ville.');
    }

    public function testUnMembreNAAucunDroitDeGestion(): void
    {
        $lyon = new Ville($this->moudery, 'Lyon');
        $compte = new Utilisateur($this->moudery, 'awa@example.org', 'Awa', 'Cissé', 'hache');
        $compte->affecter(Role::Membre, $lyon);

        self::assertFalse($compte->aLaPermission(Permission::VILLE_MODIFIER, $lyon));
        self::assertFalse($compte->aLaPermission(Permission::VILLE_CREER, $this->moudery));
    }

    public function testLaMemeAffectationNEstPasCreeeDeuxFois(): void
    {
        $lyon = new Ville($this->moudery, 'Lyon');
        $compte = new Utilisateur($this->moudery, 'cumul@example.org', 'Awa', 'Cissé', 'hache');

        $premiere = $compte->affecter(Role::Tresorier, $lyon);
        $seconde = $compte->affecter(Role::Tresorier, $lyon);
        $compte->affecter(Role::Secretaire, $lyon);

        self::assertSame($premiere, $seconde);
        self::assertCount(2, $compte->getAffectations(), 'Une même personne peut cumuler plusieurs rôles.');
    }

    public function testUnRoleDeVilleExigeUneVilleDeLAssociationDuCompte(): void
    {
        $compte = new Utilisateur($this->moudery, 'tresorier@example.org', 'Seydou', 'Diakité', 'hache');

        try {
            $compte->affecter(Role::Tresorier);
            self::fail('Un rôle de ville sans ville doit être refusé.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('sur une ville', $e->getMessage());
        }

        try {
            $compte->affecter(Role::BureauCentral, new Ville($this->moudery, 'Lyon'));
            self::fail('Le bureau central ne s’attribue pas sur une ville.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ne s\'attribue pas sur une ville', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $compte->affecter(Role::Tresorier, new Ville($this->bakel, 'Dakar'));
    }

    public function testLeJetonDeReinitialisationExpireEtNeSertQuUneFois(): void
    {
        $compte = new Utilisateur($this->moudery, 'central@moudery.fr', 'Mamadou', 'Diaby', 'hache');
        $maintenant = new \DateTimeImmutable('2026-10-01 10:00:00');

        self::assertFalse($compte->jetonReinitialisationValide($maintenant));

        $compte->demanderReinitialisation('empreinte', $maintenant->modify('+1 hour'));
        self::assertTrue($compte->jetonReinitialisationValide($maintenant));
        self::assertFalse($compte->jetonReinitialisationValide($maintenant->modify('+61 minutes')), 'Le lien expire après une heure.');

        $compte->terminerReinitialisation();
        self::assertFalse($compte->jetonReinitialisationValide($maintenant));
        self::assertNull($compte->getJetonReinitialisation());
    }

    public function testEnSessionSeulLEssentielEstConserve(): void
    {
        $compte = new Utilisateur($this->moudery, 'central@moudery.fr', 'Mamadou', 'Diaby', 'hache', UtilisateurStatut::Actif);
        $compte->affecter(Role::BureauCentral);

        $copie = unserialize(serialize($compte));
        \assert($copie instanceof Utilisateur);

        self::assertSame('central@moudery.fr', $copie->getUserIdentifier());
        self::assertSame('hache', $copie->getPassword());
        self::assertTrue($copie->estActif());
        self::assertCount(0, $copie->getAffectations(), 'Les affectations sont rechargées depuis la base, pas depuis la session.');
    }
}
