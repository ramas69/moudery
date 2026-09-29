<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Cotisation\Cotisations;
use App\Entity\Association;
use App\Entity\Echeance;
use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\ModeMontant;
use App\Entity\MoyenPaiement;
use App\Entity\TypeContribution;
use App\Entity\UniteContribution;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Paiement\Paiements;
use App\Security\Role;

/** La page Membres d'une ville (artboard « 05 Membres · trésorière ») : situation de paiement, inscriptions à valider, sélection. */
final class EspaceVilleMembresTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $tresorier;
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->annee = (int) date('Y');
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', [], EtapeAssistant::Activation);
        $this->tresorier = $this->creerUtilisateur($this->moudery, 'tresorier@lyon.fr', Role::Tresorier, $this->lyon);

        $em = $this->em();
        $association = $em->find(Association::class, $this->moudery);
        $lyon = $em->find(Ville::class, $this->lyon);
        \assert($association instanceof Association && $lyon instanceof Ville);
        $association->definirPremierExercice($this->annee - 1);
        $lyon->changerStatut(VilleStatut::Active);
        $type = new TypeContribution($association, 'cotisation', 'Cotisation', UniteContribution::Personne, ModeMontant::Fixe, 1000, null);
        $em->persist($type);
        $mamadou = new Membre($lyon, 'Mamadou', 'Diaby', 'mamadou@example.org', '0612345678');
        $fanta = new Membre($lyon, 'Fanta', 'Traoré');
        $em->persist($mamadou);
        $em->persist($fanta);
        $em->persist(new Membre($lyon, 'Awa', 'Cissé', null, null, MembreStatut::EnAttente));
        $em->persist(new Membre($lyon, 'Samba', 'Coulibaly', null, null, MembreStatut::EnAttente));
        $em->flush();

        // Cotisations de l'année dernière : Mamadou a tout payé (à jour), Fanta rien (en retard).
        $cotisations = static::getContainer()->get(Cotisations::class);
        \assert($cotisations instanceof Cotisations);
        $cotisations->ouvrir($lyon, $type, $this->annee - 1, 1000, 5, null, new \DateTimeImmutable());
        $paiements = static::getContainer()->get(Paiements::class);
        \assert($paiements instanceof Paiements);
        $paiements->enregistrer($mamadou, $em->getRepository(Echeance::class)->findBy(['membre' => $mamadou]), MoyenPaiement::Especes, new \DateTimeImmutable(), null, null, null, new \DateTimeImmutable());
    }

    public function testLaTresoriereVoitLaSituationDeChaqueMembre(): void
    {
        $this->connecter($this->tresorier);
        $crawler = $this->client->request('GET', '/associations/moudery/membres');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1 .compteur', '4');
        self::assertSelectorTextContains('.console__nav a[href$="/membres"] .console__badge', '2', 'Deux inscriptions à valider dans la barre latérale.');
        self::assertSelectorTextContains('.bandeau--attente', '2 demandes d’inscription attendent une validation');
        self::assertSelectorTextContains('.bandeau--attente', 'Awa Cissé, Samba Coulibaly');
        self::assertSelectorTextContains('.filtres', 'À jour · 1');
        self::assertSelectorTextContains('.filtres', 'En retard · 1');
        self::assertSelectorTextContains('.filtres', 'En attente · 2');
        self::assertSelectorExists('a.bouton[href*="/assistant/membres"]', 'Importer et Ajouter mènent à la gestion de la ville.');

        $lignes = $crawler->filter('.tableau--membres-ville tbody tr');
        self::assertCount(4, $lignes);
        $fanta = $lignes->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Fanta'))->first();
        self::assertStringContainsString('120,00', $fanta->text(), 'Reste dû : douze mensualités.');
        self::assertStringContainsString('En retard', $fanta->text());
        self::assertStringContainsString('Sans adresse e-mail', $fanta->text());
        $mamadou = $lignes->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Mamadou'))->first();
        self::assertStringContainsString('À jour', $mamadou->text());
        self::assertStringContainsString('06 12 34 56 78', $mamadou->text());
        self::assertStringContainsString(date('d'), $mamadou->text(), 'Dernier paiement : aujourd’hui.');
        self::assertSelectorExists('.selection-barre form[action$="/impayes/relancer"]');
        self::assertSelectorExists('.selection-barre a[href*="/paiements/nouveau"]');

        // Le filtre « en retard » ne garde que Fanta.
        $crawler = $this->client->request('GET', '/associations/moudery/membres?filtre=en_retard');
        self::assertCount(1, $crawler->filter('.tableau--membres-ville tbody tr'));
        self::assertSelectorTextContains('.tableau--membres-ville tbody', 'Fanta');
    }

    public function testToutValiderPasseLesInscriptionsActives(): void
    {
        $this->connecter($this->tresorier);
        $crawler = $this->client->request('GET', '/associations/moudery/membres');
        $this->client->submit($crawler->filter('.bandeau--attente form')->form());
        self::assertResponseRedirects('/associations/moudery/membres', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', '2 inscriptions validées.');
        self::assertSelectorNotExists('.bandeau--attente');
        self::assertSelectorTextContains('.filtres', 'En attente · 0');
        self::assertCount(0, $this->em()->getRepository(Membre::class)->findBy(['statut' => MembreStatut::EnAttente]));

        // Un président (sans MEMBRE_GERER) ne valide pas.
        $this->connecter($this->creerUtilisateur($this->moudery, 'president@lyon.fr', Role::President, $this->lyon));
        $this->client->request('POST', '/associations/moudery/membres/valider', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testLaSelectionInviteALeurEspaceLesMembresAvecAdresseEtSansCompte(): void
    {
        $this->connecter($this->tresorier);
        $crawler = $this->client->request('GET', '/associations/moudery/membres');
        self::assertResponseIsSuccessful();
        $formulaire = $crawler->filter('.selection-barre form[action$="/membres/inviter"]');
        self::assertCount(1, $formulaire);
        self::assertSame('/associations/moudery/membres', $formulaire->filter('input[name="retour"]')->attr('value'), 'Retour sur la page après l’envoi.');
        $mamadou = $this->em()->getRepository(Membre::class)->findOneBy(['prenom' => 'Mamadou']);
        $fanta = $this->em()->getRepository(Membre::class)->findOneBy(['prenom' => 'Fanta']);
        \assert($mamadou instanceof Membre && $fanta instanceof Membre);
        self::assertSame('1', $crawler->filter(\sprintf('input[name="membres[]"][value="%d"]', $mamadou->getId()))->attr('data-invitable'), 'Mamadou a une adresse et pas de compte.');
        self::assertSame('0', $crawler->filter(\sprintf('input[name="membres[]"][value="%d"]', $fanta->getId()))->attr('data-invitable'), 'Fanta n’a pas d’adresse.');

        $jeton = $formulaire->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/associations/moudery/membres/inviter', ['_token' => $jeton, 'retour' => '/associations/moudery/membres', 'membres' => [$mamadou->getId(), $fanta->getId()]]);
        self::assertResponseRedirects('/associations/moudery/membres');
        self::assertQueuedEmailCount(1, null, 'Mamadou seul est invité.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte', 'invitation');
    }

    public function testLaSelectionPrecocheLesMembresSurLaSaisieGroupee(): void
    {
        $this->connecter($this->tresorier);
        $fanta = $this->em()->getRepository(Membre::class)->findOneBy(['prenom' => 'Fanta']);
        \assert($fanta instanceof Membre);
        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/paiements/nouveau?membres[]=%d', $fanta->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('input[name="membres[]"][value="%d"][checked]', $fanta->getId()));
        self::assertCount(1, $crawler->filter('input[name="membres[]"][checked]'));
    }
}
