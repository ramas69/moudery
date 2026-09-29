<?php

declare(strict_types=1);

namespace App\Association;

use App\Administration\Texte;
use App\Entity\Association;
use App\Entity\InvitationResponsable;
use App\Entity\Ville;
use App\Repository\AdhesionRepository;
use App\Repository\MembreRepository;
use App\Repository\UtilisateurRepository;
use App\Repository\VilleRepository;

/**
 * La liste des villes de l'association pour le bureau central (F-28), d'après l'artboard « Villes » du canevas
 * Console : chaque ville avec son statut et son avancement, ses responsables, ses membres, et pour l'année de
 * référence les adhérents à jour et le collecté, lus dans l'historique des adhésions (le classeur importé). Aucun
 * montant inventé : sans historique, un tiret.
 *
 * @phpstan-type Responsable array{role: string, email: string, nom: ?string, initiales: string, acceptee: bool, envoyee: bool}
 * @phpstan-type Ligne array{ville: Ville, membres: int, responsables: list<Responsable>, adherents: int, avecHistorique: int, aJour: int, tauxAJour: ?int, collecte: int}
 */
final class VillesAssociation
{
    public const array STATUTS = ['tous', 'actives', 'brouillons', 'archivees'];
    /** Panneau « Filtres » : responsables (tous, au moins une invitation non acceptée, aucun responsable) et membres (tous, avec, sans). */
    public const array RESPONSABLES = ['tous', 'en_attente', 'aucun'];
    public const array MEMBRES = ['tous', 'avec', 'sans'];

    public function __construct(
        private readonly VilleRepository $villes,
        private readonly MembreRepository $membres,
        private readonly AdhesionRepository $adhesions,
        private readonly UtilisateurRepository $utilisateurs,
    ) {
    }

    /**
     * Les chiffres se lisent pour l'année demandée, sinon pour l'année de référence. Les compteurs des chips comptent
     * toutes les villes par statut ; la recherche, le statut et le panneau « Filtres » ne restreignent que les lignes.
     *
     * @return array{annee: int, anneeReference: int, annees: list<int>, lignes: list<Ligne>, compteurs: array<string, int>, totalCollecte: int}
     */
    public function tableau(Association $association, string $statut, string $recherche, \DateTimeImmutable $aujourdhui, ?int $annee = null, string $filtreResponsables = 'tous', string $filtreMembres = 'tous'): array
    {
        $villes = $this->villes->listerPourAssociation($association);
        $anneeReference = $this->anneeDeReference($association, $aujourdhui);
        $annee ??= $anneeReference;
        $membresParVille = $this->membres->compterParVille($association, null);

        $compteurs = ['tous' => \count($villes), 'actives' => 0, 'brouillons' => 0, 'archivees' => 0];
        foreach ($villes as $ville) {
            ++$compteurs[self::statutDe($ville)];
        }

        // Les brouillons d'abord (ils attendent une action), puis les actives, puis les archivées ; par nom à statut égal.
        $rang = static fn (Ville $v): int => $v->estBrouillon() ? 0 : ($v->estActive() ? 1 : 2);
        usort($villes, static fn (Ville $a, Ville $b): int => $rang($a) <=> $rang($b) ?: strcoll($a->getNom(), $b->getNom()));

        $lignes = [];
        $totalCollecte = 0;
        foreach ($villes as $ville) {
            if ('tous' !== $statut && self::statutDe($ville) !== $statut) {
                continue;
            }
            $responsables = $this->responsables($ville);
            if (!self::correspond($ville, $responsables, $recherche)) {
                continue;
            }
            $membres = $membresParVille[(int) $ville->getId()] ?? 0;
            if (!self::correspondAuxFiltres($responsables, $filtreResponsables, $membres, $filtreMembres)) {
                continue;
            }
            $synthese = $this->adhesions->synthese($ville, $annee);
            $lignes[] = [
                'ville' => $ville,
                'membres' => $membres,
                'responsables' => $responsables,
                'adherents' => $synthese['adherents'],
                'avecHistorique' => $synthese['avecHistorique'],
                'aJour' => $synthese['aJour'],
                'tauxAJour' => $synthese['avecHistorique'] > 0 ? (int) round($synthese['aJour'] / $synthese['avecHistorique'] * 100) : null,
                'collecte' => $synthese['collecte'],
            ];
            $totalCollecte += $synthese['collecte'];
        }

        return [
            'annee' => $annee,
            'anneeReference' => $anneeReference,
            'annees' => $this->annees($association, $aujourdhui),
            'lignes' => $lignes,
            'compteurs' => $compteurs,
            'totalCollecte' => $totalCollecte,
        ];
    }

