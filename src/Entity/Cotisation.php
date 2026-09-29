<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CotisationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * La cotisation périodique d'une ville pour un exercice (F-12) : un tarif mensuel, un jour d'échéance, et une échéance
 * par mois pour chaque adhérent concerné (par personne ou par foyer selon le type). C'est la colonne des mois du
 * classeur, tenue par l'application. Une seule cotisation par ville et par exercice.
 */
#[ORM\Entity(repositoryClass: CotisationRepository::class)]
#[ORM\Table(name: 'cotisation')]
#[ORM\UniqueConstraint(name: 'uniq_cotisation_ville_annee', columns: ['ville_id', 'annee'])]
class Cotisation
{
    public const int JOUR_ECHEANCE_DEFAUT = 5;

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
    #[ORM\JoinColumn(nullable: false)]
    private TypeContribution $type;

    /** L'année civile où commence l'exercice ; c'est aussi l'année de l'adhésion (la ligne du classeur). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $annee;

    #[ORM\Column]
    private int $montantMensuel;

    /** Le jour du mois où chaque mensualité est due (1 à 28, pour exister tous les mois). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $jourEcheance;

    #[ORM\Column(length: 12, enumType: CotisationStatut::class)]
    private CotisationStatut $statut = CotisationStatut::Ouverte;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $creePar;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    public function __construct(Ville $ville, TypeContribution $type, int $annee, int $montantMensuel, int $jourEcheance, ?Utilisateur $creePar, \DateTimeImmutable $quand)
    {
        if ($type->getAssociation() !== $ville->getAssociation()) {
            throw new \InvalidArgumentException('Le type de contribution appartient à une autre association.');
        }
        if (!Adhesion::anneeValide($annee)) {
            throw new \InvalidArgumentException(\sprintf('L\'année « %d » n\'est pas plausible.', $annee));
        }
        $this->ville = $ville;
        $this->association = $ville->getAssociation();
        $this->type = $type;
        $this->annee = $annee;
        $this->creePar = $creePar;
        $this->creeLe = $quand;
        $this->modifieLe = $quand;
        $this->definirTarif($montantMensuel, $jourEcheance);
    }

    public function definirTarif(int $montantMensuel, int $jourEcheance): void
    {
        if ($montantMensuel <= 0) {
            throw new \InvalidArgumentException('Le tarif mensuel est strictement positif.');
        }
        if ($jourEcheance < 1 || $jourEcheance > 28) {
            throw new \InvalidArgumentException('Le jour d\'échéance va de 1 à 28.');
        }
        $this->montantMensuel = $montantMensuel;
        $this->jourEcheance = $jourEcheance;
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function cloturer(): void
    {
        $this->statut = CotisationStatut::Cloturee;
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function rouvrir(): void
    {
        $this->statut = CotisationStatut::Ouverte;
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function estOuverte(): bool
    {
        return CotisationStatut::Ouverte === $this->statut;
    }

    /** La date limite de la mensualité d'un mois civil donné. */
    public function dateLimitePour(int $mois, int $anneeCivile): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('%04d-%02d-%02d', $anneeCivile, $mois, $this->jourEcheance));
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

    public function getType(): TypeContribution
    {
        return $this->type;
    }

    public function getAnnee(): int
    {
        return $this->annee;
    }

    public function getMontantMensuel(): int
    {
        return $this->montantMensuel;
    }

    public function getJourEcheance(): int
    {
        return $this->jourEcheance;
    }

    public function getStatut(): CotisationStatut
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
}
