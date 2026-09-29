<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Association;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Les paramètres de gestion que le bureau central règle lui-même : contact, exercice, reversement par défaut,
 * calendrier des relances. Le nom, l'identifiant et l'existence juridique restent au super-admin.
 */
final class ParametresAssociationData
{
    #[Assert\Email(message: 'compte.email.invalide')]
    #[Assert\Length(max: 180)]
    public ?string $emailContact = null;

    #[Assert\Length(max: 30)]
    #[Assert\Regex(pattern: '/^[0-9+][0-9 .()-]*$/', message: 'association.telephone.invalide')]
    public ?string $telephoneContact = null;

    #[Assert\Length(max: 500)]
    public ?string $adresseSiege = null;

    #[Assert\NotNull(message: 'association.exercice.invalide')]
    #[Assert\Range(min: 1, max: 12, notInRangeMessage: 'association.exercice.invalide')]
    public ?int $debutExerciceMois = 1;

    #[Assert\NotNull(message: 'association.premier_exercice.invalide')]
    #[Assert\Range(min: 2000, max: 2100, notInRangeMessage: 'association.premier_exercice.invalide')]
    public ?int $premierExercice = Association::PREMIER_EXERCICE_PAR_DEFAUT;

    #[Assert\NotNull(message: 'association.taux.invalide')]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'association.taux.invalide')]
    public ?int $tauxReversementDefaut = 0;

    /** Jours par rapport à l'échéance, séparés par des virgules : « -7, 0, 15 ». */
    #[Assert\NotBlank(message: 'association.relances.obligatoire')]
    #[Assert\Regex(pattern: '/^\s*-?\d{1,3}(\s*,\s*-?\d{1,3})*\s*$/', message: 'association.relances.invalide')]
    public ?string $calendrierRelances = '-7, 0, 15';

    public static function depuis(Association $association): self
    {
        $donnees = new self();
        $donnees->emailContact = $association->getEmailContact();
        $donnees->telephoneContact = $association->getTelephoneContact();
        $donnees->adresseSiege = $association->getAdresseSiege();
        $donnees->debutExerciceMois = $association->getDebutExerciceMois();
        $donnees->premierExercice = $association->getPremierExercice();
        $donnees->tauxReversementDefaut = $association->getTauxReversementDefaut();
        $donnees->calendrierRelances = implode(', ', $association->getCalendrierRelances());

        return $donnees;
    }

    /** @return list<int> */
    public function relancesEnJours(): array
    {
        return array_map('intval', array_filter(array_map('trim', explode(',', (string) $this->calendrierRelances)), static fn (string $j): bool => '' !== $j));
    }
}
