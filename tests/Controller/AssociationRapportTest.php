<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\CategorieDepense;
use App\Entity\Depense;
use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\MoyenPaiement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Rapport d'AG (F-30) : synthèse, graphiques, comptes des villes, appels ; export pour Excel ; réservé au bureau central. */
final class AssociationRapportTest extends CasDeTestWeb
{
    private int $moudery;
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->annee = (int) date('Y');
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyonId = $this->creerVille($this->moudery, 'Lyon', [], EtapeAssistant::Activation);
        $this->creerVille($this->moudery, 'Évry');

        $em = $this->em();
        $lyon = $em->find(Ville::class, $lyonId);
        \assert($lyon instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        $mamadou = new Membre($lyon, 'Mamadou', 'Diaby');
        $mamadou->adherer($this->annee)->definirHistorique(array_fill(1, 12, 1000));
        $fanta = new Membre($lyon, 'Fanta', 'Traoré');
        $fanta->adherer($this->annee)->definirHistorique([1 => 1000, 2 => 1000]);
        $em->persist($mamadou);
        $em->persist($fanta);
        $em->flush();
    }

    public function testLeRapportReprendLesChiffresDeLExercice(): void
    {
        $auteurId = $this->creerUtilisateur($this->moudery, 'oumar@example.org', Role::BureauCentral);
        $valideurId = $this->creerUtilisateur($this->moudery, 'kadiatou@example.org', Role::BureauCentral);
        $this->payerUneDepense($auteurId, $valideurId, 5000);
        $this->connecter($auteurId);

        $this->client->request('GET', '/associations/moudery/rapport');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Rapport d’AG');
        self::assertSelectorTextContains('.console__nav a[aria-current="page"]', 'Rapports d’AG');
        self::assertSelectorCount(3, '.rapport__page');
        // 12 × 10 € + 2 × 10 € = 140 € collectés ; 50 € dépensés ; solde 90 €.
        self::assertSelectorTextContains('.rapport__kpis', '140');
        self::assertSelectorTextContains('.rapport__kpis', '50');
        self::assertSelectorTextContains('.rapport__kpis', '90');
        self::assertSelectorTextContains('.rapport__kpis', '50 %', 'Un adhérent sur deux à jour.');
        self::assertSelectorTextContains('.rapport__kpis', 'sur '.($this->annee - 1), 'Comparaison avec l’exercice précédent.');
        self::assertSelectorTextContains('.rapport__deux', 'Fêtes et événements', 'Dépenses par catégorie.');
        self::assertSelectorCount(12, '.rapport__barres .rapport__colonne');
        self::assertSelectorTextContains('#rapport-villes', 'Comptes des villes');
        self::assertSelectorTextContains('.rapport__table--large tbody', 'Lyon');
        self::assertSelectorTextNotContains('.rapport__table--large tbody', 'Évry', 'Un brouillon n’entre pas dans les comptes.');
        self::assertSelectorTextContains('.rapport__faits', '1 en cours de création');
        self::assertSelectorTextContains('.rapport__note', 'Le bureau central a dépensé 50');
        self::assertSelectorTextNotContains('.rapport', 'Mamadou', 'Aucun nom de membre dans le rapport.');

        $this->client->request('GET', '/associations/moudery/rapport/export.csv');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Collecté;140,00', $csv);
        self::assertStringContainsString('Dépensé;50,00', $csv);
        self::assertStringContainsString('Lyon;2;1;140,00;0,00;', $csv);
    }

    public function testLeTableauDeBordMeneAuRapportEtLeRapportEstReserve(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery?exercice=2025');
        self::assertSelectorExists('.console__actions a[href="/associations/moudery/rapport?exercice=2025"]');
        $this->client->request('GET', '/associations/moudery/rapport?exercice=2025');
        self::assertSelectorTextContains('.console__sous-titre', 'Exercice 2025');
        $this->client->request('GET', '/associations/moudery/rapport?exercice=1999');
        self::assertSelectorTextContains('.console__sous-titre', 'Exercice '.$this->annee, 'Hors des exercices suivis : l’exercice en cours.');

        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery/rapport');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/associations/moudery/rapport/export.csv');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeRapportDUneSeuleVille(): void
    {
        $lyon = $this->em()->getRepository(Ville::class)->findOneBy(['nom' => 'Lyon']);
        \assert($lyon instanceof Ville);
        $lyonId = (int) $lyon->getId();

        // Le bureau central choisit Lyon dans le périmètre : le rapport ne parle plus que de Lyon.
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery/rapport?ville='.$lyonId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__sous-titre', 'caisse de Lyon');
        self::assertSelectorTextContains('.rapport__texte', 'La caisse de Lyon a collecté');
        self::assertSelectorNotExists('.rapport__faits li:nth-child(3)');
        self::assertSelectorTextContains('.rapport__note', 'dépenses payées de la ville');
        $this->client->request('GET', '/associations/moudery/rapport/export.csv?ville='.$lyonId);
        self::assertStringContainsString('Lyon;2;1;140,00', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('rapport-ag-moudery-lyon-', (string) $this->client->getResponse()->headers->get('content-disposition'));

        // Un trésorier de Lyon ouvre le rapport de sa ville, par la navigation.
        $this->connecter($this->creerUtilisateur($this->moudery, 'tresorier@example.org', Role::Tresorier, $lyonId));
        $this->client->request('GET', '/associations/moudery/rapport');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__sous-titre', 'caisse de Lyon');
        self::assertSelectorExists('.console__nav a[href="/associations/moudery/rapport"]');
    }

    private function payerUneDepense(int $auteurId, int $valideurId, int $montant): void
    {
        $em = $this->em();
        $association = $em->find(Association::class, $this->moudery);
        $auteur = $em->find(Utilisateur::class, $auteurId);
        $valideur = $em->find(Utilisateur::class, $valideurId);
        \assert($association instanceof Association && $auteur instanceof Utilisateur && $valideur instanceof Utilisateur);
        $maintenant = new \DateTimeImmutable();
        $depense = new Depense($association, null, $this->annee.'-001', $auteur, $maintenant);
        $depense->definir('Sono pour la fête', $montant, $maintenant, CategorieDepense::Fetes, null, 'Loueur', MoyenPaiement::Virement, '');
        $depense->joindreJustificatif('1/test.pdf', 'facture.pdf', 'application/pdf', 1000);
        $depense->soumettre($maintenant);
        $depense->valider($valideur, $maintenant);
        $depense->marquerPayee($valideur, $maintenant);
        $em->persist($depense);
        $em->flush();
    }
}
