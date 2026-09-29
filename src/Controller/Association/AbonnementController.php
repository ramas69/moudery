<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Abonnement\Catalogue;
use App\Administration\GestionAbonnements;
use App\Entity\Association;
use App\Entity\PaiementAbonnement;
use App\Entity\Utilisateur;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Onglet « Abonnement » des Paramètres : le bureau central voit son abonnement à Caisses, ses paiements, et le règle en
 * ligne. Le règlement est SIMULÉ (décision de Rama du 29 septembre 2026) : 25 € par mois, aucune carte demandée.
 */
#[Route('/associations/{slug}/parametres/abonnement', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ASSOCIATION_PILOTER, subject: 'association')]
final class AbonnementController extends AbstractController
{
    public function __construct(private readonly GestionAbonnements $abonnements)
    {
    }

    #[Route('', name: 'association_abonnement', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, EntityManagerInterface $em): Response
    {
        $abonnement = $this->abonnements->pour($association);
        $paiements = $em->getRepository(PaiementAbonnement::class)->findBy(['association' => $association], ['recuLe' => 'DESC', 'id' => 'DESC'], 24);

        return $this->render('association/abonnement/index.html.twig', [
            'association' => $association,
            'abonnement' => $abonnement,
            'paiements' => $paiements,
            'offres' => Catalogue::offres(),
            'economie' => Catalogue::economieAnnuelle(),
            'aujourdhui' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/payer', name: 'association_abonnement_payer', methods: ['POST'])]
    public function payer(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request, TranslatorInterface $traducteur): Response
    {
        if (!$this->isCsrfTokenValid('abonnement-payer', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($this->isGranted('IS_IMPERSONATOR')) {
            throw $this->createAccessDeniedException('On ne paie pas sous emprunt d’identité.');
        }
        $compte = $this->getUser();
        \assert($compte instanceof Utilisateur);
        $abonnement = $this->abonnements->pour($association);
        $retour = $this->redirectToRoute('association_abonnement', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        if (!$abonnement->attendLaSouscription() && !$abonnement->estFacturable() && \App\Entity\AbonnementStatut::Offert !== $abonnement->getStatut()) {
            $this->addFlash('erreur', $traducteur->trans('abonnement_association.flash.rien_a_payer'));

            return $retour;
        }
        try {
            $paiement = $this->abonnements->payerEnLigne($abonnement, Catalogue::offre((string) $request->request->get('offre')), $compte, new \DateTimeImmutable());
        } catch (\InvalidArgumentException|\LogicException) {
            $this->addFlash('erreur', $traducteur->trans('abonnement_association.flash.offre'));

            return $retour;
        }
        $this->addFlash('succes', $traducteur->trans('abonnement_association.flash.paye', ['montant' => $paiement->getMontant() / 100]));

        return $retour;
    }
}
