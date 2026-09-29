<?php

declare(strict_types=1);

namespace App\Administration;

use App\Entity\Abonnement;
use App\Entity\AbonnementStatut;
use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\AssociationStatut;
use App\Entity\Evenement;
use App\Entity\Invitation;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Repository\AbonnementRepository;
use App\Repository\AssociationRepository;
use App\Repository\EvenementRepository;
use App\Repository\InvitationRepository;
use App\Repository\PaiementAbonnementRepository;
use App\Repository\UtilisateurRepository;
use App\Repository\VilleRepository;
use App\Security\Role;

/**
 * Les séries des graphiques du tableau de bord super-admin. Tout se calcule en mémoire à partir des entités,
 * par tranche de la période demandée ; les volumes de la plateforme (dizaines d'associations) le permettent largement.
 *
 * @phpstan-type Serie list<int>
 */
final class Statistiques
{
    /** Un compte est considéré actif s'il s'est connecté dans les 30 derniers jours. */
    public const int JOURS_ACTIVITE = 30;

    public function __construct(
        private readonly AssociationRepository $associations,
        private readonly VilleRepository $villes,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly InvitationRepository $invitations,
        private readonly AbonnementRepository $abonnements,
        private readonly PaiementAbonnementRepository $paiements,
        private readonly EvenementRepository $evenements,
    ) {
    }

    /**
     * Croissance : ce qui a été créé dans chaque tranche, et le cumul à la fin de chaque tranche.
     *
     * @return array{associations: Serie, villes: Serie, comptes: Serie, cumulAssociations: Serie, cumulVilles: Serie, cumulComptes: Serie}
     */
    public function croissance(Periode $periode, ?Association $association, ?AssociationStatut $statut): array
    {
        $associations = array_filter($this->associations->findAll(), static fn (Association $a): bool => (null === $association || $a === $association) && (null === $statut || $a->getStatut() === $statut));
        $villes = array_filter($this->villes->findAll(), static fn (Ville $v): bool => (null === $association || $v->getAssociation() === $association) && (null === $statut || $v->getAssociation()->getStatut() === $statut));
        $comptes = array_filter($this->utilisateurs->findAll(), static fn (Utilisateur $u): bool => null !== $u->getAssociation() && (null === $association || $u->getAssociation() === $association) && (null === $statut || $u->getAssociation()->getStatut() === $statut));

        $dates = static fn (array $entites): array => array_map(static fn (object $e): \DateTimeImmutable => $e->getCreeLe(), array_values($entites));
        $seriesAssociations = $this->repartir($periode, $dates($associations));
        $seriesVilles = $this->repartir($periode, $dates($villes));
        $seriesComptes = $this->repartir($periode, $dates($comptes));

        return [
            'associations' => $seriesAssociations,
            'villes' => $seriesVilles,
            'comptes' => $seriesComptes,
            'cumulAssociations' => $this->cumuler($periode, $dates($associations)),
            'cumulVilles' => $this->cumuler($periode, $dates($villes)),
            'cumulComptes' => $this->cumuler($periode, $dates($comptes)),
        ];
    }

    /**
     * Où en sont les villes : les brouillons par étape de l'assistant, puis les actives et les archivées.
     *
     * @return array{etapes: array<int, int>, actives: int, archivees: int, total: int}
     */
    public function villesParEtape(?Association $association, ?VilleStatut $statut): array
    {
        $etapes = array_fill(1, EtapeAssistant::nombre(), 0);
        $actives = 0;
        $archivees = 0;
        $total = 0;
        foreach ($this->villes->findAll() as $ville) {
            if ((null !== $association && $ville->getAssociation() !== $association) || (null !== $statut && $ville->getStatut() !== $statut)) {
                continue;
            }
            ++$total;
            match ($ville->getStatut()) {
                VilleStatut::Brouillon => ++$etapes[$ville->getEtapeAssistant()->numero()],
                VilleStatut::Active => ++$actives,
                VilleStatut::Archivee => ++$archivees,
            };
        }

        return ['etapes' => $etapes, 'actives' => $actives, 'archivees' => $archivees, 'total' => $total];
    }

    /**
     * Les abonnements par statut réel (le retard déduit de l'échéance compte comme retard), avec leur revenu mensuel.
     *
     * @return array<string, array{nombre: int, mensuel: int}> indexé par valeur de statut
     */
    public function abonnementsParStatut(?Association $association, \DateTimeImmutable $aujourdhui): array
    {
        $repartition = [];
        foreach (AbonnementStatut::cases() as $statut) {
            $repartition[$statut->value] = ['nombre' => 0, 'mensuel' => 0];
        }
        foreach ($this->abonnements->findAll() as $abonnement) {
            if (null !== $association && $abonnement->getAssociation() !== $association) {
                continue;
            }
            $statut = $abonnement->estEnRetard($aujourdhui) ? AbonnementStatut::EnRetard : $abonnement->getStatut();
            ++$repartition[$statut->value]['nombre'];
            $repartition[$statut->value]['mensuel'] += $abonnement->montantMensuel();
        }

        return $repartition;
    }

