<?php

declare(strict_types=1);

namespace App\Tests\Ville;

use App\Ville\ImportMembres;
use App\Ville\LecteurXlsx;
use App\Ville\ReglagesAnalyse;
use PHPUnit\Framework\TestCase;

/** Lecture des fichiers de membres (F-31) : CSV dans ses variantes courantes et classeur Excel .xlsx. */
final class ImportMembresLectureTest extends TestCase
{
    public function testUnCsvExcelFrancaisAvecBomEtPointVirgule(): void
    {
        $lignes = ImportMembres::lire("\xEF\xBB\xBFPrénom;Nom;E-mail\r\nMamadou;Diaby;mamadou@example.org\r\n\r\nAwa;Cissé;\r\n");

        self::assertSame([
            ['Prénom', 'Nom', 'E-mail'],
            ['Mamadou', 'Diaby', 'mamadou@example.org'],
            ['Awa', 'Cissé', ''],
        ], $lignes);
    }

    public function testUnCsvAVirgulesAvecUnChampSurDeuxLignes(): void
    {
        $lignes = ImportMembres::lire("first name,last name,household\nMamadou,Diaby,\"Famille\nDiaby\"\n");

        self::assertCount(2, $lignes);
        self::assertSame("Famille\nDiaby", $lignes[1][2]);
    }

    public function testUnCsvWindows1252EstConverti(): void
    {
        $contenu = mb_convert_encoding("Prénom;Nom\nAïssata;Doucouré\n", 'Windows-1252', 'UTF-8');

        $lignes = ImportMembres::lire($contenu);

        self::assertSame(['Aïssata', 'Doucouré'], $lignes[1]);
    }

    public function testUnFichierVideNeDonneAucuneLigne(): void
    {
        self::assertSame([], ImportMembres::lire("\xEF\xBB\xBF\n\n"));
    }

    public function testUnClasseurExcelSeLitParSaPremiereFeuille(): void
    {
        $classeur = ClasseurExcel::membres();

        self::assertTrue(LecteurXlsx::estUnClasseur($classeur));
        $lignes = ImportMembres::lire($classeur);

        self::assertSame([
            ['Prénom', 'Nom', 'E-mail', 'Téléphone', 'Foyer'],
            ['Mamadou', 'Diaby', 'mamadou@example.org', '612345678', 'Famille Diaby'],
            ['Awa', 'Cissé', null, '612345679'],
            ['Seydou', 'Sylla'],
        ], $lignes, 'Les cellules vides omises sont comblées, les nombres perdent la notation scientifique, la ligne vide disparaît.');
    }

    public function testLesFeuillesDUnClasseurSontListeesEtLisiblesUneParUne(): void
    {
        $classeur = ClasseurExcel::membresEnDeuxiemeFeuille();

        self::assertSame([['nom' => 'Membres', 'lignes' => 1], ['nom' => 'Autre', 'lignes' => 4]], ImportMembres::feuilles($classeur));
        self::assertSame([['Notes de réunion']], ImportMembres::lire($classeur, 0));
        self::assertSame(['Prénom', 'Nom', 'E-mail', 'Téléphone', 'Foyer'], ImportMembres::lire($classeur, 1)[0]);
        self::assertSame(0, ImportMembres::ligneEnTete(ImportMembres::lire($classeur, 1)));
        self::assertNull(ImportMembres::ligneEnTete([['Notes de réunion']]));
        self::assertCount(2, ImportMembres::lire($classeur, 1, 2), 'La lecture s’arrête au maximum demandé.');
        self::assertSame([['nom' => null, 'lignes' => 2]], ImportMembres::feuilles("Prénom;Nom\nAwa;Cissé\n"), 'Un CSV est une feuille sans nom.');

        $this->expectException(\RuntimeException::class);
        ImportMembres::lire($classeur, 2);
    }

