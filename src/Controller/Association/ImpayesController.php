<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Association\Perimetre;
use App\Entity\Association;
use App\Entity\Membre;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Export\Tableur;
use App\Relance\Relances;
use App\Repository\MembreRepository;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Impayés et relances (F-24, F-25, artboard « Impayés et relances ») : les membres en retard avec ce qu'ils doivent,
 * recherche, filtres et sélecteur d'origine, la relance par e-mail (sélection, une ligne ou tous), « Marquer comme
 * appelé », les prochains envois automatiques, l'export.
 */
#[Route('/associations/{slug}/impayes', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ESPACE_OUVRIR, subject: 'association')]
final class ImpayesController extends AbstractController
{
    private const int PAR_PAGE = 25;

    public function __construct(
        private readonly Relances $relances,
        private readonly MembreRepository $membres,
        private readonly Perimetre $perimetre,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'association_impayes', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        $aujourdhui = new \DateTimeImmutable();
        $impayes = $this->relances->impayes($association, $ville, $aujourdhui);
        [$filtre, $q, $source] = $this->selection($request, $impayes);
        $lignes = Relances::filtrer($impayes, $filtre, $q, $source);

        $total = \count($lignes);
        $pages = max(1, (int) ceil($total / self::PAR_PAGE));
        $page = max(1, min($request->query->getInt('page', 1), $pages));
        $lignes = \array_slice($lignes, ($page - 1) * self::PAR_PAGE, self::PAR_PAGE);

        return $this->render('association/impayes/index.html.twig', [
            'association' => $association,
            'ville' => $ville,
            'aujourdhui' => $aujourdhui,
            'lignes' => $lignes,
            'total' => $total,
            'pagination' => ['page' => $page, 'pages' => $pages, 'de' => 0 === $total ? 0 : ($page - 1) * self::PAR_PAGE + 1, 'a' => min($total, $page * self::PAR_PAGE)],
            'compteurs' => Relances::compteurs($impayes),
            'sources' => Relances::sources($impayes),
            'filtre' => $filtre,
            'q' => $q,
            'source' => $source,
            'anciennete' => Relances::anciennete($impayes, $aujourdhui),
            'totalMontant' => array_sum(array_column($impayes, 'montant')),
            'totalEcheances' => array_sum(array_map(static fn (array $i): int => \count($i['echeances']), $impayes)),
            'joignables' => \count(array_filter($impayes, static fn (array $i): bool => $i['joignable'])),
            'prochaines' => $this->relances->prochainesAutomatiques($association, $ville, $aujourdhui),
            'calendrier' => $association->getCalendrierRelances(),
            'peutRelancer' => $this->isGranted(Permission::PAIEMENT_SAISIR, $ville ?? $association),
            'pilote' => $this->isGranted(Permission::ASSOCIATION_PILOTER, $association),
        ]);
    }

