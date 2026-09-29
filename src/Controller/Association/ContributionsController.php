<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Association\TypesContribution;
use App\Entity\Association;
use App\Entity\TypeContribution;
use App\Entity\Utilisateur;
use App\Form\Model\TypeContributionData;
use App\Form\TypeContributionType;
use App\Repository\TypeContributionRepository;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Onglet « Types de contribution » des Paramètres (F-11) : liste, ajout, modification, archivage et réactivation. */
#[Route('/associations/{slug}/parametres/contributions', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ASSOCIATION_PILOTER, subject: 'association')]
final class ContributionsController extends AbstractController
{
    public function __construct(
        private readonly TypesContribution $service,
        private readonly TypeContributionRepository $types,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'association_contributions', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association): Response
    {
        return $this->render('association/contributions/index.html.twig', [
            'association' => $association,
            'types' => $this->types->listerPour($association),
            'appelsParType' => $this->types->nombreAppelsParType($association),
        ]);
    }

    #[Route('/nouveau', name: 'association_contribution_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $donnees = new TypeContributionData();
        $donnees->tauxReversement = $association->getTauxReversementDefaut() > 0 ? $association->getTauxReversementDefaut() : null;
        $form = $this->createForm(TypeContributionType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $type = $this->service->ajouter($association, $donnees, $this->compte());
            $this->addFlash('succes', $this->traducteur->trans('contributions_association.flash.ajoute', ['nom' => $type->getNom()]));

            return $this->redirectToRoute('association_contributions', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('association/contributions/formulaire.html.twig', [
            'association' => $association,
            'type' => null,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/modifier', name: 'association_contribution_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $type = $this->typeDe($association, $id);
        $donnees = TypeContributionData::depuis($type);
        $form = $this->createForm(TypeContributionType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->service->modifier($type, $donnees, $this->compte());
            $this->addFlash('succes', $this->traducteur->trans('contributions_association.flash.modifie', ['nom' => $type->getNom()]));

            return $this->redirectToRoute('association_contributions', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('association/contributions/formulaire.html.twig', [
            'association' => $association,
            'type' => $type,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/archiver', name: 'association_contribution_archiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archiver(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $type = $this->typeDe($association, $id);
        $this->verifierJeton('contribution-archiver-'.$id, $request);
        try {
            $this->service->archiver($type, $this->compte());
            $this->addFlash('succes', $this->traducteur->trans('contributions_association.flash.archive', ['nom' => $type->getNom()]));
        } catch (\LogicException) {
            $this->addFlash('erreur', $this->traducteur->trans('contributions_association.flash.dernier_actif'));
        }

        return $this->redirectToRoute('association_contributions', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/reactiver', name: 'association_contribution_reactiver', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reactiver(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $type = $this->typeDe($association, $id);
        $this->verifierJeton('contribution-reactiver-'.$id, $request);
        $this->service->reactiver($type, $this->compte());
        $this->addFlash('succes', $this->traducteur->trans('contributions_association.flash.reactive', ['nom' => $type->getNom()]));

        return $this->redirectToRoute('association_contributions', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }

    private function typeDe(Association $association, int $id): TypeContribution
    {
        $type = $this->types->find($id);
        if (!$type instanceof TypeContribution || $type->getAssociation() !== $association) {
            throw new NotFoundHttpException('Type de contribution introuvable.');
        }

        return $type;
    }

    private function verifierJeton(string $identifiant, Request $request): void
    {
        if (!$this->isCsrfTokenValid($identifiant, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
    }

    private function compte(): ?Utilisateur
    {
        $compte = $this->getUser();

        return $compte instanceof Utilisateur ? $compte : null;
    }
}
