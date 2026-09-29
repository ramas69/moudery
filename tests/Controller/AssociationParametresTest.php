<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\Evenement;
use App\Entity\TypeEvenement;
use App\Security\Role;

/** Onglet « Association » des Paramètres : le bureau central règle contact, exercice, reversement par défaut et relances. */
final class AssociationParametresTest extends CasDeTestWeb
{
    public function testLeBureauCentralRegleLesParametresDeSonAssociation(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur($moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/parametres');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Paramètres');
        self::assertSelectorTextContains('.onglets-parametres a[aria-current="page"]', 'Association');
        self::assertSelectorExists('.onglets-parametres a[href="/parametres"]');

        $formulaire = $crawler->filter('form[name="parametres_association"]')->form();
        $formulaire['parametres_association[emailContact]'] = 'contact@moudery.fr';
        $formulaire['parametres_association[debutExerciceMois]'] = '10';
        $formulaire['parametres_association[premierExercice]'] = '2024';
        $formulaire['parametres_association[tauxReversementDefaut]'] = '30';
        $formulaire['parametres_association[calendrierRelances]'] = '-3, 0, 10, 30';
        $this->client->submit($formulaire);

        self::assertResponseRedirects('/associations/moudery/parametres', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Les paramètres de l’association sont enregistrés.');

        $this->em()->clear();
        $association = $this->em()->find(Association::class, $moudery);
        \assert($association instanceof Association);
        self::assertSame('contact@moudery.fr', $association->getEmailContact());
        self::assertSame(10, $association->getDebutExerciceMois());
        self::assertSame(2024, $association->getPremierExercice());
        self::assertSame(30, $association->getTauxReversementDefaut());
        self::assertSame([-3, 0, 10, 30], $association->getCalendrierRelances());

        $evenement = $this->em()->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::AssociationParametres]);
        self::assertInstanceOf(Evenement::class, $evenement);
        self::assertSame(0, $evenement->getDetails()['avant']['taux_reversement_defaut']);
        self::assertSame(30, $evenement->getDetails()['apres']['taux_reversement_defaut']);

        // Le tableau de bord suit le nouvel exercice : il commence en octobre.
        $this->client->request('GET', '/associations/moudery?ville=association');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.exercice', '–', 'Un exercice d’octobre à septembre s’écrit « 2026 – 2027 ».');
    }

    public function testUnTauxHorsBornesEstRefuse(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur($moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/parametres');
        $formulaire = $crawler->filter('form[name="parametres_association"]')->form();
        $formulaire['parametres_association[tauxReversementDefaut]'] = '120';
        $formulaire['parametres_association[calendrierRelances]'] = 'lundi';
        $this->client->submit($formulaire);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.champ--erreur .champ__erreur', 'Le taux de reversement va de 0 à 100 %.');
        self::assertSelectorTextContains('form[name="parametres_association"]', 'Le calendrier des relances se compose de nombres de jours');
    }

    public function testSeulLeBureauCentralDeLAssociationYAccede(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon', ['tresorier' => 'awa@example.org']);
        $this->connecter($this->creerUtilisateur($moudery, 'awa@example.org', Role::Tresorier, $lyon));
        $this->client->request('GET', '/associations/moudery/parametres');
        self::assertResponseStatusCodeSame(403);

        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'bakel@example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery/parametres');
        self::assertResponseStatusCodeSame(403);
    }
}
