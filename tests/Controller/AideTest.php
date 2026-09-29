<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\EtapeAssistant;
use App\Security\Role;

/** Guide d'utilisation (page Aide) : chaque rôle voit ses tutoriels, dans sa coquille ; l'entrée est dans le menu du compte. */
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

    public function testLeBureauCentralVoitLesTroisGuides(): void
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
    }

    public function testUnResponsableDeVilleVoitSonGuideEtCeluiDesMembres(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'awa@example.org', Role::Tresorier, $this->lyon));
        $this->client->request('GET', '/aide');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#guide-central');
        self::assertSelectorExists('#guide-ville');
        self::assertSelectorExists('#guide-membre');
        self::assertSelectorTextContains('#guide-ville', 'Marquer comme appelé');
    }

    public function testUnMembreVoitSonGuideDansLaCoquilleSimple(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'membre@example.org', Role::Membre, $this->lyon));
        $this->client->request('GET', '/aide');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#guide-central');
        self::assertSelectorNotExists('#guide-ville');
        self::assertSelectorExists('#guide-membre');
        self::assertSelectorNotExists('.console__nav');
    }

    public function testLaPageDemandeUneConnexion(): void
    {
        $this->client->request('GET', '/aide');
        self::assertResponseRedirects('/connexion');
    }
}
