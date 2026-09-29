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
 * Entrer dans l'espace d'une association (`Permission::ESPACE_OUVRIR`) : le bureau central et le super-admin, ou toute
 * personne qui tient un rôle sur une ville de cette association (trésorier, président, secrétaire). Le périmètre
 * (`App\Association\Perimetre`) limite ensuite ces responsables à leurs villes. Association suspendue ou archivée :
 * fermée à tous sauf au super-admin.
 *
 * @extends Voter<string, Association>
 */
final class EspaceVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return Permission::ESPACE_OUVRIR === $attribute && $subject instanceof Association;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur || !$subject instanceof Association) {
            return false;
        }
        if (!$subject->estActive() && !$utilisateur->aLaPermissionSurLaPlateforme(Permission::PLATEFORME_ADMINISTRER)) {
            return false;
        }
        if ($utilisateur->aLaPermission(Permission::ESPACE_OUVRIR, $subject)) {
            return true;
        }
        foreach ($utilisateur->getAffectations() as $affectation) {
            $ville = $affectation->getVille();
            if (null !== $ville && $ville->getAssociation() === $subject && $affectation->getRole()->accorde(Permission::VILLE_CONSULTER)) {
                return true;
            }
        }

        return false;
    }
}
