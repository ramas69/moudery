<?php

declare(strict_types=1);

namespace App\Association;

use App\Administration\Periode;
use App\Administration\Statistiques;
use App\Administration\TableauVilles;
use App\Entity\Abonnement;
use App\Entity\Association;
use App\Entity\Evenement;
use App\Entity\Invitation;
use App\Entity\RoleVille;
use App\Entity\UtilisateurStatut;
use App\Entity\Ville;
use App\Repository\AdhesionRepository;
use App\Repository\EvenementRepository;
use App\Repository\InvitationRepository;
use App\Repository\UtilisateurRepository;
use App\Repository\VilleRepository;

/**
 * Le tableau de bord du bureau central (F-28, d'après l'artboard « Tableau de bord » du canevas Console), avec ce qui
 * existe aujourd'hui : villes et leur avancement, responsables, invitations, abonnement, activité. Les encaissements,
 * dépenses et reversements viendront avec leurs modules ; rien n'est inventé en attendant.
 *
 * @phpstan-type ATraiter array{type: string, ville: ?Ville, lien: ?string, details: array<string, mixed>}
 */
final class TableauDeBordAssociation
{
    public const int NOMBRE_EVENEMENTS = 8;

    public function __construct(
        private readonly VilleRepository $villes,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly InvitationRepository $invitations,
        private readonly EvenementRepository $evenements,
        private readonly Statistiques $statistiques,
        private readonly AdhesionRepository $adhesions,
        private readonly \App\Repository\DepenseRepository $depenses,
        private readonly \App\Repository\EcheanceRepository $echeances,
    ) {
    }

    /** @return list<Ville> actives d'abord, puis brouillons, puis archivées ; par nom à statut égal */
    public function villes(Association $association): array
    {
        $villes = $this->villes->listerPourAssociation($association);
        $rang = static fn (Ville $v): int => $v->estActive() ? 0 : ($v->estBrouillon() ? 1 : 2);
        usort($villes, static fn (Ville $a, Ville $b): int => $rang($a) <=> $rang($b) ?: strcoll($a->getNom(), $b->getNom()));

        return $villes;
    }

    /**
     * Les chiffres du haut de page.
     *
     * @return array{villes: int, actives: int, brouillons: int, archivees: int, avancement: ?int, comptes: int, comptesEnAttente: int, invitationsEnCours: int, invitationsExpirees: int, abonnement: ?Abonnement}
     */
    public function synthese(Association $association, \DateTimeImmutable $aujourdhui): array
    {
        $villes = $this->villes->listerPourAssociation($association);
        $brouillons = array_values(array_filter($villes, static fn (Ville $v): bool => $v->estBrouillon()));
        $avancement = [] === $brouillons ? null : (int) round(array_sum(array_map(static fn (Ville $v): int => $v->getEtapeAssistant()->avancement(), $brouillons)) / \count($brouillons));

        $comptes = $this->utilisateurs->listerPourAssociation($association);
        $enCours = $this->invitations->enCoursParAssociation($association);

        return [
            'villes' => \count($villes),
            'actives' => \count(array_filter($villes, static fn (Ville $v): bool => $v->estActive())),
            'brouillons' => \count($brouillons),
            'archivees' => \count(array_filter($villes, static fn (Ville $v): bool => $v->estArchivee())),
            'avancement' => $avancement,
            'comptes' => \count(array_filter($comptes, static fn ($c): bool => $c->estActif())),
            'comptesEnAttente' => \count(array_filter($comptes, static fn ($c): bool => UtilisateurStatut::EnAttente === $c->getStatut())),
            'invitationsEnCours' => \count(array_filter($enCours, static fn (Invitation $i): bool => !$i->estExpiree($aujourdhui))),
            'invitationsExpirees' => \count(array_filter($enCours, static fn (Invitation $i): bool => $i->estExpiree($aujourdhui))),
            'abonnement' => $association->getAbonnement(),
        ];
    }

