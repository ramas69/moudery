<?php

declare(strict_types=1);

namespace App\Administration;

use App\Entity\Association;
use App\Entity\Ville;
use App\Entity\VilleStatut;

/**
 * La liste des villes de l'administration : recherche, filtres, tri, pagination, tout en mémoire sur les villes chargées.
 * Les paramètres de l'URL passent par les normaliseurs, qui ne renvoient jamais autre chose qu'une valeur connue.
 */
final class TableauVilles
{
    public const array STATUTS = ['tous', 'brouillon', 'active', 'archivee', 'bloquees'];
    public const array TRIS = ['ville', 'association', 'statut', 'etape', 'responsables', 'creation'];
    public const array PAR_PAGE = [25, 50, 100];

    /** Un brouillon qui n'a pas bougé depuis un mois est signalé : quelqu'un a sans doute abandonné en route. */
    public const int JOURS_BLOCAGE = 30;

    public static function statut(?string $valeur): string
    {
        return \in_array($valeur, self::STATUTS, true) ? $valeur : 'tous';
    }

    public static function tri(?string $valeur): string
    {
        return \in_array($valeur, self::TRIS, true) ? $valeur : 'association';
    }

    public static function sens(?string $valeur): string
    {
        return 'desc' === $valeur ? 'desc' : 'asc';
    }

    public static function parPage(mixed $valeur): int
    {
        $valeur = (int) $valeur;

        return \in_array($valeur, self::PAR_PAGE, true) ? $valeur : self::PAR_PAGE[0];
    }

    public static function page(mixed $valeur): int
    {
        return max(1, (int) $valeur);
    }

    /**
     * L'association désignée par le sélecteur : son identifiant, ou son nom tel qu'il a été saisi (sans accents ni casse),
     * ou à défaut le début de son nom quand il n'y a qu'une candidate.
     *
     * @param list<Association> $associations
     */
    public static function associationCorrespondante(array $associations, string $saisie): ?Association
    {
        $saisie = Texte::normaliser($saisie);
        if ('' === $saisie) {
            return null;
        }
        foreach ($associations as $association) {
            if ($association->getSlug() === $saisie || Texte::normaliser($association->getNom()) === $saisie) {
                return $association;
            }
        }
        $candidates = array_values(array_filter($associations, static fn (Association $a): bool => Texte::contient($a->getNom(), $saisie)));

        return 1 === \count($candidates) ? $candidates[0] : null;
    }

    public static function estBloquee(Ville $ville, \DateTimeImmutable $aujourdhui): bool
    {
        return $ville->estBrouillon() && $ville->getModifieLe() < $aujourdhui->modify(\sprintf('-%d days', self::JOURS_BLOCAGE));
    }

    /** @param list<Ville> $villes @return list<Ville> */
    public static function filtrer(array $villes, string $recherche, string $statut, \DateTimeImmutable $aujourdhui): array
    {
        return array_values(array_filter(
            $villes,
            static fn (Ville $ville): bool => self::correspondAuStatut($ville, $statut, $aujourdhui) && Texte::contient(self::texteRecherche($ville), $recherche),
        ));
    }

    /**
     * @param list<Ville> $villes
     *
     * @return list<Ville>
     */
    public static function trier(array $villes, string $tri, string $sens, bool $parAssociation = false): array
    {
        $collation = new \Collator('fr_FR');
        $signe = 'desc' === $sens ? -1 : 1;
        usort($villes, static function (Ville $a, Ville $b) use ($tri, $signe, $parAssociation, $collation): int {
            if ($parAssociation) {
                $groupe = $collation->compare($a->getAssociation()->getNom(), $b->getAssociation()->getNom());
                if (0 !== $groupe) {
                    return $groupe;
                }
            }
            $resultat = match ($tri) {
                'association' => $collation->compare($a->getAssociation()->getNom(), $b->getAssociation()->getNom()),
                'statut' => self::rangStatut($a->getStatut()) <=> self::rangStatut($b->getStatut()),
                'etape' => $a->getEtapeAssistant()->numero() <=> $b->getEtapeAssistant()->numero(),
                'responsables' => \count($a->getInvitations()) <=> \count($b->getInvitations()),
                'creation' => $a->getCreeLe() <=> $b->getCreeLe(),
                default => $collation->compare($a->getNom(), $b->getNom()),
            };

            // À égalité, le nom de la ville départage toujours dans l'ordre alphabétique, quel que soit le sens demandé.
            return 0 !== $resultat ? $signe * $resultat : $collation->compare($a->getNom(), $b->getNom());
        });

        return $villes;
    }

    /**
     * @param list<Ville> $villes
     *
     * @return array<string, int> un compteur par filtre de statut
     */
    public static function compter(array $villes, \DateTimeImmutable $aujourdhui): array
    {
        $compteurs = [];
        foreach (self::STATUTS as $statut) {
            $compteurs[$statut] = \count(array_filter($villes, static fn (Ville $v): bool => self::correspondAuStatut($v, $statut, $aujourdhui)));
        }

        return $compteurs;
    }

    /**
     * @param list<Ville> $villes
     *
     * @return array{villes: list<Ville>, page: int, pages: int, total: int, de: int, a: int, parPage: int}
     */
    public static function paginer(array $villes, int $page, int $parPage): array
    {
        $total = \count($villes);
        $pages = max(1, (int) ceil($total / $parPage));
        $page = min($page, $pages);
        $tranche = \array_slice($villes, ($page - 1) * $parPage, $parPage);

        return [
            'villes' => $tranche,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'de' => 0 === $total ? 0 : ($page - 1) * $parPage + 1,
            'a' => ($page - 1) * $parPage + \count($tranche),
            'parPage' => $parPage,
        ];
    }

    private static function correspondAuStatut(Ville $ville, string $statut, \DateTimeImmutable $aujourdhui): bool
    {
        return match ($statut) {
            'brouillon' => $ville->estBrouillon(),
            'active' => $ville->estActive(),
            'archivee' => $ville->estArchivee(),
            'bloquees' => self::estBloquee($ville, $aujourdhui),
            default => true,
        };
    }

    /** Nom de la ville, association (nom, identifiant, village), étape. */
    private static function texteRecherche(Ville $ville): string
    {
        $association = $ville->getAssociation();

        return implode(' ', [$ville->getNom(), $association->getNom(), $association->getSlug(), $association->getNomVillage(), $ville->getEtapeAssistant()->value]);
    }

    private static function rangStatut(VilleStatut $statut): int
    {
        return match ($statut) {
            VilleStatut::Brouillon => 0,
            VilleStatut::Active => 1,
            VilleStatut::Archivee => 2,
        };
    }
}
