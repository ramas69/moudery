<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Association;
use App\Entity\Membre;
use App\Entity\Paiement;
use App\Entity\PaiementStatut;
use App\Entity\Ville;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Paiement> */
final class PaiementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Paiement::class);
    }

    /**
     * Les paiements de l'association (ou d'une ville), les plus récents d'abord, avec leur membre et leurs échéances.
     *
     * @return list<Paiement>
     */
    public function lister(Association $association, ?Ville $ville, ?\DateTimeImmutable $du = null, ?\DateTimeImmutable $au = null, int $maximum = 500): array
    {
        $requete = $this->createQueryBuilder('p')
            ->innerJoin('p.membre', 'm')
            ->addSelect('m')
            ->leftJoin('p.echeances', 'e')
            ->addSelect('e')
            ->andWhere('p.association = :association')
            ->setParameter('association', $association)
            ->orderBy('p.recuLe', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($maximum);
        if (null !== $ville) {
            $requete->andWhere('p.ville = :ville')->setParameter('ville', $ville);
        }
        if (null !== $du) {
            $requete->andWhere('p.recuLe >= :du')->setParameter('du', $du, Types::DATE_IMMUTABLE);
        }
        if (null !== $au) {
            $requete->andWhere('p.recuLe <= :au')->setParameter('au', $au, Types::DATE_IMMUTABLE);
        }

        return $requete->getQuery()->getResult();
    }

    /** @return list<Paiement> les paiements d'un membre, les plus récents d'abord */
    public function pourMembre(Membre $membre): array
    {
        return $this->findBy(['membre' => $membre], ['recuLe' => 'DESC', 'id' => 'DESC']);
    }

    /** Le total encaissé (paiements enregistrés) d'une ville ou de l'association entre deux dates, en centimes. */
    public function totalEncaisse(Association $association, ?Ville $ville, \DateTimeImmutable $du, \DateTimeImmutable $au): int
    {
        $requete = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.montant), 0)')
            ->andWhere('p.association = :association')
            ->andWhere('p.statut = :statut')
            ->andWhere('p.recuLe BETWEEN :du AND :au')
            ->setParameter('association', $association)
            ->setParameter('statut', PaiementStatut::Enregistre)
            ->setParameter('du', $du, Types::DATE_IMMUTABLE)
            ->setParameter('au', $au, Types::DATE_IMMUTABLE);
        if (null !== $ville) {
            $requete->andWhere('p.ville = :ville')->setParameter('ville', $ville);
        }

        return (int) $requete->getQuery()->getSingleScalarResult();
    }

    /**
     * La date du dernier paiement enregistré (non annulé) de chaque membre de la ville.
     *
     * @return array<int, \DateTimeImmutable> identifiant du membre => reçu le
     */
    public function derniersParMembre(Association $association, ?Ville $ville): array
    {
        $requete = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.membre) AS membre, MAX(p.recuLe) AS dernier')
            ->andWhere('p.association = :association')
            ->andWhere('p.statut = :statut')
            ->setParameter('association', $association)
            ->setParameter('statut', PaiementStatut::Enregistre)
            ->groupBy('p.membre');
        if (null !== $ville) {
            $requete->andWhere('p.ville = :ville')->setParameter('ville', $ville);
        }
        $derniers = [];
        foreach ($requete->getQuery()->getArrayResult() as $ligne) {
            $derniers[(int) $ligne['membre']] = new \DateTimeImmutable((string) $ligne['dernier']);
        }

        return $derniers;
    }
}
