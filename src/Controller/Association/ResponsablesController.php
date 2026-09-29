<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Administration\GestionComptes;
use App\Association\Responsables;
use App\Compte\Invitations;
use App\Entity\Affectation;
use App\Entity\Association;
use App\Entity\Invitation;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Form\InvitationResponsableType;
use App\Form\Model\InvitationResponsableData;
use App\Repository\InvitationRepository;
use App\Repository\UtilisateurRepository;
use App\Repository\VilleRepository;
use App\Security\Permission;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Paramètres › Responsables et rôles » de l'association (F-02, F-07), pour qui pilote l'association : les affectations
 * et invitations sur une ligne, les rôles et leurs droits, le journal des rôles ; inviter un responsable, retirer un
 * rôle, renvoyer une invitation. Chaque action vérifie le droit sur son périmètre (la ville, ou l'association).
 */
#[Route('/associations/{slug}/parametres/responsables', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ASSOCIATION_PILOTER, subject: 'association')]
final class ResponsablesController extends AbstractController
{
    public const array ONGLETS = ['responsables', 'roles', 'journal'];
    public const int JOURNAL_ASIDE = 3;
    public const int JOURNAL_ONGLET = 60;

    public function __construct(
        private readonly Responsables $responsables,
        private readonly Invitations $invitations,
        private readonly GestionComptes $comptes,
        private readonly VilleRepository $villes,
        private readonly InvitationRepository $invitationRepository,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'association_responsables', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $onglet = (string) $request->query->get('onglet', 'responsables');
        if (!\in_array($onglet, self::ONGLETS, true)) {
            $onglet = 'responsables';
        }
        $villes = $this->villes->listerPourAssociation($association);
        $perimetre = (string) $request->query->get('perimetre', 'tous');
        $villeFiltre = null;
        foreach ($villes as $ville) {
            if ((string) $ville->getId() === $perimetre) {
                $villeFiltre = $ville;
            }
        }
        if (!\in_array($perimetre, ['tous', 'association'], true) && null === $villeFiltre) {
            $perimetre = 'tous';
        }
        $role = (string) $request->query->get('role', 'tous');
        if ('tous' !== $role && !\in_array(Role::tryFrom($role), Responsables::ROLES, true)) {
            $role = 'tous';
        }
        $recherche = trim((string) $request->query->get('q', ''));

        $aujourdhui = new \DateTimeImmutable();
        $toutes = $this->responsables->lignes($association, $aujourdhui);
        $lignes = Responsables::filtrer($toutes, $recherche, $perimetre, $role);

        return $this->render('association/responsables/index.html.twig', [
            'association' => $association,
            'aujourdhui' => $aujourdhui,
            'onglet' => $onglet,
            'filtres' => ['q' => $recherche, 'perimetre' => $perimetre, 'role' => $role],
            'villeFiltre' => $villeFiltre,
            'villes' => $villes,
            'roles' => Responsables::ROLES,
            'lignes' => $lignes,
            'compte' => Responsables::compter($lignes),
            'total' => \count($toutes),
            'journal' => $this->responsables->journal($association, 'journal' === $onglet ? self::JOURNAL_ONGLET : self::JOURNAL_ASIDE),
            'peutInviter' => [] !== $this->rolesAttribuables($association) ,
        ]);
    }

    #[Route('/inviter', name: 'association_responsables_inviter', methods: ['GET', 'POST'])]
    public function inviter(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $roles = $this->rolesAttribuables($association);
        $villes = $this->villesModifiables($association);
        if ([] === $roles) {
            throw $this->createAccessDeniedException('Aucun rôle à attribuer.');
        }

        $donnees = new InvitationResponsableData();
        $form = $this->createForm(InvitationResponsableType::class, $donnees, ['roles' => $roles, 'villes' => $villes]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $donnees->role);
            $email = (string) Utilisateur::normaliserEmail($donnees->email);
            if (!$this->responsables->peutGerer($association, $donnees->role, $donnees->ville)) {
                throw $this->createAccessDeniedException('Ce rôle ne peut pas être attribué sur ce périmètre.');
            }
            $erreur = $this->dejaEnPlace($association, $email, $donnees->role, $donnees->ville);
            if (null !== $erreur) {
                $form->get('email')->addError(new FormError($this->traducteur->trans($erreur, [], 'validators')));
            } else {
                $this->invitations->inviter($association, $donnees->role, $email, $this->acteur(), $donnees->ville);
                $this->addFlash('succes', $this->traducteur->trans('responsables_association.inviter.envoyee', ['email' => $email]));

                return $this->redirectToRoute('association_responsables', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('association/responsables/inviter.html.twig', [
            'association' => $association,
            'form' => $form,
            'villes' => $villes,
            'roles' => $roles,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/affectations/{id}/retirer', name: 'association_responsables_retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retirer(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $this->verifierJeton($request, 'retirer-affectation-'.$id);
        $affectation = $this->entityManager->find(Affectation::class, $id);
        if (!$affectation instanceof Affectation || $affectation->getUtilisateur()->getAssociation()?->getId() !== $association->getId()) {
            throw $this->createNotFoundException('Affectation introuvable dans cette association.');
        }
        $compte = $affectation->getUtilisateur();
        $moi = $this->acteur();
        if (null === $moi || $moi->getId() === $compte->getId() || !$this->responsables->peutGerer($association, $affectation->getRole(), $affectation->getVille())) {
            throw $this->createAccessDeniedException('Ce rôle ne peut pas être retiré d’ici.');
        }

        $this->comptes->retirerAffectation($compte, $affectation);
        $this->addFlash('succes', $this->traducteur->trans('responsables_association.retire', ['nom' => $compte->getNomComplet(), 'role' => $this->traducteur->trans('role.'.$affectation->getRole()->value)]));

        return $this->redirectToRoute('association_responsables', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/invitations/{id}/renvoyer', name: 'association_responsables_renvoyer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function renvoyer(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $this->verifierJeton($request, 'renvoyer-invitation-'.$id);
        $invitation = $this->entityManager->find(Invitation::class, $id);
        if (!$invitation instanceof Invitation || $invitation->getAssociation()->getId() !== $association->getId() || $invitation->estAcceptee()) {
            throw $this->createNotFoundException('Invitation introuvable dans cette association.');
        }
        if (!$this->responsables->peutGerer($association, $invitation->getRole(), $invitation->getVille())) {
            throw $this->createAccessDeniedException('Cette invitation ne peut pas être renvoyée d’ici.');
        }

        $this->invitations->renvoyer($invitation);
        $this->addFlash('succes', $this->traducteur->trans('responsables_association.renvoyee', ['email' => $invitation->getEmail()]));

        return $this->redirectToRoute('association_responsables', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }

    /** @return list<Role> les rôles que la personne connectée peut attribuer : ceux des villes qu'elle modifie, et bureau central si elle gère les comptes */
    private function rolesAttribuables(Association $association): array
    {
        $roles = [];
        if ($this->isGranted(Permission::COMPTE_GERER, $association)) {
            $roles[] = Role::BureauCentral;
        }
        if ([] !== $this->villesModifiables($association)) {
            array_push($roles, Role::Tresorier, Role::President, Role::Secretaire);
        }

        return $roles;
    }

    /** @return list<Ville> les villes actives que la personne connectée peut modifier (un brouillon reçoit ses responsables dans l'assistant) */
    private function villesModifiables(Association $association): array
    {
        return array_values(array_filter($this->villes->listerPourAssociation($association), fn (Ville $v): bool => $v->estActive() && $this->isGranted(Permission::VILLE_MODIFIER, $v)));
    }

    /** La personne a-t-elle déjà ce rôle sur ce périmètre, ou une invitation en cours pour lui ? Rend la clé du message, ou null. */
    private function dejaEnPlace(Association $association, string $email, Role $role, ?Ville $ville): ?string
    {
        $compte = $this->utilisateurs->trouverParEmail($email);
        if (null !== $compte && null !== $compte->affectationPour($role, $ville)) {
            return 'invitation.email.deja_ce_role';
        }
        foreach ($this->invitationRepository->enCoursPourEmail($association, $email) as $invitation) {
            if ($invitation->getRole() === $role && $invitation->getVille()?->getId() === $ville?->getId() && !$invitation->estExpiree(new \DateTimeImmutable())) {
                return 'invitation.email.deja_invitee';
            }
        }

        return null;
    }

    private function verifierJeton(Request $request, string $identifiant): void
    {
        if (!$this->isCsrfTokenValid($identifiant, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
    }

    private function acteur(): ?Utilisateur
    {
        $acteur = $this->getUser();

        return $acteur instanceof Utilisateur ? $acteur : null;
    }
}
