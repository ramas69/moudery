<?php

declare(strict_types=1);

namespace App\Abonnement;

use App\Entity\Periodicite;

/**
 * Les offres proposées à la souscription d'une association. Une seule formule, deux périodicités.
 * TARIFS PROVISOIRES : le cahier des charges ne fixe aucune grille ; ils se changent ici, en un seul endroit.
 */
final class Catalogue
{
    public const string FORMULE = 'Standard';

    /** 25 € par mois. */
    public const int MENSUEL = 2500;

    /** 250 € par an : deux mois offerts par rapport au mensuel. */
    public const int ANNUEL = 25000;

    public const string CODE_MENSUEL = 'standard-mensuel';
    public const string CODE_ANNUEL = 'standard-annuel';

    /** @return list<Offre> */
    public static function offres(): array
    {
        return [
            new Offre(self::CODE_MENSUEL, self::FORMULE, Periodicite::Mensuelle, self::MENSUEL),
            new Offre(self::CODE_ANNUEL, self::FORMULE, Periodicite::Annuelle, self::ANNUEL),
        ];
    }

    /** @return array<string, Offre> Les offres par code, pour les gabarits. */
    public static function parCode(): array
    {
        $parCode = [];
        foreach (self::offres() as $offre) {
            $parCode[$offre->code] = $offre;
        }

        return $parCode;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::parCode());
    }

    public static function offre(string $code): ?Offre
    {
        return self::parCode()[$code] ?? null;
    }

    /** Ce que l'offre annuelle fait économiser sur douze mois, en centimes. */
    public static function economieAnnuelle(): int
    {
        return self::MENSUEL * 12 - self::ANNUEL;
    }
}
