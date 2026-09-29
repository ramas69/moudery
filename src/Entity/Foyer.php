<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FoyerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un foyer (F-09) regroupe des membres d'une même ville ; un membre payeur reçoit les contributions « par foyer »
 * et son paiement vaut pour tous. Le nom est libre (« Famille Diaby ») et unique dans la ville.
 */
#[ORM\Entity(repositoryClass: FoyerRepository::class)]
#[ORM\Table(name: 'foyer')]
#[ORM\UniqueConstraint(name: 'uniq_foyer_ville_nom', columns: ['ville_id', 'nom'])]
class Foyer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Association $association;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ville $ville;

    #[ORM\Column(length: 120)]
    private string $nom;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Membre $payeur = null;

    /** @var Collection<int, Membre> */
    #[ORM\OneToMany(targetEntity: Membre::class, mappedBy: 'foyer')]
    private Collection $membres;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(Ville $ville, string $nom)
    {
        $this->ville = $ville;
        $this->association = $ville->getAssociation();
        $this->nom = Membre::normaliserNom($nom);
        $this->membres = new ArrayCollection();
        $this->creeLe = new \DateTimeImmutable();

        if ('' === $this->nom) {
            throw new \InvalidArgumentException('Un foyer a un nom.');
        }
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

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getPayeur(): ?Membre
    {
        return $this->payeur;
    }

    /** @return Collection<int, Membre> */
    public function getMembres(): Collection
    {
        return $this->membres;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    /** Le payeur est forcément un membre du foyer ; le premier arrivé le devient par défaut. */
    public function designerPayeur(Membre $membre): void
    {
        if (!$this->membres->contains($membre)) {
            throw new \LogicException('Le payeur d\'un foyer est un de ses membres.');
        }
        $this->payeur = $membre;
    }

    /** @internal appelé par Membre::rejoindreFoyer() */
    public function accueillir(Membre $membre): void
    {
        if (!$this->membres->contains($membre)) {
            $this->membres->add($membre);
        }
        $this->payeur ??= $membre;
    }

    /** @internal appelé par Membre::rejoindreFoyer() */
    public function retirer(Membre $membre): void
    {
        $this->membres->removeElement($membre);
        if ($this->payeur === $membre) {
            $this->payeur = $this->membres->first() ?: null;
        }
    }
}
