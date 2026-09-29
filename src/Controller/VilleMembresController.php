<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Form\ImportMembresType;
use App\Form\MembreType;
use App\Form\Model\MembreData;
use App\Form\Model\ReglagesImportData;
use App\Form\ReglagesImportType;
use App\Repository\AdhesionRepository;
use App\Repository\FoyerRepository;
use App\Repository\MembreRepository;
use App\Security\Permission;
use App\Ville\DepotImport;
use App\Ville\ImportMembres;
use App\Ville\Membres;
use App\Ville\ReglagesAnalyse;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
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
 * Étape 2 de l'assistant de création d'une ville : les membres (F-42, F-31), facultative. La liste reste ouverte une fois la ville active.
 * Import d'un fichier CSV ou Excel en trois temps (dépôt, réglages : feuille et colonnes, aperçu) avant enregistrement,
 * ajout à la main, liste avec recherche et filtres, retrait.
 * Comme pour les autres étapes, la ville est cherchée dans l'association de l'URL : une ville d'ailleurs répond 404.
 */
#[Route('/associations/{slug}/villes/{id}/assistant/membres')]
final class VilleMembresController extends AbstractController
{
    public const int PAR_PAGE = 25;
    /** Lignes lues pour la page de réglages : de quoi trouver la ligne d'en-têtes et montrer des exemples. */
    public const int LIGNES_EXTRAIT = 40;
    /** Colonnes proposées au mappage, au plus : au-delà, le fichier est un tableau de bord, pas une liste. */
    public const int COLONNES_MAXIMUM = 80;
    /** Lignes prêtes montrées dans l'aperçu d'un gros fichier. */
    public const int LIGNES_APERCU = 200;
    private const string CLE_SESSION_IMPORT = 'assistant.membres.import';

