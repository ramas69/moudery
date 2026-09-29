<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\CategorieDepense;
use App\Entity\Depense;
use App\Entity\MoyenPaiement;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/** Saisie d'une dépense (F-21) : libellé, montant, date, catégorie, appel lié, bénéficiaire, moyen, justificatif, commentaire. */
final class DepenseData
{
    #[Assert\NotBlank(message: 'depense.libelle.obligatoire')]
    #[Assert\Length(max: 160, maxMessage: 'depense.libelle.trop_long')]
    public ?string $libelle = null;

    #[Assert\NotNull(message: 'depense.montant.obligatoire')]
    #[Assert\Positive(message: 'depense.montant.obligatoire')]
    #[Assert\LessThanOrEqual(value: 1000000, message: 'depense.montant.invalide')]
    public ?float $montant = null;

    #[Assert\NotNull(message: 'depense.date.obligatoire')]
    #[Assert\LessThanOrEqual(value: 'today', message: 'depense.date.future')]
    public ?\DateTimeImmutable $date = null;

    public CategorieDepense $categorie = CategorieDepense::Autre;

    /** Identifiant d'un appel de l'association, ou vide. */
    public ?string $appel = null;

    #[Assert\NotBlank(message: 'depense.beneficiaire.obligatoire')]
    #[Assert\Length(max: 160, maxMessage: 'depense.beneficiaire.trop_long')]
    public ?string $beneficiaire = null;

    public MoyenPaiement $moyen = MoyenPaiement::Virement;

    #[Assert\File(maxSize: '10M', mimeTypes: ['application/pdf', 'image/jpeg', 'image/png'], maxSizeMessage: 'depense.justificatif.trop_lourd', mimeTypesMessage: 'depense.justificatif.format')]
    public ?UploadedFile $justificatif = null;

    #[Assert\Length(max: 2000, maxMessage: 'depense.commentaire.trop_long')]
    public ?string $commentaire = null;

    public static function depuis(Depense $depense): self
    {
        $donnees = new self();
        $donnees->libelle = $depense->getLibelle();
        $donnees->montant = $depense->getMontant() > 0 ? $depense->getMontant() / 100 : null;
        $donnees->date = $depense->getDateDepense();
        $donnees->categorie = $depense->getCategorie();
        $donnees->appel = null !== $depense->getAppel() ? (string) $depense->getAppel()->getId() : null;
        $donnees->beneficiaire = $depense->getBeneficiaire();
        $donnees->moyen = $depense->getMoyen();
        $donnees->commentaire = $depense->getCommentaire();

        return $donnees;
    }

    public function montantEnCentimes(): int
    {
        return (int) round(($this->montant ?? 0) * 100);
    }
}
