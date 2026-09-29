<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Association;
use App\Validator\UniqueAssociationSlug;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Modification d'une association par le super-admin : nom et identifiant, identité (village, contact,
 * existence juridique) et paramètres de gestion (exercice, reversement par défaut, relances).
 */
#[UniqueAssociationSlug]
final class AssociationModificationData
{
    #[Assert\NotBlank(message: 'association.nom.obligatoire')]
    #[Assert\Length(max: 120, maxMessage: 'association.nom.trop_long')]
    public ?string $nom = null;

    /** Déduit du nom s'il est vide. */
    #[Assert\Length(max: 80, maxMessage: 'association.slug.trop_long')]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'association.slug.invalide')]
    public ?string $slug = null;

    #[Assert\Length(max: 80, maxMessage: 'association.village.trop_long')]
    public ?string $village = null;

    #[Assert\Email(message: 'compte.email.invalide')]
    #[Assert\Length(max: 180)]
    public ?string $emailContact = null;

    #[Assert\Length(max: 30)]
    #[Assert\Regex(pattern: '/^[0-9+][0-9 .()-]*$/', message: 'association.telephone.invalide')]
    public ?string $telephoneContact = null;

    #[Assert\Length(max: 500)]
    public ?string $adresseSiege = null;

    #[Assert\Regex(pattern: '/^\s*[Ww]\s*\d{3}\s*\d{3}\s*\d{3}\s*$/', message: 'association.rna.invalide')]
    public ?string $numeroRna = null;

    #[Assert\Regex(pattern: '/^\s*\d{3}\s*\d{3}\s*\d{3}\s*$/', message: 'association.siren.invalide')]
    public ?string $siren = null;

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

    public function __construct(public readonly Association $association)
    {
    }

    public static function depuis(Association $association): self
    {
        $donnees = new self($association);
        $donnees->nom = $association->getNom();
        $donnees->slug = $association->getSlug();
        $donnees->village = $association->getVillage();
        $donnees->emailContact = $association->getEmailContact();
        $donnees->telephoneContact = $association->getTelephoneContact();
        $donnees->adresseSiege = $association->getAdresseSiege();
        $donnees->numeroRna = $association->getNumeroRna();
        $donnees->siren = $association->getSiren();
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
