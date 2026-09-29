<?php

declare(strict_types=1);

namespace App\Entity;

/** Rythme de facturation d'un abonnement. */
enum Periodicite: string
{
    case Mensuelle = 'mensuelle';
    case Annuelle = 'annuelle';

    public function intervalle(): \DateInterval
    {
        return new \DateInterval(self::Mensuelle === $this ? 'P1M' : 'P1Y');
    }

    /** Ce que rapporte un montant par période, ramené au mois. */
    public function mensualiser(int $montant): int
    {
        return self::Mensuelle === $this ? $montant : intdiv($montant, 12);
    }
}
