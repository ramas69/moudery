<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Adhesion;
use App\Entity\Association;
use App\Entity\Membre;
use App\Entity\Ville;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Adhesion> */
final class AdhesionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Adhesion::class);
    }

    /** @return array<int, int> année => nombre d'adhérents de la ville cette année-là, de la plus ancienne à la plus récente */
    public function compterParAnnee(Ville $ville): array
    {
        $lignes = $this->createQueryBuilder('a')
            ->select('a.annee AS annee, COUNT(a.id) AS nombre')
            ->andWhere('a.ville = :ville')
            ->setParameter('ville', $ville)
            ->groupBy('a.annee')
            ->orderBy('a.annee', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $parAnnee = [];
        foreach ($lignes as $ligne) {
            $parAnnee[(int) $ligne['annee']] = (int) $ligne['nombre'];
        }

        return $parAnnee;
    }

    /**
     * Les adhésions d'une ville pour une année, avec leurs membres, par nom puis prénom : la grille du classeur, paginée.
     * La recherche porte sur le nom, le prénom et la localité.
     *
     * @return array{adhesions: list<Adhesion>, total: int}
     */
    public function listerPourVilleEtAnnee(Ville $ville, int $annee, string $recherche, int $page, int $parPage): array
    {
        $requete = $this->createQueryBuilder('a')
            ->innerJoin('a.membre', 'm')
            ->addSelect('m')
            ->andWhere('a.ville = :ville')
            ->andWhere('a.annee = :annee')
            ->setParameter('ville', $ville)
            ->setParameter('annee', $annee)
            ->orderBy('m.nom', 'ASC')
            ->addOrderBy('m.prenom', 'ASC');
        $recherche = trim($recherche);
        if ('' !== $recherche) {
            $requete->andWhere('LOWER(m.nom) LIKE :aiguille OR LOWER(m.prenom) LIKE :aiguille OR LOWER(m.localite) LIKE :aiguille')
                ->setParameter('aiguille', '%'.mb_strtolower($recherche).'%');
        }

        $total = (int) (clone $requete)->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $adhesions = $requete->setFirstResult(max(0, $page - 1) * $parPage)->setMaxResults($parPage)->getQuery()->getResult();

        return ['adhesions' => $adhesions, 'total' => $total];
    }

    /**
     * Les chiffres d'une année pour une ville, calculés sur toutes ses adhésions : adhérents, ceux dont on connaît les
     * mois, collecté (somme des totaux), reste dû (somme des restes connus), à jour (reste nul).
     *
     * @return array{adherents: int, avecHistorique: int, collecte: int, reste: int, aJour: int}
     */
    public function synthese(Ville $ville, int $annee): array
    {
        $synthese = ['adherents' => 0, 'avecHistorique' => 0, 'collecte' => 0, 'reste' => 0, 'aJour' => 0];
        $adhesions = $this->createQueryBuilder('a')
            ->andWhere('a.ville = :ville')
            ->andWhere('a.annee = :annee')
            ->setParameter('ville', $ville)
            ->setParameter('annee', $annee)
            ->getQuery()
            ->getResult();
        foreach ($adhesions as $adhesion) {
            \assert($adhesion instanceof Adhesion);
            ++$synthese['adherents'];
            if (!$adhesion->aUnHistorique()) {
                continue;
            }
            ++$synthese['avecHistorique'];
            $synthese['collecte'] += $adhesion->getTotal();
            $reste = $adhesion->getReste();
            if (null !== $reste) {
                $synthese['reste'] += $reste;
                if (0 === $reste) {
                    ++$synthese['aJour'];
                }
            }
        }

        return $synthese;
    }

    /**
     * Les adhésions de l'association (ou d'une ville) pour ces années, avec leur ville : de quoi calculer les
     * encaissements d'un exercice.
     *
     * @param list<int> $annees
     *
     * @return list<Adhesion>
     */
    public function listerPourAnnees(Association $association, array $annees, ?Ville $ville = null): array
    {
        if ([] === $annees) {
            return [];
        }
        $requete = $this->createQueryBuilder('a')
            ->innerJoin('a.ville', 'v')
            ->addSelect('v')
            ->andWhere('a.association = :association')
            ->andWhere('a.annee IN (:annees)')
            ->setParameter('association', $association)
            ->setParameter('annees', $annees);
        if (null !== $ville) {
            $requete->andWhere('a.ville = :ville')->setParameter('ville', $ville);
        }

        return $requete->getQuery()->getResult();
    }

    /**
     * L'adhésion de chaque membre pour cette année, en une requête : la ligne du classeur à afficher dans la grille.
     *
     * @param list<Membre> $membres
     *
     * @return array<int, Adhesion> identifiant du membre => adhésion
     */
    public function pourMembresEtAnnee(array $membres, int $annee): array
    {
        if ([] === $membres) {
            return [];
        }
        $adhesions = $this->createQueryBuilder('a')
            ->andWhere('a.membre IN (:membres)')
            ->andWhere('a.annee = :annee')
            ->setParameter('membres', $membres)
            ->setParameter('annee', $annee)
            ->getQuery()
            ->getResult();

        $parMembre = [];
        foreach ($adhesions as $adhesion) {
            \assert($adhesion instanceof Adhesion);
            $parMembre[(int) $adhesion->getMembre()->getId()] = $adhesion;
        }

        return $parMembre;
    }
}
