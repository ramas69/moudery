<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReversementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un virement d'une ville vers le bureau central (F-20) : la part du central sur ce que la ville a encaissé.
 * Montant en centimes, rattaché à un exercice. Déclaré par la ville puis confirmé par le central, ou enregistré
 * directement par le central quand il constate l'arrivée de l'argent. Jamais supprimé : le journal en garde la trace.
 */
#[ORM\Entity(repositoryClass: ReversementRepository::class)]
#[ORM\Table(name: 'reversement')]
#[ORM\Index(name: 'idx_reversement_association_exercice', columns: ['association_id', 'exercice'])]
#[ORM\Index(name: 'idx_reversement_ville_recu_le', columns: ['ville_id', 'recu_le'])]
class Reversement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Portée par toutes les tables métier, pour le filtre multi-tenant. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Association $association;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ville $ville;

    /** L'année civile où commence l'exercice auquel ce reversement se rattache. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $exercice;

    /** Le montant qui compte : celui déclaré par la ville, ou celui que le central a réellement reçu s'il diffère. */
    #[ORM\Column]
    private int $montant;

    /** Le montant annoncé par la ville, gardé tel quel quand le central en confirme un autre (écart). */
    #[ORM\Column]
    private int $montantDeclare;

    /** La date du virement (ou de sa réception), sans heure. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $recuLe;

    /** Le libellé ou la référence du virement, tel qu'il apparaît sur le relevé. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reference;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note;

    #[ORM\Column(length: 12, enumType: ReversementStatut::class)]
    private ReversementStatut $statut;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $declarePar;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $confirmePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $confirmeLe = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(Ville $ville, int $exercice, int $montant, \DateTimeImmutable $recuLe, ?string $reference = null, ?string $note = null, ?Utilisateur $declarePar = null)
    {
        if ($montant <= 0) {
            throw new \InvalidArgumentException('Un reversement porte un montant strictement positif.');
        }

        $this->ville = $ville;
        $this->association = $ville->getAssociation();
        $this->exercice = $exercice;
        $this->montant = $montant;
        $this->montantDeclare = $montant;
        $this->recuLe = $recuLe->setTime(0, 0);
        $reference = trim((string) $reference);
        $this->reference = '' === $reference ? null : mb_substr($reference, 0, 80);
        $note = trim((string) $note);
        $this->note = '' === $note ? null : mb_substr($note, 0, 255);
        $this->statut = ReversementStatut::Declare;
        $this->declarePar = $declarePar;
        $this->creeLe = new \DateTimeImmutable();
    }

    /**
     * Le central constate l'arrivée de l'argent : le reversement compte dès lors dans le reçu. S'il a reçu un autre
     * montant que celui déclaré, c'est le montant reçu qui compte et l'écart reste visible.
     */
    public function confirmer(?Utilisateur $par, \DateTimeImmutable $quand, ?int $montantRecu = null): void
    {
        if (ReversementStatut::Confirme === $this->statut) {
            return;
        }
        if (null !== $montantRecu) {
            if ($montantRecu <= 0) {
                throw new \InvalidArgumentException('Un reversement porte un montant strictement positif.');
            }
            $this->montant = $montantRecu;
        }
        $this->statut = ReversementStatut::Confirme;
        $this->confirmePar = $par;
        $this->confirmeLe = $quand;
    }

    public function estConfirme(): bool
    {
        return ReversementStatut::Confirme === $this->statut;
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

    public function getExercice(): int
    {
        return $this->exercice;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getMontantDeclare(): int
    {
        return $this->montantDeclare;
    }

    /** Reçu moins déclaré : négatif quand le central a reçu moins que ce que la ville annonçait. */
    public function getEcart(): int
    {
        return $this->montant - $this->montantDeclare;
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

    public function getStatut(): ReversementStatut
    {
        return $this->statut;
    }

    public function getDeclarePar(): ?Utilisateur
    {
        return $this->declarePar;
    }

    public function getConfirmePar(): ?Utilisateur
    {
        return $this->confirmePar;
    }

    public function getConfirmeLe(): ?\DateTimeImmutable
    {
        return $this->confirmeLe;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
