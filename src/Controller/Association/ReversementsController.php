<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Association\Perimetre;
use App\Association\Reversements;
use App\Entity\Association;
use App\Entity\Reversement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Form\Model\ReversementData;
use App\Form\ReversementType;
use App\Repository\ReversementRepository;
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
 * Reversements des villes au bureau central (F-20) : le dû par ville pour un exercice, le reçu, le restant, les
 * déclarations des villes à confirmer, l'historique ; l'enregistrement d'un virement reçu et l'export CSV. La ville du
 * périmètre restreint la page à cette ville. Un reversement d'une autre association : 404.
 */
#[Route('/associations/{slug}/reversements', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted(Permission::ESPACE_OUVRIR, subject: 'association')]
final class ReversementsController extends AbstractController
{
    public function __construct(
        private readonly Reversements $service,
        private readonly ReversementRepository $reversements,
        private readonly Perimetre $perimetre,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    #[Route('', name: 'association_reversements', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        $aujourdhui = new \DateTimeImmutable();
        $tableau = $this->service->tableau($association, $aujourdhui, self::exerciceDemande($request), $ville);

        return $this->render('association/reversements/index.html.twig', [
            'association' => $association,
            'ville' => $ville,
            'aujourdhui' => $aujourdhui,
            'pilote' => $this->isGranted(Permission::ASSOCIATION_PILOTER, $association),
            'peutDeclarer' => null !== $ville && $this->isGranted(Permission::PAIEMENT_SAISIR, $ville),
            ...$tableau,
        ]);
    }

