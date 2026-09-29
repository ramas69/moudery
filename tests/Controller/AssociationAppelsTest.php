<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AppelContribution;
use App\Entity\AppelStatut;
use App\Entity\Echeance;
use App\Entity\EtapeAssistant;
use App\Entity\Foyer;
use App\Entity\Membre;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Projets et appels du bureau central (F-13, maquette « 04 Lancer un appel ») : brouillon, synthèse, lancement, isolation. */
final class AssociationAppelsTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $paris;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', [], EtapeAssistant::Activation);
        $this->paris = $this->creerVille($this->moudery, 'Paris', [], EtapeAssistant::Activation);
        $this->creerVille($this->moudery, 'Évry');

        $em = $this->em();
        $lyon = $em->find(Ville::class, $this->lyon);
        $paris = $em->find(Ville::class, $this->paris);
        \assert($lyon instanceof Ville && $paris instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        $paris->changerStatut(VilleStatut::Active);

        // Lyon : un foyer de deux (Hawa paie), un membre seul sans adresse ; Paris : un membre.
        $foyer = new Foyer($lyon, 'Famille Sakho');
        $hawa = new Membre($lyon, 'Hawa', 'Sakho', 'hawa@example.org');
        $moussa = new Membre($lyon, 'Moussa', 'Sakho', 'moussa@example.org');
        $sans = new Membre($lyon, 'Awa', 'Cissé');
        $fanta = new Membre($paris, 'Fanta', 'Diaby', 'fanta@example.org');
        foreach ([$foyer, $hawa, $moussa, $sans, $fanta] as $objet) {
            $em->persist($objet);
        }
        $em->flush();
        $hawa->rejoindreFoyer($foyer);
        $moussa->rejoindreFoyer($foyer);
        $foyer->designerPayeur($hawa);
        $em->flush();
    }

    public function testLeFormulaireReprendLaMaquette(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/appels/nouveau');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nouvel appel à contribution');
        self::assertSelectorTextContains('.console__fil', 'Projets et appels');
        self::assertSelectorTextContains('.console__nav a[aria-current="page"]', 'Projets et appels');
        self::assertCount(4, $crawler->filter('.appel-type'), 'Décès, Projet du village, Fête, Autre.');
        self::assertSelectorTextContains('.appel-type', 'par adulte');
        self::assertStringNotContainsString('%', $crawler->filter('.appel__types')->text(), 'Aucun pourcentage sur les types.');
        self::assertSelectorTextContains('.appel__types', 'Par foyer');
        self::assertSame('10,00', $crawler->filter('#appel_montant')->attr('value'));
        self::assertStringContainsString('Toutes les villes · 4 membres', $crawler->filter('#appel_perimetre')->text(), 'Évry, en brouillon, ne compte pas.');
        self::assertSelectorExists('#appel_taux', 'La part du central se saisit sur l’appel.');

        // « Ce qui va se passer » : 4 membres × 10 €, 3 e-mails, 1 sans adresse ; part du central inconnue tant que le taux n'est pas saisi.
        self::assertSelectorTextContains('[data-appel-target="membres"]', '4');
        self::assertSelectorTextContains('[data-appel-target="objectif"]', '40');
        self::assertSelectorTextContains('[data-appel-target="partCentral"]', '0');
        self::assertSelectorTextContains('[data-appel-target="emails"]', '3');
        self::assertSelectorTextContains('[data-appel-target="sansEmail"]', '1');
        self::assertSelectorTextContains('.console__actions button[value="lancer"]', 'Lancer l’appel à 4 membres');
        self::assertSelectorTextContains('#apercu-email', 'Bonjour');
        self::assertSelectorTextContains('#apercu-email', 'en ligne');
        self::assertSelectorTextContains('#apercu-email', 'Bonjour Hawa');
    }

    public function testEnregistrerUnBrouillonNEnvoieRien(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/appels/nouveau');
        $formulaire = $crawler->filter('button[value="enregistrer"]')->form();
        $formulaire['appel[objet]'] = '';
        $this->client->submit($formulaire);
        self::assertResponseStatusCodeSame(422, 'L’objet est obligatoire.');
        self::assertSelectorTextContains('.champ__erreur', 'Donnez un objet');

        $formulaire = $this->client->getCrawler()->filter('button[value="enregistrer"]')->form();
        $formulaire['appel[objet]'] = 'Contribution décès · famille Sakho';
        $formulaire['appel[taux]'] = '40';
        $formulaire['appel[message]'] = 'Notre communauté a perdu un membre.';
        $this->client->submit($formulaire);
        self::assertResponseRedirects('/associations/moudery/appels', 303);
        self::assertQueuedEmailCount(0);

        $appel = $this->em()->getRepository(AppelContribution::class)->findOneBy([]);
        self::assertInstanceOf(AppelContribution::class, $appel);
        self::assertSame(AppelStatut::Brouillon, $appel->getStatut());
        self::assertSame(1000, $appel->getMontant());
        self::assertSame(40, $appel->getTauxReversement(), 'Le taux saisi sur l’appel.');
        self::assertCount(0, $this->em()->getRepository(Echeance::class)->findAll());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.tableau', 'Contribution décès · famille Sakho');
        self::assertSelectorTextContains('.tableau', 'Brouillon');

        $this->client->request('GET', '/associations/moudery/appels/'.$appel->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.appel__etat', 'Brouillon enregistré à');
    }

    public function testLancerCreeLesEcheancesEtEnvoieLesEmails(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/appels/nouveau');
        $formulaire = $crawler->filter('button[value="lancer"]')->form();
        $formulaire['appel[objet]'] = 'Contribution décès · famille Sakho';
        $formulaire['appel[taux]'] = '40';
        $this->client->submit($formulaire);
        self::assertQueuedEmailCount(3, null, 'Hawa, Moussa et Fanta ; Awa n’a pas d’adresse.');
        $appel = $this->em()->getRepository(AppelContribution::class)->findOneBy([]);
        \assert($appel instanceof AppelContribution);
        self::assertResponseRedirects('/associations/moudery/appels/'.$appel->getId(), 303);
        self::assertSame(AppelStatut::Ouvert, $appel->getStatut());
        self::assertCount(4, $this->em()->getRepository(Echeance::class)->findBy(['appel' => $appel]));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', '4 échéances créées, 3 e-mails envoyés');
        self::assertSelectorTextContains('.kpis', 'sur 4 échéances');
        self::assertSelectorNotExists('form#appel-formulaire', 'Un appel lancé ne se modifie plus.');
    }

    public function testUnAppelParFoyerNeVisePasDeuxFoisLeMemeFoyerEtPeutViserUneVille(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/appels/nouveau');
        $formulaire = $crawler->filter('button[value="lancer"]')->form();
        $formulaire['appel[type]'] = 'fete';
        $formulaire['appel[objet]'] = 'Fête de Lyon';
        $formulaire['appel[montant]'] = '15';
        $formulaire['appel[perimetre]'] = (string) $this->lyon;
        $formulaire['appel[taux]'] = '0';
        $this->client->submit($formulaire);

        $appel = $this->em()->getRepository(AppelContribution::class)->findOneBy([]);
        \assert($appel instanceof AppelContribution);
        $echeances = $this->em()->getRepository(Echeance::class)->findBy(['appel' => $appel]);
        $prenoms = array_map(static fn (Echeance $e): string => $e->getMembre()->getPrenom(), $echeances);
        sort($prenoms);
        self::assertSame(['Awa', 'Hawa'], $prenoms, 'Par foyer : Hawa paie pour le foyer Sakho, Awa seule ; Paris hors périmètre.');
        self::assertSame(0, $appel->getTauxReversement(), 'Tout reste à la ville : 0 % saisi.');
        self::assertQueuedEmailCount(1);
    }

    public function testLeSuiviMontreLeCollecteDesEcheancesPayees(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $crawler = $this->client->request('GET', '/associations/moudery/appels/nouveau');
        $formulaire = $crawler->filter('button[value="lancer"]')->form();
        $formulaire['appel[objet]'] = 'Contribution décès · famille Sakho';
        $formulaire['appel[taux]'] = '40';
        $this->client->submit($formulaire);

        // Deux des quatre échéances de 10 € sont payées (paiement manuel ou en ligne : l'échéance est soldée).
        $em = $this->em();
        $appel = $em->getRepository(AppelContribution::class)->findOneBy([]);
        \assert($appel instanceof AppelContribution);
        $echeances = $em->getRepository(Echeance::class)->findBy(['appel' => $appel], ['id' => 'ASC']);
        $echeances[0]->payer(null, new \DateTimeImmutable());
        $echeances[1]->payer(null, new \DateTimeImmutable());
        $em->flush();

        $this->client->request('GET', '/associations/moudery/appels/'.$appel->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.appel-suivi__montant', '20');
        self::assertSelectorTextContains('.appel-suivi', 'sur 40');
        self::assertSelectorTextContains('.appel-suivi__pourcentage', '50 %');
        self::assertSelectorTextContains('.appel-suivi', '2 contributeurs sur 4 échéances');
        self::assertSelectorTextContains('.kpis', '8', 'Part du central : 40 % de 20 €.');
        self::assertSelectorExists('.appel-suivi__jours');
        self::assertSelectorTextContains('section[aria-labelledby="appel-villes"]', 'Lyon');

        $this->client->request('GET', '/associations/moudery/appels');
        self::assertSelectorTextContains('.tableau', '20,00');
        self::assertSelectorTextContains('.tableau', '50 %');

        // Le tableau de bord compte ces contributions ponctuelles dans le collecté.
        $this->client->request('GET', '/associations/moudery?ville=association');
        self::assertSelectorTextContains('.kpis', '20');
    }

    public function testUnTypeSansTauxExigeLeTaux(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/appels/nouveau');
        $formulaire = $crawler->filter('button[value="enregistrer"]')->form();
        $formulaire['appel[type]'] = 'autre';
        $formulaire['appel[objet]'] = 'Puits du village';
        $formulaire['appel[taux]'] = '';
        $this->client->submit($formulaire);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.champ__erreur', 'indiquez la part reversée');
    }

    public function testLesAppelsSontReservesAuBureauCentralDeLAssociation(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'tresorier@example.org', Role::Tresorier, $this->lyon));
        $this->client->request('GET', '/associations/moudery/appels/nouveau');
        self::assertResponseStatusCodeSame(403);

        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery/appels');
        self::assertResponseStatusCodeSame(403);

        // Un appel de Moudery ne s'ouvre pas depuis l'adresse de Bakel.
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $crawler = $this->client->request('GET', '/associations/moudery/appels/nouveau');
        $formulaire = $crawler->filter('button[value="enregistrer"]')->form();
        $formulaire['appel[objet]'] = 'Décès';
        $formulaire['appel[taux]'] = '40';
        $this->client->submit($formulaire);
        $appel = $this->em()->getRepository(AppelContribution::class)->findOneBy([]);
        \assert($appel instanceof AppelContribution);
        $this->connecter($this->creerUtilisateur($bakel, 'autre@bakel.example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/bakel/appels/'.$appel->getId());
        self::assertResponseStatusCodeSame(404);
    }
}
