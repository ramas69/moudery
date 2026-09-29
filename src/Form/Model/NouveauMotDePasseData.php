<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** Choix d'un nouveau mot de passe : 12 caractères minimum et suffisamment robuste (F-03). */
final class NouveauMotDePasseData
{
    public const int LONGUEUR_MINIMALE = 12;

    #[Assert\NotBlank(message: 'compte.mot_de_passe.obligatoire')]
    #[Assert\Length(min: self::LONGUEUR_MINIMALE, minMessage: 'compte.mot_de_passe.trop_court', max: 4096)]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'compte.mot_de_passe.trop_faible')]
    public ?string $motDePasse = null;

    #[Assert\EqualTo(propertyPath: 'motDePasse', message: 'compte.mot_de_passe.confirmation')]
    public ?string $confirmation = null;
}
