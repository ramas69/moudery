<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\AssociationStatut;
use App\Security\Role;

/** Identité, paramètres et statut d'une association, et la liste qui les montre : recherche, filtres, tri, export. */
final class AdministrationAssociationIdentiteTest extends CasDeTestWeb
{
    public function testLeSuperAdminRenseigneLIdentiteEtLesParametres(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/associations/'.$moudery.'/modifier');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Enregistrer les modifications', [
            'association_modification[nom]' => 'Association de Moudery',
            'association_modification[slug]' => 'moudery',
            'association_modification[village]' => ' Moudery ',
            'association_modification[emailContact]' => 'Contact@Moudery.fr',
            'association_modification[telephoneContact]' => '06 12 34 56 78',
            'association_modification[adresseSiege]' => "12 rue du Village\n75000 Paris",
            'association_modification[numeroRna]' => 'w123 456 789',
            'association_modification[siren]' => '123 456 789',
            'association_modification[debutExerciceMois]' => '10',
            'association_modification[tauxReversementDefaut]' => '40',
            'association_modification[calendrierRelances]' => '15, -7, 0',
        ]);
        self::assertResponseRedirects('/administration/associations/'.$moudery, 303);

        $association = $this->em()->find(Association::class, $moudery);
        self::assertNotNull($association);
        self::assertSame('Moudery', $association->getVillage());
        self::assertSame('contact@moudery.fr', $association->getEmailContact());
        self::assertSame('06 12 34 56 78', $association->getTelephoneContact());
        self::assertSame("12 rue du Village\n75000 Paris", $association->getAdresseSiege());
        self::assertSame('W123456789', $association->getNumeroRna());
        self::assertSame('123456789', $association->getSiren());
        self::assertSame(10, $association->getDebutExerciceMois());
        self::assertSame(40, $association->getTauxReversementDefaut());
        self::assertSame([-7, 0, 15], $association->getCalendrierRelances());

        $this->client->followRedirect();
        self::assertSelectorTextContains('section[aria-labelledby="identite-titre"]', 'contact@moudery.fr');
        self::assertSelectorTextContains('section[aria-labelledby="identite-titre"]', 'W123456789');
        self::assertSelectorTextContains('section[aria-labelledby="identite-titre"]', 'Débute en octobre');
        self::assertSelectorTextContains('section[aria-labelledby="identite-titre"]', '40 %');
        self::assertSelectorTextContains('section[aria-labelledby="identite-titre"]', '-7, 0, 15');
        self::assertSelectorTextContains('.console__sous-titre', 'Moudery');

