<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Permissions fines du produit (section 2 du cahier des charges).
 * Les rôles en regroupent plusieurs ; les Voters les vérifient sur un périmètre.
 */
final class Permission
{
    /** Voir l'administration de la plateforme : associations, villes, comptes, support. Réservé au super-admin. */
    public const string PLATEFORME_ADMINISTRER = 'plateforme.administrer';

    /** Créer une association, le tenant : réservé au super-admin plateforme. */
    public const string ASSOCIATION_CREER = 'association.creer';

    /** Consulter l'organisation de toutes les associations (villes, comptes, invitations), sans leurs finances. */
    public const string ASSOCIATION_CONSULTER = 'association.consulter';

    /** Ouvrir l'espace de son association : tableau de bord, villes, activité. Sujet : l'association. */
    public const string ASSOCIATION_PILOTER = 'association.piloter';

    /** Renommer une association ou changer son identifiant. Sujet : l'association. */
    public const string ASSOCIATION_MODIFIER = 'association.modifier';

    /** Supprimer une association vide (sans ville ni compte). Sujet : l'association. */
    public const string ASSOCIATION_SUPPRIMER = 'association.supprimer';

    /** Inviter, modifier, activer ou désactiver un compte et ses rôles. Sujet : l'association du compte. */
    public const string COMPTE_GERER = 'compte.gerer';

    public const string VILLE_CREER = 'ville.creer';
    public const string VILLE_MODIFIER = 'ville.modifier';
    /** Ouvrir l'espace de l'association avec un périmètre de ville : tableau de bord, membres, cotisations… Sujet : une ville. */
    public const string VILLE_CONSULTER = 'ville.consulter';
    /** Ajouter, modifier, importer des membres et des foyers, valider une inscription. Sujet : une ville. */
    public const string MEMBRE_GERER = 'membre.gerer';
    /** Ouvrir les cotisations, saisir un paiement manuel, relancer, déclarer un reversement. Sujet : une ville. */
    public const string PAIEMENT_SAISIR = 'paiement.saisir';
    /** Saisir une dépense de la caisse de la ville et la marquer payée. Sujet : une ville. */
    public const string DEPENSE_SAISIR = 'depense.saisir';
    /** Valider ou refuser une dépense de la ville. Sujet : une ville. */
    public const string DEPENSE_VALIDER = 'depense.valider';
    /** Lancer un appel à contribution de la ville. Sujet : une ville. */
    public const string APPEL_LANCER = 'appel.lancer';
    /** Entrer dans l'espace d'une association : le bureau central, ou tout responsable d'une de ses villes. Sujet : l'association. */
    public const string ESPACE_OUVRIR = 'espace.ouvrir';

    /** @return list<string> */
    public static function toutes(): array
    {
        return [...self::plateforme(), ...self::associations(), ...self::villes()];
    }

    /** Permissions sans sujet : elles portent sur la plateforme entière. @return list<string> */
    public static function plateforme(): array
    {
        return [self::PLATEFORME_ADMINISTRER, self::ASSOCIATION_CREER, self::ASSOCIATION_CONSULTER];
    }

    /** Permissions dont le sujet est une association. @return list<string> */
    public static function associations(): array
    {
        return [self::ASSOCIATION_PILOTER, self::ASSOCIATION_MODIFIER, self::ASSOCIATION_SUPPRIMER, self::COMPTE_GERER, self::ESPACE_OUVRIR];
    }

    /** Permissions dont le sujet est une ville, ou l'association pour en créer une. @return list<string> */
    public static function villes(): array
    {
        return [self::VILLE_CREER, self::VILLE_MODIFIER, self::VILLE_CONSULTER, self::MEMBRE_GERER, self::PAIEMENT_SAISIR, self::DEPENSE_SAISIR, self::DEPENSE_VALIDER, self::APPEL_LANCER];
    }

    private function __construct()
    {
    }
}