    /**
     * Invitations : envoyées, acceptées, expirées par tranche ; taux d'acceptation et délai moyen sur la période.
     *
     * @return array{envoyees: Serie, acceptees: Serie, expirees: Serie, totalEnvoyees: int, totalAcceptees: int, taux: ?float, delaiMoyenHeures: ?float}
     */
    public function invitations(Periode $periode, ?Association $association, \DateTimeImmutable $aujourdhui): array
    {
        $invitations = array_filter($this->invitations->findAll(), static fn (Invitation $i): bool => null === $association || $i->getAssociation() === $association);
        $envoyees = [];
        $acceptees = [];
        $expirees = [];
        $delais = [];
        foreach ($invitations as $invitation) {
            $envoyees[] = $invitation->getEnvoyeeLe();
            if (null !== $invitation->getAccepteeLe()) {
                $acceptees[] = $invitation->getAccepteeLe();
                if (null !== $periode->indice($invitation->getAccepteeLe())) {
                    $delais[] = ($invitation->getAccepteeLe()->getTimestamp() - $invitation->getEnvoyeeLe()->getTimestamp()) / 3600;
                }
            } elseif ($invitation->getExpireLe() < $aujourdhui) {
                $expirees[] = $invitation->getExpireLe();
            }
        }
        $serieEnvoyees = $this->repartir($periode, $envoyees);
        $serieAcceptees = $this->repartir($periode, $acceptees);
        $totalEnvoyees = array_sum($serieEnvoyees);
        $totalAcceptees = array_sum($serieAcceptees);

        return [
            'envoyees' => $serieEnvoyees,
            'acceptees' => $serieAcceptees,
            'expirees' => $this->repartir($periode, $expirees),
            'totalEnvoyees' => $totalEnvoyees,
            'totalAcceptees' => $totalAcceptees,
            'taux' => $totalEnvoyees > 0 ? $totalAcceptees / $totalEnvoyees : null,
            'delaiMoyenHeures' => [] === $delais ? null : array_sum($delais) / \count($delais),
        ];
    }

    /**
     * Les comptes des associations (jamais les super-admins) : actifs récemment connectés, actifs jamais ou plus connectés,
     * en attente, désactivés. Un rôle filtre sur les affectations.
     *
     * @return array{connectes: int, inactifs: int, jamaisConnectes: int, enAttente: int, desactives: int, total: int}
     */
    public function comptes(?Association $association, ?Role $role, \DateTimeImmutable $aujourdhui): array
    {
        $resultat = ['connectes' => 0, 'inactifs' => 0, 'jamaisConnectes' => 0, 'enAttente' => 0, 'desactives' => 0, 'total' => 0];
        $limite = $aujourdhui->modify(\sprintf('-%d days', self::JOURS_ACTIVITE));
        foreach ($this->utilisateurs->findAll() as $compte) {
            if (null === $compte->getAssociation() || (null !== $association && $compte->getAssociation() !== $association)) {
                continue;
            }
            if (null !== $role && !self::aLeRole($compte, $role)) {
                continue;
            }
            ++$resultat['total'];
            if (UtilisateurStatut::EnAttente === $compte->getStatut()) {
                ++$resultat['enAttente'];
            } elseif (UtilisateurStatut::Desactive === $compte->getStatut()) {
                ++$resultat['desactives'];
            } elseif (null === $compte->getDerniereConnexionLe()) {
                ++$resultat['jamaisConnectes'];
            } elseif ($compte->getDerniereConnexionLe() >= $limite) {
                ++$resultat['connectes'];
            } else {
                ++$resultat['inactifs'];
            }
        }

        return $resultat;
    }

