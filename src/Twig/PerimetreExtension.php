<?php

declare(strict_types=1);

namespace App\Twig;

use App\Association\Perimetre;
use App\Entity\Association;
use App\Entity\Ville;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Le périmètre courant pour la barre latérale de l'espace d'une association : la ville choisie et les villes proposées. */
final class PerimetreExtension extends AbstractExtension
{
    public function __construct(private readonly Perimetre $perimetre)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('perimetre', $this->perimetre(...))];
    }

    /** @return array{ville: ?Ville, villes: list<Ville>, toute: bool} toute : la personne peut choisir « toute l'association » */
    public function perimetre(Association $association): array
    {
        return ['ville' => $this->perimetre->villeCourante($association), 'villes' => $this->perimetre->villes($association), 'toute' => $this->perimetre->piloteToute($association)];
    }
}
