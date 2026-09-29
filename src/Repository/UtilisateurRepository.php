<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Association;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Security\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 *
 * @implements PasswordUpgraderInterface<Utilisateur>
 */
final class UtilisateurRepository extends ServiceEntityRepository implements UserLoaderInterface, PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    /** Le pare-feu cherche le compte par l'adresse saisie, quelle que soit sa casse. */
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        return $this->trouverParEmail($identifier);
    }

    public function trouverParEmail(string $email): ?Utilisateur
    {
        $email = Utilisateur::normaliserEmail($email);

        return null === $email ? null : $this->findOneBy(['email' => $email]);
    }

    public function trouverParJetonReinitialisation(string $jetonHache): ?Utilisateur
    {
        return $this->findOneBy(['jetonReinitialisation' => $jetonHache]);
    }

    /**
     * Nombre de comptes par association, et combien attendent encore une validation.
     *
     * @return array<int, array{total: int, en_attente: int}> indexé par identifiant d'association
     */
    public function compterParAssociation(): array
    {
        $lignes = $this->createQueryBuilder('u')
            ->select('IDENTITY(u.association) AS association, u.statut AS statut, COUNT(u.id) AS nombre')
            ->andWhere('u.association IS NOT NULL')
            ->groupBy('u.association', 'u.statut')
            ->getQuery()
            ->getArrayResult();

        $compteurs = [];
        foreach ($lignes as $ligne) {
            $id = (int) $ligne['association'];
            $compteurs[$id] ??= ['total' => 0, 'en_attente' => 0];
            $compteurs[$id]['total'] += (int) $ligne['nombre'];
            if (UtilisateurStatut::EnAttente === $ligne['statut']) {
                $compteurs[$id]['en_attente'] += (int) $ligne['nombre'];
            }
        }

        return $compteurs;
    }

    /** @return list<Utilisateur> les derniers comptes connectés, le plus récent d'abord */
    public function derniersConnectes(int $limite): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.derniereConnexionLe IS NOT NULL')
            ->orderBy('u.derniereConnexionLe', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Utilisateur> les derniers comptes créés, le plus récent d'abord */
    public function derniersCrees(int $limite): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.creeLe', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * La dernière connexion d'un compte de chaque association : l'activité récente d'un espace.
     *
     * @return array<int, \DateTimeImmutable> indexée par identifiant d'association
     */
    public function derniereConnexionParAssociation(): array
    {
        $lignes = $this->createQueryBuilder('u')
            ->select('IDENTITY(u.association) AS association, MAX(u.derniereConnexionLe) AS derniere')
            ->andWhere('u.association IS NOT NULL')
            ->andWhere('u.derniereConnexionLe IS NOT NULL')
            ->groupBy('u.association')
            ->getQuery()
            ->getArrayResult();

        $activites = [];
        foreach ($lignes as $ligne) {
            $activites[(int) $ligne['association']] = new \DateTimeImmutable((string) $ligne['derniere']);
        }

        return $activites;
    }

    /**
     * Les comptes du bureau central de chaque association.
     *
     * @return array<int, list<Utilisateur>> indexé par identifiant d'association
     */
    public function bureauxCentrauxParAssociation(): array
    {
        $comptes = $this->createQueryBuilder('u')
            ->innerJoin('u.affectations', 'a', 'WITH', 'a.role = :role')
            ->setParameter('role', Role::BureauCentral)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        $parAssociation = [];
        foreach ($comptes as $compte) {
            $parAssociation[(int) $compte->getAssociation()?->getId()][] = $compte;
        }

        return $parAssociation;
    }

    /**
     * L'équipe de la plateforme : les comptes qui portent le rôle super-admin, avec ou sans association
     * (règle du 27 septembre 2026 : une personne peut être super-admin et appartenir à une association).
     *
     * @return list<Utilisateur>
     */
    public function listerSuperAdmins(): array
    {
        return $this->createQueryBuilder('u')
            ->addSelect('a')
            ->leftJoin('u.association', 'a')
            ->innerJoin('u.affectations', 'af', 'WITH', 'af.role = :role')
            ->setParameter('role', Role::SuperAdmin)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les comptes de la plateforme, filtrés si besoin. Réservé à l'administration.
     *
     * @return list<Utilisateur>
     */
    public function listerTous(?Association $association = null, ?UtilisateurStatut $statut = null): array
    {
        $requete = $this->createQueryBuilder('u')
            ->addSelect('a', 'af')
            ->leftJoin('u.association', 'a')
            ->leftJoin('u.affectations', 'af')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');

        if (null !== $association) {
            $requete->andWhere('u.association = :association')->setParameter('association', $association);
        }
        if (null !== $statut) {
            $requete->andWhere('u.statut = :statut')->setParameter('statut', $statut);
        }

        return $requete->getQuery()->getResult();
    }

    /** @return list<Utilisateur> */
    public function listerPourAssociation(Association $association): array
    {
        return $this->findBy(['association' => $association], ['nom' => 'ASC', 'prenom' => 'ASC']);
    }

    /** Symfony renforce le hachage à la connexion quand l'algorithme « auto » évolue. */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Utilisateur) {
            throw new UnsupportedUserException(\sprintf('Les comptes « %s » ne sont pas pris en charge.', $user::class));
        }

        $user->definirMotDePasse($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
