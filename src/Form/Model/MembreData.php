<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Foyer;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Ajout d'un membre à la main dans l'assistant (F-42) : identité, contact, foyer existant ou nouveau. */
final class MembreData
{
    #[Assert\NotBlank(message: 'membre.prenom.obligatoire')]
    #[Assert\Length(max: 80, maxMessage: 'membre.prenom.trop_long')]
    public ?string $prenom = null;

    #[Assert\NotBlank(message: 'membre.nom.obligatoire')]
    #[Assert\Length(max: 80, maxMessage: 'membre.nom.trop_long')]
    public ?string $nom = null;

    #[Assert\Email(message: 'membre.email.invalide')]
    #[Assert\Length(max: 180, maxMessage: 'membre.email.trop_long')]
    public ?string $email = null;

    #[Assert\Length(max: 30, maxMessage: 'membre.telephone.trop_long')]
    public ?string $telephone = null;

    /** La localité à l'intérieur de la ville, quand la caisse en regroupe plusieurs. */
    #[Assert\Length(max: 80, maxMessage: 'membre.localite.trop_long')]
    public ?string $localite = null;

    public ?int $anneeNaissance = null;

    public ?Foyer $foyer = null;

    #[Assert\Length(max: 120, maxMessage: 'membre.foyer.trop_long')]
    public ?string $nouveauFoyer = null;

    public static function depuis(\App\Entity\Membre $membre): self
    {
        $donnees = new self();
        $donnees->prenom = $membre->getPrenom();
        $donnees->nom = $membre->getNom();
        $donnees->email = $membre->getEmail();
        $donnees->telephone = $membre->getTelephoneAffiche();
        $donnees->localite = $membre->getLocalite();
        $donnees->anneeNaissance = $membre->getAnneeNaissance();
        $donnees->foyer = $membre->getFoyer();

        return $donnees;
    }

    #[Assert\Callback]
    public function validerAnneeNaissance(ExecutionContextInterface $contexte): void
    {
        if (null !== $this->anneeNaissance && ($this->anneeNaissance < 1900 || $this->anneeNaissance > (int) date('Y'))) {
            $contexte->buildViolation('membre.annee_naissance.invalide')->atPath('anneeNaissance')->addViolation();
        }
    }
}