    /**
     * Encaissements : reçus par tranche (historique des paiements), attendus par tranche (échéances projetées
     * des abonnements payants, à partir d'aujourd'hui), et le retard actuel.
     *
     * @return array{recus: Serie, attendus: Serie, totalRecu: int, totalAttendu: int, enRetard: int}
     */
    public function encaissements(Periode $periode, ?Association $association, \DateTimeImmutable $aujourdhui): array
    {
        $tranches = $periode->tranches();
        $recus = array_fill(0, \count($tranches), 0);
        foreach ($this->paiements->entre($periode->debut, $periode->fin, $association) as $paiement) {
            $indice = $periode->indice($paiement->getRecuLe());
            if (null !== $indice) {
                $recus[$indice] += $paiement->getMontant();
            }
        }

        $attendus = array_fill(0, \count($tranches), 0);
        $enRetard = 0;
        $jour = $aujourdhui->setTime(0, 0);
        foreach ($this->abonnements->findAll() as $abonnement) {
            if (null !== $association && $abonnement->getAssociation() !== $association) {
                continue;
            }
            $echeance = $abonnement->getProchaineEcheanceLe();
            if (!$abonnement->estFacturable() || null === $echeance || 0 === $abonnement->getMontant()) {
                continue;
            }
            $intervalle = $abonnement->getPeriodicite()->intervalle();
            if ($abonnement->estEnRetard($aujourdhui)) {
                $enRetard += $abonnement->getMontant();
                $echeance = $jour->add($intervalle);
            }
            while ($echeance < $periode->fin) {
                $indice = $periode->indice($echeance);
                if (null !== $indice) {
                    $attendus[$indice] += $abonnement->getMontant();
                }
                $echeance = $echeance->add($intervalle);
            }
        }

        return ['recus' => $recus, 'attendus' => $attendus, 'totalRecu' => array_sum($recus), 'totalAttendu' => array_sum($attendus), 'enRetard' => $enRetard];
    }

    /**
     * Le revenu mensuel récurrent à la fin de chaque tranche, retracé depuis le journal : le dernier instantané
     * de chaque abonnement avant la fin de la tranche donne son statut et son montant à ce moment-là.
     *
     * @return array{mensuel: Serie, payants: Serie}
     */
    public function revenuRecurrent(Periode $periode, ?Association $association): array
    {
        $evenements = $this->evenements->abonnementsJusqua($periode->fin, $association);
        $tranches = $periode->tranches();
        $mensuel = [];
        $payants = [];
        $curseur = 0;
        /** @var array<int, array{mensuel: int, facturable: bool}> $etats indexés par association */
        $etats = [];
        foreach ($tranches as $tranche) {
            while ($curseur < \count($evenements) && $evenements[$curseur]->getQuand() < $tranche['fin']) {
                $evenement = $evenements[$curseur];
                $associationId = (int) $evenement->getAssociation()?->getId();
                $statut = (string) $evenement->detail('statut');
                $etats[$associationId] = [
                    'mensuel' => (int) $evenement->detail('mensuel'),
                    'facturable' => \in_array($statut, [AbonnementStatut::Actif->value, AbonnementStatut::EnRetard->value], true),
                ];
                ++$curseur;
            }
            $mensuel[] = array_sum(array_map(static fn (array $e): int => $e['facturable'] ? $e['mensuel'] : 0, $etats));
            $payants[] = \count(array_filter($etats, static fn (array $e): bool => $e['facturable']));
        }

        return ['mensuel' => $mensuel, 'payants' => $payants];
    }

    /**
     * L'activité consignée au journal : connexions et autres actions par tranche.
     *
     * @return array{connexions: Serie, actions: Serie, totalConnexions: int, totalActions: int, parCategorie: array<string, int>}
     */
    public function activite(Periode $periode, ?Association $association): array
    {
        $tranches = $periode->tranches();
        $connexions = array_fill(0, \count($tranches), 0);
        $actions = array_fill(0, \count($tranches), 0);
        $parCategorie = [];
        foreach ($this->evenements->entre($periode->debut, $periode->fin, $association) as $evenement) {
            $indice = $periode->indice($evenement->getQuand());
            if (null === $indice) {
                continue;
            }
            $categorie = $evenement->getType()->categorie();
            $parCategorie[$categorie] = ($parCategorie[$categorie] ?? 0) + 1;
            if (TypeEvenement::Connexion === $evenement->getType()) {
                ++$connexions[$indice];
            } else {
                ++$actions[$indice];
            }
        }

        return ['connexions' => $connexions, 'actions' => $actions, 'totalConnexions' => array_sum($connexions), 'totalActions' => array_sum($actions), 'parCategorie' => $parCategorie];
    }

    /** @param list<\DateTimeImmutable> $dates @return Serie une valeur par tranche */
    private function repartir(Periode $periode, array $dates): array
    {
        $serie = array_fill(0, \count($periode->tranches()), 0);
        foreach ($dates as $date) {
            $indice = $periode->indice($date);
            if (null !== $indice) {
                ++$serie[$indice];
            }
        }

        return $serie;
    }

    /** @param list<\DateTimeImmutable> $dates @return Serie le total existant à la fin de chaque tranche */
    private function cumuler(Periode $periode, array $dates): array
    {
        $cumul = [];
        foreach ($periode->tranches() as $tranche) {
            $cumul[] = \count(array_filter($dates, static fn (\DateTimeImmutable $d): bool => $d < $tranche['fin']));
        }

        return $cumul;
    }

    private static function aLeRole(Utilisateur $compte, Role $role): bool
    {
        foreach ($compte->getAffectations() as $affectation) {
            if ($affectation->getRole() === $role) {
                return true;
            }
        }

        return false;
    }
}
