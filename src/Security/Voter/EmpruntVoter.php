<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Utilisateur;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Emprunt d'identité (switch_user) : seul un super-admin de la plateforme voit l'application comme une autre
 * personne, jamais comme lui-même ni comme un autre administrateur de la plateforme.
 * Le pare-feu demande ROLE_ALLOWED_TO_SWITCH avec le compte visé pour sujet.
 *
 * @extends Voter<string, Utilisateur|null>
 */
final class EmpruntVoter extends Voter
{
    public const string ATTRIBUT = 'ROLE_ALLOWED_TO_SWITCH';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUT === $attribute && (null === $subject || $subject instanceof Utilisateur);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $acteur = $token->getUser();
        if (!$acteur instanceof Utilisateur || !$acteur->aLaPermissionSurLaPlateforme(Permission::PLATEFORME_ADMINISTRER)) {
            return false;
        }

        if (!$subject instanceof Utilisateur) {
            return true;
        }

        // Jamais soi-même, et jamais un compte qui administre la plateforme : l'emprunt sert à voir l'application
        // comme un rôle d'association, pas à agir en super-admin sous un autre nom.
        return $subject->getId() !== $acteur->getId() && !$subject->aLaPermissionSurLaPlateforme(Permission::PLATEFORME_ADMINISTRER);
    }
}
