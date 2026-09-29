<?php

declare(strict_types=1);

namespace App\Entity;

/** Un paiement est enregistré, ou annulé (saisie erronée) : jamais supprimé, ses échéances redeviennent dues. */
enum PaiementStatut: string
{
    case Enregistre = 'enregistre';
    case Annule = 'annule';
}
