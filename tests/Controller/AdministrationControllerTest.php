<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\Invitation;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Security\Permission;
use App\Security\Role;
use Symfony\Component\Mime\Email;

/** Administration de la plateforme par le super-admin : les associations, leur création et l'invitation de leur bureau central (F-02). */
final class AdministrationControllerTest extends CasDeTestWeb
{
    public function testUnVisiteurAnonymeEstRenvoyeVersLaConnexion(): void
    {
        $this->client->request('GET', '/administration');

        self::assertResponseRedirects('http://localhost/connexion', 302);
    }

    public function testLeBureauCentralNAccedePasALAdministration(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral));

        $this->client->request('GET', '/administration');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/administration/associations/nouvelle');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeSuperAdminVoitLaPlateformeEnChiffres(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $this->creerVille($moudery, 'Marseille', [], EtapeAssistant::Activation);
        $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->creerUtilisateur($moudery, 'awa@example.org', Role::Membre, $lyon, UtilisateurStatut::EnAttente);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertSelectorTextContains('.console__sous-titre', '2 associations');
        self::assertSelectorTextContains('.kpis', 'Associations 2');
        self::assertSelectorTextContains('.kpis', 'Villes 2');
        self::assertSelectorTextContains('.kpis', '2 brouillons');
        self::assertSelectorTextContains('.kpis', 'Comptes 2', 'Le super-admin lui-même n’est compté dans aucune association.');
        self::assertSelectorTextContains('.kpis', '1 en attente');
        self::assertSelectorTextContains('#a-traiter', 'À traiter 1');
        self::assertSelectorTextContains('section[aria-labelledby="a-traiter"] .section__principal', 'Association de Bakel n’a pas de bureau central');
        self::assertSelectorExists('.tableau tbody tr:nth-child(1) .entite__nom');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(1)', 'Association de Bakel');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(1) .pastille--attente', 'Aucun compte');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(2)', 'Association de Moudery');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(2)', 'Awa Cissé', 'Le bureau central de chaque association est nommé.');
        self::assertSelectorExists('a[href="/administration/associations/nouvelle"]');
        self::assertSelectorExists(\sprintf('a[href="/administration/associations/%d"]', $bakel), 'Chaque association mène à sa fiche.');
    }

    public function testLeTableauDeBordSeFiltreParPeriodeAssociationEtStatut(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->creerAssociation('Amicale de Bakel', 'bakel');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $this->creerUtilisateur($moudery, 'tresorier@moudery.fr', Role::Tresorier, $lyon);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(8, 'canvas[data-controller="symfony--ux-chartjs--chart"]');
        self::assertSelectorTextContains('nav[aria-label="Période"] a[aria-current="true"]', '12 mois');
        self::assertSelectorTextContains('.note--serree', 'par mois · toutes les associations');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-croissance-titre"] .section__sous-titre', '2 associations, 1 ville et 1 compte');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-villes-titre"] .graphique__donnees', '2 · Membres');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-comptes-titre"] .section__sous-titre', '1 compte');

        $this->client->request('GET', '/administration?periode=3m&association=amicale&statut_association=actives&statut_ville=active&role=tresorier');
        self::assertSelectorTextContains('nav[aria-label="Période"] a[aria-current="true"]', '3 mois');
        self::assertSelectorTextContains('.note--serree', 'par semaine · Amicale de Bakel');
        self::assertSelectorExists('input[name="association"][value="Amicale de Bakel"]');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-croissance-titre"] .section__sous-titre', '1 association, aucune ville', 'Filtrée sur Bakel.');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-croissance-titre"] nav a[aria-current="true"]', 'Actives');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-villes-titre"] nav a[aria-current="true"]', 'Actives');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-comptes-titre"] nav a[aria-current="true"]', 'Trésorier');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-comptes-titre"] .section__sous-titre', 'Aucun compte', 'Bakel n’a pas de trésorier.');
        self::assertSelectorExists('a.bouton--discret[href="/administration"]', 'Tout effacer.');

        $this->client->request('GET', '/administration?periode=libre&du=2026-01-01&au=2026-03-31');
        self::assertSelectorTextContains('.note--serree', 'Du 1 janv. 2026 au 31 mars 2026, par semaine', 'Trois mois : le pas est la semaine.');
        self::assertSelectorExists('input[name="du"][value="2026-01-01"]');

        $this->client->request('GET', '/administration?periode=hier&role=super-admin&statut_ville=fermee');
        self::assertResponseIsSuccessful('Des paramètres inconnus retombent sur les valeurs par défaut.');
        self::assertSelectorTextContains('nav[aria-label="Période"] a[aria-current="true"]', '12 mois');
    }

    public function testLIdentifiantSeDeduitDuVillageSansLeRetaper(): void
    {
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/associations/nouvelle');
        self::assertSelectorExists('form[data-controller="identifiant"]');
        self::assertSelectorExists('input[name="association[village]"][data-identifiant-target="village"]');
        self::assertSelectorExists('details.formulaire__avance:not([open]) input[name="association[slug]"]', 'Le champ identifiant est replié.');
        self::assertSelectorTextContains('.identifiant', 'Adresse de l’association');

        // Le village suffit : « Moudéry » donne « moudery », sans rien retaper.
        $this->client->submitForm('Créer l’association et inviter', [
            'association[nom]' => 'Association des ressortissants de Moudéry',
            'association[village]' => 'Moudéry',
            'association[slug]' => '',
            'association[emailBureauCentral]' => 'rama@example.org',
        ]);
        self::assertResponseRedirects('/administration/associations', 303);
        $association = $this->em()->getRepository(Association::class)->findOneBy(['slug' => 'moudery']);
        self::assertNotNull($association, 'L’identifiant vient du village.');
        self::assertSame('Moudéry', $association->getVillage());

        // Un identifiant saisi l'emporte sur le village.
        $this->client->request('GET', '/administration/associations/nouvelle');
        $this->client->submitForm('Créer l’association et inviter', [
            'association[nom]' => 'Amicale de Bakel',
            'association[village]' => 'Bakel',
            'association[slug]' => 'bakel-amicale',
            'association[emailBureauCentral]' => 'amicale@example.org',
        ]);
        self::assertResponseRedirects('/administration/associations', 303);
        self::assertNotNull($this->em()->getRepository(Association::class)->findOneBy(['slug' => 'bakel-amicale']));

        // Un village déjà pris signale le conflit sur le champ village, et le panneau reste replié.
        $this->client->request('GET', '/administration/associations/nouvelle');
        $this->client->submitForm('Créer l’association et inviter', [
            'association[nom]' => 'Autre association de Moudéry',
            'association[village]' => 'moudery',
            'association[slug]' => '',
            'association[emailBureauCentral]' => 'autre@example.org',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#association_village_erreur', 'moudery');
        self::assertSelectorExists('details.formulaire__avance:not([open])');
    }

    public function testLeSuperAdminCreeUneAssociationEtInviteSonBureauCentral(): void
    {
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/associations/nouvelle');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Créer l’association et inviter', [
            'association[nom]' => '  Association de Bakel  ',
            'association[slug]' => '',
            'association[emailBureauCentral]' => 'Mamadou@Example.org',
        ]);
        self::assertResponseRedirects('/administration/associations', 303, 'La création mène à la liste des associations.');
        self::assertQueuedEmailCount(1);

        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'to', 'mamadou@example.org');
        self::assertEmailTextBodyContains($email, 'Association de Bakel');
        self::assertEmailTextBodyContains($email, 'Awa Cissé a ouvert');
        self::assertEmailTextBodyContains($email, 'Souscrire et créer mon compte', 'Le bureau central souscrit l’abonnement en activant son compte.');
        self::assertSame(1, preg_match('#/invitation/([a-f0-9]{64})#', (string) $email->getTextBody(), $correspondance));
        $lien = '/invitation/'.$correspondance[1];

        $association = $this->em()->getRepository(Association::class)->findOneBy(['slug' => 'association-de-bakel']);
        self::assertNotNull($association, 'L’identifiant est déduit du nom.');
        $invitation = $this->em()->getRepository(Invitation::class)->findOneBy(['email' => 'mamadou@example.org']);
        self::assertNotNull($invitation);
        self::assertSame(Role::BureauCentral, $invitation->getRole());
        self::assertSame('admin@example.org', $invitation->getInviteePar()?->getEmail());

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Associations');
        self::assertSelectorTextContains('.alerte--succes', 'mamadou@example.org');
        self::assertSelectorTextContains('.tableau tbody', 'Invitation envoyée');

        $this->client->request('GET', '/administration');
        self::assertSelectorTextContains('.tableau tbody', 'Invitation envoyée');
        self::assertSelectorTextContains('section[aria-labelledby="a-traiter"]', 'Association de Bakel attend son bureau central');
        self::assertSelectorExists('section[aria-labelledby="a-traiter"] a.section__ligne--lien', 'La ligne mène à la fiche, où l’invitation se renvoie.');

        // La personne invitée souscrit et active son compte depuis le lien, sans être connectée.
        $this->client->submit($this->client->getCrawler()->filter('form.deconnexion')->form());
        $this->client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Souscrire et activer mon compte');
        self::assertSelectorTextContains('.fiche', 'mamadou@example.org');
        self::assertSelectorTextContains('.fiche', 'Bureau central');
        self::assertSelectorTextContains('.fiche', 'Association de Bakel');
        self::assertSelectorTextContains('.fiche', 'Awa Cissé');
        self::assertSelectorExists('input#invitation-email[readonly][value="mamadou@example.org"]');

        $this->client->submitForm('Souscrire et ouvrir Association de Bakel', [
            'souscription[offre]' => 'standard-mensuel',
            'souscription[prenom]' => 'Mamadou',
            'souscription[nom]' => 'Diaby',
            'souscription[motDePasse]' => 'Bakel-Registre-2026!',
            'souscription[conditions]' => '1',
        ]);
        self::assertResponseRedirects('/', 303);

        $compte = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => 'mamadou@example.org']);
        self::assertNotNull($compte);
        self::assertTrue($compte->estActif(), 'Le compte invité est actif dès que le mot de passe est défini.');
        self::assertSame('Mamadou Diaby', $compte->getNomComplet());
        self::assertSame('association-de-bakel', $compte->getAssociation()?->getSlug());
        self::assertTrue($compte->aLaPermission(Permission::VILLE_CREER, $compte->getAssociation()));
        self::assertTrue($this->em()->getRepository(Invitation::class)->findOneBy(['email' => 'mamadou@example.org'])?->estAcceptee());

        $this->client->followRedirect();
        self::assertResponseRedirects('/associations/association-de-bakel', 302, 'Connecté d’office, le nouveau bureau central arrive sur son association.');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__nom', 'Mamadou Diaby');

        $this->client->request('GET', $lien);
        self::assertResponseStatusCodeSame(410, 'Le lien ne sert qu’une fois.');
        self::assertSelectorTextContains('h1', 'n’est plus valable');
    }

    public function testUneInvitationExpireeSeRenvoie(): void
    {
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $adminId = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);
        $ancienLien = $this->creerInvitationExpiree($bakel, 'mamadou@example.org', $adminId);

        $this->client->request('GET', $ancienLien);
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('h1', 'n’est plus valable');
        self::assertSelectorTextContains('.invitation__intro', 'a expiré');
        self::assertSelectorTextContains('.fiche', 'Bureau central');
        self::assertSelectorExists('form[action$="/nouveau-lien"] button', 'Maquette « Invitation expirée » : demander un nouveau lien.');

        // Demander un nouveau lien prévient la personne qui a invité, une seule fois par jour.
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/nouveau-lien"]')->form());
        self::assertResponseStatusCodeSame(410);
        self::assertQueuedEmailCount(1);
        self::assertSelectorTextContains('.alerte--succes', 'Votre demande est envoyée');
        $this->client->request('GET', $ancienLien);
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/nouveau-lien"]')->form());
        self::assertQueuedEmailCount(0, null, 'Pas deux demandes le même jour.');
        self::assertSelectorTextContains('.alerte--attention', 'déjà été envoyée');

        $this->connecter($adminId);
        $this->client->request('GET', '/administration');
        self::assertSelectorTextContains('section[aria-labelledby="a-traiter"]', 'a expiré');
        self::assertSelectorTextContains('.tableau tbody .pastille--erreur', 'Invitation expirée');

        // Le renvoi se fait depuis la fiche de l'association.
        $this->client->request('GET', '/administration/associations/'.$bakel);
        self::assertSelectorTextContains('section[aria-labelledby="comptes-titre"]', 'Aucun bureau central');
        self::assertSelectorTextContains('section[aria-labelledby="comptes-titre"]', 'expirée le');
        $this->client->submitForm('Renvoyer l’invitation');
        self::assertResponseRedirects('/administration/associations/'.$bakel, 303, 'Le renvoi ramène sur la fiche.');
        self::assertQueuedEmailCount(1);
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        preg_match('#/invitation/[a-f0-9]{64}#', (string) $email->getTextBody(), $correspondance);
        self::assertNotSame($ancienLien, $correspondance[0], 'Le renvoi émet un nouveau lien.');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'mamadou@example.org');
        self::assertSelectorTextContains('section[aria-labelledby="comptes-titre"]', 'valable jusqu’au');
        $this->client->request('GET', '/administration');
        self::assertSelectorTextContains('.tableau tbody .pastille--attente', 'Invitation envoyée');

        $this->client->request('GET', $ancienLien);
        self::assertResponseStatusCodeSame(404, 'L’ancien lien ne mène plus nulle part.');
        $this->client->request('GET', $correspondance[0]);
        self::assertResponseIsSuccessful();
    }

    public function testUnEmailDejaUtiliseOuAbsentEstRefuse(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/associations/nouvelle');
        $this->client->submitForm('Créer l’association et inviter', ['association[nom]' => 'Association de Bakel', 'association[emailBureauCentral]' => 'central@moudery.fr']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#association_emailBureauCentral_erreur', 'existe déjà');

        $this->client->submitForm('Créer l’association et inviter', ['association[nom]' => 'Association de Bakel', 'association[emailBureauCentral]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#association_emailBureauCentral_erreur', 'recevra l’invitation');

        self::assertCount(1, $this->em()->getRepository(Association::class)->findAll());
        self::assertQueuedEmailCount(0);
    }

    public function testUnIdentifiantDejaUtiliseOuInvalideEstRefuse(): void
    {
        $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/associations/nouvelle');
        $this->client->submitForm('Créer l’association et inviter', ['association[nom]' => 'Moudery bis', 'association[slug]' => 'Moudery', 'association[emailBureauCentral]' => 'x@example.org']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#association_slug_erreur', 'déjà utilisé');

        $this->client->submitForm('Créer l’association et inviter', ['association[nom]' => 'Autre', 'association[slug]' => 'pas valide !', 'association[emailBureauCentral]' => 'x@example.org']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#association_slug_erreur', 'minuscules');

        $this->client->submitForm('Créer l’association et inviter', ['association[nom]' => '   ', 'association[slug]' => '', 'association[emailBureauCentral]' => 'x@example.org']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#association_nom_erreur', 'obligatoire');

        self::assertCount(1, $this->em()->getRepository(Association::class)->findAll());
    }

    public function testUnMotDePasseFaibleEstRefuseALActivation(): void
    {
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $lien = $this->creerInvitation($bakel, 'mamadou@example.org');

        $this->client->request('GET', $lien);
        $this->client->submitForm('Activer mon compte et ouvrir Association de Bakel', [
            'activation_compte[prenom]' => '',
            'activation_compte[nom]' => 'Diaby',
            'activation_compte[motDePasse]' => 'court',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#activation_compte_prenom_erreur', 'obligatoire');
        self::assertSelectorTextContains('#activation_compte_motDePasse_erreur', '12 caractères');
        self::assertNull($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => 'mamadou@example.org']));
    }

    /** Une invitation de bureau central valable, dont le lien est renvoyé. */
    private function creerInvitation(int $associationId, string $email, ?int $parId = null): string
    {
        return $this->creerInvitationAvecEcheance($associationId, $email, $parId, new \DateTimeImmutable());
    }

    private function creerInvitationExpiree(int $associationId, string $email, ?int $parId = null): string
    {
        return $this->creerInvitationAvecEcheance($associationId, $email, $parId, new \DateTimeImmutable('-8 days'));
    }

    private function creerInvitationAvecEcheance(int $associationId, string $email, ?int $parId, \DateTimeImmutable $envoi): string
    {
        $em = $this->em();
        $association = $em->find(Association::class, $associationId);
        \assert($association instanceof Association);
        $par = null !== $parId ? $em->find(Utilisateur::class, $parId) : null;
        $jeton = bin2hex(random_bytes(32));
        $invitation = new Invitation($association, Role::BureauCentral, $email, hash('sha256', $jeton), $envoi, $par);
        $em->persist($invitation);
        $em->flush();

        return '/invitation/'.$jeton;
    }
}