    public function __construct(
        private readonly Membres $membres,
        private readonly ImportMembres $import,
        private readonly DepotImport $depot,
        private readonly MembreRepository $membreRepository,
        private readonly FoyerRepository $foyers,
        private readonly AdhesionRepository $adhesions,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'ville_assistant_membres', methods: ['GET'])]
    #[IsGranted(Permission::MEMBRE_GERER, subject: 'ville')]
    public function index(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
        Request $request,
    ): Response {
        $this->verifierAssistantDisponible($association, $ville);

        return $this->afficher($association, $ville, $request, $this->formulaireAjout($ville), $this->formulaireImport($ville));
    }

    #[Route('/ajouter', name: 'ville_assistant_membres_ajouter', methods: ['POST'])]
    #[IsGranted(Permission::MEMBRE_GERER, subject: 'ville')]
    public function ajouter(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
        Request $request,
    ): Response {
        $this->verifierAssistantDisponible($association, $ville);

        $donnees = new MembreData();
        $form = $this->formulaireAjout($ville, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // L'unicité de l'adresse dans la ville se vérifie avec les autres erreurs, pour tout signaler d'un coup.
            $email = Utilisateur::normaliserEmail($donnees->email);
            if (null !== $email && $this->membreRepository->emailPris($ville, $email)) {
                $form->get('email')->addError(new FormError($this->traducteur->trans('membre.email.deja_membre', [], 'validators')));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $membre = $this->membres->ajouter($ville, $donnees, $this->acteur());
                $this->addFlash('succes', $this->traducteur->trans('assistant_ville.membres.ajoute', ['nom' => $membre->getNomComplet()]));

                return $this->redirectToRoute('ville_assistant_membres', $this->parametres($ville), Response::HTTP_SEE_OTHER);
            } catch (UniqueConstraintViolationException) {
                // Deux ajouts simultanés de la même adresse : la contrainte SQL a tranché.
                $form->get('email')->addError(new FormError($this->traducteur->trans('membre.email.deja_membre', [], 'validators')));
            }
        }

        return $this->afficher($association, $ville, $request, $form, $this->formulaireImport($ville), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** Dépose le fichier, repère ses feuilles et conduit aux réglages : rien n'est enregistré à ce stade. */
    #[Route('/importer', name: 'ville_assistant_membres_importer', methods: ['POST'])]
    #[IsGranted(Permission::MEMBRE_GERER, subject: 'ville')]
    public function importer(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
        Request $request,
    ): Response {
        $this->verifierAssistantDisponible($association, $ville);

        $form = $this->formulaireImport($ville);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $fichier = $form->get('fichier')->getData();
            \assert($fichier instanceof UploadedFile);
            $contenu = (string) file_get_contents($fichier->getPathname());

            $erreur = null;
            $feuilles = [];
            try {
                $feuilles = ImportMembres::feuilles($contenu);
            } catch (\RuntimeException) {
                $erreur = 'illisible';
            }
            $premiereFeuilleRemplie = null;
            foreach ($feuilles as $indice => $feuille) {
                if ($feuille['lignes'] > 0) {
                    $premiereFeuilleRemplie = $indice;
                    break;
                }
            }
            if (null === $erreur && null === $premiereFeuilleRemplie) {
                $erreur = 'vide';
            }

            if (null !== $erreur) {
                $form->get('fichier')->addError(new FormError($this->traducteur->trans('membre.import.'.$erreur, [], 'validators')));
            } else {
                $import = $this->importEnSession($request, $ville);
                if (null !== $import) {
                    $this->depot->supprimer($import['jeton']);
                }
                $request->getSession()->set(self::CLE_SESSION_IMPORT, [
                    'ville' => $ville->getId(),
                    'jeton' => $this->depot->deposer($contenu),
                    'fichier' => $fichier->getClientOriginalName(),
                    'feuilles' => $feuilles,
                    'feuille' => $premiereFeuilleRemplie,
                    'ligne_en_tete' => null,
                    'colonnes' => null,
                    'analyse' => null,
                ]);

                return $this->redirectToRoute('ville_assistant_membres_import_reglages', $this->parametres($ville), Response::HTTP_SEE_OTHER);
            }
        }

        return $this->afficher($association, $ville, $request, $this->formulaireAjout($ville), $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Réglages de l'import : la feuille du classeur (choisie par lien), la ligne d'en-têtes, le champ de chaque colonne.
     * Tout est détecté d'office et modifiable ; à la validation, le fichier est analysé et l'aperçu s'ouvre.
     */
    #[Route('/importer/reglages', name: 'ville_assistant_membres_import_reglages', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::MEMBRE_GERER, subject: 'ville')]
    public function reglagesImport(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
        Request $request,
    ): Response {
        $this->verifierAssistantDisponible($association, $ville);

        $import = $this->importEnSession($request, $ville);
        $contenu = null === $import ? null : $this->depot->lire($import['jeton']);
        if (null === $import || null === $contenu) {
            $request->getSession()->remove(self::CLE_SESSION_IMPORT);

            return $this->redirectToRoute('ville_assistant_membres', $this->parametres($ville), Response::HTTP_SEE_OTHER);
        }

        $soumis = $request->request->all('reglages_import');
        $feuille = (int) ($soumis['feuille'] ?? $request->query->get('feuille', $import['feuille']));
        if (!isset($import['feuilles'][$feuille])) {
            $feuille = (int) $import['feuille'];
        }
        try {
            $lignes = ImportMembres::lire($contenu, $feuille, self::LIGNES_EXTRAIT);
        } catch (\RuntimeException) {
            $lignes = [];
        }

        // La ligne d'en-têtes : celle soumise, sinon celle des réglages gardés pour cette feuille, sinon celle détectée
        // (avec le dictionnaire que l'association a appris de ses imports précédents).
        $dictionnaire = $association->getDictionnaireImport();
        $reglagesGardes = null !== $import['colonnes'] && $feuille === (int) $import['feuille'];
        if (isset($soumis['ligneEnTete'])) {
            $ligneEnTete = max(0, min(200, (int) $soumis['ligneEnTete']));
        } elseif ($reglagesGardes) {
            $ligneEnTete = (int) $import['ligne_en_tete'];
        } else {
            $ligneEnTete = (ImportMembres::ligneEnTete($lignes, $dictionnaire) ?? -1) + 1;
        }
        $enTetes = $ligneEnTete > 0 ? ($lignes[$ligneEnTete - 1] ?? []) : [];
        $exemples = \array_slice($lignes, $ligneEnTete, 3);
        [$indices, $colonnesMasquees] = self::colonnesUtiles($enTetes, $exemples);
        $detectees = ImportMembres::colonnesDetectees($enTetes, $dictionnaire);
        $ordreMemorise = $association->getReglagesImport()['ordre_nom'] ?? null;

        $donnees = new ReglagesImportData();
        $donnees->feuille = $feuille;
        $donnees->ligneEnTete = $ligneEnTete;
        $donnees->ordreNomComplet = $reglagesGardes ? (string) ($import['ordre_nom'] ?? ReglagesAnalyse::ORDRE_NOM_PRENOM) : (\is_string($ordreMemorise) && \in_array($ordreMemorise, ReglagesAnalyse::ORDRES, true) ? $ordreMemorise : ReglagesAnalyse::ORDRE_NOM_PRENOM);
        $donnees->colonnes = ReglagesImportData::depuisCorrespondances($reglagesGardes ? $import['colonnes'] : $detectees);

        $options = ['indices' => $indices, 'action' => $this->generateUrl('ville_assistant_membres_import_reglages', $this->parametres($ville))];
        $form = $this->createForm(ReglagesImportType::class, $donnees, $options);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->get('appliquer')->isClicked()) {
            // La ligne d'en-têtes vient de changer : on repart des colonnes détectées sur cette ligne, sans valider.
            $ordre = $donnees->ordreNomComplet;
            $donnees = new ReglagesImportData();
            $donnees->feuille = $feuille;
            $donnees->ligneEnTete = $ligneEnTete;
            $donnees->ordreNomComplet = $ordre;
            $donnees->colonnes = ReglagesImportData::depuisCorrespondances($detectees);
            $form = $this->createForm(ReglagesImportType::class, $donnees, $options);
        } elseif ($form->isSubmitted() && $form->isValid()) {
            $colonnes = $donnees->correspondances();
            $analyse = $this->import->analyser($contenu, $ville, new ReglagesAnalyse($donnees->feuille, $donnees->ligneEnTete, $colonnes, $donnees->ordreNomComplet, dictionnaire: $dictionnaire));
            if (null !== $analyse['erreur']) {
                $form->addError(new FormError($this->traducteur->trans('membre.import.'.$analyse['erreur'], ['{{ maximum }}' => ImportMembres::LIGNES_MAXIMUM], 'validators')));
            } else {
                $request->getSession()->set(self::CLE_SESSION_IMPORT, [
                    ...$import,
                    'feuille' => $donnees->feuille,
                    'ligne_en_tete' => $donnees->ligneEnTete,
                    'ordre_nom' => $donnees->ordreNomComplet,
                    'colonnes' => $colonnes,
                    // Ce que cet import apprend, à retenir pour l'association à la confirmation.
                    'dictionnaire' => ImportMembres::dictionnaire($enTetes, $colonnes),
                    'analyse' => $analyse,
                ]);

                return $this->redirectToRoute('ville_assistant_membres_import_apercu', $this->parametres($ville), Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('ville/assistant/membres_import_reglages.html.twig', [
            ...$this->contexte($association, $ville),
            'form' => $form,
            'fichier' => $import['fichier'],
            'feuilles' => $import['feuilles'],
            'feuille' => $feuille,
            'enTetes' => $enTetes,
            'exemples' => $exemples,
            'indices' => $indices,
            'lettres' => array_combine($indices, array_map(self::lettre(...), $indices)) ?: [],
            'colonnesMasquees' => $colonnesMasquees,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Un fichier modèle avec les colonnes reconnues et deux lignes d'exemple, à remplir dans un tableur. */
    #[Route('/importer/modele.csv', name: 'ville_assistant_membres_modele', methods: ['GET'])]
    #[IsGranted(Permission::MEMBRE_GERER, subject: 'ville')]
    public function modele(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
    ): Response {
        $this->verifierAssistantDisponible($association, $ville);

        $reponse = new Response(ImportMembres::modele(), Response::HTTP_OK, ['Content-Type' => 'text/csv; charset=UTF-8']);
        $reponse->headers->set('Content-Disposition', $reponse->headers->makeDisposition('attachment', 'membres-modele.csv'));

        return $reponse;
    }

    #[Route('/importer/apercu', name: 'ville_assistant_membres_import_apercu', methods: ['GET'])]
    #[IsGranted(Permission::MEMBRE_GERER, subject: 'ville')]
    public function apercu(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
        Request $request,
    ): Response {
        $this->verifierAssistantDisponible($association, $ville);

        $import = $this->importEnSession($request, $ville);
        if (null === $import) {
            return $this->redirectToRoute('ville_assistant_membres', $this->parametres($ville), Response::HTTP_SEE_OTHER);
        }
        if (null === $import['analyse']) {
            return $this->redirectToRoute('ville_assistant_membres_import_reglages', $this->parametres($ville), Response::HTTP_SEE_OTHER);
        }

        // Un gros fichier : toutes les lignes en erreur ou ignorées, mais seulement les premières lignes prêtes.
        $lignes = $import['analyse']['lignes'];
        $masquees = 0;
        if (\count($lignes) > self::LIGNES_APERCU) {
            $pretes = 0;
            $lignes = array_values(array_filter($lignes, static function (array $ligne) use (&$pretes): bool {
                if ('prete' !== $ligne['etat']) {
                    return true;
                }

                return ++$pretes <= self::LIGNES_APERCU;
            }));
            $masquees = \count($import['analyse']['lignes']) - \count($lignes);
        }

        return $this->render('ville/assistant/membres_import.html.twig', [
            ...$this->contexte($association, $ville),
            'fichier' => $import['fichier'],
            'analyse' => $import['analyse'],
            'lignes' => $lignes,
            'masquees' => $masquees,
        ]);
    }

    #[Route('/importer/confirmer', name: 'ville_assistant_membres_import_confirmer', methods: ['POST'])]
    #[IsGranted(Permission::MEMBRE_GERER, subject: 'ville')]
    public function confirmerImport(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
        Request $request,
    ): Response {
        $this->verifierAssistantDisponible($association, $ville);
        $this->verifierJeton($request, 'import-membres-'.$ville->getId());

        $import = $this->importEnSession($request, $ville);
        if (null === $import) {
            return $this->redirectToRoute('ville_assistant_membres', $this->parametres($ville), Response::HTTP_SEE_OTHER);
        }
        if (null === $import['analyse']) {
            return $this->redirectToRoute('ville_assistant_membres_import_reglages', $this->parametres($ville), Response::HTTP_SEE_OTHER);
        }

        $resultat = $this->import->importer($ville, $import['analyse']['lignes'], $this->acteur());
        $association->memoriserReglagesImport(['colonnes' => $import['dictionnaire'] ?? [], 'ordre_nom' => $import['ordre_nom'] ?? null, 'ligne_en_tete' => $import['ligne_en_tete'] ?? null]);
        $this->entityManager->flush();
        $this->depot->supprimer($import['jeton']);
        $request->getSession()->remove(self::CLE_SESSION_IMPORT);
        $this->addFlash('succes', $this->traducteur->trans('assistant_ville.membres.importes', ['nombre' => $resultat['membres'], 'adhesions' => $resultat['adhesions']]));

        return $this->redirectToRoute('ville_assistant_membres', $this->parametres($ville), Response::HTTP_SEE_OTHER);
    }

    #[Route('/importer/annuler', name: 'ville_assistant_membres_import_annuler', methods: ['POST'])]
    #[IsGranted(Permission::MEMBRE_GERER, subject: 'ville')]
    public function annulerImport(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
        Request $request,
    ): Response {
        $this->verifierAssistantDisponible($association, $ville);
        $this->verifierJeton($request, 'import-membres-'.$ville->getId());
        $import = $this->importEnSession($request, $ville);
        if (null !== $import) {
            $this->depot->supprimer($import['jeton']);
        }
        $request->getSession()->remove(self::CLE_SESSION_IMPORT);

        return $this->redirectToRoute('ville_assistant_membres', $this->parametres($ville), Response::HTTP_SEE_OTHER);
    }

    #[Route('/{membre}/retirer', name: 'ville_assistant_membres_retirer', requirements: ['membre' => '\d+'], methods: ['POST'])]
    #[IsGranted(Permission::MEMBRE_GERER, subject: 'ville')]
    public function retirer(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
        #[MapEntity(mapping: ['membre' => 'id'])] Membre $membre,
        Request $request,
    ): Response {
        $this->verifierAssistantDisponible($association, $ville);
        if ($membre->getVille()->getId() !== $ville->getId()) {
            throw $this->createNotFoundException('Membre introuvable dans cette ville.');
        }
        $this->verifierJeton($request, 'retirer-membre-'.$membre->getId());

        $nom = $membre->getNomComplet();
        $this->membres->retirer($membre, $this->acteur());
        $this->addFlash('succes', $this->traducteur->trans('assistant_ville.membres.retire', ['nom' => $nom]));

        return $this->redirectToRoute('ville_assistant_membres', [...$this->parametres($ville), ...$this->filtresConserves($request)], Response::HTTP_SEE_OTHER);
    }

    #[Route('/continuer', name: 'ville_assistant_membres_continuer', methods: ['POST'])]
    #[IsGranted(Permission::VILLE_MODIFIER, subject: 'ville')]
    public function continuer(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
        Request $request,
    ): Response {
        $this->verifierAssistantDisponible($association, $ville);
        $this->verifierJeton($request, 'continuer-membres-'.$ville->getId());

        $this->membres->terminerEtape($ville);

        return $this->redirectToRoute('ville_assistant_activation', $this->parametres($ville), Response::HTTP_SEE_OTHER);
    }

    private function afficher(Association $association, Ville $ville, Request $request, FormInterface $formAjout, FormInterface $formImport, int $statut = Response::HTTP_OK): Response
    {
        $recherche = trim((string) $request->query->get('q', ''));
        $filtreStatut = MembreStatut::tryFrom((string) $request->query->get('statut', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $resultat = $this->membreRepository->rechercher($ville, $recherche, $filtreStatut, $page, self::PAR_PAGE);
        $pages = max(1, (int) ceil($resultat['total'] / self::PAR_PAGE));
        $compteurs = $this->membreRepository->compterParStatut($ville);

        return $this->render('ville/assistant/membres.html.twig', [
            ...$this->contexte($association, $ville),
            'formAjout' => $formAjout,
            'formImport' => $formImport,
            'membres' => $resultat['membres'],
            'total' => $resultat['total'],
            'totalVille' => array_sum($compteurs),
            'compteurs' => $compteurs,
            'sansEmail' => $this->membreRepository->compterSansEmail($ville),
            'foyers' => \count($this->foyers->listerPourVille($ville)),
            'adhesionsParAnnee' => $this->adhesions->compterParAnnee($ville),
            'filtres' => ['q' => $recherche, 'statut' => $filtreStatut?->value ?? ''],
            'pagination' => ['page' => min($page, $pages), 'pages' => $pages, 'parPage' => self::PAR_PAGE, 'de' => 0 === $resultat['total'] ? 0 : ($page - 1) * self::PAR_PAGE + 1, 'a' => min($page * self::PAR_PAGE, $resultat['total'])],
            'importEnCours' => null === ($import = $this->importEnSession($request, $ville)) ? null : (null === $import['analyse'] ? 'reglages' : 'apercu'),
        ], new Response(status: $statut));
    }

    private function formulaireAjout(Ville $ville, ?MembreData $donnees = null): FormInterface
    {
        return $this->createForm(MembreType::class, $donnees ?? new MembreData(), [
            'ville' => $ville,
            'action' => $this->generateUrl('ville_assistant_membres_ajouter', $this->parametres($ville)),
        ]);
    }

    private function formulaireImport(Ville $ville): FormInterface
    {
        return $this->createForm(ImportMembresType::class, null, [
            'action' => $this->generateUrl('ville_assistant_membres_importer', $this->parametres($ville)),
        ]);
    }

    /**
     * L'import en cours pour cette ville : fichier déposé, feuilles, réglages, et l'analyse une fois les réglages validés.
     *
     * @return array{ville: int, jeton: string, fichier: string, feuilles: list<array{nom: ?string, lignes: int}>, feuille: int, ligne_en_tete: ?int, colonnes: ?array<string, int>, analyse: ?array<string, mixed>}|null
     */
    private function importEnSession(Request $request, Ville $ville): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }
        $import = $request->getSession()->get(self::CLE_SESSION_IMPORT);
        if (!\is_array($import) || ($import['ville'] ?? null) !== $ville->getId() || !isset($import['jeton'])) {
            return null;
        }

        return $import;
    }

    /**
     * Les colonnes à proposer au mappage : celles qui ont un en-tête ou une valeur dans les exemples, dans la limite
     * de COLONNES_MAXIMUM (un classeur de suivi peut en compter des centaines, vides ou de formules).
     *
     * @param list<?string>       $enTetes
     * @param list<list<?string>> $exemples
     *
     * @return array{list<int>, int} les indices retenus et le nombre de colonnes utiles écartées
     */
    private static function colonnesUtiles(array $enTetes, array $exemples): array
    {
        $utiles = [];
        foreach ([$enTetes, ...$exemples] as $ligne) {
            foreach ($ligne as $indice => $valeur) {
                if (null !== $valeur && '' !== trim($valeur)) {
                    $utiles[$indice] = true;
                }
            }
        }
        $indices = array_keys($utiles);
        sort($indices);

        return [\array_slice($indices, 0, self::COLONNES_MAXIMUM), max(0, \count($indices) - self::COLONNES_MAXIMUM)];
    }

    /** La lettre d'une colonne de tableur : 0 donne A, 26 donne AA. */
    private static function lettre(int $indice): string
    {
        $lettre = '';
        $n = $indice + 1;
        while ($n > 0) {
            $reste = ($n - 1) % 26;
            $lettre = \chr(65 + $reste).$lettre;
            $n = intdiv($n - 1, 26);
        }

        return $lettre;
    }

    /** @return array<string, mixed> */
    private function contexte(Association $association, Ville $ville): array
    {
        return [
            'association' => $association,
            'ville' => $ville,
            'etape' => EtapeAssistant::Membres,
            'etapes' => EtapeAssistant::cases(),
        ];
    }

    /** @return array{slug: string, id: int} */
    private function parametres(Ville $ville): array
    {
        return ['slug' => $ville->getAssociation()->getSlug(), 'id' => (int) $ville->getId()];
    }

    /** @return array<string, string> les filtres de la liste transmis par le formulaire d'action, pour y revenir */
    private function filtresConserves(Request $request): array
    {
        $filtres = [];
        foreach (['q', 'statut', 'page'] as $cle) {
            $valeur = trim((string) $request->request->get($cle, ''));
            if ('' !== $valeur) {
                $filtres[$cle] = $valeur;
            }
        }

        return $filtres;
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

    /** La ville doit être de cette association ; en brouillon ou active, ses membres se gèrent ici, jamais une fois archivée. */
    private function verifierAssistantDisponible(Association $association, Ville $ville): void
    {
        if ($ville->getAssociation()->getId() !== $association->getId()) {
            throw $this->createNotFoundException('Ville introuvable dans cette association.');
        }
        if ($ville->estArchivee()) {
            throw $this->createNotFoundException('Une ville archivée ne se modifie plus.');
        }
    }
}
