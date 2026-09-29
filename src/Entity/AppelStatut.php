<?php

declare(strict_types=1);

namespace App\Entity;

/** Cycle d'un appel à contribution : brouillon (rien n'est envoyé), ouvert (échéances créées, e-mails partis), clôturé. */
enum AppelStatut: string
{
    case Brouillon = 'brouillon';
    case Ouvert = 'ouvert';
    case Cloture = 'cloture';
}
