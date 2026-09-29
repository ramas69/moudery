<?php

declare(strict_types=1);

namespace App\Administration;

use function Symfony\Component\String\u;

/** Comparaisons de texte sans accents ni casse, pour les recherches des listes d'administration. */
final class Texte
{
    public static function normaliser(string $texte): string
    {
        return u($texte)->ascii()->lower()->collapseWhitespace()->toString();
    }

    public static function contient(string $meule, string $aiguille): bool
    {
        $aiguille = self::normaliser($aiguille);

        return '' === $aiguille || str_contains(self::normaliser($meule), $aiguille);
    }
}
