<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Adhesion;
use App\Entity\Association;
use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\Ville;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Membre> */
final class MembreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membre::class);
    }

    /**
     * Les membres d'une ville, par nom, avec recherche (nom, prénom, e-mail, téléphone) et filtre de statut, paginés.
     *
     * @return array{membres: list<Membre>, total: int}
     */
    public function rechercher(Ville $ville, string $recherche, ?MembreStatut $statut, int $page, int $parPage): array
    {
        $requete = $this->createQueryBuilder('m')
            ->leftJoin('m.foyer', 'f')
            ->addSelect('f')
            ->andWhere('m.ville = :ville')
            ->setParameter('ville', $ville)
            ->orderBy('m.nom', 'ASC')
            ->addOrderBy('m.prenom', 'ASC');
        if (null !== $statut) {
            $requete->andWhere('m.statut = :statut')->setParameter('statut', $statut);
        }
        $recherche = trim($recherche);
        if ('' !== $recherche) {
            $conditions = 'LOWER(m.nom) LIKE :aiguille OR LOWER(m.prenom) LIKE :aiguille OR LOWER(m.email) LIKE :aiguille OR LOWER(f.nom) LIKE :aiguille';
            $requete->setParameter('aiguille', '%'.mb_strtolower($recherche).'%');
            // Le téléphone se cherche par ses chiffres seuls (« 06 12 » trouve « 0612… ») ; sans chiffre, pas de recherche sur le numéro.
            $chiffres = (string) preg_replace('/\D+/', '', $recherche);
            if ('' !== $chiffres) {
                $conditions .= ' OR m.telephone LIKE :chiffres';
                $requete->setParameter('chiffres', '%'.$chiffres.'%');
            }
            $requete->andWhere($conditions);
        }

        $total = (int) (clone $requete)->select('COUNT(m.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $membres = $requete->setFirstResult(max(0, $page - 1) * $parPage)->setMaxResults($parPage)->getQuery()->getResult();

        return ['membres' => $membres, 'total' => $total];
    }

    /** @return array<string, int> indexé par valeur de statut, toutes valeurs présentes */
    public function compterParStatut(Ville $ville): array
    {
        $compteurs = [];
        foreach (MembreStatut::cases() as $statut) {
            $compteurs[$statut->value] = 0;
        }
        $lignes = $this->createQueryBuilder('m')
            ->select('m.statut AS statut, COUNT(m.id) AS nombre')
            ->andWhere('m.ville = :ville')
            ->setParameter('ville', $ville)
            ->groupBy('m.statut')
            ->getQuery()
            ->getArrayResult();
        foreach ($lignes as $ligne) {
            $statut = $ligne['statut'] instanceof MembreStatut ? $ligne['statut']->value : (string) $ligne['statut'];
            $compteurs[$statut] = (int) $ligne['nombre'];
        }

        return $compteurs;
    }

    public function emailPris(Ville $ville, string $email): bool
    {
        return null !== $this->findOneBy(['ville' => $ville, 'email' => $email]);
    }

    public function compterSansEmail(Ville $ville): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.ville = :ville')
            ->andWhere('m.email IS NULL')
            ->setParameter('ville', $ville)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Les repères d'unicité de la ville, pour l'import : les e-mails déjà pris (avec la personne, « prénom|nom » en
     * minuscules) et les noms complets normalisés (avec leur e-mail).
     *
     * @return array{emails: array<string, string>, noms: array<string, ?string>, ids: array<string, int>, annees: array<string, list<int>>, historiques: array<string, list<int>>} historiques : les années dont l'adhésion porte déjà les mois du classeur
     */
    public function reperesPourImport(Ville $ville): array
    {
        if (null === $ville->getId()) {
            // Une ville que l'import va créer : elle n'a encore aucun membre.
            return ['emails' => [], 'noms' => [], 'ids' => [], 'annees' => [], 'historiques' => []];
        }
        $emails = [];
        $noms = [];
        $ids = [];
        $annees = [];
        $historiques = [];
        $lignes = $this->createQueryBuilder('m')
            ->select('m.id, m.prenom, m.nom, m.email, a.annee, a.mois')
            ->leftJoin('m.adhesions', 'a')
            ->andWhere('m.ville = :ville')
            ->setParameter('ville', $ville)
            ->getQuery()
            ->getArrayResult();
        foreach ($lignes as $ligne) {
            $cle = mb_strtolower($ligne['prenom'].'|'.$ligne['nom']);
            if (null !== $ligne['email']) {
                $emails[$ligne['email']] = $cle;
            }
            $noms[$cle] = $ligne['email'];
            $ids[$cle] = (int) $ligne['id'];
            $annees[$cle] ??= [];
            if (null !== $ligne['annee']) {
                $annees[$cle][] = (int) $ligne['annee'];
                if (null !== $ligne['mois']) {
                    $historiques[$cle][] = (int) $ligne['annee'];
                }
            }
        }

        return ['emails' => $emails, 'noms' => $noms, 'ids' => $ids, 'annees' => $annees, 'historiques' => $historiques];
    }

    /**
     * Les membres de toute l'association (vue du bureau central), par ville puis par nom, filtrés par ville, année
     * d'adhésion (« adhérent en 2026 »), statut et recherche, paginés.
     *
     * @return array{membres: list<Membre>, total: int}
     */
    public function rechercherPourAssociation(Association $association, ?Ville $ville, ?int $annee, string $recherche, ?MembreStatut $statut, int $page, int $parPage): array
    {
        $requete = $this->requetePourAssociation($association, $ville, $annee)
            ->innerJoin('m.ville', 'v')
            ->addSelect('v')
            ->leftJoin('m.foyer', 'f')
            ->addSelect('f')
            ->orderBy('v.nom', 'ASC')
            ->addOrderBy('m.nom', 'ASC')
            ->addOrderBy('m.prenom', 'ASC');
        if (null !== $statut) {
            $requete->andWhere('m.statut = :statut')->setParameter('statut', $statut);
        }
        $recherche = trim($recherche);
        if ('' !== $recherche) {
            $conditions = 'LOWER(m.nom) LIKE :aiguille OR LOWER(m.prenom) LIKE :aiguille OR LOWER(m.email) LIKE :aiguille OR LOWER(m.localite) LIKE :aiguille OR LOWER(f.nom) LIKE :aiguille';
            $requete->setParameter('aiguille', '%'.mb_strtolower($recherche).'%');
            $chiffres = (string) preg_replace('/\D+/', '', $recherche);
            if ('' !== $chiffres) {
                $conditions .= ' OR m.telephone LIKE :chiffres';
                $requete->setParameter('chiffres', '%'.$chiffres.'%');
            }
            $requete->andWhere($conditions);
        }

        $total = (int) (clone $requete)->select('COUNT(m.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $membres = $requete->setFirstResult(max(0, $page - 1) * $parPage)->setMaxResults($parPage)->getQuery()->getResult();

        return ['membres' => $membres, 'total' => $total];
    }

    /** @return array<int, int> identifiant de ville => membres (adhérents de l'année si elle est donnée) */
    public function compterParVille(Association $association, ?int $annee): array
    {
        $lignes = $this->requetePourAssociation($association, null, $annee)
            ->select('IDENTITY(m.ville) AS ville, COUNT(m.id) AS nombre')
            ->groupBy('m.ville')
            ->getQuery()
            ->getArrayResult();
        $parVille = [];
        foreach ($lignes as $ligne) {
            $parVille[(int) $ligne['ville']] = (int) $ligne['nombre'];
        }

        return $parVille;
    }

    /** @return array<int, int> année => adhérents de l'association (ou de la ville), de la plus récente à la plus ancienne */
    public function compterParAnnee(Association $association, ?Ville $ville): array
    {
        $requete = $this->getEntityManager()->createQueryBuilder()
            ->select('a.annee AS annee, COUNT(a.id) AS nombre')
            ->from(Adhesion::class, 'a')
            ->andWhere('a.association = :association')
            ->setParameter('association', $association)
            ->groupBy('a.annee')
            ->orderBy('a.annee', 'DESC');
        if (null !== $ville) {
            $requete->andWhere('a.ville = :ville')->setParameter('ville', $ville);
        }
        $parAnnee = [];
        foreach ($requete->getQuery()->getArrayResult() as $ligne) {
            $parAnnee[(int) $ligne['annee']] = (int) $ligne['nombre'];
        }

        return $parAnnee;
    }

    /**
     * Les chiffres de la sélection : nouveaux (première année d'adhésion = l'année choisie), sans e-mail ni téléphone,
     * villes représentées.
     *
     * @return array{nouveaux: ?int, sans_contact: int, villes: int}
     */
    public function statistiquesPourAssociation(Association $association, ?Ville $ville, ?int $annee): array
    {
        $nouveaux = null;
        if (null !== $annee) {
            $nouveaux = (int) $this->requetePourAssociation($association, $ville, $annee)
                ->select('COUNT(m.id)')
                ->andWhere('(SELECT MIN(a2.annee) FROM '.Adhesion::class.' a2 WHERE a2.membre = m) = :annee')
                ->getQuery()
                ->getSingleScalarResult();
        }
        $sansContact = (int) $this->requetePourAssociation($association, $ville, $annee)
            ->select('COUNT(m.id)')
            ->andWhere('m.email IS NULL')
            ->andWhere('m.telephone IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
        $villes = (int) $this->requetePourAssociation($association, $ville, $annee)
            ->select('COUNT(DISTINCT m.ville)')
            ->getQuery()
            ->getSingleScalarResult();

        return ['nouveaux' => $nouveaux, 'sans_contact' => $sansContact, 'villes' => $villes];
    }

    /**
     * Les années d'adhésion de ces membres, en une requête (pas une par ligne du tableau).
     *
     * @param list<Membre> $membres
     *
     * @return array<int, list<int>> identifiant du membre => années croissantes
     */
    public function anneesAdhesion(array $membres): array
    {
        $annees = [];
        if ([] === $membres) {
            return $annees;
        }
        $lignes = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(a.membre) AS membre, a.annee AS annee')
            ->from(Adhesion::class, 'a')
            ->andWhere('a.membre IN (:membres)')
            ->setParameter('membres', $membres)
            ->orderBy('a.annee', 'ASC')
            ->getQuery()
            ->getArrayResult();
        foreach ($lignes as $ligne) {
            $annees[(int) $ligne['membre']][] = (int) $ligne['annee'];
        }

        return $annees;
    }

    private function requetePourAssociation(Association $association, ?Ville $ville, ?int $annee): \Doctrine\ORM\QueryBuilder
    {
        $requete = $this->createQueryBuilder('m')
            ->andWhere('m.association = :association')
            ->setParameter('association', $association);
        if (null !== $ville) {
            $requete->andWhere('m.ville = :ville')->setParameter('ville', $ville);
        }
        if (null !== $annee) {
            $requete->andWhere('EXISTS (SELECT a.id FROM '.Adhesion::class.' a WHERE a.membre = m AND a.annee = :annee)')->setParameter('annee', $annee);
        }

        return $requete;
    }
}
