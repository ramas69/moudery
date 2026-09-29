<?php

declare(strict_types=1);

namespace App\Tests\Controller;

/** Mentions légales et politique de confidentialité : publiques, reliées depuis la connexion. */
final class LegalTest extends CasDeTestWeb
{
    public function testLesPagesLegalesSontPubliques(): void
    {
        $this->client->request('GET', '/mentions-legales');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mentions légales');
        self::assertSelectorTextContains('.legal__corps', 'O2switch');
        $this->client->request('GET', '/confidentialite');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Politique de confidentialité');
        $this->client->request('GET', '/connexion');
        self::assertSelectorExists('.pied-legal a[href="/mentions-legales"]');
        self::assertSelectorNotExists('link[href*="fonts.googleapis.com"]', 'Plus aucun appel à Google Fonts.');
    }
}
