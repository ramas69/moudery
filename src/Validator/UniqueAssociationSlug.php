<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** Le slug d'une association est unique sur la plateforme. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueAssociationSlug extends Constraint
{
    public string $message = 'association.slug.deja_utilise';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
