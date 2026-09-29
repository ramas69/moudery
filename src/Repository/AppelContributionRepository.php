<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppelContribution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AppelContribution> */
final class AppelContributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppelContribution::class);
    }
}
