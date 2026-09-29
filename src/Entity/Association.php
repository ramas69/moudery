<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssociationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le tenant : une association de diaspora et ses villes.
 * Son identité (village, contact, existence juridique) et ses paramètres de gestion
 * (exercice, reversement par défaut, relances, compte Stripe du central) sont administrés par le super-admin.
 */
#[ORM\Entity(repositoryClass: AssociationRepository::class)]
#[ORM\Table(name: 'association')]
class Association
{
    /** Relances par défaut, en jours par rapport à l'échéance : J-7, le jour J, J+15 (F-24). */
    public const array RELANCES_PAR_DEFAUT = [-7, 0, 15];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $nom;

    #[ORM\Column(length: 80, unique: true)]
    private string $slug;

    #[ORM\Column(length: 20, enumType: AssociationStatut::class, options: ['default' => 'active'])]
    private AssociationStatut $statut = AssociationStatut::Active;

    /** Nom du village, affiché sous le logo ; le nom de l'association à défaut. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $village = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $emailContact = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephoneContact = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adresseSiege = null;

    /** Numéro au répertoire national des associations : W suivi de neuf chiffres. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $numeroRna = null;

    #[ORM\Column(length: 9, nullable: true)]
    private ?string $siren = null;

    /** Décision de Rama du 28 septembre 2026 : l'application suit les exercices à partir de 2025. */
    public const int PREMIER_EXERCICE_PAR_DEFAUT = 2025;

    /** Mois de début de l'exercice comptable, de 1 (janvier) à 12 (décembre). */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $debutExerciceMois = 1;

