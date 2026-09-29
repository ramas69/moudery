<?php

declare(strict_types=1);

namespace App\Validator;

use App\Association\CreationAssociation;
use App\Form\Model\AssociationData;
use App\Form\Model\AssociationModificationData;
use App\Repository\AssociationRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class UniqueAssociationSlugValidator extends ConstraintValidator
{
    public function __construct(private readonly AssociationRepository $associations)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueAssociationSlug) {
            throw new UnexpectedTypeException($constraint, UniqueAssociationSlug::class);
        }
        if (!$value instanceof AssociationData && !$value instanceof AssociationModificationData) {
            throw new UnexpectedValueException($value, AssociationData::class.'|'.AssociationModificationData::class);
        }

        $slug = CreationAssociation::slugPour($value->nom, $value->slug, $value->village);
        if ('' === $slug) {
            return;
        }

        $existante = $this->associations->trouverParSlug($slug);
        if (null === $existante) {
            return;
        }
        // En modification, garder son propre identifiant n'est pas un conflit.
        if ($value instanceof AssociationModificationData && $existante->getId() === $value->association->getId()) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ slug }}', $slug)
            ->atPath('' !== trim((string) $value->slug) ? 'slug' : ('' !== trim((string) $value->village) ? 'village' : 'nom'))
            ->addViolation();
    }
}
