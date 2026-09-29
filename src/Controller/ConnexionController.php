<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\Model\MotDePasseOublieData;
use App\Form\Model\NouveauMotDePasseData;
use App\Form\MotDePasseOublieType;
use App\Form\NouveauMotDePasseType;
use App\Security\ReinitialisationMotDePasse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Connexion, déconnexion et mot de passe oublié (M1, F-03).
 * La connexion elle-même est traitée par le pare-feu (form_login) ; le contrôleur affiche le formulaire et les erreurs.
 */
final class ConnexionController extends AbstractController
{
    public function __construct(private readonly TranslatorInterface $traducteur)
    {
    }

    #[Route('/connexion', name: 'connexion', methods: ['GET', 'POST'])]
    public function connexion(AuthenticationUtils $authentification): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('accueil');
        }

        return $this->render('securite/connexion.html.twig', [
            'erreur' => $authentification->getLastAuthenticationError(),
            'dernierEmail' => $authentification->getLastUsername(),
        ]);
    }

    /** Interceptée par le pare-feu (logout) : le corps ne s'exécute jamais. */
    #[Route('/deconnexion', name: 'deconnexion', methods: ['POST'])]
    public function deconnexion(): never
    {
        throw new \LogicException('La déconnexion est prise en charge par le pare-feu.');
    }

    #[Route('/mot-de-passe-oublie', name: 'mot_de_passe_oublie', methods: ['GET', 'POST'])]
    public function motDePasseOublie(
        Request $request,
        ReinitialisationMotDePasse $reinitialisation,
        RateLimiterFactoryInterface $motDePasseOublieLimiter,
    ): Response {
        $donnees = new MotDePasseOublieData();
        $form = $this->createForm(MotDePasseOublieType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Limite les envois par adresse IP : pas de bombardement d'emails.
            if (!$motDePasseOublieLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError($this->traducteur->trans('mot_de_passe_oublie.trop_de_demandes')));
            } else {
                $reinitialisation->demander((string) $donnees->email);

                return $this->redirectToRoute('mot_de_passe_oublie_envoye', [], Response::HTTP_SEE_OTHER);
            }
        }

        $statut = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('securite/mot_de_passe_oublie.html.twig', ['form' => $form], new Response(status: $statut));
    }

    #[Route('/mot-de-passe-oublie/envoye', name: 'mot_de_passe_oublie_envoye', methods: ['GET'])]
    public function motDePasseOublieEnvoye(): Response
    {
        return $this->render('securite/mot_de_passe_oublie_envoye.html.twig');
    }

    #[Route('/mot-de-passe/reinitialiser/{jeton}', name: 'reinitialisation_mot_de_passe', requirements: ['jeton' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function reinitialiser(string $jeton, Request $request, ReinitialisationMotDePasse $reinitialisation): Response
    {
        $utilisateur = $reinitialisation->utilisateurPourJeton($jeton);
        if (null === $utilisateur) {
            $this->addFlash('erreur', 'mot_de_passe_oublie.lien_invalide');

            return $this->redirectToRoute('mot_de_passe_oublie', [], Response::HTTP_SEE_OTHER);
        }

        $donnees = new NouveauMotDePasseData();
        $form = $this->createForm(NouveauMotDePasseType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $reinitialisation->reinitialiser($utilisateur, (string) $donnees->motDePasse);
            $this->addFlash('succes', 'reinitialisation.reussie');

            return $this->redirectToRoute('connexion', [], Response::HTTP_SEE_OTHER);
        }

        $statut = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('securite/reinitialiser.html.twig', ['form' => $form], new Response(status: $statut));
    }
}
