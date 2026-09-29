<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Evenement;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Security\Role;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Menu du compte et paramètres (F-04) : la personne connectée modifie son identité, son adresse et son mot de passe. */
final class ParametresControllerTest extends CasDeTestWeb
{
    private const string NOUVEAU_MOT_DE_PASSE = 'Caisses-Moudery-2027!';

    public function testLeMenuDuCompteProposeLesParametresEtLaDeconnexion(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__compte .console__nom', 'Awa Cissé');
        self::assertSelectorExists('.console__compte .menu__liste a[href="/parametres"]');
        self::assertSelectorExists('.console__compte .menu__liste form.deconnexion button', 'La déconnexion reste un formulaire POST.');

        $this->connecter($this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.console__compte .menu__liste a[href="/parametres"]');
        self::assertSelectorExists('.console__compte .menu__liste form.deconnexion button');
    }

    public function testLaPersonneModifieSonIdentiteEtSonAdresse(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->creerUtilisateur($moudery, 'tresorier@moudery.fr', Role::BureauCentral);
        $id = $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->connecter($id);

        $crawler = $this->client->request('GET', '/parametres');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Paramètres');
        self::assertSelectorTextContains('.console__perimetre-nom', 'Toute l’association', 'Le bureau central reste dans l’espace de son association.');
        self::assertSame('central@moudery.fr', $crawler->filter('#profil_email')->attr('value'));

        // Garder sa propre adresse n'est pas un doublon.
        $this->client->submitForm('Enregistrer', ['profil[prenom]' => 'Mariam', 'profil[nom]' => 'Sy', 'profil[email]' => 'central@moudery.fr']);
        self::assertResponseRedirects('/parametres', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'enregistrées');
        self::assertSelectorTextContains('.console__nom', 'Mariam Sy');

        // L'adresse de quelqu'un d'autre est refusée.
        $this->client->submitForm('Enregistrer', ['profil[prenom]' => 'Mariam', 'profil[nom]' => 'Sy', 'profil[email]' => 'tresorier@moudery.fr']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.champ--erreur .champ__erreur', 'Un compte existe déjà');

        // Une nouvelle adresse devient l'identifiant de connexion, et la modification est consignée par la personne elle-même.
        $this->client->submitForm('Enregistrer', ['profil[prenom]' => 'Mariam', 'profil[nom]' => 'Sy', 'profil[email]' => 'Mariam.Sy@moudery.fr']);
        self::assertResponseRedirects('/parametres', 303);
        self::assertSame('mariam.sy@moudery.fr', $this->em()->find(Utilisateur::class, $id)?->getEmail());
        $evenements = $this->em()->getRepository(Evenement::class)->findBy(['type' => TypeEvenement::CompteModifie]);
        self::assertCount(2, $evenements);
        self::assertSame('mariam.sy@moudery.fr', $evenements[1]->getActeur()?->getEmail());
        self::assertSame('moudery', $evenements[1]->getAssociation()?->getSlug());
    }

    public function testLaPersonneChangeSonMotDePasseEtResteConnectee(): void
    {
        $id = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);
        $this->connecter($id);

        $this->client->request('GET', '/parametres');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.console__nav a[href="/administration"]', 'Le super-admin reste dans l’administration.');

        $champs = static fn (string $actuel, string $confirmation): array => [
            'changement_mot_de_passe[actuel]' => $actuel,
            'changement_mot_de_passe[motDePasse]' => self::NOUVEAU_MOT_DE_PASSE,
            'changement_mot_de_passe[confirmation]' => $confirmation,
        ];

        $this->client->submitForm('Changer le mot de passe', $champs('pas-le-bon', self::NOUVEAU_MOT_DE_PASSE));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.champ--erreur .champ__erreur', 'incorrect');

        $this->client->submitForm('Changer le mot de passe', $champs(self::MOT_DE_PASSE, 'autre-chose'));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.champ--erreur .champ__erreur', 'identiques');

        $this->client->submitForm('Changer le mot de passe', $champs(self::MOT_DE_PASSE, self::NOUVEAU_MOT_DE_PASSE));
        self::assertResponseRedirects('/parametres', 303);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful('La personne reste connectée après le changement.');
        self::assertSelectorTextContains('.alerte--succes', 'changé');

        $compte = $this->em()->find(Utilisateur::class, $id);
        self::assertNotNull($compte);
        $hacheur = static::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hacheur instanceof UserPasswordHasherInterface);
        self::assertTrue($hacheur->isPasswordValid($compte, self::NOUVEAU_MOT_DE_PASSE));
        self::assertFalse($hacheur->isPasswordValid($compte, self::MOT_DE_PASSE));

        $evenement = $this->em()->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::CompteMotDePasseChange]);
        self::assertSame('admin@example.org', $evenement?->getActeur()?->getEmail(), 'Le changement de mot de passe est consigné.');
    }

    public function testUnCompteSansEspaceALesParametresDansUneCoquilleSimple(): void
    {
        $this->connecter($this->creerUtilisateur(null, 'seul@example.org'));

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.accueil__compte a[href="/parametres"]');

        $this->client->request('GET', '/parametres');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.accueil__entete');
        self::assertSelectorTextContains('h1', 'Paramètres');
        self::assertSelectorExists('#profil_email');
    }

    public function testSousEmpruntDIdentiteLesParametresSontFermes(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/?_voir_comme=central@moudery.fr');
        $this->client->followRedirect();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.emprunt');
        self::assertSelectorNotExists('a[href="/parametres"]', 'Sous emprunt, le menu ne propose pas les paramètres.');
        self::assertSelectorExists('.console__compte .menu__liste form.deconnexion button');

        $this->client->request('GET', '/parametres');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/parametres/mot-de-passe', ['changement_mot_de_passe' => ['actuel' => self::MOT_DE_PASSE, 'motDePasse' => self::NOUVEAU_MOT_DE_PASSE, 'confirmation' => self::NOUVEAU_MOT_DE_PASSE]]);
        self::assertResponseStatusCodeSame(403);
    }
}
