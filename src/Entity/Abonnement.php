<?php

declare(strict_types=1);

namespace App\Entity;

use App\Abonnement\Offre;
use App\Repository\AbonnementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'abonnement d'une association à la plateforme. Préparation de la facturation du SaaS (hors V1 dans le cahier
 * des charges) : il naît « à souscrire », le bureau central choisit son offre en activant son compte, le super-admin
 * peut aussi le renseigner ou l'offrir à la main ; aucun paiement n'est encaissé ici. Les montants sont en centimes.
 * Moudery, premier client, est offert.
 */
#[ORM\Entity(repositoryClass: AbonnementRepository::class)]
#[ORM\Table(name: 'abonnement')]
class Abonnement
{
    public const string FORMULE_GRATUITE = 'Gratuit';

    /** Une échéance dans moins de 30 jours est signalée au super-admin. */
    public const int JOURS_ECHEANCE_PROCHE = 30;

    /** Après la souscription en ligne, le premier paiement est attendu sous quatorze jours, sur facture. */
    public const int JOURS_PREMIER_PAIEMENT = 14;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'abonnement')]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Association $association;

    #[ORM\Column(length: 60)]
    private string $formule = self::FORMULE_GRATUITE;

    #[ORM\Column(length: 20, enumType: AbonnementStatut::class)]
    private AbonnementStatut $statut = AbonnementStatut::ASouscrire;

    /** Montant par période, en centimes. */
    #[ORM\Column]
    private int $montant = 0;

    #[ORM\Column(length: 20, enumType: Periodicite::class)]
    private Periodicite $periodicite = Periodicite::Mensuelle;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $debutLe;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $prochaineEcheanceLe = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dernierPaiementLe = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    public function __construct(Association $association, ?\DateTimeImmutable $debutLe = null)
    {
        $this->association = $association;
        $this->debutLe = ($debutLe ?? new \DateTimeImmutable())->setTime(0, 0);
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssociation(): Association
    {
        return $this->association;
    }

    public function getFormule(): string
    {
        return $this->formule;
    }

    public function getStatut(): AbonnementStatut
    {
        return $this->statut;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getPeriodicite(): Periodicite
    {
        return $this->periodicite;
    }

    public function getDebutLe(): \DateTimeImmutable
    {
        return $this->debutLe;
    }

    public function getProchaineEcheanceLe(): ?\DateTimeImmutable
    {
        return $this->prochaineEcheanceLe;
    }

    public function getDernierPaiementLe(): ?\DateTimeImmutable
    {
        return $this->dernierPaiementLe;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    /** Tant que le bureau central n'a pas choisi son offre : le lien d'invitation mène à la page de souscription. */
    public function attendLaSouscription(): bool
    {
        return AbonnementStatut::ASouscrire === $this->statut;
    }

    /** Un abonnement à souscrire, offert ou résilié ne coûte rien ; les autres sont ramenés au mois pour le revenu récurrent. */
    public function montantMensuel(): int
    {
        return $this->estFacturable() ? $this->periodicite->mensualiser($this->montant) : 0;
    }

    public function estFacturable(): bool
    {
        return \in_array($this->statut, [AbonnementStatut::Actif, AbonnementStatut::EnRetard], true);
    }

    /** En retard : constaté par le super-admin, ou échéance dépassée sans paiement. */
    public function estEnRetard(\DateTimeImmutable $aujourdhui): bool
    {
        if (AbonnementStatut::EnRetard === $this->statut) {
            return true;
        }

        return AbonnementStatut::Actif === $this->statut
            && null !== $this->prochaineEcheanceLe
            && $this->prochaineEcheanceLe < $aujourdhui->setTime(0, 0);
    }

    /** Échéance dans les 30 jours, pour prévenir avant le retard. */
    public function echeanceProche(\DateTimeImmutable $aujourdhui): bool
    {
        if (AbonnementStatut::Actif !== $this->statut || null === $this->prochaineEcheanceLe) {
            return false;
        }

        $jour = $aujourdhui->setTime(0, 0);

        return $this->prochaineEcheanceLe >= $jour && $this->prochaineEcheanceLe <= $jour->modify('+'.self::JOURS_ECHEANCE_PROCHE.' days');
    }

    public function definir(string $formule, AbonnementStatut $statut, int $montant, Periodicite $periodicite, \DateTimeImmutable $debutLe, ?\DateTimeImmutable $prochaineEcheanceLe, ?string $notes): void
    {
        if ($montant < 0) {
            throw new \InvalidArgumentException('Le montant d\'un abonnement ne peut pas être négatif.');
        }
        $formule = trim($formule);
        if ('' === $formule) {
            throw new \InvalidArgumentException('La formule est obligatoire.');
        }

        $this->formule = $formule;
        $this->statut = $statut;
        $this->montant = \in_array($statut, [AbonnementStatut::ASouscrire, AbonnementStatut::Offert], true) ? 0 : $montant;
        $this->periodicite = $periodicite;
        $this->debutLe = $debutLe->setTime(0, 0);
        $this->prochaineEcheanceLe = $prochaineEcheanceLe?->setTime(0, 0);
        $notes = trim((string) $notes);
        $this->notes = '' === $notes ? null : $notes;
        $this->toucher();
    }

    /**
     * La souscription en ligne du bureau central : l'offre choisie prend effet aujourd'hui,
     * le premier paiement est attendu sous quatorze jours.
     */
    public function souscrire(Offre $offre, \DateTimeImmutable $quand): void
    {
        if (!$this->attendLaSouscription()) {
            throw new \LogicException(\sprintf('Un abonnement « %s » ne se souscrit plus.', $this->statut->value));
        }

        $jour = $quand->setTime(0, 0);
        $this->formule = $offre->formule;
        $this->statut = AbonnementStatut::Actif;
        $this->montant = $offre->montant;
        $this->periodicite = $offre->periodicite;
        $this->debutLe = $jour;
        $this->prochaineEcheanceLe = $jour->modify('+'.self::JOURS_PREMIER_PAIEMENT.' days');
        $this->notes = \sprintf('Souscription en ligne le %s : %s, %s.', $jour->format('d/m/Y'), $offre->formule, $offre->periodicite->value);
        $this->toucher();
    }

    /**
     * Un paiement reçu : l'abonnement redevient actif et l'échéance avance d'une période,
     * à partir de l'échéance prévue si elle n'est pas encore passée, sinon à partir d'aujourd'hui.
     */
    public function marquerPaye(\DateTimeImmutable $quand): void
    {
        if (!$this->estFacturable()) {
            throw new \LogicException(\sprintf('Un abonnement « %s » n\'attend pas de paiement.', $this->statut->value));
        }

        $jour = $quand->setTime(0, 0);
        $base = null !== $this->prochaineEcheanceLe && $this->prochaineEcheanceLe >= $jour ? $this->prochaineEcheanceLe : $jour;

        $this->statut = AbonnementStatut::Actif;
        $this->dernierPaiementLe = $jour;
        $this->prochaineEcheanceLe = $base->add($this->periodicite->intervalle());
        $this->toucher();
    }

    public function constaterLeRetard(): void
    {
        if (AbonnementStatut::Actif !== $this->statut) {
            throw new \LogicException('Seul un abonnement actif peut passer en retard.');
        }

        $this->statut = AbonnementStatut::EnRetard;
        $this->toucher();
    }

    public function resilier(): void
    {
        $this->statut = AbonnementStatut::Resilie;
        $this->prochaineEcheanceLe = null;
        $this->toucher();
    }

    private function toucher(): void
    {
        $this->modifieLe = new \DateTimeImmutable();
    }
}
