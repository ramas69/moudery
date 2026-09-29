<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Association\ParametresAssociation;
use App\Entity\Association;
use App\Entity\Utilisateur;
use App\Form\Model\ParametresAssociationData;
use App\Form\ParametresAssociationType;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Onglet « Association » des Paramètres : le bureau central règle contact, exercice, reversement par défaut et relances. */
#[Route('/associations/{slug}/parametres', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ASSOCIATION_PILOTER, subject: 'association')]
final class ParametresController extends AbstractController
{
    #[Route('', name: 'association_parametres', methods: ['GET', 'POST'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request, ParametresAssociation $parametres, TranslatorInterface $traducteur): Response
    {
        if ($this->isGranted('IS_IMPERSONATOR')) {
            throw $this->createAccessDeniedException('Les paramètres ne se modifient pas sous emprunt d’identité.');
        }

        $donnees = ParametresAssociationData::depuis($association);
        $form = $this->createForm(ParametresAssociationType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $acteur = $this->getUser();
            $parametres->modifier($association, $donnees, $acteur instanceof Utilisateur ? $acteur : null);
            $this->addFlash('succes', $traducteur->trans('parametres_association.enregistre'));

            return $this->redirectToRoute('association_parametres', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('association/parametres/index.html.twig', [
            'association' => $association,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
