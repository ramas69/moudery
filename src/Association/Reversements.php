<?php

declare(strict_types=1);

namespace App\Association;

use App\Administration\Texte;
use App\Entity\Association;
use App\Entity\Reversement;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Form\Model\ReversementData;
use App\Journal\Journal;
use App\Repository\ReversementRepository;
use App\Repository\VilleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les reversements des villes au bureau central (F-20, graphique G-11), pour un exercice : ce que chaque ville doit
 * (sa collecte de cotisations au taux par défaut de l'association, plus les échéances d'appels encaissées à la main au
 * taux de chaque appel, arrondis au centime inférieur), ce que le central a reçu et confirmé, le restant, les
 * reversements déclarés par les villes qui attendent une confirmation. Rien n'est inventé : sans collecte, rien n'est dû.
 *
 * Date limite (hypothèse du 28 septembre 2026, à confirmer avec l'association) : la fin du mois qui suit la clôture de
 * l'exercice. Une ville est « en retard » quand il lui reste à reverser après cette date.
 *
 * @phpstan-type Ligne array{ville: Ville, collecte: int, appels: int, du: int, recu: int, declare: int, restant: int, statut: string, dernier: ?Reversement}
 */
final class Reversements
{
    public const string STATUT_RIEN = 'rien';
    public const string STATUT_A_REVERSER = 'a_reverser';
    public const string STATUT_PARTIEL = 'partiel';
    public const string STATUT_SOLDE = 'solde';
    public const string STATUT_EN_RETARD = 'en_retard';
    public const array STATUTS = [self::STATUT_RIEN, self::STATUT_A_REVERSER, self::STATUT_PARTIEL, self::STATUT_SOLDE, self::STATUT_EN_RETARD];

    /** Le délai, en mois après la fin de l'exercice, avant qu'un restant dû soit en retard. */
    public const int MOIS_DE_DELAI = 1;

    public function __construct(
        private readonly TableauDeBordAssociation $tableau,
        private readonly VilleRepository $villes,
        private readonly ReversementRepository $reversements,
        private readonly EntityManagerInterface $entityManager,
        private readonly Journal $journal,
    ) {
    }

    /**
     * La page, pour un exercice (celui en cours par défaut) et, le cas échéant, la ville du périmètre.
     *
     * @return array{exercice: int, libelle: string, exercices: list<array{annee: int, libelle: string}>, taux: int, debut: \DateTimeImmutable, fin: \DateTimeImmutable, dateLimite: \DateTimeImmutable, enRetard: bool, lignes: list<Ligne>, totaux: array{collecte: int, du: int, recu: int, declare: int, restant: int}, compteurs: array<string, int>, aConfirmer: list<Reversement>, historique: list<Reversement>}
     */
    public function tableau(Association $association, \DateTimeImmutable $aujourdhui, ?int $exercice = null, ?Ville $perimetre = null): array
    {
        $exercices = $this->tableau->exercices($association, $aujourdhui);
        $connus = array_column($exercices, 'annee');
        $exercice = null !== $exercice && \in_array($exercice, $connus, true) ? $exercice : $this->tableau->anneeExercice($association, $aujourdhui);
        [$debut, $fin] = $this->bornes($association, $exercice);
        $dateLimite = $this->dateLimite($association, $exercice);
        $enRetard = $aujourdhui->setTime(0, 0) > $dateLimite;

        $finances = $this->tableau->villesFinances($association, $aujourdhui, $exercice);
        $appels = $this->reversements->partDuCentralSurLesAppels($association, $debut, $fin);
        $historique = $this->reversements->listerPourExercice($association, $exercice);
        $taux = $association->getTauxReversementDefaut();

        $recus = [];
        $declares = [];
        $derniers = [];
        foreach ($historique as $reversement) {
            $id = (int) $reversement->getVille()->getId();
            if ($reversement->estConfirme()) {
                $recus[$id] = ($recus[$id] ?? 0) + $reversement->getMontant();
                // Le « dernier reversement » d'une ville est le dernier confirmé : une déclaration n'est pas encore de l'argent reçu.
                $derniers[$id] ??= $reversement;
            } else {
                $declares[$id] = ($declares[$id] ?? 0) + $reversement->getMontant();
            }
        }

        $lignes = [];
        $totaux = ['collecte' => 0, 'du' => 0, 'recu' => 0, 'declare' => 0, 'restant' => 0];
        $compteurs = array_fill_keys(self::STATUTS, 0);
        foreach ($this->villes->listerPourAssociation($association) as $ville) {
            $id = (int) $ville->getId();
            if (null !== $perimetre && $perimetre->getId() !== $id) {
                continue;
            }
            $collecte = $finances[$id]['collecte'] ?? 0;
            $partAppels = $appels[$id] ?? 0;
            $du = intdiv($collecte * $taux, 100) + $partAppels;
            $recu = $recus[$id] ?? 0;
            $declare = $declares[$id] ?? 0;
            // Un brouillon n'a rien collecté ; une ville archivée ne figure que si elle a un passif ou un historique.
            if ($ville->estBrouillon() || ($ville->estArchivee() && 0 === $du && 0 === $recu && 0 === $declare)) {
                continue;
            }
            $restant = max(0, $du - $recu);
            $statut = self::statutDe($du, $recu, $restant, $enRetard);
            ++$compteurs[$statut];
            $lignes[] = [
                'ville' => $ville,
                'collecte' => $collecte,
                'appels' => $partAppels,
                'du' => $du,
                'recu' => $recu,
                'declare' => $declare,
                'restant' => $restant,
                'statut' => $statut,
                'dernier' => $derniers[$id] ?? null,
            ];
            $totaux['collecte'] += $collecte;
            $totaux['du'] += $du;
            $totaux['recu'] += $recu;
            $totaux['declare'] += $declare;
            $totaux['restant'] += $restant;
        }

        // Ce qui attend une action d'abord (en retard, puis à reverser, partiel), puis le reste ; par nom à statut égal.
        $rang = [self::STATUT_EN_RETARD => 0, self::STATUT_A_REVERSER => 1, self::STATUT_PARTIEL => 2, self::STATUT_SOLDE => 3, self::STATUT_RIEN => 4];
        usort($lignes, static fn (array $a, array $b): int => $rang[$a['statut']] <=> $rang[$b['statut']] ?: strcmp(Texte::normaliser($a['ville']->getNom()), Texte::normaliser($b['ville']->getNom())));

        $aConfirmer = $this->reversements->aConfirmer($association);
        if (null !== $perimetre) {
            $aConfirmer = array_values(array_filter($aConfirmer, static fn (Reversement $r): bool => $r->getVille()->getId() === $perimetre->getId()));
            $historique = array_values(array_filter($historique, static fn (Reversement $r): bool => $r->getVille()->getId() === $perimetre->getId()));
        }

        return [
            'exercice' => $exercice,
            'libelle' => $this->tableau->libelleExercice($association, $exercice),
            'exercices' => $exercices,
            'taux' => $taux,
            'debut' => $debut,
            'fin' => $fin,
            'dateLimite' => $dateLimite,
            'enRetard' => $enRetard,
            'lignes' => $lignes,
            'totaux' => $totaux,
            'compteurs' => $compteurs,
            'aConfirmer' => $aConfirmer,
            'historique' => $historique,
        ];
    }

    /** Le bureau central constate un virement reçu : le reversement est créé confirmé, et consigné au journal. */
    public function enregistrer(Association $association, ReversementData $donnees, ?Utilisateur $acteur, \DateTimeImmutable $quand): Reversement
    {
        \assert($donnees->ville instanceof Ville && null !== $donnees->exercice && null !== $donnees->montant && null !== $donnees->recuLe);
        if ($donnees->ville->getAssociation() !== $association) {
            throw new \InvalidArgumentException('La ville appartient à une autre association.');
        }

        $reversement = new Reversement($donnees->ville, $donnees->exercice, (int) round($donnees->montant * 100), $donnees->recuLe, $donnees->reference, $donnees->note, $acteur);
        $reversement->confirmer($acteur, $quand);
        $this->entityManager->persist($reversement);
        $this->journal->consigner(TypeEvenement::ReversementConfirme, $acteur, $association, $donnees->ville->getNom(), self::details($reversement) + ['direct' => true]);
        $this->entityManager->flush();

        return $reversement;
    }

    /**
     * Le bureau central confirme un reversement déclaré par une ville, avec le montant réellement reçu s'il diffère.
     * L'écart éventuel est consigné au journal.
     */
    public function confirmer(Reversement $reversement, ?Utilisateur $acteur, \DateTimeImmutable $quand, ?int $montantRecu = null): void
    {
        if ($reversement->estConfirme()) {
            throw new \LogicException('Ce reversement est déjà confirmé.');
        }

        $reversement->confirmer($acteur, $quand, $montantRecu);
        $this->journal->consigner(TypeEvenement::ReversementConfirme, $acteur, $reversement->getAssociation(), $reversement->getVille()->getNom(), self::details($reversement));
        $this->entityManager->flush();
    }

    /** Une ville annonce un virement (« reversement effectué », cahier des charges) : il attend la confirmation du central. */
    public function declarer(Ville $ville, int $exercice, int $montant, \DateTimeImmutable $recuLe, ?string $reference, ?string $note, ?Utilisateur $acteur): Reversement
    {
        $reversement = new Reversement($ville, $exercice, $montant, $recuLe, $reference, $note, $acteur);
        $this->entityManager->persist($reversement);
        $this->journal->consigner(TypeEvenement::ReversementDeclare, $acteur, $ville->getAssociation(), $ville->getNom(), self::details($reversement));
        $this->entityManager->flush();

        return $reversement;
    }

    /** Le premier et le dernier jour de l'exercice qui commence cette année-là. */
    public function bornes(Association $association, int $exercice): array
    {
        return $this->tableau->bornesExercice($association, $exercice);
    }

    /** La fin du mois qui suit la clôture de l'exercice. */
    public function dateLimite(Association $association, int $exercice): \DateTimeImmutable
    {
        [, $fin] = $this->bornes($association, $exercice);

        return $fin->modify(\sprintf('first day of +%d month', self::MOIS_DE_DELAI))->modify('last day of this month');
    }

    public static function statutDe(int $du, int $recu, int $restant, bool $enRetard): string
    {
        if (0 === $du && 0 === $recu) {
            return self::STATUT_RIEN;
        }
        if (0 === $restant) {
            return self::STATUT_SOLDE;
        }
        if ($enRetard) {
            return self::STATUT_EN_RETARD;
        }

        return $recu > 0 ? self::STATUT_PARTIEL : self::STATUT_A_REVERSER;
    }

    /** @return array<string, mixed> */
    private static function details(Reversement $reversement): array
    {
        return [
            'ville' => $reversement->getVille()->getNom(),
            'exercice' => $reversement->getExercice(),
            'montant' => $reversement->getMontant(),
            'montant_declare' => $reversement->getMontantDeclare(),
            'ecart' => $reversement->getEcart(),
            'recu_le' => $reversement->getRecuLe()->format('Y-m-d'),
            'reference' => $reversement->getReference(),
            'statut' => $reversement->getStatut()->value,
        ];
    }
}
