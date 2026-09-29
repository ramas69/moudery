<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvitationResponsableRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Invitation d'un responsable de ville (trésorier, président, secrétaire) par email.
 *
 * Créée dès l'étape Identité de l'assistant (F-40), mais envoyée seulement à l'activation
 * de la ville (F-46). Le lien d'activation vaut 7 jours (F-02).
 */
#[ORM\Entity(repositoryClass: InvitationResponsableRepository::class)]
#[ORM\Table(name: 'invitation_responsable')]
#[ORM\UniqueConstraint(name: 'uniq_invitation_ville_role', columns: ['ville_id', 'role'])]
class InvitationResponsable
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

    #[ORM\ManyToOne(inversedBy: 'invitations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ville $ville;

    #[ORM\Column(length: 20, enumType: RoleVille::class)]
    private RoleVille $role;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $token = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $envoyeeLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expireLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $accepteeLe = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(Ville $ville, RoleVille $role, string $email)
    {
        $this->ville = $ville;
        $this->association = $ville->getAssociation();
        $this->role = $role;
        $this->email = self::normaliserEmail($email) ?? throw new \InvalidArgumentException('L\'adresse email de l\'invitation est vide.');
        $this->creeLe = new \DateTimeImmutable();
    }

    /** Minuscules et sans espaces autour ; null si vide. */
    public static function normaliserEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return '' === $email ? null : $email;
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

    public function getRole(): RoleVille
    {
        return $this->role;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /** Nouveau destinataire : tout envoi précédent devient caduc. */
    public function changerEmail(string $email): void
    {
        $this->email = self::normaliserEmail($email) ?? throw new \InvalidArgumentException('L\'adresse email de l\'invitation est vide.');
        $this->token = null;
        $this->envoyeeLe = null;
        $this->expireLe = null;
        $this->accepteeLe = null;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function estEnvoyee(): bool
    {
        return null !== $this->envoyeeLe;
    }

    public function getEnvoyeeLe(): ?\DateTimeImmutable
    {
        return $this->envoyeeLe;
    }

    public function getExpireLe(): ?\DateTimeImmutable
    {
        return $this->expireLe;
    }

    public function getAccepteeLe(): ?\DateTimeImmutable
    {
        return $this->accepteeLe;
    }

    /** Émet le lien d'activation, valable 7 jours. Appelée à l'activation de la ville (F-46). */
    public function marquerEnvoyee(\DateTimeImmutable $quand): void
    {
        $this->token = bin2hex(random_bytes(32));
        $this->envoyeeLe = $quand;
        $this->expireLe = $quand->add(new \DateInterval(self::DUREE_VALIDITE));
    }

    /** La personne a déjà un compte dans l'association : le rôle lui est donné à l'activation, sans email. */
    public function marquerAcceptee(\DateTimeImmutable $quand): void
    {
        $this->accepteeLe = $quand;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
