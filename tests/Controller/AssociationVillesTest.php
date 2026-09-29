<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Les villes de l'association vues par le bureau central (F-28) : statut, avancement, responsables, membres, à jour, collecté. */
final class AssociationVillesTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $evry;
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->annee = (int) date('Y');
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', ['tresorier' => 'awa@example.org'], EtapeAssistant::Activation);
        $this->evry = $this->creerVille($this->moudery, 'Évry', ['tresorier' => 'seydou@example.org'], EtapeAssistant::Membres);
        $leHavre = $this->creerVille($this->moudery, 'Le Havre', [], EtapeAssistant::Activation);
        $this->creerUtilisateur($this->moudery, 'awa@example.org', Role::Tresorier, $this->lyon);

        $em = $this->em();
        $lyon = $em->find(Ville::class, $this->lyon);
        $havre = $em->find(Ville::class, $leHavre);
        \assert($lyon instanceof Ville && $havre instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        $havre->changerStatut(VilleStatut::Active);
        $havre->changerStatut(VilleStatut::Archivee);
        foreach ($lyon->getInvitations() as $invitation) {
            $invitation->marquerEnvoyee(new \DateTimeImmutable('-10 days'));
            $invitation->marquerAcceptee(new \DateTimeImmutable('-9 days'));
        }

        // Deux adhérents cette année à Lyon : l'un a tout versé, l'autre deux mois.
        $mamadou = new Membre($lyon, 'Mamadou', 'Diaby');
        $mamadou->adherer($this->annee)->definirHistorique(array_fill(1, 12, 1000));
        $fanta = new Membre($lyon, 'Fanta', 'Traoré');
        $fanta->adherer($this->annee)->definirHistorique([1 => 1000, 2 => 1000]);
        $sans = new Membre($lyon, 'Hawa', 'Cissé');
        $em->persist($mamadou);
        $em->persist($fanta);
        $em->persist($sans);
        $em->flush();
    }

    public function testLeBureauCentralVoitSesVillesAvecLeursChiffres(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/villes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Villes');
        self::assertSelectorTextContains('h1 .compteur', '3');
        self::assertSelectorTextContains('.console__sous-titre', '1 active');
        self::assertSelectorTextContains('.console__nav a[aria-current="page"]', 'Villes');
        self::assertSelectorTextContains('.filtres', 'Toutes · 3');
        self::assertSelectorTextContains('.filtres', 'Brouillons · 1');
        self::assertSelectorTextContains('.filtres', 'Archivées · 1');

        $lignes = $crawler->filter('tbody tr');
        self::assertCount(3, $lignes);
        self::assertStringContainsString('Évry', $lignes->eq(0)->text(), 'Le brouillon vient en premier : il attend une action.');
        self::assertSelectorExists('tbody tr.villes__ligne--brouillon .progression__barre');
        self::assertStringContainsString('Étape 2 sur 3 · Membres', $lignes->eq(0)->text());
        self::assertStringContainsString('Invitation à envoyer à l’activation', $lignes->eq(0)->text());
        self::assertSelectorExists(\sprintf('tbody tr.villes__ligne--brouillon a[href="/associations/moudery/villes/%d/assistant"]', $this->evry), 'Le chevron reprend l’assistant.');

        $lyon = $lignes->eq(1);
        self::assertStringContainsString('Lyon', $lyon->text());
        self::assertStringContainsString('Active', $lyon->text());
        self::assertStringContainsString('AC', $lyon->filter('.villes__avatar')->text(), 'Le trésorier a son compte : ses initiales.');
        self::assertStringContainsString('Awa C.', $lyon->text());
        self::assertSame('3', trim($lyon->filter('td')->eq(3)->text()));
        self::assertStringContainsString('50 %', $lyon->text(), 'Un adhérent sur deux a tout versé.');
        self::assertStringContainsString('1 sur 2', $lyon->text());
        self::assertStringContainsString('140,00', $lyon->text(), 'Collecté : 120 € + 20 €.');
        self::assertCount(5, $lyon->filter('.menu__item'), 'Quatre liens et « Archiver la ville ».');

        $havre = $lignes->eq(2);
        self::assertStringContainsString('Le Havre', $havre->text());
        self::assertStringContainsString('Archivée', $havre->text());
        self::assertStringContainsString('—', $havre->filter('td')->eq(5)->text(), 'Sans historique, pas de montant inventé.');

        self::assertSelectorTextContains('tfoot', '140,00');
        self::assertSelectorTextContains('.section__aide', 'Une ville archivée n’est jamais supprimée');
        self::assertSelectorTextContains('thead', 'À jour '.$this->annee);
    }

    public function testLesChipsEtLaRechercheFiltrent(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/villes?statut=brouillons');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Évry');
        self::assertSelectorExists('.filtres a[aria-current="true"][href$="statut=brouillons"]');

        $crawler = $this->client->request('GET', '/associations/moudery/villes?q=awa');
        self::assertCount(1, $crawler->filter('tbody tr'), 'La recherche porte aussi sur les responsables.');
        self::assertSelectorTextContains('tbody', 'Lyon');

        $crawler = $this->client->request('GET', '/associations/moudery/villes?q=seydou@example.org');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Évry');

        $this->client->request('GET', '/associations/moudery/villes?q=atlantide');
        self::assertSelectorTextContains('.section__vide', 'Aucune ville ne correspond');
    }

    public function testSeulLeBureauCentralDeLAssociationVoitLaPage(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'tresorier2@example.org', Role::Tresorier, $this->lyon));
        $this->client->request('GET', '/associations/moudery/villes');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/associations/moudery/villes/export.csv');
        self::assertResponseStatusCodeSame(403, 'L’export non plus.');

        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery/villes');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/associations/moudery/villes/export.csv');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLePanneauDeFiltresRestreintLaListeEtSuitLExport(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        // Responsables en attente : Seydou (Évry) n'a pas accepté ; Awa (Lyon) oui ; Le Havre n'a personne.
        $this->client->request('GET', '/associations/moudery/villes?responsables=en_attente');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Évry');
        self::assertSelectorTextNotContains('tbody', 'Lyon');
        self::assertSelectorTextNotContains('tbody', 'Le Havre');
        self::assertSelectorTextContains('.filtres-menu summary', 'Filtres');
        self::assertSelectorTextContains('.filtres-menu summary .compteur', '1');
        self::assertSelectorExists('.filtres-menu select[name="responsables"] option[value="en_attente"][selected]');
        self::assertSelectorExists('.console__actions a[href="/associations/moudery/villes/export.csv?responsables=en_attente"]', 'L’export garde les filtres de la page.');
        self::assertSelectorExists('.filtres a[href="/associations/moudery/villes?responsables=en_attente&statut=actives"]', 'Les chips gardent les filtres du panneau.');
        self::assertSelectorExists('a.bouton--discret[href="/associations/moudery/villes"]', 'Effacer les filtres.');

        // Sans membre : Évry et Le Havre ; Lyon en a trois.
        $this->client->request('GET', '/associations/moudery/villes?membres=sans');
        self::assertSelectorTextContains('tbody', 'Évry');
        self::assertSelectorTextContains('tbody', 'Le Havre');
        self::assertSelectorTextNotContains('tbody', 'Lyon');

        // Une autre année : les chiffres suivent, Lyon n'a rien collecté en 2020.
        $this->client->request('GET', '/associations/moudery/villes?annee=2020');
        self::assertSelectorTextContains('thead', 'À jour 2020');
        self::assertSelectorTextContains('tfoot', '0,00');
        self::assertSelectorExists('.filtres-menu select[name="annee"] option[value="2020"][selected]');
        self::assertSelectorExists('.filtres-menu select[name="annee"] option[value="2025"]', 'Les exercices suivis depuis 2025 sont toujours proposés.');

        // Une année invraisemblable est ignorée : on revient à l'année de référence, sans filtre compté.
        $this->client->request('GET', '/associations/moudery/villes?annee=1850');
        self::assertSelectorTextContains('thead', 'À jour '.$this->annee);
        self::assertSelectorNotExists('.filtres-menu summary .compteur');
    }

    public function testLExportCsvRendLeTableauFiltre(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $this->client->request('GET', '/associations/moudery/villes/export.csv?statut=actives');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('villes-moudery-', (string) $this->client->getResponse()->headers->get('content-disposition'));
        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}Ville;Statut;\"Étape de création\";Responsables;\"Invitations en attente\";Membres;\"Adhérents ".$this->annee.'";', $contenu);
        self::assertStringContainsString('Lyon;Active;;"Awa ', $contenu);
        self::assertStringContainsString('(trésorier)";0;3;2;1;50;140,00;;', $contenu, 'Trois membres, deux adhérents, un à jour, 140 € collectés.');
        self::assertStringNotContainsString('Évry', $contenu, 'Le brouillon est hors du filtre « actives ».');
        self::assertStringNotContainsString('Le Havre', $contenu);

        $this->client->request('GET', '/associations/moudery/villes/export.csv?statut=brouillons');
        $contenu = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Évry;Brouillon;"Étape 2 sur 3 · Membres";"seydou@example.org (trésorier)";1;0;0;;;;;', $contenu);
    }
}
