<?php

declare(strict_types=1);

namespace App\Administration;

use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Form\Model\VilleAdministrationData;
use App\Journal\Journal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Administration des villes par le super-admin, hors assistant : renommage, changement de statut. La création
 * appartient au bureau central, dans l'assistant. Activer une ville depuis ici n'envoie ni invitation ni échéance :
 * c'est le rôle de l'assistant (F-46). Chaque changement de statut laisse une ligne dans le journal (F-38).
 */
final class GestionVilles
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Journal $journal,
    ) {
    }

    /** Le renommage n'est pas consigné ; le statut l'est, seulement quand il change vraiment. */
    public function modifier(Ville $ville, VilleAdministrationData $donnees, ?Utilisateur $par = null): void
    {
        $ville->renommer((string) $donnees->nom);
        if (null !== $donnees->statut && $donnees->statut !== $ville->getStatut()) {
            $ville->changerStatut($donnees->statut);
            $this->journal->consigner(TypeEvenement::VilleStatut, $par, $ville->getAssociation(), $ville->getNom(), ['statut' => $donnees->statut->value]);
        }

        $this->entityManager->flush();
    }

    /** Un changement de statut seul (archiver, réactiver), consigné quand il change vraiment. */
    public function changerStatut(Ville $ville, VilleStatut $statut, ?Utilisateur $par = null): void
    {
        if ($statut === $ville->getStatut()) {
            return;
        }
        $ville->changerStatut($statut);
        $this->journal->consigner(TypeEvenement::VilleStatut, $par, $ville->getAssociation(), $ville->getNom(), ['statut' => $statut->value]);
        $this->entityManager->flush();
    }
}
