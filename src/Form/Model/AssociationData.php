<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Validator\EmailCompteLibre;
use App\Validator\UniqueAssociationSlug;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Création d'une association, le tenant (section 2 du cahier des charges : super-admin plateforme),
 * avec l'invitation de son premier bureau central (F-02).
 */
#[UniqueAssociationSlug]
final class AssociationData
{
    #[Assert\NotBlank(message: 'association.nom.obligatoire')]
    #[Assert\Length(max: 120, maxMessage: 'association.nom.trop_long')]
    public ?string $nom = null;

    /** Identifiant dans les URL ; déduit du village, ou du nom, s'il est vide. */
    #[Assert\Length(max: 80, maxMessage: 'association.slug.trop_long')]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'association.slug.invalide')]
    public ?string $slug = null;

    /** Nom du village, affiché sous le logo et source de l'identifiant ; le nom de l'association à défaut. */
    #[Assert\Length(max: 80, maxMessage: 'association.village.trop_long')]
    public ?string $village = null;

    /** La personne invitée à administrer l'association ; elle choisira son mot de passe depuis l'email reçu. */
    #[Assert\NotBlank(message: 'association.bureau_central.obligatoire')]
    #[Assert\Email(message: 'compte.email.invalide')]
    #[Assert\Length(max: 180)]
    #[EmailCompteLibre]
    public ?string $emailBureauCentral = null;
}
