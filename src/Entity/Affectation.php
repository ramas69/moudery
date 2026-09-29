<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AffectationRepository;
use App\Security\Perimetre;
use App\Security\Role;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un rôle attribué à un compte sur un périmètre (F-07) : toute la plateforme (association nulle),
 * toute une association (ville nulle) ou une ville précise.
 * Le trésorier de Lyon n'a aucun accès aux données de Marseille : c'est le périmètre qui l'en empêche.
 */
#[ORM\Entity(repositoryClass: AffectationRepository::class)]
#[ORM\Table(name: 'affectation')]
class Affectation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null seulement pour le super-admin plateforme, qui n'appartient à aucune association. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Association $association;

    #[ORM\ManyToOne(inversedBy: 'affectations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Utilisateur $utilisateur;

    #[ORM\Column(length: 20, enumType: Role::class)]
    private Role $role;

    /** Null : le périmètre est plus large qu'une ville. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ville $ville;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(Utilisateur $utilisateur, Role $role, ?Ville $ville = null)
    {
        $perimetre = $role->perimetre();
        $association = $utilisateur->getAssociation();

        if (Perimetre::Ville === $perimetre && null === $ville) {
            throw new \InvalidArgumentException(\sprintf('Le rôle « %s » s\'attribue sur une ville.', $role->value));
        }
        if (Perimetre::Ville !== $perimetre && null !== $ville) {
            throw new \InvalidArgumentException(\sprintf('Le rôle « %s » ne s\'attribue pas sur une ville.', $role->value));
        }
        if (Perimetre::Plateforme !== $perimetre && null === $association) {
            throw new \InvalidArgumentException(\sprintf('Le rôle « %s » exige un compte rattaché à une association.', $role->value));
        }
        if (null !== $ville && null !== $association && !self::memeEntite($ville->getAssociation(), $association)) {
            throw new \InvalidArgumentException('La ville n\'appartient pas à l\'association du compte.');
        }

        $this->utilisateur = $utilisateur;
        $this->association = Perimetre::Plateforme === $perimetre ? null : $association;
        $this->role = $role;
        $this->ville = $ville;
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssociation(): ?Association
    {
        return $this->association;
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getVille(): ?Ville
    {
        return $this->ville;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    /** Le périmètre de l'affectation englobe-t-il ce sujet ? Le rôle, lui, dit ce qu'on peut y faire. */
    public function couvre(Association|Ville $sujet): bool
    {
        if (null === $this->association) {
            return true; // Périmètre : toute la plateforme.
        }

        $association = $sujet instanceof Ville ? $sujet->getAssociation() : $sujet;
        if (!self::memeEntite($association, $this->association)) {
            return false;
        }

        if (null === $this->ville) {
            return true; // Périmètre : toute l'association.
        }

        return $sujet instanceof Ville && self::memeEntite($sujet, $this->ville);
    }

    public function porteSurLaVille(?Ville $ville): bool
    {
        if (null === $ville || null === $this->ville) {
            return $ville === $this->ville;
        }

        return self::memeEntite($ville, $this->ville);
    }

    /** Deux entités sont les mêmes si c'est le même objet, ou le même identifiant une fois enregistrées. */
    private static function memeEntite(Association|Ville $a, Association|Ville $b): bool
    {
        return $a === $b || (null !== $a->getId() && $a->getId() === $b->getId());
    }
}
