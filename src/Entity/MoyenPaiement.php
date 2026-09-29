<?php

declare(strict_types=1);

namespace App\Entity;

/** Comment une dépense est réglée. */
enum MoyenPaiement: string
{
    case Virement = 'virement';
    case Especes = 'especes';
    case Carte = 'carte';
    case Cheque = 'cheque';
}
