<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Administration\GestionVilles;
use App\Administration\TableauVilles;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Form\Model\VilleAdministrationData;
use App\Form\VilleAdministrationType;
use App\Repository\AssociationRepository;
use App\Repository\VilleRepository;
use App\Security\Permission;
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
 * Administration des villes de toutes les associations par le super-admin : liste, renommage et changement de statut.
 * La création reste au bureau central, dans l'assistant (règle du cahier des charges).
 */
#[Route('/administration/villes')]
#[IsGranted(Permission::PLATEFORME_ADMINISTRER)]
final class VilleController extends AbstractController
{
    public function __construct(
        private readonly GestionVilles $gestion,
        private readonly VilleRepository $villes,
        private readonly AssociationRepository $associations,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    /** Toutes les villes de la plateforme : recherche, association, statut, tri, regroupement, pagination. */
    #[Route('', name: 'administration_villes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $aujourdhui = new \DateTimeImmutable();
        $filtres = self::filtres($request);
        $associations = $this->associations->listerParNom();
        $association = TableauVilles::associationCorrespondante($associations, $filtres['association']);
        $toutes = $this->villes->listerToutes($association);

        $sansStatut = TableauVilles::filtrer($toutes, $filtres['q'], 'tous', $aujourdhui);
        $villes = TableauVilles::trier(
            TableauVilles::filtrer($sansStatut, '', $filtres['statut'], $aujourdhui),
            $filtres['tri'],
            $filtres['sens'],
            '' !== $filtres['groupe'],
        );

        return $this->render('administration/ville/index.html.twig', [
            'pagination' => TableauVilles::paginer($villes, TableauVilles::page($request->query->get('page')), TableauVilles::parPage($request->query->get('par'))),
            'total' => \count($toutes),
            'filtres' => $filtres,
            'association' => $association,
            'associations' => $associations,
            'compteurs' => TableauVilles::compter($sansStatut, $aujourdhui),
            'aujourdhui' => $aujourdhui,
            'parPage' => TableauVilles::PAR_PAGE,
        ]);
    }

    /** La liste filtrée et triée, en CSV pour le tableur (point-virgule, UTF-8 avec BOM). */
    #[Route('/export.csv', name: 'administration_villes_export', methods: ['GET'])]
    public function exporter(Request $request, TranslatorInterface $traducteur): Response
    {
        $aujourdhui = new \DateTimeImmutable();
        $filtres = self::filtres($request);
        $association = TableauVilles::associationCorrespondante($this->associations->listerParNom(), $filtres['association']);
        $villes = TableauVilles::trier(
            TableauVilles::filtrer($this->villes->listerToutes($association), $filtres['q'], $filtres['statut'], $aujourdhui),
            $filtres['tri'],
            $filtres['sens'],
            '' !== $filtres['groupe'],
        );

        $colonnes = ['ville', 'association', 'identifiant', 'village', 'statut', 'etape', 'responsables', 'creee_le', 'modifiee_le', 'bloquee'];
        $flux = fopen('php://temp', 'r+');
        \assert(false !== $flux);
        fwrite($flux, "\u{FEFF}");
        fputcsv($flux, array_map(static fn (string $c): string => $traducteur->trans('administration.villes.csv.'.$c), $colonnes), ';', '"', '');
        foreach ($villes as $ville) {
            $associationDeLaVille = $ville->getAssociation();
            fputcsv($flux, [
                $ville->getNom(),
                $associationDeLaVille->getNom(),
                $associationDeLaVille->getSlug(),
                $associationDeLaVille->getNomVillage(),
                $traducteur->trans('assistant_ville.statut.'.$ville->getStatut()->value),
                $traducteur->trans('administration.villes.etape', ['numero' => $ville->getEtapeAssistant()->numero(), 'libelle' => $traducteur->trans('assistant_ville.etapes.'.$ville->getEtapeAssistant()->value)]),
                (string) \count($ville->getInvitations()),
                $ville->getCreeLe()->format('Y-m-d'),
                $ville->getModifieLe()->format('Y-m-d'),
                $traducteur->trans(TableauVilles::estBloquee($ville, $aujourdhui) ? 'administration.villes.csv.oui' : 'administration.villes.csv.non'),
            ], ';', '"', '');
        }
        rewind($flux);
        $contenu = (string) stream_get_contents($flux);
        fclose($flux);

        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="villes-'.$aujourdhui->format('Y-m-d').'.csv"',
        ]);
    }

    /** Les paramètres de la liste, toujours valides. @return array{q: string, association: string, statut: string, tri: string, sens: string, groupe: string} */
    private static function filtres(Request $request): array
    {
        return [
            'q' => trim((string) $request->query->get('q', '')),
            'association' => trim((string) $request->query->get('association', '')),
            'statut' => TableauVilles::statut($request->query->get('statut')),
            'tri' => TableauVilles::tri($request->query->get('tri')),
            'sens' => TableauVilles::sens($request->query->get('sens')),
            'groupe' => '' !== (string) $request->query->get('groupe', '') ? '1' : '',
        ];
    }

    #[Route('/{id}/modifier', name: 'administration_ville_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(Permission::VILLE_MODIFIER, subject: 'ville')]
    public function modifier(#[MapEntity] Ville $ville, Request $request): Response
    {
        $donnees = VilleAdministrationData::depuisVille($ville);
        $form = $this->createForm(VilleAdministrationType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->gestion->modifier($ville, $donnees, $this->acteur());
                $this->addFlash('succes', $this->traducteur->trans('administration.ville.modifiee', ['nom' => $ville->getNom()]));

                return $this->redirectToRoute('administration_ville_modifier', ['id' => $ville->getId()], Response::HTTP_SEE_OTHER);
            } catch (UniqueConstraintViolationException) {
                $form->get('nom')->addError(new FormError($this->traducteur->trans('ville.nom.deja_utilise', [], 'validators')));
            }
        }

        $statut = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('administration/ville/formulaire.html.twig', ['form' => $form, 'ville' => $ville], new Response(status: $statut));
    }

    /** Le compte connecté, consigné dans le journal comme auteur de l'action. */
    private function acteur(): ?Utilisateur
    {
        $utilisateur = $this->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur : null;
    }
}
