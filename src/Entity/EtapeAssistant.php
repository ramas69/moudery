<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Étapes de l'assistant de création d'une ville (M2 bis). L'ordre des cas est l'ordre des étapes.
 *
 * Décision de Rama du 27 septembre 2026 : trois étapes seulement. Le compte bancaire (F-41), les cotisations (F-43),
 * les projets (F-44) et l'historique (F-45) se règlent après l'activation, depuis l'espace de la ville : les types de
 * cotisation dépendent des projets et de l'historique, ils n'ont pas leur place à la création.
 */
enum EtapeAssistant: string
{
    case Identite = 'identite';
    case Membres = 'membres';
    case Activation = 'activation';

    public function numero(): int
    {
        return (int) array_search($this, self::cases(), true) + 1;
    }

    public static function nombre(): int
    {
        return \count(self::cases());
    }

    /** Les membres peuvent attendre : un trésorier renseigné suffit pour activer la ville. */
    public function estFacultative(): bool
    {
        return self::Membres === $this;
    }

    public function suivante(): ?self
    {
        return self::cases()[$this->numero()] ?? null;
    }

    public function estAvant(self $autre): bool
    {
        return $this->numero() < $autre->numero();
    }

    /** Part du chemin parcouru, en pourcentage : 33, 67, 100. */
    public function avancement(): int
    {
        return (int) round($this->numero() / self::nombre() * 100);
    }
}
