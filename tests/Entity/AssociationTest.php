<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Association;
use App\Entity\AssociationStatut;
use PHPUnit\Framework\TestCase;

/** Identité, paramètres et statut d'une association. */
final class AssociationTest extends TestCase
{
    public function testUneAssociationNaitActiveAvecLesParametresParDefaut(): void
    {
        $association = new Association('  Association de Moudery ', 'moudery');

        self::assertSame('Association de Moudery', $association->getNom());
        self::assertTrue($association->estActive());
        self::assertSame('Association de Moudery', $association->getNomVillage(), 'Sans village, le nom de l’association s’affiche sous le logo.');
        self::assertSame(1, $association->getDebutExerciceMois());
        self::assertSame(0, $association->getTauxReversementDefaut());
        self::assertSame([-7, 0, 15], $association->getCalendrierRelances());
        self::assertNull($association->getCompteStripe());
    }

    public function testLIdentiteEstNormalisee(): void
    {
        $association = new Association('Association de Moudery', 'moudery');

        $association->definirVillage('  Moudery ');
        $association->definirContact(' Contact@Moudery.fr ', ' 06 12 34 56 78 ', '  ');
        $association->definirIdentifiantsLegaux(' w123 456 789 ', ' 123 456 789 ');

        self::assertSame('Moudery', $association->getVillage());
        self::assertSame('Moudery', $association->getNomVillage());
        self::assertSame('contact@moudery.fr', $association->getEmailContact());
        self::assertSame('06 12 34 56 78', $association->getTelephoneContact());
        self::assertNull($association->getAdresseSiege(), 'Un texte vide devient null.');
        self::assertSame('W123456789', $association->getNumeroRna());
        self::assertSame('123456789', $association->getSiren());

        $association->definirVillage('');
        $association->definirIdentifiantsLegaux(null, '');
        self::assertNull($association->getVillage());
        self::assertNull($association->getNumeroRna());
        self::assertNull($association->getSiren());
    }

    public function testLesParametresDeGestionSontVerifiesEtLesRelancesOrdonnees(): void
    {
        $association = new Association('Association de Moudery', 'moudery');

        $association->definirParametres(10, 40, [15, -7, 0, 15]);

        self::assertSame(10, $association->getDebutExerciceMois());
        self::assertSame(40, $association->getTauxReversementDefaut());
        self::assertSame([-7, 0, 15], $association->getCalendrierRelances(), 'Les doublons disparaissent, les jours sont triés.');

        try {
            $association->definirParametres(13, 40, [0]);
            self::fail('Le mois 13 doit être refusé.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('1 à 12', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $association->definirParametres(10, 101, [0]);
    }

    public function testLesExercicesSuivisPartentDuPremierExercice(): void
    {
        $association = new Association('  Association de Moudery ', 'moudery');
        self::assertSame(2025, $association->getPremierExercice(), 'Décision de Rama du 28 septembre 2026 : l’application suit les exercices à partir de 2025.');
        self::assertSame([2027, 2026, 2025], $association->exercices(2027));
        self::assertSame([2025], $association->exercices(2024), 'Jamais avant le premier exercice.');

        $association->definirPremierExercice(2023);
        self::assertSame([2026, 2025, 2024, 2023], $association->exercices(2026));

        $this->expectException(\InvalidArgumentException::class);
        $association->definirPremierExercice(1999);
    }

    public function testLeStatutSuitLesTransitionsAutorisees(): void
    {
        $association = new Association('Association de Moudery', 'moudery');

        $association->changerStatut(AssociationStatut::Suspendue);
        self::assertTrue($association->estSuspendue());
        self::assertFalse($association->estActive());

        $association->changerStatut(AssociationStatut::Active);
        self::assertTrue($association->estActive());

        $association->changerStatut(AssociationStatut::Archivee);
        self::assertTrue($association->estArchivee());
        self::assertTrue($association->peutPasserA(AssociationStatut::Active), 'Une association archivée se réactive.');
        self::assertFalse($association->peutPasserA(AssociationStatut::Suspendue), 'Une association archivée ne se suspend pas.');

        $association->changerStatut(AssociationStatut::Archivee);
        self::assertTrue($association->estArchivee(), 'Rester dans le même statut est sans effet.');

        $this->expectException(\LogicException::class);
        $association->changerStatut(AssociationStatut::Suspendue);
    }
}
