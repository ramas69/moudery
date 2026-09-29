<?php

declare(strict_types=1);

namespace App\Administration;

use App\Entity\Affectation;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Form\Model\AffectationData;
use App\Form\Model\CompteIdentiteData;
use App\Journal\Journal;
use App\Security\Role;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Administration des comptes par le super-admin : identité, statut, rôles et périmètres (F-07).
 * Chaque action est consignée au journal avant d'être enregistrée : les changements de rôle sont sensibles (section 2).
 */
final class GestionComptes
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly Journal $journal,
        private readonly Security $securite,
    ) {
    }

    /** L'adresse est-elle libre, ou déjà celle de ce compte ? */
    public function emailDisponible(string $email, Utilisateur $pour): bool
    {
        $existant = $this->utilisateurs->trouverParEmail($email);

        return null === $existant || $existant->getId() === $pour->getId();
    }

    public function modifierIdentite(Utilisateur $compte, CompteIdentiteData $donnees): void
    {
        $avant = ['prenom' => $compte->getPrenom(), 'nom' => $compte->getNom(), 'email' => $compte->getEmail()];
        $compte->modifierIdentite((string) $donnees->prenom, (string) $donnees->nom);
        $compte->changerEmail((string) $donnees->email);
        $apres = ['prenom' => $compte->getPrenom(), 'nom' => $compte->getNom(), 'email' => $compte->getEmail()];

        $this->journal->consigner(TypeEvenement::CompteModifie, $this->acteur(), $compte->getAssociation(), $compte->getEmail(), ['avant' => $avant, 'apres' => $apres]);
        $this->entityManager->flush();
    }

    public function activer(Utilisateur $compte): void
    {
        $compte->activer();
        $this->journal->consigner(TypeEvenement::CompteStatut, $this->acteur(), $compte->getAssociation(), $compte->getEmail(), ['statut' => $compte->getStatut()->value]);
        $this->entityManager->flush();
    }

    /**
     * Un compte désactivé ne peut plus se connecter ; ses rôles sont conservés pour une réactivation.
     * Le dernier super-admin actif ne se désactive pas : personne ne pourrait plus administrer.
     */
    public function desactiver(Utilisateur $compte): void
    {
        if ($compte->aLeRole(Role::SuperAdmin) && $this->estLeDernierSuperAdminActif($compte)) {
            throw new \LogicException(\sprintf('%s est le dernier super-admin actif : son compte ne peut pas être désactivé.', $compte->getNomComplet()));
        }

        $compte->desactiver();
        $this->journal->consigner(TypeEvenement::CompteStatut, $this->acteur(), $compte->getAssociation(), $compte->getEmail(), ['statut' => $compte->getStatut()->value]);
        $this->entityManager->flush();
    }

    public function affecter(Utilisateur $compte, AffectationData $donnees): Affectation
    {
        \assert(null !== $donnees->role);
        $affectation = $compte->affecter($donnees->role, $donnees->ville);
        $this->journal->consigner(TypeEvenement::RoleAttribue, $this->acteur(), $compte->getAssociation(), $compte->getEmail(), self::detailsAffectation($affectation));
        $this->entityManager->flush();

        return $affectation;
    }

    /** Le dernier super-admin actif de la plateforme ne perd jamais son rôle : personne ne pourrait plus administrer. */
    public function retirerAffectation(Utilisateur $compte, Affectation $affectation): void
    {
        if (Role::SuperAdmin === $affectation->getRole() && $this->estLeDernierSuperAdminActif($compte)) {
            throw new \LogicException(\sprintf('%s est le dernier super-admin actif : son rôle ne peut pas être retiré.', $compte->getNomComplet()));
        }

        $details = self::detailsAffectation($affectation);
        $compte->retirerAffectation($affectation);
        $this->journal->consigner(TypeEvenement::RoleRetire, $this->acteur(), $compte->getAssociation(), $compte->getEmail(), $details);
        $this->entityManager->flush();
    }

    private function estLeDernierSuperAdminActif(Utilisateur $compte): bool
    {
        foreach ($this->utilisateurs->listerSuperAdmins() as $superAdmin) {
            if ($superAdmin->getId() !== $compte->getId() && $superAdmin->estActif()) {
                return false;
            }
        }

        return true;
    }

    /** @return array{role: string, ville: ?string} */
    private static function detailsAffectation(Affectation $affectation): array
    {
        return ['role' => $affectation->getRole()->value, 'ville' => $affectation->getVille()?->getNom()];
    }

    private function acteur(): ?Utilisateur
    {
        $acteur = $this->securite->getUser();

        return $acteur instanceof Utilisateur ? $acteur : null;
    }
}
