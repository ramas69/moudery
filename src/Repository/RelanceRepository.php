<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Association;
use App\Entity\Membre;
use App\Entity\Relance;
use App\Entity\Ville;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Relance> */
final class RelanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Relance::class);
    }

    /** Ce membre a-t-il déjà reçu ce genre de relance (canal, automatique ou non) ce jour-là ? Jamais deux fois la même relance le même jour. */
    public function dejaRelanceLe(Membre $membre, \DateTimeImmutable $jour, bool $automatique, string $canal = Relance::CANAL_EMAIL): bool
    {
        return null !== $this->createQueryBuilder('r')
            ->select('r.id')
            ->andWhere('r.membre = :membre')
            ->andWhere('r.jour = :jour')
            ->andWhere('r.automatique = :automatique')
            ->andWhere('r.canal = :canal')
            ->setParameter('membre', $membre)
            ->setParameter('jour', $jour->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->setParameter('automatique', $automatique)
            ->setParameter('canal', $canal)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Toutes les relances de chaque membre de l'association (ou de la ville), de la plus ancienne à la plus récente.
     *
     * @return array<int, list<Relance>> identifiant du membre => relances
     */
    public function historiqueParMembre(Association $association, ?Ville $ville = null): array
    {
        $requete = $this->createQueryBuilder('r')
            ->andWhere('r.association = :association')
            ->setParameter('association', $association)
            ->orderBy('r.envoyeeLe', 'ASC')
            ->addOrderBy('r.id', 'ASC');
        if (null !== $ville) {
            $requete->andWhere('r.ville = :ville')->setParameter('ville', $ville);
        }
        $historique = [];
        foreach ($requete->getQuery()->getResult() as $relance) {
            $historique[(int) $relance->getMembre()->getId()][] = $relance;
        }

        return $historique;
    }

    /**
     * La dernière relance de chaque membre de l'association (ou de la ville).
     *
     * @return array<int, Relance> identifiant du membre => relance
     */
    public function dernieresParMembre(Association $association, ?Ville $ville = null): array
    {
        return array_map(static fn (array $relances): Relance => $relances[array_key_last($relances)], $this->historiqueParMembre($association, $ville));
    }

    public function compterDepuis(Association $association, ?Ville $ville, \DateTimeImmutable $depuis): int
    {
        $requete = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.association = :association')
            ->andWhere('r.envoyeeLe >= :depuis')
            ->setParameter('association', $association)
            ->setParameter('depuis', $depuis);
        if (null !== $ville) {
            $requete->andWhere('r.ville = :ville')->setParameter('ville', $ville);
        }

        return (int) $requete->getQuery()->getSingleScalarResult();
    }
}
