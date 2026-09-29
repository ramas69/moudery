<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Adhesion;
use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Grille du classeur pour un exercice d'octobre à septembre : les colonnes suivent l'exercice, pas l'année civile. */
final class AssociationGrilleExerciceTest extends CasDeTestWeb
{
    public function testLOrdreDesMoisSuitLExercice(): void
    {
        self::assertSame([10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8, 9], Adhesion::ordreDesMois(10));
        self::assertSame(range(1, 12), Adhesion::ordreDesMois(1));
    }

    public function testLaGrilleCommenceEnOctobre(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $idLyon = $this->creerVille($moudery, 'Lyon', [], EtapeAssistant::Activation);
        $em = $this->em();
        $association = $em->find(Association::class, $moudery);
        $lyon = $em->find(Ville::class, $idLyon);
        \assert($association instanceof Association && $lyon instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        $association->definirParametres(10, 0, [-7, 0, 15]);
        $association->definirPremierExercice(2024);
        $hawa = new Membre($lyon, 'Hawa', 'Soumaré');
        $hawa->adherer(2025)->definirHistorique([10 => 1500, 1 => 2000]);
        $em->persist($hawa);
        $em->flush();

        $this->connecter($this->creerUtilisateur($moudery, 'central@example.org', Role::BureauCentral));
        $crawler = $this->client->request('GET', '/associations/moudery/cotisations?ville='.$idLyon.'&annee=2025');
        self::assertResponseIsSuccessful();
        $entetes = $crawler->filter('thead th.grille__mois');
        self::assertStringContainsString('Oct', $entetes->eq(0)->text());
        self::assertStringContainsString('2025', $entetes->eq(0)->text());
        self::assertStringContainsString('Janv', $entetes->eq(3)->text());
        self::assertStringContainsString('2026', $entetes->eq(3)->text());
        $cellules = $crawler->filter('tbody tr')->first()->filter('td.grille__mois');
        self::assertSame('15', trim($cellules->eq(0)->text()), 'Octobre en première colonne.');
        self::assertSame('20', trim($cellules->eq(3)->text()), 'Janvier en quatrième colonne.');

        $this->client->request('GET', '/associations/moudery/membres/export.csv?ville='.$idLyon.'&annee=2025');
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Année;"Oct. 2025";"Nov. 2025";"Déc. 2025";"Janv. 2026"', $csv);
        self::assertStringContainsString('2025;15;;;20;', $csv);
    }
}
