<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EvenementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne du journal de la plateforme (F-38) : quoi, quand, par qui, pour quelle association, avec quels détails.
 * Les événements de plateforme (connexion d'un super-admin) n'ont pas d'association. Jamais modifié, jamais supprimé.
 */
#[ORM\Entity(repositoryClass: EvenementRepository::class)]
#[ORM\Table(name: 'evenement')]
#[ORM\Index(name: 'idx_evenement_quand', columns: ['quand'])]
#[ORM\Index(name: 'idx_evenement_type', columns: ['type'])]
class Evenement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $quand;

    #[ORM\Column(length: 40, enumType: TypeEvenement::class)]
    private TypeEvenement $type;

    /** Le compte à l'origine de l'événement ; null pour un traitement automatique ou une commande. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $acteur;

    /** Portée par toutes les tables métier ; null pour un événement de plateforme. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Association $association;

    /** Ce qui est concerné, en clair : un nom de ville, une adresse e-mail… */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $cible;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details;

    /** @param array<string, mixed> $details */
    public function __construct(TypeEvenement $type, \DateTimeImmutable $quand, ?Utilisateur $acteur = null, ?Association $association = null, ?string $cible = null, array $details = [])
    {
        $this->type = $type;
        $this->quand = $quand;
        $this->acteur = $acteur;
        $this->association = $association;
        $cible = trim((string) $cible);
        $this->cible = '' === $cible ? null : mb_substr($cible, 0, 180);
        $this->details = [] === $details ? null : $details;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuand(): \DateTimeImmutable
    {
        return $this->quand;
    }

    public function getType(): TypeEvenement
    {
        return $this->type;
    }

    public function getActeur(): ?Utilisateur
    {
        return $this->acteur;
    }

    public function getAssociation(): ?Association
    {
        return $this->association;
    }

    public function getCible(): ?string
    {
        return $this->cible;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details ?? [];
    }

    public function detail(string $cle): mixed
    {
        return $this->details[$cle] ?? null;
    }
}
