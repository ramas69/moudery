<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Association\ImportAssociation;
use App\Entity\Association;
use App\Entity\Utilisateur;
use App\Form\ImportMembresType;
use App\Form\Model\RattachementImportData;
use App\Form\RattachementImportType;
use App\Repository\VilleRepository;
use App\Security\Permission;
use App\Ville\DepotImport;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Import d'un fichier pour toute l'association (F-31), réservé à qui peut créer des villes (le bureau central) :
 * dépôt du fichier, rattachement des onglets ou d'une colonne « caisse » aux villes, aperçu, confirmation, bilan.
 */
#[Route('/associations/{slug}/import', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::VILLE_CREER, subject: 'association')]
final class ImportController extends AbstractController
{
    private const string CLE_SESSION = 'association.import';
    private const string CLE_SESSION_BILAN = 'association.import.bilan';

    public function __construct(
        private readonly ImportAssociation $import,
        private readonly DepotImport $depot,
        private readonly VilleRepository $villes,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'association_import', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        return $this->afficherDepot($association, $request, $this->formulaireFichier($association));
    }

    /** Dépose le fichier, reconnaît ses feuilles et conduit au rattachement. */
    #[Route('', name: 'association_import_deposer', methods: ['POST'])]
    public function deposer(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $form = $this->formulaireFichier($association);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $fichier = $form->get('fichier')->getData();
            \assert($fichier instanceof UploadedFile);
            $contenu = (string) file_get_contents($fichier->getPathname());

            $erreur = null;
            $feuilles = [];
            try {
                $feuilles = $this->import->reconnaitre($contenu, $association);
            } catch (\RuntimeException) {
                $erreur = 'illisible';
            }
            if (null === $erreur && [] === array_filter($feuilles, static fn (array $f): bool => $f['lignes'] > 0)) {
                $erreur = 'vide';
            }

            if (null !== $erreur) {
                $form->get('fichier')->addError(new FormError($this->traducteur->trans('membre.import.'.$erreur, [], 'validators')));
            } else {
                $this->oublier($request, $association);
                $importables = array_filter($feuilles, static fn (array $f): bool => $f['importable']);
                $request->getSession()->set(self::CLE_SESSION, [
                    'association' => $association->getId(),
                    'jeton' => $this->depot->deposer($contenu),
                    'fichier' => $fichier->getClientOriginalName(),
                    'feuilles' => $feuilles,
                    'mode' => $this->modeParDefaut($association, \count($importables)),
                    'colonne' => null,
                    'cibles' => null,
                    'analyses' => null,
                ]);

                return $this->redirectToRoute('association_import_rattachement', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->afficherDepot($association, $request, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Rattachement : par onglet (un choix de ville par onglet qui liste des membres) ou par colonne (la feuille, la
     * colonne « caisse », puis un choix de ville par valeur). Tout est proposé d'après les noms ; la validation analyse.
     */
    #[Route('/rattachement', name: 'association_import_rattachement', methods: ['GET', 'POST'])]
    public function rattachement(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $import = $this->importEnSession($request, $association);
        $contenu = null === $import ? null : $this->depot->lire($import['jeton']);
        if (null === $import || null === $contenu) {
            $this->oublier($request, $association);

            return $this->redirectToRoute('association_import', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }

        $soumis = $request->request->all('rattachement_import');
        $mode = (string) ($soumis['mode'] ?? $request->query->get('mode', $import['mode']));
        if (!\in_array($mode, ImportAssociation::MODES, true)) {
            $mode = ImportAssociation::MODE_ONGLETS;
        }
        $feuilles = $import['feuilles'];
        $importables = array_values(array_filter($feuilles, static fn (array $f): bool => $f['importable']));
        $villes = $this->villes->listerPourAssociation($association);
        $memoire = null !== $import['cibles'] && $import['mode'] === $mode ? $import['cibles'] : null;

        $donnees = new RattachementImportData();
        $donnees->mode = $mode;
        $options = ['mode' => $mode, 'villes' => $villes, 'action' => $this->generateUrl('association_import_rattachement', ['slug' => $association->getSlug()])];
        $valeurs = [];
        $feuilleColonne = null;
        $colonne = null;

        if (ImportAssociation::MODE_COLONNE === $mode) {
            $feuillesChoix = [];
            foreach ($importables as $f) {
                $feuillesChoix[$f['indice']] = $f['nom'] ?? $this->traducteur->trans('import_association.rattachement.feuille_unique');
            }
            $feuilleColonne = (int) ($soumis['feuille'] ?? $request->query->get('feuille', $import['colonne']['feuille'] ?? array_key_first($feuillesChoix) ?? 0));
            if (!isset($feuillesChoix[$feuilleColonne])) {
                $feuilleColonne = (int) (array_key_first($feuillesChoix) ?? 0);
            }
            $reconnue = $feuilles[$feuilleColonne] ?? null;
            $colonnesChoix = [];
            foreach ($reconnue['enTetes'] ?? [] as $enTete) {
                $colonnesChoix[$enTete['indice']] = $enTete['libelle'];
            }
            $colonneGardee = null !== $import['colonne'] && ($import['colonne']['feuille'] ?? null) === $feuilleColonne ? $import['colonne']['colonne'] : null;
            $colonne = isset($soumis['colonne']) ? (int) $soumis['colonne'] : ($colonneGardee ?? $reconnue['colonneCaisse'] ?? array_key_first($colonnesChoix));
            if (null !== $colonne && !isset($colonnesChoix[$colonne])) {
                $colonne = array_key_first($colonnesChoix);
            }
            if (null !== $reconnue && null !== $colonne) {
                $valeurs = $this->import->valeursCaisse($contenu, $association, $feuilleColonne, $reconnue['ligneEnTete'], $colonne);
            }
            $donnees->feuille = $feuilleColonne;
            $donnees->colonne = $colonne;
            foreach ($valeurs as $indice => $valeur) {
                $donnees->cibles['v'.$indice] = null !== $memoire && ($memoire['colonne'] ?? null) === $colonne && ($memoire['feuille'] ?? null) === $feuilleColonne
                    ? ($memoire['choix']['v'.$indice] ?? ImportAssociation::CIBLE_IGNORER)
                    : (null !== $valeur['villeProposee'] ? 'ville:'.$valeur['villeProposee'] : ImportAssociation::CIBLE_CREER);
            }
            $options += ['feuillesChoix' => $feuillesChoix, 'colonnesChoix' => $colonnesChoix, 'valeurs' => $valeurs];
        } else {
            foreach ($importables as $f) {
                $donnees->cibles['f'.$f['indice']] = null !== $memoire
                    ? ($memoire['choix']['f'.$f['indice']] ?? ImportAssociation::CIBLE_IGNORER)
                    : (null !== $f['villeProposee'] ? 'ville:'.$f['villeProposee'] : ImportAssociation::CIBLE_CREER);
            }
            $options += ['feuilles' => $importables];
        }

        $form = $this->createForm(RattachementImportType::class, $donnees, $options);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->has('appliquer') && $form->get('appliquer')->isClicked()) {
            // La feuille ou la colonne vient de changer : les valeurs ont été relues, on repart des propositions.
            $donnees->cibles = [];
            foreach ($valeurs as $indice => $valeur) {
                $donnees->cibles['v'.$indice] = null !== $valeur['villeProposee'] ? 'ville:'.$valeur['villeProposee'] : ImportAssociation::CIBLE_CREER;
            }
            $form = $this->createForm(RattachementImportType::class, $donnees, $options);
        } elseif ($form->isSubmitted() && $form->isValid()) {
            $cibles = [];
            if (ImportAssociation::MODE_COLONNE === $mode) {
                $reconnue = $feuilles[$feuilleColonne];
                foreach ($valeurs as $indice => $valeur) {
                    $choix = RattachementImportData::lire($donnees->cibles['v'.$indice] ?? null);
                    if (null !== $choix) {
                        $cibles[] = ['feuille' => $feuilleColonne, 'ligneEnTete' => $reconnue['ligneEnTete'], 'filtre' => ['colonne' => $colonne, 'valeur' => $valeur['valeur']], 'libelle' => $valeur['valeur'], 'ville' => $choix['ville'], 'creer' => $choix['creer'] ? $valeur['valeur'] : null];
                    }
                }
            } else {
                foreach ($importables as $f) {
                    $choix = RattachementImportData::lire($donnees->cibles['f'.$f['indice']] ?? null);
                    if (null !== $choix) {
                        $libelle = $f['nom'] ?? $import['fichier'];
                        $cibles[] = ['feuille' => $f['indice'], 'ligneEnTete' => $f['ligneEnTete'], 'filtre' => null, 'libelle' => $libelle, 'ville' => $choix['ville'], 'creer' => $choix['creer'] ? $libelle : null];
                    }
                }
            }

            $request->getSession()->set(self::CLE_SESSION, [
                ...$import,
                'mode' => $mode,
                'colonne' => ImportAssociation::MODE_COLONNE === $mode ? ['feuille' => $feuilleColonne, 'colonne' => $colonne, 'enTete' => $options['colonnesChoix'][$colonne] ?? null] : null,
                'cibles' => ['feuille' => $feuilleColonne, 'colonne' => $colonne, 'choix' => $donnees->cibles, 'liste' => $cibles],
                'analyses' => $this->import->analyser($contenu, $association, $cibles),
            ]);

            return $this->redirectToRoute('association_import_apercu', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('association/import/rattachement.html.twig', [
            'association' => $association,
            'form' => $form,
            'mode' => $mode,
            'fichier' => $import['fichier'],
            'feuilles' => $feuilles,
            'importables' => $importables,
            'valeurs' => $valeurs,
            'colonneEnTete' => null !== $colonne ? ($options['colonnesChoix'][$colonne] ?? null) : null,
            'plusieursFeuilles' => \count($feuilles) > 1,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/apercu', name: 'association_import_apercu', methods: ['GET'])]
    public function apercu(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $import = $this->importEnSession($request, $association);
        if (null === $import) {
            return $this->redirectToRoute('association_import', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }
        if (null === $import['analyses']) {
            return $this->redirectToRoute('association_import_rattachement', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }

        $totaux = ['valides' => 0, 'adhesions' => 0, 'invalides' => 0, 'ignorees' => 0, 'creations' => 0];
        foreach ($import['analyses'] as $analyse) {
            $totaux['valides'] += $analyse['valides'];
            $totaux['adhesions'] += $analyse['adhesions'];
            $totaux['invalides'] += $analyse['invalides'];
            $totaux['ignorees'] += $analyse['ignorees'];
            $totaux['creations'] += null !== $analyse['creer'] ? 1 : 0;
        }

        return $this->render('association/import/apercu.html.twig', [
            'association' => $association,
            'fichier' => $import['fichier'],
            'mode' => $import['mode'],
            'analyses' => $import['analyses'],
            'totaux' => $totaux,
        ]);
    }

    #[Route('/confirmer', name: 'association_import_confirmer', methods: ['POST'])]
    public function confirmer(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $this->verifierJeton($request, 'import-association-'.$association->getId());
        $import = $this->importEnSession($request, $association);
        $contenu = null === $import ? null : $this->depot->lire($import['jeton']);
        if (null === $import || null === $contenu || null === $import['analyses']) {
            $this->oublier($request, $association);

            return $this->redirectToRoute('association_import', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }

        $bilan = $this->import->executer($contenu, $association, $import['cibles']['liste'], $this->acteur(), $import['mode'], $import['colonne']['enTete'] ?? null);
        $this->oublier($request, $association);
        $request->getSession()->set(self::CLE_SESSION_BILAN, ['association' => $association->getId(), 'fichier' => $import['fichier'], 'bilan' => $bilan]);

        return $this->redirectToRoute('association_import_bilan', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/annuler', name: 'association_import_annuler', methods: ['POST'])]
    public function annuler(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $this->verifierJeton($request, 'import-association-'.$association->getId());
        $this->oublier($request, $association);

        return $this->redirectToRoute('association_import', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }

    /** Le bilan du dernier import confirmé, montré une fois. */
    #[Route('/bilan', name: 'association_import_bilan', methods: ['GET'])]
    public function bilan(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $bilan = $request->getSession()->get(self::CLE_SESSION_BILAN);
        if (!\is_array($bilan) || ($bilan['association'] ?? null) !== $association->getId()) {
            return $this->redirectToRoute('association_import', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }

        $totaux = ['membres' => 0, 'adhesions' => 0, 'invalides' => 0, 'ignorees' => 0, 'creees' => 0];
        foreach ($bilan['bilan'] as $ligne) {
            $totaux['membres'] += $ligne['membres'];
            $totaux['adhesions'] += $ligne['adhesions'];
            $totaux['invalides'] += $ligne['invalides'];
            $totaux['ignorees'] += $ligne['ignorees'];
            $totaux['creees'] += $ligne['creee'] ? 1 : 0;
        }

        return $this->render('association/import/bilan.html.twig', [
            'association' => $association,
            'fichier' => $bilan['fichier'],
            'bilan' => $bilan['bilan'],
            'totaux' => $totaux,
        ]);
    }

    private function afficherDepot(Association $association, Request $request, FormInterface $form, int $statut = Response::HTTP_OK): Response
    {
        $import = $this->importEnSession($request, $association);

        return $this->render('association/import/index.html.twig', [
            'association' => $association,
            'form' => $form,
            'enCours' => null === $import ? null : ['fichier' => $import['fichier'], 'etape' => null === $import['analyses'] ? 'rattachement' : 'apercu'],
            'villes' => $this->villes->listerPourAssociation($association),
            'reglages' => $association->getReglagesImport(),
        ], new Response(status: $statut));
    }

    private function formulaireFichier(Association $association): FormInterface
    {
        return $this->createForm(ImportMembresType::class, null, ['action' => $this->generateUrl('association_import_deposer', ['slug' => $association->getSlug()])]);
    }

    /** Le mode mémorisé par l'association, sinon « onglets » dès que plusieurs feuilles listent des membres. */
    private function modeParDefaut(Association $association, int $importables): string
    {
        $memorise = $association->getReglagesImport()['mode'] ?? null;
        if (\is_string($memorise) && \in_array($memorise, ImportAssociation::MODES, true)) {
            return $memorise;
        }

        return $importables > 1 ? ImportAssociation::MODE_ONGLETS : ImportAssociation::MODE_COLONNE;
    }

    /** @return array<string, mixed>|null */
    private function importEnSession(Request $request, Association $association): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }
        $import = $request->getSession()->get(self::CLE_SESSION);
        if (!\is_array($import) || ($import['association'] ?? null) !== $association->getId() || !isset($import['jeton'])) {
            return null;
        }

        return $import;
    }

    private function oublier(Request $request, Association $association): void
    {
        $import = $this->importEnSession($request, $association);
        if (null !== $import) {
            $this->depot->supprimer($import['jeton']);
        }
        $request->getSession()->remove(self::CLE_SESSION);
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
