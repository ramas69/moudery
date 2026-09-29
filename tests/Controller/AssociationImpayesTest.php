<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Cotisation\Cotisations;
use App\Entity\Association;
use App\Entity\Cotisation;
use App\Entity\Evenement;
use App\Entity\Membre;
use App\Entity\ModeMontant;
use App\Entity\Relance;
use App\Entity\TypeContribution;
use App\Entity\TypeEvenement;
use App\Entity\UniteContribution;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Impayés et relances (F-24, F-25) : le tableau des retards avec ce que chacun doit, les filtres, la relance manuelle
 * (sélection ou tous), jamais deux fois le même jour, « Marquer comme appelé », les prochains envois automatiques et
 * les relances automatiques du calendrier.
 */
final class AssociationImpayesTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        // L'année dernière : toutes les mensualités sont en retard, quelle que soit la date du jour.
        $this->annee = (int) date('Y') - 1;
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', ['tresorier' => 'awa@example.org'], \App\Entity\EtapeAssistant::Activation);
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $em = $this->em();
        $association = $em->find(Association::class, $this->moudery);
        $lyon = $em->find(Ville::class, $this->lyon);
        \assert($association instanceof Association && $lyon instanceof Ville);
        $association->definirParametres(1, 30, [-7, 0, 15]);
        $association->definirPremierExercice($this->annee);
        $lyon->changerStatut(VilleStatut::Active);
        $type = new TypeContribution($association, 'cotisation', 'Cotisation', UniteContribution::Personne, ModeMontant::Fixe, 1000, null);
        $em->persist($type);
        // Mamadou est joignable ; Fanta n'a pas d'adresse.
        $em->persist(new Membre($lyon, 'Mamadou', 'Diaby', 'mamadou@example.org'));
        $em->persist(new Membre($lyon, 'Fanta', 'Traoré'));
        $em->flush();

        $this->cotisations()->ouvrir($lyon, $type, $this->annee, 1000, 5, null, new \DateTimeImmutable());
    }

    public function testLeTableauDesImpayesEtSesFiltres(): void
    {
        $crawler = $this->client->request('GET', '/associations/moudery/impayes?ville=association');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Impayés et relances');
        self::assertSelectorTextContains('h1 .compteur', '2');
        self::assertSelectorTextContains('.console__nav a[aria-current="page"]', 'Impayés');
        self::assertSelectorTextContains('.console__nav a[href$="/impayes"] .console__badge', '2', 'Le badge de la navigation compte les membres en retard.');
        self::assertSelectorTextContains('.console__sous-titre', 'J-7, J+0, J+15');

        // Quatre chiffres : montant dû, plus de 60 jours, sans adresse, prochaine relance automatique.
        self::assertSelectorTextContains('.kpi__valeur--erreur', '240', 'Les cartes sont en euros entiers.');
        self::assertSelectorTextContains('.kpis', '2 membres, 24 échéances');
        self::assertSelectorTextContains('.kpis', '2 membres · à appeler');
        self::assertSelectorTextContains('.kpis', 'Relance impossible par e-mail');
        self::assertSelectorTextContains('.kpis', 'Aucune', 'L’année dernière ne tombe plus sur le calendrier.');
        self::assertSelectorTextContains('.anciennete__legende', 'Plus de 60 jours · 2 membres');
        self::assertSelectorTextContains('.calendrier-relances__regles', '7 jours avant l’échéance');
        self::assertSelectorTextContains('.calendrier-relances__regles', 'Le jour de l’échéance');
        self::assertSelectorTextContains('.calendrier-relances__regles', '15 jours après l’échéance');

        // Une ligne par membre : ce qu'il doit, le montant, le retard, la dernière relance.
        $lignes = $crawler->filter('.tableau--impayes tbody tr');
        self::assertCount(2, $lignes);
        self::assertStringContainsString('Cotisation '.$this->annee.' · 12 mensualités', $lignes->eq(0)->text());
        self::assertStringContainsString('120,00', $lignes->eq(0)->text());
        self::assertStringContainsString('Jamais', $lignes->eq(0)->text());
        self::assertSelectorTextContains('.tableau--impayes tbody', 'Sans adresse e-mail');
        self::assertSelectorTextContains('.tableau--impayes tbody', 'à appeler');
        self::assertSelectorNotExists('.tableau--impayes tbody input[type="checkbox"][disabled]', 'Fanta, sans adresse, se coche quand même : on peut la marquer comme appelée.');
        self::assertSelectorExists('.tableau--impayes tbody input[type="checkbox"][data-injoignable="1"]');
        self::assertSelectorTextContains('.selecteur-echeance', 'Échéance');
        self::assertSelectorTextContains('form[action$="/impayes/relancer"] .bouton--primaire', 'Relancer 1 membre', 'Le bouton d’en-tête compte les membres joignables.');

        // Filtres : sans e-mail, recherche, origine.
        $crawler = $this->client->request('GET', '/associations/moudery/impayes?ville=association&filtre=sans_email');
        self::assertCount(1, $crawler->filter('.tableau--impayes tbody tr'));
        self::assertSelectorTextContains('.tableau--impayes tbody', 'Fanta Traoré');
        self::assertSelectorExists('.filtres .filtre[aria-current="true"]');
        $crawler = $this->client->request('GET', '/associations/moudery/impayes?ville=association&q=mamadou');
        self::assertCount(1, $crawler->filter('.tableau--impayes tbody tr'));
        self::assertSelectorTextContains('.tableau--impayes tbody', 'Mamadou Diaby');
        $cotisation = $this->em()->getRepository(Cotisation::class)->findOneBy([]);
        \assert($cotisation instanceof Cotisation);
        $crawler = $this->client->request('GET', '/associations/moudery/impayes?ville=association&echeance=cotisation:'.$cotisation->getId());
        self::assertCount(2, $crawler->filter('.tableau--impayes tbody tr'));
        self::assertSelectorTextContains('.selecteur-echeance', 'Cotisation '.$this->annee);
        $this->client->request('GET', '/associations/moudery/impayes?ville=association&q=personne');
        self::assertSelectorTextContains('.section__vide', 'Aucun membre ne correspond');

        $this->client->request('GET', '/associations/moudery/impayes/export.csv?ville=association');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"Mamadou Diaby";Lyon;"Cotisation '.$this->annee.' (12)";12;120,00;', (string) $this->client->getResponse()->getContent());
    }

    public function testLaRelanceManuelleEtLAppelTelephonique(): void
    {
        $crawler = $this->client->request('GET', '/associations/moudery/impayes?ville=association');
        $mamadou = $this->em()->getRepository(Membre::class)->findOneBy(['prenom' => 'Mamadou']);
        $fanta = $this->em()->getRepository(Membre::class)->findOneBy(['prenom' => 'Fanta']);
        \assert($mamadou instanceof Membre && $fanta instanceof Membre);

        // Relance de la sélection : Mamadou reçoit un e-mail, Fanta est ignorée.
        $jeton = $crawler->filter('#impayes-relancer-ligne input[name="_token"]')->attr('value');
        $this->client->request('POST', '/associations/moudery/impayes/relancer', ['_token' => $jeton, 'membres' => [$mamadou->getId(), $fanta->getId()]]);
        self::assertResponseRedirects('/associations/moudery/impayes', 303);
        self::assertQueuedEmailCount(1);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', '1 relance envoyée · 1 membre ignoré');
        self::assertSelectorTextContains('.tableau--impayes tbody tr:first-child', 'e-mail');

        $em = $this->em();
        $relance = $em->getRepository(Relance::class)->findOneBy([]);
        self::assertInstanceOf(Relance::class, $relance);
        self::assertFalse($relance->estAutomatique());
        self::assertFalse($relance->estUnAppel());
        self::assertSame(12000, $relance->getMontant());
        self::assertSame(12, $relance->getNombreEcheances());
        self::assertSame($this->moudery, $relance->getAssociation()->getId());
        self::assertInstanceOf(Evenement::class, $em->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::RelanceEnvoyee]));

        // Le même jour, une seconde relance manuelle est ignorée ; « tous » aussi.
        $this->client->request('POST', '/associations/moudery/impayes/relancer', ['_token' => $jeton, 'membres' => [$mamadou->getId()]]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'Aucune relance envoyée · 1 membre ignoré');
        $this->client->request('POST', '/associations/moudery/impayes/relancer', ['_token' => $jeton, 'tous' => '1']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'Aucune relance envoyée · 1 membre ignoré');
        self::assertCount(1, $this->em()->getRepository(Relance::class)->findAll());

        // Fanta, sans adresse, est appelée : l'appel est noté, rien n'est envoyé.
        $crawler = $this->client->request('GET', '/associations/moudery/impayes?ville=association');
        $jetonAppel = $crawler->filter('#impayes-appeler-ligne input[name="_token"]')->attr('value');
        $this->client->request('POST', '/associations/moudery/impayes/appeler', ['_token' => $jetonAppel, 'membres' => [$fanta->getId()]]);
        self::assertResponseRedirects('/associations/moudery/impayes', 303);
        self::assertQueuedEmailCount(0, null, 'Un appel n’envoie aucun e-mail.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', '1 membre marqué comme appelé');
        self::assertSelectorTextContains('.tableau--impayes tbody', 'appel téléphonique');
        $em = $this->em();
        $appel = $em->getRepository(Relance::class)->findOneBy(['canal' => Relance::CANAL_TELEPHONE]);
        self::assertInstanceOf(Relance::class, $appel);
        self::assertTrue($appel->estUnAppel());
        self::assertSame(12000, $appel->getMontant());
        self::assertSame($fanta->getId(), $appel->getMembre()->getId());
        self::assertInstanceOf(Evenement::class, $em->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::MembreAppele]));

        // Pas deux appels notés le même jour.
        $this->client->request('POST', '/associations/moudery/impayes/appeler', ['_token' => $jetonAppel, 'membres' => [$fanta->getId()]]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'Aucun membre marqué comme appelé · 1 ignoré');
        self::assertCount(2, $this->em()->getRepository(Relance::class)->findAll());
    }

    public function testRelancerTousLesMembresJoignables(): void
    {
        $crawler = $this->client->request('GET', '/associations/moudery/impayes?ville=association');
        $this->client->submit($crawler->filter('form[action$="/impayes/relancer"] .bouton--primaire')->form());
        self::assertResponseRedirects('/associations/moudery/impayes', 303);
        self::assertQueuedEmailCount(1, null, 'Mamadou seul est joignable.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', '1 relance envoyée.');
        self::assertSelectorTextContains('.tableau--impayes tbody tr:first-child', 'e-mail');
    }

    public function testLaProchaineRelanceAutomatiqueEstAnnoncee(): void
    {
        // Une cotisation de l'année prochaine : toutes ses dates de relance sont à venir.
        $em = $this->em();
        $lyon = $em->find(Ville::class, $this->lyon);
        $type = $em->getRepository(TypeContribution::class)->findOneBy([]);
        \assert($lyon instanceof Ville && $type instanceof TypeContribution);
        $this->cotisations()->ouvrir($lyon, $type, (int) date('Y') + 1, 1000, 5, null, new \DateTimeImmutable());

        $crawler = $this->client->request('GET', '/associations/moudery/impayes?ville=association');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kpis', '1 membre concerné', 'Mamadou est joignable, Fanta non.');
        self::assertGreaterThanOrEqual(1, $crawler->filter('.calendrier-relances__prochaines li')->count());
        self::assertSelectorTextContains('.calendrier-relances__prochaines', '1 membre · 1 sans e-mail');
    }

    public function testLesRelancesAutomatiquesSuiventLeCalendrier(): void
    {
        $commande = new CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('app:relances:envoyer'));

        // Le 12 mars = J+7 après le 5 mars : rien au calendrier (J-7, J, J+15).
        $commande->execute(['--date' => \sprintf('%d-03-12', $this->annee)]);
        self::assertStringContainsString('0 relance(s) envoyée(s)', (string) preg_replace("/\\s+/u", " ", $commande->getDisplay()));
        self::assertQueuedEmailCount(0);

        // Le 20 mars = J+15 après le 5 mars : Mamadou est relancé, Fanta (sans adresse) est ignorée.
        $commande->execute(['--date' => \sprintf('%d-03-20', $this->annee)]);
        self::assertStringContainsString('1 relance(s) envoyée(s), 1 membre(s) ignoré(s)', (string) preg_replace("/\\s+/u", " ", $commande->getDisplay()));
        self::assertQueuedEmailCount(1);
        $relance = $this->em()->getRepository(Relance::class)->findOneBy([]);
        self::assertInstanceOf(Relance::class, $relance);
        self::assertTrue($relance->estAutomatique());
        self::assertSame(\sprintf('%d-03-20', $this->annee), $relance->getJour()->format('Y-m-d'));

        // Rejouer le même jour n'envoie rien de plus ; la simulation ne crée rien.
        $commande->execute(['--date' => \sprintf('%d-03-20', $this->annee)]);
        self::assertStringContainsString('0 relance(s) envoyée(s)', (string) preg_replace("/\\s+/u", " ", $commande->getDisplay()));
        $commande->execute(['--date' => \sprintf('%d-04-05', $this->annee), '--simuler' => true]);
        self::assertStringContainsString('1 relance(s) à envoyer (simulation)', (string) preg_replace("/\\s+/u", " ", $commande->getDisplay()));
        self::assertCount(1, $this->em()->getRepository(Relance::class)->findAll());
    }

    public function testUnTresorierVoitLesImpayesDeSaVilleEtPeutAgir(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'awa@example.org', Role::Tresorier, $this->lyon));
        $this->client->request('GET', '/associations/moudery/impayes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__sous-titre', 'Lyon', 'Le trésorier voit les impayés de sa ville.');
        self::assertSelectorExists('form[action$="/impayes/relancer"] .bouton--primaire', 'Et peut relancer.');
        self::assertSelectorExists('.selection-barre form[action$="/impayes/appeler"]', 'Et marquer comme appelé.');
        self::assertSelectorNotExists('.calendrier-relances a[href$="/parametres"]', 'Le calendrier se modifie dans les paramètres de l’association, réservés au bureau central.');
    }

    private function cotisations(): Cotisations
    {
        $cotisations = static::getContainer()->get(Cotisations::class);
        \assert($cotisations instanceof Cotisations);

        return $cotisations;
    }
}
