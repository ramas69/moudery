<?php

declare(strict_types=1);

namespace App\Ville;

/**
 * Lit une feuille d'un classeur Excel (.xlsx, Office Open XML) sans bibliothèque : le fichier est une archive
 * ZIP dont on parcourt le classeur, ses relations, les chaînes partagées et la feuille. La feuille est lue en flux
 * (XMLReader), ligne par ligne, pour tenir sur des classeurs de plusieurs milliers de lignes et de centaines de
 * colonnes. Les cellules reviennent en texte, à la position de leur colonne (les cellules vides omises par Excel
 * sont comblées). Les nombres sont rendus sans notation scientifique : un téléphone saisi comme nombre garde ses chiffres.
 */
final class LecteurXlsx
{
    private const string SIGNATURE_ZIP = "PK\x03\x04";
    private const string NS_FEUILLE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const string NS_RELATIONS_DOC = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const string NS_RELATIONS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public static function estUnClasseur(string $contenu): bool
    {
        return str_starts_with($contenu, self::SIGNATURE_ZIP);
    }

    /**
     * Les feuilles du classeur, dans l'ordre des onglets, avec leur nombre de lignes : celui que déclare la feuille
     * (sa « dimension », qui peut compter quelques lignes vides en fin), sinon les lignes non vides comptées.
     *
     * @return list<array{nom: string, lignes: int}>
     *
     * @throws \RuntimeException si le fichier n'est pas un classeur lisible
     */
    public static function feuilles(string $contenu): array
    {
        return self::avecArchive($contenu, static function (\ZipArchive $archive, string $chemin): array {
            $feuilles = [];
            $chaines = null;
            foreach (self::listeFeuilles($archive) as $feuille) {
                $lignes = self::dimension($chemin, $feuille['chemin']);
                if (null === $lignes) {
                    $chaines ??= self::chainesPartagees($archive, $chemin);
                    $lignes = \count(self::lignes($chemin, $feuille['chemin'], $chaines, null));
                }
                $feuilles[] = ['nom' => $feuille['nom'], 'lignes' => $lignes];
            }

            return $feuilles;
        });
    }

    /**
     * @param int            $feuille l'indice de la feuille, dans l'ordre des onglets
     * @param int|null       $maximum s'arrêter après ce nombre de lignes non vides
     * @param list<int>|null $garder  ne garder que ces colonnes (indices d'origine conservés) : une feuille de suivi peut en
     *                                compter des centaines, une analyse n'en lit que quelques-unes ; les lignes vides restent
     *                                jugées sur toutes leurs cellules, la numérotation ne bouge pas
     *
     * @return list<array<int, ?string>> les lignes de la feuille, sans les lignes entièrement vides
     *
     * @throws \RuntimeException si le fichier n'est pas un classeur lisible ou si la feuille n'existe pas
     */
    public static function lire(string $contenu, int $feuille = 0, ?int $maximum = null, ?array $garder = null): array
    {
        return self::avecArchive($contenu, static function (\ZipArchive $archive, string $chemin) use ($feuille, $maximum, $garder): array {
            $feuilles = self::listeFeuilles($archive);
            if (!isset($feuilles[$feuille])) {
                throw new \RuntimeException(\sprintf('Le classeur n’a pas de feuille n° %d.', $feuille + 1));
            }

            return self::lignes($chemin, $feuilles[$feuille]['chemin'], self::chainesPartagees($archive, $chemin), $maximum, $garder);
        });
    }

    /**
     * @template T
     *
     * @param callable(\ZipArchive, string): T $action reçoit l'archive ouverte et le chemin du fichier temporaire
     *
     * @return T
     */
    private static function avecArchive(string $contenu, callable $action): mixed
    {
        if (!self::estUnClasseur($contenu)) {
            throw new \RuntimeException('Ce fichier n’est pas un classeur Excel (.xlsx).');
        }

        $chemin = tempnam(sys_get_temp_dir(), 'xlsx');
        if (false === $chemin) {
            throw new \RuntimeException('Impossible de créer un fichier temporaire.');
        }

        try {
            file_put_contents($chemin, $contenu);
            $archive = new \ZipArchive();
            if (true !== $archive->open($chemin, \ZipArchive::RDONLY)) {
                throw new \RuntimeException('Le classeur ne s’ouvre pas.');
            }

            try {
                return $action($archive, $chemin);
            } finally {
                $archive->close();
            }
        } finally {
            @unlink($chemin);
        }
    }

