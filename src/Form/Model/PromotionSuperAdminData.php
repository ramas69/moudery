<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Utilisateur;
use Symfony\Component\Validator\Constraints as Assert;

/** Promotion d'un compte existant au rôle de super-admin : il garde ses autres rôles. */
final class PromotionSuperAdminData
{
    #[Assert\NotNull(message: 'compte.promotion.obligatoire')]
    public ?Utilisateur $compte = null;
}
