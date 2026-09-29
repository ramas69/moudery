<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\EtapeAssistant;
use App\Security\Role;

/** Guide d'utilisation (page Aide) : la page décrit tout à tout le monde, dans la coquille de chacun, en proposant son guide ; l'entrée est dans le menu du compte. */
final class AideTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', [], EtapeAssistant::Activation);
    }

    public function testLeBureauCentralVoitToutLeGuideDansSonEspace(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $this->client->request('GET', '/aide');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Guide d’utilisation');
        self::assertSelectorExists('#guide-central');
        self::assertSelectorExists('#guide-ville');
        self::assertSelectorExists('#guide-membre');
        self::assertSelectorExists('.console__nav', 'Dans la coquille de l’espace de l’association.');
        self::assertSelectorExists('.console__menu-compte a[href="/aide"]', 'L’entrée « Aide » du menu du compte.');
        self::assertSelectorExists('.aide__figure img[src*="images/aide/central-tableau-de-bord"]');
        self::assertSelectorTextContains('.aide__sommaire', 'Lancer un appel à contribution');
        self::assertSelectorExists('#vue-ensemble');
        self::assertSelectorExists('#glossaire');
        self::assertSelectorTextContains('.console__actions a[href="#guide-central"]', 'Votre guide : Bureau central');
        self::assertSelectorNotExists('button[onclick]', 'Pas de bouton d’impression : la page est le tutoriel.');
    }

    public function testUnResponsableDeVilleEstConduitVersSonGuide(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'awa@example.org', Role::Tresorier, $this->lyon));
        $this->client->request('GET', '/aide');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#guide-central');
        self::assertSelectorExists('#guide-ville');
        self::assertSelectorExists('#guide-membre');
        self::assertSelectorTextContains('.console__actions a[href="#guide-ville"]', 'Responsables d’une ville');
        self::assertSelectorTextContains('#guide-ville', 'Marquer comme appelé');
    }

    public function testUnMembreLitLaPageDansLaCoquilleSimple(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'membre@example.org', Role::Membre, $this->lyon));
        $this->client->request('GET', '/aide');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#guide-membre');
        self::assertSelectorExists('#glossaire');
        self::assertSelectorNotExists('.console__nav');
    }

    public function testLaPageDemandeUneConnexion(): void
    {
        $this->client->request('GET', '/aide');
        self::assertResponseRedirects('/connexion');
    }
}
