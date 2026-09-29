<?php

declare(strict_types=1);

namespace App\Controller;

use App\Administration\ActiviteRecente;
use App\Administration\GestionAbonnements;
use App\Administration\GraphiquesTableauDeBord;
use App\Administration\Periode;
use App\Administration\Statistiques;
use App\Administration\TableauAssociations;
use App\Administration\TableauVilles;
use App\Association\CreationAssociation;
use App\Compte\Invitations;
use App\Entity\Abonnement;
use App\Entity\Association;
use App\Entity\AssociationStatut;
use App\Entity\Invitation;
use App\Entity\Utilisateur;
use App\Entity\VilleStatut;
use App\Form\AssociationType;
use App\Form\Model\AssociationData;
use App\Repository\AbonnementRepository;
use App\Repository\AssociationRepository;
use App\Repository\InvitationRepository;
use App\Repository\UtilisateurRepository;
use App\Repository\VilleRepository;
use App\Security\Permission;
use App\Security\Role;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Administration de la plateforme, réservée au super-admin : tableau de bord, liste et création des associations
 * avec l'invitation de leur bureau central. La fiche d'une association, les comptes et les villes vivent dans
 * les contrôleurs de src/Controller/Administration/.
 */
#[Route('/administration')]
#[IsGranted(Permission::PLATEFORME_ADMINISTRER)]
final class AdministrationController extends AbstractController
{
    public function __construct(
        private readonly AssociationRepository $associations,
        private readonly VilleRepository $villes,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly InvitationRepository $invitations,
    ) {
    }


    /**
     * Le poste de pilotage de la plateforme, d'après le canevas Console : chiffres, encaissements attendus,
     * ce qu'il reste à faire, les associations, la répartition des abonnements.
     */
    #[Route('', name: 'administration_accueil', methods: ['GET'])]
    public function index(Request $request, Statistiques $statistiques, GraphiquesTableauDeBord $graphiques, AssociationRepository $depotAssociations): Response
    {
        $aujourdhui = new \DateTimeImmutable();
        $lignes = $this->lignesAssociations();
        $abonnements = array_values(array_filter(array_map(static fn (array $l): ?Abonnement => $l['abonnement'], $lignes)));

        $filtres = self::filtresTableauDeBord($request);
        $periode = Periode::depuis($filtres['periode'], $filtres['du'], $filtres['au'], $aujourdhui);
        $associations = $depotAssociations->listerParNom();
        $association = TableauVilles::associationCorrespondante($associations, $filtres['association']);
        $statutAssociation = match ($filtres['statut_association']) {
            'actives' => AssociationStatut::Active,
            'suspendues' => AssociationStatut::Suspendue,
            'archivees' => AssociationStatut::Archivee,
            default => null,
        };
        $series = [
            'croissance' => $statistiques->croissance($periode, $association, $statutAssociation),
            'revenu' => $statistiques->revenuRecurrent($periode, $association),
            'encaissements' => $statistiques->encaissements($periode, $association, $aujourdhui),
            'abonnements' => $statistiques->abonnementsParStatut($association, $aujourdhui),
            'villes' => $statistiques->villesParEtape($association, VilleStatut::tryFrom($filtres['statut_ville'])),
            'invitations' => $statistiques->invitations($periode, $association, $aujourdhui),
            'comptes' => $statistiques->comptes($association, Role::tryFrom($filtres['role']), $aujourdhui),
            'activite' => $statistiques->activite($periode, $association),
        ];

        return $this->render('administration/index.html.twig', [
            'lignes' => $lignes,
            'aTraiter' => $this->aTraiter($lignes, $aujourdhui),
            'totaux' => TableauAssociations::totaux($lignes),
            'compteurs' => TableauAssociations::compter($lignes),
            'synthese' => GestionAbonnements::synthese($abonnements, $aujourdhui),
            'aujourdhui' => $aujourdhui,
            'filtres' => $filtres,
            'periode' => $periode,
            'association' => $association,
            'associations' => $associations,
            'series' => $series,
            'graphiques' => $graphiques->construire($periode, $series),
        ]);
    }

