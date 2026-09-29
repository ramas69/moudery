<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Administration\GestionComptes;
use App\Administration\TableauComptes;
use App\Compte\Invitations;
use App\Entity\Affectation;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Form\AffectationType;
use App\Form\CompteIdentiteType;
use App\Form\InvitationCompteType;
use App\Form\Model\AffectationData;
use App\Form\Model\CompteIdentiteData;
use App\Form\Model\InvitationCompteData;
use App\Journal\Journal;
use App\Repository\AssociationRepository;
use App\Repository\UtilisateurRepository;
use App\Security\Permission;
use App\Security\ReinitialisationMotDePasse;
use App\Security\Role;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Administration des comptes par le super-admin : liste, invitation (F-02), identité, statut, rôles et périmètres (F-07),
 * envoi d'un lien de mot de passe. Les fiches membres (foyer, téléphone, consentements) viendront avec le module M3.
 *
 * La fiche d'un compte est unique ; ouverte depuis l'équipe de la plateforme (paramètre « depuis=equipe »),
 * elle garde ce contexte : navigation, fil d'Ariane et retour après chaque action.
 */
#[Route('/administration/comptes')]
#[IsGranted(Permission::PLATEFORME_ADMINISTRER)]
final class CompteController extends AbstractController
{
    public function __construct(
        private readonly GestionComptes $gestion,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly AssociationRepository $associations,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'administration_comptes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filtreAssociation = $this->associations->trouverParSlug((string) $request->query->get('association'));
        $filtreStatut = UtilisateurStatut::tryFrom((string) $request->query->get('statut'));
        $recherche = trim((string) $request->query->get('q'));

        return $this->render('administration/compte/index.html.twig', [
            'comptes' => TableauComptes::filtrer($this->utilisateurs->listerTous($filtreAssociation, $filtreStatut), $recherche),
            'associations' => $this->associations->listerParNom(),
            'filtreAssociation' => $filtreAssociation,
            'filtreStatut' => $filtreStatut,
            'statuts' => UtilisateurStatut::cases(),
            'recherche' => $recherche,
        ]);
    }

    #[Route('/inviter', name: 'administration_compte_inviter', methods: ['GET', 'POST'])]
    public function inviter(Request $request, Invitations $invitations): Response
    {
        $donnees = new InvitationCompteData();
        $donnees->association = $this->associations->trouverParSlug((string) $request->query->get('association'));
        $form = $this->createForm(InvitationCompteType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $donnees->association && null !== $donnees->role);
            $this->denyAccessUnlessGranted(Permission::COMPTE_GERER, $donnees->association);

            $par = $this->getUser();
            $invitations->inviter($donnees->association, $donnees->role, (string) $donnees->email, $par instanceof Utilisateur ? $par : null, $donnees->ville);
            $this->addFlash('succes', $this->traducteur->trans('administration.compte.invitee', ['email' => Utilisateur::normaliserEmail($donnees->email)]));

            return $this->redirectToRoute('administration_comptes', [], Response::HTTP_SEE_OTHER);
        }

