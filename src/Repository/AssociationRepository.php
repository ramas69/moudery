<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Association;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Association> */
final class AssociationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Association::class);
    }

    public function trouverParSlug(string $slug): ?Association
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return list<Association> */
    public function listerParNom(): array
    {
        return $this->findBy([], ['nom' => 'ASC']);
    }

    /** @return list<Association> les dernières créées, la plus récente d'abord */
    public function dernieresCreees(int $limite): array
    {
        return $this->findBy([], ['creeLe' => 'DESC'], $limite);
    }
}
