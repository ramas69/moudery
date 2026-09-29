<?php

declare(strict_types=1);

namespace App\Entity;

/** Une cotisation périodique est ouverte (ses échéances courent) ou clôturée (plus aucune échéance ne se crée). */
enum CotisationStatut: string
{
    case Ouverte = 'ouverte';
    case Cloturee = 'cloturee';
}
