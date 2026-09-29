<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Depense;
use App\Entity\DepenseStatut;
use App\Security\Role;
use Symfony\Component\DomCrawler\Crawler;

/** Dépenses du bureau central (F-21 à F-23) : saisie, justificatif obligatoire, séparation des tâches, refus motivé, paiement. */
final class AssociationDepensesTest extends CasDeTestWeb
{
    private int $moudery;
    private int $auteur;
    private int $collegue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->auteur = $this->creerUtilisateur($this->moudery, 'oumar@example.org', Role::BureauCentral);
        $this->collegue = $this->creerUtilisateur($this->moudery, 'kadiatou@example.org', Role::BureauCentral);
    }

    protected function tearDown(): void
    {
        $dossier = (string) static::getContainer()->getParameter('kernel.project_dir').'/var/justificatifs/test';
        if (is_dir($dossier)) {
            foreach (glob($dossier.'/*/*') ?: [] as $fichier) {
                unlink($fichier);
            }
        }
        parent::tearDown();
    }

    public function testUneDepenseSansJustificatifNeSeSoumetPas(): void
    {
        $this->connecter($this->auteur);
        $crawler = $this->client->request('GET', '/associations/moudery/depenses/nouvelle');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nouvelle dépense');
        self::assertSelectorTextContains('.console__nav a[aria-current="page"]', 'Dépenses');
        self::assertSelectorTextContains('.depense-circuit', 'Awa Cissé', 'Le circuit nomme qui peut valider.');

        $this->client->submit($this->formulaire($crawler, 'soumettre'));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.depense-justificatif .champ__erreur', 'une dépense sans pièce ne peut pas être soumise');

        $this->client->submit($this->formulaire($this->client->getCrawler(), 'brouillon'));
        $depense = $this->derniere();
        self::assertResponseRedirects('/associations/moudery/depenses/'.$depense->getId(), 303);
        self::assertSame(DepenseStatut::Brouillon, $depense->getStatut());
        self::assertSame(date('Y').'-001', $depense->getNumero());
        self::assertSame(18000, $depense->getMontant());
        self::assertQueuedEmailCount(0);
    }

    public function testLeCircuitCompletRespecteLaSeparationDesTaches(): void
    {
        $this->connecter($this->auteur);
        $crawler = $this->client->request('GET', '/associations/moudery/depenses/nouvelle');
        $this->client->submit($this->formulaire($crawler, 'soumettre', $this->pdf()));
        self::assertQueuedEmailCount(1, null, 'Kadiatou est prévenue.');
        $depense = $this->derniere();
        self::assertSame(DepenseStatut::Soumise, $depense->getStatut());
        $id = (int) $depense->getId();

        // L'auteur ne peut pas valider : pas de bouton, un encart, et la route refuse.
        $this->client->request('GET', '/associations/moudery/depenses/'.$id);
        self::assertSelectorNotExists('form[action$="/valider"]');
        self::assertSelectorTextContains('.depense-verrou', 'vous l’avez saisie');
        self::assertSelectorTextContains('.console__nav', '1', 'Le compteur des dépenses à valider.');
        $this->client->request('POST', \sprintf('/associations/moudery/depenses/%d/valider', $id), ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);

        // Le justificatif s'ouvre, protégé.
        $this->client->request('GET', \sprintf('/associations/moudery/depenses/%d/justificatif', $id));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/pdf');

        // La collègue valide, l'auteur est prévenu, puis la dépense est payée.
        $this->connecter($this->collegue);
        $crawler = $this->client->request('GET', '/associations/moudery/depenses/'.$id);
        $this->client->submit($crawler->filter('form[action$="/valider"]')->form());
        self::assertQueuedEmailCount(1, null, 'Oumar est prévenu de la validation.');
        self::assertSame(DepenseStatut::Validee, $this->recharger($id)->getStatut());

        $crawler = $this->client->request('GET', '/associations/moudery/depenses/'.$id);
        $this->client->submit($crawler->filter('form[action$="/payer"]')->form());
        self::assertSame(DepenseStatut::Payee, $this->recharger($id)->getStatut());

        // Le tableau de bord compte la dépense payée : Dépensé 180 €, solde négatif faute d'encaissement.
        $this->client->request('GET', '/associations/moudery?ville=association');
        self::assertSelectorTextContains('.kpis', '180');

        $this->client->request('GET', '/associations/moudery/depenses?statut=payees');
        self::assertSelectorTextContains('.tableau--depenses', 'Location de chaises et bâches');
        self::assertSelectorTextContains('section[aria-labelledby="depenses-decisions"]', 'Validée');
    }

    public function testUnRefusExigeUnMotifEtRendLaDepenseModifiable(): void
    {
        $this->connecter($this->auteur);
        $crawler = $this->client->request('GET', '/associations/moudery/depenses/nouvelle');
        $this->client->submit($this->formulaire($crawler, 'soumettre', $this->pdf()));
        $id = (int) $this->derniere()->getId();

        $this->connecter($this->collegue);
        $crawler = $this->client->request('GET', '/associations/moudery/depenses/'.$id);
        $formulaire = $crawler->filter('form[action$="/refuser"]')->form();
        $formulaire['motif'] = '';
        $this->client->submit($formulaire);
        self::assertSame(DepenseStatut::Soumise, $this->recharger($id)->getStatut(), 'Pas de refus sans motif.');

        $crawler = $this->client->request('GET', '/associations/moudery/depenses/'.$id);
        $formulaire = $crawler->filter('form[action$="/refuser"]')->form();
        $formulaire['motif'] = 'Justificatif illisible, merci de renvoyer la photo';
        $this->client->submit($formulaire);
        $depense = $this->recharger($id);
        self::assertSame(DepenseStatut::Refusee, $depense->getStatut());
        self::assertSame('Justificatif illisible, merci de renvoyer la photo', $depense->getMotifRefus());

        $this->connecter($this->auteur);
        $this->client->request('GET', \sprintf('/associations/moudery/depenses/%d/modifier', $id));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alerte--erreur', 'Justificatif illisible');
    }

    public function testLesDepensesRestentDansLeurAssociation(): void
    {
        $this->connecter($this->auteur);
        $crawler = $this->client->request('GET', '/associations/moudery/depenses/nouvelle');
        $this->client->submit($this->formulaire($crawler, 'soumettre', $this->pdf()));
        $id = (int) $this->derniere()->getId();

        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery/depenses/'.$id);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', \sprintf('/associations/bakel/depenses/%d/justificatif', $id));
        self::assertResponseStatusCodeSame(404);

        // Un trésorier voit les dépenses de sa ville (espace de la ville, 29 septembre 2026), pas celles du central.
        $lyon = $this->creerVille($this->moudery, 'Lyon');
        $this->connecter($this->creerUtilisateur($this->moudery, 'tresorier@example.org', Role::Tresorier, $lyon));
        $this->client->request('GET', '/associations/moudery/depenses');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('.tableau--depenses, .section__vide', 'Location de chaises et bâches');
        $this->client->request('GET', '/associations/moudery/depenses/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/valider"]', 'La dépense du central ne se décide pas depuis une ville.');
    }

    private function formulaire(Crawler $crawler, string $action, ?string $fichier = null): \Symfony\Component\DomCrawler\Form
    {
        $formulaire = $crawler->filter('button[value="'.$action.'"]')->form();
        $formulaire['depense[libelle]'] = 'Location de chaises et bâches';
        $formulaire['depense[montant]'] = '180,00';
        $formulaire['depense[date]'] = date('Y-m-d');
        $formulaire['depense[categorie]'] = 'fetes';
        $formulaire['depense[beneficiaire]'] = 'Salle des fêtes';
        if (null !== $fichier) {
            $formulaire['depense[justificatif]']->upload($fichier);
        }

        return $formulaire;
    }

    private function pdf(): string
    {
        $chemin = sys_get_temp_dir().'/facture-'.bin2hex(random_bytes(4)).'.pdf';
        file_put_contents($chemin, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return $chemin;
    }

    private function derniere(): Depense
    {
        $depense = $this->em()->getRepository(Depense::class)->findOneBy([], ['id' => 'DESC']);
        \assert($depense instanceof Depense);

        return $depense;
    }

    private function recharger(int $id): Depense
    {
        $this->em()->clear();
        $depense = $this->em()->find(Depense::class, $id);
        \assert($depense instanceof Depense);

        return $depense;
    }
}
