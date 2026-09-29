<?php

declare(strict_types=1);

namespace App\Compte;

use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Form\Model\ProfilData;
use App\Journal\Journal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Paramètres du compte connecté (F-04) : la personne modifie sa propre identité, son adresse et son mot de passe.
 * Chaque changement est consigné au journal, avec elle-même pour acteur.
 */
final class Parametres
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Journal $journal,
        private readonly UserPasswordHasherInterface $hacheur,
    ) {
    }

    public function modifierProfil(Utilisateur $compte, ProfilData $donnees): void
    {
        $avant = ['prenom' => $compte->getPrenom(), 'nom' => $compte->getNom(), 'email' => $compte->getEmail()];
        $compte->modifierIdentite((string) $donnees->prenom, (string) $donnees->nom);
        $compte->changerEmail((string) $donnees->email);
        $apres = ['prenom' => $compte->getPrenom(), 'nom' => $compte->getNom(), 'email' => $compte->getEmail()];

        $this->journal->consigner(TypeEvenement::CompteModifie, $compte, $compte->getAssociation(), $compte->getEmail(), ['avant' => $avant, 'apres' => $apres, 'par' => 'la personne elle-même']);
        $this->entityManager->flush();
    }

    /**
     * Le nouveau mot de passe remplace l'ancien ; un lien de réinitialisation en attente devient inutile.
     * La session reste ouverte : l'objet en session est celui qui vient d'être modifié, Symfony n'y voit aucune différence.
     */
    public function changerMotDePasse(Utilisateur $compte, string $nouveau): void
    {
        $compte->definirMotDePasse($this->hacheur->hashPassword($compte, $nouveau));
        $compte->terminerReinitialisation();

        $this->journal->consigner(TypeEvenement::CompteMotDePasseChange, $compte, $compte->getAssociation(), $compte->getEmail());
        $this->entityManager->flush();
    }
}
