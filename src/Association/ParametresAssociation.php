<?php

declare(strict_types=1);

namespace App\Association;

use App\Entity\Association;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Form\Model\ParametresAssociationData;
use App\Journal\Journal;
use Doctrine\ORM\EntityManagerInterface;

/** Le bureau central règle les paramètres de gestion de son association ; chaque changement est consigné avec l'avant et l'après. */
final class ParametresAssociation
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Journal $journal,
    ) {
    }

    public function modifier(Association $association, ParametresAssociationData $donnees, ?Utilisateur $acteur): void
    {
        $avant = self::instantane($association);
        $association->definirContact($donnees->emailContact, $donnees->telephoneContact, $donnees->adresseSiege);
        $association->definirParametres((int) $donnees->debutExerciceMois, (int) $donnees->tauxReversementDefaut, $donnees->relancesEnJours());
        $association->definirPremierExercice((int) $donnees->premierExercice);
        $apres = self::instantane($association);

        if ($avant !== $apres) {
            $this->journal->consigner(TypeEvenement::AssociationParametres, $acteur, $association, $association->getNom(), ['avant' => $avant, 'apres' => $apres]);
        }
        $this->entityManager->flush();
    }

    /** @return array<string, mixed> */
    private static function instantane(Association $association): array
    {
        return [
            'email_contact' => $association->getEmailContact(),
            'telephone_contact' => $association->getTelephoneContact(),
            'adresse_siege' => $association->getAdresseSiege(),
            'debut_exercice_mois' => $association->getDebutExerciceMois(),
            'premier_exercice' => $association->getPremierExercice(),
            'taux_reversement_defaut' => $association->getTauxReversementDefaut(),
            'calendrier_relances' => $association->getCalendrierRelances(),
        ];
    }
}
