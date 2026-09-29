<?php

declare(strict_types=1);

namespace App\Ville;

/**
 * Les réglages d'une analyse de fichier (F-31) : la feuille, la ligne d'en-têtes, les colonnes, l'ordre d'un nom
 * complet, un filtre sur une colonne (« ne garder que les lignes de la caisse X ») et le dictionnaire d'en-têtes
 * mémorisé par l'association. Tout ce qui est null est détecté d'après le fichier.
 */
final readonly class ReglagesAnalyse
{
    public const string ORDRE_NOM_PRENOM = 'nom-prenom';
    public const string ORDRE_PRENOM_NOM = 'prenom-nom';
    public const array ORDRES = [self::ORDRE_NOM_PRENOM, self::ORDRE_PRENOM_NOM];

    /**
     * @param array<string, int>|null $colonnes     champ => indice de colonne ; détectées sur la ligne d'en-têtes si null
     * @param int|null                $ligneEnTete  à partir de 1 ; 0 = aucune ; null = détectée
     * @param array<string, string>   $dictionnaire en-tête normalisé => champ, appris des imports précédents
     */
    public function __construct(
        public int $feuille = 0,
        public ?int $ligneEnTete = null,
        public ?array $colonnes = null,
        public string $ordreNomComplet = self::ORDRE_NOM_PRENOM,
        public ?int $filtreColonne = null,
        public ?string $filtreValeur = null,
        public array $dictionnaire = [],
    ) {
    }

    public function avecFiltre(int $colonne, string $valeur): self
    {
        return new self($this->feuille, $this->ligneEnTete, $this->colonnes, $this->ordreNomComplet, $colonne, $valeur, $this->dictionnaire);
    }
}
