<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Abonnement\Catalogue;
use App\Entity\Abonnement;
use App\Entity\AbonnementStatut;
use App\Entity\Association;
use App\Entity\Periodicite;
use PHPUnit\Framework\TestCase;

/** Abonnement d'une association : statut, retard, échéances, revenu mensuel. */
final class AbonnementTest extends TestCase
{
    private Association $association;
    private \DateTimeImmutable $aujourdhui;

    protected function setUp(): void
    {
        $this->association = new Association('Association de Moudery', 'moudery');
        $this->aujourdhui = new \DateTimeImmutable('2026-10-01 15:30:00');
    }

    public function testUnAbonnementNaitASouscrireSansEcheance(): void
    {
        $abonnement = $this->association->ouvrirAbonnement();

        self::assertSame($abonnement, $this->association->getAbonnement());
        self::assertSame($abonnement, $this->association->ouvrirAbonnement(), 'Une association n’a qu’un abonnement.');
        self::assertSame(AbonnementStatut::ASouscrire, $abonnement->getStatut());
        self::assertTrue($abonnement->attendLaSouscription());
        self::assertSame(Abonnement::FORMULE_GRATUITE, $abonnement->getFormule());
        self::assertSame(0, $abonnement->montantMensuel());
        self::assertFalse($abonnement->estFacturable());
        self::assertFalse($abonnement->estEnRetard($this->aujourdhui));
        self::assertNull($abonnement->getProchaineEcheanceLe());
    }

    public function testLeRetardSeDeduitDeLEcheanceEtLeRevenuSeMensualise(): void
    {
        $abonnement = new Abonnement($this->association, new \DateTimeImmutable('2026-01-01'));
        $abonnement->definir('Standard', AbonnementStatut::Actif, 12000, Periodicite::Annuelle, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-10-15'), '  ');

        self::assertSame(1000, $abonnement->montantMensuel(), '120 € par an font 10 € par mois.');
        self::assertNull($abonnement->getNotes());
        self::assertFalse($abonnement->estEnRetard($this->aujourdhui));
        self::assertTrue($abonnement->echeanceProche($this->aujourdhui), 'Le 15 octobre est à moins de 30 jours du 1er octobre.');
        self::assertTrue($abonnement->estEnRetard(new \DateTimeImmutable('2026-10-16 08:00')), 'Le lendemain de l’échéance, l’abonnement est en retard.');
        self::assertFalse($abonnement->estEnRetard(new \DateTimeImmutable('2026-10-15 23:00')), 'Le jour de l’échéance, il est encore à jour.');

        $abonnement->constaterLeRetard();
        self::assertTrue($abonnement->estEnRetard($this->aujourdhui));
        self::assertTrue($abonnement->estFacturable());
        self::assertSame(1000, $abonnement->montantMensuel(), 'En retard, la somme reste due.');
    }

    public function testUnPaiementAvanceLEcheanceDUnePeriode(): void
    {
        $abonnement = new Abonnement($this->association);
        $abonnement->definir('Standard', AbonnementStatut::Actif, 2500, Periodicite::Mensuelle, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-10-15'), null);

        $abonnement->marquerPaye($this->aujourdhui);
        self::assertEquals(new \DateTimeImmutable('2026-11-15'), $abonnement->getProchaineEcheanceLe(), 'Payé avant l’échéance : elle avance depuis la date prévue.');
        self::assertEquals(new \DateTimeImmutable('2026-10-01'), $abonnement->getDernierPaiementLe());

        $abonnement->constaterLeRetard();
        $abonnement->marquerPaye(new \DateTimeImmutable('2026-12-20 10:00'));
        self::assertSame(AbonnementStatut::Actif, $abonnement->getStatut());
        self::assertEquals(new \DateTimeImmutable('2027-01-20'), $abonnement->getProchaineEcheanceLe(), 'Payé après l’échéance : elle repart du jour du paiement.');
    }

    public function testLaSouscriptionEnLigneActiveLOffreAvecUnPremierPaiementSousQuatorzeJours(): void
    {
        $abonnement = $this->association->ouvrirAbonnement();
        $annuel = Catalogue::offre(Catalogue::CODE_ANNUEL);
        self::assertNotNull($annuel);

        $abonnement->souscrire($annuel, $this->aujourdhui);

        self::assertFalse($abonnement->attendLaSouscription());
        self::assertSame(AbonnementStatut::Actif, $abonnement->getStatut());
        self::assertSame(Catalogue::FORMULE, $abonnement->getFormule());
        self::assertSame(Catalogue::ANNUEL, $abonnement->getMontant());
        self::assertSame(Periodicite::Annuelle, $abonnement->getPeriodicite());
        self::assertEquals(new \DateTimeImmutable('2026-10-01'), $abonnement->getDebutLe());
        self::assertEquals(new \DateTimeImmutable('2026-10-15'), $abonnement->getProchaineEcheanceLe(), 'Premier paiement sous 14 jours.');
        self::assertNull($abonnement->getDernierPaiementLe());
        self::assertTrue($abonnement->echeanceProche($this->aujourdhui));
        self::assertStringContainsString('01/10/2026', (string) $abonnement->getNotes());
        self::assertSame(intdiv(Catalogue::ANNUEL, 12), $abonnement->montantMensuel(), 'L’annuel se mensualise.');

        $this->expectException(\LogicException::class);
        $abonnement->souscrire($annuel, $this->aujourdhui);
    }

    public function testUnAbonnementOffertOuResilieNAttendPasDePaiement(): void
    {
        $abonnement = new Abonnement($this->association);
        $abonnement->definir('Gratuit', AbonnementStatut::Offert, 0, Periodicite::Mensuelle, new \DateTimeImmutable('2026-09-01'), null, null);

        try {
            $abonnement->marquerPaye($this->aujourdhui);
            self::fail('Un abonnement offert n’attend pas de paiement.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('offert', $e->getMessage());
        }

        $abonnement->definir('Standard', AbonnementStatut::Actif, 2500, Periodicite::Mensuelle, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-10-15'), null);
        $abonnement->resilier();
        self::assertSame(AbonnementStatut::Resilie, $abonnement->getStatut());
        self::assertNull($abonnement->getProchaineEcheanceLe());
        self::assertSame(0, $abonnement->montantMensuel());

        $abonnement->definir('Offert', AbonnementStatut::Offert, 2500, Periodicite::Mensuelle, new \DateTimeImmutable('2026-09-01'), null, null);
        self::assertSame(0, $abonnement->getMontant(), 'Offert, le montant est ramené à zéro.');

        $this->expectException(\InvalidArgumentException::class);
        $abonnement->definir('Standard', AbonnementStatut::Actif, -1, Periodicite::Mensuelle, new \DateTimeImmutable('2026-09-01'), null, null);
    }
}
