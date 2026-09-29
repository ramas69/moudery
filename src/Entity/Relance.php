<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RelanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une relance envoyée à un membre pour ses échéances dues (F-24, F-25) : automatique (calendrier de l'association)
 * ou manuelle (le trésorier ou le bureau central). Un membre ne reçoit jamais deux fois la même relance le même
 * jour : la relance porte le jour, le canal et la liste des échéances rappelées.
 */
#[ORM\Entity(repositoryClass: RelanceRepository::class)]
#[ORM\Table(name: 'relance')]
#[ORM\Index(name: 'idx_relance_membre_jour', columns: ['membre_id', 'jour'])]
#[ORM\Index(name: 'idx_relance_association_envoyee_le', columns: ['association_id', 'envoyee_le'])]
class Relance
{
    public const string CANAL_EMAIL = 'email';
    /** Un appel téléphonique noté à la main (« Marquer comme appelé ») : aucun message n'est envoyé. */
    public const string CANAL_TELEPHONE = 'telephone';

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

    #[ORM\Column(length: 12)]
    private string $canal = self::CANAL_EMAIL;

    #[ORM\Column]
    private bool $automatique;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $jour;

    #[ORM\Column]
    private \DateTimeImmutable $envoyeeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $envoyeePar;

    /** Le nombre d'échéances rappelées et leur total, figés au moment de l'envoi. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $nombreEcheances;

    #[ORM\Column]
    private int $montant;

    /** @var list<int> les identifiants des échéances rappelées */
    #[ORM\Column(type: Types::JSON)]
    private array $echeances;

    /** @param list<Echeance> $echeances */
    public function __construct(Membre $membre, array $echeances, bool $automatique, ?Utilisateur $envoyeePar, \DateTimeImmutable $quand, string $canal = self::CANAL_EMAIL)
    {
        if ([] === $echeances) {
            throw new \InvalidArgumentException('Une relance rappelle au moins une échéance.');
        }
        $this->membre = $membre;
        $this->ville = $membre->getVille();
        $this->association = $membre->getAssociation();
        $this->canal = $canal;
        $this->automatique = $automatique;
        $this->envoyeePar = $envoyeePar;
        $this->envoyeeLe = $quand;
        $this->jour = $quand->setTime(0, 0);
        $this->nombreEcheances = \count($echeances);
        $this->montant = array_sum(array_map(static fn (Echeance $e): int => $e->getMontant() ?? 0, $echeances));
        $this->echeances = array_values(array_map(static fn (Echeance $e): int => (int) $e->getId(), $echeances));
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

    public function getCanal(): string
    {
        return $this->canal;
    }

    public function estAutomatique(): bool
    {
        return $this->automatique;
    }

    public function estUnAppel(): bool
    {
        return self::CANAL_TELEPHONE === $this->canal;
    }

    public function getJour(): \DateTimeImmutable
    {
        return $this->jour;
    }

    public function getEnvoyeeLe(): \DateTimeImmutable
    {
        return $this->envoyeeLe;
    }

    public function getEnvoyeePar(): ?Utilisateur
    {
        return $this->envoyeePar;
    }

    public function getNombreEcheances(): int
    {
        return $this->nombreEcheances;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    /** @return list<int> */
    public function getEcheances(): array
    {
        return $this->echeances;
    }
}
