<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MembreRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un membre d'une ville (M3, F-42) : la fiche adhérent, distincte du compte de connexion qu'il aura peut-être un jour.
 * Il appartient à une ville et, éventuellement, à un foyer de cette ville. L'e-mail est facultatif (les membres sans
 * adresse sont relancés par téléphone) mais unique dans la ville quand il est renseigné. La localité (Creil, Aulnay…
 * dans la caisse de Paris), l'année de naissance et les adhésions par année viennent des classeurs des villes : on
 * les garde pour reconstituer l'historique (F-45).
 */
#[ORM\Entity(repositoryClass: MembreRepository::class)]
#[ORM\Table(name: 'membre')]
#[ORM\UniqueConstraint(name: 'uniq_membre_ville_email', columns: ['ville_id', 'email'])]
#[ORM\Index(name: 'idx_membre_ville_nom', columns: ['ville_id', 'nom', 'prenom'])]
class Membre
{
    public const string ORIGINE_SAISIE = 'saisie';
    public const string ORIGINE_IMPORT = 'import';
    public const string ORIGINE_INSCRIPTION = 'inscription';
    public const string ORIGINE_GENERATION = 'generation';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Portée par toutes les tables métier, pour le filtre multi-tenant. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Association $association;

    #[ORM\ManyToOne(inversedBy: 'membres')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Ville $ville;

    #[ORM\ManyToOne(inversedBy: 'membres')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Foyer $foyer = null;

    /** Le compte de connexion, quand le membre en a activé un. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $compte = null;

    #[ORM\Column(length: 80)]
    private string $prenom;

    #[ORM\Column(length: 80)]
    private string $nom;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephone;

    /** La localité du membre à l'intérieur de la ville, quand la caisse en regroupe plusieurs (« Creil » dans la caisse de Paris). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $localite = null;

    /** L'année de naissance plutôt qu'un âge, qui serait faux l'année suivante. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $anneeNaissance = null;

    #[ORM\Column(length: 20, enumType: MembreStatut::class)]
    private MembreStatut $statut;

    /** @var Collection<int, Adhesion> les années où ce membre était adhérent */
    #[ORM\OneToMany(targetEntity: Adhesion::class, mappedBy: 'membre', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['annee' => 'ASC'])]
    private Collection $adhesions;

    /** Consentement à recevoir les relances et informations par e-mail (RGPD, section 9). */
    #[ORM\Column]
    private bool $consentEmail = true;

    /** D'où vient la fiche : saisie, import, inscription libre, génération de données d'essai. */
    #[ORM\Column(length: 20)]
    private string $origine;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    public function __construct(Ville $ville, string $prenom, string $nom, ?string $email = null, ?string $telephone = null, MembreStatut $statut = MembreStatut::Actif, string $origine = self::ORIGINE_SAISIE)
    {
        $this->ville = $ville;
        $this->association = $ville->getAssociation();
        $this->prenom = self::normaliserNom($prenom);
        $this->nom = self::normaliserNom($nom);
        $this->email = Utilisateur::normaliserEmail($email);
        $this->telephone = self::normaliserTelephone($telephone);
        $this->statut = $statut;
        $this->origine = $origine;
        $this->creeLe = new \DateTimeImmutable();
        $this->modifieLe = $this->creeLe;
        $this->adhesions = new ArrayCollection();

        if ('' === $this->prenom || '' === $this->nom) {
            throw new \InvalidArgumentException('Un membre a un prénom et un nom.');
        }
    }

