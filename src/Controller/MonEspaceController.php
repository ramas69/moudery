<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Membre;
use App\Entity\Utilisateur;
use App\Form\Model\ProfilMembreData;
use App\Form\ProfilMembreType;
use App\Membre\EspaceMembre;
use App\Paiement\PaiementEnLigne;
use App\Repository\PaiementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * L'espace membre (F-26, F-27, F-04) : Échéances, Historique (et reçus), Ma ville, Profil. Ouvert à tout compte relié à
 * une fiche de membre d'une ville active ; sinon, retour à l'accueil. Un reçu n'est jamais celui d'un autre membre.
 */
#[Route('/mon-espace')]
#[IsGranted('IS_AUTHENTICATED')]
final class MonEspaceController extends AbstractController
{
    public function __construct(private readonly EspaceMembre $espace)
    {
    }

    #[Route('', name: 'mon_espace', methods: ['GET'])]
    public function echeances(): Response
    {
        $membre = $this->membre();
        if (null === $membre) {
            return $this->redirectToRoute('accueil');
        }

        return $this->render('mon_espace/echeances.html.twig', [
            'membre' => $membre,
            'onglet' => 'echeances',
            'aujourdhui' => $aujourdhui = new \DateTimeImmutable(),
            ...$this->espace->echeances($membre, $aujourdhui),
        ]);
    }

    #[Route('/historique', name: 'mon_espace_historique', methods: ['GET'])]
    public function historique(Request $request): Response
    {
        $membre = $this->membre();
        if (null === $membre) {
            return $this->redirectToRoute('accueil');
        }

        return $this->render('mon_espace/historique.html.twig', [
            'membre' => $membre,
            'onglet' => 'historique',
            ...$this->espace->historiqueDe($membre, $request->query->has('annee') ? $request->query->getInt('annee') : null, new \DateTimeImmutable()),
        ]);
    }

    /** L'attestation annuelle de paiements, imprimable (à garder, ou à joindre à une déclaration). */
    #[Route('/attestation/{annee}', name: 'mon_espace_attestation', requirements: ['annee' => '\d{4}'], methods: ['GET'])]
    public function attestation(int $annee): Response
    {
        $membre = $this->membre();
        if (null === $membre) {
            return $this->redirectToRoute('accueil');
        }
        $historique = $this->espace->historiqueDe($membre, $annee, new \DateTimeImmutable());
        if ($historique['annee'] !== $annee) {
            throw $this->createNotFoundException();
        }

        return $this->render('mon_espace/attestation.html.twig', [
            'membre' => $membre,
            'association' => $membre->getAssociation(),
            'aujourdhui' => new \DateTimeImmutable(),
            ...$historique,
        ]);
    }

    /** Projets et appels à contribution : collecté, reste, par ville et au total, et ma propre situation. */
    #[Route('/projets', name: 'mon_espace_projets', methods: ['GET'])]
    public function projets(): Response
    {
        $membre = $this->membre();
        if (null === $membre) {
            return $this->redirectToRoute('accueil');
        }

        return $this->render('mon_espace/projets.html.twig', [
            'membre' => $membre,
            'onglet' => 'projets',
            'projets' => $this->espace->projets($membre, new \DateTimeImmutable()),
        ]);
    }

    #[Route('/ma-ville/depenses', name: 'mon_espace_ville_depenses', methods: ['GET'])]
    public function depensesDeMaVille(): Response
    {
        $membre = $this->membre();
        if (null === $membre) {
            return $this->redirectToRoute('accueil');
        }

        return $this->render('mon_espace/depenses.html.twig', [
            'membre' => $membre,
            'onglet' => 'ville',
            'depenses' => $this->espace->depensesDeMaVille($membre, new \DateTimeImmutable()),
        ]);
    }

    #[Route('/ma-ville', name: 'mon_espace_ville', methods: ['GET'])]
    public function maVille(): Response
    {
        $membre = $this->membre();
        if (null === $membre) {
            return $this->redirectToRoute('accueil');
        }

        return $this->render('mon_espace/ville.html.twig', [
            'membre' => $membre,
            'onglet' => 'ville',
            ...$this->espace->maVille($membre, new \DateTimeImmutable()),
        ]);
    }

