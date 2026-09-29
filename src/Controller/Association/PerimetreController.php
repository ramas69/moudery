<?php

declare(strict_types=1);

namespace App\Controller\Association;

use App\Association\Perimetre;
use App\Entity\Association;
use App\Security\Permission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Le sélecteur de périmètre de la barre latérale : une ville, ou toute l'association, puis retour à la page d'origine. */
final class PerimetreController extends AbstractController
{
    #[Route('/associations/{slug}/perimetre/{ville}', name: 'association_perimetre', requirements: ['slug' => '[a-z0-9-]+', 'ville' => 'association|\d+'], methods: ['GET'])]
    #[IsGranted(Permission::ESPACE_OUVRIR, subject: 'association')]
    public function choisir(
        #[MapEntity(mapping: ['slug' => 'slug'])] Association $association,
        string $ville,
        Request $request,
        Perimetre $perimetre,
    ): Response {
        $choix = null;
        if (Perimetre::TOUTE_L_ASSOCIATION !== $ville) {
            $choix = Perimetre::parmi($perimetre->villes($association), $ville);
            if (null === $choix) {
                throw $this->createNotFoundException('Ville introuvable dans cette association.');
            }
        }
        $perimetre->choisir($association, $choix, $request);

        return $this->redirect($this->retour($request, $association));
    }

    /** La page d'origine (chemin local seulement), sans son paramètre ville qui écraserait le choix, ni sa page. */
    private function retour(Request $request, Association $association): string
    {
        $retour = (string) $request->query->get('retour', '');
        if ('' === $retour || !str_starts_with($retour, '/') || str_starts_with($retour, '//')) {
            return $this->generateUrl('association_tableau_de_bord', ['slug' => $association->getSlug()]);
        }
        $parties = parse_url($retour);
        $chemin = (string) ($parties['path'] ?? '/');
        parse_str((string) ($parties['query'] ?? ''), $parametres);
        unset($parametres['ville'], $parametres['page']);

        return $chemin.([] === $parametres ? '' : '?'.http_build_query($parametres));
    }
}