    /** Relance par e-mail des membres cochés, d'un seul depuis sa ligne, ou de tous les membres joignables (`tous=1`). */
    #[Route('/relancer', name: 'association_impayes_relancer', methods: ['POST'])]
    public function relancer(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('impayes-relancer', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $ville = $this->perimetre->villeCourante($association, $request);
        $membres = $this->membresChoisis($association, $ville, $request);
        if ([] === $membres) {
            $this->addFlash('erreur', $this->traducteur->trans('impayes_association.flash.aucun_choix'));

            return $this->redirectToRoute('association_impayes', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }

        $bilan = $this->relances->relancer($membres, $this->compte(), new \DateTimeImmutable());
        $this->addFlash($bilan['envoyees'] > 0 ? 'succes' : 'erreur', $this->traducteur->trans('impayes_association.flash.envoyees', ['nombre' => $bilan['envoyees'], 'ignores' => $bilan['ignores']]));

        return $this->redirectToRoute('association_impayes', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }

    /** « Marquer comme appelé » : l'appel téléphonique est noté comme une relance, sans e-mail. */
    #[Route('/appeler', name: 'association_impayes_appeler', methods: ['POST'])]
    public function appeler(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('impayes-appeler', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $ville = $this->perimetre->villeCourante($association, $request);
        $membres = $this->membresChoisis($association, $ville, $request);
        if ([] === $membres) {
            $this->addFlash('erreur', $this->traducteur->trans('impayes_association.flash.aucun_choix'));

            return $this->redirectToRoute('association_impayes', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }

        $bilan = $this->relances->marquerAppeles($membres, $this->compte(), new \DateTimeImmutable());
        $this->addFlash($bilan['marques'] > 0 ? 'succes' : 'erreur', $this->traducteur->trans('impayes_association.flash.appeles', ['nombre' => $bilan['marques'], 'ignores' => $bilan['ignores']]));

        return $this->redirectToRoute('association_impayes', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }

    /** Le tableau des impayés tel qu'il est filtré, en CSV ou en XLSX (F-29). */
    #[Route('/export.{format}', name: 'association_impayes_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function exporter(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request, string $format, Tableur $tableur): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        $aujourdhui = new \DateTimeImmutable();
        $impayes = $this->relances->impayes($association, $ville, $aujourdhui);
        [$filtre, $q, $source] = $this->selection($request, $impayes);
        $enTetes = array_map(fn (string $c): string => $this->traducteur->trans('impayes_association.csv.'.$c), ['membre', 'ville', 'du', 'echeances', 'montant', 'retard', 'derniere_relance', 'canal', 'rappels', 'email']);
        $lignes = [];
        foreach (Relances::filtrer($impayes, $filtre, $q, $source) as $impaye) {
            $derniere = $impaye['derniereRelance'];
            $lignes[] = [
                $impaye['membre']->getNomComplet(),
                $impaye['membre']->getVille()->getNom(),
                implode(' ; ', array_map(static fn (array $s): string => $s['libelle'].' ('.$s['nombre'].')', $impaye['sources'])),
                \count($impaye['echeances']),
                $impaye['montant'] / 100.0,
                $impaye['retard'],
                null !== $derniere ? $derniere->getEnvoyeeLe()->format('Y-m-d') : '',
                null !== $derniere ? $this->traducteur->trans('impayes_association.csv.canal_'.($derniere->estUnAppel() ? 'telephone' : ($derniere->estAutomatique() ? 'auto' : 'email'))) : '',
                $impaye['rappels'],
                (string) $impaye['membre']->getEmail(),
            ];
        }

        return $tableur->reponse($format, \sprintf('impayes-%s-%s', $association->getSlug(), $aujourdhui->format('Y-m-d')), $enTetes, $lignes, $this->traducteur->trans('impayes_association.titre'));
    }

    /**
     * Le filtre, la recherche et l'origine demandés, ramenés à des valeurs connues.
     *
     * @param list<array<string, mixed>> $impayes
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function selection(Request $request, array $impayes): array
    {
        $filtre = (string) $request->query->get('filtre', Relances::FILTRE_TOUS);
        $filtre = \in_array($filtre, Relances::FILTRES, true) ? $filtre : Relances::FILTRE_TOUS;
        $q = trim((string) $request->query->get('q', ''));
        $source = (string) $request->query->get('echeance', Relances::SOURCE_TOUTES);
        if (Relances::SOURCE_TOUTES !== $source && !isset(Relances::sources($impayes)[$source])) {
            $source = Relances::SOURCE_TOUTES;
        }

        return [$filtre, $q, $source];
    }

    /**
     * Les membres visés par une action : ceux cochés (`membres[]`) ou, avec `tous=1`, tous les membres joignables en
     * retard du périmètre ; toujours de l'association, du périmètre, et d'une ville où la personne saisit les paiements.
     *
     * @return list<Membre>
     */
    private function membresChoisis(Association $association, ?Ville $ville, Request $request): array
    {
        if ($request->request->getBoolean('tous')) {
            $candidats = array_map(static fn (array $i): Membre => $i['membre'], array_filter($this->relances->impayes($association, $ville, new \DateTimeImmutable()), static fn (array $i): bool => $i['joignable']));
        } else {
            $candidats = [];
            foreach (array_unique(array_map('intval', (array) $request->request->all('membres'))) as $id) {
                $membre = $id > 0 ? $this->membres->find($id) : null;
                if ($membre instanceof Membre) {
                    $candidats[] = $membre;
                }
            }
        }
        $membres = [];
        foreach ($candidats as $membre) {
            if ($membre->getAssociation() === $association && (null === $ville || $membre->getVille() === $ville) && $this->isGranted(Permission::PAIEMENT_SAISIR, $membre->getVille())) {
                $membres[] = $membre;
            }
        }

        return $membres;
    }

    private function compte(): ?Utilisateur
    {
        $compte = $this->getUser();

        return $compte instanceof Utilisateur ? $compte : null;
    }
}
