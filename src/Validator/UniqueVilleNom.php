<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** Le nom d'une ville est unique dans son association (F-40), sans tenir compte de la casse. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueVilleNom extends Constraint
{
    public string $message = 'ville.nom.deja_utilise';

    public function __construct(?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(groups: $groups, payload: $payload);

        $this->message = $message ?? $this->message;
    }

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
