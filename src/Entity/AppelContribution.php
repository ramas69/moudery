<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppelContributionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un appel à contribution (F-13) : un type, un objet, un message, un montant fixe ou libre (suggéré), une date limite,
 * un périmètre (toute l'association, ou une ville), un taux de reversement figé à la création et un statut. En
 * brouillon, rien n'est envoyé ; à l'ouverture, une échéance est créée pour chaque membre ou foyer concerné.
 */
#[ORM\Entity(repositoryClass: AppelContributionRepository::class)]
#[ORM\Table(name: 'appel_contribution')]
#[ORM\Index(name: 'idx_appel_association_statut', columns: ['association_id', 'statut'])]
class AppelContribution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Association $association;

    /** La ville visée, ou null pour toutes les villes actives de l'association. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Ville $ville = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private TypeContribution $type;

    #[ORM\Column(length: 160)]
    private string $objet;

    #[ORM\Column(type: 'text')]
    private string $message = '';

    #[ORM\Column(length: 12, enumType: ModeMontant::class)]
    private ModeMontant $mode;

    /** Montant demandé (fixe) ou suggéré (libre), en centimes. */
    #[ORM\Column(nullable: true)]
    private ?int $montant = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateLimite;

    #[ORM\Column(type: 'smallint')]
    private int $tauxReversement;

    #[ORM\Column]
    private bool $relancesAuto = true;

    #[ORM\Column(length: 12, enumType: AppelStatut::class)]
    private AppelStatut $statut = AppelStatut::Brouillon;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $creePar;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $ouvertLe = null;

    /** @var Collection<int, Echeance> */
    #[ORM\OneToMany(targetEntity: Echeance::class, mappedBy: 'appel', fetch: 'EXTRA_LAZY')]
    private Collection $echeances;

    public function __construct(Association $association, TypeContribution $type, ?Utilisateur $creePar)
    {
        if ($type->getAssociation() !== $association) {
            throw new \InvalidArgumentException('Le type de contribution appartient à une autre association.');
        }
        $this->association = $association;
        $this->type = $type;
        $this->mode = $type->getMode();
        $this->montant = $type->getMontantDefaut();
        $this->tauxReversement = $type->getTauxReversement() ?? 0;
        $this->creePar = $creePar;
        $this->objet = '';
        $this->dateLimite = new \DateTimeImmutable('+7 days midnight');
        $this->creeLe = new \DateTimeImmutable();
        $this->modifieLe = $this->creeLe;
        $this->echeances = new ArrayCollection();
    }

    /** Tout se modifie tant que l'appel est en brouillon ; ensuite, plus rien. */
    public function definir(TypeContribution $type, string $objet, string $message, ModeMontant $mode, ?int $montant, \DateTimeImmutable $dateLimite, ?Ville $ville, int $tauxReversement, bool $relancesAuto): void
    {
        if (!$this->estBrouillon()) {
            throw new \LogicException('Un appel lancé ne se modifie plus.');
        }
        if ($type->getAssociation() !== $this->association || (null !== $ville && $ville->getAssociation() !== $this->association)) {
            throw new \InvalidArgumentException('Le type et la ville doivent appartenir à l\'association de l\'appel.');
        }
        if ($tauxReversement < 0 || $tauxReversement > 100) {
            throw new \InvalidArgumentException('Le taux de reversement va de 0 à 100 %.');
        }
        if (null !== $montant && $montant < 0) {
            throw new \InvalidArgumentException('Un montant ne peut pas être négatif.');
        }
        if (ModeMontant::Fixe === $mode && (null === $montant || 0 === $montant)) {
            throw new \InvalidArgumentException('Un appel à montant fixe a un montant.');
        }
        $this->type = $type;
        $this->objet = trim($objet);
        $this->message = trim($message);
        $this->mode = $mode;
        $this->montant = $montant;
        $this->dateLimite = $dateLimite->setTime(0, 0);
        $this->ville = $ville;
        $this->tauxReversement = $type->getTauxReversement() ?? $tauxReversement;
        $this->relancesAuto = $relancesAuto;
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function ouvrir(\DateTimeImmutable $quand): void
    {
        if (!$this->estBrouillon()) {
            throw new \LogicException('Cet appel est déjà lancé.');
        }
        $this->statut = AppelStatut::Ouvert;
        $this->ouvertLe = $quand;
        $this->modifieLe = $quand;
    }

    public function estBrouillon(): bool
    {
        return AppelStatut::Brouillon === $this->statut;
    }

    /** Montant d'une échéance : le montant fixe, ou null pour un montant libre (le membre choisit). */
    public function montantEcheance(): ?int
    {
        return ModeMontant::Fixe === $this->mode ? $this->montant : null;
    }

    /**
     * Les dates de relance d'après le calendrier de l'association (jours autour de la date limite).
     *
     * @return list<\DateTimeImmutable>
     */
    public function datesDeRelance(): array
    {
        if (!$this->relancesAuto) {
            return [];
        }

        return array_map(fn (int $jours): \DateTimeImmutable => $this->dateLimite->modify(\sprintf('%+d days', $jours)), $this->association->getCalendrierRelances());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssociation(): Association
    {
        return $this->association;
    }

    public function getVille(): ?Ville
    {
        return $this->ville;
    }

    public function getType(): TypeContribution
    {
        return $this->type;
    }

    public function getObjet(): string
    {
        return $this->objet;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getMode(): ModeMontant
    {
        return $this->mode;
    }

    public function getMontant(): ?int
    {
        return $this->montant;
    }

    public function getDateLimite(): \DateTimeImmutable
    {
        return $this->dateLimite;
    }

    public function getTauxReversement(): int
    {
        return $this->tauxReversement;
    }

    public function aDesRelancesAuto(): bool
    {
        return $this->relancesAuto;
    }

    public function getStatut(): AppelStatut
    {
        return $this->statut;
    }

    public function getCreePar(): ?Utilisateur
    {
        return $this->creePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function getOuvertLe(): ?\DateTimeImmutable
    {
        return $this->ouvertLe;
    }

    /** @return Collection<int, Echeance> */
    public function getEcheances(): Collection
    {
        return $this->echeances;
    }
}
