<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Association;
use App\Entity\MembreStatut;
use App\Entity\Ville;
use App\Repository\EcheanceRepository;
use App\Repository\MembreRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Compteurs de la navigation de l'espace : `membres_en_attente(ville)` (inscriptions à valider, entrée « Membres ») et
 * `membres_en_retard(association, ville)` (membres avec une échéance en retard, entrée « Impayés et relances »).
 */
final class MembresExtension extends AbstractExtension
{
    public function __construct(private readonly MembreRepository $membres, private readonly EcheanceRepository $echeances)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('membres_en_attente', fn (Ville $ville): int => $this->membres->compterParStatut($ville)[MembreStatut::EnAttente->value] ?? 0),
            new TwigFunction('membres_en_retard', fn (Association $association, ?Ville $ville): int => $this->echeances->compterMembresEnRetard($association, $ville, new \DateTimeImmutable())),
        ];
    }
}
