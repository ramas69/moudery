<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Association;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur les villes, décidés par les affectations du compte (F-07) :
 * le bureau central agit sur toute son association, un trésorier sur sa ville seulement.
 * Une ville d'une autre association n'est jamais couverte.
 *
 * @extends Voter<string, Association|Ville>
 */
final class VilleVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, Permission::villes(), true)
            && ($subject instanceof Association || $subject instanceof Ville);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        // Une association suspendue ou archivée est figée pour ses responsables ; seul le super-admin y touche encore.
        $association = $subject instanceof Ville ? $subject->getAssociation() : $subject;
        if (!$association->estActive() && !$utilisateur->aLaPermissionSurLaPlateforme(Permission::PLATEFORME_ADMINISTRER)) {
            return false;
        }

        return $utilisateur->aLaPermission($attribute, $subject);
    }
}
