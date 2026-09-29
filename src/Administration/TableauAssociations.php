<?php

declare(strict_types=1);

namespace App\Administration;

use App\Entity\Abonnement;
use App\Entity\AbonnementStatut;
use App\Entity\Association;
use App\Entity\AssociationStatut;
use App\Entity\Invitation;
use App\Entity\Utilisateur;


/**
 * La liste des associations du super-admin : recherche, filtres, tri et compteurs, appliqués en mémoire
 * aux lignes calculées par AdministrationController (quelques dizaines d'associations au plus).
 *
 * @phpstan-type Ligne array{association: Association, villes: array{total: int, brouillon: int}, comptes: array{total: int, en_attente: int}, bureauCentral: list<Utilisateur>, invitation: ?Invitation, invitationEtat: ?string, derniereActivite: ?\DateTimeImmutable, suppressionPossible: bool, abonnement: ?Abonnement}
 */
final class TableauAssociations
{
    public const array STATUTS = ['toutes', 'actives', 'suspendues', 'archivees'];
    public const array BUREAUX = ['tous', 'avec', 'sans', 'invitation-en-attente', 'invitation-expiree'];
    public const array ABONNEMENTS = ['tous', 'a-souscrire', 'offert', 'actif', 'en-retard', 'echeance-proche', 'resilie'];
    public const array TRIS = ['nom', 'creation', 'villes', 'comptes', 'activite', 'echeance'];
    public const array SENS = ['asc', 'desc'];

    public static function statut(?string $valeur): string
    {
        return \in_array($valeur, self::STATUTS, true) ? $valeur : 'toutes';
    }

    public static function bureau(?string $valeur): string
    {
        return \in_array($valeur, self::BUREAUX, true) ? $valeur : 'tous';
    }

    public static function abonnement(?string $valeur): string
    {
        return \in_array($valeur, self::ABONNEMENTS, true) ? $valeur : 'tous';
    }

    public static function tri(?string $valeur): string
    {
        return \in_array($valeur, self::TRIS, true) ? $valeur : 'nom';
    }

    public static function sens(?string $valeur): string
    {
        return \in_array($valeur, self::SENS, true) ? $valeur : 'asc';
    }

    /**
     * @param list<Ligne> $lignes
     *
     * @return list<Ligne>
     */
    public static function filtrer(array $lignes, string $recherche, string $statut, string $bureau, string $abonnement = 'tous'): array
    {
        $aiguille = self::normaliser($recherche);
        $aujourdhui = new \DateTimeImmutable();

        return array_values(array_filter(
            $lignes,
            static fn (array $ligne): bool => self::correspondAuStatut($ligne, $statut)
                && self::correspondAuBureau($ligne, $bureau)
                && self::correspondALAbonnement($ligne, $abonnement, $aujourdhui)
                && ('' === $aiguille || str_contains(self::texteRecherche($ligne), $aiguille)),
        ));
    }

    /**
     * @param list<Ligne> $lignes
     *
     * @return list<Ligne>
     */
    public static function trier(array $lignes, string $tri, string $sens): array
    {
        usort($lignes, static function (array $a, array $b) use ($tri): int {
            $parNom = strnatcasecmp(self::normaliser($a['association']->getNom()), self::normaliser($b['association']->getNom()));
            $resultat = match ($tri) {
                'creation' => $a['association']->getCreeLe() <=> $b['association']->getCreeLe(),
                'villes' => $a['villes']['total'] <=> $b['villes']['total'],
                'comptes' => $a['comptes']['total'] <=> $b['comptes']['total'],
                'activite' => ($a['derniereActivite']?->getTimestamp() ?? 0) <=> ($b['derniereActivite']?->getTimestamp() ?? 0),
                'echeance' => ($a['abonnement']?->getProchaineEcheanceLe()?->getTimestamp() ?? \PHP_INT_MAX) <=> ($b['abonnement']?->getProchaineEcheanceLe()?->getTimestamp() ?? \PHP_INT_MAX),
                default => $parNom,
            };

            return 0 !== $resultat ? $resultat : $parNom;
        });

        return 'desc' === $sens ? array_reverse($lignes) : $lignes;
    }

