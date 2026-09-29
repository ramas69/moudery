<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Association;
use App\Entity\Cotisation;
use App\Entity\Ville;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Cotisation> */
final class CotisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cotisation::class);
    }

    public function pourVilleEtAnnee(Ville $ville, int $annee): ?Cotisation
    {
        return $this->findOneBy(['ville' => $ville, 'annee' => $annee]);
    }

    /** @return list<Cotisation> les cotisations de l'association, les plus récentes d'abord */
    public function listerPour(Association $association, ?Ville $ville = null): array
    {
        $criteres = ['association' => $association];
        if (null !== $ville) {
            $criteres['ville'] = $ville;
        }

        return $this->findBy($criteres, ['annee' => 'DESC', 'id' => 'DESC']);
    }
}
