<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Membre;
use App\Entity\Ville;
use App\Security\Role;

/** La fiche d'un membre : identité, contact, adhésions année par année ; ouverte au bureau central et aux responsables de la ville. */
final class AssociationMembreFicheTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $marseille;
    private int $awa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon');
        $this->marseille = $this->creerVille($this->moudery, 'Marseille');

        $em = $this->em();
        $lyon = $em->find(Ville::class, $this->lyon);
        \assert($lyon instanceof Ville);
        $awa = new Membre($lyon, 'Awa', 'Cissé', null, '0612345678', origine: Membre::ORIGINE_IMPORT);
        $awa->definirLocalite('Villeurbanne');
        $awa->definirAnneeNaissance(1990);
        $awa->adherer(2019, Membre::ORIGINE_IMPORT)->definirHistorique([1 => 1000, 2 => 'X'], null, null);
        $awa->adherer(2020, Membre::ORIGINE_IMPORT);
        $em->persist($awa);
        $em->flush();
        $this->awa = (int) $awa->getId();
    }

    public function testLeBureauCentralLitLaFicheComplete(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@moudery.fr', Role::BureauCentral));

        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/membres/%d', $this->awa));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Awa Cissé');
        self::assertSelectorTextContains('.console__sous-titre', 'Lyon · Villeurbanne');
        self::assertSelectorTextContains('.proprietes', 'Villeurbanne');
        self::assertSelectorTextContains('.proprietes', '1990');
        self::assertSelectorTextContains('.proprietes', '06 12 34 56 78');
        self::assertSelectorTextContains('.proprietes', 'Importé d’un fichier');
        self::assertSelectorTextContains('.console__nav a[aria-current="page"]', 'Membres');
        self::assertSelectorExists(\sprintf('a.fil-ariane[href="/associations/moudery/membres?ville=%d&annee=toutes"]', $this->lyon));

        $lignes = $crawler->filter('.tableau--grille tbody tr');
        self::assertCount(2, $lignes, 'Une ligne par année d’adhésion.');
        self::assertStringContainsString('2020', $lignes->first()->text(), 'La plus récente d’abord.');
        self::assertStringContainsString('—', $lignes->first()->text(), 'Sans historique, un tiret.');
        self::assertStringContainsString('2019', $lignes->last()->text());
        self::assertStringContainsString('X', $lignes->last()->filter('.grille__code')->text());
        self::assertStringContainsString('10,00', $lignes->last()->filter('.grille__total')->first()->text());
    }

    public function testLesResponsablesDeLaVilleOuvrentLaFicheMaisPasCeuxDUneAutreVille(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'tresorier@lyon.fr', Role::Tresorier, $this->lyon));
        $this->client->request('GET', \sprintf('/associations/moudery/membres/%d', $this->awa));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.menu__item[href*="/assistant/membres"]', 'Le trésorier de la ville peut aller gérer le membre.');
        self::assertSelectorExists('a.bouton--primaire[href$="/modifier"]', 'Et modifier la fiche.');

        $this->connecter($this->creerUtilisateur($this->moudery, 'tresorier@marseille.fr', Role::Tresorier, $this->marseille));
        $this->client->request('GET', \sprintf('/associations/moudery/membres/%d', $this->awa));
        self::assertResponseStatusCodeSame(403, 'Le trésorier de Marseille ne voit pas un membre de Lyon.');
    }

    public function testUneAutreAssociationNeTrouvePasLaFiche(): void
    {
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.fr', Role::BureauCentral));

        $this->client->request('GET', \sprintf('/associations/bakel/membres/%d', $this->awa));
        self::assertResponseStatusCodeSame(404, 'Le membre n’appartient pas à cette association.');

        // Le filtre multi-tenant rend la fiche invisible depuis Bakel : 404 plutôt qu'un 403 qui révélerait son existence.
        $this->client->request('GET', \sprintf('/associations/moudery/membres/%d', $this->awa));
        self::assertResponseStatusCodeSame(404);
    }
}
