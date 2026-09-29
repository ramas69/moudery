<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Journal\Journal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

/** Consigne au journal chaque emprunt d'identité : qui a vu l'application comme qui. Le retour à son compte n'est pas consigné. */
#[AsEventListener]
final class EnregistreurEmprunt
{
    public function __construct(
        private readonly Journal $journal,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SwitchUserEvent $evenement): void
    {
        $jeton = $evenement->getToken();
        $cible = $evenement->getTargetUser();
        if (!$jeton instanceof SwitchUserToken || !$cible instanceof Utilisateur) {
            return;
        }

        $acteur = $jeton->getOriginalToken()->getUser();
        $this->journal->consigner(TypeEvenement::CompteVuComme, $acteur instanceof Utilisateur ? $acteur : null, $cible->getAssociation(), $cible->getEmail());
        $this->entityManager->flush();
    }
}
