<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Appel\Appels;
use App\Association\Perimetre;
use App\Cotisation\Cotisations;
use App\Entity\Cotisation;
use App\Entity\Utilisateur;
use App\Form\CotisationType;
use App\Form\Model\CotisationData;
use App\Repository\CotisationRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Association\VillesAssociation;
use App\Entity\Association;
use App\Repository\AdhesionRepository;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les cotisations d'une ville, année par année : la grille du classeur (une ligne par adhérent, les douze mois, le
 * rapatriement, le projet, le total et le reste dû). La ville vient du périmètre de la barre latérale ; sans ville
 * choisie, la seule ville de l'association est prise d'office, sinon la page propose de choisir avec les chiffres de
 * chaque ville (décision de Rama du 28 septembre 2026). En attendant le module des cotisations (F-11 à F-13), les chiffres sont
 * ceux importés des classeurs.
 */
#[Route('/associations/{slug}/cotisations', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ESPACE_OUVRIR, subject: 'association')]
final class CotisationsController extends AbstractController
{
    public const int PAR_PAGE = 100;

    public function __construct(
        private readonly Perimetre $perimetre,
        private readonly AdhesionRepository $adhesions,
        private readonly VillesAssociation $villesAssociation,
        private readonly Cotisations $cotisations,
        private readonly Appels $appels,
        private readonly CotisationRepository $cotisationRepository,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'association_cotisations', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        if (null === $ville) {
            $villes = $this->perimetre->villes($association);
            if (1 !== \count($villes)) {
                return $this->render('association/cotisations/choisir.html.twig', [
                    'association' => $association,
                    ...$this->villesAssociation->tableau($association, 'tous', '', new \DateTimeImmutable()),
                ]);
            }
            // Une seule ville : aucun choix à faire, elle devient le périmètre.
            $ville = $villes[0];
            $this->perimetre->choisir($association, $ville, $request);
        }

        $parAnnee = $this->adhesions->compterParAnnee($ville);
        $anneeCourante = (int) date('Y');
        $anneeParDefaut = ($parAnnee[$anneeCourante] ?? 0) > 0 || [] === $parAnnee ? $anneeCourante : (int) array_key_last($parAnnee);
        $anneeChoisie = (string) $request->query->get('annee', (string) $anneeParDefaut);
        $annee = preg_match('/^\d{4}$/', $anneeChoisie) ? (int) $anneeChoisie : $anneeParDefaut;
        // Les exercices suivis (depuis le premier exercice de l'association) se proposent toujours, même sans adhérent.
        foreach ($association->exercices($anneeCourante) as $exercice) {
            $parAnnee[$exercice] ??= 0;
        }
        krsort($parAnnee);

        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $resultat = $this->adhesions->listerPourVilleEtAnnee($ville, $annee, $q, $page, self::PAR_PAGE);
        $pages = max(1, (int) ceil($resultat['total'] / self::PAR_PAGE));

        $aujourdhui = new \DateTimeImmutable();
        $cotisation = $this->cotisations->pour($ville, $annee);

        return $this->render('association/cotisations/index.html.twig', [
            'association' => $association,
            'ville' => $ville,
            'annee' => $annee,
            'cotisation' => $cotisation,
            'syntheseCotisation' => null !== $cotisation ? $this->cotisations->synthese($cotisation, $aujourdhui) : null,
            'peutOuvrir' => null === $cotisation && !$ville->estArchivee() && $annee >= $association->getPremierExercice() && $this->isGranted(Permission::PAIEMENT_SAISIR, $ville),
            'peutSaisir' => $this->isGranted(Permission::PAIEMENT_SAISIR, $ville),
            'anneeCourante' => $anneeCourante,
            'parAnnee' => $parAnnee,
            'q' => $q,
            'adhesions' => $resultat['adhesions'],
            'total' => $resultat['total'],
            'synthese' => $this->adhesions->synthese($ville, $annee),
            'pagination' => ['page' => min($page, $pages), 'pages' => $pages, 'de' => 0 === $resultat['total'] ? 0 : ($page - 1) * self::PAR_PAGE + 1, 'a' => min($page * self::PAR_PAGE, $resultat['total'])],
        ]);
    }

