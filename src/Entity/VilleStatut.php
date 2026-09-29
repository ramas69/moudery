<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Cycle de vie d'une ville : brouillon (assistant en cours) → active → archivée.
 * Une ville activée est archivée, jamais supprimée : son historique comptable est conservé.
 */
enum VilleStatut: string
{
    case Brouillon = 'brouillon';
    case Active = 'active';
    case Archivee = 'archivee';
}
