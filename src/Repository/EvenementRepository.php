<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Association;
use App\Entity\Evenement;
use App\Entity\TypeEvenement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Evenement> */
final class EvenementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evenement::class);
    }

    /**
     * Les événements d'une période, du plus ancien au plus récent, pour une association ou toute la plateforme.
     *
     * @return list<Evenement>
     */
    public function entre(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?Association $association = null): array
    {
        $requete = $this->createQueryBuilder('e')
            ->andWhere('e.quand >= :debut')
            ->andWhere('e.quand < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('e.quand', 'ASC');
        if (null !== $association) {
            $requete->andWhere('e.association = :association')->setParameter('association', $association);
        }

        return $requete->getQuery()->getResult();
    }

    /**
     * Tout l'historique des abonnements jusqu'à une date, dans l'ordre : chaque événement porte l'instantané
     * de l'abonnement à ce moment-là, ce qui permet de retracer le revenu mensuel.
     *
     * @return list<Evenement>
     */
    public function abonnementsJusqua(\DateTimeImmutable $fin, ?Association $association = null): array
    {
        $requete = $this->createQueryBuilder('e')
            ->andWhere('e.type IN (:types)')
            ->andWhere('e.quand < :fin')
            ->andWhere('e.association IS NOT NULL')
            ->setParameter('types', array_map(static fn (TypeEvenement $t): string => $t->value, TypeEvenement::abonnements()))
            ->setParameter('fin', $fin)
            ->orderBy('e.quand', 'ASC')
            ->addOrderBy('e.id', 'ASC');
        if (null !== $association) {
            $requete->andWhere('e.association = :association')->setParameter('association', $association);
        }

        return $requete->getQuery()->getResult();
    }

    /** @return list<Evenement> les plus récents d'abord, pour une association */
    public function derniersPour(Association $association, int $limite): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.association = :association')
            ->setParameter('association', $association)
            ->orderBy('e.quand', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Evenement> les plus récents d'abord */
    public function derniers(int $limite): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.quand', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
