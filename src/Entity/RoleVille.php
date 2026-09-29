<?php

declare(strict_types=1);

namespace App\Entity;

/** Rôles de ville qu'un responsable peut recevoir par invitation (F-40). */
enum RoleVille: string
{
    case Tresorier = 'tresorier';
    case President = 'president';
    case Secretaire = 'secretaire';
}
