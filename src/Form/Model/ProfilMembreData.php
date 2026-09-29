<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Membre;
use Symfony\Component\Validator\Constraints as Assert;

/** Le profil du membre (F-04) : téléphone et accord pour recevoir les e-mails de l'association. */
final class ProfilMembreData
{
    #[Assert\Length(max: 30, maxMessage: 'membre.telephone.trop_long')]
    #[Assert\Regex(pattern: '/^[\d\s+().-]*$/', message: 'membre.telephone.invalide')]
    public ?string $telephone = null;

    public bool $consentEmail = true;

    public static function depuis(Membre $membre): self
    {
        $donnees = new self();
        $donnees->telephone = $membre->getTelephoneAffiche() ?? $membre->getTelephone();
        $donnees->consentEmail = $membre->aConsentiEmail();

        return $donnees;
    }
}