    /**
     * Ce qui attend le bureau central, par urgence : abonnement en retard, brouillons bloqués, brouillons sans trésorier,
     * invitations de responsables expirées, premier paiement ou échéance proche.
     *
     * @return list<ATraiter>
     */
    public function aTraiter(Association $association, \DateTimeImmutable $aujourdhui): array
    {
        $elements = [];
        $abonnement = $association->getAbonnement();
        if (null !== $abonnement && $abonnement->estEnRetard($aujourdhui)) {
            $elements[] = ['type' => 'abonnement_en_retard', 'ville' => null, 'lien' => null, 'details' => ['montant' => $abonnement->getMontant(), 'echeance' => $abonnement->getProchaineEcheanceLe()]];
        }

        foreach ($this->villes->listerPourAssociation($association) as $ville) {
            if (!$ville->estBrouillon()) {
                continue;
            }
            if (TableauVilles::estBloquee($ville, $aujourdhui)) {
                $elements[] = ['type' => 'ville_bloquee', 'ville' => $ville, 'lien' => 'reprendre', 'details' => ['jours' => (int) $ville->getModifieLe()->diff($aujourdhui)->days]];
            } elseif (null === $ville->invitationPour(RoleVille::Tresorier)) {
                $elements[] = ['type' => 'ville_sans_tresorier', 'ville' => $ville, 'lien' => 'identite', 'details' => []];
            }
        }

        foreach ($this->invitations->enCoursParAssociation($association) as $invitation) {
            if ($invitation->estExpiree($aujourdhui) && null !== $invitation->getVille()) {
                $elements[] = ['type' => 'invitation_expiree', 'ville' => $invitation->getVille(), 'lien' => 'identite', 'details' => ['email' => $invitation->getEmail(), 'role' => $invitation->getRole()->value]];
            }
        }

        if (null !== $abonnement && !$abonnement->estEnRetard($aujourdhui) && $abonnement->echeanceProche($aujourdhui)) {
            $elements[] = ['type' => null === $abonnement->getDernierPaiementLe() ? 'premier_paiement' : 'echeance_proche', 'ville' => null, 'lien' => null, 'details' => ['montant' => $abonnement->getMontant(), 'echeance' => $abonnement->getProchaineEcheanceLe()]];
        }

        return $elements;
    }

    /** L'exercice en cours d'après le mois de début de l'association : « 2026 – 2027 », ou « 2026 » s'il suit l'année civile. */
    public function exercice(Association $association, \DateTimeImmutable $aujourdhui): string
    {
        return $this->libelleExercice($association, $this->anneeExercice($association, $aujourdhui));
    }

    /** « 2026 – 2027 », ou « 2026 » si l'exercice suit l'année civile. */
    public function libelleExercice(Association $association, int $anneeDebut): string
    {
        return 1 === $association->getDebutExerciceMois() ? (string) $anneeDebut : \sprintf('%d – %d', $anneeDebut, $anneeDebut + 1);
    }

    /**
     * Les exercices suivis, du plus récent au premier (`Association::exercices()`, 2025 par défaut), avec leur libellé :
     * le sélecteur d'exercice du tableau de bord.
     *
     * @return list<array{annee: int, libelle: string}>
     */
    public function exercices(Association $association, \DateTimeImmutable $aujourdhui): array
    {
        $exercices = [];
        foreach ($association->exercices($this->anneeExercice($association, $aujourdhui)) as $annee) {
            $exercices[] = ['annee' => $annee, 'libelle' => $this->libelleExercice($association, $annee)];
        }

        return $exercices;
    }

    /** L'année civile où commence l'exercice en cours. */
    public function anneeExercice(Association $association, \DateTimeImmutable $aujourdhui): int
    {
        return (int) $aujourdhui->format('Y') - ((int) $aujourdhui->format('n') < $association->getDebutExerciceMois() ? 1 : 0);
    }

    /**
     * Le premier et le dernier jour de l'exercice qui commence cette année-là.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function bornesExercice(Association $association, int $anneeDebut): array
    {
        $mois = $this->moisDeLExercice($association, $anneeDebut);
        $premier = $mois[0];
        $dernier = $mois[11];
        $debut = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $premier['annee'], $premier['mois']));
        $fin = (new \DateTimeImmutable(\sprintf('%04d-%02d-01', $dernier['annee'], $dernier['mois'])))->modify('last day of this month');

        return [$debut, $fin];
    }

    /**
     * Les douze mois de l'exercice qui commence cette année-là : mois civil et année civile de chacun.
     *
     * @return list<array{mois: int, annee: int}>
     */
    public function moisDeLExercice(Association $association, int $anneeDebut): array
    {
        $debut = $association->getDebutExerciceMois();
        $mois = [];
        for ($i = 0; $i < 12; ++$i) {
            $m = ($debut - 1 + $i) % 12 + 1;
            $mois[] = ['mois' => $m, 'annee' => $m >= $debut ? $anneeDebut : $anneeDebut + 1];
        }

        return $mois;
    }

