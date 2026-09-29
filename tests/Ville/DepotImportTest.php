<?php

declare(strict_types=1);

namespace App\Tests\Ville;

use App\Ville\DepotImport;
use PHPUnit\Framework\TestCase;

/** Le dépôt garde un fichier le temps d'un import, sous un jeton, et oublie les fichiers d'un jour. */
final class DepotImportTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir().'/depot-import-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier.'/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        @rmdir($this->dossier);
    }

    public function testUnFichierDeposeSeRelitParSonJetonPuisSeSupprime(): void
    {
        $depot = new DepotImport($this->dossier);

        $jeton = $depot->deposer("Prénom;Nom\n");

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $jeton);
        self::assertSame("Prénom;Nom\n", $depot->lire($jeton));
        self::assertNull($depot->lire('../../etc/passwd'), 'Seul un jeton bien formé est lu.');
        self::assertNull($depot->lire(str_repeat('a', 32)));

        $depot->supprimer($jeton);
        self::assertNull($depot->lire($jeton));
    }

    public function testLesFichiersOubliesSontPurgesAuDepotSuivant(): void
    {
        $depot = new DepotImport($this->dossier);
        $ancien = $depot->deposer('ancien');
        touch($this->dossier.'/'.$ancien.'.bin', time() - DepotImport::DUREE_DE_VIE - 60);

        $depot->deposer('nouveau');

        self::assertNull($depot->lire($ancien));
    }
}
