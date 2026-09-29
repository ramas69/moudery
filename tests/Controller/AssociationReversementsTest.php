<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\Evenement;
use App\Entity\Membre;
use App\Entity\Reversement;
use App\Entity\ReversementStatut;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/**
 * Reversements des villes au bureau central (F-20) : le dû par ville (30 % de la collecte), les déclarations à
 * confirmer avec écart, l'enregistrement d'un virement reçu, l'export, et les droits.
 */
final class AssociationReversementsTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $evry;
    private int $central;
    private int $tresorier;
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->annee = (int) date('Y');
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', ['tresorier' => 'awa@example.org'], EtapeAssistant::Activation);
        $this->evry = $this->creerVille($this->moudery, 'Évry', [], EtapeAssistant::Activation);
        $this->creerVille($this->moudery, 'Rouen', [], EtapeAssistant::Membres);
        $this->central = $this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral);
        $this->tresorier = $this->creerUtilisateur($this->moudery, 'awa@example.org', Role::Tresorier, $this->lyon);

        $em = $this->em();
        $association = $em->find(Association::class, $this->moudery);
        $lyon = $em->find(Ville::class, $this->lyon);
        $evry = $em->find(Ville::class, $this->evry);
        $tresorier = $em->find(Utilisateur::class, $this->tresorier);
        \assert($association instanceof Association && $lyon instanceof Ville && $evry instanceof Ville && $tresorier instanceof Utilisateur);
        $association->definirParametres(1, 30, $association->getCalendrierRelances());
        $lyon->changerStatut(VilleStatut::Active);
        $evry->changerStatut(VilleStatut::Active);

        // Lyon a collecté 140 € cette année (12 mois + 2 mois à 10 €) : 30 % font 42 € dus au central.
        $mamadou = new Membre($lyon, 'Mamadou', 'Diaby');
        $mamadou->adherer($this->annee)->definirHistorique(array_fill(1, 12, 1000));
        $fanta = new Membre($lyon, 'Fanta', 'Traoré');
        $fanta->adherer($this->annee)->definirHistorique([1 => 1000, 2 => 1000]);
        $em->persist($mamadou);
        $em->persist($fanta);

        // La trésorière de Lyon a déclaré un virement de 20 € : il attend la confirmation du central.
        $em->persist(new Reversement($lyon, $this->annee, 2000, new \DateTimeImmutable('-3 days'), 'VIR LYON 03', null, $tresorier));
        $em->flush();
    }

    public function testLeBureauCentralVoitLeDuParVilleEtLesDeclarationsAConfirmer(): void
    {
        $this->connecter($this->central);
        $crawler = $this->client->request('GET', '/associations/moudery/reversements?ville=association');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Reversements');
        self::assertSelectorTextContains('.console__nav a[aria-current="page"]', 'Reversements');
        self::assertSelectorTextContains('.console__nav a[aria-current="page"] .console__badge', '1', 'Le compteur des déclarations à confirmer.');
        self::assertSelectorTextContains('.console__sous-titre', 'Part du bureau central : 30 %');

        $kpis = $crawler->filter('.kpi');
        self::assertCount(4, $kpis);
        self::assertStringContainsString('42,00', $kpis->eq(0)->text(), 'Dû : 30 % de 140 €.');
        self::assertStringContainsString('30 % de 140,00', $kpis->eq(0)->text());
        self::assertStringContainsString('0,00', $kpis->eq(1)->text(), 'Rien de reçu tant que rien n’est confirmé.');
        self::assertStringContainsString('42,00', $kpis->eq(2)->text());
        self::assertStringContainsString('dont 20,00', $kpis->eq(2)->text(), 'Le déclaré apparaît en attente.');
        self::assertStringContainsString('31 janv. '.($this->annee + 1), $kpis->eq(3)->text(), 'Date limite : fin du mois qui suit la clôture.');

        self::assertSelectorTextContains('.reversements__a-confirmer', 'Lyon · 20,00');
        self::assertSelectorTextContains('.reversements__a-confirmer', 'déclaré par Awa Cissé');
        self::assertSelectorTextContains('.reversements__a-confirmer', 'réf. VIR LYON 03');

        $lignes = $crawler->filter('.tableau--reversements tbody tr');
        self::assertCount(2, $lignes, 'Le brouillon Rouen n’a rien collecté : il n’apparaît pas.');
        self::assertStringContainsString('Lyon', $lignes->eq(0)->text());
        self::assertStringContainsString('À reverser', $lignes->eq(0)->text());
        self::assertStringContainsString('aucun reversement', $lignes->eq(0)->text(), 'Un reversement déclaré ne compte pas encore.');
        self::assertStringContainsString('Évry', $lignes->eq(1)->text());
        self::assertStringContainsString('Rien à reverser', $lignes->eq(1)->text());
        self::assertSelectorTextContains('.tableau--reversements tfoot', '42,00');
        self::assertSelectorTextContains('.reversements__graphique', 'Lyon');
        self::assertSelectorExists('.reversements__barre-segment--restant');
        self::assertSelectorTextContains('.tableau--historique-reversements', 'En attente');

        // Le périmètre d'une ville restreint la page à cette ville.
        $crawler = $this->client->request('GET', '/associations/moudery/reversements?ville='.$this->evry);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__sous-titre', 'Évry · part du bureau central : 30 %');
        self::assertCount(1, $crawler->filter('.tableau--reversements tbody tr'));
        self::assertSelectorNotExists('.reversements__a-confirmer', 'La déclaration de Lyon ne concerne pas Évry.');
        self::assertSelectorTextContains('.reversements__graphique', 'Rien n’est dû');
    }

    public function testLeCentralConfirmeUneDeclarationAvecLeMontantReellementRecu(): void
    {
        $this->connecter($this->central);
        $crawler = $this->client->request('GET', '/associations/moudery/reversements?ville=association');
        $formulaire = $crawler->filter('.reversements__a-confirmer form')->form();
        $formulaire['montant'] = '15,00';
        $this->client->submit($formulaire);

        self::assertResponseRedirects('/associations/moudery/reversements?exercice='.$this->annee, 303);
        $reversement = $this->dernier();
        self::assertSame(ReversementStatut::Confirme, $reversement->getStatut());
        self::assertSame(1500, $reversement->getMontant(), 'Le montant reçu remplace le montant déclaré.');
        self::assertSame(2000, $reversement->getMontantDeclare());
        self::assertSame(-500, $reversement->getEcart());
        self::assertSame($this->central, $reversement->getConfirmePar()?->getId());

        $evenement = $this->em()->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::ReversementConfirme]);
        self::assertInstanceOf(Evenement::class, $evenement);
        self::assertSame('Lyon', $evenement->getCible());
        self::assertSame(-500, $evenement->getDetails()['ecart']);

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Reversement de Lyon confirmé');
        self::assertSelectorNotExists('.reversements__a-confirmer');
        $kpis = $crawler->filter('.kpi');
        self::assertStringContainsString('15,00', $kpis->eq(1)->text());
        self::assertStringContainsString('27,00', $kpis->eq(2)->text(), 'Restant : 42 − 15.');
        self::assertSelectorTextContains('.tableau--reversements tbody tr:first-child', 'Partiel');
        self::assertSelectorTextContains('.tableau--historique-reversements', 'déclaré 20,00', 'L’écart reste visible dans l’historique.');
        self::assertSelectorTextContains('.tableau--historique-reversements', 'Vous');

        // Une seconde confirmation est refusée sans casser la page.
        $this->client->request('POST', \sprintf('/associations/moudery/reversements/%d/confirmer', $reversement->getId()), ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403, 'Sans jeton valable, rien ne passe.');
    }

    public function testLeCentralEnregistreUnVirementRecu(): void
    {
        $this->connecter($this->central);
        $crawler = $this->client->request('GET', '/associations/moudery/reversements/nouveau?ville=association');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Enregistrer un reversement');
        self::assertSelectorTextContains('.depense-saisie__cote', 'Lyon', 'Le restant dû par ville aide à saisir.');
        self::assertSelectorTextContains('.depense-saisie__cote', '42,00');

        // Sans montant : 422 et le formulaire revient.
        $formulaire = $crawler->filter('form[name="reversement"]')->form();
        $formulaire['reversement[ville]'] = (string) $this->lyon;
        $this->client->submit($formulaire);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.champ--erreur .champ__erreur', 'Indiquez le montant reçu.');

        $formulaire = $this->client->getCrawler()->filter('form[name="reversement"]')->form();
        $formulaire['reversement[ville]'] = (string) $this->lyon;
        $formulaire['reversement[exercice]'] = (string) $this->annee;
        $formulaire['reversement[montant]'] = '42,00';
        $formulaire['reversement[recuLe]'] = date('Y-m-d');
        $formulaire['reversement[reference]'] = 'VIR LYON 04';
        $this->client->submit($formulaire);

        self::assertResponseRedirects('/associations/moudery/reversements?exercice='.$this->annee, 303);
        $reversement = $this->dernier();
        self::assertSame(ReversementStatut::Confirme, $reversement->getStatut(), 'Le central constate l’argent : confirmé d’emblée.');
        self::assertSame(4200, $reversement->getMontant());
        self::assertSame('VIR LYON 04', $reversement->getReference());
        self::assertSame($this->central, $reversement->getDeclarePar()?->getId());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Reversement de Lyon enregistré');
        self::assertSelectorTextContains('.tableau--reversements tbody tr:first-child', 'Lyon', 'Lyon soldée reste avant Évry : « rien à reverser » vient en dernier.');
        self::assertSelectorTextContains('.tableau--reversements tbody tr:first-child', 'Soldé');
        self::assertSelectorTextContains('.tableau--historique-reversements', 'VIR LYON 04');
    }

    public function testDeuxVillesDeMemeStatutSeTrientParNomSansAccent(): void
    {
        // Évry collecte aussi : Lyon et Évry ont toutes deux un reversement à faire, l'ordre est alors alphabétique
        // sans accent (Évry avant Lyon). Ce tri passe par Texte::normaliser : un import manquant l'a fait planter.
        $em = $this->em();
        $evry = $em->find(Ville::class, $this->evry);
        \assert($evry instanceof Ville);
        $samba = new Membre($evry, 'Samba', 'Coulibaly');
        $samba->adherer($this->annee)->definirHistorique(array_fill(1, 12, 1000));
        $em->persist($samba);
        $em->flush();

        $this->connecter($this->central);
        $crawler = $this->client->request('GET', '/associations/moudery/reversements?ville=association');
        self::assertResponseIsSuccessful();
        $lignes = $crawler->filter('.tableau--reversements tbody tr');
        self::assertCount(2, $lignes);
        self::assertStringContainsString('Évry', $lignes->eq(0)->text());
        self::assertStringContainsString('Lyon', $lignes->eq(1)->text());
    }

    public function testLeStatutSoldeEtLExport(): void
    {
        $em = $this->em();
        $lyon = $em->find(Ville::class, $this->lyon);
        \assert($lyon instanceof Ville);
        $recu = new Reversement($lyon, $this->annee, 4200, new \DateTimeImmutable('-1 day'), 'VIR LYON 05');
        $recu->confirmer(null, new \DateTimeImmutable());
        $em->persist($recu);
        $em->flush();

        $this->connecter($this->central);
        $this->client->request('GET', '/associations/moudery/reversements?ville=association');
        self::assertSelectorTextContains('.tableau--reversements tbody', 'Soldé');
        self::assertSelectorTextContains('.reversements__compteurs', '1 soldé');

        $this->client->request('GET', '/associations/moudery/reversements/export.csv');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ville;"Collecté '.$this->annee.' (€)";"Taux (%)";', $csv);
        self::assertStringContainsString('Lyon;140,00;30;0,00;42,00;42,00;20,00;0,00;Soldé;', $csv);
        self::assertStringContainsString('Évry;0,00;30;0,00;0,00;0,00;0,00;0,00;"Rien à reverser";', $csv);
        self::assertStringContainsString('Total;140,00;;;42,00;42,00;20,00;0,00', $csv);
    }

    public function testSeulLeBureauCentralDeLAssociationAccede(): void
    {
        // Le trésorier voit le dû de sa ville et peut déclarer un virement, jamais confirmer ni enregistrer pour le central.
        $this->connecter($this->tresorier);
        $this->client->request('GET', '/associations/moudery/reversements');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href*="/reversements/declarer"]');
        self::assertSelectorNotExists('a[href*="/reversements/nouveau"]');
        self::assertSelectorNotExists('.reversements__confirmation');
        $this->client->request('GET', '/associations/moudery/reversements/nouveau');
        self::assertResponseStatusCodeSame(403);

        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'bakel@example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery/reversements');
        self::assertResponseStatusCodeSame(403, 'Un bureau central ne voit jamais une autre association.');
        $this->client->request('POST', \sprintf('/associations/moudery/reversements/%d/confirmer', $this->dernier()->getId()), ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    private function dernier(): Reversement
    {
        $this->em()->clear();
        $reversement = $this->em()->getRepository(Reversement::class)->findOneBy([], ['id' => 'DESC']);
        \assert($reversement instanceof Reversement);

        return $reversement;
    }
}
