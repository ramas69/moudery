<?php

declare(strict_types=1);

namespace App\Association;

use App\Administration\Texte;
use App\Entity\Association;
use App\Entity\TypeContribution;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Form\Model\TypeContributionData;
use App\Journal\Journal;
use App\Repository\TypeContributionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Configuration des types de contribution par le bureau central (F-11) : ajout, modification, archivage. Un type ne se
 * supprime jamais (les appels passés y renvoient) ; archivé, il ne se propose plus pour un nouvel appel. Le dernier
 * type actif ne s'archive pas : un appel a toujours besoin d'un type.
 */
final class TypesContribution
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TypeContributionRepository $types,
        private readonly Journal $journal,
    ) {
    }

    public function ajouter(Association $association, TypeContributionData $donnees, ?Utilisateur $acteur): TypeContribution
    {
        \assert(null !== $donnees->unite && null !== $donnees->mode);
        $existants = $this->types->listerPour($association);
        $ordre = [] === $existants ? 0 : max(array_map(static fn (TypeContribution $t): int => $t->getOrdre(), $existants)) + 1;
        $type = new TypeContribution($association, self::codePour((string) $donnees->nom, $existants), (string) $donnees->nom, $donnees->unite, $donnees->mode, $donnees->montantEnCentimes(), $donnees->tauxReversement, $ordre);
        $this->entityManager->persist($type);
        $this->journal->consigner(TypeEvenement::TypeContributionModifie, $acteur, $association, $type->getNom(), ['action' => 'ajoute'] + self::details($type));
        $this->entityManager->flush();

        return $type;
    }

    public function modifier(TypeContribution $type, TypeContributionData $donnees, ?Utilisateur $acteur): void
    {
        \assert(null !== $donnees->unite && null !== $donnees->mode);
        $avant = self::details($type);
        $type->modifier((string) $donnees->nom, $donnees->unite, $donnees->mode, $donnees->montantEnCentimes(), $donnees->tauxReversement);
        $this->journal->consigner(TypeEvenement::TypeContributionModifie, $acteur, $type->getAssociation(), $type->getNom(), ['action' => 'modifie', 'avant' => $avant, 'apres' => self::details($type)]);
        $this->entityManager->flush();
    }

    public function archiver(TypeContribution $type, ?Utilisateur $acteur): void
    {
        $actifs = array_filter($this->types->listerPour($type->getAssociation()), static fn (TypeContribution $t): bool => $t->estActif() && $t !== $type);
        if ([] === $actifs) {
            throw new \LogicException('Le dernier type de contribution actif ne peut pas être archivé.');
        }
        $type->archiver();
        $this->journal->consigner(TypeEvenement::TypeContributionModifie, $acteur, $type->getAssociation(), $type->getNom(), ['action' => 'archive'] + self::details($type));
        $this->entityManager->flush();
    }

    public function reactiver(TypeContribution $type, ?Utilisateur $acteur): void
    {
        $type->reactiver();
        $this->journal->consigner(TypeEvenement::TypeContributionModifie, $acteur, $type->getAssociation(), $type->getNom(), ['action' => 'reactive'] + self::details($type));
        $this->entityManager->flush();
    }

    /**
     * Un code lisible et unique dans l'association, déduit du nom (« Fête du village » donne « fete-du-village »),
     * suffixé d'un numéro s'il existe déjà.
     *
     * @param list<TypeContribution> $existants
     */
    public static function codePour(string $nom, array $existants): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', Texte::normaliser($nom)), '-');
        $base = '' === $base ? 'type' : mb_substr($base, 0, 36);
        $pris = array_map(static fn (TypeContribution $t): string => $t->getCode(), $existants);
        $code = $base;
        for ($i = 2; \in_array($code, $pris, true); ++$i) {
            $code = $base.'-'.$i;
        }

        return $code;
    }

    /** @return array<string, mixed> */
    private static function details(TypeContribution $type): array
    {
        return [
            'code' => $type->getCode(),
            'nom' => $type->getNom(),
            'unite' => $type->getUnite()->value,
            'mode' => $type->getMode()->value,
            'montant_defaut' => $type->getMontantDefaut(),
            'taux_reversement' => $type->getTauxReversement(),
            'actif' => $type->estActif(),
        ];
    }
}
