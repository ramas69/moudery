<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UtilisateurRepository;
use App\Security\Role;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Compte de connexion (M1, F-03). L'adresse email est l'identifiant, unique sur toute la plateforme.
 *
 * Les droits ne sont pas portés par le compte mais par ses affectations (F-07) : un rôle sur un périmètre.
 * Le compte appartient à une association (le tenant) : les données d'une autre association lui sont invisibles.
 * Seul le super-admin plateforme n'a pas d'association.
 */
#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\Table(name: 'utilisateur')]
#[ORM\UniqueConstraint(name: 'uniq_utilisateur_email', columns: ['email'])]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** Durée de validité d'un lien de réinitialisation du mot de passe. */
    public const string DUREE_JETON_REINITIALISATION = 'PT1H';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null seulement pour le super-admin plateforme. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Association $association;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 80)]
    private string $prenom;

    #[ORM\Column(length: 80)]
    private string $nom;

    /** Haché par Symfony (algorithme « auto »). */
    #[ORM\Column(length: 255)]
    private string $motDePasse;

    #[ORM\Column(length: 20, enumType: UtilisateurStatut::class)]
    private UtilisateurStatut $statut;

    /** Téléphone facultatif donné à l'activation, chiffres seulement (comme `Membre`). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    /** Accord pour recevoir les e-mails de l'association (inscriptions à valider, dépenses, relances, reçus). */
    #[ORM\Column(options: ['default' => true])]
    private bool $consentEmail = true;

    /** Empreinte SHA-256 du jeton envoyé par email : le jeton en clair n'est jamais stocké. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $jetonReinitialisation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $jetonReinitialisationExpireLe = null;

    /** @var Collection<int, Affectation> */
    #[ORM\OneToMany(targetEntity: Affectation::class, mappedBy: 'utilisateur', cascade: ['persist'], orphanRemoval: true)]
    private Collection $affectations;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $derniereConnexionLe = null;

    public function __construct(
        ?Association $association,
        string $email,
        string $prenom,
        string $nom,
        string $motDePasseHache,
        UtilisateurStatut $statut = UtilisateurStatut::EnAttente,
    ) {
        $this->association = $association;
        $this->email = self::normaliserEmail($email) ?? throw new \InvalidArgumentException('L\'adresse email du compte est vide.');
        $this->prenom = trim($prenom);
        $this->nom = trim($nom);
        $this->motDePasse = $motDePasseHache;
        $this->statut = $statut;
        $this->affectations = new ArrayCollection();
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

    public function getAssociation(): ?Association
    {
        return $this->association;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function aConsentiEmail(): bool
    {
        return $this->consentEmail;
    }

    /** Téléphone et accord pour les e-mails, donnés à l'activation ou dans les paramètres. */
    public function definirCoordonnees(?string $telephone, bool $consentEmail): void
    {
        $this->telephone = Membre::normaliserTelephone($telephone);
        $this->consentEmail = $consentEmail;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getNomComplet(): string
    {
        return trim($this->prenom.' '.$this->nom);
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function modifierIdentite(string $prenom, string $nom): void
    {
        $this->prenom = trim($prenom);
        $this->nom = trim($nom);
    }

    /** L'adresse est l'identifiant de connexion : la personne se connectera avec la nouvelle. */
    public function changerEmail(string $email): void
    {
        $this->email = self::normaliserEmail($email) ?? throw new \InvalidArgumentException('L\'adresse email du compte est vide.');
    }

    /**
     * Les droits réels passent par les affectations et les Voters ; ROLE_USER dit seulement « connecté ».
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->motDePasse;
    }

    public function definirMotDePasse(string $motDePasseHache): void
    {
        $this->motDePasse = $motDePasseHache;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    public function getStatut(): UtilisateurStatut
    {
        return $this->statut;
    }

    public function estActif(): bool
    {
        return UtilisateurStatut::Actif === $this->statut;
    }

    public function activer(): void
    {
        $this->statut = UtilisateurStatut::Actif;
    }

    public function desactiver(): void
    {
        $this->statut = UtilisateurStatut::Desactive;
    }

    /** @return Collection<int, Affectation> */
    public function getAffectations(): Collection
    {
        return $this->affectations;
    }

    /** Attribue un rôle sur un périmètre ; la même affectation n'est jamais créée deux fois. */
    public function affecter(Role $role, ?Ville $ville = null): Affectation
    {
        $existante = $this->affectationPour($role, $ville);
        if (null !== $existante) {
            return $existante;
        }

        $affectation = new Affectation($this, $role, $ville);
        $this->affectations->add($affectation);

        return $affectation;
    }

    public function retirerAffectation(Affectation $affectation): void
    {
        $this->affectations->removeElement($affectation);
    }

    public function affectationPour(Role $role, ?Ville $ville): ?Affectation
    {
        foreach ($this->affectations as $affectation) {
            if ($affectation->getRole() === $role && $affectation->porteSurLaVille($ville)) {
                return $affectation;
            }
        }

        return null;
    }

    public function aLeRole(Role $role): bool
    {
        return null !== $this->premiereAffectation($role);
    }

    /** Le rôle que ce compte tient sur une ville (trésorier, président, secrétaire, membre), ou null. */
    public function roleSur(Ville $ville): ?Role
    {
        foreach ($this->affectations as $affectation) {
            if (null !== $affectation->getVille() && $affectation->getVille()->getId() === $ville->getId()) {
                return $affectation->getRole();
            }
        }

        return null;
    }

    public function premiereAffectation(Role $role): ?Affectation
    {
        foreach ($this->affectations as $affectation) {
            if ($affectation->getRole() === $role) {
                return $affectation;
            }
        }

        return null;
    }

    /** Le compte a-t-il cette permission sur ce sujet, par l'une de ses affectations ? */
    public function aLaPermission(string $permission, Association|Ville $sujet): bool
    {
        foreach ($this->affectations as $affectation) {
            if ($affectation->getRole()->accorde($permission) && $affectation->couvre($sujet)) {
                return true;
            }
        }

        return false;
    }

    /** Le compte a-t-il cette permission sur la plateforme entière, hors de toute association ? */
    public function aLaPermissionSurLaPlateforme(string $permission): bool
    {
        foreach ($this->affectations as $affectation) {
            if (null === $affectation->getAssociation() && $affectation->getRole()->accorde($permission)) {
                return true;
            }
        }

        return false;
    }

    public function demanderReinitialisation(string $jetonHache, \DateTimeImmutable $expireLe): void
    {
        $this->jetonReinitialisation = $jetonHache;
        $this->jetonReinitialisationExpireLe = $expireLe;
    }

    public function jetonReinitialisationValide(\DateTimeImmutable $maintenant): bool
    {
        return null !== $this->jetonReinitialisation
            && null !== $this->jetonReinitialisationExpireLe
            && $this->jetonReinitialisationExpireLe > $maintenant;
    }

    /** Le lien ne sert qu'une fois : il est effacé dès que le mot de passe est enregistré. */
    public function terminerReinitialisation(): void
    {
        $this->jetonReinitialisation = null;
        $this->jetonReinitialisationExpireLe = null;
    }

    public function getJetonReinitialisation(): ?string
    {
        return $this->jetonReinitialisation;
    }

    public function marquerConnexion(\DateTimeImmutable $quand): void
    {
        $this->derniereConnexionLe = $quand;
    }

    public function getDerniereConnexionLe(): ?\DateTimeImmutable
    {
        return $this->derniereConnexionLe;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    /**
     * En session, seuls l'identifiant, l'email, le mot de passe haché et le statut sont conservés :
     * Symfony recharge le compte complet depuis la base à chaque requête.
     *
     * @return array{id: ?int, email: string, motDePasse: string, statut: UtilisateurStatut}
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'motDePasse' => $this->motDePasse,
            'statut' => $this->statut,
        ];
    }

    /** @param array{id: ?int, email: string, motDePasse: string, statut: UtilisateurStatut} $donnees */
    public function __unserialize(array $donnees): void
    {
        $this->id = $donnees['id'];
        $this->email = $donnees['email'];
        $this->motDePasse = $donnees['motDePasse'];
        $this->statut = $donnees['statut'];
        $this->affectations = new ArrayCollection();
    }
}