    /**
     * Les feuilles déclarées par le classeur, dans l'ordre des onglets, via ses relations ; à défaut, la feuille 1.
     *
     * @return list<array{nom: string, chemin: string}>
     */
    private static function listeFeuilles(\ZipArchive $archive): array
    {
        $defaut = [['nom' => 'Feuille 1', 'chemin' => 'xl/worksheets/sheet1.xml']];
        if (false === $archive->locateName('xl/workbook.xml') || false === $archive->locateName('xl/_rels/workbook.xml.rels')) {
            return $defaut;
        }

        $cibles = [];
        foreach (self::xml($archive, 'xl/_rels/workbook.xml.rels')->children(self::NS_RELATIONS)->Relationship as $relation) {
            // Après children(ns), $element['x'] chercherait un attribut dans cet espace de noms : attributes() lit les attributs sans espace.
            $attributs = $relation->attributes();
            $cible = (string) ($attributs['Target'] ?? '');
            $cibles[(string) ($attributs['Id'] ?? '')] = str_starts_with($cible, '/') ? ltrim($cible, '/') : 'xl/'.$cible;
        }

        $feuilles = [];
        $classeur = self::xml($archive, 'xl/workbook.xml');
        foreach ($classeur->children(self::NS_FEUILLE)->sheets->sheet ?? [] as $feuille) {
            $identifiant = (string) ($feuille->attributes(self::NS_RELATIONS_DOC)->id ?? '');
            $chemin = $cibles[$identifiant] ?? null;
            if (null === $chemin || false === $archive->locateName($chemin)) {
                continue;
            }
            $nom = trim((string) ($feuille->attributes()['name'] ?? ''));
            $feuilles[] = ['nom' => '' !== $nom ? $nom : \sprintf('Feuille %d', \count($feuilles) + 1), 'chemin' => $chemin];
        }

        return [] === $feuilles ? $defaut : $feuilles;
    }

    /** @return list<string> */
    private static function chainesPartagees(\ZipArchive $archive, string $cheminArchive): array
    {
        if (false === $archive->locateName('xl/sharedStrings.xml')) {
            return [];
        }

        $chaines = [];
        self::parcourir($cheminArchive, 'xl/sharedStrings.xml', 'si', static function (\SimpleXMLElement $element) use (&$chaines): bool {
            $chaines[] = self::texte($element);

            return true;
        });

        return $chaines;
    }

    /** Le nombre de lignes que déclare la feuille (« A1:WJ2638 » donne 2638), ou null si elle ne le déclare pas. */
    private static function dimension(string $cheminArchive, string $cheminFeuille): ?int
    {
        $lecteur = self::ouvrir($cheminArchive, $cheminFeuille);
        try {
            while ($lecteur->read()) {
                if (\XMLReader::ELEMENT !== $lecteur->nodeType) {
                    continue;
                }
                if ('dimension' === $lecteur->localName) {
                    $reference = (string) $lecteur->getAttribute('ref');

                    return preg_match('/:[A-Z]+(\d+)$/i', $reference, $m) ? (int) $m[1] : (preg_match('/^[A-Z]+(\d+)$/i', $reference, $m) ? (int) $m[1] : null);
                }
                if ('sheetData' === $lecteur->localName) {
                    return null;
                }
            }
        } finally {
            $lecteur->close();
        }

        return null;
    }

    /**
     * @param list<string> $chaines
     *
     * @return list<list<?string>>
     */
    private static function lignes(string $cheminArchive, string $cheminFeuille, array $chaines, ?int $maximum, ?array $garder = null): array
    {
        $lignes = [];
        $garder = null === $garder ? null : array_flip($garder);
        self::parcourir($cheminArchive, $cheminFeuille, 'row', static function (\SimpleXMLElement $rangee) use (&$lignes, $chaines, $maximum, $garder): bool {
            $ligne = self::ligne($rangee, $chaines);
            if (null !== $ligne) {
                $lignes[] = null === $garder ? $ligne : array_intersect_key($ligne, $garder);
            }

            return null === $maximum || \count($lignes) < $maximum;
        });

        return $lignes;
    }

    /**
     * @param list<string> $chaines
     *
     * @return list<?string>|null null pour une ligne entièrement vide
     */
    private static function ligne(\SimpleXMLElement $rangee, array $chaines): ?array
    {
        $cellules = [];
        $colonneSuivante = 0;
        foreach ($rangee->children(self::NS_FEUILLE)->c as $cellule) {
            $reference = (string) ($cellule->attributes()['r'] ?? '');
            $colonne = '' !== $reference ? self::indiceColonne($reference) : $colonneSuivante;
            while (\count($cellules) < $colonne) {
                $cellules[] = null;
            }
            $cellules[$colonne] = self::valeur($cellule, $chaines);
            $colonneSuivante = $colonne + 1;
        }
        if ([] === array_filter($cellules, static fn (?string $v): bool => null !== $v && '' !== trim($v))) {
            return null;
        }

        return array_values($cellules);
    }

