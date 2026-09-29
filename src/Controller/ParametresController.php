<?php

declare(strict_types=1);

namespace App\Controller;

use App\Compte\Parametres;
use App\Entity\Utilisateur;
use App\Form\ChangementMotDePasseType;
use App\Form\Model\ChangementMotDePasseData;
use App\Form\Model\ProfilData;
use App\Form\ProfilType;
use App\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Paramètres du compte connecté (F-04), depuis le menu du compte : identité et adresse, mot de passe.
 * La page s'affiche dans la coquille de la personne (administration, espace de l'association, ou coquille simple).
 * Fermée pendant un emprunt d'identité : on ne modifie pas le compte de quelqu'un d'autre à sa place.
 */
#[Route('/parametres')]
final class ParametresController extends AbstractController
{
    public function __construct(
        private readonly Parametres $parametres,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'parametres', methods: ['GET'])]
    public function index(): Response
    {
        $moi = $this->moi();

        return $this->rendre($moi, $this->formulaireProfil($moi), $this->formulaireMotDePasse());
    }

    #[Route('/profil', name: 'parametres_profil', methods: ['POST'])]
    public function profil(Request $request): Response
    {
        $moi = $this->moi();
        $form = $this->formulaireProfil($moi);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            \assert($donnees instanceof ProfilData);
            $this->parametres->modifierProfil($moi, $donnees);
            $this->addFlash('succes', $this->traducteur->trans('parametres.profil.enregistre'));

            return $this->redirectToRoute('parametres', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendre($moi, $form, $this->formulaireMotDePasse(), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/mot-de-passe', name: 'parametres_mot_de_passe', methods: ['POST'])]
    public function motDePasse(Request $request): Response
    {
        $moi = $this->moi();
        $form = $this->formulaireMotDePasse();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            \assert($donnees instanceof ChangementMotDePasseData);
            $this->parametres->changerMotDePasse($moi, (string) $donnees->motDePasse);
            $this->addFlash('succes', $this->traducteur->trans('parametres.mot_de_passe.change'));

            return $this->redirectToRoute('parametres', [], Response::HTTP_SEE_OTHER);
        }

        return $this->rendre($moi, $this->formulaireProfil($moi), $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function moi(): Utilisateur
    {
        if ($this->isGranted('IS_IMPERSONATOR')) {
            throw $this->createAccessDeniedException('Pas de paramètres pendant un emprunt d’identité.');
        }

        $utilisateur = $this->getUser();
        \assert($utilisateur instanceof Utilisateur);

        return $utilisateur;
    }

    private function formulaireProfil(Utilisateur $moi): FormInterface
    {
        return $this->createForm(ProfilType::class, ProfilData::depuis($moi), ['action' => $this->generateUrl('parametres_profil')]);
    }

    private function formulaireMotDePasse(): FormInterface
    {
        return $this->createForm(ChangementMotDePasseType::class, new ChangementMotDePasseData(), ['action' => $this->generateUrl('parametres_mot_de_passe')]);
    }

    /** La coquille suit la personne : l'administration pour un super-admin, l'espace de son association pour qui peut l'ouvrir, une coquille simple sinon (un membre, dont l'espace est /mon-espace). */
    private function rendre(Utilisateur $moi, FormInterface $formProfil, FormInterface $formMotDePasse, int $statut = Response::HTTP_OK): Response
    {
        $association = $moi->getAssociation();
        $coquille = match (true) {
            $this->isGranted(Permission::PLATEFORME_ADMINISTRER) => 'administration/_layout.html.twig',
            null !== $association && $this->isGranted(Permission::ESPACE_OUVRIR, $association) => 'association/_layout.html.twig',
            default => 'parametres/_simple.html.twig',
        };

        return $this->render('parametres/index.html.twig', [
            'coquille' => $coquille,
            'association' => $association,
            'formProfil' => $formProfil,
            'formMotDePasse' => $formMotDePasse,
        ], new Response(status: $statut));
    }
}
