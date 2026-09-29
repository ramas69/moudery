<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Un reversement d'une ville au bureau central (F-20) : déclaré par la ville (virement annoncé, à confirmer par le
 * central) ou confirmé (l'argent est arrivé, seul un reversement confirmé solde le dû).
 */
enum ReversementStatut: string
{
    case Declare = 'declare';
    case Confirme = 'confirme';
}
