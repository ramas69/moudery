<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Evenement;
use App\Entity\Invitation;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Security\Role;

/** Comptes de la plateforme : liste, invitation, identité, statut, rôles et lien de mot de passe, par le super-admin. */
final class AdministrationCompteControllerTest extends CasDeTestWeb
{
    public function testLeBureauCentralNAccedePasAuxComptesDeLAdministration(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $central = $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->connecter($central);

        $this->client->request('GET', '/administration/comptes');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/administration/comptes/'.$central);
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeSuperAdminListeLesComptes(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->creerUtilisateur($moudery, 'awa@example.org', Role::Membre, $lyon, UtilisateurStatut::EnAttente);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/comptes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__sous-titre', '3 comptes');
        self::assertSelectorTextContains('.tableau', 'central@moudery.fr');
        self::assertSelectorTextContains('.tableau', 'Bureau central');
        self::assertSelectorTextContains('.tableau', 'Membre · Lyon');
        self::assertSelectorTextContains('.tableau', 'Super-admin plateforme');
        self::assertSelectorTextContains('.tableau', 'En attente');

        $this->client->request('GET', '/administration/comptes?statut=en-attente');
        self::assertSelectorTextContains('.console__sous-titre', '1 compte');
        self::assertSelectorTextContains('.tableau', 'awa@example.org');
        self::assertSelectorTextNotContains('.tableau', 'central@moudery.fr');

        // La recherche ignore la casse et les accents, et se combine aux filtres.
        $this->client->request('GET', '/administration/comptes?q=CISSE');
        self::assertSelectorTextContains('.console__sous-titre', '3 comptes', 'Tous les comptes de test s’appellent Awa Cissé.');
        $this->client->request('GET', '/administration/comptes?q=lyon');
        self::assertSelectorTextContains('.tableau', 'awa@example.org', 'La ville du rôle compte dans la recherche.');
        self::assertSelectorTextNotContains('.tableau', 'central@moudery.fr');
        $this->client->request('GET', '/administration/comptes?q=moudery&statut=actif');
        self::assertSelectorTextContains('.tableau', 'central@moudery.fr');
        self::assertSelectorTextNotContains('.tableau', 'awa@example.org');
        self::assertSelectorExists('input[name="q"][value="moudery"]');
        self::assertSelectorExists('a[href="/administration/comptes?statut=actif"]', 'Effacer la recherche garde le filtre de statut.');
        $this->client->request('GET', '/administration/comptes?q=introuvable');
        self::assertSelectorTextContains('.tableau', 'Aucun compte ne correspond');
    }

    public function testLeSuperAdminInviteUnTresorierSurUneVille(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/comptes/inviter');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Envoyer l’invitation', [
            'invitation_compte[association]' => (string) $moudery,
            'invitation_compte[role]' => 'tresorier',
            'invitation_compte[ville]' => (string) $lyon,
            'invitation_compte[email]' => 'Seydou@Example.org',
        ]);
        self::assertResponseRedirects('/administration/comptes', 303);
        self::assertQueuedEmailCount(1);

        $invitation = $this->em()->getRepository(Invitation::class)->findOneBy(['email' => 'seydou@example.org']);
        self::assertNotNull($invitation);
        self::assertSame(Role::Tresorier, $invitation->getRole());
        self::assertSame('Lyon', $invitation->getVille()?->getNom());
        self::assertSame('admin@example.org', $invitation->getInviteePar()?->getEmail());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'seydou@example.org');