    /**
     * Combien d'associations chaque filtre retiendrait, pour les afficher à côté de son libellé.
     *
     * @param list<Ligne> $lignes
     *
     * @return array{statut: array<string, int>, bureau: array<string, int>, abonnement: array<string, int>}
     */
    public static function compter(array $lignes): array
    {
        $aujourdhui = new \DateTimeImmutable();
        $compteurs = ['statut' => [], 'bureau' => [], 'abonnement' => []];
        foreach (self::STATUTS as $statut) {
            $compteurs['statut'][$statut] = \count(array_filter($lignes, static fn (array $l): bool => self::correspondAuStatut($l, $statut)));
        }
        foreach (self::BUREAUX as $bureau) {
            $compteurs['bureau'][$bureau] = \count(array_filter($lignes, static fn (array $l): bool => self::correspondAuBureau($l, $bureau)));
        }
        foreach (self::ABONNEMENTS as $abonnement) {
            $compteurs['abonnement'][$abonnement] = \count(array_filter($lignes, static fn (array $l): bool => self::correspondALAbonnement($l, $abonnement, $aujourdhui)));
        }

        return $compteurs;
    }

    /**
     * @param list<Ligne> $lignes
     *
     * @return array{associations: int, villes: int, villesBrouillon: int, comptes: int, comptesEnAttente: int}
     */
    public static function totaux(array $lignes): array
    {
        return [
            'associations' => \count($lignes),
            'villes' => array_sum(array_map(static fn (array $l): int => $l['villes']['total'], $lignes)),
            'villesBrouillon' => array_sum(array_map(static fn (array $l): int => $l['villes']['brouillon'], $lignes)),
            'comptes' => array_sum(array_map(static fn (array $l): int => $l['comptes']['total'], $lignes)),
            'comptesEnAttente' => array_sum(array_map(static fn (array $l): int => $l['comptes']['en_attente'], $lignes)),
        ];
    }

    /** @param Ligne $ligne */
    private static function correspondAuStatut(array $ligne, string $statut): bool
    {
        return match ($statut) {
            'actives' => AssociationStatut::Active === $ligne['association']->getStatut(),
            'suspendues' => AssociationStatut::Suspendue === $ligne['association']->getStatut(),
            'archivees' => AssociationStatut::Archivee === $ligne['association']->getStatut(),
            default => true,
        };
    }

    /** @param Ligne $ligne */
    private static function correspondAuBureau(array $ligne, string $bureau): bool
    {
        $sansBureau = [] === $ligne['bureauCentral'];

        return match ($bureau) {
            'avec' => !$sansBureau,
            'sans' => $sansBureau,
            'invitation-en-attente' => $sansBureau && 'en_attente' === $ligne['invitationEtat'],
            'invitation-expiree' => $sansBureau && 'expiree' === $ligne['invitationEtat'],
            default => true,
        };
    }

    /** @param Ligne $ligne */
    private static function correspondALAbonnement(array $ligne, string $filtre, \DateTimeImmutable $aujourdhui): bool
    {
        $abonnement = $ligne['abonnement'];
        if (null === $abonnement) {
            return 'tous' === $filtre;
        }

        return match ($filtre) {
            'a-souscrire' => $abonnement->attendLaSouscription(),
            'offert' => AbonnementStatut::Offert === $abonnement->getStatut(),
            'actif' => AbonnementStatut::Actif === $abonnement->getStatut() && !$abonnement->estEnRetard($aujourdhui),
            'en-retard' => $abonnement->estEnRetard($aujourdhui),
            'echeance-proche' => $abonnement->echeanceProche($aujourdhui),
            'resilie' => AbonnementStatut::Resilie === $abonnement->getStatut(),
            default => true,
        };
    }

    /** Tout ce qu'on peut chercher : nom, identifiant, village, contact, bureau central, invitation, formule. @param Ligne $ligne */
    private static function texteRecherche(array $ligne): string
    {
        $association = $ligne['association'];
        $morceaux = [$association->getNom(), $association->getSlug(), (string) $association->getVillage(), (string) $association->getEmailContact(), (string) $ligne['invitation']?->getEmail(), (string) $ligne['abonnement']?->getFormule()];
        foreach ($ligne['bureauCentral'] as $compte) {
            $morceaux[] = $compte->getNomComplet();
            $morceaux[] = $compte->getEmail();
        }

        return self::normaliser(implode(' ', $morceaux));
    }

    /** Sans accents ni majuscules : « Bakél » et « bakel » se trouvent. */
    private static function normaliser(string $texte): string
    {
        return Texte::normaliser($texte);
    }
}