    #[Route('/profil', name: 'mon_espace_profil', methods: ['GET', 'POST'])]
    public function profil(Request $request, EntityManagerInterface $em, TranslatorInterface $traducteur): Response
    {
        $membre = $this->membre();
        if (null === $membre) {
            return $this->redirectToRoute('accueil');
        }
        $donnees = ProfilMembreData::depuis($membre);
        $form = $this->createForm(ProfilMembreType::class, $donnees);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $membre->modifier($membre->getPrenom(), $membre->getNom(), $membre->getEmail(), $donnees->telephone);
            $membre->definirConsentementEmail($donnees->consentEmail);
            $em->flush();
            $this->addFlash('succes', $traducteur->trans('mon_espace.profil.enregistre'));

            return $this->redirectToRoute('mon_espace_profil', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('mon_espace/profil.html.twig', [
            'membre' => $membre,
            'onglet' => 'profil',
            'form' => $form->createView(),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Payer mes échéances en ligne (F-15, F-16), SIMULÉ en attendant Stripe : je coche ce que je règle (retards
     * précochés), je saisis ma contribution pour un montant libre, puis « Payer » enregistre le paiement comme s'il
     * avait été encaissé par carte. Aucune donnée de carte n'est demandée.
     */
    #[Route('/payer', name: 'mon_espace_payer', methods: ['GET', 'POST'])]
    public function payer(Request $request, PaiementEnLigne $enLigne): Response
    {
        $membre = $this->membre();
        if (null === $membre) {
            return $this->redirectToRoute('accueil');
        }
        $aujourdhui = new \DateTimeImmutable();
        $erreur = null;
        $cochees = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('payer-en-ligne', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $cochees = array_values(array_map('intval', (array) $request->request->all('echeances')));
            $montantsLibres = [];
            foreach ((array) $request->request->all('montant_libre') as $id => $valeur) {
                $saisie = str_replace([' ', "\u{202F}", "\u{A0}", ',', '€'], ['', '', '', '.', ''], (string) $valeur);
                if ('' !== $saisie && is_numeric($saisie)) {
                    $montantsLibres[(int) $id] = (int) round((float) $saisie * 100);
                }
            }
            try {
                $paiement = $enLigne->payer($membre, $cochees, $montantsLibres, $aujourdhui);

                return $this->redirectToRoute('mon_espace_paiement_merci', ['id' => $paiement->getId()], Response::HTTP_SEE_OTHER);
            } catch (\InvalidArgumentException|\LogicException $e) {
                $erreur = $e->getMessage();
            }
        }

        $echeances = $this->espace->echeances($membre, $aujourdhui);
        if (null === $cochees) {
            $cochees = [];
            foreach ($echeances['dues'] as $e) {
                if ($e->estEnRetard($aujourdhui)) {
                    $cochees[] = (int) $e->getId();
                }
            }
            if ([] === $cochees && [] !== $echeances['dues']) {
                $cochees[] = (int) $echeances['dues'][0]->getId();
            }
        }

        return $this->render('mon_espace/payer.html.twig', [
            'membre' => $membre,
            'onglet' => 'echeances',
            'cochees' => $cochees,
            'erreur' => $erreur,
            'aujourdhui' => $aujourdhui,
            ...$echeances,
        ], new Response(null, null !== $erreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** La confirmation d'un paiement en ligne, avec le reçu et la part qui reste à la ville. */
    #[Route('/paiements/{id}/merci', name: 'mon_espace_paiement_merci', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function merci(int $id, PaiementRepository $paiements): Response
    {
        $membre = $this->membre();
        $paiement = $paiements->find($id);
        if (null === $membre || null === $paiement || $paiement->getMembre()->getId() !== $membre->getId() || !$paiement->estEnLigne()) {
            throw $this->createNotFoundException();
        }

        return $this->render('mon_espace/merci.html.twig', [
            'membre' => $membre,
            'onglet' => 'echeances',
            'paiement' => $paiement,
            'repartition' => PaiementEnLigne::repartition($paiement),
        ]);
    }

    #[Route('/recus/{id}', name: 'mon_espace_recu', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function recu(int $id, PaiementRepository $paiements): Response
    {
        $membre = $this->membre();
        $paiement = $paiements->find($id);
        if (null === $membre || null === $paiement || $paiement->getMembre()->getId() !== $membre->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->render('association/paiements/recu.html.twig', [
            'association' => $paiement->getAssociation(),
            'paiement' => $paiement,
            'retour' => $this->generateUrl('mon_espace_historique'),
        ]);
    }

    private function membre(): ?Membre
    {
        $compte = $this->getUser();

        return $compte instanceof Utilisateur ? $this->espace->ficheDe($compte) : null;
    }
}
