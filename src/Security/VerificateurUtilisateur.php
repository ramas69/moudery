<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Seul un compte actif d'une association active peut se connecter ; sinon la connexion est refusée avec un message clair. */
final class VerificateurUtilisateur implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Utilisateur) {
            return;
        }

        match ($user->getStatut()) {
            UtilisateurStatut::EnAttente => throw new CustomUserMessageAccountStatusException('compte.en_attente'),
            UtilisateurStatut::Desactive => throw new CustomUserMessageAccountStatusException('compte.desactive'),
            UtilisateurStatut::Actif => null,
        };

        // Une association suspendue ou archivée ferme l'accès à tous ses comptes ; le super-admin n'en a pas.
        $association = $user->getAssociation();
        if (null !== $association && !$association->estActive()) {
            throw new CustomUserMessageAccountStatusException($association->estArchivee() ? 'compte.association_archivee' : 'compte.association_suspendue');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
