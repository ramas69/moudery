<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Abonnement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Abonnement> */
final class AbonnementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Abonnement::class);
    }

    /**
     * Tous les abonnements avec leur association, les échéances les plus proches d'abord, puis sans échéance.
     *
     * @return list<Abonnement>
     */
    public function listerParEcheance(): array
    {
        return $this->createQueryBuilder('ab')
            ->innerJoin('ab.association', 'a')
            ->addSelect('a')
            ->orderBy('CASE WHEN ab.prochaineEcheanceLe IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('ab.prochaineEcheanceLe', 'ASC')
            ->addOrderBy('a.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
