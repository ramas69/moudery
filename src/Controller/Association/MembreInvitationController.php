<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Compte\Invitations;
use App\Entity\Association;
use App\Entity\Utilisateur;
use App\Repository\MembreRepository;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Inviter à son espace » depuis la fiche d'un membre (décision de Rama du 29 septembre 2026 : pas d'inscription libre,
 * le membre reçoit un lien de sa ville). Réservé à qui gère les membres de sa ville (MEMBRE_GERER) ; un membre d'une
 * autre association est introuvable.
 */
#[IsGranted(Permission::ESPACE_OUVRIR, subject: 'association')]
final class MembreInvitationController extends AbstractController
{
    #[Route('/associations/{slug}/membres/{id}/inviter', name: 'association_membre_inviter', requirements: ['slug' => '[a-z0-9-]+', 'id' => '\\d+'], methods: ['POST'])]
    public function inviter(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request, MembreRepository $membres, Invitations $invitations, TranslatorInterface $traducteur): Response
    {
        $membre = $membres->find($id);
        if (null === $membre || $membre->getAssociation() !== $association) {
            throw $this->createNotFoundException();
        }
        if (!$this->isGranted(Permission::MEMBRE_GERER, $membre->getVille()) || !$this->isCsrfTokenValid('inviter-membre-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $moi = $this->getUser();
        $invitation = $invitations->inviterMembre($membre, $moi instanceof Utilisateur ? $moi : null);
        $this->addFlash(null !== $invitation ? 'succes' : 'erreur', $traducteur->trans(null !== $invitation ? 'membre_invitation.envoyee' : 'membre_invitation.impossible', ['email' => (string) $membre->getEmail(), 'nom' => $membre->getNomComplet()]));

        return $this->redirectToRoute('association_membre', ['slug' => $association->getSlug(), 'id' => $id], Response::HTTP_SEE_OTHER);
    }

    /**
     * « Inviter à leur espace » depuis la barre de sélection de la page Membres : `membres[]` (identifiants), jeton
     * `inviter-membres`. Chaque membre est invité s'il a une adresse, pas de compte, et si la personne gère sa ville ;
     * sinon il est compté parmi les ignorés. Une invitation déjà en cours est renvoyée.
     */
    #[Route('/associations/{slug}/membres/inviter', name: 'association_membres_inviter', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    public function inviterPlusieurs(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request, MembreRepository $membres, Invitations $invitations, TranslatorInterface $traducteur): Response
    {
        if (!$this->isCsrfTokenValid('inviter-membres', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $moi = $this->getUser();
        $envoyees = 0;
        $ignores = 0;
        foreach (array_unique(array_map('intval', $request->request->all('membres'))) as $id) {
            $membre = $membres->find($id);
            if (null === $membre || $membre->getAssociation() !== $association || !$this->isGranted(Permission::MEMBRE_GERER, $membre->getVille())
                || null === $invitations->inviterMembre($membre, $moi instanceof Utilisateur ? $moi : null)) {
                ++$ignores;
                continue;
            }
            ++$envoyees;
        }
        $this->addFlash($envoyees > 0 ? 'succes' : 'erreur', $traducteur->trans('membre_invitation.bilan', ['envoyees' => $envoyees, 'ignores' => $ignores]));

        $retour = (string) $request->request->get('retour', '');

        return str_starts_with($retour, '/') && !str_starts_with($retour, '//')
            ? $this->redirect($retour, Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('association_membres', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }
}
