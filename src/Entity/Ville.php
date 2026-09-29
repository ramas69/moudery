<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VilleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ville de l'association, avec sa caisse.
 *
 * Créée en brouillon par l'assistant (F-40 à F-46) : tant qu'elle n'est pas activée,
 * elle est invisible des membres et aucun email ne part.
 */
#[ORM\Entity(repositoryClass: VilleRepository::class)]
#[ORM\Table(name: 'ville')]
#[ORM\UniqueConstraint(name: 'uniq_ville_association_nom', columns: ['association_id', 'nom'])]
class Ville
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Association $association;

    #[ORM\Column(length: 120)]
    private string $nom;

    #[ORM\Column(length: 20, enumType: VilleStatut::class)]
    private VilleStatut $statut = VilleStatut::Brouillon;

    /** Étape à laquelle reprendre l'assistant : la première qui n'est pas terminée. */
    #[ORM\Column(length: 20, enumType: EtapeAssistant::class)]
    private EtapeAssistant $etapeAssistant = EtapeAssistant::Identite;

    /** @var Collection<int, InvitationResponsable> */
    #[ORM\OneToMany(targetEntity: InvitationResponsable::class, mappedBy: 'ville', cascade: ['persist'], orphanRemoval: true)]
    private Collection $invitations;

    /** @var Collection<int, Membre> */
    #[ORM\OneToMany(targetEntity: Membre::class, mappedBy: 'ville', fetch: 'EXTRA_LAZY')]
    private Collection $membres;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    public function __construct(Association $association, string $nom)
    {
        $this->association = $association;
        $this->nom = self::normaliserNom($nom);
        $this->invitations = new ArrayCollection();
        $this->membres = new ArrayCollection();
        $this->creeLe = new \DateTimeImmutable();
        $this->modifieLe = $this->creeLe;
    }

    /** Espaces superflus retirés : « Lyon » et «  Lyon  » sont la même ville. */
    public static function normaliserNom(string $nom): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $nom));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssociation(): Association
    {
        return $this->association;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function renommer(string $nom): void
    {
        $nom = self::normaliserNom($nom);
        if ($nom !== $this->nom) {
            $this->nom = $nom;
            $this->toucher();
        }
    }

    public function getStatut(): VilleStatut
    {
        return $this->statut;
    }

    public function estBrouillon(): bool
    {
        return VilleStatut::Brouillon === $this->statut;
    }

    public function estActive(): bool
    {
        return VilleStatut::Active === $this->statut;
    }

    public function estArchivee(): bool
    {
        return VilleStatut::Archivee === $this->statut;
    }

    /**
     * Transitions permises : un brouillon s'active ou s'archive, une ville active s'archive,
     * une ville archivée se réactive. Jamais de retour en brouillon, jamais de suppression.
     */
    public function peutPasserA(VilleStatut $statut): bool
    {
        return $statut === $this->statut || match ($this->statut) {
            VilleStatut::Brouillon => true,
            VilleStatut::Active => VilleStatut::Archivee === $statut,
            VilleStatut::Archivee => VilleStatut::Active === $statut,
        };
    }

    public function changerStatut(VilleStatut $statut): void
    {
        if (!$this->peutPasserA($statut)) {
            throw new \LogicException(\sprintf('Une ville « %s » ne peut pas passer à « %s ».', $this->statut->value, $statut->value));
        }

        if ($statut !== $this->statut) {
            $this->statut = $statut;
            $this->toucher();
        }
    }

    public function getEtapeAssistant(): EtapeAssistant
    {
        return $this->etapeAssistant;
    }

    /** Fait avancer le point de reprise de l'assistant, sans jamais le faire reculer. */
    public function avancerA(EtapeAssistant $etape): void
    {
        if ($this->etapeAssistant->estAvant($etape)) {
            $this->etapeAssistant = $etape;
            $this->toucher();
        }
    }

    /** @return Collection<int, Membre> */
    public function getMembres(): Collection
    {
        return $this->membres;
    }

    /** @return Collection<int, InvitationResponsable> */
    public function getInvitations(): Collection
    {
        return $this->invitations;
    }

    public function invitationPour(RoleVille $role): ?InvitationResponsable
    {
        foreach ($this->invitations as $invitation) {
            if ($invitation->getRole() === $role) {
                return $invitation;
            }
        }

        return null;
    }

    /**
     * Définit le responsable invité pour un rôle, ou le retire si l'adresse est vide.
     * Une même adresse peut être donnée pour plusieurs rôles : une personne peut les cumuler.
     */
    public function definirResponsable(RoleVille $role, ?string $email): void
    {
        $email = InvitationResponsable::normaliserEmail($email);
        $existante = $this->invitationPour($role);

        if (null === $email) {
            if (null !== $existante) {
                $this->invitations->removeElement($existante);
                $this->toucher();
            }

            return;
        }

        if (null !== $existante) {
            if ($existante->getEmail() !== $email) {
                $existante->changerEmail($email);
                $this->toucher();
            }

            return;
        }

        $this->invitations->add(new InvitationResponsable($this, $role, $email));
        $this->toucher();
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    private function toucher(): void
    {
        $this->modifieLe = new \DateTimeImmutable();
    }
}
