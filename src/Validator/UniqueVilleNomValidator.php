<?php

declare(strict_types=1);

namespace App\Validator;

use App\Form\Model\VilleAdministrationData;
use App\Form\Model\VilleIdentiteData;
use App\Repository\VilleRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class UniqueVilleNomValidator extends ConstraintValidator
{
    public function __construct(private readonly VilleRepository $villes)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueVilleNom) {
            throw new UnexpectedTypeException($constraint, UniqueVilleNom::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof VilleIdentiteData && !$value instanceof VilleAdministrationData) {
            throw new UnexpectedValueException($value, VilleIdentiteData::class.'|'.VilleAdministrationData::class);
        }

        if (null === $value->association || null === $value->nom || '' === trim($value->nom)) {
            return; // NotBlank et NotNull s'en chargent
        }

        if ($this->villes->nomDejaUtilise($value->association, $value->nom, $value->ville)) {
            $this->context->buildViolation($constraint->message)
                ->atPath('nom')
                ->addViolation();
        }
    }
}
