<?php

declare(strict_types=1);

namespace App\Administration;

/**
 * La fenêtre de temps des graphiques du tableau de bord : un début inclus, une fin exclue, un pas (jour, semaine, mois)
 * choisi pour donner entre une dizaine et une trentaine de barres. Se construit depuis les paramètres d'URL, toujours valide.
 */
final readonly class Periode
{
    public const array CLES = ['30j', '3m', '12m', 'annee', 'libre'];

    /** @param 'jour'|'semaine'|'mois' $pas */
    private function __construct(
        public string $cle,
        public \DateTimeImmutable $debut,
        public \DateTimeImmutable $fin,
        public string $pas,
    ) {
    }

    public static function depuis(?string $cle, ?string $du, ?string $au, \DateTimeImmutable $aujourdhui): self
    {
        $cle = \in_array($cle, self::CLES, true) ? $cle : '12m';
        $jour = $aujourdhui->setTime(0, 0);
        $demain = $jour->modify('+1 day');

        if ('libre' === $cle) {
            $debut = self::date($du) ?? $jour->modify('-12 months')->modify('first day of this month');
            $fin = (self::date($au) ?? $jour)->modify('+1 day');
            if ($fin <= $debut) {
                $fin = $debut->modify('+1 day');
            }
            $jours = (int) $debut->diff($fin)->days;

            return new self($cle, $debut, $fin, $jours <= 45 ? 'jour' : ($jours <= 200 ? 'semaine' : 'mois'));
        }

        return match ($cle) {
            '30j' => new self($cle, $jour->modify('-29 days'), $demain, 'jour'),
            '3m' => new self($cle, $jour->modify('-13 weeks')->modify('monday this week'), $demain, 'semaine'),
            'annee' => new self($cle, $jour->modify('first day of january this year'), $demain, 'mois'),
            default => new self($cle, $jour->modify('-11 months')->modify('first day of this month'), $demain, 'mois'),
        };
    }

    /**
     * Les tranches successives de la période : début inclus, fin exclue, libellé court pour l'axe.
     *
     * @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, cle: string}>
     */
    public function tranches(): array
    {
        $tranches = [];
        $curseur = match ($this->pas) {
            'mois' => $this->debut->modify('first day of this month'),
            'semaine' => $this->debut->modify('monday this week'),
            default => $this->debut,
        };
        while ($curseur < $this->fin) {
            $suivant = match ($this->pas) {
                'mois' => $curseur->modify('first day of next month'),
                'semaine' => $curseur->modify('+7 days'),
                default => $curseur->modify('+1 day'),
            };
            $tranches[] = ['debut' => max($curseur, $this->debut), 'fin' => min($suivant, $this->fin), 'cle' => $this->cleDeTranche($curseur)];
            $curseur = $suivant;
        }

        return $tranches;
    }

    /** L'indice de la tranche qui contient cette date, ou null hors période. */
    public function indice(\DateTimeInterface $date): ?int
    {
        $date = \DateTimeImmutable::createFromInterface($date);
        if ($date < $this->debut || $date >= $this->fin) {
            return null;
        }
        $cle = $this->cleDeTranche($date);
        foreach ($this->tranches() as $i => $tranche) {
            if ($tranche['cle'] === $cle) {
                return $i;
            }
        }

        return null;
    }

    public function nombreDeJours(): int
    {
        return (int) $this->debut->diff($this->fin)->days;
    }

    private function cleDeTranche(\DateTimeImmutable $date): string
    {
        return match ($this->pas) {
            'mois' => $date->format('Y-m'),
            'semaine' => $date->format('o-\WW'),
            default => $date->format('Y-m-d'),
        };
    }

    private static function date(?string $valeur): ?\DateTimeImmutable
    {
        if (null === $valeur || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return false === $date ? null : $date;
    }
}
