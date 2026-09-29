<?php

declare(strict_types=1);

namespace App\Administration;

use App\Association\CreationAssociation;
use App\Entity\Association;
use App\Entity\AssociationStatut;
use App\Form\Model\AssociationModificationData;
use App\Repository\InvitationRepository;
use App\Repository\UtilisateurRepository;
use App\Repository\VilleRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Administration des associations par le super-admin : modification, statut et suppression d'un tenant. */
final class GestionAssociations
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VilleRepository $villes,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly InvitationRepository $invitations,
    ) {
    }

    public function modifier(Association $association, AssociationModificationData $donnees): void
    {
        $association->renommer((string) $donnees->nom);
        $association->changerSlug(CreationAssociation::slugPour($donnees->nom, $donnees->slug));
        $association->definirVillage($donnees->village);
        $association->definirContact($donnees->emailContact, $donnees->telephoneContact, $donnees->adresseSiege);
        $association->definirIdentifiantsLegaux($donnees->numeroRna, $donnees->siren);
        $association->definirParametres((int) $donnees->debutExerciceMois, (int) $donnees->tauxReversementDefaut, $donnees->relancesEnJours());
        $association->definirPremierExercice((int) $donnees->premierExercice);

        $this->entityManager->flush();
    }

    /** Suspendre ferme l'accès aux comptes de l'association, archiver la clôt ; réactiver rouvre. Rien n'est supprimé. */
    public function changerStatut(Association $association, AssociationStatut $statut): void
    {
        $association->changerStatut($statut);

        $this->entityManager->flush();
    }

    /** Une association ne se supprime que vide : sans ville ni compte. Une ville ne se supprime jamais, elle s'archive. */
    public function peutSupprimer(Association $association): bool
    {
        return 0 === $this->villes->count(['association' => $association])
            && 0 === $this->utilisateurs->count(['association' => $association]);
    }

    /** Ses invitations en attente disparaissent avec elle. */
    public function supprimer(Association $association): void
    {
        if (!$this->peutSupprimer($association)) {
            throw new \LogicException(\sprintf('L\'association « %s » contient encore des villes ou des comptes.', $association->getNom()));
        }

        $this->invitations->supprimerPourAssociation($association);
        $this->entityManager->remove($association);
        $this->entityManager->flush();
    }
}
