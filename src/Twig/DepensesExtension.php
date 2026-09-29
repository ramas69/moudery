<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Association;
use App\Repository\DepenseRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `depenses_a_valider(association)` : le compteur de l'entrée « Dépenses » de la navigation (maquette : « Dépenses 3 »). */
final class DepensesExtension extends AbstractExtension
{
    public function __construct(private readonly DepenseRepository $depenses)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('depenses_a_valider', $this->depenses->compterAValider(...))];
    }
}
