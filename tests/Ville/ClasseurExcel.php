<?php

declare(strict_types=1);

namespace App\Tests\Ville;

/** Fabrique un classeur .xlsx minimal tel qu'Excel l'écrit : classeur, relations, chaînes partagées, deux feuilles. */
final class ClasseurExcel
{
    /**
     * @param list<string> $chaines       les chaînes partagées, référencées par indice dans la feuille
     * @param string       $feuille       les <row> de la première feuille
     * @param string|null  $chainesBrutes le contenu de sharedStrings.xml quand il faut le maîtriser (texte enrichi)
     */
    public static function construire(array $chaines, string $feuille, ?string $chainesBrutes = null, string $feuille2 = '<row r="1"><c r="A1" t="inlineStr"><is><t>Mauvaise feuille</t></is></c></row>'): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'classeur');
        \assert(false !== $chemin);
        $archive = new \ZipArchive();
        if (true !== $archive->open($chemin, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Impossible de créer le classeur de test.');
        }
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Membres" sheetId="1" r:id="rId3"/><sheet name="Autre" sheetId="2" r:id="rId4"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $chainesXml = $chainesBrutes ?? implode('', array_map(static fn (string $c): string => '<si><t>'.htmlspecialchars($c, \ENT_XML1).'</t></si>', $chaines));
        $archive->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$chainesXml.'</sst>');
        $archive->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$feuille.'</sheetData></worksheet>');
        $archive->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$feuille2.'</sheetData></worksheet>');
        $archive->close();
        $contenu = (string) file_get_contents($chemin);
        unlink($chemin);

        return $contenu;
    }

    private const array CHAINES_MEMBRES = ['Prénom', 'Nom', 'E-mail', 'Téléphone', 'Foyer', 'Mamadou', 'Diaby', 'mamadou@example.org', 'Famille Diaby', 'Awa', 'Cissé'];

    private const string FEUILLE_MEMBRES = '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c><c r="E1" t="s"><v>4</v></c></row>'
        .'<row r="2"><c r="A2" t="s"><v>5</v></c><c r="B2" t="s"><v>6</v></c><c r="C2" t="s"><v>7</v></c><c r="D2"><v>612345678</v></c><c r="E2" t="s"><v>8</v></c></row>'
        .'<row r="3"><c r="A3" t="s"><v>9</v></c><c r="B3" t="s"><v>10</v></c><c r="D3"><v>6.12345679E8</v></c></row>'
        .'<row r="4"><c r="A4" t="inlineStr"><is><t>Seydou</t></is></c><c r="B4" t="str"><v>Sylla</v></c></row>'
        .'<row r="5"><c r="A5"/><c r="B5"/></row>';

    /**
     * Un classeur d'association comme le classeur réel : un onglet « Synthèse » sans liste, puis un onglet par ville
     * (« Lyon », « Marseille ») avec un titre au-dessus des en-têtes, une ligne par membre et par année, des capitales.
     *
     * @param array<string, list<list<string>>> $villes nom d'onglet => lignes (chaque ligne : nom, prénom, année, localité)
     */
    public static function association(array $villes = [], string $premierOnglet = 'Synthèse'): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'classeur');
        \assert(false !== $chemin);
        $archive = new \ZipArchive();
        if (true !== $archive->open($chemin, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Impossible de créer le classeur de test.');
        }
        $noms = [$premierOnglet, ...array_keys($villes)];
        $feuilles = '';
        $relations = '';
        foreach ($noms as $i => $nom) {
            $feuilles .= \sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"/>', htmlspecialchars($nom, \ENT_XML1), $i + 1, $i + 10);
            $relations .= \sprintf('<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>', $i + 10, $i + 1);
        }
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$feuilles.'</sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>');

        $cellule = static fn (string $ref, string $valeur): string => \sprintf('<c r="%s" t="inlineStr"><is><t>%s</t></is></c>', $ref, htmlspecialchars($valeur, \ENT_XML1));
        $archive->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">'.$cellule('A1', 'Total des cotisations').$cellule('B1', '12 500').'</row></sheetData></worksheet>');
        $numero = 2;
        foreach ($villes as $nom => $lignes) {
            $rangees = '<row r="1">'.$cellule('G1', 'CAISSE DE MOUDERI '.mb_strtoupper($nom)).'</row>';
            $rangees .= '<row r="2">'.$cellule('A2', 'N°').$cellule('B2', 'Noms').$cellule('C2', 'Prénoms').$cellule('D2', 'Villes').$cellule('E2', 'Année').$cellule('F2', 'JANVIER').'</row>';
            foreach ($lignes as $i => [$nomFamille, $prenom, $annee, $localite]) {
                $r = $i + 3;
                $rangees .= '<row r="'.$r.'">'.$cellule('A'.$r, (string) ($i + 1)).$cellule('B'.$r, $nomFamille).$cellule('C'.$r, $prenom).$cellule('D'.$r, $localite).$cellule('E'.$r, $annee).$cellule('F'.$r, '10').'</row>';
            }
            $archive->addFromString('xl/worksheets/sheet'.$numero.'.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rangees.'</sheetData></worksheet>');
            ++$numero;
        }
        $archive->close();
        $contenu = (string) file_get_contents($chemin);
        unlink($chemin);

        return $contenu;
    }

    /** Un classeur de membres prêt à importer : en-têtes, un téléphone saisi comme nombre (zéro de tête perdu), une ligne en texte en ligne. */
    public static function membres(): string
    {
        return self::construire(chaines: self::CHAINES_MEMBRES, feuille: self::FEUILLE_MEMBRES);
    }

    /** Le même classeur, mais les membres sont sur la deuxième feuille et la première ne contient qu'une note. */
    public static function membresEnDeuxiemeFeuille(): string
    {
        return self::construire(
            chaines: self::CHAINES_MEMBRES,
            feuille: '<row r="1"><c r="A1" t="inlineStr"><is><t>Notes de réunion</t></is></c></row>',
            feuille2: self::FEUILLE_MEMBRES,
        );
    }
}
