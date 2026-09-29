<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\RoleVille;

/**
 * Rôles prédéfinis de la V1 (section 2 du cahier des charges) : chacun regroupe des permissions.
 * Le super-admin plateforme s'attribue sur toute la plateforme, le bureau central sur toute une association,
 * les autres rôles sur une ville. En V2, les rôles deviendront paramétrables par association ;
 * le code ne connaît que les permissions.
 */
enum Role: string
{
    case SuperAdmin = 'super-admin';
    case BureauCentral = 'bureau-central';
    case Tresorier = 'tresorier';
    case President = 'president';
    case Secretaire = 'secretaire';
    case Membre = 'membre';

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            // Décision du 27 septembre 2026 : le super-admin peut tout consulter et tout modifier sur toute la plateforme
            // (associations, villes, comptes et rôles), là où le cahier des charges ne lui donnait aucune donnée financière par défaut.
            // Sauf créer une ville : c'est le bureau central de l'association qui crée ses villes (règle du cahier des charges).
            self::SuperAdmin => array_values(array_diff(Permission::toutes(), [Permission::VILLE_CREER])),
            // Cahier des charges, section 2 : le bureau central voit tout et agit sur toutes ses villes ; le trésorier gère
            // membres, paiements, dépenses (saisie), impayés ; le président valide les dépenses et lance les appels de sa
            // ville ; le secrétaire gère les membres et consulte. Le point ouvert « le bureau central peut-il valider les
            // dépenses d'une ville sans son président ? » est tranché par défaut à non : DEPENSE_VALIDER reste au président.
            self::BureauCentral => [Permission::ASSOCIATION_PILOTER, Permission::ESPACE_OUVRIR, Permission::VILLE_CREER, Permission::VILLE_MODIFIER, Permission::VILLE_CONSULTER, Permission::MEMBRE_GERER, Permission::PAIEMENT_SAISIR, Permission::DEPENSE_SAISIR, Permission::APPEL_LANCER],
            self::Tresorier => [Permission::VILLE_MODIFIER, Permission::VILLE_CONSULTER, Permission::MEMBRE_GERER, Permission::PAIEMENT_SAISIR, Permission::DEPENSE_SAISIR],
            self::President => [Permission::VILLE_CONSULTER, Permission::DEPENSE_VALIDER, Permission::APPEL_LANCER],
            self::Secretaire => [Permission::VILLE_CONSULTER, Permission::MEMBRE_GERER],
            self::Membre => [],
        };
    }

    public function accorde(string $permission): bool
    {
        return \in_array($permission, $this->permissions(), true);
    }

    public function perimetre(): Perimetre
    {
        return match ($this) {
            self::SuperAdmin => Perimetre::Plateforme,
            self::BureauCentral => Perimetre::Association,
            self::Tresorier, self::President, self::Secretaire, self::Membre => Perimetre::Ville,
        };
    }

    /** Les rôles qui s'attribuent dans une association, par invitation ou depuis l'administration. @return list<self> */
    public static function dansUneAssociation(): array
    {
        return [self::BureauCentral, self::Tresorier, self::President, self::Secretaire, self::Membre];
    }

    /** Le rôle donné à un responsable invité à l'étape Identité de l'assistant. */
    public static function depuisRoleVille(RoleVille $role): self
    {
        return self::from($role->value);
    }
}
