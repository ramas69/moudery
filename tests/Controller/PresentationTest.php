<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Security\Role;

/** Page d'accueil publique : visible sans compte, sans inscription libre ; une personne connectée va à son espace. */
final class PresentationTest extends CasDeTestWeb
{
    public function testLaRacinePresenteCaissesAuxVisiteurs(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'La caisse du village');
        self::assertSelectorExists('.presentation__entete a[href="/connexion"]');
        self::assertSelectorTextContains('.presentation__membre', 'pas d’inscription');
        self::assertSelectorNotExists('a[href*="inscription"]');
        self::assertSelectorExists('.pied-legal a[href="/confidentialite"]');

        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur($moudery, 'central@example.org', Role::BureauCentral));
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/associations/moudery');
    }
}
