<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Foyer;
use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\Ville;
use App\Tests\Controller\CasDeTestWeb;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Jeu d'essai : la commande app:membres:generer peuple une ville de membres et de foyers plausibles. */
final class GenererMembresCommandTest extends CasDeTestWeb
{
    public function testLaCommandeCreeDesMembresEtDesFoyersDansLaVille(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->creerVille($moudery, 'Lyon');

        $testeur = $this->testeur();
        $code = $testeur->execute(['association' => 'moudery', 'ville' => 'Lyon', '--nombre' => '50', '--graine' => '2026']);

        self::assertSame(0, $code, $testeur->getDisplay());
        self::assertStringContainsString('50 membres', $testeur->getDisplay());
        self::assertMatchesRegularExpression('/\\d+ adhésions/', $testeur->getDisplay());

        $em = $this->em();
        $membres = $em->getRepository(Membre::class)->findAll();
        self::assertCount(50, $membres);
        $emails = [];
        $enFoyer = 0;
        $sansEmail = 0;
        $enAttente = 0;
        $anneeCourante = (int) date('Y');
        $plusieursAnnees = 0;
        foreach ($membres as $membre) {
            self::assertSame(Membre::ORIGINE_GENERATION, $membre->getOrigine());
            $annees = $membre->getAnneesAdhesion();
            self::assertNotEmpty($annees, 'Chaque membre fictif a au moins une année d’adhésion.');
            self::assertSame([], array_diff($annees, [$anneeCourante - 2, $anneeCourante - 1, $anneeCourante]), 'Les années sont l’année en cours ou les deux précédentes.');
            if (\count($annees) > 1) {
                ++$plusieursAnnees;
            }
            self::assertSame('Lyon', $membre->getVille()->getNom());
            self::assertSame($moudery, $membre->getAssociation()->getId());
            if (null !== $membre->getEmail()) {
                self::assertStringEndsWith('@example.org', $membre->getEmail());
                self::assertArrayNotHasKey($membre->getEmail(), $emails, 'Les adresses sont uniques.');
                $emails[$membre->getEmail()] = true;
            } else {
                ++$sansEmail;
            }
            if (null !== $membre->getFoyer()) {
                ++$enFoyer;
                self::assertSame($membre->getNom(), substr($membre->getFoyer()->getNom(), 8, \strlen($membre->getNom())), 'Un foyer porte le nom de ses membres.');
            }
            if (MembreStatut::EnAttente === $membre->getStatut()) {
                ++$enAttente;
            }
        }
        self::assertGreaterThan(20, $enFoyer);
        self::assertGreaterThan(0, $sansEmail);
        self::assertGreaterThan(0, $enAttente);
        self::assertGreaterThan(10, $plusieursAnnees, 'Beaucoup de membres ont plusieurs années, comme dans le classeur réel.');

        $foyers = $em->getRepository(Foyer::class)->findAll();
        self::assertNotEmpty($foyers);
        foreach ($foyers as $foyer) {
            self::assertNotNull($foyer->getPayeur(), 'Chaque foyer a un payeur.');
            self::assertGreaterThanOrEqual(2, \count($foyer->getMembres()));
        }
    }

    public function testLaPurgeNeRetireQueLesMembresFictifs(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $em = $this->em();
        $ville = $em->find(Ville::class, $lyon);
        \assert($ville instanceof Ville);
        $vrai = new Membre($ville, 'Awa', 'Cissé', 'awa@example.org', null, MembreStatut::Actif, Membre::ORIGINE_SAISIE);
        $vrai->rejoindreFoyer(new Foyer($ville, 'Famille Cissé'));
        $em->persist($vrai->getFoyer());
        $em->persist($vrai);
        $em->flush();
        $this->testeur()->execute(['association' => 'moudery', 'ville' => 'Lyon', '--nombre' => '20', '--graine' => '7']);
        self::assertSame(21, $this->em()->getRepository(Membre::class)->count([]));

        $testeur = $this->testeur();
        $code = $testeur->execute(['association' => 'moudery', 'ville' => 'Lyon', '--purger' => true]);

        self::assertSame(0, $code, $testeur->getDisplay());
        self::assertStringContainsString('20 membres fictifs', $testeur->getDisplay());
        $em = $this->em();
        self::assertSame(1, $em->getRepository(Membre::class)->count([]));
        self::assertSame(1, $em->getRepository(Foyer::class)->count([]), 'Seul le foyer du vrai membre reste.');
        self::assertSame('Famille Cissé', $em->getRepository(Foyer::class)->findAll()[0]->getNom());
    }

    public function testLaCommandeRefuseUneVilleInconnue(): void
    {
        $this->creerAssociation('Association de Moudery', 'moudery');

        $testeur = $this->testeur();
        $code = $testeur->execute(['association' => 'moudery', 'ville' => 'Atlantide']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Aucune ville « Atlantide »', $testeur->getDisplay());
    }

    private function testeur(): CommandTester
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);

        return new CommandTester($application->find('app:membres:generer'));
    }
}
