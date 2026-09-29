<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Appel\Appels;
use App\Association\Perimetre;
use App\Entity\AppelContribution;
use App\Entity\Association;
use App\Entity\TypeContribution;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Form\AppelType;
use App\Form\Model\AppelData;
use App\Repository\AppelContributionRepository;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Projets et appels du bureau central (F-13, maquette « 04 Lancer un appel ») : la liste des appels, le formulaire
 * « Nouvel appel à contribution » avec « Ce qui va se passer » et l'aperçu de l'e-mail, l'enregistrement du brouillon
 * et le lancement. Un appel appartient toujours à l'association de l'adresse : sinon 404.
 */
#[Route('/associations/{slug}/appels', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ESPACE_OUVRIR, subject: 'association')]
final class AppelsController extends AbstractController
{
    public function __construct(
        private readonly Appels $appels,
        private readonly AppelContributionRepository $repository,
        private readonly Perimetre $perimetre,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'association_appels', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $appels = $this->repository->findBy(['association' => $association], ['creeLe' => 'DESC']);
        // Un responsable de ville ne voit que les appels de sa ville et ceux de toute l'association.
        $ville = $this->perimetre->villeCourante($association, $request);
        if (!$this->isGranted(Permission::ASSOCIATION_PILOTER, $association) && null !== $ville) {
            $appels = array_values(array_filter($appels, static fn (AppelContribution $a): bool => null === $a->getVille() || $a->getVille() === $ville));
        }

        return $this->render('association/appels/index.html.twig', [
            'association' => $association,
            'appels' => $appels,
            'suivis' => $this->suivis($appels),
            'peutLancer' => $this->isGranted(Permission::APPEL_LANCER, $ville ?? $association),
        ]);
    }

    #[Route('/nouveau', name: 'association_appel_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $types = $this->appels->types($association);
        $donnees = new AppelData();
        $premier = $types[0];
        $donnees->type = $premier->getCode();
        $donnees->mode = $premier->getMode();
        $donnees->montant = null !== $premier->getMontantDefaut() ? $premier->getMontantDefaut() / 100 : null;
        $donnees->taux = $premier->getTauxReversement();
        $donnees->dateLimite = new \DateTimeImmutable('+7 days midnight');
        $ville = $this->perimetre->villeCourante($association, $request);
        $this->denyAccessUnlessGranted(Permission::APPEL_LANCER, $ville ?? $association);
        if (null !== $ville && $ville->estActive()) {
            $donnees->perimetre = (string) $ville->getId();
        }

