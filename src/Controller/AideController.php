<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Security\Permission;
use App\Security\Role;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Guide d'utilisation (« Aide ») : une page publique qui décrit tout, vue d'ensemble, tutoriel de chaque rôle avec des
 * captures d'écran prises sur une ville fictive, glossaire. Connecté, on la lit dans sa coquille (administration, espace
 * de l'association, coquille simple) avec son guide proposé en premier ; sans compte, dans une coquille publique.
 */
final class AideController extends AbstractController
{
    #[Route('/aide', name: 'aide', methods: ['GET'])]
    public function index(): Response
    {
        $compte = $this->getUser();
        if (!$compte instanceof Utilisateur) {
            return $this->render('aide/index.html.twig', ['coquille' => 'aide/_public.html.twig', 'association' => null, 'guideLecteur' => null]);
        }
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