    /** Année civile où commence le premier exercice suivi dans l'application ; avant, il n'y a que l'historique importé. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => self::PREMIER_EXERCICE_PAR_DEFAUT])]
    private int $premierExercice = self::PREMIER_EXERCICE_PAR_DEFAUT;

    /** Part reversée au bureau central proposée par défaut pour un nouveau type de contribution, en pourcentage. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $tauxReversementDefaut = 0;

    /**
     * Calendrier des relances, en jours par rapport à l'échéance : négatif avant, positif après (F-24).
     *
     * @var list<int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $calendrierRelances = self::RELANCES_PAR_DEFAUT;

    /** Identifiant du compte Stripe Connect du bureau central, une fois l'onboarding fait (F-06). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $compteStripe = null;

    /**
     * Réglages d'import appris des imports confirmés (F-31) : « colonnes » (en-tête normalisé => champ), « ordre_nom »,
     * « mode » (onglets ou colonne) et « colonne_caisse » ; le fichier de l'année suivante se reconnaît tout seul.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $reglagesImport = null;

    /** L'abonnement à la plateforme ; toute association en a un, offert par défaut. */
    #[ORM\OneToOne(mappedBy: 'association', targetEntity: Abonnement::class, cascade: ['persist', 'remove'])]
    private ?Abonnement $abonnement = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(string $nom, string $slug)
    {
        $this->nom = trim($nom);
        $this->slug = $slug;
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function renommer(string $nom): void
    {
        $this->nom = trim($nom);
    }

    /** L'identifiant change dans toutes les adresses de l'association : à manier avec prudence. */
    public function changerSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getStatut(): AssociationStatut
    {
        return $this->statut;
    }

    public function estActive(): bool
    {
        return AssociationStatut::Active === $this->statut;
    }

    public function estSuspendue(): bool
    {
        return AssociationStatut::Suspendue === $this->statut;
    }

    public function estArchivee(): bool
    {
        return AssociationStatut::Archivee === $this->statut;
    }

    /** Active ↔ suspendue, active ou suspendue → archivée, archivée → active : jamais de suppression. */
    public function peutPasserA(AssociationStatut $statut): bool
    {
        return $statut === $this->statut || match ($this->statut) {
            AssociationStatut::Active, AssociationStatut::Suspendue => true,
            AssociationStatut::Archivee => AssociationStatut::Active === $statut,
        };
    }

    public function changerStatut(AssociationStatut $statut): void
    {
        if (!$this->peutPasserA($statut)) {
            throw new \LogicException(\sprintf('Une association « %s » ne peut pas passer à « %s ».', $this->statut->value, $statut->value));
        }

        $this->statut = $statut;
    }

    public function getVillage(): ?string
    {
        return $this->village;
    }

    /** Ce qui s'affiche sous le logo. */
    public function getNomVillage(): string
    {
        return $this->village ?? $this->nom;
    }

    public function definirVillage(?string $village): void
    {
        $this->village = self::texteOuNull($village);
    }

    public function getEmailContact(): ?string
    {
        return $this->emailContact;
    }

    public function getTelephoneContact(): ?string
    {
        return $this->telephoneContact;
    }

    public function getAdresseSiege(): ?string
    {
        return $this->adresseSiege;
    }

    public function definirContact(?string $email, ?string $telephone, ?string $adresseSiege): void
    {
        $this->emailContact = Utilisateur::normaliserEmail($email);
        $this->telephoneContact = self::texteOuNull($telephone);
        $this->adresseSiege = self::texteOuNull($adresseSiege);
    }

    public function getNumeroRna(): ?string
    {
        return $this->numeroRna;
    }

    public function getSiren(): ?string
    {
        return $this->siren;
    }

    /** Existence juridique, exigée par Stripe pour l'onboarding. Les espaces sont retirés, le RNA mis en majuscules. */
    public function definirIdentifiantsLegaux(?string $numeroRna, ?string $siren): void
    {
        $rna = self::texteOuNull($numeroRna);
        $this->numeroRna = null === $rna ? null : strtoupper((string) preg_replace('/\s+/', '', $rna));
        $sirenNettoye = self::texteOuNull($siren);
        $this->siren = null === $sirenNettoye ? null : (string) preg_replace('/\s+/', '', $sirenNettoye);
    }

    public function getDebutExerciceMois(): int
    {
        return $this->debutExerciceMois;
    }

    public function getPremierExercice(): int
    {
        return $this->premierExercice;
    }

    /** L'année du premier exercice suivi : le tableau de bord et les filtres d'année partent de là. */
    public function definirPremierExercice(int $annee): void
    {
        if ($annee < 2000 || $annee > 2100) {
            throw new \InvalidArgumentException('Le premier exercice est une année entre 2000 et 2100.');
        }
        $this->premierExercice = $annee;
    }

    /**
     * Les exercices suivis, du plus récent au premier : chaque année civile de début d'exercice, depuis celui qui
     * commence l'année donnée jusqu'au premier exercice (au moins le premier).
     *
     * @return list<int>
     */
    public function exercices(int $anneeDebutCourante): array
    {
        return range(max($anneeDebutCourante, $this->premierExercice), $this->premierExercice);
    }

    public function getTauxReversementDefaut(): int
    {
        return $this->tauxReversementDefaut;
    }

    /** @return list<int> */
    public function getCalendrierRelances(): array
    {
        return $this->calendrierRelances;
    }

    /** @return array<string, mixed> */
    public function getReglagesImport(): array
    {
        return $this->reglagesImport ?? [];
    }

    /** @return array<string, string> en-tête normalisé => champ */
    public function getDictionnaireImport(): array
    {
        $colonnes = $this->reglagesImport['colonnes'] ?? [];

        return \is_array($colonnes) ? array_filter($colonnes, static fn ($v, $k): bool => \is_string($v) && \is_string($k), \ARRAY_FILTER_USE_BOTH) : [];
    }

    /**
     * Retient ce qu'un import confirmé a appris : le dictionnaire des en-têtes s'enrichit, les autres réglages sont remplacés.
     *
     * @param array<string, mixed> $reglages
     */
    public function memoriserReglagesImport(array $reglages): void
    {
        $colonnes = $this->getDictionnaireImport();
        foreach ($reglages['colonnes'] ?? [] as $enTete => $champ) {
            if (\is_string($enTete) && \is_string($champ)) {
                $colonnes[$enTete] = $champ;
            }
        }
        $this->reglagesImport = [...$this->getReglagesImport(), ...$reglages, 'colonnes' => $colonnes];
    }

    /** @param list<int> $calendrierRelances jours par rapport à l'échéance, dans n'importe quel ordre */
    public function definirParametres(int $debutExerciceMois, int $tauxReversementDefaut, array $calendrierRelances): void
    {
        if ($debutExerciceMois < 1 || $debutExerciceMois > 12) {
            throw new \InvalidArgumentException('Le mois de début d\'exercice va de 1 à 12.');
        }
        if ($tauxReversementDefaut < 0 || $tauxReversementDefaut > 100) {
            throw new \InvalidArgumentException('Le taux de reversement va de 0 à 100 %.');
        }

        $relances = array_values(array_unique(array_map(static fn (int|string $jour): int => (int) $jour, $calendrierRelances)));
        sort($relances);

        $this->debutExerciceMois = $debutExerciceMois;
        $this->tauxReversementDefaut = $tauxReversementDefaut;
        $this->calendrierRelances = $relances;
    }

    public function getCompteStripe(): ?string
    {
        return $this->compteStripe;
    }

    public function definirCompteStripe(?string $compteStripe): void
    {
        $this->compteStripe = self::texteOuNull($compteStripe);
    }

    public function getAbonnement(): ?Abonnement
    {
        return $this->abonnement;
    }

    /** Ouvre l'abonnement de l'association, offert et sans échéance tant que le super-admin n'en décide pas autrement. */
    public function ouvrirAbonnement(): Abonnement
    {
        return $this->abonnement ??= new Abonnement($this, $this->creeLe);
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    private static function texteOuNull(?string $texte): ?string
    {
        $texte = trim((string) $texte);

        return '' === $texte ? null : $texte;
    }
}
