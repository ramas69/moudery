<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Association;
use App\Entity\Echeance;
use App\Entity\EcheanceStatut;
use App\Entity\Reversement;
use App\Entity\ReversementStatut;
use App\Entity\Ville;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Reversement> */
final class ReversementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reversement::class);
    }

    /** @return list<Reversement> les reversements d'un exercice, les plus récents d'abord */
    public function listerPourExercice(Association $association, int $exercice): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.ville', 'v')
            ->addSelect('v')
            ->andWhere('r.association = :association')
            ->andWhere('r.exercice = :exercice')
            ->setParameter('association', $association)
            ->setParameter('exercice', $exercice)
            ->orderBy('r.recuLe', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Reversement> les reversements déclarés par les villes que le central n'a pas encore confirmés, les plus anciens d'abord */
    public function aConfirmer(Association $association): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.ville', 'v')
            ->addSelect('v')
            ->andWhere('r.association = :association')
            ->andWhere('r.statut = :statut')
            ->setParameter('association', $association)
            ->setParameter('statut', ReversementStatut::Declare)
            ->orderBy('r.recuLe', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Le compteur de l'entrée « Reversements » de la navigation : les déclarations qui attendent le central. */
    public function compterAConfirmer(Association $association, ?Ville $ville = null): int
    {
        $requete = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.association = :association')
            ->andWhere('r.statut = :statut')
            ->setParameter('association', $association)
            ->setParameter('statut', ReversementStatut::Declare);
        if (null !== $ville) {
            $requete->andWhere('r.ville = :ville')->setParameter('ville', $ville);
        }

        return (int) $requete->getQuery()->getSingleScalarResult();
    }

    /**
     * La part du central sur les contributions ponctuelles encaissées à la main par chaque ville : les échéances
     * payées des appels dont la date limite tombe dans l'exercice, au taux de reversement de chaque appel, arrondie au centime inférieur (cahier des charges).
     *
     * @return array<int, int> identifiant de ville => centimes dus
     */
    public function partDuCentralSurLesAppels(Association $association, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $lignes = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(m.ville) AS ville, e.montant AS montant, a.tauxReversement AS taux')
            ->from(Echeance::class, 'e')
            ->innerJoin('e.appel', 'a')
            ->innerJoin('e.membre', 'm')
            ->andWhere('e.association = :association')
            ->andWhere('e.statut = :payee')
            ->andWhere('a.dateLimite BETWEEN :debut AND :fin')
            ->setParameter('association', $association)
            ->setParameter('payee', EcheanceStatut::Payee)
            ->setParameter('debut', $debut, Types::DATE_IMMUTABLE)
            ->setParameter('fin', $fin, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();

        $parVille = [];
        foreach ($lignes as $ligne) {
            $ville = (int) $ligne['ville'];
            $parVille[$ville] = ($parVille[$ville] ?? 0) + intdiv((int) ($ligne['montant'] ?? 0) * (int) $ligne['taux'], 100);
        }

        return $parVille;
    }
}
