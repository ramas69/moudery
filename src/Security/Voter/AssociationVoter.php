<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Association;
use App\Entity\Utilisateur;
use App\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur une association et ses comptes (modifier, supprimer, gérer les comptes),
 * décidés par les affectations du compte : aujourd'hui seul le super-admin les détient, sur toute la plateforme.
 *
 * @extends Voter<string, Association>
 */
final class AssociationVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Association && \in_array($attribute, Permission::associations(), true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $utilisateur = $token->getUser();

        return $utilisateur instanceof Utilisateur && $utilisateur->aLaPermission($attribute, $subject);
    }
}
