<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Administration\GestionEquipe;
use App\Administration\TableauComptes;
use App\Entity\Utilisateur;
use App\Form\EquipeInvitationType;
use App\Form\Model\EquipeInvitationData;
use App\Form\Model\PromotionSuperAdminData;
use App\Form\PromotionSuperAdminType;
use App\Security\Permission;
use App\Security\Role;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Équipe de la plateforme : les super-admins, avec ou sans association. Le super-admin y ajoute un collègue,
 * promeut un compte existant et renvoie un lien de mot de passe ; retirer quelqu'un passe par sa fiche de compte.
 */
#[Route('/administration/equipe')]
#[IsGranted(Permission::PLATEFORME_ADMINISTRER)]
final class EquipeController extends AbstractController
{
    public function __construct(
        private readonly GestionEquipe $gestion,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'administration_equipe', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->rendreIndex($this->formulairePromotion($this->gestion->candidats()), trim((string) $request->query->get('q')));
    }

    #[Route('/ajouter', name: 'administration_equipe_ajouter', methods: ['GET', 'POST'])]
    public function ajouter(Request $request): Response
    {
        $donnees = new EquipeInvitationData();
        $form = $this->createForm(EquipeInvitationType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $par = $this->getUser();
            $compte = $this->gestion->ajouter($donnees, $par instanceof Utilisateur ? $par : null);
            $this->addFlash('succes', $this->traducteur->trans('administration.equipe.ajoute', ['nom' => $compte->getNomComplet(), 'email' => $compte->getEmail()]));

            return $this->redirectToRoute('administration_equipe', [], Response::HTTP_SEE_OTHER);
        }

        $statut = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('administration/equipe/ajouter.html.twig', ['form' => $form], new Response(status: $statut));
    }

    /** Un compte existant, par exemple le bureau central d'une association, devient aussi super-admin. */
    #[Route('/promouvoir', name: 'administration_equipe_promouvoir', methods: ['POST'])]
    public function promouvoir(Request $request): Response
    {
        $form = $this->formulairePromotion($this->gestion->candidats());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            \assert($donnees instanceof PromotionSuperAdminData && null !== $donnees->compte);
            $this->gestion->promouvoir($donnees->compte);
            $this->addFlash('succes', $this->traducteur->trans('administration.equipe.promu', ['nom' => $donnees->compte->getNomComplet()]));

            return $this->redirectToRoute('administration_equipe', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendreIndex($form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** Nouveau lien de 7 jours pour un collègue qui n'a pas encore choisi son mot de passe. */
    #[Route('/{id}/renvoyer-le-lien', name: 'administration_equipe_renvoyer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function renvoyer(#[MapEntity] Utilisateur $compte, Request $request): Response
    {
        if (!$compte->aLeRole(Role::SuperAdmin)) {
            throw $this->createNotFoundException('Ce compte ne fait pas partie de l\'équipe de la plateforme.');
        }
        if (!$this->isCsrfTokenValid('equipe-lien-'.$compte->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }

        if (!$compte->estActif()) {
            $this->addFlash('erreur', $this->traducteur->trans('administration.equipe.lien_impossible', ['nom' => $compte->getNomComplet()]));
        } else {
            $par = $this->getUser();
            $this->gestion->envoyerLien($compte, $par instanceof Utilisateur ? $par : null);
            $this->addFlash('succes', $this->traducteur->trans('administration.equipe.lien_envoye', ['email' => $compte->getEmail()]));
        }

        return $this->redirectToRoute('administration_equipe', [], Response::HTTP_SEE_OTHER);
    }

    /** @param list<Utilisateur> $candidats */
    private function formulairePromotion(array $candidats): FormInterface
    {
        return $this->createForm(PromotionSuperAdminType::class, new PromotionSuperAdminData(), [
            'candidats' => $candidats,
            'action' => $this->generateUrl('administration_equipe_promouvoir'),
        ]);
    }

    private function rendreIndex(FormInterface $formPromotion, string $recherche = '', int $statut = Response::HTTP_OK): Response
    {
        return $this->render('administration/equipe/index.html.twig', [
            'membres' => TableauComptes::filtrer($this->gestion->membres(), $recherche),
            'formPromotion' => $formPromotion,
            'aucunCandidat' => [] === $formPromotion->getConfig()->getOption('candidats'),
            'recherche' => $recherche,
        ], new Response(status: $statut));
    }
}
