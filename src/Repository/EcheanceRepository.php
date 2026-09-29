<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Association;
use App\Entity\Cotisation;
use App\Entity\Echeance;
use App\Entity\EcheanceStatut;
use App\Entity\Membre;
use App\Entity\Ville;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Echeance> */
final class EcheanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Echeance::class);
    }

    /** @return list<Echeance> ce que le membre doit encore, la plus ancienne d'abord (avec appel et cotisation chargés) */
    public function duesPourMembre(Membre $membre): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.appel', 'a')
            ->addSelect('a')
            ->leftJoin('e.cotisation', 'c')
            ->addSelect('c')
            ->andWhere('e.membre = :membre')
            ->andWhere('e.statut = :due')
            ->setParameter('membre', $membre)
            ->setParameter('due', EcheanceStatut::Due)
            ->orderBy('e.dateLimite', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Echeance> toutes les mensualités d'une cotisation, avec leur membre */
    public function listerPourCotisation(Cotisation $cotisation): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.membre', 'm')
            ->addSelect('m')
            ->andWhere('e.cotisation = :cotisation')
            ->setParameter('cotisation', $cotisation)
            ->orderBy('m.id', 'ASC')
            ->addOrderBy('e.mois', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les échéances dues (à venir ou en retard) des membres du périmètre, de la plus ancienne à la plus récente ;
     * `$depuis` écarte celles dont la date limite est antérieure (pour ne charger que celles qu'un calendrier de
     * relances peut encore concerner).
     *
     * @return list<Echeance>
     */
    public function duesPourPerimetre(Association $association, ?Ville $ville, ?\DateTimeImmutable $depuis = null): array
    {
        $requete = $this->createQueryBuilder('e')
            ->innerJoin('e.membre', 'm')
            ->addSelect('m')
            ->leftJoin('e.appel', 'a')
            ->addSelect('a')
            ->leftJoin('e.cotisation', 'c')
            ->addSelect('c')
            ->andWhere('e.association = :association')
            ->andWhere('e.statut = :due')
            ->setParameter('association', $association)
            ->setParameter('due', EcheanceStatut::Due)
            ->orderBy('e.dateLimite', 'ASC')
            ->addOrderBy('e.id', 'ASC');
        if (null !== $ville) {
            $requete->andWhere('m.ville = :ville')->setParameter('ville', $ville);
        }
        if (null !== $depuis) {
            $requete->andWhere('e.dateLimite >= :depuis')->setParameter('depuis', $depuis->setTime(0, 0), Types::DATE_IMMUTABLE);
        }

        return $requete->getQuery()->getResult();
    }

    /** Combien de membres du périmètre ont au moins une échéance due dont la date limite est passée (badge « Impayés »). */
    public function compterMembresEnRetard(Association $association, ?Ville $ville, \DateTimeImmutable $aujourdhui): int
    {
        $requete = $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e.membre)')
            ->andWhere('e.association = :association')
            ->andWhere('e.statut = :due')
            ->andWhere('e.dateLimite < :aujourdhui')
            ->setParameter('association', $association)
            ->setParameter('due', EcheanceStatut::Due)
            ->setParameter('aujourdhui', $aujourdhui->setTime(0, 0), Types::DATE_IMMUTABLE);
        if (null !== $ville) {
            $requete->innerJoin('e.membre', 'm')->andWhere('m.ville = :ville')->setParameter('ville', $ville);
        }

        return (int) $requete->getQuery()->getSingleScalarResult();
    }

    /**
     * Les échéances en retard (dues, date limite passée) de l'association ou d'une ville, la plus ancienne d'abord,
     * avec leur membre, leur appel et leur cotisation : le tableau des impayés (F-25).
     *
     * @return list<Echeance>
     */
    public function impayees(Association $association, ?Ville $ville, \DateTimeImmutable $aujourdhui): array
    {
        $requete = $this->createQueryBuilder('e')
            ->innerJoin('e.membre', 'm')
            ->addSelect('m')
            ->leftJoin('e.appel', 'a')
            ->addSelect('a')
            ->leftJoin('e.cotisation', 'c')
            ->addSelect('c')
            ->andWhere('e.association = :association')
            ->andWhere('e.statut = :due')
            ->andWhere('e.dateLimite < :aujourdhui')
            ->setParameter('association', $association)
            ->setParameter('due', EcheanceStatut::Due)
            ->setParameter('aujourdhui', $aujourdhui->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('e.dateLimite', 'ASC')
            ->addOrderBy('e.id', 'ASC');
        if (null !== $ville) {
            $requete->andWhere('m.ville = :ville')->setParameter('ville', $ville);
        }

        return $requete->getQuery()->getResult();
    }

    /**
     * Les compteurs d'une cotisation : mensualités dues, payées, annulées, en retard, et les centimes correspondants.
     *
     * @return array{dues: int, payees: int, annulees: int, enRetard: int, montantDu: int, montantPaye: int, montantEnRetard: int, membres: int}
     */
    public function synthesePourCotisation(Cotisation $cotisation, \DateTimeImmutable $aujourdhui): array
    {
        $synthese = ['dues' => 0, 'payees' => 0, 'annulees' => 0, 'enRetard' => 0, 'montantDu' => 0, 'montantPaye' => 0, 'montantEnRetard' => 0, 'membres' => 0];
        $membres = [];
        foreach ($this->listerPourCotisation($cotisation) as $echeance) {
            $membres[(int) $echeance->getMembre()->getId()] = true;
            $montant = $echeance->getMontant() ?? 0;
            if ($echeance->estPayee()) {
                ++$synthese['payees'];
                $synthese['montantPaye'] += $montant;
            } elseif ($echeance->estDue()) {
                ++$synthese['dues'];
                $synthese['montantDu'] += $montant;
                if ($echeance->estEnRetard($aujourdhui)) {
                    ++$synthese['enRetard'];
                    $synthese['montantEnRetard'] += $montant;
                }
            } else {
                ++$synthese['annulees'];
            }
        }
        $synthese['membres'] = \count($membres);

        return $synthese;
    }

    /**
     * Les échéances dues dont la date limite tombe à l'une de ces dates (les jours du calendrier des relances), avec
     * leur membre, leur appel et leur cotisation.
     *
     * @param list<\DateTimeImmutable> $dates
     *
     * @return list<Echeance>
     */
    public function duesAuxDates(Association $association, array $dates): array
    {
        if ([] === $dates) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->innerJoin('e.membre', 'm')
            ->addSelect('m')
            ->leftJoin('e.appel', 'a')
            ->addSelect('a')
            ->leftJoin('e.cotisation', 'c')
            ->addSelect('c')
            ->andWhere('e.association = :association')
            ->andWhere('e.statut = :due')
            ->andWhere('e.dateLimite IN (:dates)')
            ->setParameter('association', $association)
            ->setParameter('due', EcheanceStatut::Due)
            ->setParameter('dates', array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $dates))
            ->orderBy('m.id', 'ASC')
            ->addOrderBy('e.dateLimite', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ce que chaque membre doit encore : nombre de mensualités ou d'échéances dues, montant, et combien sont en retard.
     *
     * @return array<int, array{nombre: int, montant: int, retard: int}> identifiant du membre => compteurs
     */
    public function duesParMembre(Association $association, ?Ville $ville, \DateTimeImmutable $aujourdhui): array
    {
        $requete = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.membre) AS membre, e.montant AS montant, e.dateLimite AS dateLimite')
            ->innerJoin('e.membre', 'm')
            ->andWhere('e.association = :association')
            ->andWhere('e.statut = :due')
            ->setParameter('association', $association)
            ->setParameter('due', EcheanceStatut::Due);
        if (null !== $ville) {
            $requete->andWhere('m.ville = :ville')->setParameter('ville', $ville);
        }
        $jour = $aujourdhui->setTime(0, 0);
        $parMembre = [];
        foreach ($requete->getQuery()->getArrayResult() as $ligne) {
            $id = (int) $ligne['membre'];
            $parMembre[$id] ??= ['nombre' => 0, 'montant' => 0, 'retard' => 0];
            ++$parMembre[$id]['nombre'];
            $parMembre[$id]['montant'] += (int) ($ligne['montant'] ?? 0);
            $limite = $ligne['dateLimite'] instanceof \DateTimeInterface ? $ligne['dateLimite'] : new \DateTimeImmutable((string) $ligne['dateLimite']);
            if ($limite < $jour) {
                ++$parMembre[$id]['retard'];
            }
        }

        return $parMembre;
    }
}
