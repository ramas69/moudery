<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Utilisateur;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur la plateforme entière (administration, création d'association), sans sujet :
 * seules les affectations hors association, celles du super-admin, les accordent, et jamais pendant un emprunt d'identité.
 *
 * @extends Voter<string, null>
 */
final class PlateformeVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return null === $subject && \in_array($attribute, Permission::plateforme(), true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // Sous emprunt d'identité, on voit l'application comme la personne empruntée : l'administration reste fermée,
        // même si l'emprunteur est super-admin. Il revient à son compte pour administrer.
        if ($token instanceof SwitchUserToken) {
            return false;
        }

        $utilisateur = $token->getUser();

        return $utilisateur instanceof Utilisateur && $utilisateur->aLaPermissionSurLaPlateforme($attribute);
    }
}
