<?php

declare(strict_types=1);

namespace App\Entity;

/** Ce qu'un membre (ou le payeur d'un foyer) doit : due, payée, annulée. */
enum EcheanceStatut: string
{
    case Due = 'due';
    case Payee = 'payee';
    case Annulee = 'annulee';
}
