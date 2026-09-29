<?php

declare(strict_types=1);

namespace App\Tests\Securite;

use App\Doctrine\FiltreAssociation;
use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\Ville;
use App\Security\Role;
use App\Tests\Controller\CasDeTestWeb;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Isolation entre associations : le filtre Doctrine multi-tenant s'active pour un bureau central ou un trésorier,
 * jamais pour le super-admin, et une requête « oubliée » ne voit plus que l'association de la personne.
 */
final class IsolationAssociationsTest extends CasDeTestWeb
{
    private int $moudery;
    private int $bakel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $lyon = $this->creerVille($this->moudery, 'Lyon', [], EtapeAssistant::Activation);
        $rouen = $this->creerVille($this->bakel, 'Rouen', [], EtapeAssistant::Activation);
        $em = $this->em();
        $villeLyon = $em->find(Ville::class, $lyon);
        $villeRouen = $em->find(Ville::class, $rouen);
        \assert($villeLyon instanceof Ville && $villeRouen instanceof Ville);
        $em->persist(new Membre($villeLyon, 'Mamadou', 'Diaby'));
        $em->persist(new Membre($villeRouen, 'Oumar', 'Sy'));
        $em->flush();
    }

    public function testLeFiltreNeLaisseVoirQueSonAssociation(): void
    {
        $this->client->disableReboot();
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery');
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        self::assertFalse($em->getFilters()->isEnabled(FiltreAssociation::NOM), 'La requête finie, le gestionnaire redevient neutre.');

        // Pendant la requête, une requête qui oublierait l'association ne voit que Moudery : on rejoue l'activation.
        $em->getFilters()->enable(FiltreAssociation::NOM)->setParameter('association', $this->moudery);
        $noms = array_map(static fn (Membre $m): string => $m->getNomComplet(), $em->getRepository(Membre::class)->findAll());
        self::assertSame(['Mamadou Diaby'], $noms);
        self::assertCount(1, $em->getRepository(Ville::class)->findAll());
        self::assertNull($em->getRepository(Ville::class)->findOneBy(['nom' => 'Rouen']));
        $em->getFilters()->disable(FiltreAssociation::NOM);

        // Une adresse d'une autre association reste refusée par les Voters (403), pas une page vide.
        $this->client->request('GET', '/associations/bakel');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeSuperAdminVoitToutEtUnTresorierSaSeuleAssociation(): void
    {
        $this->client->disableReboot();
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));
        $this->client->request('GET', '/administration');
        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        self::assertFalse($em->getFilters()->isEnabled(FiltreAssociation::NOM), 'Rien n’est filtré pour le super-admin.');
        self::assertCount(2, $em->getRepository(Membre::class)->findAll());

        $lyon = $em->getRepository(Ville::class)->findOneBy(['nom' => 'Lyon']);
        \assert($lyon instanceof Ville);
        $this->connecter($this->creerUtilisateur($this->moudery, 'awa@example.org', Role::Tresorier, (int) $lyon->getId()));
        $this->client->request('GET', '/');
        self::assertResponseRedirects(\sprintf('/associations/moudery?ville=%d', (int) $lyon->getId()), null, 'Le trésorier arrive sur l’espace de sa ville.');
        // La page des membres de sa ville ne liste que Moudery, filtre compris.
        $this->client->request('GET', \sprintf('/associations/moudery/membres/%d', (int) $em->getRepository(Membre::class)->findOneBy(['prenom' => 'Mamadou'])?->getId()));
        self::assertResponseIsSuccessful();
    }
}
