<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Association;
use App\Entity\Ville;
use App\Security\Perimetre;
use App\Security\Role;
use App\Validator\EmailCompteLibre;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Invitation d'une personne depuis l'administration (F-02) : un rôle sur un périmètre, à une adresse. */
final class InvitationCompteData
{
    #[Assert\NotNull(message: 'compte.association.obligatoire')]
    public ?Association $association = null;

    #[Assert\NotNull(message: 'compte.role.obligatoire')]
    public ?Role $role = null;

    /** Obligatoire pour un rôle de ville, interdit pour le bureau central. */
    public ?Ville $ville = null;

    #[Assert\NotBlank(message: 'compte.email.obligatoire')]
    #[Assert\Email(message: 'compte.email.invalide')]
    #[Assert\Length(max: 180)]
    #[EmailCompteLibre]
    public ?string $email = null;

    #[Assert\Callback]
    public function validerPerimetre(ExecutionContextInterface $contexte): void
    {
        if (null === $this->role) {
            return;
        }

        $perimetre = $this->role->perimetre();
        if (Perimetre::Ville === $perimetre && null === $this->ville) {
            $contexte->buildViolation('compte.role.ville_obligatoire')->atPath('ville')->addViolation();

            return;
        }
        if (Perimetre::Ville !== $perimetre && null !== $this->ville) {
            $contexte->buildViolation('compte.role.sans_ville')->atPath('ville')->addViolation();

            return;
        }
        if (null !== $this->ville && null !== $this->association && $this->ville->getAssociation()->getId() !== $this->association->getId()) {
            $contexte->buildViolation('compte.ville.hors_association')->atPath('ville')->addViolation();
        }
    }
}
