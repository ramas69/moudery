<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\Ville;
use App\Form\Model\VilleIdentiteData;
use App\Form\VilleIdentiteType;
use App\Security\Permission;
use App\Ville\AssistantVille;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Assistant de création d'une ville (M2 bis), en trois étapes : 1. Identité (F-40), 2. Membres (F-42, dans
 * VilleMembresController), 3. Activation (F-46). Une ville active revient sur son récapitulatif, sans assistant.
 *
 * L'association (le tenant) vient de l'URL ; la ville est cherchée dans cette association seulement,
 * pour qu'une ville d'une autre association réponde 404 avant même le contrôle des droits.
 */
#[Route('/associations/{slug}')]
final class VilleAssistantController extends AbstractController
{
    public function __construct(
        private readonly AssistantVille $assistant,
        private readonly TranslatorInterface $traducteur,
        private readonly \Doctrine\ORM\EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/villes/nouvelle', name: 'ville_assistant_nouvelle', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::VILLE_CREER, subject: 'association')]
    public function nouvelle(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        Request $request,
    ): Response {
        return $this->traiterIdentite($association, new VilleIdentiteData($association), $request);
    }

    /** Reprend l'assistant là où il s'est arrêté ; une ville déjà active s'ouvre sur son récapitulatif. */
    #[Route('/villes/{id}/assistant', name: 'ville_assistant_reprendre', methods: ['GET'])]
    #[IsGranted(Permission::VILLE_MODIFIER, subject: 'ville')]
    public function reprendre(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
    ): Response {
        $this->verifierAppartenance($association, $ville);

        return $this->redirigerVersEtape($ville, $ville->estBrouillon() ? $ville->getEtapeAssistant() : EtapeAssistant::Activation);
    }

    #[Route('/villes/{id}/assistant/identite', name: 'ville_assistant_identite', methods: ['GET', 'POST'])]
    #[IsGranted(Permission::VILLE_MODIFIER, subject: 'ville')]
    public function identite(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
        Request $request,
    ): Response {
        $this->verifierAppartenance($association, $ville);
        // Une ville active ou archivée ne repasse pas par l'assistant : les liens « Paramètres de la ville » et
        // « Identité et responsables » mènent à sa fiche récapitulative (nom, responsables, invitations, membres).
        if (!$ville->estBrouillon()) {
            return $this->redirectToRoute('ville_assistant_activation', ['slug' => $association->getSlug(), 'id' => $ville->getId()]);
        }

        return $this->traiterIdentite($association, VilleIdentiteData::depuisVille($ville), $request);
    }

    /** Étape 3 : le récapitulatif, l'activation ; pour une ville active, le même récapitulatif sans le bouton. */
    #[Route('/villes/{id}/assistant/activation', name: 'ville_assistant_activation', methods: ['GET'])]
    #[IsGranted(Permission::VILLE_MODIFIER, subject: 'ville')]
    public function activation(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
    ): Response {
        $this->verifierAppartenance($association, $ville);

        return $this->render('ville/assistant/activation.html.twig', [
            ...$this->contexte($association, $ville, EtapeAssistant::Activation),
            'peutActiver' => $this->assistant->peutActiver($ville),
            'comptes' => $this->comptesResponsables($ville),
        ]);
    }

    #[Route('/villes/{id}/assistant/activer', name: 'ville_assistant_activer', methods: ['POST'])]
    #[IsGranted(Permission::VILLE_MODIFIER, subject: 'ville')]
    public function activer(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        #[MapEntity(expr: 'repository.trouverDansAssociation(id, slug)')] Ville $ville,
        Request $request,
    ): Response {
        $this->verifierAppartenance($association, $ville);
        $this->verifierBrouillon($ville);
        if (!$this->isCsrfTokenValid('activer-ville-'.$ville->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }

        if (!$this->assistant->peutActiver($ville)) {
            $this->addFlash('erreur', $this->traducteur->trans('assistant_ville.activation.tresorier_obligatoire'));

            return $this->redirigerVersEtape($ville, EtapeAssistant::Activation);
        }

        $envoyees = $this->assistant->activer($ville);
        $this->addFlash('succes', $this->traducteur->trans('assistant_ville.activation.activee', ['nom' => $ville->getNom(), 'invitations' => $envoyees]));

        return $this->redirectToRoute('association_tableau_de_bord', ['slug' => $association->getSlug()], Response::HTTP_SEE_OTHER);
    }

    private function traiterIdentite(Association $association, VilleIdentiteData $donnees, Request $request): Response
    {
        $form = $this->createForm(VilleIdentiteType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $ville = $this->assistant->enregistrerIdentite($donnees);
                $this->addFlash('succes', 'assistant_ville.identite.enregistree');

                return $this->redirigerVersEtape($ville, EtapeAssistant::Membres);
            } catch (UniqueConstraintViolationException) {
                // Deux créations simultanées du même nom : la contrainte SQL a tranché, on l'explique comme une erreur de saisie.
                $form->get('nom')->addError(new FormError($this->traducteur->trans('ville.nom.deja_utilise', [], 'validators')));
            }
        }

        $statut = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render(
            'ville/assistant/identite.html.twig',
            [...$this->contexte($association, $donnees->ville, EtapeAssistant::Identite), 'form' => $form],
            new Response(status: $statut),
        );
    }

    /** @return array<string, mixed> */
    /**
     * Pour une ville active, les comptes qui tiennent aujourd'hui chaque rôle (valeur de `RoleVille` => comptes actifs) :
     * la fiche montre les responsables réels, pas seulement les invitations saisies à la création.
     *
     * @return array<string, list<\App\Entity\Utilisateur>>
     */
    private function comptesResponsables(Ville $ville): array
    {
        $comptes = [];
        if ($ville->estBrouillon()) {
            return $comptes;
        }
        foreach ($this->entityManager->getRepository(\App\Entity\Utilisateur::class)->findBy(['association' => $ville->getAssociation()], ['nom' => 'ASC']) as $compte) {
            foreach (\App\Entity\RoleVille::cases() as $role) {
                if ($compte->estActif() && null !== $compte->affectationPour(\App\Security\Role::depuisRoleVille($role), $ville)) {
                    $comptes[$role->value][] = $compte;
                }
            }
        }

        return $comptes;
    }

    private function contexte(Association $association, ?Ville $ville, EtapeAssistant $etape): array
    {
        return [
            'association' => $association,
            'ville' => $ville,
            'etape' => $etape,
            'etapes' => EtapeAssistant::cases(),
        ];
    }

    private function redirigerVersEtape(Ville $ville, EtapeAssistant $etape): Response
    {
        $parametres = ['slug' => $ville->getAssociation()->getSlug(), 'id' => $ville->getId()];
        $route = match ($etape) {
            EtapeAssistant::Identite => 'ville_assistant_identite',
            EtapeAssistant::Membres => 'ville_assistant_membres',
            EtapeAssistant::Activation => 'ville_assistant_activation',
        };

        return $this->redirectToRoute($route, $parametres, Response::HTTP_SEE_OTHER);
    }

    /** Isolation entre associations : une ville d'une autre association n'existe pas pour celle-ci. */
    private function verifierAppartenance(Association $association, Ville $ville): void
    {
        if ($ville->getAssociation()->getId() !== $association->getId()) {
            throw $this->createNotFoundException('Ville introuvable dans cette association.');
        }
    }

    /** L'identité ne se modifie dans l'assistant qu'en brouillon ; une ville active passe par l'administration. */
    private function verifierBrouillon(Ville $ville): void
    {
        if (!$ville->estBrouillon()) {
            throw $this->createNotFoundException('L\'assistant n\'est disponible que pour une ville en brouillon.');
        }
    }
}
