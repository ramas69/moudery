<?php

declare(strict_types=1);

namespace App\Membre;

use App\Association\Reversements;
use App\Association\TableauDeBordAssociation;
use App\Entity\Depense;
use App\Entity\DepenseStatut;
use App\Entity\Echeance;
use App\Entity\EcheanceStatut;
use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\Paiement;
use App\Entity\Utilisateur;
use App\Repository\DepenseRepository;
use App\Repository\EcheanceRepository;
use App\Repository\MembreRepository;
use App\Repository\PaiementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'espace du membre (F-26, F-27, F-04), d'après la maquette « Espace membre » : ses échéances à payer, son historique
 * et ses reçus, « où va l'argent de ma ville » (entrées anonymes, reversé au central, dépenses payées, solde) et son
 * profil. La fiche du membre est celle reliée à son compte ; à la première visite, une fiche de sa ville sans compte et
 * portant la même adresse e-mail lui est rattachée. Jamais le nom d'un autre membre.
 */
final class EspaceMembre
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MembreRepository $membres,
        private readonly EcheanceRepository $echeances,
        private readonly PaiementRepository $paiements,
        private readonly DepenseRepository $depenses,
        private readonly TableauDeBordAssociation $tableau,
        private readonly Reversements $reversements,
    ) {
    }

    /** La fiche du membre connecté, ou null : il n'a pas d'espace membre (pas de fiche dans une ville active). */
    public function ficheDe(Utilisateur $compte): ?Membre
    {
        $fiche = $this->membres->findOneBy(['compte' => $compte], ['id' => 'ASC']);
        if (null === $fiche && null !== $compte->getAssociation()) {
            foreach ($this->membres->findBy(['association' => $compte->getAssociation(), 'email' => mb_strtolower($compte->getEmail()), 'compte' => null], ['id' => 'ASC']) as $candidate) {
                if (MembreStatut::Sorti !== $candidate->getStatut()) {
                    $candidate->rattacherCompte($compte);
                    $this->em->flush();
                    $fiche = $candidate;
                    break;
                }
            }
        }

        return null !== $fiche && $fiche->getVille()->estActive() ? $fiche : null;
    }

    /**
     * Mes échéances : ce que je dois (la plus ancienne d'abord), le total, et ce que j'ai payé sur l'exercice en cours.
     *
     * @return array{dues: list<Echeance>, aPayer: int, enRetard: int, payeExercice: int, exercice: string}
     */
    public function echeances(Membre $membre, \DateTimeImmutable $aujourdhui): array
    {
        $dues = $this->echeances->duesPourMembre($membre);
        $association = $membre->getAssociation();
        $annee = $this->tableau->anneeExercice($association, $aujourdhui);
        [$debut, $fin] = $this->reversements->bornes($association, $annee);
        $paye = 0;
        foreach ($this->paiements->pourMembre($membre) as $paiement) {
            if (!$paiement->estAnnule() && $paiement->getRecuLe() >= $debut && $paiement->getRecuLe() <= $fin) {
                $paye += $paiement->getMontant();
            }
        }

        return [
            'dues' => $dues,
            'aPayer' => array_sum(array_map(static fn (Echeance $e): int => $e->getMontant() ?? 0, $dues)),
            'enRetard' => \count(array_filter($dues, static fn (Echeance $e): bool => $e->estEnRetard($aujourdhui))),
            'payeExercice' => $paye,
            'exercice' => $this->tableau->libelleExercice($association, $annee),
        ];
    }

    /** @return list<Paiement> mes paiements, les plus récents d'abord (annulés compris, signalés) */
    public function historique(Membre $membre): array
    {
        return $this->paiements->pourMembre($membre);
    }

    /**
     * Mes paiements d'une année civile (celle des reçus fiscaux et de l'attestation), et les années où j'ai payé.
     *
     * @return array{annee: int, annees: list<int>, paiements: list<Paiement>, total: int}
     */
    public function historiqueDe(Membre $membre, ?int $annee, \DateTimeImmutable $aujourdhui): array
    {
        $tous = $this->paiements->pourMembre($membre);
        $annees = array_values(array_unique(array_map(static fn (Paiement $p): int => (int) $p->getRecuLe()->format('Y'), $tous)));
        $courante = (int) $aujourdhui->format('Y');
        if (!\in_array($courante, $annees, true)) {
            $annees[] = $courante;
        }
        rsort($annees);
        $annee = null !== $annee && \in_array($annee, $annees, true) ? $annee : $annees[0];
        $paiements = array_values(array_filter($tous, static fn (Paiement $p): bool => (int) $p->getRecuLe()->format('Y') === $annee));
        $total = array_sum(array_map(static fn (Paiement $p): int => $p->estAnnule() ? 0 : $p->getMontant(), $paiements));

        return ['annee' => $annee, 'annees' => $annees, 'paiements' => $paiements, 'total' => $total];
    }

    /**
     * Les dépenses payées de ma ville sur l'exercice en cours, toutes (la page « Voir toutes les dépenses »).
     *
     * @return list<Depense>
     */
    public function depensesDeMaVille(Membre $membre, \DateTimeImmutable $aujourdhui): array
    {
        $association = $membre->getAssociation();
        [$debut, $fin] = $this->reversements->bornes($association, $this->tableau->anneeExercice($association, $aujourdhui));
        $depenses = array_values(array_filter(
            $this->depenses->lister($association, $membre->getVille()),
            static fn (Depense $d): bool => DepenseStatut::Payee === $d->getStatut() && $d->getDateDepense() >= $debut && $d->getDateDepense() <= $fin,
        ));
        usort($depenses, static fn (Depense $a, Depense $b): int => $b->getDateDepense() <=> $a->getDateDepense());

        return $depenses;
    }

    /**
     * Où va l'argent de ma ville (F-27) sur l'exercice en cours : entrées par type (anonymes), reversé au bureau central,
     * dépenses payées, solde de l'exercice ; les dépenses payées une à une ; les dernières entrées sans nom.
     *
     * @return array<string, mixed>
     */
    public function maVille(Membre $membre, \DateTimeImmutable $aujourdhui): array
    {
        $ville = $membre->getVille();
        $association = $membre->getAssociation();
        $annee = $this->tableau->anneeExercice($association, $aujourdhui);
        [$debut, $fin] = $this->reversements->bornes($association, $annee);
        $finances = $this->tableau->finances($association, $ville, $aujourdhui, $annee);
        $reverse = 0;
        foreach ($this->reversements->tableau($association, $aujourdhui, $annee, $ville)['lignes'] as $ligne) {
            if ($ligne['ville']->getId() === $ville->getId()) {
                $reverse = $ligne['recu'];
            }
        }

        $depensesPayees = array_values(array_filter(
            $this->depenses->lister($association, $ville),
            static fn (Depense $d): bool => DepenseStatut::Payee === $d->getStatut() && $d->getDateDepense() >= $debut && $d->getDateDepense() <= $fin,
        ));
        usort($depensesPayees, static fn (Depense $a, Depense $b): int => $b->getDateDepense() <=> $a->getDateDepense());
        $parCategorie = [];
        foreach ($depensesPayees as $depense) {
            $cle = $depense->getCategorie()->value;
            $parCategorie[$cle] = ($parCategorie[$cle] ?? 0) + $depense->getMontant();
        }
        arsort($parCategorie);

        // Entrées par type : cotisations, puis chaque type d'appel ; les dernières entrées, sans nom.
        $entrees = ['cotisations' => $finances['cotisations']];
        $dernieres = [];
        foreach ($this->paiements->lister($association, $ville, $debut, $fin, 200) as $paiement) {
            if ($paiement->estAnnule()) {
                continue;
            }
            $types = [];
            foreach ($paiement->getEcheances() as $echeance) {
                \assert($echeance instanceof Echeance);
                if (EcheanceStatut::Payee !== $echeance->getStatut() || null === $echeance->getAppel()) {
                    $types['cotisations'] = true;
                    continue;
                }
                $nom = $echeance->getAppel()->getType()->getNom();
                $entrees[$nom] = ($entrees[$nom] ?? 0) + ($echeance->getMontant() ?? 0);
                $types[$nom] = true;
            }
            if ([] === $types) {
                $types['contribution'] = true;
            }
            if (\count($dernieres) < 8) {
                $dernieres[] = ['types' => array_keys($types), 'date' => $paiement->getRecuLe(), 'montant' => $paiement->getMontant()];
            }
        }
        $entrees = array_filter($entrees, static fn (int $m): bool => $m > 0) ?: ['cotisations' => 0];
        $totalEntrees = array_sum($entrees);

        return [
            'ville' => $ville,
            'exercice' => $this->tableau->libelleExercice($association, $annee),
            'fin' => min($aujourdhui, $fin),
            'entreesTotal' => $finances['collecte'],
            'reverse' => $reverse,
            'depense' => $finances['depense'],
            'solde' => $finances['collecte'] - $reverse - $finances['depense'],
            'entrees' => $entrees,
            'totalEntrees' => $totalEntrees,
            'parCategorie' => $parCategorie,
            'maximumCategorie' => max([1, ...array_values($parCategorie)]),
            'depenses' => \array_slice($depensesPayees, 0, 5),
            'nombreDepenses' => \count($depensesPayees),
            'dernieres' => $dernieres,
        ];
    }
}
