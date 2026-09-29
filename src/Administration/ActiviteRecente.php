<?php

declare(strict_types=1);

namespace App\Administration;

use App\Repository\AssociationRepository;
use App\Repository\InvitationRepository;
use App\Repository\UtilisateurRepository;

/**
 * Le fil d'activité du tableau de bord : dernières connexions, comptes créés, invitations acceptées,
 * associations créées. Les types d'événements s'enrichiront avec les modules suivants (paiements, dépenses…).
 *
 * @phpstan-type Evenement array{quand: \DateTimeImmutable, type: string, compte: ?\App\Entity\Utilisateur, association: ?\App\Entity\Association}
 */
final class ActiviteRecente
{
    public function __construct(
        private readonly UtilisateurRepository $utilisateurs,
        private readonly AssociationRepository $associations,
        private readonly InvitationRepository $invitations,
    ) {
    }

    /** @return list<Evenement> les plus récents d'abord */
    public function derniers(int $limite = 8): array
    {
        $evenements = [];
        foreach ($this->utilisateurs->derniersConnectes($limite) as $compte) {
            $evenements[] = ['quand' => $compte->getDerniereConnexionLe(), 'type' => 'connexion', 'compte' => $compte, 'association' => $compte->getAssociation()];
        }
        foreach ($this->utilisateurs->derniersCrees($limite) as $compte) {
            $evenements[] = ['quand' => $compte->getCreeLe(), 'type' => 'compte_cree', 'compte' => $compte, 'association' => $compte->getAssociation()];
        }
        foreach ($this->invitations->dernieresAcceptees($limite) as $invitation) {
            $evenements[] = ['quand' => $invitation->getAccepteeLe(), 'type' => 'invitation_acceptee', 'compte' => $invitation->getCompte(), 'association' => $invitation->getAssociation()];
        }
        foreach ($this->associations->dernieresCreees($limite) as $association) {
            $evenements[] = ['quand' => $association->getCreeLe(), 'type' => 'association_creee', 'compte' => null, 'association' => $association];
        }

        $evenements = array_values(array_filter($evenements, static fn (array $e): bool => null !== $e['quand']));
        usort($evenements, static fn (array $a, array $b): int => $b['quand'] <=> $a['quand']);

        return \array_slice($evenements, 0, $limite);
    }
}
