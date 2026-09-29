<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Association;
use App\Entity\Invitation;
use App\Security\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Invitation> */
final class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    /** Supprime les invitations d'une association, acceptées ou non : appelé juste avant de supprimer l'association. */
    public function supprimerPourAssociation(Association $association): void
    {
        $this->createQueryBuilder('i')
            ->delete()
            ->andWhere('i.association = :association')
            ->setParameter('association', $association)
            ->getQuery()
            ->execute();
    }

    /** @return list<Invitation> les invitations non acceptées de cette adresse dans l'association */
    public function enCoursPourEmail(Association $association, string $email): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.association = :association')
            ->andWhere('i.email = :email')
            ->andWhere('i.accepteeLe IS NULL')
            ->setParameter('association', $association)
            ->setParameter('email', $email)
            ->getQuery()
            ->getResult();
    }

    public function trouverParJeton(string $jetonHache): ?Invitation
    {
        return $this->findOneBy(['jeton' => $jetonHache]);
    }

    /** @return list<Invitation> les dernières invitations acceptées, la plus récente d'abord */
    public function dernieresAcceptees(int $limite): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.accepteeLe IS NOT NULL')
            ->orderBy('i.accepteeLe', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Les invitations non acceptées d'une association, les plus récentes d'abord.
     *
     * @return list<Invitation>
     */
    public function enCoursParAssociation(Association $association): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.association = :association')
            ->andWhere('i.accepteeLe IS NULL')
            ->setParameter('association', $association)
            ->orderBy('i.envoyeeLe', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * La dernière invitation de bureau central non acceptée de chaque association.
     *
     * @return array<int, Invitation> indexée par identifiant d'association
     */
    public function bureauCentralEnCoursParAssociation(): array
    {
        $invitations = $this->createQueryBuilder('i')
            ->andWhere('i.role = :role')
            ->andWhere('i.accepteeLe IS NULL')
            ->setParameter('role', Role::BureauCentral)
            ->orderBy('i.envoyeeLe', 'DESC')
            ->getQuery()
            ->getResult();

        $parAssociation = [];
        foreach ($invitations as $invitation) {
            $parAssociation[(int) $invitation->getAssociation()->getId()] ??= $invitation;
        }

        return $parAssociation;
    }
}
