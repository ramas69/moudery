<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppelContribution;
use App\Entity\Association;
use App\Entity\TypeContribution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TypeContribution> */
final class TypeContributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TypeContribution::class);
    }

    /** @return list<TypeContribution> tous les types de l'association, actifs ou archivés, dans l'ordre d'affichage */
    public function listerPour(Association $association): array
    {
        return $this->findBy(['association' => $association], ['ordre' => 'ASC', 'id' => 'ASC']);
    }

    /** @return array<int, int> identifiant du type => nombre d'appels qui s'en servent */
    public function nombreAppelsParType(Association $association): array
    {
        $lignes = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(a.type) AS type, COUNT(a.id) AS nombre')
            ->from(AppelContribution::class, 'a')
            ->andWhere('a.association = :association')
            ->setParameter('association', $association)
            ->groupBy('a.type')
            ->getQuery()
            ->getArrayResult();

        return array_combine(array_map(static fn (array $l): int => (int) $l['type'], $lignes), array_map(static fn (array $l): int => (int) $l['nombre'], $lignes));
    }
}
