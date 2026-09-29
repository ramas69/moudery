<?php

declare(strict_types=1);

namespace App\Entity;

/** Circuit d'une dépense (F-22) : brouillon → soumise → validée ou refusée → payée. Seule une dépense payée touche le solde. */
enum DepenseStatut: string
{
    case Brouillon = 'brouillon';
    case Soumise = 'soumise';
    case Validee = 'validee';
    case Refusee = 'refusee';
    case Payee = 'payee';
}
