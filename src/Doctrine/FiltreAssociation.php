<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Association;
use App\Entity\Utilisateur;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Filtre multi-tenant (cahier des charges : « une fuite entre deux associations est le risque le plus grave »). Activé
 * pour toute requête d'une personne rattachée à une association, il ajoute `association_id = <la sienne>` à chaque
 * requête Doctrine sur une entité qui porte une association. Les Voters restent la première barrière ; ce filtre est
 * le filet de sécurité qui rend une fuite impossible même si un contrôleur oubliait de vérifier.
 */
final class FiltreAssociation extends SQLFilter
{
    public const string NOM = 'association';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        // Les comptes ne sont pas filtrés : le super-admin (sans association) et les personnes d'autres associations
        // apparaissent légitimement comme acteurs du journal, auteurs d'une invitation ou d'un paiement.
        if (Utilisateur::class === $targetEntity->getName() || !$targetEntity->hasAssociation('association')) {
            return '';
        }
        $association = $targetEntity->getAssociationMapping('association');
        if (Association::class !== $association->targetEntity) {
            return '';
        }
        $colonne = 'association_id';
        if (isset($association->joinColumns[0])) {
            $colonne = $association->joinColumns[0]->name ?? 'association_id';
        }

        return \sprintf('%s.%s = %s', $targetTableAlias, $colonne, $this->getParameter('association'));
    }
}
