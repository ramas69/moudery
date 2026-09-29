<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Abonnement;
use App\Entity\AbonnementStatut;
use App\Entity\Periodicite;
use Symfony\Component\Validator\Constraints as Assert;

/** Abonnement d'une association, renseigné par le super-admin : montant saisi en euros. */
final class AbonnementData
{
    #[Assert\NotBlank(message: 'abonnement.formule.obligatoire')]
    #[Assert\Length(max: 60, maxMessage: 'abonnement.formule.trop_long')]
    public ?string $formule = null;

    #[Assert\NotNull(message: 'abonnement.statut.obligatoire')]
    public ?AbonnementStatut $statut = null;

    #[Assert\NotNull(message: 'abonnement.montant.obligatoire')]
    #[Assert\PositiveOrZero(message: 'abonnement.montant.negatif')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'abonnement.montant.trop_eleve')]
    public ?float $montant = 0.0;

    #[Assert\NotNull(message: 'abonnement.periodicite.obligatoire')]
    public ?Periodicite $periodicite = null;

    #[Assert\NotNull(message: 'abonnement.debut.obligatoire')]
    public ?\DateTimeImmutable $debutLe = null;

    #[Assert\GreaterThanOrEqual(propertyPath: 'debutLe', message: 'abonnement.echeance.avant_debut')]
    public ?\DateTimeImmutable $prochaineEcheanceLe = null;

    #[Assert\Length(max: 1000)]
    public ?string $notes = null;

    public static function depuis(Abonnement $abonnement): self
    {
        $donnees = new self();
        $donnees->formule = $abonnement->getFormule();
        $donnees->statut = $abonnement->getStatut();
        $donnees->montant = $abonnement->getMontant() / 100;
        $donnees->periodicite = $abonnement->getPeriodicite();
        $donnees->debutLe = $abonnement->getDebutLe();
        $donnees->prochaineEcheanceLe = $abonnement->getProchaineEcheanceLe();
        $donnees->notes = $abonnement->getNotes();

        return $donnees;
    }
}
