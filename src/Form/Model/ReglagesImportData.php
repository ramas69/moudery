<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Ville\ImportMembres;
use App\Ville\ReglagesAnalyse;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Réglages d'un import de membres (F-31) : la feuille du classeur, la ligne d'en-têtes (pas forcément la première), et le champ attribué à chaque colonne. */
final class ReglagesImportData
{
    public int $feuille = 0;

    /** La ligne d'en-têtes, à partir de 1 ; 0 si le fichier n'en a pas. Les lignes au-dessus ne sont pas importées. */
    #[Assert\Range(min: 0, max: 200, notInRangeMessage: 'membre.import.reglages.ligne_en_tete')]
    public int $ligneEnTete = 1;

    /** Quand une seule colonne porte le nom complet : « DIABY Mamadou » (nom puis prénom) ou « Mamadou Diaby ». */
    #[Assert\Choice(choices: ReglagesAnalyse::ORDRES)]
    public string $ordreNomComplet = ReglagesAnalyse::ORDRE_NOM_PRENOM;

    /** @var array<string, ?string> « c0 », « c1 »… => un champ de ImportMembres::CHAMPS, ou null (colonne ignorée) */
    public array $colonnes = [];

    /** @param array<string, int> $correspondances champ => indice de colonne */
    public static function depuisCorrespondances(array $correspondances): array
    {
        $colonnes = [];
        foreach ($correspondances as $champ => $indice) {
            $colonnes[self::cle($indice)] = $champ;
        }

        return $colonnes;
    }

    public static function cle(int $indice): string
    {
        return 'c'.$indice;
    }

    /** @return array<string, int> champ => indice de colonne, pour l'analyse */
    public function correspondances(): array
    {
        $correspondances = [];
        foreach ($this->colonnes as $cle => $champ) {
            if (null !== $champ && '' !== $champ && \in_array($champ, ImportMembres::CHAMPS, true)) {
                $correspondances[$champ] = (int) substr($cle, 1);
            }
        }

        return $correspondances;
    }

    /** Le prénom et le nom (ou un nom complet) sont indispensables ; un champ ne se lit que dans une seule colonne. */
    #[Assert\Callback]
    public function valider(ExecutionContextInterface $contexte): void
    {
        $utilises = [];
        foreach ($this->colonnes as $cle => $champ) {
            if (null === $champ || '' === $champ) {
                continue;
            }
            if (isset($utilises[$champ])) {
                $contexte->buildViolation('membre.import.reglages.champ_en_double')->atPath('colonnes['.$cle.']')->addViolation();
            }
            $utilises[$champ] = true;
        }
        if (isset($utilises['nom_complet'])) {
            return;
        }
        if (!isset($utilises['prenom'])) {
            $contexte->buildViolation('membre.import.reglages.prenom_manquant')->atPath('colonnes')->addViolation();
        }
        if (!isset($utilises['nom'])) {
            $contexte->buildViolation('membre.import.reglages.nom_manquant')->atPath('colonnes')->addViolation();
        }
    }
}
