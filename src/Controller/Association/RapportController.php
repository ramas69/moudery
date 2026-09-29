<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Association\Perimetre;
use App\Association\RapportAg;
use App\Entity\Association;
use App\Entity\Ville;
use App\Export\Tableur;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le rapport d'assemblée générale (F-30) : trois pages A4 à l'écran, prêtes à imprimer ou à enregistrer en PDF depuis
 * le navigateur, et l'export des mêmes tableaux en CSV pour Excel. Pour toute l'association (consolidé) ou, depuis le
 * 29 septembre 2026, pour la seule ville du périmètre : le bureau central choisit, un responsable de ville n'a que la
 * sienne (`VILLE_CONSULTER`).
 */
#[Route('/associations/{slug}/rapport', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ESPACE_OUVRIR, subject: 'association')]
final class RapportController extends AbstractController
{
    public function __construct(private readonly RapportAg $rapport, private readonly Perimetre $perimetre)
    {
    }

    #[Route('', name: 'association_rapport', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $exercice = $request->query->has('exercice') ? $request->query->getInt('exercice') : null;

        return $this->render('association/rapport/index.html.twig', [
            'association' => $association,
            ...$this->rapport->construire($association, new \DateTimeImmutable(), $exercice, $this->ville($association, $request)),
        ]);
    }

    /** Le rapport en CSV ou en XLSX (F-29, F-30) : les mêmes chiffres que la page, les montants en euros. */
    #[Route('/export.{format}', name: 'association_rapport_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function exporter(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request, TranslatorInterface $t, string $format, Tableur $tableur): Response
    {
        $exercice = $request->query->has('exercice') ? $request->query->getInt('exercice') : null;
        $ville = $this->ville($association, $request);
        $r = $this->rapport->construire($association, new \DateTimeImmutable(), $exercice, $ville);
        $euros = static fn (?int $centimes): ?float => null === $centimes ? null : $centimes / 100.0;

        $lignes = [
            [$t->trans('rapport_association.csv.titre', ['association' => null === $ville ? $association->getNom() : $association->getNom().' · '.$ville->getNom(), 'exercice' => $r['libelle']])],
            [],
            [$t->trans('rapport_association.synthese.titre')],
            [$t->trans('rapport_association.kpi.collecte'), $euros($r['finances']['collecte'])],
            [$t->trans('rapport_association.kpi.depense'), $euros($r['finances']['depense'])],
            [$t->trans('rapport_association.kpi.solde'), $euros($r['finances']['solde'])],
            [$t->trans('rapport_association.kpi.membres'), $r['membres']],
            [$t->trans('rapport_association.kpi.a_jour'), $r['finances']['avecHistorique'] > 0 ? $r['finances']['pourcentageAJour'].' %' : ''],
            [$t->trans('rapport_association.kpi.reverse'), $euros($r['reverse']), $euros($r['du'])],
            [],
            [$t->trans('rapport_association.entrees.titre'), $t->trans('rapport_association.csv.montant')],
        ];
        foreach ($r['entrees'] as $entree) {
            $lignes[] = ['cotisations' === $entree['libelle'] ? $t->trans('rapport_association.entrees.cotisations') : $entree['libelle'], $euros($entree['montant'])];
        }
        $lignes[] = [];
        $lignes[] = [$t->trans('rapport_association.depenses.titre'), $t->trans('rapport_association.csv.montant')];
        foreach ($r['depensesParCategorie'] as $categorie => $montant) {
            $lignes[] = [$t->trans('depenses_association.categorie.'.$categorie), $euros($montant)];
        }
        $lignes[] = [];
        $lignes[] = array_map(static fn (string $c): string => $t->trans('rapport_association.villes.'.$c), ['ville', 'membres', 'a_jour', 'collecte', 'depense', 'recu', 'du', 'solde']);
        foreach ($r['villes'] as $v) {
            $lignes[] = [$v['ville']->getNom(), $v['membres'], $v['aJour'], $euros($v['collecte']), $euros($v['depense']), $euros($v['recu']), $euros($v['du']), $euros($v['solde'])];
        }
        if (null === $ville) {
            $lignes[] = [$t->trans('rapport_association.villes.central'), '', '', '', $euros($r['depenseCentral']), '', '', ''];
        }
        $lignes[] = [];
        $lignes[] = array_map(static fn (string $c): string => $t->trans('rapport_association.appels.'.$c), ['appel', 'type', 'echeances', 'objectif', 'collecte', 'etat']);
        foreach ($r['appels'] as $a) {
            $lignes[] = [$a['appel']->getObjet(), $a['appel']->getType()->getNom(), $a['echeances'], $euros($a['objectif']), $euros($a['collecte']), $t->trans('appels_association.statut.'.$a['appel']->getStatut()->value)];
        }

        $titre = array_shift($lignes);

        return $tableur->reponse($format, \sprintf('rapport-ag-%s%s-%d', $association->getSlug(), null === $ville ? '' : '-'.(new \Symfony\Component\String\Slugger\AsciiSlugger())->slug($ville->getNom())->lower(), $r['exercice']), $titre, $lignes, $t->trans('rapport_association.titre'));
    }

    /** La ville du rapport : celle du périmètre (ou aucune pour le consolidé) ; un responsable de ville n'a que la sienne. */
    private function ville(Association $association, Request $request): ?Ville
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        if ($this->isGranted(Permission::ASSOCIATION_PILOTER, $association)) {
            return $ville;
        }
        if (null === $ville || !$this->isGranted(Permission::VILLE_CONSULTER, $ville)) {
            throw $this->createAccessDeniedException();
        }

        return $ville;
    }
}
