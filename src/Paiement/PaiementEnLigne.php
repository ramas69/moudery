<?php

declare(strict_types=1);

namespace App\Paiement;

use App\Entity\Echeance;
use App\Entity\Membre;
use App\Entity\MoyenPaiement;
use App\Entity\Paiement;
use App\Repository\EcheanceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Paiement en ligne d'un membre depuis son espace (F-15, F-16), SIMULÉ en attendant Stripe (décision de Rama du
 * 29 septembre 2026) : aucune carte n'est demandée, aucun argent ne circule. Le règlement passe par
 * {@see Paiements::enregistrer()} comme un paiement manuel (échéances soldées, classeur rempli, journal, reçu par e-mail),
 * avec le moyen « carte », l'acteur = le compte du membre et un identifiant « simulation_… » à la place de celui de
 * Stripe. Le jour où Stripe arrive, seule cette classe change : la session Checkout remplace l'appel direct.
 */
final class PaiementEnLigne
{
    public const REFERENCE = 'En ligne';

    public function __construct(
        private readonly Paiements $paiements,
        private readonly EcheanceRepository $echeances,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<int>       $identifiants    les échéances choisies (dues, au membre)
     * @param array<int, int> $montantsLibres  centimes par échéance à montant libre
     */
    public function payer(Membre $membre, array $identifiants, array $montantsLibres, \DateTimeImmutable $quand): Paiement
    {
        $dues = [];
        foreach ($this->echeances->duesPourMembre($membre) as $echeance) {
            $dues[(int) $echeance->getId()] = $echeance;
        }
        $choisies = [];
        foreach (array_unique($identifiants) as $id) {
            if (!isset($dues[$id])) {
                throw new \InvalidArgumentException('Une échéance choisie n\'est plus à payer.');
            }
            $choisies[] = $dues[$id];
        }
        if ([] === $choisies) {
            throw new \InvalidArgumentException('Choisissez au moins une échéance à payer.');
        }

        $resultat = $this->paiements->enregistrer($membre, $choisies, MoyenPaiement::Carte, $quand, self::REFERENCE, null, $membre->getCompte(), $quand, $montantsLibres);
        $paiement = $resultat['paiement'];
        $paiement->marquerEnLigne(Paiement::PREFIXE_SIMULATION.bin2hex(random_bytes(10)));
        $this->entityManager->flush();

        return $paiement;
    }

    /**
     * Ce qui reste à la ville et ce qui revient au bureau central : le taux de chaque appel pour ses échéances, le
     * taux par défaut de l'association pour les cotisations, arrondi au centime inférieur comme les reversements.
     *
     * @return array{ville: int, central: int}
     */
    public static function repartition(Paiement $paiement): array
    {
        $central = 0;
        $tauxDefaut = $paiement->getMembre()->getAssociation()->getTauxReversementDefaut();
        foreach ($paiement->getEcheances() as $echeance) {
            /** @var Echeance $echeance */
            $taux = null !== $echeance->getAppel() ? $echeance->getAppel()->getTauxReversement() : $tauxDefaut;
            $central += intdiv(($echeance->getMontant() ?? 0) * $taux, 100);
        }

        return ['ville' => $paiement->getMontant() - $central, 'central' => $central];
    }
}
