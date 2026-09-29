<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Administration\GestionComptes;
use App\Entity\Evenement;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Security\Role;

/**
 * Garde-fous de l'emprunt d'identité : jamais un administrateur, administration fermée pendant l'emprunt,
 * journal au nom de la vraie personne, et le dernier super-admin garde son rôle.
 */
final class EmpruntSecuriteTest extends CasDeTestWeb
{
    private int $moudery;
    private int $central;
    private int $admin;
    private int $collegue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->central = $this->creerUtilisateur($this->moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->admin = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);
        $this->collegue = $this->creerUtilisateur(null, 'collegue@example.org', Role::SuperAdmin);
    }

    public function testOnNEmprunteJamaisLIdentiteDUnAdministrateur(): void
    {
        $this->connecter($this->admin);

        $this->client->request('GET', '/administration/voir-comme');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/?_voir_comme=central@moudery.fr"]');
        self::assertSelectorNotExists('a[href="/?_voir_comme=collegue@example.org"]', 'Un autre super-admin n’est pas proposé.');

        $this->client->request('GET', '/?_voir_comme=collegue@example.org');
        self::assertResponseStatusCodeSame(403, 'Ni accessible en forçant l’adresse.');
    }

    public function testLAdministrationEstFermeePendantLEmprunt(): void
    {
        $this->connecter($this->admin);
        $this->client->request('GET', '/?_voir_comme=central@moudery.fr');
        self::assertResponseRedirects('/', 302);
        $this->client->followRedirect();
        self::assertResponseRedirects('/associations/moudery', 302, 'Vu comme le bureau central : son tableau de bord.');

        $this->client->request('GET', '/administration');
        self::assertResponseStatusCodeSame(403, 'Vu comme un bureau central, plus d’administration.');
        $this->client->request('GET', '/administration/comptes/'.$this->admin);
        self::assertResponseStatusCodeSame(403);

        $affectation = $this->em()->find(Utilisateur::class, $this->admin)?->premiereAffectation(Role::SuperAdmin);
        self::assertNotNull($affectation);
        $this->client->request('POST', \sprintf('/administration/comptes/%d/affectations/%d/retirer', $this->admin, $affectation->getId()), ['_token' => 'peu-importe']);
        // Sous l'identité d'un bureau central, le filtre multi-tenant cache même l'affectation du super-admin : 404, jamais une action.
        self::assertResponseStatusCodeSame(404, 'Retirer un rôle sous emprunt est impossible, quel que soit le jeton.');
        self::assertTrue($this->em()->find(Utilisateur::class, $this->admin)?->aLeRole(Role::SuperAdmin) ?? false, 'Le super-admin garde son rôle.');

        // De retour sur son compte, l'administration rouvre.
        $this->client->request('GET', '/?_voir_comme=_exit');
        $this->client->request('GET', '/administration');
        self::assertResponseIsSuccessful();
    }

    public function testSousEmpruntLeJournalNommeLaVraiePersonne(): void
    {
        $this->connecter($this->admin);
        $this->client->request('GET', '/?_voir_comme=central@moudery.fr');
        $this->client->request('GET', '/associations/moudery/villes/nouvelle');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Enregistrer et continuer', ['ville_identite[nom]' => 'Lyon', 'ville_identite[emailTresorier]' => 'tresorier@example.org']);
        self::assertResponseRedirects();

        $evenement = $this->em()->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::VilleCreee], ['id' => 'DESC']);
        self::assertNotNull($evenement);
        self::assertSame('Lyon', $evenement->getCible());
        self::assertSame('admin@example.org', $evenement->getActeur()?->getEmail(), 'C’est le super-admin qui a agi.');
        self::assertSame('central@moudery.fr', $evenement->detail('vu_comme'), 'Sous l’identité du bureau central.');
    }

    public function testLeDernierSuperAdminActifNeSeDesactivePas(): void
    {
        $this->connecter($this->admin);

        // Deux super-admins : l'un peut désactiver l'autre.
        $this->client->request('GET', '/administration/comptes/'.$this->collegue);
        $this->client->submitForm('Désactiver le compte');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'désactivé');
        self::assertFalse($this->em()->find(Utilisateur::class, $this->collegue)?->estActif() ?? true);

        // Il ne reste qu'un super-admin actif : le désactiver est refusé, même par un service.
        $em = $this->em();
        $admin = $em->find(Utilisateur::class, $this->admin);
        \assert($admin instanceof Utilisateur);
        $gestion = static::getContainer()->get(GestionComptes::class);
        \assert($gestion instanceof GestionComptes);
        try {
            $gestion->desactiver($admin);
            self::fail('Le dernier super-admin actif ne se désactive pas.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('dernier super-admin actif', $e->getMessage());
        }
        self::assertTrue($admin->estActif());
    }

    public function testLeDernierSuperAdminActifGardeSonRole(): void
    {
        $this->connecter($this->admin);

        // Deux super-admins : l'un peut retirer le rôle de l'autre.
        $this->client->request('GET', '/administration/comptes/'.$this->collegue);
        self::assertResponseIsSuccessful();
        $this->client->submit($this->client->getCrawler()->filter('form.retrait')->last()->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Super-admin');
        self::assertFalse($this->em()->find(Utilisateur::class, $this->collegue)?->aLeRole(Role::SuperAdmin) ?? true);

        // Il ne reste qu'un super-admin actif : son rôle est intouchable, même par un service.
        $em = $this->em();
        $admin = $em->find(Utilisateur::class, $this->admin);
        \assert($admin instanceof Utilisateur);
        $affectation = $admin->premiereAffectation(Role::SuperAdmin);
        self::assertNotNull($affectation);
        $gestion = static::getContainer()->get(GestionComptes::class);
        \assert($gestion instanceof GestionComptes);
        $this->expectException(\LogicException::class);
        $gestion->retirerAffectation($admin, $affectation);
    }
}
