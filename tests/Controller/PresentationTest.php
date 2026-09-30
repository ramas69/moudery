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
        self::assertSelectorTextContains('h1', 'De la ville au village');
        self::assertSelectorExists('.vitrine__entete a[href="/connexion"]');
        self::assertSelectorTextContains('.vitrine__membre', 'pas d’inscription');
        self::assertSelectorExists('.demo .demo__piece');
        self::assertSelectorNotExists('a[href*="inscription"]');
        self::assertSelectorExists('.pied a[href="/confidentialite"]');

        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur($moudery, 'central@example.org', Role::BureauCentral));
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/associations/moudery');
    }
}
