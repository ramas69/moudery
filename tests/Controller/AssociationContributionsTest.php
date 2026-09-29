<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\Evenement;
use App\Entity\TypeContribution;
use App\Entity\TypeEvenement;
use App\Security\Role;

/** Types de contribution (F-11) : les quatre types par défaut, ajout, modification, archivage sans suppression, dernier type actif protégé. */
final class AssociationContributionsTest extends CasDeTestWeb
{
    private int $moudery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
    }

    public function testLeBureauCentralAjouteModifieEtArchiveUnType(): void
    {
        // Le premier passage par les appels crée les quatre types par défaut.
        $this->client->request('GET', '/associations/moudery/appels/nouveau');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/associations/moudery/parametres/contributions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.onglets-parametres a[aria-current="page"]', 'Types de contribution');
        self::assertCount(4, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Décès');
        self::assertSelectorTextContains('tbody tr:first-child', '10,00');
        self::assertSelectorTextContains('tbody tr:first-child', 'à préciser sur chaque appel');

        // Ajout d'un type « Fête du village », par foyer, 25 € fixes, 20 % au central.
        $crawler = $this->client->request('GET', '/associations/moudery/parametres/contributions/nouveau');
        $formulaire = $crawler->filter('form[name="type_contribution"]')->form();
        $formulaire['type_contribution[nom]'] = 'Fête du village';
        $formulaire['type_contribution[unite]'] = 'foyer';
        $formulaire['type_contribution[mode]'] = 'fixe';
        $formulaire['type_contribution[montantDefaut]'] = '25,00';
        $formulaire['type_contribution[tauxReversement]'] = '20';
        $this->client->submit($formulaire);
        self::assertResponseRedirects('/associations/moudery/parametres/contributions', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Le type « Fête du village » est ajouté.');

        $type = $this->dernier();
        self::assertSame('fete-du-village', $type->getCode());
        self::assertSame(2500, $type->getMontantDefaut());
        self::assertSame(20, $type->getTauxReversement());
        self::assertSame(4, $type->getOrdre());

        // Le nouveau type se propose dans le formulaire d'appel.
        $this->client->request('GET', '/associations/moudery/appels/nouveau');
        self::assertSelectorExists('input[name="appel[type]"][value="fete-du-village"]');

        // Modification : le code ne change pas, le journal garde l'avant et l'après.
        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/parametres/contributions/%d/modifier', $type->getId()));
        $formulaire = $crawler->filter('form[name="type_contribution"]')->form();
        $formulaire['type_contribution[nom]'] = 'Fête annuelle';
        $formulaire['type_contribution[tauxReversement]'] = '';
        $this->client->submit($formulaire);
        self::assertResponseRedirects('/associations/moudery/parametres/contributions', 303);
        $type = $this->recharger($type->getId());
        self::assertSame('Fête annuelle', $type->getNom());
        self::assertSame('fete-du-village', $type->getCode());
        self::assertNull($type->getTauxReversement());
        $evenements = $this->em()->getRepository(Evenement::class)->findBy(['type' => TypeEvenement::TypeContributionModifie]);
        self::assertCount(2, $evenements);
        self::assertSame('modifie', $evenements[1]->getDetails()['action']);
        self::assertSame(20, $evenements[1]->getDetails()['avant']['taux_reversement']);

        // Archivage : plus proposé pour un appel, toujours listé.
        $crawler = $this->client->request('GET', '/associations/moudery/parametres/contributions');
        $this->client->submit($crawler->filter(\sprintf('form[action$="/contributions/%d/archiver"]', $type->getId()))->form());
        self::assertResponseRedirects('/associations/moudery/parametres/contributions', 303);
        self::assertFalse($this->recharger($type->getId())->estActif());
        $this->client->request('GET', '/associations/moudery/appels/nouveau');
        self::assertSelectorNotExists('input[name="appel[type]"][value="fete-du-village"]');
        $crawler = $this->client->request('GET', '/associations/moudery/parametres/contributions');
        self::assertCount(5, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody tr.contributions__ligne--archive', 'Archivé');

        // Réactivation.
        $this->client->submit($crawler->filter(\sprintf('form[action$="/contributions/%d/reactiver"]', $type->getId()))->form());
        self::assertTrue($this->recharger($type->getId())->estActif());
    }

    public function testUnNomVideOuUnTauxHorsBornesEstRefuse(): void
    {
        $crawler = $this->client->request('GET', '/associations/moudery/parametres/contributions/nouveau');
        $formulaire = $crawler->filter('form[name="type_contribution"]')->form();
        $formulaire['type_contribution[nom]'] = '';
        $formulaire['type_contribution[tauxReversement]'] = '150';
        $this->client->submit($formulaire);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="type_contribution"]', 'Donnez un nom au type de contribution.');
        self::assertSelectorTextContains('form[name="type_contribution"]', 'Le taux de reversement va de 0 à 100 %.');
    }

    public function testLeDernierTypeActifNeSArchivePas(): void
    {
        // em() vide l'unité de travail à chaque appel : une seule référence pour persister puis enregistrer.
        $em = $this->em();
        $association = $em->find(Association::class, $this->moudery);
        \assert($association instanceof Association);
        $seul = new TypeContribution($association, 'cotisation', 'Cotisation', \App\Entity\UniteContribution::Personne, \App\Entity\ModeMontant::Fixe, 1000, null);
        $em->persist($seul);
        $em->flush();

        $crawler = $this->client->request('GET', '/associations/moudery/parametres/contributions');
        self::assertCount(1, $crawler->filter('tbody tr'), 'Un type existant : les types par défaut ne sont pas créés par-dessus.');
        $this->client->submit($crawler->filter('form[action$="/archiver"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'Le dernier type actif ne peut pas être archivé');
        self::assertTrue($this->recharger((int) $seul->getId())->estActif());
    }

    public function testUnTresorierOuUneAutreAssociationNAccedePas(): void
    {
        $lyon = $this->creerVille($this->moudery, 'Lyon', ['tresorier' => 'awa@example.org']);
        $this->connecter($this->creerUtilisateur($this->moudery, 'awa@example.org', Role::Tresorier, $lyon));
        $this->client->request('GET', '/associations/moudery/parametres/contributions');
        self::assertResponseStatusCodeSame(403);

        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'bakel@example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery/parametres/contributions');
        self::assertResponseStatusCodeSame(403);
    }

    private function dernier(): TypeContribution
    {
        $this->em()->clear();
        $type = $this->em()->getRepository(TypeContribution::class)->findOneBy([], ['id' => 'DESC']);
        \assert($type instanceof TypeContribution);

        return $type;
    }

    private function recharger(int $id): TypeContribution
    {
        $this->em()->clear();
        $type = $this->em()->find(TypeContribution::class, $id);
        \assert($type instanceof TypeContribution);

        return $type;
    }
}
