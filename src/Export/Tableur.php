<?php

declare(strict_types=1);

namespace App\Export;

use Symfony\Component\HttpFoundation\Response;

/**
 * Les exports tableur (F-29) : le même tableau (en-têtes, lignes) en CSV (point-virgule, BOM UTF-8, pour Excel en
 * français) ou en XLSX (classeur minimal écrit sans bibliothèque : une feuille, chaînes en ligne, nombres typés).
 * Une cellule int ou float devient un nombre dans le classeur et s'écrit avec une virgule dans le CSV ; une chaîne
 * reste telle quelle ; null donne une cellule vide.
 */
final class Tableur
{
    public const string CSV = 'csv';
    public const string XLSX = 'xlsx';
    public const array FORMATS = [self::CSV, self::XLSX];

    /**
     * @param list<string>                            $enTetes
     * @param list<list<int|float|string|null>>       $lignes
     */
    public function reponse(string $format, string $nomSansExtension, array $enTetes, array $lignes, string $feuille = 'Export'): Response
    {
        if (self::XLSX === $format) {
            return new Response(self::xlsx($enTetes, $lignes, $feuille), Response::HTTP_OK, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => \sprintf('attachment; filename="%s.xlsx"', $nomSansExtension),
            ]);
        }

        return new Response(self::csv($enTetes, $lignes), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="%s.csv"', $nomSansExtension),
        ]);
    }

    /**
     * @param list<string>                      $enTetes
     * @param list<list<int|float|string|null>> $lignes
     */
    public static function csv(array $enTetes, array $lignes): string
    {
        $flux = fopen('php://temp', 'r+');
        \assert(false !== $flux);
        fwrite($flux, "\u{FEFF}");
        fputcsv($flux, $enTetes, ';', '"', '');
        foreach ($lignes as $ligne) {
            fputcsv($flux, array_map(static fn (int|float|string|null $v): string => match (true) {
                null === $v => '',
                \is_int($v) => (string) $v,
                \is_float($v) => number_format($v, 2, ',', ''),
                default => $v,
            }, $ligne), ';', '"', '');
        }
        rewind($flux);
        $contenu = (string) stream_get_contents($flux);
        fclose($flux);

        return $contenu;
    }

    /**
     * Un classeur .xlsx minimal : [Content_Types], relations, classeur, une feuille, styles (un format monétaire
     * pour les flottants). Lisible par Excel, LibreOffice, Numbers et notre propre LecteurXlsx.
     *
     * @param list<string>                      $enTetes
     * @param list<list<int|float|string|null>> $lignes
     */
    public static function xlsx(array $enTetes, array $lignes, string $feuille = 'Export'): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'xlsx');
        if (false === $chemin) {
            throw new \RuntimeException('Impossible de créer le classeur temporaire.');
        }
        $archive = new \ZipArchive();
        if (true !== $archive->open($chemin, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Impossible d\'ouvrir le classeur temporaire.');
        }

        $rangees = [];
        $rangees[] = self::rangee(1, array_map(static fn (string $t): string => $t, $enTetes), true);
        foreach ($lignes as $i => $ligne) {
            $rangees[] = self::rangee($i + 2, $ligne, false);
        }
        $nombreColonnes = max(1, \count($enTetes));
        $derniere = self::colonne($nombreColonnes).(\count($lignes) + 1);
        $feuilleXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="A1:'.$derniere.'"/>'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols><col min="1" max="'.$nombreColonnes.'" width="18" customWidth="1"/></cols>'
            .'<sheetData>'.implode('', $rangees).'</sheetData>'
            .'</worksheet>';
        $nomFeuille = htmlspecialchars(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $feuille) ?? 'Export', 0, 31), \ENT_XML1);

        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $archive->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$nomFeuille.'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $archive->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" applyFont="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $archive->addFromString('xl/worksheets/sheet1.xml', $feuilleXml);
        $archive->close();

        $contenu = (string) file_get_contents($chemin);
        unlink($chemin);

        return $contenu;
    }

    /** @param list<int|float|string|null> $cellules */
    private static function rangee(int $numero, array $cellules, bool $enTete): string
    {
        $xml = '<row r="'.$numero.'">';
        foreach (array_values($cellules) as $i => $valeur) {
            $ref = self::colonne($i + 1).$numero;
            if (null === $valeur || '' === $valeur) {
                continue;
            }
            if (\is_int($valeur)) {
                $xml .= '<c r="'.$ref.'"><v>'.$valeur.'</v></c>';
            } elseif (\is_float($valeur)) {
                $xml .= '<c r="'.$ref.'" s="2"><v>'.rtrim(rtrim(number_format($valeur, 2, '.', ''), '0'), '.').'</v></c>';
            } else {
                $xml .= '<c r="'.$ref.'" t="inlineStr"'.($enTete ? ' s="1"' : '').'><is><t xml:space="preserve">'.htmlspecialchars($valeur, \ENT_XML1 | \ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
        }

        return $xml.'</row>';
    }

    /** 1 donne A, 27 donne AA. */
    public static function colonne(int $indice): string
    {
        $lettres = '';
        while ($indice > 0) {
            $reste = ($indice - 1) % 26;
            $lettres = \chr(65 + $reste).$lettres;
            $indice = intdiv($indice - 1, 26);
        }

        return $lettres;
    }
}