    /**
     * Les chiffres financiers de la maquette, calculés depuis l'historique des adhésions (les mois des classeurs) :
     * collecté sur l'exercice et variation avec l'exercice précédent, membres à jour (reste dû nul parmi les adhérents
     * dont on connaît les versements), encaissements mois par mois. Les contributions ponctuelles sont les échéances
     * d'appel payées (mois du paiement) ; « collecte » additionne cotisations et contributions ponctuelles, « cotisations »
     * et « ponctuelles » les détaillent.
     *
     * L'exercice demandé, sinon celui en cours ; pas de comparaison avant le premier exercice suivi (`exercicePrecedent` null).
     *
     * @return array{annee: int, exercicePrecedent: ?string, collecte: int, cotisations: int, ponctuelles: int, collectePrecedent: int, variation: int, depense: int, solde: int, adherents: int, avecHistorique: int, aJour: int, pourcentageAJour: int, reversements: int, villesEnRetard: int, mois: list<array{mois: int, annee: int, cotisations: int, ponctuelles: int}>}
     */
    public function finances(Association $association, ?Ville $ville, \DateTimeImmutable $aujourdhui, ?int $anneeDebut = null): array
    {
        $anneeDebut ??= $this->anneeExercice($association, $aujourdhui);
        $aUnPrecedent = $anneeDebut - 1 >= $association->getPremierExercice();
        $moisExercice = $this->moisDeLExercice($association, $anneeDebut);
        $moisPrecedent = $this->moisDeLExercice($association, $anneeDebut - 1);
        $annees = array_values(array_unique([...array_column($moisExercice, 'annee'), ...array_column($moisPrecedent, 'annee')]));
        $adhesions = $this->adhesions->listerPourAnnees($association, $annees, $ville);

        $ponctuellesParMois = $this->ponctuellesParMois($association, $ville, $moisPrecedent[0], $moisExercice[11]);
        $mois = [];
        foreach ($moisExercice as $m) {
            $mois[] = ['mois' => $m['mois'], 'annee' => $m['annee'], 'cotisations' => self::sommeDuMois($adhesions, $m['mois'], $m['annee']), 'ponctuelles' => $ponctuellesParMois[\sprintf('%04d-%02d', $m['annee'], $m['mois'])] ?? 0];
        }
        $cotisations = array_sum(array_column($mois, 'cotisations'));
        $ponctuelles = array_sum(array_column($mois, 'ponctuelles'));
        $collecte = $cotisations + $ponctuelles;
        $collectePrecedent = 0;
        foreach ($moisPrecedent as $m) {
            $collectePrecedent += self::sommeDuMois($adhesions, $m['mois'], $m['annee']) + ($ponctuellesParMois[\sprintf('%04d-%02d', $m['annee'], $m['mois'])] ?? 0);
        }

        $adherents = 0;
        $avecHistorique = 0;
        $aJour = 0;
        foreach ($adhesions as $adhesion) {
            if ($adhesion->getAnnee() !== $anneeDebut) {
                continue;
            }
            ++$adherents;
            if ($adhesion->aUnHistorique()) {
                ++$avecHistorique;
                if (0 === $adhesion->getReste()) {
                    ++$aJour;
                }
            }
        }

        // Dépensé : les dépenses payées de l'exercice (seule une dépense payée touche le solde).
        $premier = $moisExercice[0];
        $dernier = $moisExercice[\count($moisExercice) - 1];
        $depense = $this->depenses->totalPaye(
            $association,
            $ville,
            new \DateTimeImmutable(\sprintf('%d-%02d-01', $premier['annee'], $premier['mois'])),
            (new \DateTimeImmutable(\sprintf('%d-%02d-01', $dernier['annee'], $dernier['mois'])))->modify('last day of this month'),
        );

        return [
            'annee' => $anneeDebut,
            'exercicePrecedent' => $aUnPrecedent ? $this->libelleExercice($association, $anneeDebut - 1) : null,
            'collecte' => $collecte,
            'cotisations' => $cotisations,
            'ponctuelles' => $ponctuelles,
            'collectePrecedent' => $aUnPrecedent ? $collectePrecedent : 0,
            'variation' => $aUnPrecedent && $collectePrecedent > 0 ? (int) round(($collecte - $collectePrecedent) / $collectePrecedent * 100) : 0,
            'depense' => $depense,
            'solde' => $collecte - $depense,
            'adherents' => $adherents,
            'avecHistorique' => $avecHistorique,
            'aJour' => $aJour,
            'pourcentageAJour' => $avecHistorique > 0 ? (int) round($aJour / $avecHistorique * 100) : 0,
            'reversements' => 0,
            'villesEnRetard' => 0,
            'mois' => $mois,
        ];
    }

