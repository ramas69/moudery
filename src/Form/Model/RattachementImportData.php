<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Association\ImportAssociation;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Rattachement des onglets d'un fichier (ou des valeurs d'une colonne « caisse ») aux villes de l'association (F-31). */
final class RattachementImportData
{
    #[Assert\Choice(choices: ImportAssociation::MODES)]
    public string $mode = ImportAssociation::MODE_ONGLETS;

    /** Mode colonne : la feuille lue et la colonne qui désigne la caisse. */
    public ?int $feuille = null;

    public ?int $colonne = null;

    /** @var array<string, ?string> « f<indice> » ou « v<indice> » => '' (ignorer), « creer » ou « ville:<id> » */
    public array $cibles = [];

    /** @return array{ville: ?int, creer: bool}|null null pour une cible ignorée */
    public static function lire(?string $choix): ?array
    {
        if (null === $choix || ImportAssociation::CIBLE_IGNORER === $choix) {
            return null;
        }
        if (ImportAssociation::CIBLE_CREER === $choix) {
            return ['ville' => null, 'creer' => true];
        }
        if (preg_match('/^ville:(\d+)$/', $choix, $m)) {
            return ['ville' => (int) $m[1], 'creer' => false];
        }

        return null;
    }

    #[Assert\Callback]
    public function valider(ExecutionContextInterface $contexte): void
    {
        $retenues = 0;
        $villes = [];
        foreach ($this->cibles as $cle => $choix) {
            $cible = self::lire($choix);
            if (null === $cible) {
                continue;
            }
            ++$retenues;
            if (null !== $cible['ville']) {
                if (isset($villes[$cible['ville']])) {
                    $contexte->buildViolation('membre.import.rattachement.ville_en_double')->atPath('cibles['.$cle.']')->addViolation();
                }
                $villes[$cible['ville']] = true;
            }
        }
        if (0 === $retenues) {
            $contexte->buildViolation('membre.import.rattachement.aucune')->atPath('cibles')->addViolation();
        }
    }
}
