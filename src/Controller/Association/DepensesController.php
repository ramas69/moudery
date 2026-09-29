<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Association\Perimetre;
use App\Association\TableauDeBordAssociation;
use App\Depense\Depenses;
use App\Entity\AppelContribution;
use App\Entity\Association;
use App\Entity\Depense;
use App\Entity\DepenseStatut;
use App\Entity\Utilisateur;
use App\Form\DepenseType;
use App\Form\Model\DepenseData;
use App\Repository\AppelContributionRepository;
use App\Repository\DepenseRepository;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Dépenses du bureau central (F-21 à F-23) : la liste (toutes les caisses, ou la ville du périmètre), la saisie avec
 * justificatif, la fiche avec son circuit, la validation ou le refus par une autre personne du bureau central, le
 * paiement. Le bureau central décide des dépenses de sa propre caisse ; celles des villes se consultent seulement
 * (leur validation revient au président de la ville). Une dépense d'une autre association : 404.
 */
#[Route('/associations/{slug}/depenses', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ESPACE_OUVRIR, subject: 'association')]
final class DepensesController extends AbstractController
{
    public const array FILTRES = ['toutes', 'a_valider', 'a_payer', 'payees', 'brouillons'];

    public function __construct(
        private readonly Depenses $service,
        private readonly DepenseRepository $depenses,
        private readonly AppelContributionRepository $appels,
        private readonly Perimetre $perimetre,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'association_depenses', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request, TableauDeBordAssociation $tableau): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        $toutes = $this->depenses->lister($association, $ville);
        $filtre = (string) $request->query->get('statut', 'toutes');
        $filtre = \in_array($filtre, self::FILTRES, true) ? $filtre : 'toutes';
        $statuts = [
            'a_valider' => [DepenseStatut::Soumise],
            'a_payer' => [DepenseStatut::Validee],
            'payees' => [DepenseStatut::Payee],
            'brouillons' => [DepenseStatut::Brouillon, DepenseStatut::Refusee],
        ];
        $lignes = 'toutes' === $filtre ? $toutes : array_values(array_filter($toutes, static fn (Depense $d): bool => \in_array($d->getStatut(), $statuts[$filtre], true)));
        $compteurs = ['toutes' => \count($toutes)];
        foreach ($statuts as $cle => $liste) {
            $compteurs[$cle] = \count(array_filter($toutes, static fn (Depense $d): bool => \in_array($d->getStatut(), $liste, true)));
        }
        $decisions = array_values(array_filter($toutes, static fn (Depense $d): bool => null !== $d->getDecisionLe()));
        usort($decisions, static fn (Depense $a, Depense $b): int => $b->getDecisionLe() <=> $a->getDecisionLe());

        $aujourdhui = new \DateTimeImmutable();
        $anneeExercice = $tableau->anneeExercice($association, $aujourdhui);

