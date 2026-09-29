<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Association;
use App\Entity\Depense;
use App\Entity\DepenseStatut;
use App\Entity\Ville;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Depense> */
final class DepenseRepository extends ServiceEntityRepository
{
    public const string CENTRAL = 'central';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Depense::class);
    }

    /**
     * Les dépenses de l'association, ou d'une caisse (une ville, ou « central » pour le bureau central), les plus récentes d'abord.
     *
     * @return list<Depense>
     */
    public function lister(Association $association, Ville|string|null $caisse = null): array
    {
        return $this->requete($association, $caisse)
            ->orderBy('d.saisieLe', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Somme des dépenses payées entre deux dates (incluses), en centimes : le « Dépensé » du tableau de bord. */
    public function totalPaye(Association $association, ?Ville $ville, \DateTimeImmutable $du, \DateTimeImmutable $au): int
    {
        return (int) $this->requete($association, $ville)
            ->select('COALESCE(SUM(d.montant), 0)')
            ->andWhere('d.statut = :payee')
            ->andWhere('d.dateDepense BETWEEN :du AND :au')
            ->setParameter('payee', DepenseStatut::Payee)
            ->setParameter('du', $du->setTime(0, 0))
            ->setParameter('au', $au->setTime(0, 0))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Les dépenses payées entre deux dates, par caisse : identifiant de la ville, ou « central » pour le bureau central.
     *
     * @return array<int|string, int>
     */
    public function totalPayeParCaisse(Association $association, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        $lignes = $this->payees($association, $du, $au)
            ->select('IDENTITY(d.ville) AS ville, SUM(d.montant) AS total')
            ->groupBy('d.ville')
            ->getQuery()
            ->getArrayResult();
        $totaux = [];
        foreach ($lignes as $ligne) {
            $totaux[null === $ligne['ville'] ? self::CENTRAL : (int) $ligne['ville']] = (int) $ligne['total'];
        }

        return $totaux;
    }

    /**
     * Les dépenses payées entre deux dates, par catégorie (valeur de `CategorieDepense`), de la plus lourde à la plus légère.
     *
     * @return array<string, int>
     */
    public function totalPayeParCategorie(Association $association, \DateTimeImmutable $du, \DateTimeImmutable $au, ?\App\Entity\Ville $ville = null): array
    {
        $requete = $this->payees($association, $du, $au);
        if (null !== $ville) {
            $requete->andWhere('d.ville = :ville')->setParameter('ville', $ville);
        }
        $lignes = $requete
            ->select('d.categorie AS categorie, SUM(d.montant) AS total')
            ->groupBy('d.categorie')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();
        $totaux = [];
        foreach ($lignes as $ligne) {
            $categorie = $ligne['categorie'];
            $totaux[$categorie instanceof \BackedEnum ? (string) $categorie->value : (string) $categorie] = (int) $ligne['total'];
        }

        return $totaux;
    }

    private function payees(Association $association, \DateTimeImmutable $du, \DateTimeImmutable $au): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.association = :association')
            ->andWhere('d.statut = :payee')
            ->andWhere('d.dateDepense BETWEEN :du AND :au')
            ->setParameter('association', $association)
            ->setParameter('payee', DepenseStatut::Payee)
            ->setParameter('du', $du->setTime(0, 0))
            ->setParameter('au', $au->setTime(0, 0));
    }

    /** Le compteur de la navigation : toute l'association, ou la caisse d'une ville pour un responsable de ville. */
    public function compterAValider(Association $association, ?Ville $ville = null): int
    {
        return (int) $this->requete($association, $ville)
            ->select('COUNT(d.id)')
            ->andWhere('d.statut = :soumise')
            ->setParameter('soumise', DepenseStatut::Soumise)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Le prochain numéro de l'année : « 2026-001 », « 2026-002 »… */
    public function prochainNumero(Association $association, int $annee): string
    {
        $dernier = $this->createQueryBuilder('d')
            ->select('MAX(d.numero)')
            ->andWhere('d.association = :association')
            ->andWhere('d.numero LIKE :prefixe')
            ->setParameter('association', $association)
            ->setParameter('prefixe', $annee.'-%')
            ->getQuery()
            ->getSingleScalarResult();
        $rang = null === $dernier ? 0 : (int) substr((string) $dernier, 5);

        return \sprintf('%d-%03d', $annee, $rang + 1);
    }

    private function requete(Association $association, Ville|string|null $caisse): QueryBuilder
    {
        $requete = $this->createQueryBuilder('d')
            ->leftJoin('d.ville', 'v')
            ->addSelect('v')
            ->andWhere('d.association = :association')
            ->setParameter('association', $association);
        if ($caisse instanceof Ville) {
            $requete->andWhere('d.ville = :ville')->setParameter('ville', $caisse);
        } elseif (self::CENTRAL === $caisse) {
            $requete->andWhere('d.ville IS NULL');
        }

        return $requete;
    }
}
