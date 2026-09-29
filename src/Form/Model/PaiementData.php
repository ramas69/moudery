<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Echeance;
use App\Entity\MoyenPaiement;
use Symfony\Component\Validator\Constraints as Assert;

/** Un paiement manuel (F-17) : les échéances soldées, le moyen, la date, la référence et une note. */
final class PaiementData
{
    /** @var list<Echeance> */
    #[Assert\Count(min: 1, minMessage: 'paiement.echeances.obligatoire')]
    public array $echeances = [];

    #[Assert\NotNull(message: 'paiement.moyen.obligatoire')]
    public ?MoyenPaiement $moyen = MoyenPaiement::Especes;

    #[Assert\NotNull(message: 'paiement.date.obligatoire')]
    #[Assert\LessThanOrEqual(value: 'today', message: 'paiement.date.future')]
    public ?\DateTimeImmutable $recuLe = null;

    #[Assert\Length(max: 80, maxMessage: 'paiement.reference.trop_long')]
    public ?string $reference = null;

    #[Assert\Length(max: 255, maxMessage: 'paiement.note.trop_long')]
    public ?string $note = null;
}
