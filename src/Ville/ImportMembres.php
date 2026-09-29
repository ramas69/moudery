<?php

declare(strict_types=1);

namespace App\Ville;

use App\Administration\Texte;
use App\Entity\Adhesion;
use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Journal\Journal;
use App\Repository\FoyerRepository;
use App\Repository\MembreRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import d'un fichier de membres (F-31, F-42), CSV ou classeur Excel (.xlsx, une feuille au choix) : les colonnes sont
 * reconnues par leur en-tête (prénom, nom, e-mail, téléphone, foyer, localité, année d'adhésion, âge), chaque ligne est
 * vérifiée (identité obligatoire, e-mail valide, pas de doublon dans le fichier ni dans la ville), l'aperçu montre les
 * erreurs ligne par ligne, puis seules les lignes valides sont importées.
 *
 * Chaque ligne est « prête », « en erreur » (identité manquante, e-mail invalide ou pris par une autre personne),
 * « adhésion » (la même personne plus haut dans le fichier ou déjà membre, mais avec une année d'adhésion nouvelle :
 * les classeurs des villes tiennent une ligne par membre et par année, on garde chaque année) ou « ignorée » (la même
 * personne sans année nouvelle, ou une ligne sans aucun nom : les classeurs gardent des lignes numérotées vides).
 *
 * Les douze mois, le rapatriement et le projet du classeur alimentent l'historique de l'adhésion (Adhesion) : un
 * montant en centimes, ou le code tel qu'il est tapé (« V », « X »…), jamais interprété.
 *
 * @phpstan-type Historique array{mois: array<int, int|string|null>, rapatriement: ?int, projet: ?int}
 * @phpstan-type Ligne array{numero: int, prenom: string, nom: string, email: ?string, telephone: ?string, foyer: ?string, localite: ?string, annee: ?int, anneeNaissance: ?int, historique: ?Historique, etat: 'prete'|'erreur'|'adhesion'|'ignoree', erreurs: list<string>, motif: ?string, reference: ?int}
 */
final class ImportMembres
{
    /** Nombre de lectures de fichier gardées en cache (voir {@see self::lire()}). */
    private const LECTURES_GARDEES = 6;

    /** @var array<string, list<array<int, ?string>>> */
    private static array $lectures = [];

    private static int $lecturesEnCache = 0;

    public const int LIGNES_MAXIMUM = 5000;

    /** On cherche la ligne d'en-têtes dans les premières lignes seulement. */
    public const int LIGNES_EN_TETE_MAXIMUM = 30;

    /** Les champs qu'une colonne du fichier peut alimenter ; « nom_complet » remplace prénom et nom quand le fichier n'a qu'une colonne. */
    public const array CHAMPS = ['prenom', 'nom', 'nom_complet', 'email', 'telephone', 'foyer', 'localite', 'annee', 'age', 'mois_1', 'mois_2', 'mois_3', 'mois_4', 'mois_5', 'mois_6', 'mois_7', 'mois_8', 'mois_9', 'mois_10', 'mois_11', 'mois_12', 'rapatriement', 'projet'];

    /** Les douze colonnes de mois du classeur, de janvier à décembre. */
    public const array MOIS = ['mois_1', 'mois_2', 'mois_3', 'mois_4', 'mois_5', 'mois_6', 'mois_7', 'mois_8', 'mois_9', 'mois_10', 'mois_11', 'mois_12'];

    /** Les en-têtes reconnus, sans accents ni casse, pour chaque colonne. Le dictionnaire mémorisé par l'association passe avant. */
    private const array EN_TETES = [
        'prenom' => ['prenom', 'prenoms', 'first name', 'firstname', 'given name'],
        'nom' => ['nom', 'noms', 'nom de famille', 'last name', 'lastname', 'surname', 'family name'],
        'nom_complet' => ['nom complet', 'noms complets', 'nom et prenom', 'nom et prenoms', 'noms et prenoms', 'nom prenom', 'noms prenoms', 'prenom nom', 'prenom et nom', 'prenoms et noms', 'nom & prenom', 'membre', 'membres', 'adherent', 'adherents', 'cotisant', 'cotisants', 'identite', 'full name', 'name'],
        'email' => ['email', 'e-mail', 'mail', 'courriel', 'adresse email', 'adresse e-mail', 'adresse mail'],
        'telephone' => ['telephone', 'tel', 'tel.', 'portable', 'mobile', 'phone', 'numero', 'numero de telephone', 'gsm'],
        'foyer' => ['foyer', 'famille', 'menage', 'household'],
        'localite' => ['villes', 'ville', 'localite', 'secteur', 'commune', 'lieu', 'quartier', 'city', 'town'],
        'annee' => ['annee', 'annees', 'exercice', 'saison', 'year'],
        'age' => ['age', 'ages'],
        'mois_1' => ['janvier', 'janv', 'janv.', 'jan', 'january'],
        'mois_2' => ['fevrier', 'fevr', 'fevr.', 'fev', 'fev.', 'feb', 'february'],
        'mois_3' => ['mars', 'mar', 'march'],
        'mois_4' => ['avril', 'avr', 'avr.', 'apr', 'april'],
        'mois_5' => ['mai', 'may'],
        'mois_6' => ['juin', 'jun', 'june'],
        'mois_7' => ['juillet', 'juil', 'juil.', 'jul', 'july'],
        'mois_8' => ['aout', 'aou', 'aou.', 'aug', 'august'],
        'mois_9' => ['septembre', 'sept', 'sept.', 'sep', 'september'],
        'mois_10' => ['octobre', 'oct', 'oct.', 'october'],
        'mois_11' => ['novembre', 'nov', 'nov.', 'november'],
        'mois_12' => ['decembre', 'dec', 'dec.', 'december'],
        'rapatriement' => ['rapatriement', 'rapat', 'rapat.', 'caisse de rapatriement', 'repatriation'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MembreRepository $membres,
        private readonly FoyerRepository $foyers,
        private readonly Membres $gestion,
        private readonly Journal $journal,
    ) {
    }

    /**
     * Lit le fichier et vérifie chaque ligne, sans rien enregistrer. Les réglages non renseignés sont détectés.
     *
     * @return array{colonnes: array<string, int>, lignes: list<Ligne>, valides: int, invalides: int, ignorees: int, adhesions: int, erreur: ?string}
     */
    public function analyser(string $contenu, Ville $ville, ?ReglagesAnalyse $reglages = null): array
    {
        $reglages ??= new ReglagesAnalyse();
        $vide = ['colonnes' => [], 'lignes' => [], 'valides' => 0, 'invalides' => 0, 'ignorees' => 0, 'adhesions' => 0, 'erreur' => null];
        try {
            // Deux passes : les premières lignes en entier pour trouver les en-têtes, puis tout le fichier réduit aux colonnes utiles.
            $extrait = self::lire($contenu, $reglages->feuille, self::LIGNES_EN_TETE_MAXIMUM + 1);
            if ([] === $extrait) {
                return [...$vide, 'erreur' => 'vide'];
            }
            $ligneEnTete = $reglages->ligneEnTete ?? (self::ligneEnTete($extrait, $reglages->dictionnaire) ?? -1) + 1;
            $enTetes = $ligneEnTete > 0 ? ($extrait[$ligneEnTete - 1] ?? []) : [];
            $colonnes = $reglages->colonnes ?? self::colonnesDetectees($enTetes, $reglages->dictionnaire);
            if (!self::identifient($colonnes)) {
                return [...$vide, 'colonnes' => $colonnes, 'erreur' => 'colonnes'];
            }
            $garder = array_values($colonnes);
            if (null !== $reglages->filtreColonne) {
                $garder[] = $reglages->filtreColonne;
            }
            $brutes = self::lire($contenu, $reglages->feuille, null, $garder);
        } catch (\RuntimeException) {
            return [...$vide, 'erreur' => 'illisible'];
        }
        $donnees = \array_slice($brutes, $ligneEnTete, null, true);
        if (null !== $reglages->filtreColonne) {
            // Une seule caisse parmi celles du fichier : les autres lignes n'existent pas pour cette analyse.
            $attendu = Texte::normaliser((string) $reglages->filtreValeur);
            $donnees = array_filter($donnees, static fn (array $cellules): bool => Texte::normaliser((string) ($cellules[$reglages->filtreColonne] ?? '')) === $attendu);
        }
        if (\count($donnees) > self::LIGNES_MAXIMUM) {
            return [...$vide, 'colonnes' => $colonnes, 'erreur' => 'trop_de_lignes'];
        }

        $reperes = $this->membres->reperesPourImport($ville);
        $emailsDuFichier = [];
        $personnesDuFichier = [];
        $anneesAjoutees = [];
        $lignes = [];
        foreach ($donnees as $indice => $cellules) {
            $numero = $indice + 1;
            $valeur = static fn (string $cle): ?string => isset($colonnes[$cle], $cellules[$colonnes[$cle]]) ? trim((string) $cellules[$colonnes[$cle]]) : null;

            $prenom = self::casse(Membre::normaliserNom((string) $valeur('prenom')));
            $nom = self::casse(Membre::normaliserNom((string) $valeur('nom')));
            if ('' === $prenom && '' === $nom && isset($colonnes['nom_complet'])) {
                [$prenom, $nom] = self::separerNomComplet((string) $valeur('nom_complet'), $reglages->ordreNomComplet);
            }
            // Le nom complet glissé dans une seule des deux colonnes (« DIABY Mamadou » dans Prénoms, Noms vide) : on le sépare.
            if (('' === $prenom) !== ('' === $nom) && str_contains('' === $prenom ? $nom : $prenom, ' ')) {
                [$prenom, $nom] = self::separerNomComplet('' === $prenom ? $nom : $prenom, $reglages->ordreNomComplet);
            }
            $email = Utilisateur::normaliserEmail($valeur('email'));
            $telephone = self::telephone($valeur('telephone'));
            $foyer = self::casse(Membre::normaliserNom((string) $valeur('foyer')));
            $localite = self::casse(Membre::normaliserNom((string) $valeur('localite')));
            $annee = self::annee($valeur('annee'));
            $anneeNaissance = self::anneeNaissance($valeur('age'), $annee);
            $historique = self::historique($valeur);
            $personne = mb_strtolower($prenom.'|'.$nom);
            $erreurs = [];
            $etat = null;
            $motif = null;
            $reference = null;

            if ('' === $prenom && '' === $nom) {
                // Une ligne numérotée sans personne (les classeurs gardent des lignes vides prêtes à remplir) : ignorée, pas en erreur.
                $motif = 'sans_identite';
            } elseif ('' === $prenom || '' === $nom) {
                $erreurs[] = 'identite';
            }
            if (null !== $email && false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $erreurs[] = 'email_invalide';
            }
            if ([] === $erreurs && null === $motif) {
                $memePersonne = isset($personnesDuFichier[$personne]) && (null === $email || $personnesDuFichier[$personne]['email'] === $email);
                $dejaMembre = !$memePersonne && \array_key_exists($personne, $reperes['noms']) && (null === $email || $reperes['noms'][$personne] === $email);

                if ($memePersonne) {
                    // La même personne plus haut dans le fichier : une ligne par année, par exemple. Une année nouvelle devient une adhésion.
                    $reference = $personnesDuFichier[$personne]['numero'];
                    if (null !== $annee && !\in_array($annee, $personnesDuFichier[$personne]['annees'], true)) {
                        $etat = 'adhesion';
                        $motif = 'adhesion_fichier';
                        $personnesDuFichier[$personne]['annees'][] = $annee;
                    } else {
                        $motif = 'doublon_fichier';
                    }
                } elseif ($dejaMembre) {
                    if (null !== $annee && !\in_array($annee, $reperes['annees'][$personne] ?? [], true) && !isset($anneesAjoutees[$personne][$annee])) {
                        $etat = 'adhesion';
                        $motif = 'adhesion_membre';
                        $anneesAjoutees[$personne][$annee] = true;
                    } elseif (null !== $annee && null !== $historique && \in_array($annee, $reperes['annees'][$personne] ?? [], true) && !\in_array($annee, $reperes['historiques'][$personne] ?? [], true) && !isset($anneesAjoutees[$personne][$annee])) {
                        // L'adhésion existe mais sans les mois du classeur (un premier import sans ces colonnes) : on la complète.
                        $etat = 'adhesion';
                        $motif = 'historique_membre';
                        $anneesAjoutees[$personne][$annee] = true;
                    } else {
                        $motif = 'deja_membre';
                    }
                } elseif (null !== $email && isset($emailsDuFichier[$email])) {
                    // Même adresse pour une autre personne : deux membres ne peuvent pas partager une adresse dans la ville.
                    $erreurs[] = 'email_en_double';
                    $reference = $emailsDuFichier[$email];
                } elseif (null !== $email && isset($reperes['emails'][$email])) {
                    $erreurs[] = 'email_deja_membre';
                }
            }

            $etat = [] !== $erreurs ? 'erreur' : ($etat ?? (null !== $motif ? 'ignoree' : 'prete'));
            if ('prete' === $etat) {
                $personnesDuFichier[$personne] = ['numero' => $numero, 'email' => $email, 'annees' => null !== $annee ? [$annee] : []];
                if (null !== $email) {
                    $emailsDuFichier[$email] = $numero;
                }
            }

            $lignes[] = [
                'numero' => $numero, 'prenom' => $prenom, 'nom' => $nom, 'email' => $email, 'telephone' => $telephone,
                'foyer' => '' === $foyer ? null : $foyer, 'localite' => '' === $localite ? null : $localite, 'annee' => $annee, 'anneeNaissance' => $anneeNaissance, 'historique' => $historique,
                'etat' => $etat, 'erreurs' => $erreurs, 'motif' => $motif, 'reference' => $reference,
            ];
        }

        $compte = static fn (string $etat) => \count(array_filter($lignes, static fn (array $l): bool => $etat === $l['etat']));
        $valides = $compte('prete');
        $ignorees = $compte('ignoree');
        $adhesions = $compte('adhesion');

        return ['colonnes' => $colonnes, 'lignes' => $lignes, 'valides' => $valides, 'invalides' => \count($lignes) - $valides - $ignorees - $adhesions, 'ignorees' => $ignorees, 'adhesions' => $adhesions, 'erreur' => null];
    }

    /** Ces colonnes suffisent-elles à identifier une personne : prénom et nom, ou un nom complet ? */
    public static function identifient(array $colonnes): bool
    {
        return isset($colonnes['prenom'], $colonnes['nom']) || isset($colonnes['nom_complet']);
    }

    /**
     * Sépare « DIABY Mamadou », « Mamadou DIABY » ou « Diaby Mamadou » en prénom et nom : les mots en capitales font le
     * nom quand la casse est mixte ; sinon l'ordre choisi tranche (premier mot ou dernier mot = nom).
     *
     * @return array{string, string} prénom, nom
     */
    public static function separerNomComplet(string $complet, string $ordre = ReglagesAnalyse::ORDRE_NOM_PRENOM): array
    {
        $mots = '' === ($complet = Membre::normaliserNom($complet)) ? [] : explode(' ', $complet);
        if ([] === $mots) {
            return ['', ''];
        }
        if (1 === \count($mots)) {
            return ['', self::casse($mots[0])];
        }

        $enCapitales = array_map(static fn (string $mot): bool => mb_strtoupper($mot) === $mot && (bool) preg_match('/\p{L}/u', $mot), $mots);
        if (\in_array(true, $enCapitales, true) && \in_array(false, $enCapitales, true)) {
            $nom = [];
            $prenom = [];
            foreach ($mots as $i => $mot) {
                $enCapitales[$i] ? $nom[] = $mot : $prenom[] = $mot;
            }
        } elseif (ReglagesAnalyse::ORDRE_PRENOM_NOM === $ordre) {
            $nom = [array_pop($mots)];
            $prenom = $mots;
        } else {
            $nom = [array_shift($mots)];
            $prenom = $mots;
        }

        return [self::casse(implode(' ', $prenom)), self::casse(implode(' ', $nom))];
    }

    /**
     * La ligne d'en-têtes parmi les premières lignes : la première où l'on reconnaît de quoi identifier une personne,
     * sinon celle où l'on reconnaît au moins le prénom ou le nom.
     *
     * @param list<list<?string>>   $lignes
     * @param array<string, string> $dictionnaire en-tête normalisé => champ, appris des imports précédents
     *
     * @return int|null l'indice (à partir de 0), ou null si aucune ligne ne ressemble à des en-têtes
     */
    public static function ligneEnTete(array $lignes, array $dictionnaire = []): ?int
    {
        $partielle = null;
        foreach (\array_slice($lignes, 0, self::LIGNES_EN_TETE_MAXIMUM) as $indice => $ligne) {
            $colonnes = self::colonnesDetectees($ligne, $dictionnaire);
            if (self::identifient($colonnes)) {
                return $indice;
            }
            if (null === $partielle && (isset($colonnes['prenom']) || isset($colonnes['nom']))) {
                $partielle = $indice;
            }
        }

        return $partielle;
    }

    /** « DIABY » devient « Diaby », « BOYE LILLE » devient « Boye Lille » ; une casse mixte est respectée. */
    public static function casse(string $texte): string
    {
        if ('' === $texte || mb_strtoupper($texte) !== $texte || !preg_match('/\p{L}/u', $texte)) {
            return $texte;
        }

        return mb_convert_case(mb_strtolower($texte), \MB_CASE_TITLE, 'UTF-8');
    }

    /** « 2019 », « 2019.0 » ou « 2 019 » : une année d'adhésion plausible, sinon rien. */
    public static function annee(?string $brut): ?int
    {
        $chiffres = (string) preg_replace('/[^\d.]/', '', (string) $brut);
        if ('' === $chiffres || !is_numeric($chiffres)) {
            return null;
        }
        $annee = (int) round((float) $chiffres);

        return Adhesion::anneeValide($annee) ? $annee : null;
    }

    /**
     * Les douze mois, le rapatriement et le projet de la ligne, ou null quand aucune de ces cases n'est renseignée.
     *
     * @param \Closure(string): ?string $valeur la cellule d'un champ
     *
     * @return Historique|null
     */
    private static function historique(\Closure $valeur): ?array
    {
        $mois = [];
        $renseigne = false;
        foreach (self::MOIS as $indice => $champ) {
            $case = self::caseMois($valeur($champ));
            $mois[$indice + 1] = $case;
            $renseigne = $renseigne || null !== $case;
        }
        $rapatriement = self::montant($valeur('rapatriement'));
        $projet = self::montant($valeur('projet'));
        if (!$renseigne && null === $rapatriement && null === $projet) {
            return null;
        }

        return ['mois' => $mois, 'rapatriement' => $rapatriement, 'projet' => $projet];
    }

    /** Une case de mois : « 10 » ou « 1O » (lettre O) deviennent des centimes, « V », « X »… restent le code en capitales, vide reste vide. */
    public static function caseMois(?string $brut): int|string|null
    {
        $brut = trim((string) $brut);
        if ('' === $brut || '-' === $brut) {
            return null;
        }
        $montant = self::montant($brut);
        if (null !== $montant) {
            return $montant;
        }

        return mb_substr(mb_strtoupper($brut), 0, 8);
    }

    /** Un montant écrit comme dans un tableur (« 10 », « 10,00 », « 200 € », « 1O » avec la lettre O) en centimes, sinon null. */
    public static function montant(?string $brut): ?int
    {
        $texte = (string) preg_replace('/[€\s\x{00a0}\x{202f}]/u', '', str_replace(',', '.', trim((string) $brut)));
        if (preg_match('/^[\dO]+(\.\d+)?$/', $texte)) {
            $texte = str_replace('O', '0', $texte);
        }
        if ('' === $texte || !is_numeric($texte)) {
            return null;
        }

        return (int) round((float) $texte * 100);
    }

    /** Un âge lu dans le fichier devient une année de naissance, calculée sur l'année de la ligne (sinon celle d'aujourd'hui). */
    public static function anneeNaissance(?string $age, ?int $annee): ?int
    {
        $chiffres = (string) preg_replace('/[^\d.]/', '', (string) $age);
        if ('' === $chiffres || !is_numeric($chiffres)) {
            return null;
        }
        $age = (int) round((float) $chiffres);
        if ($age < 0 || $age > 120) {
            return null;
        }

        return ($annee ?? (int) date('Y')) - $age;
    }

    /**
     * Enregistre les lignes prêtes (membres actifs, foyers créés à la volée) et les adhésions des lignes « adhésion »,
     * une ligne de journal pour l'import.
     *
     * @param list<Ligne> $lignes
     *
     * @return array{membres: int, adhesions: int} le nombre de membres créés et d'adhésions ajoutées
     */
    public function importer(Ville $ville, array $lignes, ?Utilisateur $par): array
    {
        $reperes = $this->membres->reperesPourImport($ville);
        $crees = 0;
        $adhesions = 0;
        /** @var array<string, Membre> $membresDuFichier */
        $membresDuFichier = [];
        foreach ($lignes as $ligne) {
            $etat = $ligne['etat'] ?? 'prete';
            if ([] !== $ligne['erreurs'] || '' === $ligne['prenom'] || '' === $ligne['nom']) {
                continue;
            }
            $personne = mb_strtolower($ligne['prenom'].'|'.$ligne['nom']);
            $annee = $ligne['annee'] ?? null;

            if ('adhesion' === $etat) {
                $membre = $membresDuFichier[$personne] ?? (isset($reperes['ids'][$personne]) ? $this->membres->find($reperes['ids'][$personne]) : null);
                if (null === $membre || null === $annee) {
                    continue;
                }
                $existante = $membre->adhesionPour($annee);
                if (null !== $existante && (null === ($ligne['historique'] ?? null) || $existante->aUnHistorique())) {
                    continue;
                }
                $this->poserHistorique($membre->adherer($annee, Membre::ORIGINE_IMPORT), $ligne);
                $this->completer($membre, $ligne);
                ++$adhesions;
                continue;
            }
            if ('prete' !== $etat) {
                continue;
            }
            // Le fichier a pu être importé deux fois : on ne recrée jamais un e-mail déjà pris.
            if (null !== $ligne['email'] && isset($reperes['emails'][$ligne['email']])) {
                continue;
            }
            $membre = new Membre($ville, $ligne['prenom'], $ligne['nom'], $ligne['email'], $ligne['telephone'], MembreStatut::Actif, Membre::ORIGINE_IMPORT);
            if (null !== $ligne['foyer']) {
                $membre->rejoindreFoyer($this->gestion->foyerPour($ville, $ligne['foyer']));
            }
            $this->completer($membre, $ligne);
            if (null !== $annee) {
                $this->poserHistorique($membre->adherer($annee, Membre::ORIGINE_IMPORT), $ligne);
            }
            $this->entityManager->persist($membre);
            $membresDuFichier[$personne] = $membre;
            if (null !== $ligne['email']) {
                $reperes['emails'][$ligne['email']] = $personne;
            }
            ++$crees;
        }

        if ($crees > 0 || $adhesions > 0) {
            $details = ['nombre' => $crees];
            if ($adhesions > 0) {
                $details['adhesions'] = $adhesions;
            }
            $this->journal->consigner(TypeEvenement::MembresImportes, $par, $ville->getAssociation(), $ville->getNom(), $details);
        }
        $this->entityManager->flush();

        return ['membres' => $crees, 'adhesions' => $adhesions];
    }

    /** Les mois, le rapatriement et le projet de la ligne, posés sur l'adhésion si elle n'en avait pas encore. */
    private function poserHistorique(Adhesion $adhesion, array $ligne): void
    {
        $historique = $ligne['historique'] ?? null;
        if (null === $historique || $adhesion->aUnHistorique()) {
            return;
        }
        $adhesion->definirHistorique($historique['mois'], $historique['rapatriement'], $historique['projet']);
    }

    /** La localité et l'année de naissance complètent une fiche qui ne les avait pas ; elles n'écrasent rien. */
    private function completer(Membre $membre, array $ligne): void
    {
        if (null === $membre->getLocalite() && null !== ($ligne['localite'] ?? null)) {
            $membre->definirLocalite($ligne['localite']);
        }
        if (null === $membre->getAnneeNaissance() && null !== ($ligne['anneeNaissance'] ?? null) && $ligne['anneeNaissance'] >= 1900 && $ligne['anneeNaissance'] <= (int) date('Y')) {
            $membre->definirAnneeNaissance($ligne['anneeNaissance']);
        }
    }

    /** Le fichier modèle proposé au téléchargement : en-têtes reconnus, point-virgule (Excel en français), BOM UTF-8. */
    public static function modele(): string
    {
        $lignes = [
            ['Prénom', 'Nom', 'E-mail', 'Téléphone', 'Foyer', 'Localité', 'Année'],
            ['Mamadou', 'Diaby', 'mamadou.diaby@example.org', '06 12 34 56 78', 'Famille Diaby', '', date('Y')],
            ['Awa', 'Cissé', '', '07 98 76 54 32', '', '', date('Y')],
        ];

        return "\xEF\xBB\xBF".implode("\r\n", array_map(static fn (array $l): string => implode(';', $l), $lignes))."\r\n";
    }

    /**
     * Les feuilles du fichier : celles du classeur avec leur nombre de lignes, ou une seule, sans nom, pour un CSV.
     *
     * @return list<array{nom: ?string, lignes: int}>
     *
     * @throws \RuntimeException si un classeur Excel est illisible
     */
    public static function feuilles(string $contenu): array
    {
        if (LecteurXlsx::estUnClasseur($contenu)) {
            return LecteurXlsx::feuilles($contenu);
        }

        return [['nom' => null, 'lignes' => \count(self::lire($contenu))]];
    }

    /**
     * Les lignes du fichier, en cellules : un classeur Excel (.xlsx) par l'une de ses feuilles, sinon un CSV (BOM retiré,
     * Windows-1252 converti, séparateur détecté sur la première ligne, champs entre guillemets même sur plusieurs lignes).
     * Les lignes entièrement vides sont ignorées.
     *
     * @param int|null       $maximum s'arrêter après ce nombre de lignes, pour un extrait
     * @param list<int>|null $garder  ne garder que ces colonnes (indices d'origine conservés), pour lire léger
     *
     * @return list<array<int, ?string>>
     *
     * @throws \RuntimeException si un classeur Excel est illisible
     */
    public static function lire(string $contenu, int $feuille = 0, ?int $maximum = null, ?array $garder = null): array
    {
        // Mise en cache des lectures (29 septembre 2026) : en mode « une colonne indique la ville », chaque valeur de la
        // colonne relance l'analyse, donc relisait la même feuille. La clé porte l'empreinte du fichier et les réglages
        // de lecture ; seules les dernières lectures sont gardées, pour ne pas retenir de gros tableaux en mémoire.
        if (null !== $garder) {
            sort($garder);
        }
        $cle = hash('xxh128', $contenu).'|'.$feuille.'|'.($maximum ?? '').'|'.(null === $garder ? '*' : implode(',', $garder));
        if (\array_key_exists($cle, self::$lectures)) {
            ++self::$lecturesEnCache;

            return self::$lectures[$cle];
        }
        $lignes = self::lireSansCache($contenu, $feuille, $maximum, $garder);
        self::$lectures[$cle] = $lignes;
        if (\count(self::$lectures) > self::LECTURES_GARDEES) {
            unset(self::$lectures[array_key_first(self::$lectures)]);
        }

        return $lignes;
    }

    /** Combien de lectures ont été servies par le cache depuis le dernier {@see self::viderCache()} (pour les tests). */
    public static function lecturesEnCache(): int
    {
        return self::$lecturesEnCache;
    }

    public static function viderCache(): void
    {
        self::$lectures = [];
        self::$lecturesEnCache = 0;
    }

    /**
     * @param list<int>|null $garder
     *
     * @return list<array<int, ?string>>
     */
    private static function lireSansCache(string $contenu, int $feuille, ?int $maximum, ?array $garder): array
    {
        if (LecteurXlsx::estUnClasseur($contenu)) {
            return LecteurXlsx::lire($contenu, $feuille, $maximum, $garder);
        }
        $garder = null === $garder ? null : array_flip($garder);

        $contenu = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
        if (!mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }
        if ('' === trim($contenu)) {
            return [];
        }
        $premiereLigne = (string) strtok($contenu, "\r\n");
        $separateur = self::separateur($premiereLigne);

        $flux = fopen('php://temp', 'r+');
        if (false === $flux) {
            throw new \RuntimeException('Impossible de lire le fichier.');
        }
        try {
            fwrite($flux, $contenu);
            rewind($flux);
            $lignes = [];
            while (false !== ($cellules = fgetcsv($flux, null, $separateur, '"', '\\'))) {
                $cellules = array_map(static fn (?string $c): ?string => null === $c ? null : trim($c), $cellules);
                if ([] === array_filter($cellules, static fn (?string $c): bool => null !== $c && '' !== $c)) {
                    continue;
                }
                $cellules = array_values($cellules);
                $lignes[] = null === $garder ? $cellules : array_intersect_key($cellules, $garder);
                if (null !== $maximum && \count($lignes) >= $maximum) {
                    break;
                }
            }
        } finally {
            fclose($flux);
        }

        return $lignes;
    }

    private static function separateur(string $enTete): string
    {
        $candidats = [';' => substr_count($enTete, ';'), ',' => substr_count($enTete, ','), "\t" => substr_count($enTete, "\t")];
        arsort($candidats);

        return (string) array_key_first($candidats);
    }

    /** Un tableur perd le zéro de tête d'un numéro saisi comme nombre : « 612345678 » redevient « 0612345678 ». */
    private static function telephone(?string $brut): ?string
    {
        $telephone = Membre::normaliserTelephone($brut);
        if (null !== $telephone && 9 === \strlen($telephone) && ctype_digit($telephone) && '0' !== $telephone[0]) {
            return '0'.$telephone;
        }

        return $telephone;
    }

    /**
     * @param list<string|null>     $enTetes
     * @param array<string, string> $dictionnaire en-tête normalisé => champ, appris des imports précédents ; passe avant les alias
     *
     * @return array<string, int> la colonne (indice) reconnue pour chaque champ, d'après son en-tête
     */
    public static function colonnesDetectees(array $enTetes, array $dictionnaire = []): array
    {
        $colonnes = [];
        foreach ($enTetes as $indice => $enTete) {
            $cle = Texte::normaliser((string) $enTete);
            if ('' === $cle) {
                continue;
            }
            $appris = $dictionnaire[$cle] ?? null;
            if (null !== $appris && \in_array($appris, self::CHAMPS, true) && !isset($colonnes[$appris])) {
                $colonnes[$appris] = $indice;
                continue;
            }
            foreach (self::EN_TETES as $champ => $alias) {
                if (!isset($colonnes[$champ]) && \in_array($cle, $alias, true)) {
                    $colonnes[$champ] = $indice;
                }
            }
            // « PROJET 200€ », « Projets » : la colonne du projet, quel que soit le montant écrit dans l'en-tête.
            if (!isset($colonnes['projet']) && str_starts_with($cle, 'projet')) {
                $colonnes['projet'] = $indice;
            }
        }

        return $colonnes;
    }

    /**
     * Ce qu'un import confirmé apprend : pour chaque colonne attribuée, l'en-tête normalisé et son champ, à mémoriser
     * par l'association pour reconnaître le même fichier la fois suivante.
     *
     * @param list<string|null>  $enTetes
     * @param array<string, int> $colonnes champ => indice
     *
     * @return array<string, string> en-tête normalisé => champ
     */
    public static function dictionnaire(array $enTetes, array $colonnes): array
    {
        $dictionnaire = [];
        foreach ($colonnes as $champ => $indice) {
            $cle = Texte::normaliser((string) ($enTetes[$indice] ?? ''));
            if ('' !== $cle) {
                $dictionnaire[$cle] = $champ;
            }
        }

        return $dictionnaire;
    }
}
