<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvitationRepository;
use App\Security\Perimetre;
use App\Security\Role;
use Doctrine\ORM\Mapping as ORM;

/**
 * Invitation à créer son compte (F-02) : un rôle sur un périmètre, envoyée par email à une adresse.
 * Le lien vaut 7 jours et ne sert qu'une fois ; le compte est actif dès que la personne choisit son mot de passe.
 * Seule l'empreinte du jeton est conservée : le jeton en clair ne figure que dans l'email.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitation')]
#[ORM\UniqueConstraint(name: 'uniq_invitation_jeton', columns: ['jeton'])]
class Invitation
{
    public const string DUREE_VALIDITE = 'P7D';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Portée par toutes les tables métier, pour le filtre multi-tenant. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Association $association;

    /** Null : le périmètre est toute l'association. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ville $ville;

    #[ORM\Column(length: 20, enumType: Role::class)]
    private Role $role;

    #[ORM\Column(length: 180)]
    private string $email;

    /** Empreinte SHA-256 du jeton ; remplacée à chaque renvoi. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $jeton = null;

    #[ORM\Column]
    private \DateTimeImmutable $envoyeeLe;

    #[ORM\Column]
    private \DateTimeImmutable $expireLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $accepteeLe = null;

    /** Le compte créé en acceptant l'invitation. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $compte = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $inviteePar;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(Association $association, Role $role, string $email, string $jetonHache, \DateTimeImmutable $quand, ?Utilisateur $inviteePar = null, ?Ville $ville = null)
    {
        $perimetre = $role->perimetre();
        if (Perimetre::Plateforme === $perimetre) {
            throw new \InvalidArgumentException(\sprintf('Le rôle « %s » ne s\'attribue pas par invitation.', $role->value));
        }
        if (Perimetre::Ville === $perimetre && null === $ville) {
            throw new \InvalidArgumentException(\sprintf('Le rôle « %s » s\'attribue sur une ville.', $role->value));
        }
        if (Perimetre::Ville !== $perimetre && null !== $ville) {
            throw new \InvalidArgumentException(\sprintf('Le rôle « %s » ne s\'attribue pas sur une ville.', $role->value));
        }
        if (null !== $ville && $ville->getAssociation() !== $association && $ville->getAssociation()->getId() !== $association->getId()) {
            throw new \InvalidArgumentException('La ville n\'appartient pas à cette association.');
        }

        $this->association = $association;
        $this->ville = $ville;
        $this->role = $role;
        $this->email = Utilisateur::normaliserEmail($email) ?? throw new \InvalidArgumentException('L\'adresse email de l\'invitation est vide.');
        $this->inviteePar = $inviteePar;
        $this->creeLe = $quand;
        $this->emettre($jetonHache, $quand);
    }

    /** Émet ou renouvelle le lien : nouveau jeton, nouvelle échéance à 7 jours. */
    public function emettre(string $jetonHache, \DateTimeImmutable $quand): void
    {
        if (null !== $this->accepteeLe) {
            throw new \LogicException('Une invitation acceptée ne se renvoie pas.');
        }

        $this->jeton = $jetonHache;
        $this->envoyeeLe = $quand;
        $this->expireLe = $quand->add(new \DateInterval(self::DUREE_VALIDITE));
    }

    /** L'invitation du bureau central d'une association qui n'a pas encore souscrit : le lien mène à la page de souscription. */
    public function ouvreLaSouscription(): bool
    {
        return Role::BureauCentral === $this->role
            && null === $this->ville
            && true === $this->association->getAbonnement()?->attendLaSouscription();
    }

    public function estValide(\DateTimeImmutable $maintenant): bool
    {
        return null === $this->accepteeLe && null !== $this->jeton && $this->expireLe > $maintenant;
    }

    public function estAcceptee(): bool
    {
        return null !== $this->accepteeLe;
    }

    public function estExpiree(\DateTimeImmutable $maintenant): bool
    {
        return null === $this->accepteeLe && $this->expireLe <= $maintenant;
    }

    /** Le lien ne sert qu'une fois : accepté, il reste reconnaissable pour dire « déjà utilisé », mais plus valable. */
    public function accepter(Utilisateur $compte, \DateTimeImmutable $quand): void
    {
        if (!$this->estValide($quand)) {
            throw new \LogicException('Cette invitation n\'est plus valable.');
        }

        $this->accepteeLe = $quand;
        $this->compte = $compte;
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

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getEnvoyeeLe(): \DateTimeImmutable
    {
        return $this->envoyeeLe;
    }

    public function getExpireLe(): \DateTimeImmutable
    {
        return $this->expireLe;
    }

    public function getAccepteeLe(): ?\DateTimeImmutable
    {
        return $this->accepteeLe;
    }

    public function getCompte(): ?Utilisateur
    {
        return $this->compte;
    }

    public function getInviteePar(): ?Utilisateur
    {
        return $this->inviteePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
