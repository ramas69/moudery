<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Ville;
use App\Security\Role;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Inviter un responsable depuis l'espace de l'association (F-02, F-07) : une adresse, un rôle, une ville active pour un rôle de ville. */
final class InvitationResponsableData
{
    #[Assert\NotBlank(message: 'invitation.email.obligatoire')]
    #[Assert\Email(message: 'invitation.email.invalide')]
    #[Assert\Length(max: 180, maxMessage: 'invitation.email.trop_long')]
    public ?string $email = null;

    #[Assert\NotNull(message: 'compte.role.obligatoire')]
    public ?Role $role = null;

    public ?Ville $ville = null;

    #[Assert\Callback]
    public function validerPerimetre(ExecutionContextInterface $contexte): void
    {
        if (null === $this->role) {
            return;
        }
        if (Role::BureauCentral === $this->role && null !== $this->ville) {
            $contexte->buildViolation('invitation.ville.inutile')->atPath('ville')->addViolation();
        }
        if (Role::BureauCentral !== $this->role && null === $this->ville) {
            $contexte->buildViolation('invitation.ville.obligatoire')->atPath('ville')->addViolation();
        }
    }
}
