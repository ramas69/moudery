<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Association\Perimetre;
use App\Association\TableauDeBordAssociation;
use App\Export\Tableur;
use App\Entity\Association;
use App\Repository\EcheanceRepository;
use App\Repository\CotisationRepository;
use App\Repository\AppelContributionRepository;
use App\Entity\MoyenPaiement;
use App\Entity\MembreStatut;
use App\Entity\Cotisation;
use App\Entity\AppelStatut;
use App\Entity\AppelContribution;
use App\Cotisation\Cotisations;
use App\Entity\Echeance;
use App\Entity\Membre;
use App\Entity\Paiement;
use App\Entity\Utilisateur;
use App\Form\Model\PaiementData;
use App\Form\PaiementType;
use App\Paiement\Paiements;
use App\Repository\MembreRepository;
use App\Repository\PaiementRepository;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Paiements manuels (F-17, F-18) : la liste des paiements reçus (toute l'association ou la ville du périmètre),
 * l'enregistrement d'un paiement sur les échéances d'un membre, le reçu imprimable, l'annulation d'une saisie
 * erronée, l'export CSV. Un paiement ou un membre d'une autre association : 404.
 */
#[Route('/associations/{slug}/paiements', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ESPACE_OUVRIR, subject: 'association')]
final class PaiementsController extends AbstractController
{
    public const array PERIODES = ['exercice', 'mois', 'tout'];

    public function __construct(
        private readonly Paiements $service,
        private readonly PaiementRepository $paiements,
        private readonly MembreRepository $membres,
        private readonly Perimetre $perimetre,
        private readonly TableauDeBordAssociation $tableau,
        private readonly TranslatorInterface $traducteur,
        private readonly EcheanceRepository $echeances,
        private readonly Cotisations $cotisations,
        private readonly CotisationRepository $cotisationRepository,
        private readonly AppelContributionRepository $appels,
    ) {
    }

    #[Route('', name: 'association_paiements', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        $aujourdhui = new \DateTimeImmutable();
        $periode = (string) $request->query->get('periode', 'exercice');
        $periode = \in_array($periode, self::PERIODES, true) ? $periode : 'exercice';
        [$du, $au] = $this->bornes($association, $periode, $aujourdhui);
        $lignes = $this->paiements->lister($association, $ville, $du, $au);

        $exercice = $this->tableau->anneeExercice($association, $aujourdhui);
        [$debutExercice, $finExercice] = $this->tableau->bornesExercice($association, $exercice);
        $nombreExercice = \count(array_filter($this->paiements->lister($association, $ville, $debutExercice, $finExercice, 100000), static fn (Paiement $p): bool => !$p->estAnnule()));

        return $this->render('association/paiements/index.html.twig', [
            'association' => $association,
            'ville' => $ville,
            'aujourdhui' => $aujourdhui,
            'periode' => $periode,
            'lignes' => $lignes,
            'libelleExercice' => $this->tableau->libelleExercice($association, $exercice),
            'encaisseExercice' => $this->paiements->totalEncaisse($association, $ville, $debutExercice, $finExercice),
            'nombreExercice' => $nombreExercice,
            'encaisseMois' => $this->paiements->totalEncaisse($association, $ville, $aujourdhui->modify('first day of this month'), $aujourdhui->modify('last day of this month')),
            'annules' => \count(array_filter($lignes, static fn (Paiement $p): bool => $p->estAnnule())),
            'peutSaisir' => $this->isGranted(Permission::PAIEMENT_SAISIR, $ville ?? $association),
        ]);
    }

    /** Choisir le membre (recherche), puis cocher ses échéances dues et enregistrer. */
    #[Route('/nouveau', name: 'association_paiement_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        $q = trim((string) $request->query->get('q', ''));
        $membre = null;
        $idMembre = $request->query->getInt('membre', (int) $request->request->get('membre', 0));
        if ($idMembre > 0) {
            $membre = $this->membres->find($idMembre);
            if (!$membre instanceof Membre || $membre->getAssociation() !== $association || (null !== $ville && $membre->getVille() !== $ville)) {
                throw new NotFoundHttpException('Membre introuvable.');
            }
            $this->denyAccessUnlessGranted(Permission::PAIEMENT_SAISIR, $membre->getVille());
        } else {
            $this->denyAccessUnlessGranted(Permission::PAIEMENT_SAISIR, $ville ?? $association);
        }

        // Sans membre choisi : la liste des membres actifs du périmètre, avec ce que chacun doit, à cocher pour un
        // paiement groupé ; le filtre `q` sert aussi sans JavaScript.
        $resultats = [];
        $dues = [];
        $cotisation = null;
        $appels = [];
        if (null === $membre) {
            $resultats = $this->membres->rechercherPourAssociation($association, $ville, null, $q, MembreStatut::Actif, 1, 100000)['membres'];
            $dues = $this->echeances->duesParMembre($association, $ville, new \DateTimeImmutable());
            $cotisation = null !== $ville ? $this->cotisations->pour($ville, (int) date('Y')) : null;
            $appels = array_values(array_filter(
                $this->appels->findBy(['association' => $association, 'statut' => AppelStatut::Ouvert], ['dateLimite' => 'ASC']),
                static fn (AppelContribution $a): bool => null === $ville || null === $a->getVille() || $a->getVille() === $ville,
            ));
        }

        $form = null;
        $echeances = [];
        if (null !== $membre) {
            $aujourdhui = new \DateTimeImmutable();
            $echeances = $this->service->duesPour($membre);
            $donnees = new PaiementData();
            $donnees->recuLe = $aujourdhui->setTime(0, 0);
            $donnees->echeances = array_values(array_filter($echeances, static fn (Echeance $e): bool => $e->estEnRetard($aujourdhui)));
            $form = $this->createForm(PaiementType::class, $donnees, ['echeances' => $echeances]);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $montantsLibres = [];
                foreach ((array) $request->request->all('montant_libre') as $id => $valeur) {
                    $saisie = str_replace([' ', "\u{202F}", "\u{A0}", ','], ['', '', '', '.'], (string) $valeur);
                    if ('' !== $saisie && is_numeric($saisie)) {
                        $montantsLibres[(int) $id] = (int) round((float) $saisie * 100);
                    }
                }
                try {
                    \assert(null !== $donnees->moyen && null !== $donnees->recuLe);
                    $resultat = $this->service->enregistrer($membre, $donnees->echeances, $donnees->moyen, $donnees->recuLe, $donnees->reference, $donnees->note, $this->compte(), new \DateTimeImmutable(), $montantsLibres);
                    $paiement = $resultat['paiement'];
                    $this->addFlash('succes', $this->traducteur->trans($resultat['recu'] ? 'paiements_association.flash.enregistre_recu' : 'paiements_association.flash.enregistre', ['montant' => $paiement->getMontant() / 100.0, 'membre' => $membre->getNomComplet()]));

                    return $this->redirectToRoute('association_paiements', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
                } catch (\InvalidArgumentException) {
                    $form->addError(new \Symfony\Component\Form\FormError($this->traducteur->trans('paiements_association.flash.montant_libre')));
                }
            }
        }

        return $this->render('association/paiements/nouveau.html.twig', [
            'association' => $association,
            'ville' => $ville,
            'q' => $q,
            'resultats' => $resultats,
            'dues' => $dues,
            'cotisation' => $cotisation,
            'appels' => $appels,
            'moisCourant' => (int) date('n'),
            'precoches' => array_map('intval', (array) $request->query->all('membres')),
            'membre' => $membre,
            'echeances' => $echeances,
            'form' => $form,
            'aujourdhui' => new \DateTimeImmutable(),
        ], new Response(null, null !== $form && $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Le même paiement pour plusieurs membres cochés : mois de cotisation ou appels choisis, moyen, date, référence. */
    #[Route('/groupe', name: 'association_paiement_groupe', methods: ['POST'])]
    public function groupe(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('paiement-groupe', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $ville = $this->perimetre->villeCourante($association, $request);
        $retour = $this->redirectToRoute('association_paiement_nouveau', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);

        $membres = [];
        foreach (array_unique(array_map('intval', (array) $request->request->all('membres'))) as $id) {
            $candidat = $id > 0 ? $this->membres->find($id) : null;
            if ($candidat instanceof Membre && $candidat->getAssociation() === $association && (null === $ville || $candidat->getVille() === $ville) && $candidat->estActif() && $this->isGranted(Permission::PAIEMENT_SAISIR, $candidat->getVille())) {
                $membres[] = $candidat;
            }
        }
        $cibles = [];
        foreach ((array) $request->request->all('cibles') as $cible) {
            $morceaux = explode(':', (string) $cible);
            if ('cotisation' === ($morceaux[0] ?? '') && 3 === \count($morceaux)) {
                $cotisation = $this->cotisationRepository->find((int) $morceaux[1]);
                $mois = (int) $morceaux[2];
                if ($cotisation instanceof Cotisation && $cotisation->getAssociation() === $association && $mois >= 1 && $mois <= 12) {
                    $cibles[] = ['type' => 'cotisation', 'cotisation' => $cotisation, 'mois' => $mois];
                }
            } elseif ('appel' === ($morceaux[0] ?? '') && 2 === \count($morceaux)) {
                $appel = $this->appels->find((int) $morceaux[1]);
                if ($appel instanceof AppelContribution && $appel->getAssociation() === $association) {
                    $cibles[] = ['type' => 'appel', 'appel' => $appel];
                }
            }
        }
        $moyen = MoyenPaiement::tryFrom((string) $request->request->get('moyen', ''));
        $recuLe = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('recuLe', ''));
        if ([] === $membres || [] === $cibles || null === $moyen || !$recuLe instanceof \DateTimeImmutable || $recuLe > new \DateTimeImmutable('today')) {
            $this->addFlash('erreur', $this->traducteur->trans('paiements_association.groupe.flash.incomplet'));

            return $retour;
        }

        $bilan = $this->service->enregistrerGroupe($membres, $cibles, $moyen, $recuLe, trim((string) $request->request->get('reference', '')) ?: null, trim((string) $request->request->get('note', '')) ?: null, $this->compte(), new \DateTimeImmutable());
        $this->addFlash($bilan['paiements'] > 0 ? 'succes' : 'erreur', $this->traducteur->trans('paiements_association.groupe.flash.bilan', [
            'paiements' => $bilan['paiements'],
            'montant' => $bilan['montant'] / 100,
            'recus' => $bilan['recus'],
            'ignores' => \count($bilan['ignores']),
            'noms' => implode(', ', \array_slice($bilan['ignores'], 0, 5)),
        ]));

        return $bilan['paiements'] > 0 ? $this->redirectToRoute('association_paiements', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER) : $retour;
    }

    #[Route('/{id}/recu', name: 'association_paiement_recu', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function recu(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id): Response
    {
        return $this->render('association/paiements/recu.html.twig', [
            'association' => $association,
            'paiement' => $this->paiementDe($association, $id),
        ]);
    }

    #[Route('/{id}/annuler', name: 'association_paiement_annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function annuler(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $paiement = $this->paiementDe($association, $id);
        $this->denyAccessUnlessGranted(Permission::PAIEMENT_SAISIR, $paiement->getVille());
        if (!$this->isCsrfTokenValid('paiement-annuler-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        if ($paiement->estAnnule()) {
            $this->addFlash('erreur', $this->traducteur->trans('paiements_association.flash.deja_annule'));
        } else {
            $this->service->annuler($paiement, $this->compte(), new \DateTimeImmutable(), trim((string) $request->request->get('motif', '')) ?: null);
            $this->addFlash('succes', $this->traducteur->trans('paiements_association.flash.annule', ['membre' => $paiement->getMembre()->getNomComplet()]));
        }

        return $this->redirectToRoute('association_paiements', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }

    /** Les paiements de la période, en CSV ou en XLSX (F-29). */
    #[Route('/export.{format}', name: 'association_paiements_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function exporter(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request, string $format, Tableur $tableur): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        $aujourdhui = new \DateTimeImmutable();
        $periode = (string) $request->query->get('periode', 'exercice');
        $periode = \in_array($periode, self::PERIODES, true) ? $periode : 'exercice';
        [$du, $au] = $this->bornes($association, $periode, $aujourdhui);

        $enTetes = array_map(fn (string $c): string => $this->traducteur->trans('paiements_association.csv.'.$c), ['date', 'membre', 'ville', 'objet', 'moyen', 'montant', 'reference', 'statut', 'par']);
        $lignes = [];
        foreach ($this->paiements->lister($association, $ville, $du, $au, 100000) as $paiement) {
            $lignes[] = [
                $paiement->getRecuLe()->format('Y-m-d'),
                $paiement->getMembre()->getNomComplet(),
                $paiement->getVille()->getNom(),
                implode(' + ', array_map(static fn (Echeance $e): string => Paiements::libelle($e)['libelle'], $paiement->getEcheances()->toArray())),
                $this->traducteur->trans('paiements_association.moyen.'.$paiement->getMoyen()->value),
                $paiement->getMontant() / 100.0,
                (string) $paiement->getReference(),
                $this->traducteur->trans('paiements_association.statut.'.$paiement->getStatut()->value),
                $paiement->getEnregistrePar()?->getNomComplet() ?? '',
            ];
        }

        return $tableur->reponse($format, \sprintf('paiements-%s-%s', $association->getSlug(), $aujourdhui->format('Y-m-d')), $enTetes, $lignes, $this->traducteur->trans('paiements_association.titre'));
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} */
    private function bornes(Association $association, string $periode, \DateTimeImmutable $aujourdhui): array
    {
        return match ($periode) {
            'mois' => [$aujourdhui->modify('first day of this month')->setTime(0, 0), $aujourdhui->modify('last day of this month')->setTime(0, 0)],
            'tout' => [null, null],
            default => $this->tableau->bornesExercice($association, $this->tableau->anneeExercice($association, $aujourdhui)),
        };
    }

    private function paiementDe(Association $association, int $id): Paiement
    {
        $paiement = $this->paiements->find($id);
        if (!$paiement instanceof Paiement || $paiement->getAssociation() !== $association) {
            throw new NotFoundHttpException('Paiement introuvable.');
        }

        return $paiement;
    }

    private function compte(): ?Utilisateur
    {
        $compte = $this->getUser();

        return $compte instanceof Utilisateur ? $compte : null;
    }
}
