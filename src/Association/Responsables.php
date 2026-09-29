<?php

declare(strict_types=1);

namespace App\Association;

use App\Administration\Texte;
use App\Entity\Affectation;
use App\Entity\Association;
use App\Entity\Evenement;
use App\Entity\Invitation;
use App\Entity\InvitationResponsable;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Entity\Ville;
use App\Repository\EvenementRepository;
use App\Repository\InvitationRepository;
use App\Repository\MembreRepository;
use App\Repository\UtilisateurRepository;
use App\Repository\VilleRepository;
use App\Security\Permission;
use App\Security\Role;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * « Responsables et rôles » de l'association (F-07, artboard 03 du canevas Console) : chaque affectation d'un compte,
 * chaque invitation en cours et chaque responsable « à envoyer à l'activation » d'une ville en brouillon, sur une
 * ligne ; filtres par personne, périmètre et rôle ; le journal des rôles. Aucune règle d'accès ici : la page vérifie
 * les droits, ce service dit seulement ce que la personne connectée peut faire sur chaque ligne.
 *
 * @phpstan-type Ligne array{cle: string, type: 'affectation'|'invitation'|'a_envoyer', nom: ?string, email: string, initiales: string, role: string, perimetre: 'association'|'ville', ville: ?Ville, detail: string, depuis: ?\DateTimeImmutable, statut: string, expireLe: ?\DateTimeImmutable, cumul: int, affectation: ?Affectation, invitation: ?Invitation, peutRetirer: bool, peutRenvoyer: bool}
 */
final class Responsables
{
    /** Les rôles d'une association, dans l'ordre d'affichage ; le super-admin plateforme n'en fait pas partie. */
    public const array ROLES = [Role::BureauCentral, Role::Tresorier, Role::President, Role::Secretaire, Role::Membre];

    /** Les événements qui parlent des responsables et de leurs rôles. */
    public const array TYPES_JOURNAL = [
        TypeEvenement::RoleAttribue, TypeEvenement::RoleRetire, TypeEvenement::InvitationEnvoyee, TypeEvenement::InvitationAcceptee,
        TypeEvenement::CompteCree, TypeEvenement::CompteStatut, TypeEvenement::VilleCreee,
    ];

    public function __construct(
        private readonly UtilisateurRepository $utilisateurs,
        private readonly InvitationRepository $invitations,
        private readonly VilleRepository $villes,
        private readonly MembreRepository $membres,
        private readonly EvenementRepository $evenements,
        private readonly Security $securite,
    ) {
    }

    /**
     * Toutes les lignes : l'association d'abord, puis les villes par nom ; dans une ville, trésorier, président,
     * secrétaire, membres ; les affectations avant les invitations.
     *
     * @return list<Ligne>
     */
    public function lignes(Association $association, \DateTimeImmutable $aujourdhui): array
    {
        $moi = $this->securite->getUser();
        $membresParVille = $this->membres->compterParVille($association, null);
        $lignes = [];
        $cumuls = [];
        $couverts = [];

        foreach ($this->utilisateurs->listerPourAssociation($association) as $compte) {
            foreach ($compte->getAffectations() as $affectation) {
                \assert($affectation instanceof Affectation);
                if (!\in_array($affectation->getRole(), self::ROLES, true)) {
                    continue;
                }
                $ville = $affectation->getVille();
                $cumuls[$compte->getEmail()] = ($cumuls[$compte->getEmail()] ?? 0) + 1;
                $couverts[self::cle($compte->getEmail(), $affectation->getRole()->value, $ville)] = true;
                $lignes[] = [
                    'cle' => 'a'.$affectation->getId(),
                    'type' => 'affectation',
                    'nom' => $compte->getNomComplet(),
                    'email' => $compte->getEmail(),
                    'initiales' => mb_strtoupper(mb_substr($compte->getPrenom(), 0, 1).mb_substr($compte->getNom(), 0, 1)),
                    'role' => $affectation->getRole()->value,
                    'perimetre' => null === $ville ? 'association' : 'ville',
                    'ville' => $ville,
                    'detail' => self::detailPerimetre($ville, $membresParVille),
                    'depuis' => $affectation->getCreeLe(),
                    'statut' => $compte->estActif() ? 'actif' : (UtilisateurStatut::EnAttente === $compte->getStatut() ? 'en-attente' : 'desactive'),
                    'expireLe' => null,
                    'cumul' => 0,
                    'affectation' => $affectation,
                    'invitation' => null,
                    'peutRetirer' => ($moi instanceof Utilisateur && $moi->getId() !== $compte->getId()) && $this->peutGerer($association, $affectation->getRole(), $ville),
                    'peutRenvoyer' => false,
                ];
            }
        }

        foreach ($this->invitations->enCoursParAssociation($association) as $invitation) {
            \assert($invitation instanceof Invitation);
            if (!\in_array($invitation->getRole(), self::ROLES, true)) {
                continue;
            }
            $ville = $invitation->getVille();
            $couverts[self::cle($invitation->getEmail(), $invitation->getRole()->value, $ville)] = true;
            $lignes[] = [
                'cle' => 'i'.$invitation->getId(),
                'type' => 'invitation',
                'nom' => null,
                'email' => $invitation->getEmail(),
                'initiales' => self::initialesEmail($invitation->getEmail()),
                'role' => $invitation->getRole()->value,
                'perimetre' => null === $ville ? 'association' : 'ville',
                'ville' => $ville,
                'detail' => self::detailPerimetre($ville, $membresParVille),
                'depuis' => null,
                'statut' => $invitation->estExpiree($aujourdhui) ? 'invitation-expiree' : 'invitation-envoyee',
                'expireLe' => $invitation->getExpireLe(),
                'cumul' => 0,
                'affectation' => null,
                'invitation' => $invitation,
                'peutRetirer' => false,
                'peutRenvoyer' => $this->peutGerer($association, $invitation->getRole(), $ville),
            ];
        }

        // Les responsables d'une ville en brouillon : leur invitation partira à l'activation (F-46).
        foreach ($this->villes->listerPourAssociation($association) as $ville) {
            if (!$ville->estBrouillon()) {
                continue;
            }
            foreach ($ville->getInvitations() as $responsable) {
                \assert($responsable instanceof InvitationResponsable);
                $role = Role::depuisRoleVille($responsable->getRole());
                if (isset($couverts[self::cle($responsable->getEmail(), $role->value, $ville)])) {
                    continue;
                }
                $lignes[] = [
                    'cle' => 'r'.$responsable->getId(),
                    'type' => 'a_envoyer',
                    'nom' => null,
                    'email' => $responsable->getEmail(),
                    'initiales' => self::initialesEmail($responsable->getEmail()),
                    'role' => $role->value,
                    'perimetre' => 'ville',
                    'ville' => $ville,
                    'detail' => self::detailPerimetre($ville, $membresParVille),
                    'depuis' => null,
                    'statut' => 'a-envoyer',
                    'expireLe' => null,
                    'cumul' => 0,
                    'affectation' => null,
                    'invitation' => null,
                    'peutRetirer' => false,
                    'peutRenvoyer' => false,
                ];
            }
        }

        foreach ($lignes as &$ligne) {
            $ligne['cumul'] = $cumuls[$ligne['email']] ?? 0;
        }
        unset($ligne);

        usort($lignes, static function (array $a, array $b): int {
            $rangPerimetre = static fn (array $l): int => 'association' === $l['perimetre'] ? 0 : 1;
            $rangRole = static fn (array $l): int => (int) array_search(Role::from($l['role']), self::ROLES, true);
            $rangType = static fn (array $l): int => 'affectation' === $l['type'] ? 0 : ('invitation' === $l['type'] ? 1 : 2);

            // Sans accents ni casse : « Évry » se range avant « Lyon », quelle que soit la locale du serveur.
            return $rangPerimetre($a) <=> $rangPerimetre($b)
                ?: strcmp(Texte::normaliser($a['ville']?->getNom() ?? ''), Texte::normaliser($b['ville']?->getNom() ?? ''))
                ?: $rangRole($a) <=> $rangRole($b)
                ?: $rangType($a) <=> $rangType($b)
                ?: strcmp(Texte::normaliser($a['nom'] ?? $a['email']), Texte::normaliser($b['nom'] ?? $b['email']));
        });

        return $lignes;
    }

