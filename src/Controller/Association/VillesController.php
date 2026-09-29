<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Association\Reversements;
use App\Association\VillesAssociation;
use App\Entity\Association;
use App\Repository\VilleRepository;
use App\Entity\VilleStatut;
use App\Entity\Ville;
use App\Entity\Utilisateur;
use App\Administration\GestionVilles;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les villes de l'association, vues par le bureau central (F-28) : statut, avancement, responsables, membres, à jour,
 * collecté. La page et l'export CSV lisent les mêmes filtres : recherche, chips de statut et panneau « Filtres »
 * (année des chiffres, responsables, membres).
 */
#[Route('/associations/{slug}/villes', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ASSOCIATION_PILOTER, subject: 'association')]
final class VillesController extends AbstractController
{
    #[Route('', name: 'association_villes', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request, VillesAssociation $villes, Reversements $reversements): Response
    {
        $filtres = self::filtres($request);
        $aujourdhui = new \DateTimeImmutable();
        $tableau = $villes->tableau($association, $filtres['statut'], $filtres['q'], $aujourdhui, '' !== $filtres['annee'] ? (int) $filtres['annee'] : null, $filtres['responsables'], $filtres['membres']);
        // Le reversement dû de chaque ville (restant sur l'exercice de l'année affichée) vient du module Reversements.
        $restants = [];
        foreach ($reversements->tableau($association, $aujourdhui, $tableau['annee'])['lignes'] as $ligne) {
            $restants[(int) $ligne['ville']->getId()] = $ligne['restant'];
        }
        foreach ($tableau['lignes'] as &$ligne) {
            $ligne['reversement'] = $restants[(int) $ligne['ville']->getId()] ?? null;
        }
        unset($ligne);

        return $this->render('association/villes/index.html.twig', [
            'association' => $association,
            'aujourdhui' => $aujourdhui,
            'filtres' => $filtres,
            ...$tableau,
        ]);
    }

    /** Le tableau tel qu'il est filtré, en CSV (point-virgule, BOM UTF-8) : une ligne par ville, les montants en euros. */
    #[Route('/export.csv', name: 'association_villes_export', methods: ['GET'])]
    public function exporter(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request, VillesAssociation $villes, TranslatorInterface $traducteur): Response
    {
        $filtres = self::filtres($request);
        $aujourdhui = new \DateTimeImmutable();
        $tableau = $villes->tableau($association, $filtres['statut'], $filtres['q'], $aujourdhui, '' !== $filtres['annee'] ? (int) $filtres['annee'] : null, $filtres['responsables'], $filtres['membres']);
        $annee = $tableau['annee'];

        $colonnes = ['ville', 'statut', 'etape', 'responsables', 'invitations_en_attente', 'membres', 'adherents', 'a_jour', 'taux_a_jour', 'collecte', 'reversement', 'creee_le', 'archivee_le'];
        $flux = fopen('php://temp', 'r+');
        \assert(false !== $flux);
        fwrite($flux, "\u{FEFF}");
        fputcsv($flux, array_map(static fn (string $c): string => $traducteur->trans('villes_association.csv.'.$c, ['annee' => $annee]), $colonnes), ';', '"', '');
        foreach ($tableau['lignes'] as $ligne) {
            $ville = $ligne['ville'];
            $responsables = array_map(
                static fn (array $r): string => \sprintf('%s (%s)', $r['nom'] ?? $r['email'], $traducteur->trans('villes_association.roles.'.$r['role'])),
                $ligne['responsables'],
            );
            $enAttente = array_filter($ligne['responsables'], static fn (array $r): bool => !$r['acceptee']);
            fputcsv($flux, [
                $ville->getNom(),
                $traducteur->trans('assistant_ville.statut.'.$ville->getStatut()->value),
                $ville->estBrouillon()
                    ? $traducteur->trans('villes_association.etape', ['numero' => $ville->getEtapeAssistant()->numero(), 'total' => 3, 'libelle' => $traducteur->trans('assistant_ville.etapes.'.$ville->getEtapeAssistant()->value)])
                    : '',
                implode(', ', $responsables),
                (string) \count($enAttente),
                (string) $ligne['membres'],
                (string) $ligne['adherents'],
                $ligne['avecHistorique'] > 0 ? (string) $ligne['aJour'] : '',
                null !== $ligne['tauxAJour'] ? (string) $ligne['tauxAJour'] : '',
                $ligne['avecHistorique'] > 0 ? number_format($ligne['collecte'] / 100, 2, ',', '') : '',
                '',
                $ville->getCreeLe()->format('Y-m-d'),
                $ville->estArchivee() ? $ville->getModifieLe()->format('Y-m-d') : '',
            ], ';', '"', '');
        }
        rewind($flux);
        $contenu = (string) stream_get_contents($flux);
        fclose($flux);

        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="villes-%s-%s.csv"', $association->getSlug(), $aujourdhui->format('Y-m-d')),
        ]);
    }

    /**
     * Les filtres de la page, assainis : statut parmi les chips, recherche, année plausible (sinon vide = année de
     * référence), responsables et membres parmi les valeurs du panneau.
     *
     * @return array{statut: string, q: string, annee: string, responsables: string, membres: string}
     */
    private static function filtres(Request $request): array
    {
        $statut = (string) $request->query->get('statut', 'tous');
        $responsables = (string) $request->query->get('responsables', 'tous');
        $membres = (string) $request->query->get('membres', 'tous');
        $annee = (string) $request->query->get('annee', '');
        $anneeMax = (int) date('Y') + 1;

        return [
            'statut' => \in_array($statut, VillesAssociation::STATUTS, true) ? $statut : 'tous',
            'q' => trim((string) $request->query->get('q', '')),
            'annee' => preg_match('/^\d{4}$/', $annee) && (int) $annee >= 2000 && (int) $annee <= $anneeMax ? $annee : '',
            'responsables' => \in_array($responsables, VillesAssociation::RESPONSABLES, true) ? $responsables : 'tous',
            'membres' => \in_array($membres, VillesAssociation::MEMBRES, true) ? $membres : 'tous',
        ];
    }

    /** Archiver une ville active, ou réactiver une ville archivée, depuis l'espace : jamais de suppression. */
    #[Route('/{id}/statut/{action}', name: 'association_ville_statut', requirements: ['id' => '\d+', 'action' => 'archiver|reactiver'], methods: ['POST'])]
    public function statut(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, string $action, Request $request, GestionVilles $gestion, TranslatorInterface $traducteur, VilleRepository $villesRepository): Response
    {
        $ville = $villesRepository->trouverDansAssociation($id, $association->getSlug());
        if (!$ville instanceof Ville) {
            throw $this->createNotFoundException('Ville introuvable.');
        }
        if (!$this->isCsrfTokenValid('ville-statut-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $statut = 'archiver' === $action ? VilleStatut::Archivee : VilleStatut::Active;
        try {
            $acteur = $this->getUser();
            $gestion->changerStatut($ville, $statut, $acteur instanceof Utilisateur ? $acteur : null);
            $this->addFlash('succes', $traducteur->trans('villes_association.flash.'.$action, ['ville' => $ville->getNom()]));
        } catch (\LogicException) {
            $this->addFlash('erreur', $traducteur->trans('villes_association.flash.impossible', ['ville' => $ville->getNom()]));
        }

        return $this->redirectToRoute('association_villes', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }
}
