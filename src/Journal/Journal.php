<?php

declare(strict_types=1);

namespace App\Journal;

use App\Entity\Abonnement;
use App\Entity\Association;
use App\Entity\Evenement;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/**
 * Le journal de la plateforme (F-38) : toute action sensible y laisse une ligne. Les services métier consignent
 * juste avant leur flush ; le journal ne flushe jamais lui-même. Sous emprunt d'identité, l'acteur consigné est
 * toujours la vraie personne, la personne empruntée figure dans les détails (vu_comme).
 */
final class Journal
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $jetons,
    ) {
    }

    /** @param array<string, mixed> $details */
    public function consigner(TypeEvenement $type, ?Utilisateur $acteur = null, ?Association $association = null, ?string $cible = null, array $details = [], ?\DateTimeImmutable $quand = null): Evenement
    {
        // Sous emprunt d'identité, c'est l'emprunteur qui agit : lui pour acteur, la personne empruntée en détail.
        $jeton = $this->jetons->getToken();
        if ($jeton instanceof SwitchUserToken) {
            $reel = $jeton->getOriginalToken()->getUser();
            $emprunte = $jeton->getUser();
            if ($reel instanceof Utilisateur && $emprunte instanceof Utilisateur) {
                $details += ['vu_comme' => $emprunte->getEmail()];
                $acteur = $reel;
            }
        }

        $evenement = new Evenement($type, $quand ?? new \DateTimeImmutable(), $acteur, $association, $cible, $details);
        $this->entityManager->persist($evenement);

        return $evenement;
    }

    /**
     * L'état d'un abonnement à consigner avec chaque événement qui le concerne : c'est ce qui permet de retracer
     * le revenu mensuel dans le temps.
     *
     * @return array{statut: string, formule: string, montant: int, periodicite: string, mensuel: int}
     */
    public static function instantane(Abonnement $abonnement): array
    {
        return [
            'statut' => $abonnement->getStatut()->value,
            'formule' => $abonnement->getFormule(),
            'montant' => $abonnement->getMontant(),
            'periodicite' => $abonnement->getPeriodicite()->value,
            'mensuel' => $abonnement->montantMensuel(),
        ];
    }
}
