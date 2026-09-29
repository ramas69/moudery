<?php

declare(strict_types=1);

namespace App\Mailer;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Rend chaque e-mail Twig AU MOMENT de l'envoi, avant sa mise en file Messenger (bogue trouvé le 29 septembre 2026 au
 * premier envoi en production) : sinon le contexte du gabarit (entités Doctrine : compte, invitation, paiement…) est
 * sérialisé dans la file et revient vide dans le worker (« Typed property … must not be accessed before
 * initialization »). Une fois rendu, l'e-mail ne garde que ses corps HTML et texte (`markAsRendered()` vide le
 * contexte) et le worker ne le rend pas une seconde fois. Les liens sont ainsi calculés dans la requête d'origine.
 */
#[AsDecorator('mailer.mailer')]
final class MailerRendu implements MailerInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly MailerInterface $interne,
        private readonly BodyRendererInterface $rendu,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($message instanceof TemplatedEmail && !$message->isRendered()) {
            $this->rendu->render($message);
        }
        $this->interne->send($message, $envelope);
    }
}
