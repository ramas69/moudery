<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Entity\Association;
use App\Entity\Membre;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\FormError;
use App\Ville\Membres;
use App\Repository\VilleRepository;
use App\Repository\PaiementRepository;
use App\Repository\MembreRepository;
use App\Repository\EcheanceRepository;
use App\Form\Model\MembreData;
use App\Form\MembreType;
use App\Entity\Ville;
use App\Entity\Utilisateur;
use App\Entity\MembreStatut;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La fiche d'un membre : son identité, son contact, son foyer, et ses années d'adhésion avec les versements mois par
 * mois, comme les lignes du classeur de sa ville. Ouverte par le bureau central de l'association et par les
 * responsables de la ville du membre ; une autre association ne la trouve pas.
 */
final class MembreController extends AbstractController
{
    public function __construct(
        private readonly Membres $service,
        private readonly MembreRepository $membres,
        private readonly VilleRepository $villes,
        private readonly PaiementRepository $paiements,
        private readonly EcheanceRepository $echeances,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('/associations/{slug}/membres/{id}', name: 'association_membre', requirements: ['slug' => '[a-z0-9-]+', 'id' => '\d+'], methods: ['GET'])]
    public function fiche(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(mapping: ['id' => 'id'])] Membre $membre,
    ): Response {
        $this->verifier($association, $membre, Permission::VILLE_CONSULTER);

        $adhesions = $membre->getAdhesions()->toArray();
        usort($adhesions, static fn ($a, $b): int => $b->getAnnee() <=> $a->getAnnee());

        $villesCibles = array_values(array_filter($this->villes->listerPourAssociation($association), static fn (Ville $v): bool => $v->estActive() && $v !== $membre->getVille()));

        return $this->render('association/membres/fiche.html.twig', [
            'association' => $association,
            'membre' => $membre,
            'adhesions' => $adhesions,
            'villesCibles' => $this->isGranted(Permission::ASSOCIATION_PILOTER, $association) ? $villesCibles : [],
            'peutGerer' => $this->isGranted(Permission::ASSOCIATION_PILOTER, $association) || $this->isGranted(Permission::MEMBRE_GERER, $membre->getVille()),
            'paiements' => $this->paiements->pourMembre($membre),
            'dues' => $this->echeances->duesPourMembre($membre),
            'aujourdhui' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/associations/{slug}/membres/{id}/modifier', name: 'association_membre_modifier', requirements: ['slug' => '[a-z0-9-]+', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(mapping: ['id' => 'id'])] Membre $membre,
        Request $request,
    ): Response {
        $this->verifier($association, $membre, Permission::MEMBRE_GERER);
        $donnees = MembreData::depuis($membre);
        $form = $this->createForm(MembreType::class, $donnees, ['ville' => $membre->getVille()]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $email = Utilisateur::normaliserEmail($donnees->email);
            if (null !== $email && $email !== $membre->getEmail() && $this->membres->emailPris($membre->getVille(), $email)) {
                $form->get('email')->addError(new FormError($this->traducteur->trans('membre.email.deja_membre', [], 'validators')));
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $this->service->modifier($membre, $donnees, $this->compte());
            $this->addFlash('succes', $this->traducteur->trans('membre_fiche.flash.modifie', ['nom' => $membre->getNomComplet()]));

            return $this->redirectToRoute('association_membre', ['slug' => $association->getSlug(), 'id' => $membre->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('association/membres/modifier.html.twig', [
            'association' => $association,
            'membre' => $membre,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/associations/{slug}/membres/{id}/statut/{action}', name: 'association_membre_statut', requirements: ['slug' => '[a-z0-9-]+', 'id' => '\d+', 'action' => 'sortir|reactiver'], methods: ['POST'])]
    public function statut(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(mapping: ['id' => 'id'])] Membre $membre,
        string $action,
        Request $request,
    ): Response {
        $this->verifier($association, $membre, Permission::MEMBRE_GERER);
        if (!$this->isCsrfTokenValid('membre-statut-'.$membre->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $this->service->changerStatut($membre, 'sortir' === $action ? MembreStatut::Sorti : MembreStatut::Actif, $this->compte());
        $this->addFlash('succes', $this->traducteur->trans('sortir' === $action ? 'membre_fiche.flash.sorti' : 'membre_fiche.flash.reactive', ['nom' => $membre->getNomComplet()]));

        return $this->redirectToRoute('association_membre', ['slug' => $association->getSlug(), 'id' => $membre->getId()], Response::HTTP_SEE_OTHER);
    }

    /** Transfert vers une autre ville (F-10), réservé au bureau central. */
    #[Route('/associations/{slug}/membres/{id}/transferer', name: 'association_membre_transferer', requirements: ['slug' => '[a-z0-9-]+', 'id' => '\d+'], methods: ['POST'])]
    public function transferer(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(mapping: ['id' => 'id'])] Membre $membre,
        Request $request,
    ): Response {
        if ($membre->getAssociation()->getId() !== $association->getId()) {
            throw $this->createNotFoundException('Membre introuvable dans cette association.');
        }
        $this->denyAccessUnlessGranted(Permission::ASSOCIATION_PILOTER, $association);
        if (!$this->isCsrfTokenValid('membre-transferer-'.$membre->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $cible = $this->villes->trouverDansAssociation($request->request->getInt('ville'), $association->getSlug());
        if (!$cible instanceof Ville || !$cible->estActive() || $cible === $membre->getVille()) {
            $this->addFlash('erreur', $this->traducteur->trans('membre_fiche.flash.ville_invalide'));

            return $this->redirectToRoute('association_membre', ['slug' => $association->getSlug(), 'id' => $membre->getId()], Response::HTTP_SEE_OTHER);
        }
        try {
            $origine = $membre->getVille()->getNom();
            $this->service->transferer($membre, $cible, $this->compte());
            $this->addFlash('succes', $this->traducteur->trans('membre_fiche.flash.transfere', ['nom' => $membre->getNomComplet(), 'de' => $origine, 'vers' => $cible->getNom()]));
        } catch (\LogicException) {
            $this->addFlash('erreur', $this->traducteur->trans('membre_fiche.flash.email_pris', ['ville' => $cible->getNom()]));
        }

        return $this->redirectToRoute('association_membre', ['slug' => $association->getSlug(), 'id' => $membre->getId()], Response::HTTP_SEE_OTHER);
    }

    /** La fiche appartient à l'association, et la personne pilote l'association ou tient ce droit sur la ville du membre. */
    private function verifier(Association $association, Membre $membre, string $permission): void
    {
        if ($membre->getAssociation()->getId() !== $association->getId()) {
            throw $this->createNotFoundException('Membre introuvable dans cette association.');
        }
        if (!$this->isGranted(Permission::ASSOCIATION_PILOTER, $association) && !$this->isGranted($permission, $membre->getVille())) {
            throw $this->createAccessDeniedException('Cette fiche est réservée au bureau central et aux responsables de la ville.');
        }
    }

    private function compte(): ?Utilisateur
    {
        $compte = $this->getUser();

        return $compte instanceof Utilisateur ? $compte : null;
    }
}
