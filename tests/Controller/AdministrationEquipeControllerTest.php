<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Evenement;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Security\Permission;
use App\Security\Role;
use Symfony\Component\Mime\Email;

/**
 * L'équipe de la plateforme : le super-admin ajoute un collègue, qui choisit son mot de passe depuis le lien reçu,
 * ou promeut un compte existant. Une personne peut être super-admin et appartenir à une association.
 */
final class AdministrationEquipeControllerTest extends CasDeTestWeb
{
    public function testLeBureauCentralNAccedePasALEquipe(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral));

        $this->client->request('GET', '/administration/equipe');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/administration/equipe/ajouter');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeSuperAdminVoitSonEquipeAvecOuSansAssociation(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $central = $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->creerUtilisateur($moudery, 'rama@moudery.fr', Role::SuperAdmin);
        $this->creerUtilisateur(null, 'staff@example.org', Role::SuperAdmin);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/equipe');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Équipe de la plateforme');
        self::assertSelectorTextContains('.console__sous-titre', '3 super-admins');
        self::assertSelectorTextContains('.tableau', 'admin@example.org');
        self::assertSelectorTextContains('.tableau', 'staff@example.org');
        self::assertSelectorTextContains('.tableau', 'rama@moudery.fr');
        self::assertSelectorTextContains('.tableau', 'Association de Moudery', 'Un super-admin rattaché à une association l’affiche.');
        self::assertSelectorTextNotContains('.tableau', 'central@moudery.fr', 'Le bureau central n’est pas super-admin.');
        self::assertSelectorTextContains('.tableau', 'Mot de passe à choisir');
        self::assertSelectorTextContains('.tableau .chip', 'Vous');
        self::assertSelectorTextContains('.tableau .entite__nom a', 'Awa Cissé', 'Le nom complet est affiché à côté des initiales.');
        self::assertCount(2, $this->client->getCrawler()->filter('form.renvoi-lien'), 'Le lien ne se renvoie qu’aux collègues qui ne se sont jamais connectés.');
        self::assertSelectorExists('a[href="/administration/equipe/ajouter"]');
        self::assertSelectorExists(\sprintf('select[name="promotion_super_admin[compte]"] option[value="%d"]', $central), 'Le bureau central peut être promu.');

        $this->client->request('GET', '/administration/equipe?q=staff');
        self::assertSelectorTextContains('.tableau', 'staff@example.org');
        self::assertSelectorTextNotContains('.tableau', 'admin@example.org');
        self::assertSelectorExists('input[name="q"][value="staff"]');
        $this->client->request('GET', '/administration/equipe?q=moudery');
        self::assertSelectorTextContains('.tableau', 'rama@moudery.fr', 'L’association compte dans la recherche.');
        self::assertSelectorTextNotContains('.tableau', 'staff@example.org');
        $this->client->request('GET', '/administration/equipe?q=personne');
        self::assertSelectorTextContains('.tableau', 'Aucun super-admin ne correspond');
    }

    public function testLeSuperAdminAjouteUnCollegueQuiChoisitSonMotDePasse(): void
    {
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/equipe/ajouter');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Ajouter et envoyer le lien', [
            'equipe_invitation[prenom]' => ' Fatou ',
            'equipe_invitation[nom]' => 'Ndiaye',
            'equipe_invitation[email]' => 'Fatou@Example.org',
            'equipe_invitation[association]' => '',
        ]);
        self::assertResponseRedirects('/administration/equipe', 303);
        self::assertQueuedEmailCount(1);

        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'to', 'fatou@example.org');
        self::assertEmailTextBodyContains($email, 'Awa Cissé vous a ajouté');
        self::assertSame(1, preg_match('#/mot-de-passe/reinitialiser/([a-f0-9]{64})#', (string) $email->getTextBody(), $correspondance));
        $lien = '/mot-de-passe/reinitialiser/'.$correspondance[1];

        $compte = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => 'fatou@example.org']);
        self::assertNotNull($compte);
        self::assertNull($compte->getAssociation(), 'Sans association choisie, le compte est un compte de la plateforme.');
        self::assertTrue($compte->estActif());
        self::assertTrue($compte->aLeRole(Role::SuperAdmin));
        self::assertSame('Fatou Ndiaye', $compte->getNomComplet());
        $creation = $this->em()->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::CompteCree]);
        self::assertSame('fatou@example.org', $creation?->getCible());
        self::assertSame('admin@example.org', $creation?->getActeur()?->getEmail());
        self::assertSame('super-admin', $creation?->getDetails()['role'] ?? null);
        self::assertCount(1, $this->em()->getRepository(Evenement::class)->findBy(['type' => TypeEvenement::CompteLienMotDePasse]));

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'fatou@example.org');
        self::assertSelectorTextContains('.tableau', 'Fatou Ndiaye');

        // La collègue choisit son mot de passe depuis le lien, puis se connecte et arrive sur l'administration.
        $this->client->submit($crawler->filter('form.deconnexion')->form());
        $this->client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Choisir un nouveau mot de passe');
        $this->client->submitForm('Enregistrer le mot de passe', [
            'nouveau_mot_de_passe[motDePasse]' => 'Equipe-Moudery-2026!',
            'nouveau_mot_de_passe[confirmation]' => 'Equipe-Moudery-2026!',
        ]);
        self::assertResponseRedirects('/connexion', 303);

        $this->client->followRedirect();
        $this->client->submitForm('Se connecter', ['email' => 'fatou@example.org', 'mot_de_passe' => 'Equipe-Moudery-2026!']);
        self::assertResponseRedirects('http://localhost/', 302);
        $this->client->followRedirect();
        self::assertResponseRedirects('/administration', 302);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__nom', 'Fatou Ndiaye');

        $this->client->request('GET', $lien);
        self::assertResponseRedirects('/mot-de-passe-oublie', 303, 'Le lien ne sert qu’une fois.');
    }

    public function testUnSuperAdminPeutEtreRattacheAUneAssociation(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/equipe/ajouter');
        $this->client->submitForm('Ajouter et envoyer le lien', [
            'equipe_invitation[prenom]' => 'Mamadou',
            'equipe_invitation[nom]' => 'Diaby',
            'equipe_invitation[email]' => 'mamadou@moudery.fr',
            'equipe_invitation[association]' => (string) $moudery,
        ]);
        self::assertResponseRedirects('/administration/equipe', 303);

        $compte = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => 'mamadou@moudery.fr']);
        self::assertNotNull($compte);
        self::assertSame('moudery', $compte->getAssociation()?->getSlug());
        self::assertTrue($compte->aLeRole(Role::SuperAdmin));
        self::assertTrue($compte->aLaPermissionSurLaPlateforme(Permission::PLATEFORME_ADMINISTRER));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.tableau', 'Association de Moudery');
    }

    public function testLeSuperAdminPromeutUnCompteExistantQuiGardeSesRoles(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $central = $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/equipe');
        $this->client->submitForm('Promouvoir', ['promotion_super_admin[compte]' => (string) $central]);
        self::assertResponseRedirects('/administration/equipe', 303);

        $compte = $this->em()->find(Utilisateur::class, $central);
        self::assertTrue($compte?->aLeRole(Role::SuperAdmin));
        self::assertTrue($compte?->aLeRole(Role::BureauCentral), 'La promotion ne retire aucun rôle.');
        self::assertTrue($compte?->aLaPermissionSurLaPlateforme(Permission::PLATEFORME_ADMINISTRER));
        $promotion = $this->em()->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::RoleAttribue]);
        self::assertSame('central@moudery.fr', $promotion?->getCible());
        self::assertSame(['role' => 'super-admin', 'ville' => null], $promotion?->getDetails());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'super-admin');
        self::assertSelectorTextContains('.tableau', 'central@moudery.fr');
        self::assertSelectorTextContains('.console__sous-titre', '2 super-admins');
        self::assertSelectorNotExists(\sprintf('select[name="promotion_super_admin[compte]"] option[value="%d"]', $central), 'Un super-admin ne se promeut pas deux fois.');

        // Connecté, le bureau central devenu super-admin arrive sur l'administration.
        $this->client->submit($crawler->filter('form.deconnexion')->form());
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'central@moudery.fr', 'mot_de_passe' => self::MOT_DE_PASSE]);
        self::assertResponseRedirects('http://localhost/', 302);
        $this->client->followRedirect();
        self::assertResponseRedirects('/administration', 302);
    }

    public function testUneAdresseDejaUtiliseeEstRefusee(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/equipe/ajouter');
        $this->client->submitForm('Ajouter et envoyer le lien', [
            'equipe_invitation[prenom]' => 'Mamadou',
            'equipe_invitation[nom]' => 'Diaby',
            'equipe_invitation[email]' => 'central@moudery.fr',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#equipe_invitation_email_erreur', 'existe déjà');
        self::assertQueuedEmailCount(0);
        self::assertCount(2, $this->em()->getRepository(Utilisateur::class)->findAll());
    }

    public function testLeLienSeRenvoieAUnCollegueQuiNeSEstJamaisConnecte(): void
    {
        $staff = $this->creerUtilisateur(null, 'staff@example.org', Role::SuperAdmin);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/equipe');
        $this->client->submitForm('Renvoyer le lien');
        self::assertResponseRedirects('/administration/equipe', 303);
        self::assertQueuedEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'to', 'staff@example.org');
        self::assertNotNull($this->em()->find(Utilisateur::class, $staff)?->getJetonReinitialisation());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'staff@example.org');
    }

    public function testDepuisLEquipeLaFicheGardeLeContexteDeLEquipe(): void
    {
        $staff = $this->creerUtilisateur(null, 'staff@example.org', Role::SuperAdmin);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/equipe');
        self::assertSelectorExists(\sprintf('a[href="/administration/comptes/%d?depuis=equipe"]', $staff));

        $this->client->request('GET', '/administration/comptes/'.$staff.'?depuis=equipe');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__lien[aria-current="page"]', 'Équipe');
        self::assertSelectorExists('a.fil-ariane[href="/administration/equipe"]');
        self::assertSelectorExists(\sprintf('a[href="/administration/comptes/%d/modifier?depuis=equipe"]', $staff));

        // Une action depuis cette fiche y ramène, toujours dans le contexte de l'équipe.
        $this->client->submitForm('Désactiver le compte');
        self::assertResponseRedirects('/administration/comptes/'.$staff.'?depuis=equipe', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.console__lien[aria-current="page"]', 'Équipe');
        self::assertSelectorTextContains('.alerte--succes', 'désactivé');

        // Sans contexte, la fiche reste sous « Comptes ».
        $this->client->request('GET', '/administration/comptes/'.$staff);
        self::assertSelectorTextContains('.console__lien[aria-current="page"]', 'Comptes');
        self::assertSelectorExists('a.fil-ariane[href="/administration/comptes"]');
    }
}
