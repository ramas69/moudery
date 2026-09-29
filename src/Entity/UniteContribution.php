<?php

declare(strict_types=1);

namespace App\Entity;

/** Qui paie une contribution (cahier des charges, section 3) : chaque personne, ou le payeur de chaque foyer. */
enum UniteContribution: string
{
    case Personne = 'personne';
    case Foyer = 'foyer';
}
