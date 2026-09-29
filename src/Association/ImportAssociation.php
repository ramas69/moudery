<?php

declare(strict_types=1);

namespace App\Association;

use App\Administration\Texte;
use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Journal\Journal;
use App\Repository\VilleRepository;
use App\Ville\ImportMembres;
use App\Ville\ReglagesAnalyse;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import d'un fichier pour toute l'association (F-31) : un classeur déposé une fois, chaque onglet (ou chaque valeur
 * d'une colonne « caisse ») rattaché à une ville existante, à créer en brouillon ou à ignorer, un bilan par ville.
 * Rien n'est propre à un village : les onglets, la ligne d'en-têtes et les colonnes sont reconnus d'après le fichier
 * et le dictionnaire que l'association a appris de ses imports précédents.
 *
 * @phpstan-type Feuille array{indice: int, nom: ?string, lignes: int, ligneEnTete: int, enTetes: list<array{indice: int, libelle: string}>, colonnes: array<string, int>, importable: bool, villeProposee: ?int, colonneCaisse: ?int}
 * @phpstan-type Cible array{feuille: int, ligneEnTete: int, filtre: ?array{colonne: int, valeur: string}, libelle: string, ville: ?int, creer: ?string}
 */
final class ImportAssociation
{
    public const string MODE_ONGLETS = 'onglets';
    public const string MODE_COLONNE = 'colonne';
    public const array MODES = [self::MODE_ONGLETS, self::MODE_COLONNE];

    /** Choix de rattachement d'un onglet ou d'une valeur : ignorer, créer la ville, ou « ville:<id> ». */
    public const string CIBLE_IGNORER = '';
    public const string CIBLE_CREER = 'creer';

    /** Les en-têtes qui désignent d'ordinaire la caisse d'une ligne, sans accents ni casse. */
    public const array EN_TETES_CAISSE = ['caisse', 'caisses', 'ville', 'villes', 'section', 'sections', 'antenne', 'antennes', 'branche', 'commune', 'city'];

    /** Erreurs montrées par ville dans l'aperçu, au plus. */
    public const int ERREURS_MONTREES = 30;

    /** Colonnes proposées comme colonne « caisse », au plus. */
    public const int COLONNES_MAXIMUM = 80;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VilleRepository $villes,
        private readonly ImportMembres $import,
        private readonly Journal $journal,
    ) {
    }

    /**
     * Reconnaît chaque feuille du fichier : sa ligne d'en-têtes, ses colonnes, si elle liste des membres, la ville
     * de l'association qui porte son nom, et la colonne qui ressemble à une caisse.
     *
     * @return list<Feuille>
     *
     * @throws \RuntimeException si le fichier est illisible
     */
    public function reconnaitre(string $contenu, Association $association): array
    {
        $dictionnaire = $association->getDictionnaireImport();
        $colonneMemorisee = $association->getReglagesImport()['colonne_caisse'] ?? null;
        $villes = $this->villes->listerPourAssociation($association);

        $feuilles = [];
        foreach (ImportMembres::feuilles($contenu) as $indice => $feuille) {
            $lignes = ImportMembres::lire($contenu, $indice, ImportMembres::LIGNES_EN_TETE_MAXIMUM + 1);
            $enTeteIndice = ImportMembres::ligneEnTete($lignes, $dictionnaire);
            $ligneEnTete = null === $enTeteIndice ? 0 : $enTeteIndice + 1;
            $enTetes = $ligneEnTete > 0 ? $lignes[$ligneEnTete - 1] : [];
            $colonnes = ImportMembres::colonnesDetectees($enTetes, $dictionnaire);

            $feuilles[] = [
                'indice' => $indice,
                'nom' => $feuille['nom'],
                'lignes' => $feuille['lignes'],
                'ligneEnTete' => $ligneEnTete,
                'enTetes' => self::enTetesUtiles($enTetes),
                'colonnes' => $colonnes,
                'importable' => ImportMembres::identifient($colonnes) && \count($lignes) > $ligneEnTete,
                'villeProposee' => self::villeCorrespondante($feuille['nom'], $villes)?->getId(),
                'colonneCaisse' => self::colonneCaisse($enTetes, \is_string($colonneMemorisee) ? $colonneMemorisee : null),
            ];
        }

        return $feuilles;
    }

    /**
     * Les valeurs distinctes d'une colonne « caisse » d'une feuille, avec leur nombre de lignes et la ville qui porte ce nom.
     *
     * @return list<array{valeur: string, lignes: int, villeProposee: ?int}>
     */
    public function valeursCaisse(string $contenu, Association $association, int $feuille, int $ligneEnTete, int $colonne): array
    {
        $villes = $this->villes->listerPourAssociation($association);
        $valeurs = [];
        foreach (\array_slice(ImportMembres::lire($contenu, $feuille, null, [$colonne]), $ligneEnTete) as $cellules) {
            $brut = trim((string) ($cellules[$colonne] ?? ''));
            if ('' === $brut) {
                continue;
            }
            $cle = Texte::normaliser($brut);
            $valeurs[$cle] ??= ['valeur' => ImportMembres::casse($brut), 'lignes' => 0, 'villeProposee' => self::villeCorrespondante($brut, $villes)?->getId()];
            ++$valeurs[$cle]['lignes'];
        }
        ksort($valeurs);

        return array_values($valeurs);
    }

    /**
     * Analyse chaque cible sans rien enregistrer : un résumé par ville, avec les premières lignes en erreur.
     *
     * @param list<Cible> $cibles
     *
     * @return list<array{libelle: string, ville: ?string, creer: ?string, valides: int, adhesions: int, invalides: int, ignorees: int, erreur: ?string, erreurs: list<array{numero: int, prenom: string, nom: string, erreurs: list<string>, reference: ?int}>}>
     */
    public function analyser(string $contenu, Association $association, array $cibles): array
    {
        $resumes = [];
        foreach ($cibles as $cible) {
            $ville = $this->villePour($association, $cible);
            $analyse = $this->import->analyser($contenu, $ville, $this->reglagesPour($association, $cible));
            $erreurs = [];
            foreach ($analyse['lignes'] as $ligne) {
                if ('erreur' === $ligne['etat']) {
                    $erreurs[] = ['numero' => $ligne['numero'], 'prenom' => $ligne['prenom'], 'nom' => $ligne['nom'], 'erreurs' => $ligne['erreurs'], 'reference' => $ligne['reference']];
                    if (\count($erreurs) >= self::ERREURS_MONTREES) {
                        break;
                    }
                }
            }
            $resumes[] = [
                'libelle' => $cible['libelle'],
                'ville' => $ville->getNom(),
                'creer' => $cible['creer'],
                'valides' => $analyse['valides'],
                'adhesions' => $analyse['adhesions'],
                'invalides' => $analyse['invalides'],
                'ignorees' => $analyse['ignorees'],
                'erreur' => $analyse['erreur'],
                'erreurs' => $erreurs,
            ];
        }

        return $resumes;
    }

    /**
     * Crée les villes manquantes en brouillon, importe chaque cible, puis retient les réglages pour la prochaine fois.
     *
     * @param list<Cible> $cibles
     *
     * @return list<array{libelle: string, ville: string, villeId: ?int, creee: bool, membres: int, adhesions: int, invalides: int, ignorees: int, erreur: ?string}>
     */
    public function executer(string $contenu, Association $association, array $cibles, ?Utilisateur $par, string $mode, ?string $colonneCaisse): array
    {
        $bilan = [];
        $dictionnaire = [];
        foreach ($cibles as $cible) {
            $ville = $this->villePour($association, $cible);
            $creee = false;
            if (null === $ville->getId()) {
                $ville->avancerA(EtapeAssistant::Membres);
                $this->entityManager->persist($ville);
                $this->journal->consigner(TypeEvenement::VilleCreee, $par, $association, $ville->getNom(), ['import' => true]);
                $this->entityManager->flush();
                $creee = true;
            }

            $analyse = $this->import->analyser($contenu, $ville, $this->reglagesPour($association, $cible));
            $resultat = ['membres' => 0, 'adhesions' => 0];
            if (null === $analyse['erreur']) {
                $resultat = $this->import->importer($ville, $analyse['lignes'], $par);
                $enTetes = [];
                foreach ($analyse['colonnes'] as $champ => $indice) {
                    $enTetes[$indice] = self::libelleEnTete($contenu, $cible, $indice);
                }
                $dictionnaire = [...$dictionnaire, ...ImportMembres::dictionnaire($enTetes, $analyse['colonnes'])];
            }

            $bilan[] = [
                'libelle' => $cible['libelle'],
                'ville' => $ville->getNom(),
                'villeId' => $ville->getId(),
                'creee' => $creee,
                'membres' => $resultat['membres'],
                'adhesions' => $resultat['adhesions'],
                'invalides' => $analyse['invalides'],
                'ignorees' => $analyse['ignorees'],
                'erreur' => $analyse['erreur'],
            ];
        }

        $association->memoriserReglagesImport(array_filter(['mode' => $mode, 'colonne_caisse' => $colonneCaisse, 'colonnes' => $dictionnaire], static fn ($v): bool => null !== $v));
        $this->entityManager->flush();

        return $bilan;
    }

    /** La ville de l'association dont le nom correspond, en ignorant accents, casse, espaces et traits d'union (« LEMANS » = « Le mans »). */
    public static function villeCorrespondante(?string $nom, array $villes): ?Ville
    {
        $cle = self::compact((string) $nom);
        if ('' === $cle) {
            return null;
        }
        foreach ($villes as $ville) {
            if (self::compact($ville->getNom()) === $cle) {
                return $ville;
            }
        }

        return null;
    }

    /** La colonne qui désigne la caisse : celle mémorisée par l'association, sinon la première dont l'en-tête y ressemble. */
    public static function colonneCaisse(array $enTetes, ?string $memorise): ?int
    {
        $memorise = null === $memorise ? null : Texte::normaliser($memorise);
        $candidat = null;
        foreach ($enTetes as $indice => $enTete) {
            $cle = Texte::normaliser((string) $enTete);
            if ('' !== $cle && $cle === $memorise) {
                return $indice;
            }
            if (null === $candidat && \in_array($cle, self::EN_TETES_CAISSE, true)) {
                $candidat = $indice;
            }
        }

        return $candidat;
    }

    /** @return list<array{indice: int, libelle: string}> les en-têtes non vides, dans la limite de COLONNES_MAXIMUM */
    private static function enTetesUtiles(array $enTetes): array
    {
        $utiles = [];
        foreach ($enTetes as $indice => $enTete) {
            $libelle = trim((string) $enTete);
            if ('' !== $libelle) {
                $utiles[] = ['indice' => $indice, 'libelle' => $libelle];
            }
            if (\count($utiles) >= self::COLONNES_MAXIMUM) {
                break;
            }
        }

        return $utiles;
    }

    private static function compact(string $texte): string
    {
        return (string) preg_replace('/[\s\-\']+/', '', Texte::normaliser($texte));
    }

    /** @param Cible $cible */
    private function villePour(Association $association, array $cible): Ville
    {
        if (null !== $cible['ville']) {
            $ville = $this->villes->find($cible['ville']);
            if ($ville instanceof Ville && $ville->getAssociation()->getId() === $association->getId()) {
                return $ville;
            }
        }
        $nom = trim((string) ($cible['creer'] ?? $cible['libelle']));

        // Le nom existe peut-être déjà (l'utilisateur a choisi « créer » pour un onglet qui a sa ville) : on la reprend.
        return $this->villes->trouverParNom($association, $nom) ?? new Ville($association, $nom);
    }

    /** @param Cible $cible */
    private function reglagesPour(Association $association, array $cible): ReglagesAnalyse
    {
        $ordre = $association->getReglagesImport()['ordre_nom'] ?? ReglagesAnalyse::ORDRE_NOM_PRENOM;

        return new ReglagesAnalyse(
            feuille: $cible['feuille'],
            ligneEnTete: $cible['ligneEnTete'],
            ordreNomComplet: \is_string($ordre) && \in_array($ordre, ReglagesAnalyse::ORDRES, true) ? $ordre : ReglagesAnalyse::ORDRE_NOM_PRENOM,
            filtreColonne: $cible['filtre']['colonne'] ?? null,
            filtreValeur: $cible['filtre']['valeur'] ?? null,
            dictionnaire: $association->getDictionnaireImport(),
        );
    }

    /** L'en-tête d'une colonne d'une cible, pour mémoriser ce que l'import a appris. */
    private static function libelleEnTete(string $contenu, array $cible, int $indice): ?string
    {
        static $cache = [];
        $cle = md5($contenu).'|'.$cible['feuille'];
        if (!isset($cache[$cle])) {
            $lignes = ImportMembres::lire($contenu, $cible['feuille'], max(1, $cible['ligneEnTete']));
            $cache[$cle] = $cible['ligneEnTete'] > 0 ? ($lignes[$cible['ligneEnTete'] - 1] ?? []) : [];
        }

        return $cache[$cle][$indice] ?? null;
    }
}
