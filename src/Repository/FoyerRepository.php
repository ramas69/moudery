<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Foyer;
use App\Entity\Ville;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Foyer> */
final class FoyerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Foyer::class);
    }

    /** @return list<Foyer> par nom */
    public function listerPourVille(Ville $ville): array
    {
        return $this->findBy(['ville' => $ville], ['nom' => 'ASC']);
    }

    public function trouverParNom(Ville $ville, string $nom): ?Foyer
    {
        return $this->findOneBy(['ville' => $ville, 'nom' => \App\Entity\Membre::normaliserNom($nom)]);
    }
}
