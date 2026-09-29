<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Tableau de bord : filtres communs (F-32), comparaison (F-34), flux de l'argent (G-02), carte (G-03), export PNG (F-35). */
final class AssociationGraphiquesTest extends CasDeTestWeb
{
    public function testFiltresComparaisonFluxEtCarte(): void
    {
        $annee = (int) date('Y');
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $idLyon = $this->creerVille($moudery, 'Lyon', [], EtapeAssistant::Activation);
        $em = $this->em();
        $lyon = $em->find(Ville::class, $idLyon);
        \assert($lyon instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        $lyon->getAssociation()->definirPremierExercice($annee - 1);
        $hawa = new Membre($lyon, 'Hawa', 'Soumaré');
        $hawa->adherer($annee)->definirHistorique([1 => 1000, 2 => 1000]);
        $hawa->adherer($annee - 1)->definirHistorique([1 => 500]);
        $em->persist($hawa);
        $em->flush();
        $this->connecter($this->creerUtilisateur($moudery, 'central@example.org', Role::BureauCentral));

        $this->client->request('GET', '/associations/moudery');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.filtres-page a[aria-current="true"]', 'Tout');
        self::assertSelectorTextContains('#flux-titre', 'Où va chaque euro collecté');
        self::assertSelectorExists('.flux__svg path.flux__lien--cotisations');
        self::assertSelectorTextContains('.flux__svg', 'Lyon');
        self::assertSelectorExists('.carte__svg .carte__bulle circle');
        self::assertSelectorTextContains('.carte__svg', 'Lyon');
        self::assertSelectorExists('[data-controller="export-png"] button[data-action="export-png#exporter"]');
        self::assertSelectorNotExists('.graphique__precedent');

        // Comparer avec l'exercice précédent : un repère par mois et la valeur sous « Collecté ».
        $this->client->request('GET', '/associations/moudery?comparer=1');
        self::assertSelectorCount(12, '.graphique__precedent');
        self::assertSelectorTextContains('.kpi__precedent', (string) ($annee - 1));
        self::assertSelectorExists('.filtres-page__comparer[aria-pressed="true"]');

        // Filtre de type : contributions ponctuelles seules, rien d'encaissé ; le flux ne montre plus les sorties.
        $this->client->request('GET', '/associations/moudery?type=ponctuelles');
        self::assertSelectorTextContains('.filtres-page a[aria-current="true"]', 'Contributions ponctuelles');
        self::assertSelectorExists('.graphique__vide');
        $this->client->request('GET', '/associations/moudery?type=cotisations');
        self::assertSelectorTextContains('.flux figcaption', 'seules les entrées');
        self::assertSelectorNotExists('.flux__lien--du');

        // Le rapport d'AG reprend le flux.
        $this->client->request('GET', '/associations/moudery/rapport');
        self::assertSelectorExists('.rapport__flux .flux__svg');
    }
}
