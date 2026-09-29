<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Journal\Journal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** Garde la date de dernière connexion de chaque compte et la consigne au journal. */
#[AsEventListener]
final class EnregistreurConnexion
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly Journal $journal)
    {
    }

    public function __invoke(LoginSuccessEvent $evenement): void
    {
        $utilisateur = $evenement->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $quand = new \DateTimeImmutable();
        $utilisateur->marquerConnexion($quand);
        $this->journal->consigner(TypeEvenement::Connexion, $utilisateur, $utilisateur->getAssociation(), $utilisateur->getEmail(), [], $quand);
        $this->entityManager->flush();
    }
}
