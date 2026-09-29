<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DepenseRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une sortie d'argent (F-21 à F-23) : caisse du bureau central (ville null) ou d'une ville, libellé, montant en centimes,
 * date, catégorie, appel lié facultatif, bénéficiaire, moyen de paiement, justificatif, commentaire pour le valideur.
 * Circuit : brouillon → soumise (justificatif obligatoire) → validée ou refusée (motif obligatoire) → payée. Règle du
 * cahier des charges : une dépense n'est jamais validée par la personne qui l'a saisie. Chaque étape garde qui et quand.
 */
#[ORM\Entity(repositoryClass: DepenseRepository::class)]
#[ORM\Table(name: 'depense')]
#[ORM\UniqueConstraint(name: 'uniq_depense_numero', columns: ['association_id', 'numero'])]
#[ORM\Index(name: 'idx_depense_association_statut', columns: ['association_id', 'statut'])]
class Depense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Association $association;

    /** La caisse : une ville, ou null pour le bureau central. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Ville $ville;

    /** « 2026-039 » : l'année de saisie et un rang dans l'association. */
    #[ORM\Column(length: 12)]
    private string $numero;

    #[ORM\Column(length: 160)]
    private string $libelle = '';

    #[ORM\Column]
    private int $montant = 0;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateDepense;

    #[ORM\Column(length: 20, enumType: CategorieDepense::class)]
    private CategorieDepense $categorie = CategorieDepense::Autre;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AppelContribution $appel = null;

    #[ORM\Column(length: 160)]
    private string $beneficiaire = '';

    #[ORM\Column(length: 12, enumType: MoyenPaiement::class)]
    private MoyenPaiement $moyen = MoyenPaiement::Virement;

    #[ORM\Column(type: 'text')]
    private string $commentaire = '';

    /** Chemin du justificatif sous le dossier des justificatifs, nom d'origine, type et taille. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $justificatif = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $justificatifNom = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $justificatifType = null;

    #[ORM\Column(nullable: true)]
    private ?int $justificatifTaille = null;

    #[ORM\Column(length: 12, enumType: DepenseStatut::class)]
    private DepenseStatut $statut = DepenseStatut::Brouillon;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $saisiePar;

    #[ORM\Column]
    private \DateTimeImmutable $saisieLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $soumiseLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $decisionPar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decisionLe = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $motifRefus = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $payeePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $payeeLe = null;

    public function __construct(Association $association, ?Ville $ville, string $numero, ?Utilisateur $saisiePar, \DateTimeImmutable $quand)
    {
        if (null !== $ville && $ville->getAssociation() !== $association) {
            throw new \InvalidArgumentException('La ville appartient à une autre association.');
        }
        $this->association = $association;
        $this->ville = $ville;
        $this->numero = $numero;
        $this->saisiePar = $saisiePar;
        $this->saisieLe = $quand;
        $this->dateDepense = $quand->setTime(0, 0);
    }

    /** Modifiable en brouillon, et après un refus (la dépense corrigée repasse en brouillon). */
    public function definir(string $libelle, int $montant, \DateTimeImmutable $date, CategorieDepense $categorie, ?AppelContribution $appel, string $beneficiaire, MoyenPaiement $moyen, string $commentaire): void
    {
        if (!$this->estModifiable()) {
            throw new \LogicException('Une dépense soumise, validée ou payée ne se modifie plus.');
        }
        if ($montant <= 0) {
            throw new \InvalidArgumentException('Le montant d\'une dépense est positif.');
        }
        if (null !== $appel && $appel->getAssociation() !== $this->association) {
            throw new \InvalidArgumentException('L\'appel lié appartient à une autre association.');
        }
        $this->libelle = trim($libelle);
        $this->montant = $montant;
        $this->dateDepense = $date->setTime(0, 0);
        $this->categorie = $categorie;
        $this->appel = $appel;
        $this->beneficiaire = trim($beneficiaire);
        $this->moyen = $moyen;
        $this->commentaire = trim($commentaire);
        if (DepenseStatut::Refusee === $this->statut) {
            $this->statut = DepenseStatut::Brouillon;
        }
    }

    public function joindreJustificatif(string $chemin, string $nom, string $type, int $taille): void
    {
        if (!$this->estModifiable()) {
            throw new \LogicException('Le justificatif d\'une dépense soumise ne se remplace plus.');
        }
        $this->justificatif = $chemin;
        $this->justificatifNom = $nom;
        $this->justificatifType = $type;
        $this->justificatifTaille = $taille;
    }

    public function soumettre(\DateTimeImmutable $quand): void
    {
        if (!$this->estModifiable()) {
            throw new \LogicException('Seul un brouillon (ou une dépense refusée) se soumet.');
        }
        if (null === $this->justificatif) {
            throw new \LogicException('Une dépense sans justificatif ne peut pas être soumise.');
        }
        if ($this->montant <= 0 || '' === $this->libelle) {
            throw new \LogicException('Une dépense soumise a un libellé et un montant.');
        }
        $this->statut = DepenseStatut::Soumise;
        $this->soumiseLe = $quand;
        $this->decisionPar = null;
        $this->decisionLe = null;
        $this->motifRefus = null;
    }

    /** Séparation des tâches : jamais la personne qui a saisi la dépense. */
    public function peutEtreValideePar(?Utilisateur $valideur): bool
    {
        return DepenseStatut::Soumise === $this->statut && null !== $valideur && !$this->aEteSaisiePar($valideur);
    }

    public function aEteSaisiePar(?Utilisateur $personne): bool
    {
        return null !== $personne && null !== $this->saisiePar && $this->saisiePar->getId() === $personne->getId();
    }

    public function valider(Utilisateur $valideur, \DateTimeImmutable $quand): void
    {
        if (!$this->peutEtreValideePar($valideur)) {
            throw new \LogicException('Une dépense se valide une fois soumise, et jamais par la personne qui l\'a saisie.');
        }
        $this->statut = DepenseStatut::Validee;
        $this->decisionPar = $valideur;
        $this->decisionLe = $quand;
    }

    public function refuser(Utilisateur $valideur, string $motif, \DateTimeImmutable $quand): void
    {
        if (!$this->peutEtreValideePar($valideur)) {
            throw new \LogicException('Une dépense se refuse une fois soumise, et jamais par la personne qui l\'a saisie.');
        }
        if ('' === trim($motif)) {
            throw new \InvalidArgumentException('Un refus a toujours un motif.');
        }
        $this->statut = DepenseStatut::Refusee;
        $this->decisionPar = $valideur;
        $this->decisionLe = $quand;
        $this->motifRefus = trim($motif);
    }

    public function marquerPayee(Utilisateur $personne, \DateTimeImmutable $quand): void
    {
        if (DepenseStatut::Validee !== $this->statut) {
            throw new \LogicException('Seule une dépense validée peut être marquée payée.');
        }
        $this->statut = DepenseStatut::Payee;
        $this->payeePar = $personne;
        $this->payeeLe = $quand;
    }

    public function estModifiable(): bool
    {
        return \in_array($this->statut, [DepenseStatut::Brouillon, DepenseStatut::Refusee], true);
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

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function getDateDepense(): \DateTimeImmutable
    {
        return $this->dateDepense;
    }

    public function getCategorie(): CategorieDepense
    {
        return $this->categorie;
    }

    public function getAppel(): ?AppelContribution
    {
        return $this->appel;
    }

    public function getBeneficiaire(): string
    {
        return $this->beneficiaire;
    }

    public function getMoyen(): MoyenPaiement
    {
        return $this->moyen;
    }

    public function getCommentaire(): string
    {
        return $this->commentaire;
    }

    public function getJustificatif(): ?string
    {
        return $this->justificatif;
    }

    public function getJustificatifNom(): ?string
    {
        return $this->justificatifNom;
    }

    public function getJustificatifType(): ?string
    {
        return $this->justificatifType;
    }

    public function getJustificatifTaille(): ?int
    {
        return $this->justificatifTaille;
    }

    public function getStatut(): DepenseStatut
    {
        return $this->statut;
    }

    public function getSaisiePar(): ?Utilisateur
    {
        return $this->saisiePar;
    }

    public function getSaisieLe(): \DateTimeImmutable
    {
        return $this->saisieLe;
    }

    public function getSoumiseLe(): ?\DateTimeImmutable
    {
        return $this->soumiseLe;
    }

    public function getDecisionPar(): ?Utilisateur
    {
        return $this->decisionPar;
    }

    public function getDecisionLe(): ?\DateTimeImmutable
    {
        return $this->decisionLe;
    }

    public function getMotifRefus(): ?string
    {
        return $this->motifRefus;
    }

    public function getPayeePar(): ?Utilisateur
    {
        return $this->payeePar;
    }

    public function getPayeeLe(): ?\DateTimeImmutable
    {
        return $this->payeeLe;
    }
}
