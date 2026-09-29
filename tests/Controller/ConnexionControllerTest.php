<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Security\Role;
use Symfony\Component\Mime\Email;

/** Connexion, déconnexion et mot de passe oublié (F-03). */
final class ConnexionControllerTest extends CasDeTestWeb
{
    private int $moudery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
    }

    public function testLaPageDeConnexionSAffiche(): void
    {
        $this->client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Se connecter');
        self::assertSelectorExists('input[type="email"][name="email"]');
        self::assertSelectorExists('input[type="password"][name="mot_de_passe"]');
        self::assertSelectorExists('input[type="checkbox"][name="rester_connecte"]');
        self::assertSelectorExists('a[href="/mot-de-passe-oublie"]');
    }

    public function testUnePageProtegeeRenvoieVersLaConnexion(): void
    {
        // Depuis le 29 septembre 2026, la racine présente Caisses aux visiteurs ; les autres pages restent protégées.
        $this->client->request('GET', '/parametres');

        self::assertResponseRedirects('http://localhost/connexion', 302);
    }

    public function testLeBureauCentralSeConnecteEtSeDeconnecte(): void
    {
        $this->creerUtilisateur($this->moudery, 'central@moudery.fr', Role::BureauCentral);

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'Central@Moudery.fr', 'mot_de_passe' => self::MOT_DE_PASSE]);
        self::assertResponseRedirects('http://localhost/', 302);

        $this->client->followRedirect();
        self::assertResponseRedirects('/associations/moudery', 302, 'Le bureau central est conduit au tableau de bord de son association.');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertSelectorTextContains('.console__nom', 'Awa Cissé');

        $utilisateur = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => 'central@moudery.fr']);
        self::assertNotNull($utilisateur?->getDerniereConnexionLe(), 'La date de dernière connexion est enregistrée.');

        $this->client->submit($this->client->getCrawler()->filter('form.deconnexion')->form());
        self::assertResponseRedirects('http://localhost/connexion', 302);

        $this->client->request('GET', '/parametres');
        self::assertResponseRedirects('http://localhost/connexion', 302, 'Une fois déconnecté, les pages protégées ne sont plus accessibles.');
    }

    public function testUnMembreArriveSurLAccueil(): void
    {
        $lyon = $this->creerVille($this->moudery, 'Lyon');
        $this->creerUtilisateur($this->moudery, 'awa@example.org', Role::Membre, $lyon);

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'awa@example.org', 'mot_de_passe' => self::MOT_DE_PASSE]);
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bienvenue, Awa');
    }

    public function testLeSuperAdminArriveSurLAdministrationDeLaPlateforme(): void
    {
        $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'admin@example.org', 'mot_de_passe' => self::MOT_DE_PASSE]);
        $this->client->followRedirect();
        self::assertResponseRedirects('/administration', 302);

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');

        $this->client->request('GET', '/administration/villes');
        self::assertResponseIsSuccessful('Décision du 27 septembre 2026 : le super-admin accède aussi aux villes des associations.');
        $this->client->request('GET', '/associations/moudery/villes/nouvelle');
        self::assertResponseStatusCodeSame(403, 'Mais il n’en crée pas : c’est le bureau central qui crée ses villes.');
    }

    public function testUnMauvaisMotDePasseEstRefuse(): void
    {
        $this->creerUtilisateur($this->moudery, 'central@moudery.fr', Role::BureauCentral);

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'central@moudery.fr', 'mot_de_passe' => 'faux-mot-de-passe']);
        self::assertResponseRedirects('http://localhost/connexion', 302);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'Identifiants invalides');
        self::assertSelectorExists('input[name="email"][value="central@moudery.fr"]', 'L’adresse saisie est conservée.');

        $this->client->request('GET', '/parametres');
        self::assertResponseRedirects('http://localhost/connexion', 302);
    }

    public function testUnCompteEnAttenteNePeutPasSeConnecter(): void
    {
        $lyon = $this->creerVille($this->moudery, 'Lyon');
        $this->creerUtilisateur($this->moudery, 'awa@example.org', Role::Membre, $lyon, UtilisateurStatut::EnAttente);

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'awa@example.org', 'mot_de_passe' => self::MOT_DE_PASSE]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alerte--erreur', 'en attente de validation');
    }

    public function testLesTentativesDeConnexionSontLimitees(): void
    {
        $this->creerUtilisateur($this->moudery, 'central@moudery.fr', Role::BureauCentral);
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('GET', '/connexion');
            $this->client->submitForm('Se connecter', ['email' => 'central@moudery.fr', 'mot_de_passe' => 'faux-mot-de-passe']);
        }

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'central@moudery.fr', 'mot_de_passe' => self::MOT_DE_PASSE]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alerte--erreur', 'tentatives de connexion', 'Après cinq échecs, même le bon mot de passe est bloqué un moment.');
    }

    public function testLeMotDePasseOublieEnvoieUnLienQuiReinitialise(): void
    {
        $this->creerUtilisateur($this->moudery, 'central@moudery.fr', Role::BureauCentral);

        $this->client->request('GET', '/mot-de-passe-oublie');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Envoyer le lien', ['mot_de_passe_oublie[email]' => 'Central@moudery.fr']);
        self::assertResponseRedirects('/mot-de-passe-oublie/envoye', 303);
        self::assertQueuedEmailCount(1);

        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'to', 'central@moudery.fr');
        self::assertEmailAddressContains($email, 'from', 'ne-pas-repondre@caisses-moudery.fr');
        self::assertSame(1, preg_match('#https?://[^\s]+/mot-de-passe/reinitialiser/([a-f0-9]{64})#', (string) $email->getTextBody(), $correspondance));
        $lien = '/mot-de-passe/reinitialiser/'.$correspondance[1];

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Vérifiez votre boîte mail');

        $this->client->request('GET', $lien);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Choisir un nouveau mot de passe');
        $this->client->submitForm('Enregistrer le mot de passe', [
            'nouveau_mot_de_passe[motDePasse]' => 'Nouveau-Registre-2027!',
            'nouveau_mot_de_passe[confirmation]' => 'Nouveau-Registre-2027!',
        ]);
        self::assertResponseRedirects('/connexion', 303);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'enregistré');

        $this->client->submitForm('Se connecter', ['email' => 'central@moudery.fr', 'mot_de_passe' => 'Nouveau-Registre-2027!']);
        self::assertResponseRedirects('http://localhost/', 302, 'Le nouveau mot de passe permet de se connecter.');

        $this->client->request('GET', $lien);
        self::assertResponseRedirects('/mot-de-passe-oublie', 303, 'Le lien ne sert qu’une fois.');
    }

    public function testUneAdresseInconnueNeRevelePasLesComptes(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Envoyer le lien', ['mot_de_passe_oublie[email]' => 'inconnue@example.org']);

        self::assertResponseRedirects('/mot-de-passe-oublie/envoye', 303, 'Même réponse qu’une adresse connue.');
        self::assertQueuedEmailCount(0);
    }

    public function testUnLienInvalideRenvoieVersLaDemande(): void
    {
        $this->client->request('GET', '/mot-de-passe/reinitialiser/'.str_repeat('0', 64));
        self::assertResponseRedirects('/mot-de-passe-oublie', 303);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'plus valable');
    }

    public function testUnMotDePasseTropCourtOuDifferentEstRefuse(): void
    {
        $this->creerUtilisateur($this->moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Envoyer le lien', ['mot_de_passe_oublie[email]' => 'central@moudery.fr']);
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        preg_match('#/mot-de-passe/reinitialiser/[a-f0-9]{64}#', (string) $email->getTextBody(), $correspondance);

        $this->client->request('GET', $correspondance[0]);
        $this->client->submitForm('Enregistrer le mot de passe', [
            'nouveau_mot_de_passe[motDePasse]' => 'court',
            'nouveau_mot_de_passe[confirmation]' => 'autre',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#nouveau_mot_de_passe_motDePasse_erreur', '12 caractères');
        self::assertSelectorTextContains('#nouveau_mot_de_passe_confirmation_erreur', 'pas identiques');
    }

    public function testUneAdresseInvalideEstRefusee(): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Envoyer le lien', ['mot_de_passe_oublie[email]' => 'pas-un-email']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#mot_de_passe_oublie_email_erreur', 'pas valide');
    }
}
