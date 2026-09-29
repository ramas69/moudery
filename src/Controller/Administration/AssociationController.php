<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Administration\GestionAbonnements;
use App\Administration\GestionAssociations;
use App\Association\CreationAssociation;
use App\Entity\Association;
use App\Entity\Invitation;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\AssociationStatut;
use App\Form\AbonnementType;
use App\Journal\Journal;
use App\Form\AssociationModificationType;
use App\Form\Model\AbonnementData;
use App\Form\Model\AssociationModificationData;
use App\Repository\InvitationRepository;
use App\Repository\UtilisateurRepository;
use App\Repository\VilleRepository;
use App\Security\Permission;
use App\Security\Role;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Administration d'une association par le super-admin : sa fiche (villes, comptes), sa modification, son statut, sa suppression.
 * La création vit dans AdministrationController, avec l'invitation du premier bureau central.
 */
#[Route('/administration/associations')]
#[IsGranted(Permission::PLATEFORME_ADMINISTRER)]
final class AssociationController extends AbstractController
{
    public function __construct(
        private readonly GestionAssociations $gestion,
        private readonly VilleRepository $villes,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly TranslatorInterface $traducteur,
        private readonly Journal $journal,
    ) {
    }

    #[Route('/{id}', name: 'administration_association_fiche', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(Permission::ASSOCIATION_MODIFIER, subject: 'association')]
    public function fiche(#[MapEntity] Association $association, GestionAbonnements $abonnements, InvitationRepository $invitations): Response
    {
        return $this->render('administration/association/fiche.html.twig', [
            'association' => $association,
            'abonnement' => $abonnements->pour($association),
            'aujourdhui' => new \DateTimeImmutable(),
            'bureauCentral' => $this->utilisateurs->bureauxCentrauxParAssociation()[$association->getId()] ?? [],
            'invitationBureauCentral' => self::invitationBureauCentral($invitations->enCoursParAssociation($association)),
            'villes' => $this->villes->listerPourAssociation($association),
            'comptes' => $this->utilisateurs->listerPourAssociation($association),
            'suppressionPossible' => $this->gestion->peutSupprimer($association),
        ]);
    }

    #[Route('/{id}/modifier', name: 'administration_association_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(Permission::ASSOCIATION_MODIFIER, subject: 'association')]
    public function modifier(#[MapEntity] Association $association, Request $request): Response
    {
        $donnees = AssociationModificationData::depuis($association);
        $form = $this->createForm(AssociationModificationType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->gestion->modifier($association, $donnees);
                $this->addFlash('succes', $this->traducteur->trans('administration.association.modifiee', ['nom' => $association->getNom()]));

                return $this->redirectToRoute('administration_association_fiche', ['id' => $association->getId()], Response::HTTP_SEE_OTHER);
            } catch (UniqueConstraintViolationException) {
                $form->get('slug')->addError(new FormError($this->traducteur->trans('association.slug.deja_utilise', ['{{ slug }}' => CreationAssociation::slugPour($donnees->nom, $donnees->slug)], 'validators')));
            }
        }

        $statut = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('administration/association/modifier.html.twig', ['association' => $association, 'form' => $form], new Response(status: $statut));
    }

    /** Suspendre, réactiver ou archiver : l'association reste, avec toutes ses données. */
    #[Route('/{id}/statut/{action}', name: 'administration_association_statut', requirements: ['id' => '\d+', 'action' => 'suspendre|reactiver|archiver'], methods: ['POST'])]
    #[IsGranted(Permission::ASSOCIATION_MODIFIER, subject: 'association')]
    public function statut(#[MapEntity] Association $association, string $action, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('statut-association-'.$association->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }

        $cible = match ($action) {
            'suspendre' => AssociationStatut::Suspendue,
            'archiver' => AssociationStatut::Archivee,
            default => AssociationStatut::Active,
        };

        try {
            $this->journal->consigner(TypeEvenement::AssociationStatut, $this->acteur(), $association, $association->getNom(), ['statut' => $cible->value]);
            $this->gestion->changerStatut($association, $cible);
            $this->addFlash('succes', $this->traducteur->trans('administration.association.statut_change', [
                'nom' => $association->getNom(),
                'statut' => mb_strtolower($this->traducteur->trans('statut_association.'.$cible->value)),
            ]));
        } catch (\LogicException) {
            $this->addFlash('erreur', $this->traducteur->trans('administration.association.statut_impossible', ['nom' => $association->getNom()]));
        }

        $retour = (string) $request->request->get('retour', '');
        if (str_starts_with($retour, '/administration')) {
            return $this->redirect($retour, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('administration_association_fiche', ['id' => $association->getId()], Response::HTTP_SEE_OTHER);
    }

    private function acteur(): ?Utilisateur
    {
        $utilisateur = $this->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur : null;
    }

    /**
     * L'invitation du bureau central encore ouverte (en attente ou expirée), pour la renvoyer depuis la fiche.
     *
     * @param list<Invitation> $enCours
     */
    private static function invitationBureauCentral(array $enCours): ?Invitation
    {
        foreach ($enCours as $invitation) {
            if (Role::BureauCentral === $invitation->getRole() && null === $invitation->getVille()) {
                return $invitation;
            }
        }

        return null;
    }

    /** L'abonnement de l'association : formule, statut, montant, échéances. Aucun paiement n'est encaissé ici. */
    #[Route('/{id}/abonnement', name: 'administration_association_abonnement', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(Permission::ASSOCIATION_MODIFIER, subject: 'association')]
    public function abonnement(#[MapEntity] Association $association, Request $request, GestionAbonnements $abonnements): Response
    {
        $abonnement = $abonnements->pour($association);
        $donnees = AbonnementData::depuis($abonnement);
        $form = $this->createForm(AbonnementType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $abonnements->modifier($abonnement, $donnees, $this->acteur());
            $this->addFlash('succes', $this->traducteur->trans('administration.abonnement.modifie', ['nom' => $association->getNom()]));

            $retour = (string) $request->request->get('retour', '');

            return $this->redirect(str_starts_with($retour, '/administration') ? $retour : $this->generateUrl('administration_association_fiche', ['id' => $association->getId()]).'#abonnement', Response::HTTP_SEE_OTHER);
        }

        $statut = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('administration/association/abonnement.html.twig', [
            'association' => $association,
            'abonnement' => $abonnement,
            'form' => $form,
            'retour' => (string) $request->query->get('retour', ''),
        ], new Response(status: $statut));
    }

    /** Un paiement reçu hors plateforme : l'échéance avance d'une période. */
    #[Route('/{id}/abonnement/paye', name: 'administration_association_abonnement_paye', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Permission::ASSOCIATION_MODIFIER, subject: 'association')]
    public function abonnementPaye(#[MapEntity] Association $association, Request $request, GestionAbonnements $abonnements): Response
    {
        if (!$this->isCsrfTokenValid('abonnement-paye-'.$association->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }

        $abonnement = $abonnements->pour($association);
        try {
            $abonnements->marquerPaye($abonnement, $this->acteur());
            $this->addFlash('succes', $this->traducteur->trans('administration.abonnement.paye', ['nom' => $association->getNom(), 'echeance' => $abonnement->getProchaineEcheanceLe()?->format('d/m/Y') ?? '']));
        } catch (\LogicException) {
            $this->addFlash('erreur', $this->traducteur->trans('administration.abonnement.paiement_impossible', ['nom' => $association->getNom()]));
        }

        $retour = (string) $request->request->get('retour', '');
        if (str_starts_with($retour, '/administration')) {
            return $this->redirect($retour, Response::HTTP_SEE_OTHER);
        }

        return $this->redirect($this->generateUrl('administration_association_fiche', ['id' => $association->getId()]).'#abonnement', Response::HTTP_SEE_OTHER);
    }

    /** Seule une association vide se supprime ; sinon la demande est refusée avec une explication. */
    #[Route('/{id}/supprimer', name: 'administration_association_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Permission::ASSOCIATION_SUPPRIMER, subject: 'association')]
    public function supprimer(#[MapEntity] Association $association, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('supprimer-association-'.$association->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }

        $nom = $association->getNom();
        if (!$this->gestion->peutSupprimer($association)) {
            $this->addFlash('erreur', $this->traducteur->trans('administration.association.suppression_impossible', ['nom' => $nom]));

            return $this->redirectToRoute('administration_association_fiche', ['id' => $association->getId()], Response::HTTP_SEE_OTHER);
        }

        $this->gestion->supprimer($association);
        $this->addFlash('succes', $this->traducteur->trans('administration.association.supprimee', ['nom' => $nom]));

        return $this->redirectToRoute('administration_accueil', [], Response::HTTP_SEE_OTHER);
    }
}
