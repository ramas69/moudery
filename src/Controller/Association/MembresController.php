<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Entity\Adhesion;
use App\Association\Perimetre;
use App\Entity\Association;
use App\Ville\Membres;
use App\Repository\PaiementRepository;
use App\Repository\EcheanceRepository;
use App\Entity\Utilisateur;
use App\Export\Tableur;
use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\Ville;
use App\Repository\AdhesionRepository;
use App\Repository\MembreRepository;
use App\Repository\VilleRepository;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les membres de toute l'association, vus par le bureau central (F-28) : la ville du périmètre et une année d'adhésion au choix,
 * l'année en cours par défaut (ou la dernière qui a des adhérents), comme les onglets du classeur que l'application
 * remplace. Export CSV de la sélection.
 */
#[Route('/associations/{slug}/membres', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ESPACE_OUVRIR, subject: 'association')]
final class MembresController extends AbstractController
{
    public const int PAR_PAGE = 25;
    public const string TOUTES = 'toutes';

    public function __construct(
        private readonly MembreRepository $membres,
        private readonly VilleRepository $villes,
        private readonly AdhesionRepository $adhesions,
        private readonly Perimetre $perimetre,
        private readonly TranslatorInterface $traducteur,
        private readonly EcheanceRepository $echeances,
        private readonly PaiementRepository $paiements,
    ) {
    }

    #[Route('', name: 'association_membres', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $villes = $this->villes->listerPourAssociation($association);
        $selection = $this->selection($request, $villes, $association);
        $page = max(1, $request->query->getInt('page', 1));

        // Un responsable de ville a la page de la trésorière (artboard « 05 Membres · trésorière ») : ce que chaque
        // membre doit, son dernier paiement, sa situation, les inscriptions à valider. Le bureau central garde la vue
        // par ville et année d'adhésion (le classeur), même avec une ville dans le périmètre.
        if (null !== $selection['ville'] && !$this->isGranted(Permission::ASSOCIATION_PILOTER, $association)) {
            return $this->villeVue($association, $selection['ville'], $request, $page);
        }

        $resultat = $this->membres->rechercherPourAssociation($association, $selection['ville'], $selection['annee'], $selection['q'], $selection['statut'], $page, self::PAR_PAGE);
        $pages = max(1, (int) ceil($resultat['total'] / self::PAR_PAGE));
        $parAnnee = $selection['parAnnee'];
        $anneeCourante = (int) date('Y');
        // Les exercices suivis (depuis le premier exercice de l'association) se proposent toujours, même sans adhérent.
        foreach ($association->exercices($anneeCourante) as $exercice) {
            $parAnnee[$exercice] ??= 0;
        }
        krsort($parAnnee);

