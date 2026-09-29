<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Cotisation;
use App\Entity\Echeance;
use App\Entity\EcheanceStatut;
use App\Entity\EtapeAssistant;
use App\Entity\Evenement;
use App\Entity\Membre;
use App\Entity\TypeEvenement;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Cotisations périodiques (F-12) : ouverture de l'année, mensualités générées, mois du classeur déjà versés, tarif modifié, régénération. */
final class AssociationCotisationsPeriodiquesTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->annee = (int) date('Y');
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', ['tresorier' => 'awa@example.org'], EtapeAssistant::Activation);
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $em = $this->em();
        $lyon = $em->find(Ville::class, $this->lyon);
        \assert($lyon instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        // Mamadou a déjà versé janvier et février d'après le classeur ; Fanta n'a rien versé ; Hawa est sortie.
        $mamadou = new Membre($lyon, 'Mamadou', 'Diaby', 'mamadou@example.org');
        $mamadou->adherer($this->annee)->definirHistorique([1 => 1000, 2 => 1000]);
        $fanta = new Membre($lyon, 'Fanta', 'Traoré');
        $hawa = new Membre($lyon, 'Hawa', 'Cissé');
        $hawa->sortir();
        $em->persist($mamadou);
        $em->persist($fanta);
        $em->persist($hawa);
        $em->flush();
    }

    public function testOuvrirLesCotisationsDeLAnneeCreeLesMensualites(): void
    {
        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/cotisations?ville=%d&annee=%d', $this->lyon, $this->annee));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cotisation-panneau--a-ouvrir', 'Cotisations '.$this->annee.' à ouvrir');
        $lien = $crawler->filter('.cotisation-panneau--a-ouvrir a.bouton--primaire')->link();

        $crawler = $this->client->click($lien);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ouvrir les cotisations '.$this->annee.' · Lyon');
        self::assertSelectorTextContains('.depense-saisie__cote', '2 adhérents concernés', 'Les membres actifs, pas la sortie.');

        $formulaire = $crawler->filter('form[name="cotisation"]')->form();
        $formulaire['cotisation[montantMensuel]'] = '10,00';
        $formulaire['cotisation[jourEcheance]'] = '5';
        $this->client->submit($formulaire);
        self::assertResponseRedirects(\sprintf('/associations/moudery/cotisations?annee=%d', $this->annee), 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Cotisations '.$this->annee.' ouvertes pour Lyon : 2 adhérents, 24 mensualités créées, 2 déjà versées.');
        self::assertSelectorTextContains('.cotisation-panneau', '10,00');
        self::assertSelectorTextContains('.cotisation-panneau', '2 mensualités payées sur 24');

        $em = $this->em();
        $cotisation = $em->getRepository(Cotisation::class)->findOneBy(['annee' => $this->annee]);
        self::assertInstanceOf(Cotisation::class, $cotisation);
        self::assertSame(1000, $cotisation->getMontantMensuel());
        $echeances = $em->getRepository(Echeance::class)->findBy(['cotisation' => $cotisation]);
        self::assertCount(24, $echeances);
        $payees = array_filter($echeances, static fn (Echeance $e): bool => $e->estPayee());
        self::assertCount(2, $payees, 'Janvier et février de Mamadou, versés d’après le classeur.');
        foreach ($payees as $e) {
            self::assertNull($e->getPaiement(), 'Un mois versé avant l’application n’a pas de paiement.');
            self::assertSame('Mamadou Diaby', $e->getMembre()->getNomComplet());
        }
        $fanta = $em->getRepository(Membre::class)->findOneBy(['prenom' => 'Fanta']);
        self::assertInstanceOf(Membre::class, $fanta);
        self::assertSame(1000, $fanta->adhesionPour($this->annee)?->getTarifMensuel(), 'La ligne du classeur est créée avec le tarif.');
        $mars = $em->getRepository(Echeance::class)->findOneBy(['cotisation' => $cotisation, 'membre' => $fanta, 'mois' => 3]);
        self::assertInstanceOf(Echeance::class, $mars);
        self::assertSame(\sprintf('%d-03-05', $this->annee), $mars->getDateLimite()->format('Y-m-d'));
        self::assertSame(EcheanceStatut::Due, $mars->getStatut());

        $evenement = $em->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::CotisationOuverte]);
        self::assertInstanceOf(Evenement::class, $evenement);
        self::assertSame(24, $evenement->getDetails()['echeances']);

        // Une seconde ouverture est refusée ; la grille montre la ligne de Fanta désormais.
        $this->client->request('GET', \sprintf('/associations/moudery/cotisations/ouvrir?annee=%d', $this->annee));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'déjà ouvertes');
        self::assertSelectorTextContains('.tableau--grille', 'Fanta Traoré');
    }

    public function testModifierLeTarifEtRegenererApresUnNouveauMembre(): void
    {
        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/cotisations/ouvrir?ville=%d&annee=%d', $this->lyon, $this->annee));
        $formulaire = $crawler->filter('form[name="cotisation"]')->form();
        $formulaire['cotisation[montantMensuel]'] = '10,00';
        $this->client->submit($formulaire);
        $em = $this->em();
        $cotisation = $em->getRepository(Cotisation::class)->findOneBy(['annee' => $this->annee]);
        \assert($cotisation instanceof Cotisation);

        // Le tarif passe à 12 € le 10 : seules les mensualités dues changent.
        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/cotisations/%d/tarif', $cotisation->getId()));
        self::assertResponseIsSuccessful();
        $formulaire = $crawler->filter('form[name="cotisation"]')->form();
        $formulaire['cotisation[montantMensuel]'] = '12,00';
        $formulaire['cotisation[jourEcheance]'] = '10';
        $this->client->submit($formulaire);
        self::assertResponseRedirects();
        $em = $this->em();
        $echeances = $em->getRepository(Echeance::class)->findBy(['cotisation' => $cotisation->getId()]);
        $dues = array_filter($echeances, static fn (Echeance $e): bool => $e->estDue());
        $payees = array_filter($echeances, static fn (Echeance $e): bool => $e->estPayee());
        self::assertCount(22, $dues);
        foreach ($dues as $e) {
            self::assertSame(1200, $e->getMontant());
            self::assertSame('10', $e->getDateLimite()->format('d'));
        }
        foreach ($payees as $e) {
            self::assertSame(1000, $e->getMontant(), 'Un mois déjà payé garde son montant.');
        }

        // Un membre arrive : « Mettre à jour les échéances » lui crée ses douze mensualités, sans doublon pour les autres.
        $lyon = $em->find(Ville::class, $this->lyon);
        \assert($lyon instanceof Ville);
        $em->persist(new Membre($lyon, 'Seydou', 'Sakho'));
        $em->flush();
        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/cotisations?annee=%d', $this->annee));
        $this->client->submit($crawler->filter('form[action$="/generer"]')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', '12 mensualités créées');
        self::assertCount(36, $this->em()->getRepository(Echeance::class)->findBy(['cotisation' => $cotisation->getId()]));
    }

    public function testUnTresorierDUneAutreVilleNOuvrePas(): void
    {
        $evry = $this->creerVille($this->moudery, 'Évry', [], EtapeAssistant::Activation);
        $this->connecter($this->creerUtilisateur($this->moudery, 'seydou@example.org', Role::Tresorier, $evry));
        $this->client->request('GET', \sprintf('/associations/moudery/cotisations/ouvrir?ville=%d&annee=%d', $this->lyon, $this->annee));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Évry', 'Le périmètre reste sa ville : Lyon n’est jamais atteinte.');
    }
}
