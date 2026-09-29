<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Cotisation\Cotisations;
use App\Entity\Association;
use App\Entity\Depense;
use App\Entity\Echeance;
use App\Entity\EtapeAssistant;
use App\Entity\Evenement;
use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\ModeMontant;
use App\Entity\TypeContribution;
use App\Entity\TypeEvenement;
use App\Entity\UniteContribution;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Paiement\Paiements;
use App\Security\Role;
use Symfony\Component\DomCrawler\Crawler;

/**
 * L'accueil d'un responsable de ville (artboard « Accueil trésorière mobile ») : chiffres de la ville, « À faire »
 * selon les droits, derniers paiements ; et la fiche d'une dépense de ville (« Décision sur une dépense », « Dépense
 * saisie par soi-même ») avec « Prévenir le bureau central ».
 */
final class EspaceVilleAccueilTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $tresorier;
    private int $president;
    private int $mamadou;
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->annee = (int) date('Y');
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', [], EtapeAssistant::Activation);
        $this->tresorier = $this->creerUtilisateur($this->moudery, 'tresorier@lyon.fr', Role::Tresorier, $this->lyon);
        $this->president = $this->creerUtilisateur($this->moudery, 'president@lyon.fr', Role::President, $this->lyon);
        $this->creerUtilisateur($this->moudery, 'central@moudery.fr', Role::BureauCentral);

        $em = $this->em();
        $association = $em->find(Association::class, $this->moudery);
        $lyon = $em->find(Ville::class, $this->lyon);
        \assert($association instanceof Association && $lyon instanceof Ville);
        $association->definirParametres(1, 30, $association->getCalendrierRelances());
        $association->definirPremierExercice($this->annee - 1);
        $lyon->changerStatut(VilleStatut::Active);
        $type = new TypeContribution($association, 'cotisation', 'Cotisation', UniteContribution::Personne, ModeMontant::Fixe, 1000, null);
        $em->persist($type);
        $mamadou = new Membre($lyon, 'Mamadou', 'Diaby', 'mamadou@example.org');
        $em->persist($mamadou);
        $em->persist(new Membre($lyon, 'Awa', 'Cissé', null, null, MembreStatut::EnAttente));
        $em->flush();
        $this->mamadou = (int) $mamadou->getId();

        // Les cotisations de l'année dernière sont ouvertes : douze mensualités en retard pour Mamadou.
        $cotisations = static::getContainer()->get(Cotisations::class);
        \assert($cotisations instanceof Cotisations);
        $cotisations->ouvrir($lyon, $type, $this->annee - 1, 1000, 5, null, new \DateTimeImmutable());
        // Un paiement de deux mensualités : 20 € collectés, 6 € à reverser.
        $echeances = $em->getRepository(Echeance::class)->findBy(['membre' => $mamadou], ['mois' => 'ASC']);
        $paiements = static::getContainer()->get(Paiements::class);
        \assert($paiements instanceof Paiements);
        $paiements->enregistrer($mamadou, [$echeances[0], $echeances[1]], \App\Entity\MoyenPaiement::Especes, new \DateTimeImmutable(), null, null, null, new \DateTimeImmutable());
    }

    public function testLAccueilDeLaTresoriereListeCeQuElleADeFaire(): void
    {
        $this->connecter($this->tresorier);
        $crawler = $this->client->request('GET', '/associations/moudery?exercice='.($this->annee - 1));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Bonjour Awa');
        self::assertSelectorTextContains('.console__sous-titre', 'Lyon · 2 membres');
        $kpis = $crawler->filter('.accueil-ville__kpis .kpi');
        self::assertCount(4, $kpis);
        self::assertStringContainsString('20', $kpis->eq(0)->text(), 'Collecté : 20 €.');
        self::assertStringContainsString('1 membre', $kpis->eq(2)->text(), 'Impayés : un membre en retard.');
        self::assertStringContainsString('100', $kpis->eq(2)->text(), 'Dix mensualités en retard : 100 €.');

        $taches = $crawler->filter('.accueil-ville__tache');
        $textes = $taches->each(static fn (Crawler $t): string => preg_replace('/\s+/u', ' ', $t->text()) ?? '');
        self::assertStringContainsString('1 demande d’inscription', $textes[0]);
        self::assertStringContainsString('Valider', $textes[0]);
        self::assertStringContainsString('1 membre en retard', $textes[1]);
        self::assertStringContainsString('Relancer', $textes[1]);
        self::assertStringContainsString('Reversement au bureau central', $textes[2]);
        self::assertStringContainsString('6,00', $textes[2]);
        self::assertStringContainsString('Déclarer', $textes[2]);
        self::assertStringContainsString('Cotisations de l’année à ouvrir', $textes[3]);
        self::assertSelectorTextContains('section[aria-labelledby="derniers-paiements-titre"]', 'Mamadou Diaby');
        self::assertSelectorTextContains('section[aria-labelledby="derniers-paiements-titre"]', '20,00');
        self::assertSelectorExists('.console__onglets a[aria-current="page"]', 'La barre d’onglets mobile marque l’accueil.');

        // Le président voit les mêmes chiffres mais n'agit que sur les dépenses : « Voir » ailleurs.
        $this->connecter($this->president);
        $crawler = $this->client->request('GET', '/associations/moudery?exercice='.($this->annee - 1));
        self::assertSelectorTextContains('h1', 'Bonjour Awa');
        $textes = $crawler->filter('.accueil-ville__tache')->each(static fn (Crawler $t): string => preg_replace('/\s+/u', ' ', $t->text()) ?? '');
        self::assertStringContainsString('Voir', $textes[0]);
        self::assertStringNotContainsString('Cotisations de l’année à ouvrir', implode(' ', $textes));
    }

    public function testLaFicheDUneDepenseDeVilleGuideLePresidentEtVerrouilleLAuteur(): void
    {
        $this->connecter($this->tresorier);
        $crawler = $this->client->request('GET', '/associations/moudery/depenses/nouvelle');
        $formulaire = $crawler->filter('button[value="soumettre"]')->form();
        $formulaire['depense[libelle]'] = 'Location de la salle';
        $formulaire['depense[montant]'] = '350,00';
        $formulaire['depense[date]'] = date('Y-m-d');
        $formulaire['depense[categorie]'] = 'fetes';
        $formulaire['depense[beneficiaire]'] = 'Salle des fêtes';
        $formulaire['depense[justificatif]']->upload($this->pdf());
        $this->client->submit($formulaire);
        $depense = $this->em()->getRepository(Depense::class)->findOneBy([]);
        \assert($depense instanceof Depense);
        $id = (int) $depense->getId();

        // L'auteur : verrou, pas de « Prévenir » puisqu'un président existe, et l'étape « Décision du président ».
        $this->client->request('GET', '/associations/moudery/depenses/'.$id);
        self::assertSelectorTextContains('.depense-verrou', 'vous l’avez saisie');
        self::assertSelectorNotExists('form[action$="/prevenir"]');
        self::assertSelectorTextContains('.depense-suivi', 'Décision du président');
        self::assertSelectorTextContains('.depense-verrou__note', 'n’apparaît jamais sur sa propre dépense');

        // Le président : « En attente de votre décision », le feu vert, Valider et Refuser.
        $this->connecter($this->president);
        $this->client->request('GET', '/associations/moudery/depenses/'.$id);
        self::assertSelectorTextContains('.depense-fiche__carte .pastille', 'En attente de votre décision');
        self::assertSelectorTextContains('.depense-feu-vert', 'Vous pouvez valider cette dépense.');
        self::assertSelectorTextContains('.depense-feu-vert', 'la trésorière prévenue');
        self::assertSelectorExists('form[action$="/valider"]');
        self::assertSelectorTextContains('.depense-decision__note', 'Un refus demande un motif');

        // Sans président, l'auteur peut prévenir le bureau central.
        $em = $this->em();
        $president = $em->find(\App\Entity\Utilisateur::class, $this->president);
        \assert($president instanceof \App\Entity\Utilisateur);
        $president->desactiver();
        $em->flush();
        $this->connecter($this->tresorier);
        $crawler = $this->client->request('GET', '/associations/moudery/depenses/'.$id);
        self::assertSelectorTextContains('.depense-verrou', 'Aucun président n’a de compte pour Lyon');
        $this->client->submit($crawler->filter('form[action$="/prevenir"]')->form());
        self::assertResponseRedirects('/associations/moudery/depenses/'.$id, 303);
        self::assertQueuedEmailCount(1, null, 'Le bureau central reçoit un e-mail.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Le bureau central est prévenu');
        self::assertInstanceOf(Evenement::class, $this->em()->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::DepenseSignalee]));

        // Le président désactivé ne peut plus décider ; le bureau central ne décide jamais une dépense de ville.
        $this->connecter($this->creerUtilisateur($this->moudery, 'autre-central@moudery.fr', Role::BureauCentral));
        $this->client->request('POST', \sprintf('/associations/moudery/depenses/%d/valider', $id), ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    private function pdf(): string
    {
        $chemin = sys_get_temp_dir().'/justificatif-'.bin2hex(random_bytes(4)).'.pdf';
        file_put_contents($chemin, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return $chemin;
    }
}