    /**
     * @param list<Ligne> $lignes
     * @param string      $perimetre « tous », « association » ou un identifiant de ville
     * @param string      $role      « tous » ou une valeur de Role
     *
     * @return list<Ligne>
     */
    public static function filtrer(array $lignes, string $recherche, string $perimetre, string $role): array
    {
        return array_values(array_filter($lignes, static function (array $ligne) use ($recherche, $perimetre, $role): bool {
            if ('tous' !== $role && $ligne['role'] !== $role) {
                return false;
            }
            if ('association' === $perimetre && 'association' !== $ligne['perimetre']) {
                return false;
            }
            if ('tous' !== $perimetre && 'association' !== $perimetre && (string) $ligne['ville']?->getId() !== $perimetre) {
                return false;
            }
            if ('' !== trim($recherche) && !Texte::contient($ligne['email'], $recherche) && !(null !== $ligne['nom'] && Texte::contient($ligne['nom'], $recherche))) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param list<Ligne> $lignes
     *
     * @return array{affectations: int, personnes: int}
     */
    public static function compter(array $lignes): array
    {
        $personnes = [];
        foreach ($lignes as $ligne) {
            $personnes[mb_strtolower($ligne['email'])] = true;
        }

        return ['affectations' => \count($lignes), 'personnes' => \count($personnes)];
    }

    /**
     * Le journal des rôles : attributions, retraits, invitations, comptes créés, villes créées.
     *
     * @return list<Evenement>
     */
    public function journal(Association $association, int $limite): array
    {
        $retenus = [];
        foreach ($this->evenements->derniersPour($association, max(200, $limite * 4)) as $evenement) {
            if (\in_array($evenement->getType(), self::TYPES_JOURNAL, true)) {
                $retenus[] = $evenement;
                if (\count($retenus) >= $limite) {
                    break;
                }
            }
        }

        return $retenus;
    }

    /** La personne connectée peut-elle attribuer, retirer ou renvoyer ce rôle sur ce périmètre ? */
    public function peutGerer(Association $association, Role $role, ?Ville $ville): bool
    {
        if (null !== $ville) {
            return $this->securite->isGranted(Permission::VILLE_MODIFIER, $ville);
        }

        return $this->securite->isGranted(Permission::COMPTE_GERER, $association);
    }

    /** @param array<int, int> $membresParVille */
    private static function detailPerimetre(?Ville $ville, array $membresParVille): string
    {
        if (null === $ville) {
            return 'association';
        }
        if ($ville->estBrouillon()) {
            return 'brouillon';
        }

        return (string) ($membresParVille[(int) $ville->getId()] ?? 0);
    }

    private static function initialesEmail(string $email): string
    {
        return mb_strtoupper(mb_substr((string) strtok($email, '@'), 0, 2));
    }

    private static function cle(string $email, string $role, ?Ville $ville): string
    {
        return mb_strtolower($email).'|'.$role.'|'.($ville?->getId() ?? 0);
    }
}
