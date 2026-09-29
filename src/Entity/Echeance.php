<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EcheanceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce qu'un membre doit (cahier des charges : débiteur, cotisation ou appel, montant, statut) : soit pour un appel à
 * contribution, soit une mensualité d'une cotisation périodique (F-12 : mois et année civils). Pour une contribution
 * par foyer, seul le payeur a une échéance, qui vaut pour tout le foyer. Un membre n'a jamais deux échéances pour le
 * même appel, ni deux pour le même mois d'une cotisation. Montant null : montant libre, le membre choisit en payant.
 * Une échéance payée renvoie au paiement qui l'a soldée ; un mois versé avant l'arrivée de l'application (classeur
 * importé) est payé sans paiement.
 */
#[ORM\Entity(repositoryClass: EcheanceRepository::class)]
#[ORM\Table(name: 'echeance')]
#[ORM\UniqueConstraint(name: 'uniq_echeance_appel_membre', columns: ['appel_id', 'membre_id'])]
#[ORM\UniqueConstraint(name: 'uniq_echeance_cotisation_membre_mois', columns: ['cotisation_id', 'membre_id', 'mois'])]
#[ORM\Index(name: 'idx_echeance_association_statut_date', columns: ['association_id', 'statut', 'date_limite'])]
class Echeance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Association $association;

    #[ORM\ManyToOne(inversedBy: 'echeances')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?AppelContribution $appel = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Cotisation $cotisation = null;

    /** Mois et année civils d'une mensualité de cotisation ; null pour un appel. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $mois = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $annee = null;

    #[ORM\ManyToOne(inversedBy: 'echeances')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Paiement $paiement = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $payeeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Membre $membre;

    #[ORM\Column(nullable: true)]
    private ?int $montant;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateLimite;

    #[ORM\Column(length: 12, enumType: EcheanceStatut::class)]
    private EcheanceStatut $statut = EcheanceStatut::Due;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(?AppelContribution $appel, Membre $membre, \DateTimeImmutable $quand)
    {
        $this->association = $membre->getAssociation();
        $this->membre = $membre;
        $this->creeLe = $quand;
        if (null === $appel) {
            $this->montant = null;
            $this->dateLimite = $quand;

            return;
        }
        if ($membre->getAssociation() !== $appel->getAssociation()) {
            throw new \InvalidArgumentException('Le membre appartient à une autre association que l\'appel.');
        }
        $this->appel = $appel;
        $this->montant = $appel->montantEcheance();
        $this->dateLimite = $appel->getDateLimite();
    }

    /** La mensualité d'une cotisation périodique pour un membre : mois civil, année civile, tarif du moment. */
    public static function pourCotisation(Cotisation $cotisation, Membre $membre, int $mois, int $anneeCivile, \DateTimeImmutable $quand): self
    {
        if ($membre->getAssociation() !== $cotisation->getAssociation()) {
            throw new \InvalidArgumentException('Le membre appartient à une autre association que la cotisation.');
        }
        if ($mois < 1 || $mois > 12) {
            throw new \InvalidArgumentException('Le mois va de 1 à 12.');
        }
        $echeance = new self(null, $membre, $quand);
        $echeance->cotisation = $cotisation;
        $echeance->mois = $mois;
        $echeance->annee = $anneeCivile;
        $echeance->montant = $cotisation->getMontantMensuel();
        $echeance->dateLimite = $cotisation->dateLimitePour($mois, $anneeCivile);

        return $echeance;
    }

    /**
     * Soldée par un paiement (manuel ou en ligne), ou sans paiement pour un mois versé avant l'application. Le montant
     * réellement payé remplace un montant libre ou un tarif qui a changé entre-temps.
     */
    public function payer(?Paiement $paiement, \DateTimeImmutable $quand, ?int $montantPaye = null): void
    {
        if (EcheanceStatut::Payee === $this->statut) {
            throw new \LogicException('Cette échéance est déjà payée.');
        }
        if (null !== $montantPaye) {
            if ($montantPaye <= 0) {
                throw new \InvalidArgumentException('Le montant payé est strictement positif.');
            }
            $this->montant = $montantPaye;
        } elseif (null === $this->montant) {
            throw new \LogicException('Une échéance à montant libre se paie avec un montant.');
        }
        $this->statut = EcheanceStatut::Payee;
        $this->paiement = $paiement;
        $this->payeeLe = $quand;
    }

    /**
     * Le paiement est annulé : l'échéance redevient due. Elle garde le lien vers ce paiement annulé (l'historique du
     * paiement reste lisible) jusqu'à ce qu'un nouveau paiement la solde.
     */
    public function remettreDue(): void
    {
        $this->statut = EcheanceStatut::Due;
        $this->payeeLe = null;
    }

    public function annuler(): void
    {
        if (EcheanceStatut::Payee === $this->statut) {
            throw new \LogicException('Une échéance payée ne s\'annule pas : annulez le paiement.');
        }
        $this->statut = EcheanceStatut::Annulee;
    }

    /** Le tarif change en cours d'année : seules les mensualités encore dues suivent. */
    public function actualiserMontant(int $montant): void
    {
        if (EcheanceStatut::Due === $this->statut) {
            $this->montant = $montant;
        }
    }

    /** Le jour d'échéance de la cotisation change : une mensualité due prend la nouvelle date limite. */
    public function reporterA(\DateTimeImmutable $dateLimite): void
    {
        if (EcheanceStatut::Due === $this->statut) {
            $this->dateLimite = $dateLimite;
        }
    }

    public function estDue(): bool
    {
        return EcheanceStatut::Due === $this->statut;
    }

    public function estPayee(): bool
    {
        return EcheanceStatut::Payee === $this->statut;
    }

    public function estEnRetard(\DateTimeImmutable $aujourdhui): bool
    {
        return $this->estDue() && $this->dateLimite < $aujourdhui->setTime(0, 0);
    }

    public function getCotisation(): ?Cotisation
    {
        return $this->cotisation;
    }

    public function getMois(): ?int
    {
        return $this->mois;
    }

    public function getAnnee(): ?int
    {
        return $this->annee;
    }

    public function getPaiement(): ?Paiement
    {
        return $this->paiement;
    }

    public function getPayeeLe(): ?\DateTimeImmutable
    {
        return $this->payeeLe;
    }

    public function getAssociation(): Association
    {
        return $this->association;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAppel(): ?AppelContribution
    {
        return $this->appel;
    }

    public function getMembre(): Membre
    {
        return $this->membre;
    }

    public function getMontant(): ?int
    {
        return $this->montant;
    }

    public function getDateLimite(): \DateTimeImmutable
    {
        return $this->dateLimite;
    }

    public function getStatut(): EcheanceStatut
    {
        return $this->statut;
    }
}
