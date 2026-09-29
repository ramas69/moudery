<?php

declare(strict_types=1);

namespace App\Ville;

use App\Compte\Invitations;
use App\Entity\EtapeAssistant;
use App\Entity\RoleVille;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Form\Model\VilleIdentiteData;
use App\Journal\Journal;
use App\Repository\UtilisateurRepository;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Assistant de création d'une ville (M2 bis), en trois étapes : identité, membres, activation.
 * Chaque étape est enregistrée, on peut s'arrêter et reprendre plus tard.
 */
final class AssistantVille
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Journal $journal,
        private readonly Security $securite,
        private readonly Invitations $invitations,
        private readonly UtilisateurRepository $utilisateurs,
    ) {
    }

    /**
     * Étape 1, Identité (F-40) : crée la ville en brouillon ou la renomme,
     * et met à jour les responsables à inviter. Aucun email ne part avant l'activation (F-46).
     */
    public function enregistrerIdentite(VilleIdentiteData $donnees): Ville
    {
        $nom = (string) $donnees->nom;
        $nouvelle = null === $donnees->ville;
        $ville = $donnees->ville ?? new Ville($donnees->association, $nom);
        $ville->renommer($nom);

        foreach (RoleVille::cases() as $role) {
            $ville->definirResponsable($role, $donnees->emailPour($role));
        }

        $ville->avancerA(EtapeAssistant::Membres);

        $this->entityManager->persist($ville);
        if ($nouvelle) {
            $this->journal->consigner(TypeEvenement::VilleCreee, $this->acteur(), $donnees->association, $nom);
        }
        $this->entityManager->flush();

        return $ville;
    }

    /** Un trésorier renseigné suffit pour activer une ville en brouillon (décision du 27 septembre 2026). */
    public function peutActiver(Ville $ville): bool
    {
        return $ville->estBrouillon() && null !== $ville->invitationPour(RoleVille::Tresorier);
    }

    /**
     * Étape 3, Activation (F-46) : la ville devient visible et ses responsables sont invités. Une personne qui a déjà
     * un compte dans l'association reçoit son rôle tout de suite, sans email ; les autres reçoivent un lien valable
     * 7 jours (F-02). Le compte bancaire, les cotisations, les projets et l'historique se règlent ensuite, depuis
     * l'espace de la ville.
     *
     * @return int le nombre d'invitations envoyées
     */
    public function activer(Ville $ville): int
    {
        if (!$this->peutActiver($ville)) {
            throw new \LogicException(\sprintf('La ville « %s » ne peut pas être activée : elle n\'est pas en brouillon ou n\'a pas de trésorier.', $ville->getNom()));
        }

        $par = $this->acteur();
        $quand = new \DateTimeImmutable();
        $association = $ville->getAssociation();
        $envoyees = 0;
        $rattaches = 0;

        $ville->avancerA(EtapeAssistant::Activation);
        $ville->changerStatut(VilleStatut::Active);

        foreach ($ville->getInvitations() as $responsable) {
            if (null !== $responsable->getAccepteeLe()) {
                continue;
            }
            $role = Role::depuisRoleVille($responsable->getRole());
            $compte = $this->utilisateurs->trouverParEmail($responsable->getEmail());

            if (null !== $compte && $compte->getAssociation()?->getId() === $association->getId()) {
                $compte->affecter($role, $ville);
                $responsable->marquerAcceptee($quand);
                ++$rattaches;
                continue;
            }
            if (null !== $compte) {
                // Un compte d'une autre association ou de la plateforme : rien ne se fait sans l'administration.
                $this->journal->consigner(TypeEvenement::VilleStatut, $par, $association, $ville->getNom(), ['responsable_ignore' => $responsable->getEmail(), 'role' => $role->value, 'raison' => 'compte hors association']);
                continue;
            }

            $this->invitations->inviter($association, $role, $responsable->getEmail(), $par, $ville);
            $responsable->marquerEnvoyee($quand);
            ++$envoyees;
        }

        $this->journal->consigner(TypeEvenement::VilleStatut, $par, $association, $ville->getNom(), ['statut' => VilleStatut::Active->value, 'invitations' => $envoyees, 'rattaches' => $rattaches], $quand);
        $this->entityManager->flush();

        return $envoyees;
    }

    private function acteur(): ?Utilisateur
    {
        $acteur = $this->securite->getUser();

        return $acteur instanceof Utilisateur ? $acteur : null;
    }
}