        return $this->formulaire($association, null, $donnees, $types, $request);
    }

    #[Route('/{id}', name: 'association_appel', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function appel(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $appel = $this->repository->find($id);
        if (null === $appel || $appel->getAssociation() !== $association) {
            throw $this->createNotFoundException();
        }
        // Un responsable de ville ne voit pas l'appel d'une autre ville.
        if (!$this->isGranted(Permission::ASSOCIATION_PILOTER, $association) && null !== $appel->getVille() && $appel->getVille() !== $this->perimetre->villeCourante($association, $request)) {
            throw $this->createNotFoundException();
        }
        if (!$appel->estBrouillon()) {
            return $this->render('association/appels/suivi.html.twig', [
                'association' => $association,
                'appel' => $appel,
                'suivi' => Appels::suivi($appel, new \DateTimeImmutable()),
            ]);
        }

        return $this->formulaire($association, $appel, AppelData::depuis($appel), $this->appels->types($association), $request);
    }

    /**
     * @param list<AppelContribution> $appels
     *
     * @return array<int, array<string, mixed>>
     */
    private function suivis(array $appels): array
    {
        $aujourdhui = new \DateTimeImmutable();
        $suivis = [];
        foreach ($appels as $appel) {
            if (!$appel->estBrouillon()) {
                $suivis[(int) $appel->getId()] = Appels::suivi($appel, $aujourdhui);
            }
        }

        return $suivis;
    }

    /** @param list<TypeContribution> $types */
    private function formulaire(Association $association, ?AppelContribution $appel, AppelData $donnees, array $types, Request $request): Response
    {
        $statistiques = $this->appels->statistiques($association);
        $villes = $this->appels->villes($association);
        $pilote = $this->isGranted(Permission::ASSOCIATION_PILOTER, $association);
        $villePerimetre = $this->perimetre->villeCourante($association, $request);
        if (!$pilote) {
            // Un président lance des appels pour sa ville seulement : le périmètre est figé.
            if (null === $villePerimetre || !$this->isGranted(Permission::APPEL_LANCER, $villePerimetre) || (null !== $appel && $appel->getVille() !== $villePerimetre)) {
                throw $this->createAccessDeniedException('Les appels de cette ville ne vous sont pas ouverts.');
            }
            $villes = array_values(array_filter($villes, static fn (Ville $v): bool => $v === $villePerimetre));
            $donnees->perimetre = (string) $villePerimetre->getId();
        }
        $perimetres = [];
        if ($pilote) {
            $perimetres[$this->traducteur->trans('appels_association.formulaire.toutes', ['nombre' => $statistiques[AppelData::TOUTES]['personne']['membres']])] = AppelData::TOUTES;
        }
        foreach ($villes as $ville) {
            $perimetres[$this->traducteur->trans('appels_association.formulaire.une_ville', ['ville' => $ville->getNom(), 'nombre' => $statistiques[(string) $ville->getId()]['personne']['membres']])] = (string) $ville->getId();
        }

        $typeChoisi = Appels::typeParCode($types, $request->isMethod('POST') ? (string) ($request->request->all('appel')['type'] ?? '') : $donnees->type) ?? $types[0];
        $donnees->tauxAPreciser = !$typeChoisi->aUnTauxFixe();
        $form = $this->createForm(AppelType::class, $donnees, ['types' => $types, 'perimetres' => $perimetres]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $acteur = $this->getUser();
            $acteur = $acteur instanceof Utilisateur ? $acteur : null;
            $appel = $this->appels->enregistrer($association, $appel, $donnees, $acteur);
            if ('lancer' === $request->request->get('action')) {
                if (!$this->isCsrfTokenValid('lancer-appel', (string) $request->request->get('_jeton_lancer'))) {
                    throw $this->createAccessDeniedException();
                }
                try {
                    $bilan = $this->appels->lancer($appel, $acteur, new \DateTimeImmutable());
                } catch (\LogicException) {
                    $this->addFlash('erreur', $this->traducteur->trans('appels_association.flash.aucun_membre'));

                    return $this->redirectToRoute('association_appel', ['slug' => $association->getSlug(), 'id' => $appel->getId()], Response::HTTP_SEE_OTHER);
                }
                $this->addFlash('succes', $this->traducteur->trans('appels_association.flash.lance', $bilan));

                return $this->redirectToRoute('association_appel', ['slug' => $association->getSlug(), 'id' => $appel->getId()], Response::HTTP_SEE_OTHER);
            }
            $this->addFlash('succes', $this->traducteur->trans('appels_association.flash.enregistre', ['objet' => $appel->getObjet()]));

            return $this->redirectToRoute('association_appels', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }

        $synthese = Appels::synthese($statistiques, $donnees->perimetre, $typeChoisi->getUnite(), $donnees->montantEnCentimes(), $typeChoisi->getTauxReversement() ?? ($donnees->taux ?? 0));

        return $this->render('association/appels/formulaire.html.twig', [
            'association' => $association,
            'appel' => $appel,
            'form' => $form->createView(),
            'types' => $types,
            'typeChoisi' => $typeChoisi,
            'statistiques' => $statistiques,
            'synthese' => $synthese,
            'donnees' => $donnees,
            'relances' => $this->datesRelance($association, $donnees),
            'prenomExemple' => $this->prenomExemple($association),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** @return list<\DateTimeImmutable> */
    private function datesRelance(Association $association, AppelData $donnees): array
    {
        if (!$donnees->relancesAuto || null === $donnees->dateLimite) {
            return [];
        }

        return array_map(static fn (int $jours): \DateTimeImmutable => $donnees->dateLimite->modify(\sprintf('%+d days', $jours)), $association->getCalendrierRelances());
    }

    /** Le prénom du premier membre joignable, pour l'aperçu de l'e-mail (« Bonjour Hawa »). */
    private function prenomExemple(Association $association): ?string
    {
        foreach ($this->appels->concernes($association, null, \App\Entity\UniteContribution::Personne) as $membre) {
            if (Appels::joignable($membre)) {
                return $membre->getPrenom();
            }
        }

        return null;
    }
}
