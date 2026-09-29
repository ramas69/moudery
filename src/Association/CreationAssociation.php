<?php

declare(strict_types=1);

namespace App\Association;

use App\Compte\Invitations;
use App\Entity\Association;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Form\Model\AssociationData;
use App\Journal\Journal;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Crée une association, le tenant, et invite son premier bureau central par email (F-02).
 * Le slug, identifiant dans les URL, se déduit du village, ou du nom, s'il n'est pas donné.
 */
final class CreationAssociation
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Invitations $invitations,
        private readonly Journal $journal,
    ) {
    }

    /** @param Utilisateur|null $par le compte qui crée l'association, nommé dans l'invitation */
    public function creer(AssociationData $donnees, ?Utilisateur $par = null): Association
    {
        $association = new Association((string) $donnees->nom, self::slugPour($donnees->nom, $donnees->slug, $donnees->village));
        $association->definirVillage($donnees->village);
        $abonnement = $association->ouvrirAbonnement();

        $this->entityManager->persist($association);
        $this->journal->consigner(TypeEvenement::AssociationCreee, $par, $association, $association->getNom());
        $this->journal->consigner(TypeEvenement::AbonnementInitial, $par, $association, null, Journal::instantane($abonnement));
        $this->entityManager->flush();

        if (null !== Utilisateur::normaliserEmail($donnees->emailBureauCentral)) {
            $this->invitations->inviter($association, Role::BureauCentral, (string) $donnees->emailBureauCentral, $par);
        }

        return $association;
    }

    /**
     * L'identifiant dans les adresses : celui qui est saisi, en minuscules ; sinon le village (« Moudéry » donne
     * « moudery ») ; sinon le nom (« Association de Moudery » donne « association-de-moudery »).
     */
    public static function slugPour(?string $nom, ?string $slug, ?string $village = null): string
    {
        $slug = mb_strtolower(trim((string) $slug));
        if ('' !== $slug) {
            return $slug;
        }
        $source = '' !== trim((string) $village) ? $village : $nom;

        return (new AsciiSlugger('fr'))->slug(trim((string) $source))->lower()->toString();
    }
}
