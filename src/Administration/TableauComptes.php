<?php

declare(strict_types=1);

namespace App\Administration;

use App\Entity\Utilisateur;

/** Recherche dans les listes de comptes de l'administration (comptes, équipe) : nom, adresse, association, rôles, villes. */
final class TableauComptes
{
    /**
     * @param list<Utilisateur> $comptes
     *
     * @return list<Utilisateur>
     */
    public static function filtrer(array $comptes, string $recherche): array
    {
        if ('' === trim($recherche)) {
            return $comptes;
        }

        return array_values(array_filter(
            $comptes,
            static fn (Utilisateur $compte): bool => Texte::contient(self::texteRecherche($compte), $recherche),
        ));
    }

    private static function texteRecherche(Utilisateur $compte): string
    {
        $morceaux = [$compte->getNomComplet(), $compte->getEmail()];

        $association = $compte->getAssociation();
        if (null !== $association) {
            $morceaux[] = $association->getNom();
            $morceaux[] = $association->getSlug();
        }

        foreach ($compte->getAffectations() as $affectation) {
            $morceaux[] = $affectation->getRole()->value;
            $morceaux[] = $affectation->getVille()?->getNom() ?? '';
        }

        return implode(' ', $morceaux);
    }

    private function __construct()
    {
    }
}