    /** Ouvre la cotisation de l'année pour la ville du périmètre (F-12) : type, tarif mensuel, jour d'échéance. */
    #[Route('/ouvrir', name: 'association_cotisation_ouvrir', methods: ['GET', 'POST'])]
    public function ouvrir(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        if (null === $ville) {
            return $this->redirectToRoute('association_cotisations', ['slug' => $association->getSlug()]);
        }
        $this->denyAccessUnlessGranted(Permission::PAIEMENT_SAISIR, $ville);
        $annee = $request->query->getInt('annee', (int) date('Y'));
        if (null !== $this->cotisations->pour($ville, $annee)) {
            $this->addFlash('erreur', $this->traducteur->trans('cotisations_association.ouverture.flash_deja', ['annee' => $annee, 'ville' => $ville->getNom()]));

            return $this->redirectToRoute('association_cotisations', ['slug' => $association->getSlug(), 'annee' => $annee], Response::HTTP_SEE_OTHER);
        }
        $types = $this->appels->types($association);
        if ([] === $types) {
            $this->addFlash('erreur', $this->traducteur->trans('cotisations_association.ouverture.aucun_type'));

            return $this->redirectToRoute('association_cotisations', ['slug' => $association->getSlug(), 'annee' => $annee], Response::HTTP_SEE_OTHER);
        }

        $donnees = new CotisationData();
        $cotisationPrecedente = $this->cotisations->pour($ville, $annee - 1);
        $donnees->type = ($cotisationPrecedente?->getType() ?? Appels::typeParCode($types, 'cotisation') ?? $types[0])->getCode();
        if (null !== $cotisationPrecedente) {
            $donnees->montantMensuel = $cotisationPrecedente->getMontantMensuel() / 100;
            $donnees->jourEcheance = $cotisationPrecedente->getJourEcheance();
        }
        $form = $this->createForm(CotisationType::class, $donnees, ['types' => $types]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $type = Appels::typeParCode($types, $donnees->type) ?? $types[0];
            $bilan = $this->cotisations->ouvrir($ville, $type, $annee, $donnees->montantEnCentimes(), (int) $donnees->jourEcheance, $this->compte(), new \DateTimeImmutable());
            $this->addFlash('succes', $this->traducteur->trans('cotisations_association.ouverture.flash_ouverte', ['annee' => $annee, 'ville' => $ville->getNom(), 'membres' => $bilan['membres'], 'echeances' => $bilan['echeances'], 'versees' => $bilan['dejaVersees']]));

            return $this->redirectToRoute('association_cotisations', ['slug' => $association->getSlug(), 'annee' => $annee], Response::HTTP_SEE_OTHER);
        }

        return $this->render('association/cotisations/ouvrir.html.twig', [
            'association' => $association,
            'ville' => $ville,
            'annee' => $annee,
            'cotisation' => null,
            'form' => $form,
            'concernes' => \count($this->appels->concernes($association, $ville, (Appels::typeParCode($types, $donnees->type) ?? $types[0])->getUnite())),
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Modifie le tarif d'une cotisation ouverte : seules les mensualités encore dues changent. */
    #[Route('/{id}/tarif', name: 'association_cotisation_tarif', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function tarif(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $cotisation = $this->cotisationDe($association, $id);
        $this->denyAccessUnlessGranted(Permission::PAIEMENT_SAISIR, $cotisation->getVille());
        $donnees = CotisationData::depuis($cotisation);
        $form = $this->createForm(CotisationType::class, $donnees, ['types' => [$cotisation->getType()], 'type_fige' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->cotisations->modifierTarif($cotisation, $donnees->montantEnCentimes(), (int) $donnees->jourEcheance, $this->compte(), new \DateTimeImmutable());
            $this->addFlash('succes', $this->traducteur->trans('cotisations_association.ouverture.flash_modifiee', ['annee' => $cotisation->getAnnee(), 'ville' => $cotisation->getVille()->getNom()]));

            return $this->redirectToRoute('association_cotisations', ['slug' => $association->getSlug(), 'annee' => $cotisation->getAnnee(), 'ville' => $cotisation->getVille()->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('association/cotisations/ouvrir.html.twig', [
            'association' => $association,
            'ville' => $cotisation->getVille(),
            'annee' => $cotisation->getAnnee(),
            'cotisation' => $cotisation,
            'form' => $form,
            'concernes' => null,
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Crée les mensualités manquantes (membres arrivés depuis l'ouverture). */
    #[Route('/{id}/generer', name: 'association_cotisation_generer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function generer(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $cotisation = $this->cotisationDe($association, $id);
        $this->denyAccessUnlessGranted(Permission::PAIEMENT_SAISIR, $cotisation->getVille());
        if (!$this->isCsrfTokenValid('cotisation-generer-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $bilan = $this->cotisations->generer($cotisation, new \DateTimeImmutable());
        $this->cotisationRepository->getEntityManager()->flush();
        $this->addFlash('succes', $this->traducteur->trans('cotisations_association.ouverture.flash_generee', ['echeances' => $bilan['echeances']]));

        return $this->redirectToRoute('association_cotisations', ['slug' => $association->getSlug(), 'annee' => $cotisation->getAnnee(), 'ville' => $cotisation->getVille()->getId()], Response::HTTP_SEE_OTHER);
    }

    private function cotisationDe(Association $association, int $id): Cotisation
    {
        $cotisation = $this->cotisationRepository->find($id);
        if (!$cotisation instanceof Cotisation || $cotisation->getAssociation() !== $association) {
            throw new NotFoundHttpException('Cotisation introuvable.');
        }

        return $cotisation;
    }

    private function compte(): ?Utilisateur
    {
        $compte = $this->getUser();

        return $compte instanceof Utilisateur ? $compte : null;
    }
}
