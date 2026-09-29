<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\AssociationStatut;
use App\Entity\Evenement;
use App\Entity\TypeEvenement;
use App\Entity\UtilisateurStatut;
use App\Security\Role;
use Symfony\Component\DomCrawler\Crawler;

/** Emprunt d'identité : le super-admin voit l'application comme une autre personne, puis revient à son compte. */
final class EmpruntControllerTest extends CasDeTestWeb
{
    public function testLeMenuVoirCommeProposeLesPersonnesEtBasculeDepuisLaSaisie(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->creerUtilisateur($moudery, 'tresorier@moudery.fr', Role::BureauCentral);
        $this->creerUtilisateur($moudery, 'attente@moudery.fr', Role::BureauCentral, null, UtilisateurStatut::EnAttente);
        $this->creerUtilisateur($moudery, 'parti@moudery.fr', Role::BureauCentral, null, UtilisateurStatut::Desactive);
        $this->creerUtilisateur($bakel, 'central@bakel.fr', Role::BureauCentral);
        $em = $this->em();
        $em->find(Association::class, $bakel)?->changerStatut(AssociationStatut::Suspendue);
        $em->flush();
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration');
        self::assertSelectorExists('.console__nav a[href="/administration/voir-comme"]', 'Le menu propose « Voir comme… ».');
        self::assertSelectorExists('.console__marque a.logo[href="/"]', 'Le logo ramène au tableau de bord.');

        $crawler = $this->client->request('GET', '/administration/voir-comme');
        self::assertResponseIsSuccessful();
        $proposees = $crawler->filter('.combobox__option')->each(static fn (Crawler $option): ?string => $option->attr('data-valeur'));
        self::assertSame(['central@moudery.fr', 'tresorier@moudery.fr'], $proposees, 'Ni soi-même, ni les comptes en attente ou désactivés, ni ceux d’une association suspendue.');
        self::assertSelectorExists('a[href="/?_voir_comme=tresorier@moudery.fr"]', 'La liste porte un bouton « Voir » par personne.');

        // Une saisie ambiguë (tout le monde s'appelle Awa Cissé) ne bascule pas : on reste sur la page, avec une explication.
        $this->client->request('GET', '/administration/voir-comme/aller?personne=Awa');
        self::assertResponseRedirects('/administration/voir-comme');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'Awa');
        self::assertCount(0, $this->em()->getRepository(Evenement::class)->findBy(['type' => TypeEvenement::CompteVuComme]));

        // Une saisie qui ne désigne qu'une personne bascule aussitôt.
        $this->client->request('GET', '/administration/voir-comme/aller?personne=tresorier');
        self::assertResponseRedirects('/?_voir_comme=tresorier@moudery.fr');
        $this->client->followRedirect();
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseRedirects('/associations/moudery', 302, 'Vu comme un bureau central, l’accueil mène au tableau de bord de son association.');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.emprunt', 'tresorier@moudery.fr');
        self::assertSelectorTextContains('.emprunt', 'Revenir à mon compte (Awa Cissé)');

        // Depuis le bandeau, « Voir une autre personne » quitte l'emprunt et ramène au menu Voir comme.
        self::assertSelectorExists('.emprunt a[href="/administration/voir-comme?_voir_comme=_exit"]');
        $this->client->request('GET', '/administration/voir-comme?_voir_comme=_exit');
        self::assertResponseRedirects('/administration/voir-comme');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.emprunt');
        self::assertSelectorExists('.combobox__option[data-valeur="central@moudery.fr"]');
    }

    public function testLeSuperAdminVoitLApplicationCommeUnBureauCentralPuisRevient(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $central = $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->creerUtilisateur($moudery, 'tresorier@moudery.fr', Role::BureauCentral);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/comptes/'.$central);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/?_voir_comme=central@moudery.fr"]', 'La fiche propose de voir l’application comme cette personne.');

        $this->client->request('GET', '/?_voir_comme=central@moudery.fr');
        self::assertResponseRedirects('/', 302);
        $this->client->followRedirect();
        self::assertResponseRedirects('/associations/moudery', 302, 'Vu comme le bureau central, l’accueil mène au tableau de bord de son association.');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.emprunt', 'central@moudery.fr');
        self::assertSelectorExists('.emprunt a[href="/?_voir_comme=_exit"]');
        self::assertSelectorTextContains('.console__titre', 'Tableau de bord');
        self::assertSelectorTextContains('.console__nom', 'Awa Cissé');
        self::assertSelectorExists('.console__marque a.logo[href="/"]', 'Le logo ramène à l’accueil de la personne vue.');

        $evenement = $this->em()->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::CompteVuComme]);
        self::assertNotNull($evenement, 'L’emprunt d’identité est une action sensible : il est consigné.');
        self::assertSame('central@moudery.fr', $evenement->getCible());
        self::assertSame('admin@example.org', $evenement->getActeur()?->getEmail());
        self::assertSame('moudery', $evenement->getAssociation()?->getSlug());

        // Emprunté, le super-admin n'a que les droits de la personne : l'administration lui est fermée.
        $this->client->request('GET', '/administration');
        self::assertResponseStatusCodeSame(403);

        // Passer directement à une autre personne : Symfony quitte l'emprunt puis rebascule avec les droits d'origine.
        $this->client->request('GET', '/?_voir_comme=tresorier@moudery.fr');
        self::assertResponseRedirects('/', 302);
        $this->client->followRedirect();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.emprunt', 'tresorier@moudery.fr');
        self::assertCount(2, $this->em()->getRepository(Evenement::class)->findBy(['type' => TypeEvenement::CompteVuComme]), 'Chaque personne vue est consignée.');

        // Retour à son compte : l'accueil le ramène à l'administration.
        $this->client->request('GET', '/?_voir_comme=_exit');
        self::assertResponseRedirects('/', 302);
        $this->client->followRedirect();
        self::assertResponseRedirects('/administration', 302);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.emprunt');
        self::assertCount(2, $this->em()->getRepository(Evenement::class)->findBy(['type' => TypeEvenement::CompteVuComme]), 'Le retour n’est pas consigné.');
    }

    public function testLeBureauCentralNEmprunteAucuneIdentiteEtNeVoitPasLeMenu(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);
        $this->creerUtilisateur($moudery, 'tresorier@moudery.fr', Role::BureauCentral);
        $this->connecter($this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral));

        $this->client->request('GET', '/administration/voir-comme');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/administration/voir-comme/aller?personne=tresorier@moudery.fr');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/?_voir_comme=tresorier@moudery.fr');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/?_voir_comme=admin@example.org');
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em()->getRepository(Evenement::class)->findBy(['type' => TypeEvenement::CompteVuComme]));
    }

    public function testLaFicheDeSonPropreCompteNeProposePasDeSeVoirCommeSoiMeme(): void
    {
        $admin = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);
        $this->connecter($admin);

        $this->client->request('GET', '/administration/comptes/'.$admin);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href^="/?_voir_comme="]');

        $this->client->request('GET', '/administration/voir-comme');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.combobox__option');
        self::assertSelectorTextContains('.tableau__vide', 'Aucun autre compte actif');
    }
}
