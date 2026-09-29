<?php

declare(strict_types=1);

namespace App\Abonnement;

use App\Entity\Periodicite;

/** Une offre du catalogue : une formule, une périodicité, un prix par période en centimes. */
final readonly class Offre
{
    public function __construct(
        public string $code,
        public string $formule,
        public Periodicite $periodicite,
        public int $montant,
    ) {
    }

    /** Le prix ramené au mois, pour comparer les périodicités. */
    public function montantMensuel(): int
    {
        return $this->periodicite->mensualiser($this->montant);
    }
}
