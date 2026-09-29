<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Abonnement\Catalogue;
use Symfony\Component\Validator\Constraints as Assert;

/** Souscription du bureau central : l'offre choisie, l'acceptation des conditions, et le compte à activer. */
final class SouscriptionData extends ActivationCompteData
{
    #[Assert\NotBlank(message: 'souscription.offre.obligatoire')]
    #[Assert\Choice(callback: [Catalogue::class, 'codes'], message: 'souscription.offre.inconnue')]
    public ?string $offre = null;

    #[Assert\IsTrue(message: 'souscription.conditions.obligatoire')]
    public bool $conditions = false;
}