        // Le village apparaît désormais sous le logo de l'assistant (d'une ville existante : le super-admin n'en crée pas).
        $lyon = $this->creerVille($moudery, 'Lyon');
        $this->client->request('GET', '/associations/moudery/villes/'.$lyon.'/assistant/identite');
        self::assertSelectorTextContains('.logo__village', 'Moudery');
    }

    public function testUneIdentiteInvalideEstRefusee(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/associations/'.$moudery.'/modifier');
        $this->client->submitForm('Enregistrer les modifications', [
            'association_modification[nom]' => 'Association de Moudery',
            'association_modification[emailContact]' => 'pas-un-email',
            'association_modification[telephoneContact]' => 'abc',
            'association_modification[numeroRna]' => '123',
            'association_modification[siren]' => '12',
            'association_modification[tauxReversementDefaut]' => '120',
            'association_modification[calendrierRelances]' => 'lundi',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#association_modification_emailContact_erreur', 'pas valide');
        self::assertSelectorTextContains('#association_modification_telephoneContact_erreur', 'téléphone');
        self::assertSelectorTextContains('#association_modification_numeroRna_erreur', 'W suivie de neuf chiffres');
        self::assertSelectorTextContains('#association_modification_siren_erreur', 'neuf chiffres');
        self::assertSelectorTextContains('#association_modification_tauxReversementDefaut_erreur', '0 à 100');
        self::assertSelectorTextContains('#association_modification_calendrierRelances_erreur', 'virgules');
        self::assertNull($this->em()->find(Association::class, $moudery)?->getEmailContact());
    }

    public function testSuspendreUneAssociationFermeSesComptesPuisReactiverLesRouvre(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $admin = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);
        $this->connecter($admin);

        $crawler = $this->client->request('GET', '/administration/associations/'.$moudery);
        $jeton = $crawler->filter('section[aria-labelledby="statut-titre"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/administration/associations/'.$moudery.'/statut/suspendre', ['_token' => $jeton]);
        self::assertResponseRedirects('/administration/associations/'.$moudery, 303);
        self::assertSame(AssociationStatut::Suspendue, $this->em()->find(Association::class, $moudery)?->getStatut());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'suspendue');
        self::assertSelectorTextContains('.alerte--attention', 'ses comptes ne peuvent plus se connecter');
        self::assertSelectorTextContains('h1 .pastille', 'Suspendue');

        // Le super-admin garde la main sur ses villes existantes ; le bureau central, lui, ne se connecte plus.
        $lyon = $this->creerVille($moudery, 'Lyon');
        $this->client->request('GET', '/associations/moudery/villes/'.$lyon.'/assistant/identite');
        self::assertResponseIsSuccessful();

        $this->client->submit($this->client->getCrawler()->filter('form.deconnexion')->form());
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'central@moudery.fr', 'mot_de_passe' => self::MOT_DE_PASSE]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'association est suspendue');

        $this->connecter($admin);
        $crawler = $this->client->request('GET', '/administration/associations/'.$moudery);
        $jeton = $crawler->filter('section[aria-labelledby="statut-titre"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/administration/associations/'.$moudery.'/statut/reactiver', ['_token' => $jeton]);
        self::assertResponseRedirects('/administration/associations/'.$moudery, 303);
        self::assertTrue($this->em()->find(Association::class, $moudery)?->estActive());

        $this->client->followRedirect();
        $this->client->submit($this->client->getCrawler()->filter('form.deconnexion')->form());
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'central@moudery.fr', 'mot_de_passe' => self::MOT_DE_PASSE]);
        self::assertResponseRedirects('http://localhost/', 302, 'Réactivée, l’association rouvre l’accès.');
    }

    public function testUneAssociationArchiveeNeSeSuspendPasEtSonTresorierEstBloque(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $tresorier = $this->creerUtilisateur($moudery, 'tresorier@example.org', Role::Tresorier, $lyon);
        $admin = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);
        $this->connecter($admin);

        $crawler = $this->client->request('GET', '/administration/associations/'.$moudery);
        $jeton = $crawler->filter('section[aria-labelledby="statut-titre"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/administration/associations/'.$moudery.'/statut/archiver', ['_token' => $jeton, 'retour' => '/administration/associations']);
        self::assertResponseRedirects('/administration/associations', 303, 'Le champ « retour » ramène sur la liste.');

        $this->client->request('POST', '/administration/associations/'.$moudery.'/statut/suspendre', ['_token' => $jeton]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'pas possible');
        self::assertTrue($this->em()->find(Association::class, $moudery)?->estArchivee());

        // Déjà connecté avant l'archivage, le trésorier ne peut plus rien faire sur sa ville.
        $this->connecter($tresorier);
        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant/identite', $lyon));
        self::assertResponseStatusCodeSame(403);
    }

    public function testLaListeSeChercheSeFiltreSeTrieEtSExporte(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->creerVille($moudery, 'Lyon');
        $this->creerVille($moudery, 'Marseille');
        $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $em = $this->em();
        $association = $em->find(Association::class, $bakel);
        \assert($association instanceof Association);
        $association->definirContact('contact@bakel.org', null, null);
        $association->changerStatut(AssociationStatut::Suspendue);
        $em->flush();

        $this->client->request('GET', '/administration/associations');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Associations 2');
        self::assertSelectorTextContains('nav[aria-label="Statut"] a[aria-current="true"]', 'Toutes · 2');
        self::assertSelectorTextContains('nav[aria-label="Statut"]', 'Suspendues · 1');
        self::assertSelectorTextContains('nav[aria-label="Bureau central"]', 'Sans bureau central · 1');
        self::assertSelectorTextContains('tbody tr:nth-child(1)', 'Association de Bakel', 'Par nom, Bakel précède Moudery.');
        self::assertSelectorTextContains('tbody tr:nth-child(1) .pastille', 'Suspendue');
        self::assertSelectorTextContains('tbody tr:nth-child(1)', 'contact@bakel.org');
        self::assertSelectorTextContains('tbody tr:nth-child(2)', 'Awa Cissé');
        self::assertSelectorTextContains('tfoot', 'Total · 2 associations');
        self::assertSelectorExists('thead th[aria-sort="ascending"] a[href*="tri=nom"][href*="sens=desc"]');
        self::assertSelectorExists(\sprintf('details.menu a[href="/administration/associations/%d/modifier"]', $bakel));
        self::assertSelectorExists(\sprintf('details.menu form[action="/administration/associations/%d/statut/reactiver"]', $bakel), 'Suspendue : le menu propose de réactiver.');
        self::assertSelectorExists(\sprintf('details.menu form[action="/administration/associations/%d/statut/suspendre"]', $moudery));
        self::assertSelectorExists(\sprintf('details.menu form[action="/administration/associations/%d/supprimer"]', $bakel), 'Vide : le menu propose de supprimer.');
        self::assertSelectorNotExists(\sprintf('details.menu form[action="/administration/associations/%d/supprimer"]', $moudery), 'Habitée : pas de suppression.');

        $this->client->request('GET', '/administration/associations?q=bak%C3%A9l');
        self::assertSelectorTextContains('section h2', '1 association affichée sur 2');
        self::assertSelectorTextContains('tbody', 'Association de Bakel');
        self::assertSelectorTextNotContains('tbody', 'Moudery');

        $this->client->request('GET', '/administration/associations?bureau=avec&tri=villes&sens=desc');
        self::assertSelectorTextContains('tbody', 'Moudery');
        self::assertSelectorTextNotContains('tbody', 'Bakel');
        self::assertSelectorExists('thead th[aria-sort="descending"]');

        $this->client->request('GET', '/administration/associations?q=introuvable');
        self::assertSelectorTextContains('.tableau__vide', 'Aucune association ne correspond');

        $this->client->request('GET', '/administration/associations/export.csv?statut=suspendues');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('associations-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Le BOM permet à Excel de lire l’UTF-8.');
        self::assertStringContainsString('Nom;Identifiant;Village;Statut;Villes', $csv);
        self::assertStringContainsString('"Association de Bakel";bakel;;Suspendue;0;0;0;0;;;contact@bakel.org;;;', $csv);
        self::assertStringNotContainsString('Moudery', $csv);
    }

    public function testLeBureauCentralNeChangePasLeStatutDUneAssociation(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral));

        $this->client->request('POST', '/administration/associations/'.$moudery.'/statut/suspendre', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/administration/associations/export.csv');
        self::assertResponseStatusCodeSame(403);
    }
}