    /** @param list<string> $chaines */
    private static function valeur(\SimpleXMLElement $cellule, array $chaines): ?string
    {
        $type = (string) ($cellule->attributes()['t'] ?? '');
        $enfants = $cellule->children(self::NS_FEUILLE);

        if ('inlineStr' === $type) {
            return isset($enfants->is) ? self::texte($enfants->is) : null;
        }
        if (!isset($enfants->v)) {
            return null;
        }
        $brut = (string) $enfants->v;

        return match ($type) {
            's' => $chaines[(int) $brut] ?? null,
            'b' => '0' === $brut ? 'faux' : 'vrai',
            'str', 'e' => $brut,
            default => self::nombre($brut),
        };
    }

    /** « 6.12345678E8 » redevient « 612345678 » ; un entier reste un entier, un décimal garde ses décimales utiles. */
    private static function nombre(string $brut): string
    {
        if (!is_numeric($brut)) {
            return $brut;
        }
        if (preg_match('/^-?\d+$/', $brut)) {
            return $brut;
        }
        $nombre = (float) $brut;
        if (floor($nombre) === $nombre && abs($nombre) < 1e15) {
            return number_format($nombre, 0, '', '');
        }

        return rtrim(rtrim(number_format($nombre, 10, '.', ''), '0'), '.');
    }

    /** Le texte d'une chaîne, y compris en texte enrichi (plusieurs <r><t>). */
    private static function texte(\SimpleXMLElement $element): string
    {
        $enfants = $element->children(self::NS_FEUILLE);
        if (isset($enfants->t) && 0 === \count($enfants->r)) {
            return (string) $enfants->t;
        }
        $texte = '';
        foreach ($enfants->r as $run) {
            $texte .= (string) $run->children(self::NS_FEUILLE)->t;
        }

        return $texte;
    }

    /** « A1 » donne 0, « AB12 » donne 27. */
    private static function indiceColonne(string $reference): int
    {
        $lettres = (string) preg_replace('/\d+$/', '', strtoupper($reference));
        $indice = 0;
        foreach (str_split($lettres) as $lettre) {
            $indice = $indice * 26 + (\ord($lettre) - 64);
        }

        return max(0, $indice - 1);
    }

    /**
     * Parcourt en flux les éléments d'un nom donné (à tout niveau) d'un fichier de l'archive ; l'action rend false pour arrêter.
     * Après next(), qui saute à l'élément frère suivant, on n'appelle pas read() : il descendrait dans cet élément et le raterait.
     *
     * @param callable(\SimpleXMLElement): bool $action
     */
    private static function parcourir(string $cheminArchive, string $cheminFichier, string $nom, callable $action): void
    {
        $lecteur = self::ouvrir($cheminArchive, $cheminFichier);
        try {
            $avancer = $lecteur->read();
            while ($avancer) {
                if (\XMLReader::ELEMENT === $lecteur->nodeType && $nom === $lecteur->localName) {
                    if (!$action(self::elementCourant($lecteur))) {
                        return;
                    }
                    $avancer = $lecteur->next();
                    continue;
                }
                $avancer = $lecteur->read();
            }
        } finally {
            $lecteur->close();
        }
    }

    /** Un lecteur en flux sur un fichier de l'archive, via le flux zip:// de PHP. */
    private static function ouvrir(string $cheminArchive, string $cheminFichier): \XMLReader
    {
        $lecteur = new \XMLReader();
        $precedent = libxml_use_internal_errors(true);
        $ouvert = $lecteur->open('zip://'.$cheminArchive.'#'.$cheminFichier, null, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($precedent);
        if (!$ouvert) {
            throw new \RuntimeException(\sprintf('Le classeur ne contient pas « %s ».', $cheminFichier));
        }

        return $lecteur;
    }

    /** L'élément courant du lecteur, copié en SimpleXML pour le parcourir. */
    private static function elementCourant(\XMLReader $lecteur): \SimpleXMLElement
    {
        $document = new \DOMDocument();
        $precedent = libxml_use_internal_errors(true);
        try {
            $noeud = $lecteur->expand($document);
            $element = false === $noeud ? false : simplexml_import_dom($document->importNode($noeud, true));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($precedent);
        }
        if (false === $element || null === $element) {
            throw new \RuntimeException('La feuille n’est pas un XML valide.');
        }

        return $element;
    }

    private static function xml(\ZipArchive $archive, string $chemin): \SimpleXMLElement
    {
        $contenu = $archive->getFromName($chemin);
        if (false === $contenu) {
            throw new \RuntimeException(\sprintf('Le classeur ne contient pas « %s ».', $chemin));
        }
        $precedent = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contenu, \SimpleXMLElement::class, \LIBXML_NONET | \LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($precedent);
        }
        if (false === $xml) {
            throw new \RuntimeException(\sprintf('« %s » n’est pas un XML valide.', $chemin));
        }

        return $xml;
    }
}
