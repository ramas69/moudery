<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaiementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un encaissement (cahier des charges : échéance(s), montant, moyen, identifiant Stripe, statut). En V1, le paiement
 * manuel (F-17) : espèces, virement, chèque ou carte reçus par le trésorier, qui soldent une ou plusieurs échéances du
 * même membre. Jamais supprimé : une erreur s'annule, et les échéances redeviennent dues.
 */
#[ORM\Entity(repositoryClass: PaiementRepository::class)]
#[ORM\Table(name: 'paiement')]
#[ORM\Index(name: 'idx_paiement_association_recu_le', columns: ['association_id', 'recu_le'])]
#[ORM\Index(name: 'idx_paiement_ville_recu_le', columns: ['ville_id', 'recu_le'])]
class Paiement
{
    public const PREFIXE_SIMULATION = 'simulation_';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Association $association;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ville $ville;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Membre $membre;

    #[ORM\Column]
    private int $montant;

    #[ORM\Column(length: 12, enumType: MoyenPaiement::class)]
    private MoyenPaiement $moyen;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $recuLe;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reference;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note;

    #[ORM\Column(length: 12, enumType: PaiementStatut::class)]
    private PaiementStatut $statut = PaiementStatut::Enregistre;

    /** Identifiant de la session ou du paiement Stripe pour un paiement en ligne (F-15) ; null pour un paiement manuel. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $identifiantStripe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $enregistrePar;

    #[ORM\Column]
    private \DateTimeImmutable $enregistreLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $annuleLe = null;

    /** @var Collection<int, Echeance> */
    #[ORM\OneToMany(targetEntity: Echeance::class, mappedBy: 'paiement')]
    private Collection $echeances;

    public function __construct(Membre $membre, int $montant, MoyenPaiement $moyen, \DateTimeImmutable $recuLe, ?string $reference, ?string $note, ?Utilisateur $enregistrePar, \DateTimeImmutable $quand)
    {
        if ($montant <= 0) {
            throw new \InvalidArgumentException('Un paiement porte un montant strictement positif.');
        }
        $this->membre = $membre;
        $this->ville = $membre->getVille();
        $this->association = $membre->getAssociation();
        $this->montant = $montant;
        $this->moyen = $moyen;
        $this->recuLe = $recuLe->setTime(0, 0);
        $reference = trim((string) $reference);
        $this->reference = '' === $reference ? null : mb_substr($reference, 0, 80);
        $note = trim((string) $note);
        $this->note = '' === $note ? null : mb_substr($note, 0, 255);
        $this->enregistrePar = $enregistrePar;
        $this->enregistreLe = $quand;
        $this->echeances = new ArrayCollection();
    }

    public function annuler(\DateTimeImmutable $quand): void
    {
        if (PaiementStatut::Annule === $this->statut) {
            throw new \LogicException('Ce paiement est déjà annulé.');
        }
        $this->statut = PaiementStatut::Annule;
        $this->annuleLe = $quand;
    }

    public function estAnnule(): bool
    {
        return PaiementStatut::Annule === $this->statut;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssociation(): Association
    {
        return $this->association;
    }

    public function getVille(): Ville
    {
        return $this->ville;
    }

    public function getMembre(): Membre
    {
        return $this->membre;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getMoyen(): MoyenPaiement
    {
        return $this->moyen;
    }

    public function getRecuLe(): \DateTimeImmutable
    {
        return $this->recuLe;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getStatut(): PaiementStatut
    {
        return $this->statut;
    }

    public function getIdentifiantStripe(): ?string
    {
        return $this->identifiantStripe;
    }

    /**
     * Relie le paiement à son règlement en ligne. En attendant Stripe (décision de Rama du 29 septembre 2026), le
     * règlement est simulé et l'identifiant commence par {@see self::PREFIXE_SIMULATION}.
     */
    public function marquerEnLigne(string $identifiant): void
    {
        $this->identifiantStripe = mb_substr($identifiant, 0, 120);
    }

    public function estEnLigne(): bool
    {
        return null !== $this->identifiantStripe;
    }

    public function estSimule(): bool
    {
        return null !== $this->identifiantStripe && str_starts_with($this->identifiantStripe, self::PREFIXE_SIMULATION);
    }

    public function getEnregistrePar(): ?Utilisateur
    {
        return $this->enregistrePar;
    }

    public function getEnregistreLe(): \DateTimeImmutable
    {
        return $this->enregistreLe;
    }

    public function getAnnuleLe(): ?\DateTimeImmutable
    {
        return $this->annuleLe;
    }

    /** @return Collection<int, Echeance> */
    public function getEcheances(): Collection
    {
        return $this->echeances;
    }
}
