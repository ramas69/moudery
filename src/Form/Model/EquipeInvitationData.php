<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Association;
use App\Validator\EmailCompteLibre;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ajout d'une personne à l'équipe de la plateforme, comme super-admin : identité, adresse de connexion,
 * et, si elle appartient aussi à une association, cette association (règle du 27 septembre 2026).
 */
final class EquipeInvitationData
{
    #[Assert\NotBlank(message: 'compte.prenom.obligatoire')]
    #[Assert\Length(max: 80)]
    public ?string $prenom = null;

    #[Assert\NotBlank(message: 'compte.nom.obligatoire')]
    #[Assert\Length(max: 80)]
    public ?string $nom = null;

    #[Assert\NotBlank(message: 'compte.email.obligatoire')]
    #[Assert\Email(message: 'compte.email.invalide')]
    #[Assert\Length(max: 180)]
    #[EmailCompteLibre]
    public ?string $email = null;

    /** Facultatif : rattachée à une association, la personne pourra aussi y recevoir un rôle. */
    public ?Association $association = null;
}
