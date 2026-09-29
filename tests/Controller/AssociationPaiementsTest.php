<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\Echeance;
use App\Entity\EtapeAssistant;
use App\Entity\Evenement;
use App\Entity\Membre;
use App\Entity\ModeMontant;
use App\Entity\Paiement;
use App\Entity\PaiementStatut;
use App\Entity\TypeContribution;
use App\Entity\TypeEvenement;
use App\Entity\UniteContribution;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Paiements manuels (F-17, F-18) : recherche du membre, échéances cochées, reçu par e-mail, classeur synchronisé, annulation, export. */
final class AssociationPaiementsTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $mamadou;
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->annee = (int) date('Y');
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', ['tresorier' => 'awa@example.org'], EtapeAssistant::Activation);
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $em = $this->em();
        $association = $em->find(Association::class, $this->moudery);
        $lyon = $em->find(Ville::class, $this->lyon);
        \assert($association instanceof Association && $lyon instanceof Ville);
        $association->definirParametres(1, 30, $association->getCalendrierRelances());
        $lyon->changerStatut(VilleStatut::Active);
        $mamadou = new Membre($lyon, 'Mamadou', 'Diaby', 'mamadou@example.org');
        $em->persist($mamadou);
        $em->persist(new TypeContribution($association, 'cotisation', 'Cotisation', UniteContribution::Personne, ModeMontant::Fixe, 1000, null));
        $em->flush();
        $this->mamadou = (int) $mamadou->getId();

        // Les cotisations de l'année sont ouvertes à 10 € le 5 : douze mensualités pour Mamadou.
        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/cotisations/ouvrir?ville=%d&annee=%d', $this->lyon, $this->annee));
        $formulaire = $crawler->filter('form[name="cotisation"]')->form();
        $formulaire['cotisation[montantMensuel]'] = '10,00';
        $this->client->submit($formulaire);
        self::assertResponseRedirects();
    }

    public function testEnregistrerUnPaiementSoldeLesEcheancesEtEnvoieUnRecu(): void
    {
        $crawler = $this->client->request('GET', '/associations/moudery/paiements/nouveau?q=diaby');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__nav a[aria-current="page"]', 'Paiements');
        self::assertSelectorTextContains('.tableau--paiement-groupe tbody', 'Mamadou Diaby');
        $crawler = $this->client->click($crawler->filter('.tableau--paiement-groupe tbody a.bouton')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Enregistrer un paiement');
        self::assertCount(12, $crawler->filter('.paiement-echeances__ligne'));

        // Janvier et février réglés en espèces.
        $echeances = $this->em()->getRepository(Echeance::class)->findBy(['membre' => $this->mamadou], ['mois' => 'ASC']);
        $formulaire = $crawler->filter('form[name="paiement"]')->form();
        foreach ($formulaire['paiement[echeances]'] as $case) {
            \in_array($case->availableOptionValues()[0], [(string) $echeances[0]->getId(), (string) $echeances[1]->getId()], true) ? $case->tick() : $case->untick();
        }
        $formulaire['paiement[moyen]'] = 'especes';
        $formulaire['paiement[recuLe]'] = date('Y-m-d');
        $formulaire['paiement[reference]'] = 'Reçu à la réunion';
        $this->client->submit($formulaire);

        self::assertResponseRedirects('/associations/moudery/paiements', 303);
        self::assertQueuedEmailCount(1, null, 'Le reçu part par e-mail (F-18).');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Paiement de 20,00');
        self::assertSelectorTextContains('.alerte--succes', 'le reçu part par e-mail');
        self::assertSelectorTextContains('.tableau--paiements tbody', 'Mamadou Diaby');
        self::assertSelectorTextContains('.tableau--paiements tbody', '2 mensualités '.$this->annee);
        self::assertSelectorTextContains('.kpis', '20,00');

        $em = $this->em();
        $paiement = $em->getRepository(Paiement::class)->findOneBy([]);
        self::assertInstanceOf(Paiement::class, $paiement);
        self::assertSame(2000, $paiement->getMontant());
        self::assertSame('Reçu à la réunion', $paiement->getReference());
        self::assertCount(2, $paiement->getEcheances());
        $janvier = $em->getRepository(Echeance::class)->findOneBy(['membre' => $this->mamadou, 'mois' => 1]);
        self::assertInstanceOf(Echeance::class, $janvier);
        self::assertTrue($janvier->estPayee());
        self::assertSame($paiement->getId(), $janvier->getPaiement()?->getId());

        // La ligne du classeur suit : janvier et février à 10 €, le reste vide.
        $mamadou = $em->find(Membre::class, $this->mamadou);
        \assert($mamadou instanceof Membre);
        $adhesion = $mamadou->adhesionPour($this->annee);
        self::assertSame(1000, $adhesion?->getMontantMois(1));
        self::assertSame(1000, $adhesion?->getMontantMois(2));
        self::assertNull($adhesion?->getMontantMois(3));
        self::assertSame(2000, $adhesion?->getTotal());

        $evenement = $em->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::PaiementEnregistre]);
        self::assertInstanceOf(Evenement::class, $evenement);
        self::assertSame(2000, $evenement->getDetails()['montant']);
        self::assertSame('especes', $evenement->getDetails()['moyen']);

        // Le reçu s'imprime ; les reversements dus au central montent (30 % de 20 €).
        $this->client->request('GET', \sprintf('/associations/moudery/paiements/%d/recu', $paiement->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.recu__titre', 'Reçu n° '.$paiement->getId());
        self::assertSelectorTextContains('.recu__table', 'Cotisation · Janv. '.$this->annee);
        $this->client->request('GET', '/associations/moudery/reversements?ville=association');
        self::assertSelectorTextContains('.kpi', '6,00');

        // L'annulation remet les échéances dues et vide les cases.
        $crawler = $this->client->request('GET', '/associations/moudery/paiements');
        $this->client->submit($crawler->filter('form[action$="/annuler"]')->form());
        self::assertResponseRedirects('/associations/moudery/paiements', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'annulé');
        self::assertSelectorTextContains('.paiements__ligne--annule', 'Annulé');
        $em = $this->em();
        self::assertSame(PaiementStatut::Annule, $em->find(Paiement::class, $paiement->getId())?->getStatut());
        $janvier = $em->getRepository(Echeance::class)->findOneBy(['membre' => $this->mamadou, 'mois' => 1]);
        self::assertTrue($janvier?->estDue());
        $mamadou = $em->find(Membre::class, $this->mamadou);
        self::assertNull($mamadou?->adhesionPour($this->annee)?->getMontantMois(1));
        self::assertCount(0, $em->getRepository(Evenement::class)->findBy(['type' => TypeEvenement::PaiementAnnule]) ? [] : [1], 'Le journal garde l’annulation.');

        $this->client->request('GET', '/associations/moudery/paiements/export.csv?periode=tout');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"Mamadou Diaby";Lyon;"01/'.$this->annee.' + 02/'.$this->annee.'";Espèces;20,00;', (string) $this->client->getResponse()->getContent());
    }

    public function testLeMemePaiementPourPlusieursMembresEnUneFois(): void
    {
        // Fanta arrive après l'ouverture : « Mettre à jour les échéances » lui crée ses mensualités.
        $em = $this->em();
        $lyon = $em->find(Ville::class, $this->lyon);
        \assert($lyon instanceof Ville);
        $em->persist(new Membre($lyon, 'Fanta', 'Traoré'));
        $em->flush();
        $cotisation = $em->getRepository(\App\Entity\Cotisation::class)->findOneBy([]);
        \assert($cotisation instanceof \App\Entity\Cotisation);
        $cotisations = static::getContainer()->get(\App\Cotisation\Cotisations::class);
        \assert($cotisations instanceof \App\Cotisation\Cotisations);
        $cotisations->generer($cotisation, new \DateTimeImmutable());
        $em->flush();

        // La liste s'affiche d'emblée, avec ce que chacun doit et le mois courant précoché.
        $crawler = $this->client->request('GET', '/associations/moudery/paiements/nouveau?ville='.$this->lyon);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.tableau--paiement-groupe tbody tr'));
        self::assertSelectorTextContains('.tableau--paiement-groupe tbody', '12 échéances');
        self::assertSelectorExists(\sprintf('input[name="cibles[]"][value="cotisation:%d:%d"][checked]', $cotisation->getId(), (int) date('n')));
        $jeton = $crawler->filter('form.paiement-groupe input[name="_token"]')->attr('value');
        $ids = array_map(static fn (Membre $m): int => (int) $m->getId(), $em->getRepository(Membre::class)->findBy(['ville' => $lyon]));

        // Janvier et février pour les deux, en espèces : deux paiements de 20 €.
        $this->client->request('POST', '/associations/moudery/paiements/groupe', [
            '_token' => $jeton,
            'membres' => $ids,
            'cibles' => [\sprintf('cotisation:%d:1', $cotisation->getId()), \sprintf('cotisation:%d:2', $cotisation->getId())],
            'moyen' => 'especes',
            'recuLe' => date('Y-m-d'),
            'reference' => 'Réunion du 5',
        ]);
        self::assertResponseRedirects('/associations/moudery/paiements', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', '2 paiements enregistrés · 40,00');
        self::assertSelectorTextContains('.alerte--succes', '1 reçu envoyé', 'Mamadou a une adresse, pas Fanta.');
        $paiements = $this->em()->getRepository(Paiement::class)->findAll();
        self::assertCount(2, $paiements);
        foreach ($paiements as $p) {
            self::assertSame(2000, $p->getMontant());
            self::assertSame('Réunion du 5', $p->getReference());
        }

        // Une seconde fois pour les mêmes mois : personne ne doit plus rien, rien n'est enregistré.
        $this->client->request('POST', '/associations/moudery/paiements/groupe', [
            '_token' => $jeton,
            'membres' => $ids,
            'cibles' => [\sprintf('cotisation:%d:1', $cotisation->getId())],
            'moyen' => 'especes',
            'recuLe' => date('Y-m-d'),
        ]);
        self::assertResponseRedirects('/associations/moudery/paiements/nouveau', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'Aucun paiement enregistré · 2 membres ignorés');
        self::assertCount(2, $this->em()->getRepository(Paiement::class)->findAll());
    }

    public function testSansEcheanceCocheeLeFormulaireRevientEn422(): void
    {
        $crawler = $this->client->request('GET', '/associations/moudery/paiements/nouveau?membre='.$this->mamadou);
        $formulaire = $crawler->filter('form[name="paiement"]')->form();
        foreach ($formulaire['paiement[echeances]'] as $case) {
            $case->untick();
        }
        $this->client->submit($formulaire);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.champ--erreur .champ__erreur', 'Cochez au moins une échéance.');
    }

    public function testUnMembreDUneAutreAssociationEstIntrouvable(): void
    {
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $ville = $this->creerVille($bakel, 'Rouen', [], EtapeAssistant::Activation);
        $em = $this->em();
        $rouen = $em->find(Ville::class, $ville);
        \assert($rouen instanceof Ville);
        $autre = new Membre($rouen, 'Oumar', 'Sy');
        $em->persist($autre);
        $em->flush();

        $this->client->request('GET', '/associations/moudery/paiements/nouveau?membre='.$autre->getId());
        self::assertResponseStatusCodeSame(404);
    }
}
