<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvitationResponsable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InvitationResponsable> */
final class InvitationResponsableRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvitationResponsable::class);
    }
}
