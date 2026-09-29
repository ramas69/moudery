<?php

declare(strict_types=1);

namespace App\Administration;

use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Repository\UtilisateurRepository;
use App\Security\Permission;

/**
 * « Voir comme » : les personnes dont le super-admin peut emprunter l'identité, et la résolution d'une saisie libre
 * (sans JavaScript, le sélecteur reste un champ texte : adresse e-mail, nom complet, ou début qui ne désigne qu'une personne).
 */
final class VoirComme
{
    public function __construct(private readonly UtilisateurRepository $utilisateurs)
    {
    }

    /**
     * Comptes actifs d'associations actives (le vérificateur d'utilisateur refuserait les autres, comme à la connexion),
     * sauf soi-même et sauf les administrateurs de la plateforme.
     *
     * @return list<Utilisateur>
     */
    public function personnes(?Utilisateur $moi): array
    {
        return array_values(array_filter(
            $this->utilisateurs->listerTous(null, UtilisateurStatut::Actif),
            static fn (Utilisateur $personne): bool => $personne->getId() !== $moi?->getId()
                && !$personne->aLaPermissionSurLaPlateforme(Permission::PLATEFORME_ADMINISTRER)
                && ($personne->getAssociation()?->estActive() ?? true),
        ));
    }

    /**
     * @param list<Utilisateur> $personnes
     */
    public static function correspondante(array $personnes, string $saisie): ?Utilisateur
    {
        $aiguille = Texte::normaliser($saisie);
        if ('' === $aiguille) {
            return null;
        }

        foreach ($personnes as $personne) {
            if (Texte::normaliser($personne->getEmail()) === $aiguille || Texte::normaliser($personne->getNomComplet()) === $aiguille) {
                return $personne;
            }
        }

        $candidates = array_values(array_filter(
            $personnes,
            static fn (Utilisateur $personne): bool => Texte::contient($personne->getNomComplet().' '.$personne->getEmail(), $aiguille),
        ));

        return 1 === \count($candidates) ? $candidates[0] : null;
    }
}