    public function testLaLigneDEnTetesNEstPasForcementLaPremiere(): void
    {
        $lignes = [
            [null, null, null, null, null, null, 'CAISSE DE MOUDERI PARIS'],
            ['N°', 'Noms', 'Prénoms', 'Villes', 'Année', 'JANVIER', 'FÉVRIER'],
            ['1', 'DIABY', 'MAMADOU', 'Paris', '2019', '10', '10'],
        ];

        self::assertSame(1, ImportMembres::ligneEnTete($lignes));
        self::assertSame(['nom' => 1, 'prenom' => 2, 'localite' => 3, 'annee' => 4, 'mois_1' => 5, 'mois_2' => 6], ImportMembres::colonnesDetectees($lignes[1]), 'Villes, Année et les mois sont reconnus.');
        self::assertSame(0, ImportMembres::ligneEnTete([['Nom'], ['x']]), 'Le nom seul suffit à défaut de mieux.');
        self::assertCount(3, ImportMembres::lire("a;b\nc;d\ne;f\ng;h\n", 0, 3), 'Le CSV aussi s’arrête au maximum demandé.');
    }

    public function testUnNomCompletSeSepareEnPrenomEtNom(): void
    {
        self::assertSame(['Mamadou', 'Diaby'], ImportMembres::separerNomComplet('DIABY Mamadou'));
        self::assertSame(['Mamadou', 'Diaby'], ImportMembres::separerNomComplet('Mamadou DIABY'), 'Les capitales font le nom, quel que soit l’ordre.');
        self::assertSame(['Awa Boye', 'Cissé'], ImportMembres::separerNomComplet('Awa Boye CISSÉ'));
        self::assertSame(['Mamadou', 'Diaby'], ImportMembres::separerNomComplet('Diaby Mamadou'), 'Sans capitales, le premier mot est le nom par défaut.');
        self::assertSame(['Mamadou', 'Diaby'], ImportMembres::separerNomComplet('Mamadou Diaby', ReglagesAnalyse::ORDRE_PRENOM_NOM));
        self::assertSame(['Mamadou Lamine', 'Diaby'], ImportMembres::separerNomComplet('Mamadou Lamine Diaby', ReglagesAnalyse::ORDRE_PRENOM_NOM));
        self::assertSame(['Mamadou', 'Diaby'], ImportMembres::separerNomComplet('DIABY MAMADOU'), 'Tout en capitales : l’ordre par défaut tranche.');
        self::assertSame(['', 'Diaby'], ImportMembres::separerNomComplet('Diaby'));
        self::assertSame(['', ''], ImportMembres::separerNomComplet('  '));
    }

    public function testLeDictionnaireAppisPasseAvantLesAlias(): void
    {
        $enTetes = ['Réf.', 'Cotisant', 'Contact', 'Secteur'];

        self::assertSame(['nom_complet' => 1, 'localite' => 3], ImportMembres::colonnesDetectees($enTetes), '« Cotisant » est un nom complet, « Contact » n’est pas reconnu.');
        self::assertSame(['nom_complet' => 1, 'telephone' => 2, 'localite' => 3], ImportMembres::colonnesDetectees($enTetes, ['contact' => 'telephone']), 'Le dictionnaire de l’association apprend « Contact ».');
        self::assertSame(['contact' => 'telephone', 'cotisant' => 'nom_complet'], ImportMembres::dictionnaire($enTetes, ['telephone' => 2, 'nom_complet' => 1]));
        self::assertSame(0, ImportMembres::ligneEnTete([['Réf.', 'Cotisant']]), 'Un nom complet suffit à reconnaître la ligne d’en-têtes.');
    }

    public function testLaLectureNeGardeQueLesColonnesDemandees(): void
    {
        $lignes = ImportMembres::lire("a;b;c\n;;\nd;;f\n", 0, null, [0, 2]);

        self::assertSame([[0 => 'a', 2 => 'c'], [0 => 'd', 2 => 'f']], $lignes, 'Les indices d’origine sont conservés, la ligne vide est retirée.');
        self::assertSame([[0 => 'Prénom', 3 => 'Téléphone'], [0 => 'Mamadou', 3 => '612345678'], [0 => 'Awa', 3 => '612345679'], [0 => 'Seydou']], ImportMembres::lire(ClasseurExcel::membres(), 0, null, [0, 3]));
    }

