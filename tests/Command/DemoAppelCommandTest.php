<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\AppelContribution;
use App\Entity\AppelStatut;
use App\Entity\Depense;
use App\Entity\DepenseStatut;
use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\Paiement;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Tests\Controller\CasDeTestWeb;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** `app:demo:appel` : appel à projet de démonstration, contributions, dépense en attente du président ; `--purger` retire tout. */
final class DemoAppelCommandTest extends CasDeTestWeb
{
    public function testCreeEtPurgeLAppelDeDemonstration(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyonId = $this->creerVille($moudery, 'Lyon', [], EtapeAssistant::Activation);
        $em = $this->em();
        $lyon = $em->find(Ville::class, $lyonId);
        \assert($lyon instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        for ($i = 1; $i <= 30; ++$i) {
            $em->persist(new Membre($lyon, 'Prénom'.$i, 'Nom'.$i));
        }
        $em->flush();

        $commande = new CommandTester((new Application(self::$kernel))->find('app:demo:appel'));
        $commande->execute(['association' => 'moudery', 'ville' => 'Lyon']);
        $commande->assertCommandIsSuccessful();
        self::assertStringContainsString('tresoriere.demo@example.org', $commande->getDisplay());

        $em->clear();
        $appel = $em->getRepository(AppelContribution::class)->findOneBy([]);
        self::assertInstanceOf(AppelContribution::class, $appel);
        self::assertSame(AppelStatut::Ouvert, $appel->getStatut());
        self::assertCount(30, $appel->getEcheances());
        self::assertCount(12, $em->getRepository(Paiement::class)->findAll());
        $depense = $em->getRepository(Depense::class)->findOneBy([]);
        self::assertSame(DepenseStatut::Soumise, $depense?->getStatut());
        self::assertSame($appel->getId(), $depense?->getAppel()?->getId());

        // Le président de démonstration voit l'appel et son collecté.
        $president = $em->getRepository(\App\Entity\Utilisateur::class)->findOneBy(['email' => 'president.demo@example.org']);
        $this->connecter((int) $president?->getId());
        $this->client->request('GET', '/associations/moudery/appels/'.$appel->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.appel-suivi__jours');

        $commande->execute(['association' => 'moudery', 'ville' => 'Lyon', '--purger' => true]);
        $commande->assertCommandIsSuccessful();
        $em->clear();
        self::assertNull($em->getRepository(AppelContribution::class)->findOneBy([]));
        self::assertCount(0, $em->getRepository(Paiement::class)->findAll());
        self::assertCount(0, $em->getRepository(Depense::class)->findAll());
    }
}
