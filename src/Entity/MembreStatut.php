<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Situation d'un membre dans sa ville : actif (validé, il cotise), en attente (inscription à valider par un
 * responsable, F-01) ou sorti (parti ou transféré, conservé pour l'historique).
 */
enum MembreStatut: string
{
    case Actif = 'actif';
    case EnAttente = 'en-attente';
    case Sorti = 'sorti';
}