    public function testLesNomsEnCapitalesSontRemisEnCasse(): void
    {
        self::assertSame('Diaby', ImportMembres::casse('DIABY'));
        self::assertSame('Boye Lille', ImportMembres::casse('BOYE LILLE'));
        self::assertSame('Jean-Pierre', ImportMembres::casse('JEAN-PIERRE'));
        self::assertSame('Aïssata', ImportMembres::casse('AÏSSATA'));
        self::assertSame('McDonald', ImportMembres::casse('McDonald'), 'Une casse mixte est respectée.');
        self::assertSame('diaby', ImportMembres::casse('diaby'), 'Les minuscules aussi.');
        self::assertSame('06 12', ImportMembres::casse('06 12'), 'Sans lettre, rien ne change.');
    }

    public function testLesColonnesSontReconnuesParLeursEnTetes(): void
    {
        self::assertSame(['nom' => 0, 'prenom' => 1, 'telephone' => 2, 'foyer' => 3], ImportMembres::colonnesDetectees(['NOM DE FAMILLE', ' Prénoms', 'Tél.', 'Ménage', 'Remarques']));
        self::assertSame([], ImportMembres::colonnesDetectees(['Colonne A', 'Colonne B']));
        self::assertSame(['nom' => 1, 'prenom' => 2, 'localite' => 3, 'annee' => 4, 'age' => 5, 'mois_1' => 6], ImportMembres::colonnesDetectees(['N°', 'Noms', 'Prénoms', 'Villes', 'Année', 'AGE', 'JANVIER']), 'Les colonnes du classeur des villes.');
        self::assertSame(2019, ImportMembres::annee('2019'));
        self::assertSame(2019, ImportMembres::annee('2 019.0'));
        self::assertNull(ImportMembres::annee('JANVIER'));
        self::assertNull(ImportMembres::annee('1850'));
        self::assertSame(1985, ImportMembres::anneeNaissance('34', 2019));
        self::assertNull(ImportMembres::anneeNaissance('', 2019));
        self::assertNull(ImportMembres::anneeNaissance('200', 2019));
    }

    public function testLesMoisLeRapatriementEtLeProjetDuClasseurSontReconnus(): void
    {
        self::assertSame(
            ['nom' => 0, 'prenom' => 1, 'mois_1' => 2, 'mois_2' => 3, 'mois_8' => 4, 'mois_12' => 5, 'rapatriement' => 6, 'projet' => 7],
            ImportMembres::colonnesDetectees(['Noms', 'Prénoms', 'JANVIER', 'FÉVRIER', 'AOÛT', 'DÉCEMBRE', 'RAPATRIEMENT', 'PROJET 200€', 'Total', 'RESTE']),
            'Total et RESTE ne s’importent pas : ils se calculent.',
        );
        self::assertSame(1000, ImportMembres::caseMois('10'));
        self::assertSame(1000, ImportMembres::caseMois('1O'), 'La lettre O tapée pour un zéro.');
        self::assertSame(550, ImportMembres::caseMois('5,5'));
        self::assertSame(0, ImportMembres::caseMois('0'));
        self::assertSame('V', ImportMembres::caseMois('v'));
        self::assertSame('SPR', ImportMembres::caseMois(' spr '));
        self::assertNull(ImportMembres::caseMois(''));
        self::assertNull(ImportMembres::caseMois('-'));
        self::assertSame(20000, ImportMembres::montant('200 €'));
        self::assertNull(ImportMembres::montant('X'));
    }

    public function testLeTexteEnrichiDesChainesPartageesEstConcatene(): void
    {
        $classeur = ClasseurExcel::construire(
            chaines: [],
            feuille: '<row r="1"><c r="A1" t="s"><v>0</v></c></row>',
            chainesBrutes: '<si><r><rPr><b/></rPr><t>Pré</t></r><r><t>nom</t></r></si>',
        );

        self::assertSame([['Prénom']], ImportMembres::lire($classeur));
    }

    public function testUnClasseurCorrompuEstSignale(): void
    {
        $this->expectException(\RuntimeException::class);

        LecteurXlsx::lire("PK\x03\x04ceci n'est pas une archive");
    }
}
