<?php

declare(strict_types=1);

namespace App\Tests\Mailer;

use App\Security\Role;
use App\Tests\Controller\CasDeTestWeb;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Un e-mail mis en file doit survivre à la sérialisation du worker (bogue du 29 septembre 2026 en production : le
 * contexte Twig portait des entités qui revenaient vides). Il part donc déjà rendu, sans contexte.
 */
final class EmailEnFileTest extends CasDeTestWeb
{
    public function testLEmailDeReinitialisationPartRendu(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->creerUtilisateur($moudery, 'hawa@example.org', Role::BureauCentral);

        $crawler = $this->client->request('GET', '/mot-de-passe-oublie');
        $formulaire = $crawler->filter('form')->form();
        $formulaire['mot_de_passe_oublie[email]'] = 'hawa@example.org';
        $this->client->submit($formulaire);
        self::assertResponseRedirects();

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $envoyes = $transport->getSent();
        self::assertCount(1, $envoyes);
        $message = $envoyes[0]->getMessage();
        self::assertInstanceOf(SendEmailMessage::class, $message);

        // Ce que fait le worker de production : sérialiser, puis relire.
        $relu = unserialize(serialize($message));
        self::assertInstanceOf(SendEmailMessage::class, $relu);
        $email = $relu->getMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertTrue($email->isRendered(), 'L’e-mail est rendu avant sa mise en file.');
        self::assertSame([], $email->getContext(), 'Aucune entité ne voyage dans la file.');
        self::assertStringContainsString('/mot-de-passe/', (string) $email->getTextBody());
        self::assertNotEmpty($email->getHtmlBody());
    }
}
