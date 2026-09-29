<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/** Changement de mot de passe par la personne connectée : l'actuel pour prouver que c'est bien elle, le nouveau saisi deux fois. */
final class ChangementMotDePasseData
{
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'compte.mot_de_passe.actuel_obligatoire'),
        new UserPassword(message: 'compte.mot_de_passe.actuel_incorrect'),
    ])]
    public ?string $actuel = null;

    #[Assert\NotBlank(message: 'compte.mot_de_passe.obligatoire')]
    #[Assert\Length(min: NouveauMotDePasseData::LONGUEUR_MINIMALE, minMessage: 'compte.mot_de_passe.trop_court', max: 4096)]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'compte.mot_de_passe.trop_faible')]
    public ?string $motDePasse = null;

    #[Assert\EqualTo(propertyPath: 'motDePasse', message: 'compte.mot_de_passe.confirmation')]
    public ?string $confirmation = null;
}
