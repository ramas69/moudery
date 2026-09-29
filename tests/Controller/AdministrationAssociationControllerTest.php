<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Security\Role;

/** Fiche, modification et suppression d'une association par le super-admin. */
final class AdministrationAssociationControllerTest extends CasDeTestWeb
{
    public function testLeBureauCentralNAccedePasALaFicheDUneAssociation(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral));

        $this->client->request('GET', '/administration/associations/'.$moudery);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/administration/associations/'.$moudery.'/modifier');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeSuperAdminConsulteEtModifieUneAssociation(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/associations/'.$moudery);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Association de Moudery');
        self::assertSelectorTextContains('.tableau', 'Lyon');
        self::assertSelectorTextContains('section[aria-labelledby="comptes-titre"] .tableau', 'central@moudery.fr');
        self::assertSelectorExists(\sprintf('a[href="/administration/associations/%d/modifier"]', $moudery));
        self::assertSelectorExists(\sprintf('a[href="/associations/moudery/villes/%d/assistant"]', $lyon), 'Le super-admin ouvre l’assistant de n’importe quelle ville.');
        self::assertSelectorExists('form[action$="/supprimer"] button[disabled]', 'Une association habitée ne se supprime pas.');

        $this->client->request('GET', '/administration/associations/'.$moudery.'/modifier');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Enregistrer les modifications', [
            'association_modification[nom]' => '  Association de Moudery et environs  ',
            'association_modification[slug]' => 'moudery-env',
            'association_modification[premierExercice]' => '2024',
        ]);
        self::assertResponseRedirects('/administration/associations/'.$moudery, 303);

        $association = $this->em()->find(Association::class, $moudery);
        self::assertSame('Association de Moudery et environs', $association?->getNom());
        self::assertSame('moudery-env', $association?->getSlug());
        self::assertSame(2024, $association?->getPremierExercice(), 'Le premier exercice suivi se règle sur la fiche.');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'modifiée');
        self::assertSelectorTextContains('.console__sous-titre', '/associations/moudery-env');
        self::assertSelectorTextContains('dl', 'Premier exercice suivi');
    }

    public function testUnIdentifiantPrisParUneAutreAssociationEstRefuse(): void
    {
        $this->creerAssociation('Association de Moudery', 'moudery');
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/associations/'.$bakel.'/modifier');
        $this->client->submitForm('Enregistrer les modifications', ['association_modification[nom]' => 'Association de Bakel', 'association_modification[slug]' => 'moudery']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#association_modification_slug_erreur', 'déjà utilisé');

        // Garder son propre identifiant n'est pas un conflit.
        $this->client->submitForm('Enregistrer les modifications', ['association_modification[nom]' => 'Association de Bakel', 'association_modification[slug]' => 'bakel']);
        self::assertResponseRedirects('/administration/associations/'.$bakel, 303);
        self::assertSame('bakel', $this->em()->find(Association::class, $bakel)?->getSlug());
    }

    public function testUneAssociationVideSeSupprimeMaisPasUneAssociationHabitee(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->creerVille($moudery, 'Lyon');
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        // Habitée : le bouton est désactivé, et le serveur refuse quand même une demande directe.
        $crawler = $this->client->request('GET', '/administration/associations/'.$moudery);
        $jeton = $crawler->filter('form[action$="/supprimer"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/administration/associations/'.$moudery.'/supprimer', ['_token' => $jeton]);
        self::assertResponseRedirects('/administration/associations/'.$moudery, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'Impossible de supprimer');
        self::assertNotNull($this->em()->find(Association::class, $moudery));

        // Vide : la suppression aboutit.
        $this->client->request('GET', '/administration/associations/'.$bakel);
        self::assertSelectorExists('form[action$="/supprimer"] button:not([disabled])');
        $this->client->submitForm('Supprimer l’association');
        self::assertResponseRedirects('/administration', 303);
        self::assertNull($this->em()->find(Association::class, $bakel));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'supprimée');
    }
}
