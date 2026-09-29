<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Cotisation;
use App\Entity\Echeance;
use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\ModeMontant;
use App\Entity\Paiement;
use App\Entity\TypeContribution;
use App\Entity\UniteContribution;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Paiement en ligne simulé depuis l'espace membre (décision de Rama du 29 septembre 2026, en attendant Stripe). */
final class MonEspacePaiementTest extends CasDeTestWeb
{
    private int $compteHawa;
    /** @var list<int> */
    private array $echeancesHawa = [];
    private int $echeanceMoussa;

    protected function setUp(): void
    {
        parent::setUp();
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $idLyon = $this->creerVille($moudery, 'Lyon', [], EtapeAssistant::Activation);
        $em = $this->em();
        $lyon = $em->find(Ville::class, $idLyon);
        \assert($lyon instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        $lyon->getAssociation()->definirParametres(1, 30, [-7, 0, 15]);
        $hawa = new Membre($lyon, 'Hawa', 'Soumaré', 'hawa@example.org');
        $moussa = new Membre($lyon, 'Moussa', 'Camara', 'moussa@example.org');
        $type = new TypeContribution($lyon->getAssociation(), 'cotisation', 'Cotisation', UniteContribution::Personne, ModeMontant::Fixe, 1000, null);
        $annee = (int) date('Y');
        $cotisation = new Cotisation($lyon, $type, $annee, 1000, 5, null, new \DateTimeImmutable());
        $em->persist($hawa);
        $em->persist($moussa);
        $em->persist($type);
        $em->persist($cotisation);
        $e1 = Echeance::pourCotisation($cotisation, $hawa, 1, $annee, new \DateTimeImmutable());
        $e2 = Echeance::pourCotisation($cotisation, $hawa, 2, $annee, new \DateTimeImmutable());
        $e3 = Echeance::pourCotisation($cotisation, $moussa, 1, $annee, new \DateTimeImmutable());
        foreach ([$e1, $e2, $e3] as $e) {
            $em->persist($e);
        }
        $em->flush();
        $this->echeancesHawa = [(int) $e1->getId(), (int) $e2->getId()];
        $this->echeanceMoussa = (int) $e3->getId();
        $this->compteHawa = $this->creerUtilisateur($moudery, 'hawa@example.org', Role::Membre, $idLyon);
    }

    public function testLeMembrePaieEnLigneEtVoitSonRecu(): void
    {
        $this->connecter($this->compteHawa);
        $crawler = $this->client->request('GET', '/mon-espace');
        self::assertSelectorExists('a[href="/mon-espace/payer"]');

        $crawler = $this->client->request('GET', '/mon-espace/payer');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.payer__simulation', 'Simulation');
        self::assertSelectorCount(2, 'input[name="echeances[]"]');
        self::assertSelectorNotExists('input[autocomplete="cc-number"]', 'Aucune donnée de carte n’est demandée.');

        $this->client->request('POST', '/mon-espace/payer', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'echeances' => $this->echeancesHawa,
        ]);
        self::assertResponseStatusCodeSame(303);
        self::assertQueuedEmailCount(1, null, 'Le reçu part par e-mail.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Merci Hawa');
        self::assertSelectorTextContains('.espace-membre__contenu', '20,00');
        self::assertSelectorTextContains('.espace-membre__contenu', '6,00', '30 % des cotisations reviennent au bureau central.');

        $paiement = $this->em()->getRepository(Paiement::class)->findOneBy([]);
        self::assertInstanceOf(Paiement::class, $paiement);
        self::assertTrue($paiement->estSimule());
        self::assertSame(2000, $paiement->getMontant());
        self::assertSame($this->compteHawa, $paiement->getEnregistrePar()?->getId());
        foreach ($this->echeancesHawa as $id) {
            self::assertTrue($this->em()->find(Echeance::class, $id)?->estPayee());
        }

        $this->client->request('GET', '/mon-espace/recus/'.$paiement->getId());
        self::assertSelectorTextContains('.recu', 'paiement en ligne simulé');
    }

    public function testOnNePaiePasLEcheanceDUnAutreMembre(): void
    {
        $this->connecter($this->compteHawa);
        $crawler = $this->client->request('GET', '/mon-espace/payer');
        $this->client->request('POST', '/mon-espace/payer', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'echeances' => [$this->echeanceMoussa],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->em()->find(Echeance::class, $this->echeanceMoussa)?->estPayee());

        $this->client->request('POST', '/mon-espace/payer', ['_token' => 'faux', 'echeances' => $this->echeancesHawa]);
        self::assertResponseStatusCodeSame(403);
    }
}
