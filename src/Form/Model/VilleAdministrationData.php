<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Association;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Validator\UniqueVilleNom;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Création ou modification d'une ville depuis l'administration, hors assistant : association, nom, statut. */
#[UniqueVilleNom]
final class VilleAdministrationData
{
    #[Assert\NotNull(message: 'ville.association.obligatoire')]
    public ?Association $association = null;

    #[Assert\NotBlank(message: 'ville.nom.obligatoire')]
    #[Assert\Length(max: 120, maxMessage: 'ville.nom.trop_long')]
    public ?string $nom = null;

    #[Assert\NotNull(message: 'ville.statut.obligatoire')]
    public ?VilleStatut $statut = VilleStatut::Brouillon;

    /** @param Ville|null $ville la ville modifiée, ou null en création */
    public function __construct(public readonly ?Ville $ville = null)
    {
    }

    public static function depuisVille(Ville $ville): self
    {
        $donnees = new self($ville);
        $donnees->association = $ville->getAssociation();
        $donnees->nom = $ville->getNom();
        $donnees->statut = $ville->getStatut();

        return $donnees;
    }

    /** Le cycle de vie est respecté : brouillon → active → archivée, avec réactivation, jamais de retour en brouillon. */
    #[Assert\Callback]
    public function validerStatut(ExecutionContextInterface $contexte): void
    {
        if (null === $this->ville || null === $this->statut || $this->ville->peutPasserA($this->statut)) {
            return;
        }

        $contexte->buildViolation('ville.statut.transition')
            ->setParameter('{{ actuel }}', $this->ville->getStatut()->value)
            ->setParameter('{{ cible }}', $this->statut->value)
            ->atPath('statut')
            ->addViolation();
    }
}
