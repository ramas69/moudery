<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Utilisateur;
use App\Validator\EmailCompteLibre;
use Symfony\Component\Validator\Constraints as Assert;

/** Paramètres du compte connecté (F-04) : prénom, nom, adresse e-mail (l'identifiant de connexion). */
final class ProfilData
{
    #[Assert\NotBlank(message: 'compte.prenom.obligatoire')]
    #[Assert\Length(max: 80)]
    public ?string $prenom = null;

    #[Assert\NotBlank(message: 'compte.nom.obligatoire')]
    #[Assert\Length(max: 80)]
    public ?string $nom = null;

    #[Assert\NotBlank(message: 'compte.email.obligatoire')]
    #[Assert\Email(message: 'compte.email.invalide')]
    #[Assert\Length(max: 180)]
    #[EmailCompteLibre(saufMoi: true)]
    public ?string $email = null;

    public static function depuis(Utilisateur $compte): self
    {
        $donnees = new self();
        $donnees->prenom = $compte->getPrenom();
        $donnees->nom = $compte->getNom();
        $donnees->email = $compte->getEmail();

        return $donnees;
    }
}
