<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * État de l'abonnement d'une association à la plateforme : à souscrire (le bureau central n'a pas encore choisi
 * son offre), offert (rien à payer), actif (à jour), en retard (échéance dépassée, constatée par le super-admin) ou résilié.
 */
enum AbonnementStatut: string
{
    case ASouscrire = 'a-souscrire';
    case Offert = 'offert';
    case Actif = 'actif';
    case EnRetard = 'en-retard';
    case Resilie = 'resilie';
}
