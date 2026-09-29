<?php

declare(strict_types=1);

namespace App\Controller;

use App\Abonnement\Catalogue;
use App\Compte\Invitations;
use App\Entity\Abonnement;
use App\Form\ActivationCompteType;
use App\Form\Model\ActivationCompteData;
use App\Form\Model\SouscriptionData;
use App\Form\SouscriptionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Activation d'un compte depuis le lien reçu par email (F-02). Page publique : le jeton fait foi.
 * Le bureau central d'une association qui n'a pas encore souscrit choisit son offre en même temps.
 */
final class InvitationController extends AbstractController
{
    #[Route('/invitation/{jeton}', name: 'invitation_activer', requirements: ['jeton' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function activer(string $jeton, Request $request, Invitations $invitations, Security $securite, TranslatorInterface $traducteur): Response
    {
        $invitation = $invitations->pourJeton($jeton);
        if (null === $invitation) {
            throw $this->createNotFoundException('Invitation inconnue.');
        }

        $maintenant = new \DateTimeImmutable();
        if (!$invitation->estValide($maintenant)) {
            return $this->render('invitation/expiree.html.twig', ['invitation' => $invitation, 'jeton' => $jeton], new Response(status: Response::HTTP_GONE));
        }

        $souscription = $invitation->ouvreLaSouscription();
        $donnees = $souscription ? new SouscriptionData() : new ActivationCompteData();
        $form = $this->createForm($souscription ? SouscriptionType::class : ActivationCompteType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $association = $invitation->getAssociation();
            if ($donnees instanceof SouscriptionData) {
                $compte = $invitations->souscrire($invitation, $donnees);
                $abonnement = $association->getAbonnement();
                $this->addFlash('succes', $traducteur->trans('invitation.souscrite', [
                    'association' => $association->getNom(),
                    'formule' => $abonnement?->getFormule() ?? '',
                    'date' => $abonnement?->getProchaineEcheanceLe()?->format('d/m/Y') ?? '',
                ]));
            } else {
                $compte = $invitations->accepter($invitation, $donnees);
                $this->addFlash('succes', $traducteur->trans('invitation.activee', ['association' => $association->getNom()]));
            }
            $securite->login($compte, 'form_login', 'main');

            return $this->redirectToRoute('accueil', [], Response::HTTP_SEE_OTHER);
        }

        $statut = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render($souscription ? 'invitation/souscrire.html.twig' : 'invitation/activer.html.twig', [
            'invitation' => $invitation,
            'form' => $form,
            'offres' => Catalogue::parCode(),
            'economie' => Catalogue::economieAnnuelle(),
            'joursPremierPaiement' => Abonnement::JOURS_PREMIER_PAIEMENT,
            'membresDeLaVille' => null !== $invitation->getVille() ? \count($invitation->getVille()->getMembres()) : null,
        ], new Response(status: $statut));
    }

    /** Lien expiré : la personne demande un nouveau lien à qui l'a invitée (une fois par jour au plus). */
    #[Route('/invitation/{jeton}/nouveau-lien', name: 'invitation_nouveau_lien', requirements: ['jeton' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function nouveauLien(string $jeton, Request $request, Invitations $invitations, TranslatorInterface $traducteur): Response
    {
        $invitation = $invitations->pourJeton($jeton);
        if (null === $invitation) {
            throw $this->createNotFoundException('Invitation inconnue.');
        }
        if (!$this->isCsrfTokenValid('nouveau-lien-'.$jeton, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $maintenant = new \DateTimeImmutable();
        if ($invitation->estAcceptee()) {
            return $this->redirectToRoute('connexion', [], Response::HTTP_SEE_OTHER);
        }
        if ($invitation->estValide($maintenant)) {
            return $this->redirectToRoute('invitation_activer', ['jeton' => $jeton], Response::HTTP_SEE_OTHER);
        }

        $envoyee = $invitations->demanderNouveauLien($invitation, $maintenant);

        return $this->render('invitation/expiree.html.twig', [
            'invitation' => $invitation,
            'jeton' => $jeton,
            'demande' => $envoyee ? 'envoyee' : 'deja',
        ], new Response(status: Response::HTTP_GONE));
    }
}
