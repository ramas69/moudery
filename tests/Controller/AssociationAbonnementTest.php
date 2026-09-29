<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Abonnement\Catalogue;
use App\Entity\AbonnementStatut;
use App\Entity\Association;
use App\Entity\PaiementAbonnement;
use App\Security\Role;

/** Onglet Abonnement des Paramètres : souscription et paiement en ligne simulés de l'abonnement (25 € par mois). */
final class AssociationAbonnementTest extends CasDeTestWeb
{
    public function testLeBureauCentralSouscritPuisPaie(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $central = $this->creerUtilisateur($moudery, 'central@example.org', Role::BureauCentral);
        $this->connecter($central);

        $crawler = $this->client->request('GET', '/associations/moudery/parametres/abonnement');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.onglets-parametres [aria-current="page"]', 'Abonnement');
        $abonnement = $this->em()->find(Association::class, $moudery)?->getAbonnement();
        if (null !== $abonnement && $abonnement->attendLaSouscription()) {
            self::assertSelectorExists('input[name="offre"][value="'.Catalogue::CODE_MENSUEL.'"]');
        }
        self::assertSelectorTextContains('.abonnement__simulation', 'Simulation');

        $this->client->request('POST', '/associations/moudery/parametres/abonnement/payer', [
            '_token' => $crawler->filter('.abonnement__payer input[name="_token"]')->attr('value'),
            'offre' => Catalogue::CODE_MENSUEL,
        ]);
        self::assertResponseRedirects('/associations/moudery/parametres/abonnement', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'simulation');

        $em = $this->em();
        $abonnement = $em->find(Association::class, $moudery)?->getAbonnement();
        self::assertNotNull($abonnement);
        self::assertSame(AbonnementStatut::Actif, $abonnement->getStatut());
        self::assertSame(Catalogue::MENSUEL, $abonnement->getMontant());
        $paiements = $em->getRepository(PaiementAbonnement::class)->findAll();
        self::assertCount(1, $paiements);
        self::assertSame(2500, $paiements[0]->getMontant());
        self::assertSelectorTextContains('.tableau', 'Paiement en ligne simulé');

        // Un second paiement avance encore l'échéance.
        $crawler = $this->client->request('GET', '/associations/moudery/parametres/abonnement');
        self::assertSelectorTextContains('.abonnement__payer button', 'Payer 25');
        $this->client->request('POST', '/associations/moudery/parametres/abonnement/payer', [
            '_token' => $crawler->filter('.abonnement__payer input[name="_token"]')->attr('value'),
        ]);
        self::assertCount(2, $this->em()->getRepository(PaiementAbonnement::class)->findAll());
    }

    public function testUneAssociationOffertePassALaFormulePayante(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $em = $this->em();
        $association = $em->find(Association::class, $moudery);
        \assert($association instanceof Association);
        $abonnement = $association->getAbonnement() ?? $association->ouvrirAbonnement();
        $abonnement->definir('Gratuit', AbonnementStatut::Offert, 0, \App\Entity\Periodicite::Mensuelle, new \DateTimeImmutable('-1 month'), null, null);
        $em->persist($abonnement);
        $em->flush();
        $this->connecter($this->creerUtilisateur($moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/parametres/abonnement');
        self::assertSelectorTextContains('.abonnement', 'passer à la formule payante');
        $this->client->request('POST', '/associations/moudery/parametres/abonnement/payer', [
            '_token' => $crawler->filter('.abonnement__payer input[name="_token"]')->attr('value'),
            'offre' => Catalogue::CODE_MENSUEL,
        ]);
        $abonnement = $this->em()->find(Association::class, $moudery)?->getAbonnement();
        self::assertSame(AbonnementStatut::Actif, $abonnement?->getStatut());
        self::assertSame(2500, $abonnement?->getMontant());
        self::assertCount(1, $this->em()->getRepository(PaiementAbonnement::class)->findAll());
    }

    public function testUnTresorierNOuvrePasLAbonnement(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $tresorier = $this->creerUtilisateur($moudery, 'tresorier@example.org', Role::Tresorier, $lyon);
        $this->connecter($tresorier);
        $this->client->request('GET', '/associations/moudery/parametres/abonnement');
        self::assertResponseStatusCodeSame(403);
    }
}