        // Un rôle d'association ne prend pas de ville, un rôle de ville en exige une.
        $this->client->request('GET', '/administration/comptes/inviter');
        $this->client->submitForm('Envoyer l’invitation', [
            'invitation_compte[association]' => (string) $moudery,
            'invitation_compte[role]' => 'bureau-central',
            'invitation_compte[ville]' => (string) $lyon,
            'invitation_compte[email]' => 'autre@example.org',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#invitation_compte_ville_erreur', 'toute l’association');

        $this->client->submitForm('Envoyer l’invitation', [
            'invitation_compte[association]' => (string) $moudery,
            'invitation_compte[role]' => 'secretaire',
            'invitation_compte[ville]' => '',
            'invitation_compte[email]' => 'autre@example.org',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#invitation_compte_ville_erreur', 'choisissez-la');
        self::assertCount(1, $this->em()->getRepository(Invitation::class)->findAll());
    }

    public function testLeSuperAdminModifieUnCompteEtSesRoles(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $central = $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->creerUtilisateur($moudery, 'autre@example.org');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/comptes/'.$central);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Awa Cissé');
        self::assertSelectorTextContains('.affectations', 'Bureau central');

        // Un rôle de plus, sur une ville.
        $this->client->submitForm('Ajouter un rôle', ['affectation[role]' => 'tresorier', 'affectation[ville]' => (string) $lyon]);
        self::assertResponseRedirects('/administration/comptes/'.$central, 303);
        self::assertCount(2, $this->em()->find(Utilisateur::class, $central)?->getAffectations() ?? []);
        $evenements = $this->em()->getRepository(Evenement::class)->findBy(['type' => TypeEvenement::RoleAttribue]);
        self::assertCount(1, $evenements, 'Un changement de rôle est tracé (cahier des charges, section 2).');
        self::assertSame('central@moudery.fr', $evenements[0]->getCible());
        self::assertSame('admin@example.org', $evenements[0]->getActeur()?->getEmail());
        self::assertSame(['role' => 'tresorier', 'ville' => 'Lyon'], $evenements[0]->getDetails());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Trésorier ou trésorière · Lyon');
        self::assertSelectorTextContains('.affectations', 'Lyon');

        // Le même rôle deux fois est refusé.
        $this->client->submitForm('Ajouter un rôle', ['affectation[role]' => 'tresorier', 'affectation[ville]' => (string) $lyon]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#affectation_role_erreur', 'déjà ce rôle');

        // Retrait du rôle ajouté.
        $formulaireRetrait = $this->client->getCrawler()->filter('form.retrait')->last()->form();
        $this->client->submit($formulaireRetrait);
        self::assertResponseRedirects('/administration/comptes/'.$central, 303);
        self::assertCount(1, $this->em()->find(Utilisateur::class, $central)?->getAffectations() ?? []);
        self::assertCount(1, $this->em()->getRepository(Evenement::class)->findBy(['type' => TypeEvenement::RoleRetire]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'retiré');

        // Identité et adresse.
        $this->client->request('GET', '/administration/comptes/'.$central.'/modifier');
        $this->client->submitForm('Enregistrer les modifications', [
            'compte_identite[prenom]' => 'Mamadou',
            'compte_identite[nom]' => 'Diaby',
            'compte_identite[email]' => 'Mamadou@Moudery.fr',
        ]);
        self::assertResponseRedirects('/administration/comptes/'.$central, 303);
        $compte = $this->em()->find(Utilisateur::class, $central);
        self::assertSame('Mamadou Diaby', $compte?->getNomComplet());
        self::assertSame('mamadou@moudery.fr', $compte?->getEmail());
        $modification = $this->em()->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::CompteModifie]);
        self::assertSame('mamadou@moudery.fr', $modification?->getCible());
        self::assertSame('central@moudery.fr', $modification?->getDetails()['avant']['email'] ?? null);

        // Une adresse déjà prise par un autre compte est refusée.
        $this->client->request('GET', '/administration/comptes/'.$central.'/modifier');
        $this->client->submitForm('Enregistrer les modifications', [
            'compte_identite[prenom]' => 'Mamadou',
            'compte_identite[nom]' => 'Diaby',
            'compte_identite[email]' => 'autre@example.org',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#compte_identite_email_erreur', 'existe déjà');
    }

    public function testLeSuperAdminDesactiveReactiveEtEnvoieUnLienDeMotDePasse(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $central = $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/comptes/'.$central);
        $this->client->submitForm('Désactiver le compte');
        self::assertResponseRedirects('/administration/comptes/'.$central, 303);
        self::assertSame(UtilisateurStatut::Desactive, $this->em()->find(Utilisateur::class, $central)?->getStatut());
        $statut = $this->em()->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::CompteStatut]);
        self::assertSame(['statut' => 'desactive'], $statut?->getDetails());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'désactivé');

        // Pas de lien de mot de passe pour un compte inactif.
        $this->client->submitForm('Envoyer un lien de mot de passe');
        self::assertResponseRedirects('/administration/comptes/'.$central, 303);
        self::assertQueuedEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'pas actif');

        $this->client->submitForm('Activer le compte');
        self::assertResponseRedirects('/administration/comptes/'.$central, 303);
        self::assertTrue($this->em()->find(Utilisateur::class, $central)?->estActif());
        $this->client->followRedirect();

        $this->client->submitForm('Envoyer un lien de mot de passe');
        self::assertResponseRedirects('/administration/comptes/'.$central, 303);
        self::assertQueuedEmailCount(1);
        self::assertCount(1, $this->em()->getRepository(Evenement::class)->findBy(['type' => TypeEvenement::CompteLienMotDePasse]), 'Seul l’envoi réussi est consigné.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'central@moudery.fr');
    }

    public function testLeSuperAdminNeRetirePasSonPropreRoleNiNeSeDesactive(): void
    {
        $admin = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);
        $this->connecter($admin);

        $this->client->request('GET', '/administration/comptes/'.$admin);
        self::assertResponseIsSuccessful();
        $this->client->submit($this->client->getCrawler()->filter('form.retrait')->first()->form());
        self::assertResponseRedirects('/administration/comptes/'.$admin, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'propre rôle');
        self::assertTrue($this->em()->find(Utilisateur::class, $admin)?->aLeRole(Role::SuperAdmin));

        $this->client->submitForm('Désactiver le compte');
        self::assertResponseRedirects('/administration/comptes/'.$admin, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'propre compte');
        self::assertTrue($this->em()->find(Utilisateur::class, $admin)?->estActif());
    }