    /**
     * Par ville, les mêmes chiffres sur l'exercice en cours : adhérents, membres à jour, collecté (reversement dû : zéro).
     *
     * @return array<int, array{adherents: int, avecHistorique: int, aJour: int, pourcentageAJour: int, collecte: int, reversement: int}>
     */
    public function villesFinances(Association $association, \DateTimeImmutable $aujourdhui, ?int $anneeDebut = null): array
    {
        $anneeDebut ??= $this->anneeExercice($association, $aujourdhui);
        $moisExercice = $this->moisDeLExercice($association, $anneeDebut);
        $adhesions = $this->adhesions->listerPourAnnees($association, array_values(array_unique(array_column($moisExercice, 'annee'))));

        $parVille = [];
        foreach ($adhesions as $adhesion) {
            $id = (int) $adhesion->getVille()->getId();
            $parVille[$id] ??= ['adherents' => 0, 'avecHistorique' => 0, 'aJour' => 0, 'pourcentageAJour' => 0, 'collecte' => 0, 'reversement' => 0];
            if ($adhesion->getAnnee() === $anneeDebut) {
                ++$parVille[$id]['adherents'];
                if ($adhesion->aUnHistorique()) {
                    ++$parVille[$id]['avecHistorique'];
                    if (0 === $adhesion->getReste()) {
                        ++$parVille[$id]['aJour'];
                    }
                }
            }
            foreach ($moisExercice as $m) {
                if ($adhesion->getAnnee() === $m['annee']) {
                    $parVille[$id]['collecte'] += $adhesion->getMontantMois($m['mois']) ?? 0;
                }
            }
        }
        foreach ($parVille as &$chiffres) {
            $chiffres['pourcentageAJour'] = $chiffres['avecHistorique'] > 0 ? (int) round($chiffres['aJour'] / $chiffres['avecHistorique'] * 100) : 0;
        }

        return $parVille;
    }

    /**
     * Les échéances d'appel payées entre le premier mois donné et la fin du dernier, par mois de paiement (« AAAA-MM »).
     *
     * @param array{mois: int, annee: int} $premier
     * @param array{mois: int, annee: int} $dernier
     *
     * @return array<string, int>
     */
    private function ponctuellesParMois(Association $association, ?Ville $ville, array $premier, array $dernier): array
    {
        $requete = $this->echeances->createQueryBuilder('e')
            ->innerJoin('e.membre', 'm')
            ->andWhere('e.association = :association')
            ->andWhere('e.appel IS NOT NULL')
            ->andWhere('e.statut = :payee')
            ->andWhere('e.payeeLe >= :du AND e.payeeLe < :au')
            ->setParameter('association', $association)
            ->setParameter('payee', \App\Entity\EcheanceStatut::Payee)
            ->setParameter('du', new \DateTimeImmutable(\sprintf('%04d-%02d-01', $premier['annee'], $premier['mois'])))
            ->setParameter('au', (new \DateTimeImmutable(\sprintf('%04d-%02d-01', $dernier['annee'], $dernier['mois'])))->modify('first day of next month'));
        if (null !== $ville) {
            $requete->andWhere('m.ville = :ville')->setParameter('ville', $ville);
        }
        $parMois = [];
        foreach ($requete->getQuery()->getResult() as $echeance) {
            \assert($echeance instanceof \App\Entity\Echeance);
            $cle = (string) $echeance->getPayeeLe()?->format('Y-m');
            $parMois[$cle] = ($parMois[$cle] ?? 0) + ($echeance->getMontant() ?? 0);
        }

        return $parMois;
    }

    /** @param list<\App\Entity\Adhesion> $adhesions */
    private static function sommeDuMois(array $adhesions, int $mois, int $annee): int
    {
        $somme = 0;
        foreach ($adhesions as $adhesion) {
            if ($adhesion->getAnnee() === $annee) {
                $somme += $adhesion->getMontantMois($mois) ?? 0;
            }
        }

        return $somme;
    }

    /** @return list<Evenement> */
    public function activite(Association $association): array
    {
        return $this->evenements->derniersPour($association, self::NOMBRE_EVENEMENTS);
    }

    /** @return array{connexions: list<int>, actions: list<int>, totalConnexions: int, totalActions: int, parCategorie: array<string, int>} */
    public function serieActivite(Association $association, Periode $periode): array
    {
        return $this->statistiques->activite($periode, $association);
    }
}
