<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\ReversementRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `reversements_a_confirmer(association)` : le compteur de l'entrée « Reversements » de la navigation. */
final class ReversementsExtension extends AbstractExtension
{
    public function __construct(private readonly ReversementRepository $reversements)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('reversements_a_confirmer', $this->reversements->compterAConfirmer(...))];
    }
}
