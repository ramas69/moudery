<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Association;
use App\Entity\RoleVille;
use App\Entity\Ville;
use App\Validator\UniqueVilleNom;
use Symfony\Component\Validator\Constraints as Assert;

/** Saisie de l'étape Identité de l'assistant (F-40). */
#[UniqueVilleNom]
final class VilleIdentiteData
{
    #[Assert\NotBlank(message: 'ville.nom.obligatoire')]
    #[Assert\Length(max: 120, maxMessage: 'ville.nom.trop_long')]
    public ?string $nom = null;

    #[Assert\Email(message: 'ville.email.invalide')]
    #[Assert\Length(max: 180)]
    public ?string $emailTresorier = null;

    #[Assert\Email(message: 'ville.email.invalide')]
    #[Assert\Length(max: 180)]
    public ?string $emailPresident = null;

    #[Assert\Email(message: 'ville.email.invalide')]
    #[Assert\Length(max: 180)]
    public ?string $emailSecretaire = null;

    /**
     * @param Association $association l'association dans laquelle la ville est créée (le tenant)
     * @param Ville|null  $ville       la ville modifiée, ou null tant qu'elle n'existe pas
     */
    public function __construct(
        public readonly Association $association,
        public readonly ?Ville $ville = null,
    ) {
    }

    public static function depuisVille(Ville $ville): self
    {
        $donnees = new self($ville->getAssociation(), $ville);
        $donnees->nom = $ville->getNom();
        $donnees->emailTresorier = $ville->invitationPour(RoleVille::Tresorier)?->getEmail();
        $donnees->emailPresident = $ville->invitationPour(RoleVille::President)?->getEmail();
        $donnees->emailSecretaire = $ville->invitationPour(RoleVille::Secretaire)?->getEmail();

        return $donnees;
    }

    public function emailPour(RoleVille $role): ?string
    {
        return match ($role) {
            RoleVille::Tresorier => $this->emailTresorier,
            RoleVille::President => $this->emailPresident,
            RoleVille::Secretaire => $this->emailSecretaire,
        };
    }
}
