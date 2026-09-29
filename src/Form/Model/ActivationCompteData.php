<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** Activation d'un compte depuis une invitation (F-02) : identité et mot de passe. */
class ActivationCompteData
{
    #[Assert\NotBlank(message: 'compte.prenom.obligatoire')]
    #[Assert\Length(max: 80)]
    public ?string $prenom = null;

    #[Assert\NotBlank(message: 'compte.nom.obligatoire')]
    #[Assert\Length(max: 80)]
    public ?string $nom = null;

    #[Assert\NotBlank(message: 'compte.mot_de_passe.obligatoire')]
    #[Assert\Length(min: NouveauMotDePasseData::LONGUEUR_MINIMALE, minMessage: 'compte.mot_de_passe.trop_court', max: 4096)]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'compte.mot_de_passe.trop_faible')]
    public ?string $motDePasse = null;

    #[Assert\Length(max: 30, maxMessage: 'membre.telephone.trop_long')]
    #[Assert\Regex(pattern: '/^[\d\s+().-]*$/', message: 'membre.telephone.invalide')]
    public ?string $telephone = null;

    public bool $consentEmail = true;
}
