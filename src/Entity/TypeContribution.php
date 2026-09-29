<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TypeContributionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un type de contribution de l'association (F-11) : il fixe qui paie (personne ou foyer), le mode de montant proposé
 * par défaut et la part reversée au bureau central. Un taux null reste « à préciser » : l'appel le fixe alors lui-même.
 */
#[ORM\Entity(repositoryClass: TypeContributionRepository::class)]
#[ORM\Table(name: 'type_contribution')]
#[ORM\UniqueConstraint(name: 'uniq_type_contribution_code', columns: ['association_id', 'code'])]
class TypeContribution
{
    /**
     * Types proposés à une association qui n'en a pas encore (maquette « 04 Lancer un appel »), montants en centimes.
     * Aucun taux de reversement n'est fixé d'avance (décision de Rama du 28 septembre 2026) : chaque appel le précise.
     */
    public const array PAR_DEFAUT = [
        ['code' => 'deces', 'nom' => 'Décès', 'unite' => 'personne', 'mode' => 'fixe', 'montant' => 1000, 'taux' => null],
        ['code' => 'projet', 'nom' => 'Projet du village', 'unite' => 'personne', 'mode' => 'libre', 'montant' => null, 'taux' => null],
        ['code' => 'fete', 'nom' => 'Fête', 'unite' => 'foyer', 'mode' => 'fixe', 'montant' => null, 'taux' => null],
        ['code' => 'autre', 'nom' => 'Autre', 'unite' => 'personne', 'mode' => 'fixe', 'montant' => null, 'taux' => null],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Association $association;

    #[ORM\Column(length: 40)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $nom;

    #[ORM\Column(length: 12, enumType: UniteContribution::class)]
    private UniteContribution $unite;

    #[ORM\Column(length: 12, enumType: ModeMontant::class)]
    private ModeMontant $mode;

    /** Montant proposé par défaut, en centimes ; null = à saisir. */
    #[ORM\Column(nullable: true)]
    private ?int $montantDefaut;

    /** Part reversée au bureau central, en pourcentage ; null = à préciser à chaque appel. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $tauxReversement;

    #[ORM\Column(type: 'smallint')]
    private int $ordre;

    /** Un type archivé ne se propose plus pour un nouvel appel ; les appels passés le gardent. */
    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(Association $association, string $code, string $nom, UniteContribution $unite, ModeMontant $mode, ?int $montantDefaut, ?int $tauxReversement, int $ordre = 0)
    {
        $this->association = $association;
        $this->code = $code;
        $this->ordre = $ordre;
        $this->creeLe = new \DateTimeImmutable();
        $this->modifier($nom, $unite, $mode, $montantDefaut, $tauxReversement);
    }

    /** Le bureau central règle le type (F-11) ; le code, lui, ne change jamais : les appels passés y renvoient. */
    public function modifier(string $nom, UniteContribution $unite, ModeMontant $mode, ?int $montantDefaut, ?int $tauxReversement): void
    {
        if (null !== $tauxReversement && ($tauxReversement < 0 || $tauxReversement > 100)) {
            throw new \InvalidArgumentException('Le taux de reversement va de 0 à 100 %.');
        }
        if (null !== $montantDefaut && $montantDefaut < 0) {
            throw new \InvalidArgumentException('Un montant ne peut pas être négatif.');
        }
        $nom = trim($nom);
        if ('' === $nom) {
            throw new \InvalidArgumentException('Un type de contribution porte un nom.');
        }
        $this->nom = mb_substr($nom, 0, 80);
        $this->unite = $unite;
        $this->mode = $mode;
        $this->montantDefaut = $montantDefaut;
        $this->tauxReversement = $tauxReversement;
    }

    public function archiver(): void
    {
        $this->actif = false;
    }

    public function reactiver(): void
    {
        $this->actif = true;
    }

    public function estActif(): bool
    {
        return $this->actif;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssociation(): Association
    {
        return $this->association;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getUnite(): UniteContribution
    {
        return $this->unite;
    }

    public function getMode(): ModeMontant
    {
        return $this->mode;
    }

    public function getMontantDefaut(): ?int
    {
        return $this->montantDefaut;
    }

    public function getTauxReversement(): ?int
    {
        return $this->tauxReversement;
    }

    public function aUnTauxFixe(): bool
    {
        return null !== $this->tauxReversement;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }
}