    /**
     * Les années proposées au filtre : celles qui ont des adhérents, plus les exercices suivis (depuis le premier
     * exercice de l'association jusqu'à l'année en cours), de la plus récente à la plus ancienne.
     *
     * @return list<int>
     */
    public function annees(Association $association, \DateTimeImmutable $aujourdhui): array
    {
        $annees = array_map('intval', array_keys($this->membres->compterParAnnee($association, null)));
        array_push($annees, ...$association->exercices((int) $aujourdhui->format('Y')));
        $annees = array_values(array_unique($annees));
        rsort($annees);

        return $annees;
    }

    /** L'année en cours dès qu'elle a des adhérents, sinon la dernière année qui en a : celle du classeur importé. */
    public function anneeDeReference(Association $association, \DateTimeImmutable $aujourdhui): int
    {
        $parAnnee = $this->membres->compterParAnnee($association, null);
        $anneeCourante = (int) $aujourdhui->format('Y');

        return ($parAnnee[$anneeCourante] ?? 0) > 0 || [] === $parAnnee ? $anneeCourante : (int) array_key_first($parAnnee);
    }

    public static function statutDe(Ville $ville): string
    {
        return $ville->estBrouillon() ? 'brouillons' : ($ville->estActive() ? 'actives' : 'archivees');
    }

    /**
     * Les responsables invités d'une ville : la personne quand elle a son compte, sinon son adresse.
     *
     * @return list<Responsable>
     */
    private function responsables(Ville $ville): array
    {
        $responsables = [];
        foreach ($ville->getInvitations() as $invitation) {
            \assert($invitation instanceof InvitationResponsable);
            $compte = $this->utilisateurs->trouverParEmail($invitation->getEmail());
            $responsables[] = [
                'role' => $invitation->getRole()->value,
                'email' => $invitation->getEmail(),
                'nom' => null !== $compte ? \sprintf('%s %s.', $compte->getPrenom(), mb_substr($compte->getNom(), 0, 1)) : null,
                'initiales' => null !== $compte
                    ? mb_strtoupper(mb_substr($compte->getPrenom(), 0, 1).mb_substr($compte->getNom(), 0, 1))
                    : mb_strtoupper(mb_substr((string) strtok($invitation->getEmail(), '@'), 0, 2)),
                'acceptee' => null !== $invitation->getAccepteeLe(),
                'envoyee' => $invitation->estEnvoyee(),
            ];
        }

        return $responsables;
    }

    /** @param list<Responsable> $responsables */
    private static function correspondAuxFiltres(array $responsables, string $filtreResponsables, int $membres, string $filtreMembres): bool
    {
        $enAttente = array_filter($responsables, static fn (array $r): bool => !$r['acceptee']);
        $responsablesOk = match ($filtreResponsables) {
            'en_attente' => [] !== $enAttente,
            'aucun' => [] === $responsables,
            default => true,
        };
        $membresOk = match ($filtreMembres) {
            'avec' => $membres > 0,
            'sans' => 0 === $membres,
            default => true,
        };

        return $responsablesOk && $membresOk;
    }

    /** @param list<Responsable> $responsables */
    private static function correspond(Ville $ville, array $responsables, string $recherche): bool
    {
        if ('' === trim($recherche)) {
            return true;
        }
        if (Texte::contient($ville->getNom(), $recherche)) {
            return true;
        }
        foreach ($responsables as $responsable) {
            if (Texte::contient($responsable['email'], $recherche) || (null !== $responsable['nom'] && Texte::contient($responsable['nom'], $recherche))) {
                return true;
            }
        }

        return false;
    }
}
