<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Ville;
use Symfony\Component\Validator\Constraints as Assert;

/** Un reversement reçu par le bureau central (F-20) : ville, exercice, montant en euros, date du virement, référence, note. */
final class ReversementData
{
    #[Assert\NotNull(message: 'reversement.ville.obligatoire')]
    public ?Ville $ville = null;

    #[Assert\NotNull(message: 'reversement.exercice.obligatoire')]
    public ?int $exercice = null;

    #[Assert\NotNull(message: 'reversement.montant.obligatoire')]
    #[Assert\Positive(message: 'reversement.montant.obligatoire')]
    #[Assert\LessThanOrEqual(value: 1000000, message: 'reversement.montant.invalide')]
    public ?float $montant = null;

    #[Assert\NotNull(message: 'reversement.date.obligatoire')]
    #[Assert\LessThanOrEqual(value: 'today', message: 'reversement.date.future')]
    public ?\DateTimeImmutable $recuLe = null;

    #[Assert\Length(max: 80, maxMessage: 'reversement.reference.trop_long')]
    public ?string $reference = null;

    #[Assert\Length(max: 255, maxMessage: 'reversement.note.trop_long')]
    public ?string $note = null;
}
