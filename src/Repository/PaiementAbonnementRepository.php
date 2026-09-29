<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Association;
use App\Entity\PaiementAbonnement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PaiementAbonnement> */
final class PaiementAbonnementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaiementAbonnement::class);
    }

    /** @return list<PaiementAbonnement> reçus dans la période, du plus ancien au plus récent */
    public function entre(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?Association $association = null): array
    {
        $requete = $this->createQueryBuilder('p')
            ->andWhere('p.recuLe >= :debut')
            ->andWhere('p.recuLe < :fin')
            ->setParameter('debut', $debut->setTime(0, 0))
            ->setParameter('fin', $fin->setTime(0, 0))
            ->orderBy('p.recuLe', 'ASC');
        if (null !== $association) {
            $requete->andWhere('p.association = :association')->setParameter('association', $association);
        }

        return $requete->getQuery()->getResult();
    }
}
