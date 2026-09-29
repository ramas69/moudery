<?php

declare(strict_types=1);

namespace App\Entity;

/** Montant d'un appel : fixe (le même pour tous) ou libre (avec un montant suggéré facultatif). */
enum ModeMontant: string
{
    case Fixe = 'fixe';
    case Libre = 'libre';
}
