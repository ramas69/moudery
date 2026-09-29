<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Administration\VoirComme;
use App\Entity\Utilisateur;
use App\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Voir comme » : depuis le menu, le super-admin choisit une personne et voit l'application avec ses droits
 * (emprunt d'identité, option switch_user du pare-feu). L'emprunt lui-même se déclenche sur l'accueil avec le
 * paramètre _voir_comme ; le bandeau composants/emprunt.html.twig permet ensuite de changer de personne ou de revenir.
 */
#[Route('/administration/voir-comme')]
#[IsGranted(Permission::PLATEFORME_ADMINISTRER)]
final class VoirCommeController extends AbstractController
{
    public function __construct(
        private readonly VoirComme $voirComme,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'administration_voir_comme', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('administration/voir_comme/index.html.twig', [
            'personnes' => $this->voirComme->personnes($this->acteur()),
        ]);
    }

    /** Le sélecteur envoie un nom ou une adresse (texte libre sans JavaScript) : on le résout ici, puis l'accueil bascule. */
    #[Route('/aller', name: 'administration_voir_comme_aller', methods: ['GET'])]
    public function aller(Request $request): Response
    {
        $saisie = trim((string) $request->query->get('personne'));
        $personne = VoirComme::correspondante($this->voirComme->personnes($this->acteur()), $saisie);
        if (null === $personne) {
            $this->addFlash('erreur', $this->traducteur->trans('administration.voir_comme.introuvable', ['saisie' => $saisie]));

            return $this->redirectToRoute('administration_voir_comme');
        }

        return $this->redirectToRoute('accueil', ['_voir_comme' => $personne->getEmail()]);
    }

    private function acteur(): ?Utilisateur
    {
        $utilisateur = $this->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur : null;
    }
}