        return $this->render('association/depenses/index.html.twig', [
            'association' => $association,
            'ville' => $ville,
            'lignes' => $lignes,
            'filtre' => $filtre,
            'compteurs' => $compteurs,
            'synthese' => Depenses::synthese($toutes),
            'exercice' => $tableau->libelleExercice($association, $anneeExercice),
            'payeesExercice' => $this->depenses->totalPaye($association, $ville, ...$this->bornesExercice($tableau, $association, $anneeExercice)),
            'decisions' => \array_slice($decisions, 0, 8),
            'moi' => $this->moi(),
            'peutSaisir' => null === $ville ? $this->isGranted(Permission::ASSOCIATION_PILOTER, $association) : $this->isGranted(Permission::DEPENSE_SAISIR, $ville),
        ]);
    }

    #[Route('/nouvelle', name: 'association_depense_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        // Le bureau central sans ville dans le périmètre saisit pour sa propre caisse ; sinon, pour la caisse de la ville.
        $ville = $this->perimetre->villeCourante($association, $request);
        if (null === $ville) {
            $this->denyAccessUnlessGranted(Permission::ASSOCIATION_PILOTER, $association);
        } else {
            $this->denyAccessUnlessGranted(Permission::DEPENSE_SAISIR, $ville);
        }
        $depense = $this->service->nouvelle($association, $this->moi(), new \DateTimeImmutable(), $ville);
        $donnees = new DepenseData();
        $donnees->date = new \DateTimeImmutable('today');

        return $this->formulaire($association, $depense, $donnees, $request, true);
    }

    #[Route('/{id}', name: 'association_depense', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function fiche(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id): Response
    {
        $depense = $this->trouver($association, $id);

        return $this->render('association/depenses/fiche.html.twig', [
            'association' => $association,
            'depense' => $depense,
            'moi' => $this->moi(),
            'decideur' => $this->peutDecider($association, $depense),
            'peutModifier' => $this->peutSaisir($association, $depense),
            'peutPayer' => $this->peutSaisir($association, $depense),
            'valideurs' => $this->service->valideurs($depense),
            'peutPrevenir' => null !== $depense->getVille() && $depense->aEteSaisiePar($this->moi()) && DepenseStatut::Soumise === $depense->getStatut(),
        ]);
    }

    /** L'auteur d'une dépense de ville sans valideur prévient le bureau central, qui nommera un président. */
    #[Route('/{id}/prevenir', name: 'association_depense_prevenir', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function prevenir(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $depense = $this->trouver($association, $id);
        if (null === $depense->getVille() || !$depense->aEteSaisiePar($this->moi()) || DepenseStatut::Soumise !== $depense->getStatut() || !$this->isCsrfTokenValid('prevenir-depense-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $nombre = $this->service->prevenirLeCentral($depense, $this->moiObligatoire(), new \DateTimeImmutable());
        $this->addFlash($nombre > 0 ? 'succes' : 'erreur', $this->traducteur->trans($nombre > 0 ? 'depenses_association.flash.central_prevenu' : 'depenses_association.flash.central_absent', ['nombre' => $nombre]));

        return $this->redirectToRoute('association_depense', ['slug' => $association->getSlug(), 'id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/modifier', name: 'association_depense_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $depense = $this->trouver($association, $id);
        if (!$depense->estModifiable() || !$this->peutSaisir($association, $depense)) {
            return $this->redirectToRoute('association_depense', ['slug' => $association->getSlug(), 'id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->formulaire($association, $depense, DepenseData::depuis($depense), $request, false);
    }

    #[Route('/{id}/valider', name: 'association_depense_valider', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function valider(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $depense = $this->decidable($association, $id, $request, 'valider');
        $this->service->valider($depense, $this->moiObligatoire(), new \DateTimeImmutable());
        $this->addFlash('succes', $this->traducteur->trans('depenses_association.flash.validee', ['numero' => $depense->getNumero()]));

        return $this->redirectToRoute('association_depense', ['slug' => $association->getSlug(), 'id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/refuser', name: 'association_depense_refuser', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refuser(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $depense = $this->decidable($association, $id, $request, 'refuser');
        $motif = trim((string) $request->request->get('motif', ''));
        if ('' === $motif) {
            $this->addFlash('erreur', $this->traducteur->trans('depenses_association.flash.motif_obligatoire'));

            return $this->redirectToRoute('association_depense', ['slug' => $association->getSlug(), 'id' => $id, 'refus' => 1], Response::HTTP_SEE_OTHER);
        }
        $this->service->refuser($depense, $this->moiObligatoire(), mb_substr($motif, 0, 2000), new \DateTimeImmutable());
        $this->addFlash('succes', $this->traducteur->trans('depenses_association.flash.refusee', ['numero' => $depense->getNumero()]));

        return $this->redirectToRoute('association_depense', ['slug' => $association->getSlug(), 'id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/payer', name: 'association_depense_payer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function payer(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $depense = $this->trouver($association, $id);
        if (!$this->peutSaisir($association, $depense) || DepenseStatut::Validee !== $depense->getStatut() || !$this->isCsrfTokenValid('payer-depense-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->service->marquerPayee($depense, $this->moiObligatoire(), new \DateTimeImmutable());
        $this->addFlash('succes', $this->traducteur->trans('depenses_association.flash.payee', ['numero' => $depense->getNumero()]));

        return $this->redirectToRoute('association_depense', ['slug' => $association->getSlug(), 'id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/justificatif', name: 'association_depense_justificatif', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function justificatif(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id): Response
    {
        $depense = $this->trouver($association, $id);
        $chemin = $this->service->cheminJustificatif($depense);
        if (null === $chemin || !is_file($chemin)) {
            throw $this->createNotFoundException();
        }
        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Content-Type', (string) $depense->getJustificatifType());
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, (string) $depense->getJustificatifNom(), 'justificatif-'.$depense->getNumero());
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->setPrivate();

        return $reponse;
    }

    private function formulaire(Association $association, Depense $depense, DepenseData $donnees, Request $request, bool $nouvelle): Response
    {
        $appels = [];
        foreach ($this->appels->findBy(['association' => $association], ['creeLe' => 'DESC']) as $appel) {
            \assert($appel instanceof AppelContribution);
            if (!$appel->estBrouillon()) {
                $appels[$appel->getObjet().' · '.$appel->getType()->getNom()] = (string) $appel->getId();
            }
        }
        $form = $this->createForm(DepenseType::class, $donnees, ['appels' => $appels]);
        $form->handleRequest($request);
        $soumettre = 'soumettre' === $request->request->get('action');

        if ($form->isSubmitted() && $form->isValid() && $soumettre && null === $donnees->justificatif && null === $depense->getJustificatif()) {
            $form->get('justificatif')->addError(new FormError($this->traducteur->trans('justificatif.obligatoire', [], 'validators')));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $this->service->enregistrer($depense, $donnees, $soumettre, $this->moi(), new \DateTimeImmutable());
            $this->addFlash('succes', $this->traducteur->trans($soumettre ? 'depenses_association.flash.soumise' : 'depenses_association.flash.brouillon', ['numero' => $depense->getNumero()]));

            return $this->redirectToRoute('association_depense', ['slug' => $association->getSlug(), 'id' => $depense->getId()], Response::HTTP_SEE_OTHER);
        }

        $toutes = $this->depenses->lister($association, $depense->getVille() ?? 'central');

        return $this->render('association/depenses/formulaire.html.twig', [
            'association' => $association,
            'depense' => $depense,
            'nouvelle' => $nouvelle,
            'form' => $form->createView(),
            'synthese' => Depenses::synthese($toutes),
            'valideurs' => $this->service->valideurs($depense),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function trouver(Association $association, int $id): Depense
    {
        $depense = $this->depenses->find($id);
        if (null === $depense || $depense->getAssociation() !== $association) {
            throw $this->createNotFoundException();
        }

        return $depense;
    }

    /** Une dépense soumise que la personne connectée peut décider (et n'a pas saisie) ; jeton vérifié. */
    private function decidable(Association $association, int $id, Request $request, string $action): Depense
    {
        $depense = $this->trouver($association, $id);
        if (!$this->peutDecider($association, $depense) || !$depense->peutEtreValideePar($this->moi()) || !$this->isCsrfTokenValid($action.'-depense-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        return $depense;
    }

    /**
     * Qui décide : pour la caisse du central, le bureau central ; pour une ville, qui valide les dépenses de cette ville
     * (le président). Le point ouvert du cahier des charges (le central peut-il valider à la place du président ?)
     * est tranché à non par défaut.
     */
    private function peutDecider(Association $association, Depense $depense): bool
    {
        $ville = $depense->getVille();

        return null === $ville ? $this->isGranted(Permission::ASSOCIATION_PILOTER, $association) : $this->isGranted(Permission::DEPENSE_VALIDER, $ville);
    }

    /** Qui saisit, modifie et marque payée : le bureau central pour sa caisse, le trésorier (ou le central) pour une ville. */
    private function peutSaisir(Association $association, Depense $depense): bool
    {
        $ville = $depense->getVille();

        return null === $ville ? $this->isGranted(Permission::ASSOCIATION_PILOTER, $association) : $this->isGranted(Permission::DEPENSE_SAISIR, $ville);
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function bornesExercice(TableauDeBordAssociation $tableau, Association $association, int $annee): array
    {
        $mois = $tableau->moisDeLExercice($association, $annee);
        $debut = new \DateTimeImmutable(\sprintf('%d-%02d-01', $mois[0]['annee'], $mois[0]['mois']));
        $dernier = end($mois);

        return [$debut, (new \DateTimeImmutable(\sprintf('%d-%02d-01', $dernier['annee'], $dernier['mois'])))->modify('last day of this month')];
    }

    private function moi(): ?Utilisateur
    {
        $moi = $this->getUser();

        return $moi instanceof Utilisateur ? $moi : null;
    }

    private function moiObligatoire(): Utilisateur
    {
        return $this->moi() ?? throw $this->createAccessDeniedException();
    }
}
