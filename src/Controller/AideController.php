<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Security\Permission;
use App\Security\Role;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Guide d'utilisation (« Aide ») : une page qui décrit tout, vue d'ensemble, tutoriel de chaque rôle avec des captures
 * d'écran prises sur une ville fictive, glossaire ; dans la coquille de la personne (administration, espace de
 * l'association, coquille simple). Tout le monde voit les trois guides, le sien est proposé en premier.
 */
final class AideController extends AbstractController
{
    #[Route('/aide', name: 'aide', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function index(): Response
    {
        $compte = $this->getUser();
        \assert($compte instanceof Utilisateur);
        $association = $compte->getAssociation();
        $superAdmin = $this->isGranted(Permission::PLATEFORME_ADMINISTRER);
        $pilote = null !== $association && $this->isGranted(Permission::ASSOCIATION_PILOTER, $association);
        $responsable = $compte->aLeRole(Role::Tresorier) || $compte->aLeRole(Role::President) || $compte->aLeRole(Role::Secretaire);

        return $this->render('aide/index.html.twig', [
            'coquille' => match (true) {
                $superAdmin => 'administration/_layout.html.twig',
                null !== $association && $this->isGranted(Permission::ESPACE_OUVRIR, $association) => 'association/_layout.html.twig',
                default => 'parametres/_simple.html.twig',
            },
            'association' => $association,
            'guideLecteur' => match (true) {
                $superAdmin || $pilote => 'central',
                $responsable => 'ville',
                default => 'membre',
            },
        ]);
    }
}
