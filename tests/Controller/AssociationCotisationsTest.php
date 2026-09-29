<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Membre;
use App\Entity\Ville;
use App\Security\Role;

/** Les cotisations d'une ville, année par année : la grille du classeur, dans le périmètre choisi depuis la barre latérale. */
final class AssociationCotisationsTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $marseille;
    private int $annee;
    private int $mamadou;

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
        $mamadou = new Membre($lyon, 'Mamadou', 'Diaby', 'mamadou@example.org');
        $mamadou->adherer($this->annee - 1)->definirHistorique([1 => 1000, 2 => 1000], 2000, null);
        $mamadou->adherer($this->annee)->definirHistorique([1 => 1000, 2 => 1000, 3 => 'V', 4 => 0], 2000, 20000);
        $awa = new Membre($lyon, 'Awa', 'Cissé');
        $awa->adherer($this->annee);
        $awa->definirLocalite('Villeurbanne');
        $seydou = new Membre($marseille, 'Seydou', 'Sylla');
        $seydou->adherer($this->annee)->definirHistorique(array_fill(1, 12, 500), null, null);
        foreach ([$mamadou, $awa, $seydou] as $membre) {
            $em->persist($membre);
        }
        $em->flush();
        $this->mamadou = (int) $mamadou->getId();
    }

    public function testSansVilleDansLePerimetreOnLaChoisitPuisLaGrilleSAffiche(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/cotisations');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Cotisations');
        self::assertSelectorTextContains('.console__nav a[aria-current="page"]', 'Cotisations');
        self::assertCount(2, $crawler->filter('.choix-ville'), 'Lyon et Marseille à choisir.');
        self::assertSelectorTextContains('.choix-ville', '2 membres');
        self::assertSelectorTextContains('.choix-ville', 'collecté '.$this->annee.' : 20,00', 'Les chiffres de chaque ville aident à choisir.');

        $this->client->click($crawler->filter('.choix-ville')->first()->link());
        self::assertResponseRedirects('/associations/moudery/cotisations', 302, 'Le choix est mémorisé, puis retour aux cotisations.');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.perimetre .console__perimetre-nom', 'Lyon');
        self::assertSelectorTextContains('.console__sous-titre', 'Lyon · '.$this->annee.' · 2 adhérents');
        self::assertSelectorTextContains('.filtres', $this->annee.' · 2');
        self::assertSelectorTextContains('.filtres', ($this->annee - 1).' · 1');
        self::assertSelectorTextContains('.tableau--grille thead', 'Janv.');
        self::assertSelectorTextContains('.tableau--grille thead', 'Reste');
        self::assertCount(2, $crawler->filter('.tableau--grille tbody tr'));

        $ligne = $crawler->filter('.tableau--grille tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Mamadou Diaby'))->first();
        $mois = $ligne->filter('.grille__mois')->each(static fn ($td): string => trim($td->text()));
        self::assertSame(['10', '10', 'V', '0'], \array_slice($mois, 0, 4), 'Les montants en euros, le code tel quel, le zéro.');
        self::assertSame('20', $mois[12], 'Rapatriement.');
        self::assertSame('200', $mois[13], 'Projet.');
        self::assertStringContainsString('20,00', $mois[14], 'Total des mois.');
        self::assertStringContainsString('100,00', $mois[15], 'Reste dû sur douze fois 10 €.');
        self::assertSelectorExists(\sprintf('.tableau--grille a[href="/associations/moudery/membres/%d"]', $this->mamadou), 'Le nom ouvre la fiche.');
        self::assertSelectorTextContains('.tableau--grille', '—', 'Awa n’a pas de versements connus : un tiret.');

        // Les chiffres de l'année : deux adhérents, un renseigné, 20 € collectés, 100 € restant dus, personne à jour.
        self::assertSelectorTextContains('.kpis', 'Adhérents 2');
        self::assertSelectorTextContains('.kpis', '20,00');
        self::assertSelectorTextContains('.kpis', '100,00');
        self::assertSelectorTextContains('.kpis', 'À jour 0');
        self::assertSelectorExists(\sprintf('.console__actions a[href="/associations/moudery/membres/export.csv?ville=%d&annee=%d"]', $this->lyon, $this->annee));

        // L'année précédente et la recherche.
        $crawler = $this->client->request('GET', '/associations/moudery/cotisations?annee='.($this->annee - 1));
        self::assertCount(1, $crawler->filter('.tableau--grille tbody tr'));
        $crawler = $this->client->request('GET', '/associations/moudery/cotisations?q=ciss');
        self::assertCount(1, $crawler->filter('.tableau--grille tbody tr'));
        self::assertSelectorTextContains('.tableau--grille', 'Awa Cissé');
        $this->client->request('GET', '/associations/moudery/cotisations?q=personne');
        self::assertSelectorTextContains('.section__vide', 'Aucun adhérent ne correspond');

        // Marseille : tout le monde à jour à 5 € par mois.
        $this->client->request('GET', '/associations/moudery/cotisations?ville='.$this->marseille);
        self::assertSelectorTextContains('.perimetre .console__perimetre-nom', 'Marseille');
        self::assertSelectorTextContains('.kpis', 'À jour 1');
        self::assertSelectorTextContains('.kpis', '60,00');
    }

    public function testAvecUneSeuleVilleLaGrilleSAfficheSansChoisir(): void
    {
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->creerVille($bakel, 'Dakar');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.example.org', Role::BureauCentral));

        $this->client->request('GET', '/associations/bakel/cotisations');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.choix-ville', 'Une seule ville : aucun choix à faire.');
        self::assertSelectorTextContains('.perimetre .console__perimetre-nom', 'Dakar', 'La ville devient le périmètre.');
        self::assertSelectorTextContains('.console__sous-titre', 'Dakar');
        self::assertSelectorExists('.filtres');
    }

    public function testLesCotisationsSontReserveesAuBureauCentralDeLAssociation(): void
    {
        // Depuis le 29 septembre 2026, un trésorier ouvre les cotisations de sa ville : son périmètre y est figé.
        $this->connecter($this->creerUtilisateur($this->moudery, 'tresorier@lyon.fr', Role::Tresorier, $this->lyon));
        $this->client->request('GET', '/associations/moudery/cotisations');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__sous-titre', 'Lyon');

        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.fr', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery/cotisations');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/associations/moudery/perimetre/'.$this->lyon);
        self::assertResponseStatusCodeSame(403);
    }
}
