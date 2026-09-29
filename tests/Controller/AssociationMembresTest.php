<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\Ville;
use App\Security\Role;

/** Les membres de l'association vus par le bureau central (F-28) : une ville et une année au choix, l'année en cours par défaut. */
final class AssociationMembresTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $marseille;
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->annee = (int) date('Y');
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon');
        $this->marseille = $this->creerVille($this->moudery, 'Marseille');

        $em = $this->em();
        $lyon = $em->find(Ville::class, $this->lyon);
        $marseille = $em->find(Ville::class, $this->marseille);
        \assert($lyon instanceof Ville && $marseille instanceof Ville);

        $mamadou = new Membre($lyon, 'Mamadou', 'Diaby', 'mamadou@example.org', '0612345678');
        $mamadou->adherer($this->annee - 2);
        $mamadou->adherer($this->annee - 1);
        $mamadou->adherer($this->annee);
        $awa = new Membre($lyon, 'Awa', 'Cissé');
        $awa->adherer($this->annee);
        $awa->definirLocalite('Villeurbanne');
        $seydou = new Membre($marseille, 'Seydou', 'Sylla', null, '0700000000', MembreStatut::EnAttente);
        $seydou->adherer($this->annee - 1);
        $sansAnnee = new Membre($marseille, 'Fanta', 'Traoré');
        foreach ([$mamadou, $awa, $seydou, $sansAnnee] as $membre) {
            $em->persist($membre);
        }
        $em->flush();
    }

    public function testParDefautToutesLesVillesEtLAnneeEnCours(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/membres');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Membres');
        self::assertSelectorTextContains('.console__sous-titre', 'Toutes les villes · Adhérents en '.$this->annee.' · 2 membres');
        self::assertCount(2, $crawler->filter('.tableau tbody tr'));
        self::assertSelectorTextContains('.tableau', 'Awa Cissé');
        self::assertSelectorTextContains('.tableau', 'Villeurbanne');
        self::assertSelectorTextContains('.tableau', 'Mamadou Diaby');
        self::assertSelectorTextNotContains('.tableau', 'Seydou Sylla');
        self::assertSelectorTextContains('.tableau thead', 'Ville', 'Sans ville choisie, la colonne Ville est là.');
        self::assertSelectorTextContains('.filtres-groupe', 'Lyon · 2');
        self::assertSelectorTextContains('.filtres-groupe', 'Marseille · 0');
        self::assertSelectorTextContains('.filtres-groupe', ($this->annee - 1).' · 2');
        self::assertSelectorExists(\sprintf('.filtres-groupe a[aria-current="true"][href$="annee=%d"]', $this->annee));
        self::assertSelectorTextContains('.kpis', 'Nouveaux en '.$this->annee);
        self::assertStringContainsString('1', $crawler->filter('.kpi')->eq(1)->filter('.kpi__valeur')->text(), 'Awa est nouvelle cette année, Mamadou non.');
        self::assertSelectorTextContains('.console__nav a[aria-current="page"]', 'Membres');
        self::assertSelectorTextContains('.chip--accent', (string) $this->annee);
    }

    public function testOnChoisitUneVilleEtUneAnnee(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/membres?ville=%d&annee=%d', $this->marseille, $this->annee - 1));
        self::assertCount(1, $crawler->filter('.tableau tbody tr'));
        self::assertSelectorTextContains('.tableau', 'Seydou Sylla');
        self::assertSelectorTextContains('.tableau', 'En attente');
        self::assertSelectorTextNotContains('.tableau thead', 'Ville', 'Une ville choisie : plus de colonne Ville.');
        self::assertSelectorTextContains('.console__sous-titre', 'Marseille · Adhérents en '.($this->annee - 1));

        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/membres?ville=%d&annee=toutes', $this->marseille));
        self::assertCount(2, $crawler->filter('.tableau tbody tr'));
        self::assertSelectorTextContains('.tableau', 'Aucune année');

        $crawler = $this->client->request('GET', '/associations/moudery/membres?annee=toutes&q=0700');
        self::assertCount(1, $crawler->filter('.tableau tbody tr'));
        self::assertSelectorTextContains('.tableau', 'Seydou Sylla');

    }

    public function testSansAdherentCetteAnneeLaPageOuvreSurLaDerniereAnnee(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        // Marseille n'a d'adhérents que l'an dernier : sa page s'ouvre sur cette année-là plutôt que vide.
        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/membres?ville=%d', $this->marseille));

        self::assertSelectorTextContains('.console__sous-titre', 'Marseille · Adhérents en '.($this->annee - 1).' · 1 membre');
        self::assertSelectorTextContains('.tableau', 'Seydou Sylla');
        self::assertSelectorTextContains('.filtres-groupe', $this->annee.' · 0', 'L’année en cours reste proposée.');
        self::assertCount(1, $crawler->filter('.tableau tbody tr'));

        // Demandée explicitement, l'année en cours s'affiche même vide.
        $this->client->request('GET', \sprintf('/associations/moudery/membres?ville=%d&annee=%d', $this->marseille, $this->annee));
        self::assertSelectorTextContains('.section__vide', 'Aucun adhérent en '.$this->annee);
    }

    public function testLeNomOuvreLaFicheEtLePerimetreDeLaBarreFixeLaVille(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $mamadou = $this->em()->getRepository(Membre::class)->findOneBy(['email' => 'mamadou@example.org']);
        \assert($mamadou instanceof Membre);

        $crawler = $this->client->request('GET', '/associations/moudery/membres');
        self::assertSelectorExists(\sprintf('.tableau a[href="/associations/moudery/membres/%d"]', $mamadou->getId()), 'Le nom ouvre la fiche.');
        self::assertSelectorExists('.filtres-groupe a[href$="ville=toutes"]', 'À l’échelle de l’association, les chips de ville sont là.');

        // Lyon choisie dans la barre latérale : la page suivante, sans paramètre, reste sur Lyon et n'a plus de chips de ville.
        $this->client->request('GET', \sprintf('/associations/moudery/perimetre/%d?retour=%%2Fassociations%%2Fmoudery%%2Fmembres', $this->lyon));
        self::assertResponseRedirects('/associations/moudery/membres', 302);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.console__sous-titre', 'Lyon · Adhérents en '.$this->annee);
        self::assertSelectorTextContains('.perimetre .console__perimetre-nom', 'Lyon');
        self::assertCount(2, $crawler->filter('.tableau tbody tr'));
        self::assertSelectorTextNotContains('.tableau thead', 'Ville');
        self::assertSelectorNotExists('.filtres-groupe a[href$="ville=toutes"]');

        $this->client->click($crawler->filter(\sprintf('a[href="/associations/moudery/membres/%d"]', $mamadou->getId()))->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mamadou Diaby');
        self::assertSelectorTextContains('.perimetre .console__perimetre-nom', 'Lyon', 'La fiche garde le périmètre.');
    }

    public function testLExportCsvSuitLaSelection(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $this->client->request('GET', '/associations/moudery/membres/export.csv?annee=toutes');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBFVille;Nom;Prénom;E-mail;Téléphone;Localité;Foyer;\"Années d’adhésion\";Statut", $csv);
        self::assertStringContainsString(\sprintf('Lyon;Diaby;Mamadou;mamadou@example.org;"06 12 34 56 78";;;"%d %d %d";Actif', $this->annee - 2, $this->annee - 1, $this->annee), $csv);
        self::assertStringContainsString('Marseille;Traoré;Fanta;;;;;;Actif', $csv);

        $this->client->request('GET', \sprintf('/associations/moudery/membres/export.csv?ville=%d', $this->lyon));
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Cissé', $csv);
        self::assertStringNotContainsString('Sylla', $csv);
    }

    public function testLExportDUneAnneePorteLesMoisDuClasseur(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $em = $this->em();
        $mamadou = $em->getRepository(Membre::class)->findOneBy(['nom' => 'Diaby']);
        \assert($mamadou instanceof Membre);
        $mamadou->adhesionPour($this->annee)?->definirHistorique([1 => 1000, 2 => 'V', 3 => 500], 2000, 20000);
        $em->flush();

        $this->client->request('GET', '/associations/moudery/membres/export.csv?annee='.$this->annee);

        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(';Statut;Année;Janv.;Févr.;', $csv);
        self::assertStringContainsString(';Déc.;Rapatriement;Projet;"Total des mois";"Reste dû"', $csv);
        self::assertStringContainsString(\sprintf(';Actif;%d;10;V;5;;;;;;;;;;20;200;15;105', $this->annee), $csv, 'Les mois en euros, le code « V » tel quel, le reste sur douze fois le tarif le plus fréquent.');
        self::assertStringContainsString(\sprintf('Lyon;Cissé;Awa;;;Villeurbanne;;%d;Actif;%d;;;;;;;;;;;;;;;;', $this->annee, $this->annee), $csv, 'Sans historique, les colonnes du classeur restent vides.');
    }

    public function testSeulLeBureauCentralDeLAssociationVoitSesMembres(): void
    {
        // Un trésorier voit les membres de sa ville seulement (espace de la ville, 29 septembre 2026).
        $this->connecter($this->creerUtilisateur($this->moudery, 'tresorier@example.org', Role::Tresorier, $this->lyon));
        $this->client->request('GET', '/associations/moudery/membres?ville=toutes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__sous-titre', 'Lyon', 'Le périmètre reste sa ville, même en demandant « toutes ».');

        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery/membres');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/associations/moudery/membres/export.csv');
        self::assertResponseStatusCodeSame(403);
    }
}
