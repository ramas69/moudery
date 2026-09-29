<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Association;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Ville> */
final class VilleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ville::class);
    }

    /**
     * La ville portant cet identifiant, seulement si elle appartient à l'association désignée par son slug :
     * une ville d'une autre association n'existe pas pour celle-ci (isolation multi-tenant).
     */
    public function trouverDansAssociation(int $id, string $slug): ?Ville
    {
        return $this->createQueryBuilder('v')
            ->innerJoin('v.association', 'a')
            ->andWhere('v.id = :id')
            ->andWhere('a.slug = :slug')
            ->setParameter('id', $id)
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Nombre de villes par association, et combien sont encore en brouillon.
     *
     * @return array<int, array{total: int, brouillon: int}> indexé par identifiant d'association
     */
    public function compterParAssociation(): array
    {
        $lignes = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.association) AS association, v.statut AS statut, COUNT(v.id) AS nombre')
            ->groupBy('v.association', 'v.statut')
            ->getQuery()
            ->getArrayResult();

        $compteurs = [];
        foreach ($lignes as $ligne) {
            $id = (int) $ligne['association'];
            $compteurs[$id] ??= ['total' => 0, 'brouillon' => 0];
            $compteurs[$id]['total'] += (int) $ligne['nombre'];
            if (VilleStatut::Brouillon === $ligne['statut']) {
                $compteurs[$id]['brouillon'] += (int) $ligne['nombre'];
            }
        }

        return $compteurs;
    }

    /**
     * Toutes les villes de la plateforme, avec leur association, filtrées si besoin. Réservé à l'administration.
     *
     * @return list<Ville>
     */
    public function listerToutes(?Association $association = null, ?VilleStatut $statut = null): array
    {
        $requete = $this->createQueryBuilder('v')
            ->addSelect('a')
            ->innerJoin('v.association', 'a')
            ->orderBy('a.nom', 'ASC')
            ->addOrderBy('v.nom', 'ASC');

        if (null !== $association) {
            $requete->andWhere('v.association = :association')->setParameter('association', $association);
        }
        if (null !== $statut) {
            $requete->andWhere('v.statut = :statut')->setParameter('statut', $statut);
        }

        return $requete->getQuery()->getResult();
    }

    /** @return list<Ville> */
    public function listerPourAssociation(Association $association): array
    {
        return $this->findBy(['association' => $association], ['nom' => 'ASC']);
    }

    /**
     * Les villes d'une association, avec leurs responsables invités, par nom.
     *
     * @return list<Ville>
     */
    public function listerParAssociation(Association $association): array
    {
        return $this->createQueryBuilder('v')
            ->leftJoin('v.invitations', 'i')
            ->addSelect('i')
            ->andWhere('v.association = :association')
            ->setParameter('association', $association)
            ->orderBy('v.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Une ville de l'association par son nom, sans tenir compte de la casse ni des espaces superflus. */
    public function trouverParNom(Association $association, string $nom): ?Ville
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.association = :association')
            ->andWhere('LOWER(v.nom) = :nom')
            ->setParameter('association', $association)
            ->setParameter('nom', mb_strtolower(Ville::normaliserNom($nom)))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Le nom est-il déjà pris par une autre ville de la même association ?
     * Comparaison sans tenir compte de la casse ni des espaces superflus.
     */
    public function nomDejaUtilise(Association $association, string $nom, ?Ville $exclure = null): bool
    {
        $requete = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.association = :association')
            ->andWhere('LOWER(v.nom) = :nom')
            ->setParameter('association', $association)
            ->setParameter('nom', mb_strtolower(Ville::normaliserNom($nom)));

        if (null !== $exclure?->getId()) {
            $requete->andWhere('v.id != :exclure')->setParameter('exclure', $exclure->getId());
        }

        return (int) $requete->getQuery()->getSingleScalarResult() > 0;
    }
}
