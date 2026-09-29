<?php

declare(strict_types=1);

namespace App\Tests\Export;

use App\Export\Tableur;
use App\Ville\LecteurXlsx;
use PHPUnit\Framework\TestCase;

/** L'export tableur (F-29) : le CSV pour Excel en français, et un classeur XLSX que notre propre lecteur relit. */
final class TableurTest extends TestCase
{
    public function testLeCsvEcritLesNombresAvecUneVirguleEtUnBom(): void
    {
        $csv = Tableur::csv(['Membre', 'Montant (€)', 'Retard'], [['Mamadou Diaby', 12.5, 3], ['Awa Cissé', null, 0]]);

        self::assertStringStartsWith("\u{FEFF}", $csv);
        self::assertStringContainsString("Membre;\"Montant (€)\";Retard\n", $csv);
        self::assertStringContainsString("\"Mamadou Diaby\";12,50;3\n", $csv);
        self::assertStringContainsString("\"Awa Cissé\";;0\n", $csv);
    }

    public function testLeClasseurSeRelitAvecLesNombresTypes(): void
    {
        $xlsx = Tableur::xlsx(['Membre', 'Montant (€)', 'Retard', 'Note'], [['Mamadou Diaby', 12.5, 3, 'a < b & "c"'], ['Awa Cissé', 100.0, 0, null]], 'Impayés 2026');

        self::assertTrue(LecteurXlsx::estUnClasseur($xlsx));
        self::assertSame(['Impayés 2026'], array_column(LecteurXlsx::feuilles($xlsx), 'nom'));
        $lignes = LecteurXlsx::lire($xlsx, 0);
        self::assertSame(['Membre', 'Montant (€)', 'Retard', 'Note'], $lignes[0]);
        self::assertSame(['Mamadou Diaby', '12.5', '3', 'a < b & "c"'], $lignes[1]);
        self::assertSame(['Awa Cissé', '100', '0'], array_values(array_filter($lignes[2], static fn (string $v): bool => '' !== $v)));
    }

    public function testLesLettresDeColonnes(): void
    {
        self::assertSame('A', Tableur::colonne(1));
        self::assertSame('Z', Tableur::colonne(26));
        self::assertSame('AA', Tableur::colonne(27));
        self::assertSame('AZ', Tableur::colonne(52));
    }
}
