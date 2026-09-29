<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\AppelContribution;
use App\Entity\ModeMontant;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Le formulaire « Nouvel appel à contribution » (maquette 04) : type, objet, montant, mode, date limite, périmètre, taux, message, relances. */
final class AppelData
{
    public const string TOUTES = 'toutes';

    #[Assert\NotBlank(message: 'appel.type.obligatoire')]
    public ?string $type = null;

    #[Assert\NotBlank(message: 'appel.objet.obligatoire')]
    #[Assert\Length(max: 160, maxMessage: 'appel.objet.trop_long')]
    public ?string $objet = null;

    /** En euros, deux décimales. Obligatoire pour un montant fixe, facultatif (suggéré) pour un montant libre. */
    #[Assert\PositiveOrZero(message: 'appel.montant.invalide')]
    #[Assert\LessThanOrEqual(value: 100000, message: 'appel.montant.invalide')]
    public ?float $montant = null;

    public ModeMontant $mode = ModeMontant::Fixe;

    #[Assert\NotNull(message: 'appel.date_limite.obligatoire')]
    public ?\DateTimeImmutable $dateLimite = null;

    public string $perimetre = self::TOUTES;

    /** Utilisé seulement quand le type n'a pas de taux fixé (« à préciser »). */
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'appel.taux.invalide')]
    public ?int $taux = null;

    #[Assert\Length(max: 4000, maxMessage: 'appel.message.trop_long')]
    public ?string $message = null;

    public bool $relancesAuto = true;

    /** Le type choisi n'a pas de taux fixé : l'appel doit le préciser (renseigné par le contrôleur). */
    public bool $tauxAPreciser = false;

    public static function depuis(AppelContribution $appel): self
    {
        $donnees = new self();
        $donnees->type = $appel->getType()->getCode();
        $donnees->objet = $appel->getObjet();
        $donnees->montant = null !== $appel->getMontant() ? $appel->getMontant() / 100 : null;
        $donnees->mode = $appel->getMode();
        $donnees->dateLimite = $appel->getDateLimite();
        $donnees->perimetre = null !== $appel->getVille() ? (string) $appel->getVille()->getId() : self::TOUTES;
        $donnees->taux = $appel->getTauxReversement();
        $donnees->message = $appel->getMessage();
        $donnees->relancesAuto = $appel->aDesRelancesAuto();

        return $donnees;
    }

    public function montantEnCentimes(): ?int
    {
        return null === $this->montant ? null : (int) round($this->montant * 100);
    }

    #[Assert\Callback]
    public function verifier(ExecutionContextInterface $contexte): void
    {
        if (ModeMontant::Fixe === $this->mode && (null === $this->montant || $this->montant <= 0)) {
            $contexte->buildViolation('appel.montant.obligatoire')->atPath('montant')->addViolation();
        }
        if (null !== $this->dateLimite && $this->dateLimite < new \DateTimeImmutable('today')) {
            $contexte->buildViolation('appel.date_limite.passee')->atPath('dateLimite')->addViolation();
        }
        if ($this->tauxAPreciser && null === $this->taux) {
            $contexte->buildViolation('appel.taux.obligatoire')->atPath('taux')->addViolation();
        }
    }
}
