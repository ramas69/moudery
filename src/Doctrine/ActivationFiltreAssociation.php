<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Utilisateur;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Active le filtre multi-tenant dès que la personne connectée appartient à une association et n'administre pas la
 * plateforme (un super-admin voit tout, même s'il est aussi rattaché à une association). Sous emprunt d'identité,
 * c'est l'association de la personne empruntée qui compte. Hors requête (commandes, Messenger), rien n'est filtré.
 * Priorité 7 : juste après le pare-feu (8), qui a posé le jeton.
 */
#[AsEventListener(event: RequestEvent::class, priority: 7)]
#[AsEventListener(event: FinishRequestEvent::class, method: 'desactiver')]
final class ActivationFiltreAssociation
{
    public function __construct(
        private readonly Security $securite,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RequestEvent $evenement): void
    {
        if (!$evenement->isMainRequest()) {
            return;
        }
        $utilisateur = $this->securite->getUser();
        $filtres = $this->entityManager->getFilters();
        if (!$utilisateur instanceof Utilisateur || null === $utilisateur->getAssociation() || $utilisateur->aLeRole(Role::SuperAdmin)) {
            if ($filtres->isEnabled(FiltreAssociation::NOM)) {
                $filtres->disable(FiltreAssociation::NOM);
            }

            return;
        }
        $filtres->enable(FiltreAssociation::NOM)->setParameter('association', (int) $utilisateur->getAssociation()->getId());
    }

    /** La requête est finie : le gestionnaire d'entités redevient neutre (tests, processus longs). */
    public function desactiver(FinishRequestEvent $evenement): void
    {
        if (!$evenement->isMainRequest()) {
            return;
        }
        $filtres = $this->entityManager->getFilters();
        if ($filtres->isEnabled(FiltreAssociation::NOM)) {
            $filtres->disable(FiltreAssociation::NOM);
        }
    }
}
