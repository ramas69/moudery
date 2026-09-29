<?php

declare(strict_types=1);

namespace App\Association;

use App\Entity\Association;
use App\Entity\Ville;
use App\Repository\VilleRepository;
use App\Security\Permission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le périmètre de l'espace d'une association : toute l'association, ou une ville choisie dans la barre latérale.
 * Le choix est mémorisé en session, par association, et toute l'application le suit (tableau de bord, membres,
 * cotisations) jusqu'à ce qu'on change de ville ou qu'on revienne à l'association. Un paramètre ?ville= dans
 * l'adresse l'emporte et devient le nouveau choix.
 *
 * Un responsable de ville (trésorier, président, secrétaire) ne pilote pas l'association : son périmètre est toujours
 * une de ses villes (`Permission::VILLE_CONSULTER`), jamais « toute l'association », et il ne peut pas en choisir
 * une autre. C'est la première règle du cahier des charges : le trésorier de Lyon n'a aucun accès aux données de
 * Marseille.
 */
final class Perimetre
{
    public const string TOUTE_L_ASSOCIATION = 'association';
    private const string CLE = 'perimetre.ville';

    public function __construct(
        private readonly RequestStack $requetes,
        private readonly VilleRepository $villes,
        private readonly Security $securite,
    ) {
    }

    /** La personne connectée pilote-t-elle toute l'association (bureau central, super-admin) ? */
    public function piloteToute(Association $association): bool
    {
        return $this->securite->isGranted(Permission::ASSOCIATION_PILOTER, $association);
    }

    /** @return list<Ville> les villes que le périmètre peut désigner : toutes pour qui pilote l'association, les siennes sinon */
    public function villes(Association $association): array
    {
        $villes = $this->villes->listerPourAssociation($association);
        if ($this->piloteToute($association)) {
            return $villes;
        }

        return array_values(array_filter($villes, fn (Ville $ville): bool => $this->securite->isGranted(Permission::VILLE_CONSULTER, $ville)));
    }

    /**
     * La ville du périmètre, ou null pour toute l'association. Un paramètre ?ville= présent dans la requête (un
     * identifiant, ou « toutes » / vide pour l'association entière) est mémorisé ; sinon c'est le choix en session.
     * Une ville d'une autre association ne se choisit jamais.
     */
    public function villeCourante(Association $association, ?Request $requete = null): ?Ville
    {
        $requete ??= $this->requetes->getCurrentRequest();
        $villes = $this->villes($association);
        $pilote = $this->piloteToute($association);

        if (null !== $requete && $requete->query->has('ville')) {
            $ville = self::parmi($villes, (string) $requete->query->get('ville'));
            if (null === $ville && !$pilote) {
                // Un responsable de ville ne sort jamais de ses villes : sa première ville, jamais l'association entière.
                $ville = $villes[0] ?? null;
            }
            $this->memoriser($association, $ville, $requete);

            return $ville;
        }

        $memorise = null !== $requete && $requete->hasSession() ? $requete->getSession()->get(self::cle($association)) : null;
        $ville = null === $memorise ? null : self::parmi($villes, (string) $memorise);
        if (null === $ville && !$pilote) {
            $ville = $villes[0] ?? null;
        }

        return $ville;
    }

    public function choisir(Association $association, ?Ville $ville, Request $requete): void
    {
        $this->memoriser($association, $ville, $requete);
    }

    /** @param list<Ville> $villes */
    public static function parmi(array $villes, string $identifiant): ?Ville
    {
        foreach ($villes as $ville) {
            if ((string) $ville->getId() === $identifiant) {
                return $ville;
            }
        }

        return null;
    }

    private function memoriser(Association $association, ?Ville $ville, Request $requete): void
    {
        if (!$requete->hasSession()) {
            return;
        }
        $session = $requete->getSession();
        if (null === $ville) {
            $session->remove(self::cle($association));
        } else {
            $session->set(self::cle($association), $ville->getId());
        }
    }

    private static function cle(Association $association): string
    {
        return self::CLE.'.'.$association->getId();
    }
}
