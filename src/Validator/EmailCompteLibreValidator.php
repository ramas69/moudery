<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class EmailCompteLibreValidator extends ConstraintValidator
{
    public function __construct(
        private readonly UtilisateurRepository $utilisateurs,
        private readonly Security $securite,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EmailCompteLibre) {
            throw new UnexpectedTypeException($constraint, EmailCompteLibre::class);
        }
        if (null === $value || '' === trim((string) $value)) {
            return;
        }

        $existant = $this->utilisateurs->trouverParEmail((string) $value);
        if (null === $existant) {
            return;
        }
        if ($constraint->saufMoi) {
            $moi = $this->securite->getUser();
            if ($moi instanceof Utilisateur && $moi->getId() === $existant->getId()) {
                return;
            }
        }

        $this->context->buildViolation($constraint->message)->addViolation();
    }
}
