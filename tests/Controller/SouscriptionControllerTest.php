<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Abonnement\Catalogue;
use App\Compte\Invitations;
use App\Entity\AbonnementStatut;
use App\Entity\Association;
use App\Entity\Periodicite;
use App\Entity\Utilisateur;
use App\Security\Role;
use Symfony\Component\Mime\Email;

/** Souscription d'une association par son bureau central, depuis l'e-mail envoyé à la création (F-02 + abonnement). */
final class SouscriptionControllerTest extends CasDeTestWeb
{
    public function testLeBureauCentralInviteSouscritLOffreEtActiveSonCompte(): void
    {
        $admin = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);
        $this->connecter($admin);

        $this->client->request('GET', '/administration/associations/nouvelle');
        $this->client->submitForm('Créer l’association et inviter', [
            'association[nom]' => 'Association de Bakel',
            'association[emailBureauCentral]' => 'mamadou@example.org',
        ]);
        self::assertQueuedEmailCount(1);

        // L'e-mail présente les offres et mène à la page de souscription.
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'to', 'mamadou@example.org');
        self::assertEmailSubjectContains($email, 'souscrivez');
        self::assertEmailTextBodyContains($email, 'Souscrire et créer mon compte');
        self::assertEmailTextBodyContains($email, '25,00');
        self::assertEmailTextBodyContains($email, '250,00');
        self::assertEmailTextBodyContains($email, '14 jours');
        self::assertEmailHtmlBodyContains($email, 'Souscrire et créer mon compte');
        self::assertSame(1, preg_match('#/invitation/([a-f0-9]{64})#', (string) $email->getTextBody(), $correspondance));
        $lien = '/invitation/'.$correspondance[1];

        // La personne invitée arrive sans être connectée.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Souscrire et activer mon compte');
        self::assertSelectorCount(2, '.offre');
        self::assertSelectorTextContains('.offres', '25,00');
        self::assertSelectorTextContains('.offres', '250,00');
        self::assertSelectorTextContains('.offres', 'd’économie sur l’année');
        self::assertSelectorExists('input[type="radio"][name="souscription[offre]"][value="standard-annuel"]');

        // Sans offre ni conditions, rien ne passe.
        $this->client->submitForm('Souscrire et ouvrir Association de Bakel', [
            'souscription[prenom]' => 'Mamadou',
            'souscription[nom]' => 'Diaby',
            'souscription[motDePasse]' => self::MOT_DE_PASSE,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#offres-erreur', 'Choisissez l’abonnement');
        self::assertSelectorTextContains('#souscription_conditions_erreur', 'Acceptez les conditions');
        self::assertNull($this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => 'mamadou@example.org']));

        $this->client->submitForm('Souscrire et ouvrir Association de Bakel', [
            'souscription[offre]' => 'standard-annuel',
            'souscription[prenom]' => 'Mamadou',
            'souscription[nom]' => 'Diaby',
            'souscription[motDePasse]' => self::MOT_DE_PASSE,
            'souscription[conditions]' => '1',
        ]);
        self::assertResponseRedirects('/', 303);

        $association = $this->em()->getRepository(Association::class)->findOneBy(['slug' => 'association-de-bakel']);
        $abonnement = $association?->getAbonnement();
        self::assertNotNull($abonnement);
        self::assertSame(AbonnementStatut::Actif, $abonnement->getStatut());
        self::assertSame(Catalogue::FORMULE, $abonnement->getFormule());
        self::assertSame(Periodicite::Annuelle, $abonnement->getPeriodicite());
        self::assertSame(Catalogue::ANNUEL, $abonnement->getMontant());
        self::assertSame(date('Y-m-d'), $abonnement->getDebutLe()->format('Y-m-d'));
        self::assertSame((new \DateTimeImmutable('+14 days'))->format('Y-m-d'), $abonnement->getProchaineEcheanceLe()?->format('Y-m-d'), 'Premier paiement sous 14 jours.');

        $compte = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => 'mamadou@example.org']);
        self::assertNotNull($compte, 'Le compte est créé en même temps.');
        self::assertTrue($compte->aLaPermission(\App\Security\Permission::VILLE_CREER, $association), 'Bureau central de son association.');

        // Connecté d'office, le bureau central arrive sur son espace.
        $this->client->followRedirect();
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alerte--succes', 'abonnement Standard');

        // Le lien ne sert qu'une fois.
        $this->client->request('GET', $lien);
        self::assertResponseStatusCodeSame(410);

        // Côté super-admin : le premier paiement est attendu.
        $this->connecter($admin);
        $this->client->request('GET', '/administration');
        self::assertSelectorTextContains('section[aria-labelledby="a-traiter"]', 'Association de Bakel : premier paiement attendu le');
        self::assertSelectorTextContains('section[aria-labelledby="a-traiter"]', 'Standard annuelle, souscrit le');
        self::assertSelectorTextContains('.kpis', '20,83', '250 € par an font 20,83 € par mois.');
        $this->client->request('GET', '/administration/associations/'.$association->getId());
        self::assertSelectorTextContains('section#abonnement h2 .pastille', 'À jour');
        self::assertSelectorTextContains('section#abonnement', 'Souscription en ligne le');
    }

    public function testUnResponsableDeVilleActiveSimplementSonCompte(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $central = $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);

        $em = $this->em();
        $association = $em->find(Association::class, $moudery);
        \assert($association instanceof Association);
        $association->ouvrirAbonnement();
        $em->flush();

        $invitations = static::getContainer()->get(Invitations::class);
        \assert($invitations instanceof Invitations);
        $invitations->inviter($association, Role::Tresorier, 'seydou@example.org', $em->find(Utilisateur::class, $central), $em->find(\App\Entity\Ville::class, $lyon));
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailSubjectContains($email, 'invitation');
        self::assertEmailTextBodyNotContains($email, 'Souscrire');
        self::assertSame(1, preg_match('#/invitation/([a-f0-9]{64})#', (string) $email->getTextBody(), $correspondance));

        $this->client->request('GET', '/invitation/'.$correspondance[1]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Activer mon compte');
        self::assertSelectorNotExists('.offre');
        self::assertSelectorExists('input[name="activation_compte[prenom]"]');
    }

    public function testLeBureauCentralDUneAssociationDejaAbonneeActiveSimplementSonCompte(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $admin = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);

        $em = $this->em();
        $association = $em->find(Association::class, $moudery);
        \assert($association instanceof Association);
        $association->ouvrirAbonnement()->definir('Offert', AbonnementStatut::Offert, 0, Periodicite::Mensuelle, new \DateTimeImmutable('2026-09-01'), null, 'Premier client.');
        $em->flush();

        $invitations = static::getContainer()->get(Invitations::class);
        \assert($invitations instanceof Invitations);
        $invitations->inviter($association, Role::BureauCentral, 'rama@example.org', $em->find(Utilisateur::class, $admin));
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailTextBodyNotContains($email, 'Souscrire', 'Offert par le super-admin : rien à souscrire.');
        self::assertSame(1, preg_match('#/invitation/([a-f0-9]{64})#', (string) $email->getTextBody(), $correspondance));

        $this->client->request('GET', '/invitation/'.$correspondance[1]);
        self::assertSelectorTextContains('h1', 'Activer mon compte');
        self::assertSelectorNotExists('.offre');
    }
}
