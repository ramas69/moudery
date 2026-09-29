<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/** Aucun compte n'existe encore pour cette adresse email. */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class EmailCompteLibre extends Constraint
{
    public string $message = 'compte.email.deja_utilise';

    /** true : l'adresse du compte connecté ne compte pas, pour une personne qui garde sa propre adresse (paramètres). */
    public bool $saufMoi = false;

    #[HasNamedArguments]
    public function __construct(bool $saufMoi = false, ?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
        $this->saufMoi = $saufMoi;
        $this->message = $message ?? $this->message;
    }
}