        $statut = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('administration/compte/inviter.html.twig', ['form' => $form], new Response(status: $statut));
    }

    #[Route('/{id}', name: 'administration_compte_fiche', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function fiche(#[MapEntity] Utilisateur $compte, Request $request): Response
    {
        $this->verifierDroitDeGestion($compte);
        $depuis = self::depuis($request);

        return $this->rendreFiche($compte, $this->formulaireAffectation($compte, $depuis), $depuis);
    }

    #[Route('/{id}/modifier', name: 'administration_compte_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(#[MapEntity] Utilisateur $compte, Request $request): Response
    {
        $this->verifierDroitDeGestion($compte);
        $depuis = self::depuis($request);

        $donnees = CompteIdentiteData::depuis($compte);
        $form = $this->createForm(CompteIdentiteType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->gestion->emailDisponible((string) $donnees->email, $compte)) {
                $form->get('email')->addError(new FormError($this->traducteur->trans('compte.email.deja_utilise', [], 'validators')));
            } else {
                $this->gestion->modifierIdentite($compte, $donnees);
                $this->addFlash('succes', $this->traducteur->trans('administration.compte.modifie', ['nom' => $compte->getNomComplet()]));

                return $this->redirigerVersFiche($compte, $depuis);
            }
        }

        $statut = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('administration/compte/modifier.html.twig', ['compte' => $compte, 'form' => $form, 'depuis' => $depuis], new Response(status: $statut));
    }

    /** Activer un compte en attente ou désactivé, ou le désactiver ; jamais son propre compte. */
    #[Route('/{id}/statut/{action}', name: 'administration_compte_statut', requirements: ['id' => '\d+', 'action' => 'activer|desactiver'], methods: ['POST'])]
    public function statut(#[MapEntity] Utilisateur $compte, string $action, Request $request): Response
    {
        $this->verifierDroitDeGestion($compte);
        $this->verifierJeton($request, 'statut-compte-'.$compte->getId());

        if ('desactiver' === $action && $this->estMoiMeme($compte)) {
            $this->addFlash('erreur', $this->traducteur->trans('administration.compte.propre_compte_interdit'));
        } elseif ('activer' === $action) {
            $this->gestion->activer($compte);
            $this->addFlash('succes', $this->traducteur->trans('administration.compte.active', ['nom' => $compte->getNomComplet()]));
        } else {
            try {
                $this->gestion->desactiver($compte);
                $this->addFlash('succes', $this->traducteur->trans('administration.compte.desactive', ['nom' => $compte->getNomComplet()]));
            } catch (\LogicException) {
                $this->addFlash('erreur', $this->traducteur->trans('administration.compte.dernier_super_admin_desactivation', ['nom' => $compte->getNomComplet()]));
            }
        }

        return $this->redirigerVersFiche($compte, self::depuis($request));
    }

    #[Route('/{id}/affectations', name: 'administration_compte_affecter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function affecter(#[MapEntity] Utilisateur $compte, Request $request): Response
    {
        $this->verifierDroitDeGestion($compte);
        $depuis = self::depuis($request);

        $form = $this->formulaireAffectation($compte, $depuis);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $donnees = $form->getData();
            \assert($donnees instanceof AffectationData && null !== $donnees->role);
            $affectation = $this->gestion->affecter($compte, $donnees);
            $this->addFlash('succes', $this->traducteur->trans('administration.compte.affectation_ajoutee', ['nom' => $compte->getNomComplet(), 'role' => $this->libelleAffectation($affectation)]));

            return $this->redirigerVersFiche($compte, $depuis);
        }

        return $this->rendreFiche($compte, $form, $depuis, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** Retire un rôle ; un super-admin ne se retire jamais son propre rôle de super-admin. */
    #[Route('/{id}/affectations/{affectation}/retirer', name: 'administration_compte_retirer_affectation', requirements: ['id' => '\d+', 'affectation' => '\d+'], methods: ['POST'])]
    public function retirerAffectation(
        #[MapEntity] Utilisateur $compte,
        #[MapEntity(id: 'affectation')] Affectation $affectation,
        Request $request,
    ): Response {
        $this->verifierDroitDeGestion($compte);
        $this->verifierJeton($request, 'retirer-affectation-'.$affectation->getId());

        if ($affectation->getUtilisateur()->getId() !== $compte->getId()) {
            throw $this->createNotFoundException('Cette affectation n\'appartient pas à ce compte.');
        }

        if (Role::SuperAdmin === $affectation->getRole() && $this->estMoiMeme($compte)) {
            $this->addFlash('erreur', $this->traducteur->trans('administration.compte.affectation_propre_interdite'));
        } else {
            $libelle = $this->libelleAffectation($affectation);
            try {
                $this->gestion->retirerAffectation($compte, $affectation);
                $this->addFlash('succes', $this->traducteur->trans('administration.compte.affectation_retiree', ['nom' => $compte->getNomComplet(), 'role' => $libelle]));
            } catch (\LogicException) {
                $this->addFlash('erreur', $this->traducteur->trans('administration.compte.dernier_super_admin', ['nom' => $compte->getNomComplet()]));
            }
        }

        return $this->redirigerVersFiche($compte, self::depuis($request));
    }

    /** Un lien de mot de passe valable une heure, comme « mot de passe oublié » ; réservé aux comptes actifs. */
    #[Route('/{id}/mot-de-passe', name: 'administration_compte_mot_de_passe', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function motDePasse(#[MapEntity] Utilisateur $compte, Request $request, ReinitialisationMotDePasse $reinitialisation, Journal $journal): Response
    {
        $this->verifierDroitDeGestion($compte);
        $this->verifierJeton($request, 'mot-de-passe-compte-'.$compte->getId());

        if (!$compte->estActif()) {
            $this->addFlash('erreur', $this->traducteur->trans('administration.compte.lien_impossible', ['nom' => $compte->getNomComplet()]));
        } else {
            $journal->consigner(TypeEvenement::CompteLienMotDePasse, $this->acteur(), $compte->getAssociation(), $compte->getEmail(), ['validite' => Utilisateur::DUREE_JETON_REINITIALISATION]);
            $reinitialisation->demander($compte->getEmail());
            $this->addFlash('succes', $this->traducteur->trans('administration.compte.lien_envoye', ['email' => $compte->getEmail()]));
        }

        return $this->redirigerVersFiche($compte, self::depuis($request));
    }

    /** Un compte d'association relève de la permission « compte.gerer » sur cette association ; un compte de plateforme, de l'administration. */
    private function verifierDroitDeGestion(Utilisateur $compte): void
    {
        $association = $compte->getAssociation();
        if (null !== $association) {
            $this->denyAccessUnlessGranted(Permission::COMPTE_GERER, $association);
        }
    }

    private function verifierJeton(Request $request, string $identifiant): void
    {
        if (!$this->isCsrfTokenValid($identifiant, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
    }

    private function estMoiMeme(Utilisateur $compte): bool
    {
        $moi = $this->acteur();

        return null !== $moi && $moi->getId() === $compte->getId();
    }

    private function acteur(): ?Utilisateur
    {
        $moi = $this->getUser();

        return $moi instanceof Utilisateur ? $moi : null;
    }

    /** L'écran d'origine, « equipe » ou rien : transmis en paramètre d'adresse (lien) ou de formulaire (action). */
    private static function depuis(Request $request): ?string
    {
        $valeur = $request->request->get('depuis', $request->query->get('depuis'));

        return 'equipe' === $valeur ? 'equipe' : null;
    }

    /** @return array<string, int|string> */
    private static function parametresFiche(Utilisateur $compte, ?string $depuis): array
    {
        $parametres = ['id' => (int) $compte->getId()];
        if (null !== $depuis) {
            $parametres['depuis'] = $depuis;
        }

        return $parametres;
    }

    private function redirigerVersFiche(Utilisateur $compte, ?string $depuis): Response
    {
        return $this->redirectToRoute('administration_compte_fiche', self::parametresFiche($compte, $depuis), Response::HTTP_SEE_OTHER);
    }

    private function formulaireAffectation(Utilisateur $compte, ?string $depuis): FormInterface
    {
        return $this->createForm(AffectationType::class, new AffectationData($compte), [
            'compte' => $compte,
            'action' => $this->generateUrl('administration_compte_affecter', self::parametresFiche($compte, $depuis)),
        ]);
    }

    private function rendreFiche(Utilisateur $compte, FormInterface $formAffectation, ?string $depuis, int $statut = Response::HTTP_OK): Response
    {
        return $this->render('administration/compte/fiche.html.twig', [
            'compte' => $compte,
            'formAffectation' => $formAffectation,
            'depuis' => $depuis,
        ], new Response(status: $statut));
    }

    private function libelleAffectation(Affectation $affectation): string
    {
        $role = $this->traducteur->trans('role.'.$affectation->getRole()->value);
        $ville = $affectation->getVille();

        return null === $ville ? $role : $role.' · '.$ville->getNom();
    }
}
