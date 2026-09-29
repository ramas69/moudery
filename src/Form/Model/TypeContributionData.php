<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\ModeMontant;
use App\Entity\TypeContribution;
use App\Entity\UniteContribution;
use Symfony\Component\Validator\Constraints as Assert;

/** Un type de contribution (F-11) : nom, unité, mode et montant par défaut en euros, taux de reversement (vide = à préciser sur chaque appel). */
final class TypeContributionData
{
    #[Assert\NotBlank(message: 'type_contribution.nom.obligatoire')]
    #[Assert\Length(max: 80, maxMessage: 'type_contribution.nom.trop_long')]
    public ?string $nom = null;

    #[Assert\NotNull(message: 'type_contribution.unite.obligatoire')]
    public ?UniteContribution $unite = UniteContribution::Personne;

    #[Assert\NotNull(message: 'type_contribution.mode.obligatoire')]
    public ?ModeMontant $mode = ModeMontant::Fixe;

    #[Assert\PositiveOrZero(message: 'type_contribution.montant.invalide')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'type_contribution.montant.invalide')]
    public ?float $montantDefaut = null;

    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'type_contribution.taux.invalide')]
    public ?int $tauxReversement = null;

    public static function depuis(TypeContribution $type): self
    {
        $donnees = new self();
        $donnees->nom = $type->getNom();
        $donnees->unite = $type->getUnite();
        $donnees->mode = $type->getMode();
        $donnees->montantDefaut = null !== $type->getMontantDefaut() ? $type->getMontantDefaut() / 100 : null;
        $donnees->tauxReversement = $type->getTauxReversement();

        return $donnees;
    }

    public function montantEnCentimes(): ?int
    {
        return null === $this->montantDefaut ? null : (int) round($this->montantDefaut * 100);
    }
}
