<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaiementAbonnementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un paiement d'abonnement reçu par la plateforme, enregistré à la main par le super-admin (« Marquer comme payé »)
 * en attendant l'encaissement en ligne. Montant en centimes. Conservé même si l'abonnement change.
 */
#[ORM\Entity(repositoryClass: PaiementAbonnementRepository::class)]
#[ORM\Table(name: 'paiement_abonnement')]
#[ORM\Index(name: 'idx_paiement_abonnement_recu_le', columns: ['recu_le'])]
class PaiementAbonnement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Abonnement $abonnement;

    /** Portée par toutes les tables métier, pour le filtre multi-tenant. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Association $association;

    #[ORM\Column]
    private int $montant;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $recuLe;

    /** L'échéance que ce paiement solde. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $echeanceCouverte;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $enregistrePar;

    #[ORM\Column]
    private \DateTimeImmutable $enregistreLe;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note;

    public function __construct(Abonnement $abonnement, int $montant, \DateTimeImmutable $recuLe, ?\DateTimeImmutable $echeanceCouverte = null, ?Utilisateur $enregistrePar = null, ?string $note = null)
    {
        if ($montant <= 0) {
            throw new \InvalidArgumentException('Un paiement porte un montant strictement positif.');
        }

        $this->abonnement = $abonnement;
        $this->association = $abonnement->getAssociation();
        $this->montant = $montant;
        $this->recuLe = $recuLe->setTime(0, 0);
        $this->echeanceCouverte = $echeanceCouverte?->setTime(0, 0);
        $this->enregistrePar = $enregistrePar;
        $this->enregistreLe = new \DateTimeImmutable();
        $note = trim((string) $note);
        $this->note = '' === $note ? null : mb_substr($note, 0, 255);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAbonnement(): Abonnement
    {
        return $this->abonnement;
    }

    public function getAssociation(): Association
    {
        return $this->association;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getRecuLe(): \DateTimeImmutable
    {
        return $this->recuLe;
    }

    public function getEcheanceCouverte(): ?\DateTimeImmutable
    {
        return $this->echeanceCouverte;
    }

    public function getEnregistrePar(): ?Utilisateur
    {
        return $this->enregistrePar;
    }

    public function getEnregistreLe(): \DateTimeImmutable
    {
        return $this->enregistreLe;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}
