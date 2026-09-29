<?php

declare(strict_types=1);

namespace App\Security;

/** Étendue sur laquelle un rôle s'attribue : toute la plateforme, une association, ou une ville. */
enum Perimetre: string
{
    case Plateforme = 'plateforme';
    case Association = 'association';
    case Ville = 'ville';
}