    /**
     * Les filtres du tableau de bord, toujours valides : période, association, et un statut ou rôle par graphique.
     *
     * @return array{periode: string, du: string, au: string, association: string, statut_association: string, statut_ville: string, role: string}
     */
    private static function filtresTableauDeBord(Request $request): array
    {
        $periode = (string) $request->query->get('periode', '12m');
        $statutAssociation = (string) $request->query->get('statut_association', 'toutes');
        $statutVille = (string) $request->query->get('statut_ville', 'tous');
        $role = (string) $request->query->get('role', 'tous');

        return [
            'periode' => \in_array($periode, Periode::CLES, true) ? $periode : '12m',
            'du' => 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query->get('du', '')) ? (string) $request->query->get('du') : '',
            'au' => 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query->get('au', '')) ? (string) $request->query->get('au') : '',
            'association' => trim((string) $request->query->get('association', '')),
            'statut_association' => \in_array($statutAssociation, ['toutes', 'actives', 'suspendues', 'archivees'], true) ? $statutAssociation : 'toutes',
            'statut_ville' => null !== VilleStatut::tryFrom($statutVille) ? $statutVille : 'tous',
            'role' => null !== Role::tryFrom($role) && 'super-admin' !== $role ? $role : 'tous',
        ];
    }

    /** Le journal de la plateforme : connexions, comptes créés, invitations acceptées, associations créées, par jour. */
    #[Route('/activite', name: 'administration_activite', methods: ['GET'])]
    #[IsGranted(Permission::ASSOCIATION_CONSULTER)]
    public function activite(ActiviteRecente $activite): Response
    {
        return $this->render('administration/activite.html.twig', [
            'evenements' => $activite->derniers(60),
            'aujourdhui' => new \DateTimeImmutable(),
        ]);
    }

    /** Vue d'ensemble des abonnements : revenu récurrent, répartition, prochaines échéances. */
    #[Route('/abonnements', name: 'administration_abonnements', methods: ['GET'])]
    #[IsGranted(Permission::ASSOCIATION_CONSULTER)]
    public function abonnements(AbonnementRepository $depot): Response
    {
        $aujourdhui = new \DateTimeImmutable();
        $abonnements = $depot->listerParEcheance();

        return $this->render('administration/abonnements.html.twig', [
            'abonnements' => $abonnements,
            'synthese' => GestionAbonnements::synthese($abonnements, $aujourdhui),
            'aujourdhui' => $aujourdhui,
        ]);
    }

    #[Route('/associations', name: 'administration_associations', methods: ['GET'])]
    #[IsGranted(Permission::ASSOCIATION_CONSULTER)]
    public function associations(Request $request): Response
    {
        $filtres = $this->filtres($request);
        $toutes = $this->lignesAssociations();
        $lignes = TableauAssociations::trier(
            TableauAssociations::filtrer($toutes, $filtres['q'], $filtres['statut'], $filtres['bureau'], $filtres['abonnement']),
            $filtres['tri'],
            $filtres['sens'],
        );

        return $this->render('administration/associations.html.twig', [
            'aujourdhui' => new \DateTimeImmutable(),
            'lignes' => $lignes,
            'filtres' => $filtres,
            'compteurs' => TableauAssociations::compter($toutes),
            'totaux' => TableauAssociations::totaux($lignes),
            'total' => \count($toutes),
        ]);
    }

    /** La liste, telle que filtrée à l'écran, en CSV lisible par Excel (séparateur point-virgule, UTF-8 avec BOM). */
    #[Route('/associations/export.csv', name: 'administration_associations_export', methods: ['GET'], priority: 1)]
    #[IsGranted(Permission::ASSOCIATION_CONSULTER)]
    public function exporterAssociations(Request $request, TranslatorInterface $traducteur): Response
    {
        $filtres = $this->filtres($request);
        $lignes = TableauAssociations::trier(
            TableauAssociations::filtrer($this->lignesAssociations(), $filtres['q'], $filtres['statut'], $filtres['bureau'], $filtres['abonnement']),
            $filtres['tri'],
            $filtres['sens'],
        );

        $colonnes = ['nom', 'identifiant', 'village', 'statut', 'villes', 'villes_brouillon', 'comptes', 'comptes_en_attente', 'bureau_central', 'invitation', 'email_contact', 'telephone', 'derniere_activite', 'creee_le', 'abonnement_formule', 'abonnement_statut', 'abonnement_montant', 'abonnement_echeance'];
        $flux = fopen('php://temp', 'r+');
        \assert(false !== $flux);
        fwrite($flux, "\xEF\xBB\xBF");
        fputcsv($flux, array_map(static fn (string $c): string => $traducteur->trans('administration.associations.export.'.$c), $colonnes), ';', '"', '');
        foreach ($lignes as $ligne) {
            $association = $ligne['association'];
            fputcsv($flux, [
                $association->getNom(),
                $association->getSlug(),
                (string) $association->getVillage(),
                $traducteur->trans('statut_association.'.$association->getStatut()->value),
                $ligne['villes']['total'],
                $ligne['villes']['brouillon'],
                $ligne['comptes']['total'],
                $ligne['comptes']['en_attente'],
                implode(', ', array_map(static fn (Utilisateur $u): string => $u->getNomComplet().' <'.$u->getEmail().'>', $ligne['bureauCentral'])),
                null === $ligne['invitation'] ? '' : $ligne['invitation']->getEmail().' ('.$traducteur->trans('administration.associations.export.invitation_'.$ligne['invitationEtat']).')',
                (string) $association->getEmailContact(),
                (string) $association->getTelephoneContact(),
                $ligne['derniereActivite']?->format('Y-m-d H:i') ?? '',
                $association->getCreeLe()->format('Y-m-d'),
                (string) $ligne['abonnement']?->getFormule(),
                null === $ligne['abonnement'] ? '' : $traducteur->trans('statut_abonnement.'.$ligne['abonnement']->getStatut()->value),
                null === $ligne['abonnement'] ? '' : number_format($ligne['abonnement']->getMontant() / 100, 2, ',', ''),
                $ligne['abonnement']?->getProchaineEcheanceLe()?->format('Y-m-d') ?? '',
            ], ';', '"', '');
        }
        rewind($flux);
        $contenu = (string) stream_get_contents($flux);
        fclose($flux);

        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'associations-'.date('Y-m-d').'.csv'),
        ]);
    }

    #[Route('/associations/nouvelle', name: 'administration_association_nouvelle', methods: ['GET', 'POST'], priority: 1)]
    #[IsGranted(Permission::ASSOCIATION_CREER)]
    public function nouvelleAssociation(Request $request, CreationAssociation $creation, TranslatorInterface $traducteur): Response
    {
        $donnees = new AssociationData();
        $form = $this->createForm(AssociationType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $par = $this->getUser();
                $association = $creation->creer($donnees, $par instanceof Utilisateur ? $par : null);
                $this->addFlash('succes', $traducteur->trans('administration.association.creee', ['nom' => $association->getNom(), 'email' => (string) Utilisateur::normaliserEmail($donnees->emailBureauCentral)]));

                return $this->redirectToRoute('administration_associations', [], Response::HTTP_SEE_OTHER);
            } catch (UniqueConstraintViolationException) {
                // Deux créations simultanées du même slug : la contrainte SQL a tranché, on l'explique comme une erreur de saisie.
                $form->get('slug')->addError(new FormError($traducteur->trans('association.slug.deja_utilise', ['{{ slug }}' => CreationAssociation::slugPour($donnees->nom, $donnees->slug)], 'validators')));
            }
        }

        $statut = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('administration/association_nouvelle.html.twig', ['form' => $form], new Response(status: $statut));
    }

    /** Nouveau lien de 7 jours pour une invitation en attente ou expirée ; l'ancien lien ne vaut plus. */
    #[Route('/invitations/{id}/renvoyer', name: 'administration_invitation_renvoyer', methods: ['POST'])]
    #[IsGranted(Permission::ASSOCIATION_CREER)]
    public function renvoyerInvitation(Invitation $invitation, Request $request, Invitations $invitations, TranslatorInterface $traducteur): Response
    {
        if (!$this->isCsrfTokenValid('renvoyer-invitation-'.$invitation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }

        if ($invitation->estAcceptee()) {
            $this->addFlash('erreur', $traducteur->trans('administration.invitation.deja_acceptee', ['email' => $invitation->getEmail()]));
        } else {
            $invitations->renvoyer($invitation);
            $this->addFlash('succes', $traducteur->trans('administration.invitation.renvoyee', ['email' => $invitation->getEmail()]));
        }

        $retour = (string) $request->request->get('retour', '');
        if (str_starts_with($retour, '/administration')) {
            return $this->redirect($retour, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('administration_accueil', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Une ligne par association : ses compteurs, son bureau central, son invitation en cours.
     *
     * @return list<array{association: Association, villes: array{total: int, brouillon: int}, comptes: array{total: int, en_attente: int}, bureauCentral: list<Utilisateur>, invitation: ?Invitation, invitationEtat: ?string, derniereActivite: ?\DateTimeImmutable, suppressionPossible: bool, abonnement: ?Abonnement}>
     */
    private function lignesAssociations(): array
    {
        $villes = $this->villes->compterParAssociation();
        $comptes = $this->utilisateurs->compterParAssociation();
        $bureauxCentraux = $this->utilisateurs->bureauxCentrauxParAssociation();
        $invitations = $this->invitations->bureauCentralEnCoursParAssociation();
        $activites = $this->utilisateurs->derniereConnexionParAssociation();
        $maintenant = new \DateTimeImmutable();

        $lignes = [];
        foreach ($this->associations->listerParNom() as $association) {
            $id = (int) $association->getId();
            $invitation = $invitations[$id] ?? null;
            $nbVilles = $villes[$id] ?? ['total' => 0, 'brouillon' => 0];
            $nbComptes = $comptes[$id] ?? ['total' => 0, 'en_attente' => 0];
            $lignes[] = [
                'association' => $association,
                'villes' => $nbVilles,
                'comptes' => $nbComptes,
                'bureauCentral' => $bureauxCentraux[$id] ?? [],
                'invitation' => $invitation,
                'invitationEtat' => null === $invitation ? null : ($invitation->estValide($maintenant) ? 'en_attente' : 'expiree'),
                'derniereActivite' => $activites[$id] ?? null,
                'suppressionPossible' => 0 === $nbVilles['total'] && 0 === $nbComptes['total'],
                'abonnement' => $association->getAbonnement(),
            ];
        }

        return $lignes;
    }

    /**
     * Ce qui attend le super-admin : associations sans bureau central (invitation à envoyer, en attente ou expirée),
     * abonnements en retard, premiers paiements et échéances dans les 30 jours, abonnements à souscrire alors que
     * le bureau central est en place, associations suspendues.
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return list<array{type: string, ligne: array<string, mixed>}>
     */
    private function aTraiter(array $lignes, \DateTimeImmutable $aujourdhui): array
    {
        $aTraiter = [];
        foreach ($lignes as $ligne) {
            $association = $ligne['association'];
            $abonnement = $ligne['abonnement'];
            if (null !== $abonnement && $abonnement->estEnRetard($aujourdhui)) {
                $aTraiter[] = ['type' => 'abonnement_en_retard', 'ligne' => $ligne];
            } elseif (null !== $abonnement && $abonnement->echeanceProche($aujourdhui)) {
                $aTraiter[] = ['type' => null === $abonnement->getDernierPaiementLe() ? 'premier_paiement' : 'echeance_proche', 'ligne' => $ligne];
            } elseif (null !== $abonnement && $abonnement->attendLaSouscription() && [] !== $ligne['bureauCentral']) {
                $aTraiter[] = ['type' => 'a_souscrire', 'ligne' => $ligne];
            }
            if ([] === $ligne['bureauCentral']) {
                $aTraiter[] = ['type' => match ($ligne['invitationEtat']) {
                    'en_attente' => 'invitation_en_attente',
                    'expiree' => 'invitation_expiree',
                    default => 'sans_bureau_central',
                }, 'ligne' => $ligne];
            }
            if ($association->estSuspendue()) {
                $aTraiter[] = ['type' => 'suspendue', 'ligne' => $ligne];
            }
        }

        $poids = ['abonnement_en_retard' => 0, 'invitation_expiree' => 1, 'sans_bureau_central' => 2, 'suspendue' => 3, 'a_souscrire' => 4, 'premier_paiement' => 5, 'echeance_proche' => 6, 'invitation_en_attente' => 7];
        usort($aTraiter, static fn (array $a, array $b): int => $poids[$a['type']] <=> $poids[$b['type']]);

        return $aTraiter;
    }

    /** Les paramètres de la liste, toujours valides : recherche, statut, bureau central, abonnement, tri et sens. @return array{q: string, statut: string, bureau: string, abonnement: string, tri: string, sens: string} */
    private function filtres(Request $request): array
    {
        return [
            'q' => mb_substr(trim((string) $request->query->get('q', '')), 0, 100),
            'statut' => TableauAssociations::statut($request->query->get('statut')),
            'bureau' => TableauAssociations::bureau($request->query->get('bureau')),
            'abonnement' => TableauAssociations::abonnement($request->query->get('abonnement')),
            'tri' => TableauAssociations::tri($request->query->get('tri')),
            'sens' => TableauAssociations::sens($request->query->get('sens')),
        ];
    }
}
