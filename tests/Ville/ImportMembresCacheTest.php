<?php

declare(strict_types=1);

namespace App\Tests\Ville;

use App\Ville\ImportMembres;
use PHPUnit\Framework\TestCase;

/** La lecture d'un fichier d'import est gardée en cache : le mode « une colonne indique la ville » ne relit plus la feuille. */
final class ImportMembresCacheTest extends TestCase
{
    public function testUneMemeLectureNeRelitPasLeFichier(): void
    {
        ImportMembres::viderCache();
        $csv = "Prénom;Nom;Caisse\nHawa;Soumaré;Lyon\nOumar;Kanouté;Paris\n";
        $premiere = ImportMembres::lire($csv, 0, null, [2, 0, 1]);
        $seconde = ImportMembres::lire($csv, 0, null, [0, 1, 2]);
        self::assertSame($premiere, $seconde);
        self::assertSame(1, ImportMembres::lecturesEnCache(), 'Même fichier, mêmes colonnes dans un autre ordre : servi par le cache.');

        ImportMembres::lire($csv."Fanta;Diawara;Lyon\n", 0, null, [0, 1, 2]);
        self::assertSame(1, ImportMembres::lecturesEnCache(), 'Un autre contenu se relit.');
        self::assertCount(4, ImportMembres::lire($csv."Fanta;Diawara;Lyon\n"));
    }

    public function testLeCacheResteBorne(): void
    {
        ImportMembres::viderCache();
        for ($i = 0; $i < 20; ++$i) {
            ImportMembres::lire("Prénom;Nom\nA;B$i\n");
        }
        ImportMembres::lire("Prénom;Nom\nA;B0\n");
        self::assertSame(0, ImportMembres::lecturesEnCache(), 'Les plus anciennes lectures sont oubliées.');
    }
}
