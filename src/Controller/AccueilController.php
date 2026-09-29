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
 * Page d'entrée : une personne non connectée voit la présentation publique ; après connexion, le super-admin va à l'administration de la plateforme, le bureau central au tableau
 * de bord de son association, un responsable de ville à l'espace cadré sur sa ville, un membre à son espace (F-26) ;
 * un compte sans fiche de membre voit une page d'attente.
 */
final class AccueilController extends AbstractController
{
    public function __construct(private readonly \App\Membre\EspaceMembre $espaceMembre)
    {
    }

    #[Route('/', name: 'accueil', methods: ['GET'])]
    public function index(): Response
    {
        $utilisateur = $this->getUser();
        // Une personne non connectée découvre Caisses (page publique, 29 septembre 2026).
        if (!$utilisateur instanceof Utilisateur) {
            return $this->render('accueil/presentation.html.twig');
        }

        // Un super-admin, même s'il est aussi bureau central d'une association, commence par l'administration.
        if ($utilisateur->aLaPermissionSurLaPlateforme(Permission::PLATEFORME_ADMINISTRER)) {
            return $this->redirectToRoute('administration_accueil');
        }

        $central = $utilisateur->premiereAffectation(Role::BureauCentral);
        if (null !== $central?->getAssociation()) {
            return $this->redirectToRoute('association_tableau_de_bord', ['slug' => $central->getAssociation()->getSlug()]);
        }

        // Un responsable de ville (trésorier, président, secrétaire) arrive sur l'espace de son association, cadré sur sa ville.
        foreach ($utilisateur->getAffectations() as $affectation) {
            $ville = $affectation->getVille();
            if (null !== $ville && $affectation->getRole()->accorde(Permission::VILLE_CONSULTER) && $ville->getAssociation()->estActive()) {
                return $this->redirectToRoute('association_tableau_de_bord', ['slug' => $ville->getAssociation()->getSlug(), 'ville' => $ville->getId()]);
            }
        }

        // Un membre (sans rôle de responsable) arrive sur son espace (F-26).
        if (null !== $this->espaceMembre->ficheDe($utilisateur)) {
            return $this->redirectToRoute('mon_espace');
        }

        return $this->render('accueil/index.html.twig');
    }
}