    public function testUnCompteDAssociationPeutDevenirSuperAdminMaisUnCompteDePlateformeNeRecoitQueCeRole(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $central = $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $admin = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);
        $this->connecter($admin);

        // Règle du 27 septembre 2026 : le bureau central peut aussi être super-admin.
        $this->client->request('GET', '/administration/comptes/'.$central);
        $this->client->submitForm('Ajouter un rôle', ['affectation[role]' => 'super-admin', 'affectation[ville]' => '']);
        self::assertResponseRedirects('/administration/comptes/'.$central, 303);
        $compte = $this->em()->find(Utilisateur::class, $central);
        self::assertTrue($compte?->aLeRole(Role::SuperAdmin));
        self::assertTrue($compte?->aLeRole(Role::BureauCentral));

        // Un compte de la plateforme, sans association, ne peut recevoir aucun rôle d'association.
        $this->client->request('GET', '/administration/comptes/'.$admin);
        self::assertSelectorNotExists('select[name="affectation[role]"] option[value="tresorier"]');
        self::assertSelectorNotExists('form[action$="/affectations"]', 'Déjà super-admin, un compte de plateforme n’a plus aucun rôle à recevoir : le formulaire disparaît.');
        self::assertSelectorTextContains('.affectations', 'tous les rôles');

        // Sur un compte d'association, le bureau central déjà détenu n'est plus proposé, la ville l'est.
        $this->client->request('GET', '/administration/comptes/'.$central);
        self::assertSelectorNotExists('select[name="affectation[role]"] option[value="bureau-central"]');
        self::assertSelectorExists('select[name="affectation[ville]"]');
    }
}
