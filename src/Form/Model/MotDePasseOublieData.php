<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** Demande d'un lien de réinitialisation du mot de passe (F-03). */
final class MotDePasseOublieData
{
    #[Assert\NotBlank(message: 'compte.email.obligatoire')]
    #[Assert\Email(message: 'compte.email.invalide')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;
}