    /** Espaces superflus retirés ; la casse saisie est respectée. */
    public static function normaliserNom(string $nom): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $nom));
    }

    /** Chiffres seulement, un « + » international conservé en tête : « 06 12 34 56 78 » devient « 0612345678 ». */
    public static function normaliserTelephone(?string $telephone): ?string
    {
        $telephone = trim((string) $telephone);
        if ('' === $telephone) {
            return null;
        }
        $plus = str_starts_with($telephone, '+') ? '+' : '';
        $chiffres = (string) preg_replace('/\D+/', '', $telephone);

        return '' === $chiffres ? null : $plus.$chiffres;
    }

    /** « 0612345678 » s'affiche « 06 12 34 56 78 » ; un numéro international reste tel quel. */
    public function getTelephoneAffiche(): ?string
    {
        if (null === $this->telephone) {
            return null;
        }
        if (10 === \strlen($this->telephone) && ctype_digit($this->telephone)) {
            return trim(chunk_split($this->telephone, 2, ' '));
        }

        return $this->telephone;
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

    public function getFoyer(): ?Foyer
    {
        return $this->foyer;
    }

    public function getCompte(): ?Utilisateur
    {
        return $this->compte;
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
        return $this->prenom.' '.$this->nom;
    }

    public function getInitiales(): string
    {
        return mb_strtoupper(mb_substr($this->prenom, 0, 1).mb_substr($this->nom, 0, 1));
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function getLocalite(): ?string
    {
        return $this->localite;
    }

    public function getAnneeNaissance(): ?int
    {
        return $this->anneeNaissance;
    }

    /** L'âge atteint dans l'année, ou null sans année de naissance. */
    public function getAge(?int $annee = null): ?int
    {
        return null === $this->anneeNaissance ? null : ($annee ?? (int) date('Y')) - $this->anneeNaissance;
    }

    public function definirLocalite(?string $localite): void
    {
        $localite = self::normaliserNom((string) $localite);
        $this->localite = '' === $localite ? null : $localite;
        $this->toucher();
    }

    /** Une année de naissance plausible ; null pour l'effacer. */
    public function definirAnneeNaissance(?int $annee): void
    {
        if (null !== $annee && ($annee < 1900 || $annee > (int) date('Y'))) {
            throw new \InvalidArgumentException(\sprintf('L\'année de naissance « %d » n\'est pas plausible.', $annee));
        }
        $this->anneeNaissance = $annee;
        $this->toucher();
    }

    /** @return Collection<int, Adhesion> */
    public function getAdhesions(): Collection
    {
        return $this->adhesions;
    }

    /** @return list<int> les années d'adhésion, croissantes */
    public function getAnneesAdhesion(): array
    {
        $annees = array_map(static fn (Adhesion $a): int => $a->getAnnee(), $this->adhesions->toArray());
        sort($annees);

        return array_values(array_unique($annees));
    }

    public function estAdherent(int $annee): bool
    {
        return \in_array($annee, $this->getAnneesAdhesion(), true);
    }

    public function adhesionPour(int $annee): ?Adhesion
    {
        foreach ($this->adhesions as $adhesion) {
            if ($adhesion->getAnnee() === $annee) {
                return $adhesion;
            }
        }

        return null;
    }

    /** Enregistre l'adhésion de cette année, une seule fois : la même année redonnée ne change rien. */
    public function adherer(int $annee, string $origine = self::ORIGINE_SAISIE): Adhesion
    {
        foreach ($this->adhesions as $adhesion) {
            if ($adhesion->getAnnee() === $annee) {
                return $adhesion;
            }
        }
        $adhesion = new Adhesion($this, $annee, $origine);
        $this->adhesions->add($adhesion);
        $this->toucher();

        return $adhesion;
    }

    public function getStatut(): MembreStatut
    {
        return $this->statut;
    }

    public function estActif(): bool
    {
        return MembreStatut::Actif === $this->statut;
    }

    public function aConsentiEmail(): bool
    {
        return $this->consentEmail;
    }

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function modifier(string $prenom, string $nom, ?string $email, ?string $telephone): void
    {
        $this->prenom = self::normaliserNom($prenom);
        $this->nom = self::normaliserNom($nom);
        $this->email = Utilisateur::normaliserEmail($email);
        $this->telephone = self::normaliserTelephone($telephone);
        $this->toucher();
    }

    /** Rejoint un foyer de la même ville, ou le quitte (null). Le foyer tient sa liste à jour. */
    public function rejoindreFoyer(?Foyer $foyer): void
    {
        if (null !== $foyer && $foyer->getVille() !== $this->ville) {
            throw new \LogicException('Un foyer et ses membres sont de la même ville.');
        }
        $this->foyer?->retirer($this);
        $this->foyer = $foyer;
        $foyer?->accueillir($this);
        $this->toucher();
    }

    /**
     * Transfert vers une autre ville de l'association (F-10) : la fiche change de ville, quitte son foyer, et garde
     * tout son historique (adhésions, paiements, relances restent rattachés à l'ancienne caisse).
     */
    public function transfererVers(Ville $ville): void
    {
        if ($ville->getAssociation() !== $this->association) {
            throw new \LogicException('Un membre ne se transfère que vers une ville de son association.');
        }
        if ($ville === $this->ville) {
            throw new \LogicException('Le membre est déjà dans cette ville.');
        }
        $this->rejoindreFoyer(null);
        $this->ville = $ville;
        $this->toucher();
    }

    public function valider(): void
    {
        $this->statut = MembreStatut::Actif;
        $this->toucher();
    }

    public function sortir(): void
    {
        $this->statut = MembreStatut::Sorti;
        $this->rejoindreFoyer(null);
    }

    /** Le membre accepte ou refuse les e-mails de l'association (appels, relances, reçus) depuis son profil (F-04). */
    public function definirConsentementEmail(bool $consentement): void
    {
        $this->consentEmail = $consentement;
        $this->toucher();
    }

    public function rattacherCompte(?Utilisateur $compte): void
    {
        $this->compte = $compte;
        $this->toucher();
    }

    private function toucher(): void
    {
        $this->modifieLe = new \DateTimeImmutable();
    }
}
