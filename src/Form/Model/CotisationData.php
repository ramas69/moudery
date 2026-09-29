<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Cotisation;
use Symfony\Component\Validator\Constraints as Assert;

/** Ouverture ou modification de la cotisation d'une ville pour un exercice (F-12) : type, tarif mensuel en euros, jour d'échéance. */
final class CotisationData
{
    /** Le code du type de contribution (au choix parmi les types actifs de l'association). */
    #[Assert\NotBlank(message: 'cotisation.type.obligatoire')]
    public ?string $type = null;

    #[Assert\NotNull(message: 'cotisation.montant.obligatoire')]
    #[Assert\Positive(message: 'cotisation.montant.obligatoire')]
    #[Assert\LessThanOrEqual(value: 10000, message: 'cotisation.montant.invalide')]
    public ?float $montantMensuel = null;

    #[Assert\NotNull(message: 'cotisation.jour.invalide')]
    #[Assert\Range(min: 1, max: 28, notInRangeMessage: 'cotisation.jour.invalide')]
    public ?int $jourEcheance = Cotisation::JOUR_ECHEANCE_DEFAUT;

    public static function depuis(Cotisation $cotisation): self
    {
        $donnees = new self();
        $donnees->type = $cotisation->getType()->getCode();
        $donnees->montantMensuel = $cotisation->getMontantMensuel() / 100;
        $donnees->jourEcheance = $cotisation->getJourEcheance();

        return $donnees;
    }

    public function montantEnCentimes(): int
    {
        return (int) round((float) $this->montantMensuel * 100);
    }
}
