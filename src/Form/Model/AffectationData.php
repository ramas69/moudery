<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Security\Perimetre;
use App\Security\Role;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Un rôle de plus pour un compte existant (F-07) : le rôle et, s'il s'attribue sur une ville, la ville. */
final class AffectationData
{
    #[Assert\NotNull(message: 'compte.role.obligatoire')]
    public ?Role $role = null;

    public ?Ville $ville = null;

    public function __construct(public readonly Utilisateur $compte)
    {
    }

    /**
     * Les mêmes règles que le constructeur d'Affectation, expliquées champ par champ.
     * Un compte sans association ne reçoit que le rôle super-admin ; un compte d'association reçoit
     * les rôles de son association et peut aussi devenir super-admin (règle du 27 septembre 2026).
     */
    #[Assert\Callback]
    public function validerPerimetre(ExecutionContextInterface $contexte): void
    {
        if (null === $this->role) {
            return;
        }

        $association = $this->compte->getAssociation();
        $perimetre = $this->role->perimetre();

        if (Perimetre::Plateforme !== $perimetre && null === $association) {
            $contexte->buildViolation('compte.role.association_requise')->atPath('role')->addViolation();

            return;
        }
        if (Perimetre::Ville === $perimetre && null === $this->ville) {
            $contexte->buildViolation('compte.role.ville_obligatoire')->atPath('ville')->addViolation();

            return;
        }
        if (Perimetre::Ville !== $perimetre && null !== $this->ville) {
            $contexte->buildViolation('compte.role.sans_ville')->atPath('ville')->addViolation();

            return;
        }
        if (null !== $this->ville && null !== $association && $this->ville->getAssociation()->getId() !== $association->getId()) {
            $contexte->buildViolation('compte.ville.hors_association')->atPath('ville')->addViolation();

            return;
        }
        if (null !== $this->compte->affectationPour($this->role, $this->ville)) {
            $contexte->buildViolation('compte.affectation.existante')->atPath('role')->addViolation();
        }
    }
}
