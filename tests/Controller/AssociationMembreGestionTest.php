<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Cotisation\Cotisations;
use App\Entity\Association;
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
use App\Security\Role;

/** Fiche d'un membre : modification, sortie et réactivation, transfert vers une autre ville avec l'historique conservé (F-10). */
final class AssociationMembreGestionTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $evry;
    private int $mamadou;
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->annee = (int) date('Y');
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', ['tresorier' => 'awa@example.org'], EtapeAssistant::Activation);
        $this->evry = $this->creerVille($this->moudery, 'Évry', [], EtapeAssistant::Activation);
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $em = $this->em();
        $association = $em->find(Association::class, $this->moudery);
        $lyon = $em->find(Ville::class, $this->lyon);
        $evry = $em->find(Ville::class, $this->evry);
        \assert($association instanceof Association && $lyon instanceof Ville && $evry instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        $evry->changerStatut(VilleStatut::Active);
        $type = new TypeContribution($association, 'cotisation', 'Cotisation', UniteContribution::Personne, ModeMontant::Fixe, 1000, null);
        $em->persist($type);
        $mamadou = new Membre($lyon, 'Mamadou', 'Diaby', 'mamadou@example.org');
        $mamadou->adherer($this->annee - 1)->definirHistorique(array_fill(1, 12, 1000));
        $em->persist($mamadou);
        $em->persist(new Membre($evry, 'Oumar', 'Sy', 'oumar@example.org'));
        $em->flush();
        $this->mamadou = (int) $mamadou->getId();

        // Lyon et Évry ont ouvert leurs cotisations de l'année.
        $cotisations = static::getContainer()->get(Cotisations::class);
        \assert($cotisations instanceof Cotisations);
        $cotisations->ouvrir($lyon, $type, $this->annee, 1000, 5, null, new \DateTimeImmutable());
        $cotisations->ouvrir($evry, $type, $this->annee, 1500, 10, null, new \DateTimeImmutable());
    }

    public function testModifierLaFicheEtChangerLeStatut(): void
    {
        $crawler = $this->client->request('GET', '/associations/moudery/membres/'.$this->mamadou);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section[aria-labelledby="compte-titre"]', '12 échéances dues');
        self::assertSelectorTextContains('section[aria-labelledby="compte-titre"]', '120,00');
        self::assertSelectorExists('section[aria-labelledby="transfert-titre"] select[name="ville"] option');

        $crawler = $this->client->click($crawler->filter('a.bouton--primaire')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Modifier la fiche');
        $formulaire = $crawler->filter('form[name="membre"]')->form();
        $formulaire['membre[prenom]'] = 'Mamadou';
        $formulaire['membre[nom]'] = 'Diaby';
        $formulaire['membre[localite]'] = 'Vénissieux';
        $formulaire['membre[telephone]'] = '06 12 34 56 78';
        $formulaire['membre[nouveauFoyer]'] = 'Famille Diaby';
        $this->client->submit($formulaire);
        self::assertResponseRedirects('/associations/moudery/membres/'.$this->mamadou, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'La fiche de Mamadou Diaby est enregistrée.');
        self::assertSelectorTextContains('.proprietes', 'Vénissieux');
        self::assertSelectorTextContains('.proprietes', 'Famille Diaby');

        $em = $this->em();
        $membre = $em->find(Membre::class, $this->mamadou);
        self::assertSame('Diaby', $membre?->getNom());
        self::assertSame('0612345678', $membre?->getTelephone());
        $evenement = $em->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::MembreModifie]);
        self::assertInstanceOf(Evenement::class, $evenement);
        self::assertNull($evenement->getDetails()['avant']['localite']);
        self::assertSame('Vénissieux', $evenement->getDetails()['apres']['localite']);

        // Sortie : plus de foyer, statut sorti, puis réactivation.
        $crawler = $this->client->request('GET', '/associations/moudery/membres/'.$this->mamadou);
        $this->client->submit($crawler->filter('form[action$="/statut/sortir"]')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1 .pastille', 'Sorti');
        $membre = $this->em()->find(Membre::class, $this->mamadou);
        self::assertSame(MembreStatut::Sorti, $membre?->getStatut());
        self::assertNull($membre?->getFoyer());

        $crawler = $this->client->request('GET', '/associations/moudery/membres/'.$this->mamadou);
        $this->client->submit($crawler->filter('form[action$="/statut/reactiver"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1 .pastille', 'Actif');
        self::assertCount(2, $this->em()->getRepository(Evenement::class)->findBy(['type' => TypeEvenement::MembreStatut]));
    }

    public function testTransfererUnMembreGardeSonHistoriqueDansLaVilleDOrigine(): void
    {
        $crawler = $this->client->request('GET', '/associations/moudery/membres/'.$this->mamadou);
        $formulaire = $crawler->filter('form[action$="/transferer"]')->form();
        $formulaire['ville'] = (string) $this->evry;
        $this->client->submit($formulaire);
        self::assertResponseRedirects('/associations/moudery/membres/'.$this->mamadou, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Mamadou Diaby est transféré de Lyon vers Évry.');
        self::assertSelectorTextContains('.console__sous-titre', 'Évry');

        $em = $this->em();
        $membre = $em->find(Membre::class, $this->mamadou);
        \assert($membre instanceof Membre);
        self::assertSame($this->evry, $membre->getVille()->getId());
        $adhesion = $membre->adhesionPour($this->annee - 1);
        self::assertSame($this->lyon, $adhesion?->getVille()->getId(), 'L’historique reste rattaché à Lyon.');
        self::assertSame(12000, $adhesion?->getTotal());

        $echeances = $em->getRepository(Echeance::class)->findBy(['membre' => $membre]);
        $lyonAnnulees = array_filter($echeances, fn (Echeance $e): bool => $e->getCotisation()?->getVille()->getId() === $this->lyon);
        $evryDues = array_filter($echeances, fn (Echeance $e): bool => $e->getCotisation()?->getVille()->getId() === $this->evry && $e->estDue());
        self::assertCount(12, $lyonAnnulees);
        foreach ($lyonAnnulees as $e) {
            self::assertSame('annulee', $e->getStatut()->value, 'Les mensualités de Lyon encore dues sont annulées.');
        }
        self::assertCount(12, $evryDues, 'Évry, cotisations ouvertes, lui crée ses mensualités.');
        self::assertSame(1500, array_values($evryDues)[0]->getMontant());
        self::assertInstanceOf(Evenement::class, $em->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::MembreTransfere]));

        // Une adresse déjà prise dans la ville d'arrivée bloque le retour vers Lyon : Mamadou reste à Évry.
        $lyon = $em->find(Ville::class, $this->lyon);
        \assert($lyon instanceof Ville);
        $em->persist(new Membre($lyon, 'Moussa', 'Diaby', 'mamadou@example.org'));
        $em->flush();
        $crawler = $this->client->request('GET', '/associations/moudery/membres/'.$this->mamadou);
        $formulaire = $crawler->filter('form[action$="/transferer"]')->form();
        $formulaire['ville'] = (string) $this->lyon;
        $this->client->submit($formulaire);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'déjà celle d’un membre de Lyon');
        self::assertSame($this->evry, $this->em()->find(Membre::class, $this->mamadou)?->getVille()->getId(), 'Toujours à Évry.');
    }

    public function testUnTresorierModifieSaVilleMaisNeTransferePas(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'awa@example.org', Role::Tresorier, $this->lyon));
        $this->client->request('GET', '/associations/moudery/membres/'.$this->mamadou.'/modifier');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('section[aria-labelledby="transfert-titre"]');
        $this->client->request('POST', '/associations/moudery/membres/'.$this->mamadou.'/transferer', ['_token' => 'x', 'ville' => $this->evry]);
        self::assertResponseStatusCodeSame(403);
    }
}