    /** Le bureau central enregistre un virement reçu d'une ville : le reversement est confirmé d'emblée. */
    #[Route('/nouveau', name: 'association_reversement_nouveau', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::ASSOCIATION_PILOTER, subject: 'association')]
    public function nouveau(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        $aujourdhui = new \DateTimeImmutable();
        $tableau = $this->service->tableau($association, $aujourdhui, self::exerciceDemande($request), null);
        $villes = array_values(array_filter($this->perimetre->villes($association), static fn (Ville $v): bool => !$v->estBrouillon()));
        if (null !== $ville) {
            $villes = array_values(array_filter($villes, static fn (Ville $v): bool => $v->getId() === $ville->getId()));
        }
        $exercices = array_column($tableau['exercices'], 'libelle', 'annee');

        $donnees = new ReversementData();
        $donnees->ville = 1 === \count($villes) ? $villes[0] : null;
        $donnees->exercice = $tableau['exercice'];
        $donnees->recuLe = $aujourdhui->setTime(0, 0);
        $form = $this->createForm(ReversementType::class, $donnees, ['villes' => $villes, 'exercices' => $exercices, 'ville_fixee' => null !== $ville]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $reversement = $this->service->enregistrer($association, $donnees, $this->compte(), new \DateTimeImmutable());
            $this->addFlash('succes', $this->traducteur->trans('reversements_association.flash.enregistre', ['ville' => $reversement->getVille()->getNom(), 'montant' => $reversement->getMontant() / 100]));

            return $this->redirectToRoute('association_reversements', ['slug' => $association->getSlug(), 'exercice' => $reversement->getExercice()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('association/reversements/nouveau.html.twig', [
            'association' => $association,
            'ville' => $ville,
            'form' => $form,
            'lignes' => $tableau['lignes'],
            'libelle' => $tableau['libelle'],
            'taux' => $tableau['taux'],
            'declaration' => false,
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * La ville déclare un virement effectué vers le bureau central (cahier des charges : « reversement effectué ») :
     * il attend la confirmation du central. Trésorier (PAIEMENT_SAISIR sur la ville du périmètre) ou bureau central.
     */
    #[Route('/declarer', name: 'association_reversement_declarer', methods: ['GET', 'POST'])]
    public function declarer(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        if (null === $ville) {
            return $this->redirectToRoute('association_reversements', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }
        $this->denyAccessUnlessGranted(Permission::PAIEMENT_SAISIR, $ville);
        $aujourdhui = new \DateTimeImmutable();
        $tableau = $this->service->tableau($association, $aujourdhui, self::exerciceDemande($request), $ville);
        $exercices = array_column($tableau['exercices'], 'libelle', 'annee');

        $donnees = new ReversementData();
        $donnees->ville = $ville;
        $donnees->exercice = $tableau['exercice'];
        $donnees->recuLe = $aujourdhui->setTime(0, 0);
        $donnees->montant = $tableau['totaux']['restant'] > 0 ? $tableau['totaux']['restant'] / 100 : null;
        $form = $this->createForm(ReversementType::class, $donnees, ['villes' => [$ville], 'exercices' => $exercices, 'ville_fixee' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $donnees->exercice && null !== $donnees->montant && null !== $donnees->recuLe);
            $reversement = $this->service->declarer($ville, $donnees->exercice, (int) round($donnees->montant * 100), $donnees->recuLe, $donnees->reference, $donnees->note, $this->compte());
            $this->addFlash('succes', $this->traducteur->trans('reversements_association.flash.declare', ['montant' => $reversement->getMontant() / 100]));

            return $this->redirectToRoute('association_reversements', ['slug' => $association->getSlug(), 'exercice' => $reversement->getExercice()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('association/reversements/nouveau.html.twig', [
            'association' => $association,
            'ville' => $ville,
            'form' => $form,
            'lignes' => $tableau['lignes'],
            'libelle' => $tableau['libelle'],
            'taux' => $tableau['taux'],
            'declaration' => true,
        ], new Response(null, $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Le bureau central confirme un reversement déclaré par une ville, avec le montant reçu si la ville s'est trompée. */
    #[Route('/{id}/confirmer', name: 'association_reversement_confirmer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(Permission::ASSOCIATION_PILOTER, subject: 'association')]
    public function confirmer(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, int $id, Request $request): Response
    {
        $reversement = $this->reversements->find($id);
        if (!$reversement instanceof Reversement || $reversement->getAssociation() !== $association) {
            throw new NotFoundHttpException('Reversement introuvable.');
        }
        if (!$this->isCsrfTokenValid('reversement-confirmer-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        if ($reversement->estConfirme()) {
            $this->addFlash('erreur', $this->traducteur->trans('reversements_association.flash.deja_confirme'));

            return $this->redirectToRoute('association_reversements', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
        }

        $montantRecu = null;
        $saisie = str_replace([' ', "\u{202F}", "\u{A0}", ','], ['', '', '', '.'], (string) $request->request->get('montant', ''));
        if ('' !== $saisie) {
            if (!is_numeric($saisie) || (float) $saisie <= 0) {
                $this->addFlash('erreur', $this->traducteur->trans('reversements_association.flash.montant_invalide'));

                return $this->redirectToRoute('association_reversements', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
            }
            $montantRecu = (int) round((float) $saisie * 100);
        }

        $this->service->confirmer($reversement, $this->compte(), new \DateTimeImmutable(), $montantRecu);
        $this->addFlash('succes', $this->traducteur->trans('reversements_association.flash.confirme', ['ville' => $reversement->getVille()->getNom(), 'montant' => $reversement->getMontant() / 100]));

        return $this->redirectToRoute('association_reversements', ['slug' => $association->getSlug(), 'exercice' => $reversement->getExercice()], Response::HTTP_SEE_OTHER);
    }

    /** Le tableau du dû par ville, en CSV (point-virgule, BOM UTF-8), montants en euros. */
    #[Route('/export.csv', name: 'association_reversements_export', methods: ['GET'])]
    public function exporter(#[MapEntity(mapping: ['slug' => 'slug'])] Association $association, Request $request): Response
    {
        $ville = $this->perimetre->villeCourante($association, $request);
        $aujourdhui = new \DateTimeImmutable();
        $tableau = $this->service->tableau($association, $aujourdhui, self::exerciceDemande($request), $ville);

        $colonnes = ['ville', 'collecte', 'taux', 'appels', 'du', 'recu', 'declare', 'restant', 'statut', 'dernier'];
        $flux = fopen('php://temp', 'r+');
        \assert(false !== $flux);
        fwrite($flux, "\u{FEFF}");
        fputcsv($flux, array_map(fn (string $c): string => $this->traducteur->trans('reversements_association.csv.'.$c, ['exercice' => $tableau['libelle']]), $colonnes), ';', '"', '');
        foreach ($tableau['lignes'] as $ligne) {
            fputcsv($flux, [
                $ligne['ville']->getNom(),
                self::euros($ligne['collecte']),
                (string) $tableau['taux'],
                self::euros($ligne['appels']),
                self::euros($ligne['du']),
                self::euros($ligne['recu']),
                self::euros($ligne['declare']),
                self::euros($ligne['restant']),
                $this->traducteur->trans('reversements_association.statut.'.$ligne['statut']),
                null !== $ligne['dernier'] ? $ligne['dernier']->getRecuLe()->format('Y-m-d') : '',
            ], ';', '"', '');
        }
        fputcsv($flux, [
            $this->traducteur->trans('reversements_association.csv.total'),
            self::euros($tableau['totaux']['collecte']),
            '',
            '',
            self::euros($tableau['totaux']['du']),
            self::euros($tableau['totaux']['recu']),
            self::euros($tableau['totaux']['declare']),
            self::euros($tableau['totaux']['restant']),
            '',
            '',
        ], ';', '"', '');
        rewind($flux);
        $contenu = (string) stream_get_contents($flux);
        fclose($flux);

        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="reversements-%s-%d.csv"', $association->getSlug(), $tableau['exercice']),
        ]);
    }

    private static function exerciceDemande(Request $request): ?int
    {
        $exercice = (string) $request->query->get('exercice', '');

        return preg_match('/^\d{4}$/', $exercice) ? (int) $exercice : null;
    }

    private static function euros(int $centimes): string
    {
        return number_format($centimes / 100, 2, ',', '');
    }

    private function compte(): ?Utilisateur
    {
        $compte = $this->getUser();

        return $compte instanceof Utilisateur ? $compte : null;
    }
}