        return $this->render('association/membres/index.html.twig', [
            'association' => $association,
            'villes' => $villes,
            'ville' => $selection['ville'],
            'perimetreVille' => null !== $selection['ville'],
            'annee' => $selection['annee'],
            'anneeCourante' => $anneeCourante,
            'filtres' => $selection['filtres'],
            'membres' => $resultat['membres'],
            'total' => $resultat['total'],
            'anneesParMembre' => $this->membres->anneesAdhesion($resultat['membres']),
            'parVille' => $this->membres->compterParVille($association, $selection['annee']),
            'parAnnee' => $parAnnee,
            'statistiques' => $this->membres->statistiquesPourAssociation($association, $selection['ville'], $selection['annee']),
            'pagination' => ['page' => min($page, $pages), 'pages' => $pages, 'de' => 0 === $resultat['total'] ? 0 : ($page - 1) * self::PAR_PAGE + 1, 'a' => min($page * self::PAR_PAGE, $resultat['total'])],
        ]);
    }

    /** La sélection courante en CSV (point-virgule, BOM UTF-8 pour Excel) ou en XLSX (F-29), toutes pages confondues. */
    #[Route('/export.{format}', name: 'association_membres_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function export(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request, string $format, Tableur $tableur): Response
    {
        $villes = $this->villes->listerPourAssociation($association);
        $selection = $this->selection($request, $villes, $association);
        $resultat = $this->membres->rechercherPourAssociation($association, $selection['ville'], $selection['annee'], $selection['q'], $selection['statut'], 1, 100000);
        // Une sélection cochée sur la page Membres (`ids[]`) restreint l'export à ces membres.
        $ids = array_map('intval', (array) $request->query->all('ids'));
        if ([] !== $ids) {
            $resultat['membres'] = array_values(array_filter($resultat['membres'], static fn (Membre $m): bool => \in_array((int) $m->getId(), $ids, true)));
        }
        $annees = $this->membres->anneesAdhesion($resultat['membres']);
        // Avec une année : les colonnes du classeur (les douze mois, rapatriement, projet, total, reste) pour l'adhésion de cette année.
        $annee = $selection['annee'];
        $adhesions = null !== $annee ? $this->adhesions->pourMembresEtAnnee($resultat['membres'], $annee) : [];

        $enTetes = array_map(fn (string $c): string => $this->traducteur->trans('membres_association.export.'.$c), ['ville', 'nom', 'prenom', 'email', 'telephone', 'localite', 'foyer', 'adhesions', 'statut']);
        if (null !== $annee) {
            $enTetes[] = $this->traducteur->trans('membres_association.export.annee');
            // Les mois dans l'ordre de l'exercice, avec l'année civile quand l'exercice chevauche deux années.
            $debut = $association->getDebutExerciceMois();
            foreach (Adhesion::ordreDesMois($debut) as $mois) {
                $enTetes[] = $this->traducteur->trans('membres_association.mois.'.$mois).($debut > 1 ? ' '.($mois >= $debut ? $annee : $annee + 1) : '');
            }
            array_push($enTetes, ...array_map(fn (string $c): string => $this->traducteur->trans('membres_association.export.'.$c), ['rapatriement', 'projet', 'total', 'reste']));
        }
        $lignes = [];
        foreach ($resultat['membres'] as $membre) {
            \assert($membre instanceof Membre);
            $ligne = [
                $membre->getVille()->getNom(),
                $membre->getNom(),
                $membre->getPrenom(),
                $membre->getEmail() ?? '',
                $membre->getTelephoneAffiche() ?? '',
                $membre->getLocalite() ?? '',
                $membre->getFoyer()?->getNom() ?? '',
                implode(' ', $annees[(int) $membre->getId()] ?? []),
                $this->traducteur->trans('membre.statut.'.$membre->getStatut()->value),
            ];
            if (null !== $annee) {
                $adhesion = $adhesions[(int) $membre->getId()] ?? null;
                $ligne[] = (string) $annee;
                foreach (Adhesion::ordreDesMois($association->getDebutExerciceMois()) as $mois) {
                    $ligne[] = null === $adhesion ? '' : ($adhesion->getCodeMois($mois) ?? self::euros($adhesion->getMontantMois($mois)));
                }
                array_push($ligne, self::euros($adhesion?->getRapatriement()), self::euros($adhesion?->getProjet()), null === $adhesion || !$adhesion->aUnHistorique() ? '' : self::euros($adhesion->getTotal()), self::euros($adhesion?->getReste()));
            }
            $lignes[] = $ligne;
        }

        $nom = \sprintf('membres-%s%s%s', $association->getSlug(), null !== $selection['ville'] ? '-'.$selection['ville']->getId() : '', null !== $selection['annee'] ? '-'.$selection['annee'] : '');

        return $tableur->reponse($format, $nom, $enTetes, $lignes, $this->traducteur->trans('membres_association.titre'));
    }

    /** Des centimes en euros pour le tableur : « 1000 » donne « 10 », « 1050 » donne « 10,5 », rien pour null. */
    private static function euros(?int $centimes): string
    {
        if (null === $centimes) {
            return '';
        }
        $euros = $centimes / 100;

        return str_replace('.', ',', (string) (floor($euros) === $euros ? (int) $euros : round($euros, 2)));
    }

    public const array FILTRES_VILLE = ['tous', 'a_jour', 'en_retard', 'en_attente', 'sortis'];

    /** La page des membres d'une ville : chips à jour / en retard / en attente, reste dû, dernier paiement, sélection multiple. */
    private function villeVue(Association $association, Ville $ville, Request $request, int $page): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $filtre = (string) $request->query->get('filtre', 'tous');
        $filtre = \in_array($filtre, self::FILTRES_VILLE, true) ? $filtre : 'tous';
        $aujourdhui = new \DateTimeImmutable();

        $tous = $this->membres->rechercherPourAssociation($association, $ville, null, $q, null, 1, 100000)['membres'];
        $dues = $this->echeances->duesParMembre($association, $ville, $aujourdhui);
        $derniers = $this->paiements->derniersParMembre($association, $ville);

        $lignes = [];
        $compteurs = array_fill_keys(self::FILTRES_VILLE, 0);
        foreach ($tous as $membre) {
            \assert($membre instanceof Membre);
            $du = $dues[(int) $membre->getId()] ?? ['nombre' => 0, 'montant' => 0, 'retard' => 0];
            $etat = match (true) {
                MembreStatut::Sorti === $membre->getStatut() => 'sortis',
                MembreStatut::EnAttente === $membre->getStatut() => 'en_attente',
                $du['retard'] > 0 => 'en_retard',
                default => 'a_jour',
            };
            ++$compteurs['tous'];
            ++$compteurs[$etat];
            if ('tous' === $filtre ? 'sortis' === $etat : $filtre !== $etat) {
                continue;
            }
            $lignes[] = ['membre' => $membre, 'etat' => $etat, 'du' => $du, 'dernier' => $derniers[(int) $membre->getId()] ?? null];
        }
        $enAttente = array_values(array_filter($tous, static fn (Membre $m): bool => MembreStatut::EnAttente === $m->getStatut()));
        usort($enAttente, static fn (Membre $a, Membre $b): int => $a->getCreeLe() <=> $b->getCreeLe());

        $total = \count($lignes);
        $pages = max(1, (int) ceil($total / self::PAR_PAGE));
        $page = min($page, $pages);
        $lignes = \array_slice($lignes, ($page - 1) * self::PAR_PAGE, self::PAR_PAGE);

        return $this->render('association/membres/ville.html.twig', [
            'association' => $association,
            'ville' => $ville,
            'aujourdhui' => $aujourdhui,
            'q' => $q,
            'filtre' => $filtre,
            'compteurs' => $compteurs,
            'lignes' => $lignes,
            'total' => $total,
            'enAttente' => $enAttente,
            'peutGerer' => $this->isGranted(Permission::MEMBRE_GERER, $ville),
            'peutSaisir' => $this->isGranted(Permission::PAIEMENT_SAISIR, $ville),
            'pagination' => ['page' => $page, 'pages' => $pages, 'de' => 0 === $total ? 0 : ($page - 1) * self::PAR_PAGE + 1, 'a' => min($page * self::PAR_PAGE, $total)],
        ]);
    }

    /** « Tout valider » : les inscriptions en attente de la ville du périmètre passent actives. */
    #[Route('/valider', name: 'association_membres_valider', methods: ['POST'])]
    public function valider(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request, Membres $service): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        if (null === $ville) {
            return $this->redirectToRoute('association_membres', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }
        $this->denyAccessUnlessGranted(Permission::MEMBRE_GERER, $ville);
        if (!$this->isCsrfTokenValid('membres-valider', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $acteur = $this->getUser();
        $nombre = $service->validerEnAttente($ville, $acteur instanceof Utilisateur ? $acteur : null);
        $this->addFlash('succes', $this->traducteur->trans('membres_association.ville.flash_valides', ['nombre' => $nombre]));

        return $this->redirectToRoute('association_membres', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }

    /**
     * La ville et l'année choisies : « toutes » ou une valeur. Sans paramètre : toutes les villes et l'année en cours,
     * ou, tant que personne n'y adhère encore (un classeur importé s'arrête souvent à l'année passée), l'année la plus
     * récente qui a des adhérents, pour ne jamais ouvrir sur une page vide.
     *
     * @param list<Ville> $villes
     *
     * @return array{ville: ?Ville, annee: ?int, q: string, statut: ?MembreStatut, filtres: array<string, string>, parAnnee: array<int, int>}
     */
    private function selection(Request $request, array $villes, Association $association): array
    {
        // La ville du périmètre (barre latérale), ou ?ville= qui devient le périmètre ; « toutes » ramène à l'association.
        $ville = $this->perimetre->villeCourante($association, $request);

        $parAnnee = $this->membres->compterParAnnee($association, $ville);
        $anneeCourante = (int) date('Y');
        $anneeParDefaut = ($parAnnee[$anneeCourante] ?? 0) > 0 || [] === $parAnnee ? $anneeCourante : (int) array_key_first($parAnnee);
        $anneeChoisie = (string) $request->query->get('annee', (string) $anneeParDefaut);
        $annee = self::TOUTES === $anneeChoisie ? null : (preg_match('/^\d{4}$/', $anneeChoisie) ? (int) $anneeChoisie : $anneeParDefaut);

        $q = trim((string) $request->query->get('q', ''));
        $statut = MembreStatut::tryFrom((string) $request->query->get('statut', ''));

        return [
            'ville' => $ville,
            'annee' => $annee,
            'q' => $q,
            'statut' => $statut,
            'filtres' => ['ville' => null !== $ville ? (string) $ville->getId() : '', 'annee' => null === $annee ? self::TOUTES : (string) $annee, 'q' => $q, 'statut' => $statut?->value ?? ''],
            'parAnnee' => $parAnnee,
        ];
    }
}
