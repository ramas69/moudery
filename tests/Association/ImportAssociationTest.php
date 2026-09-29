<?php

declare(strict_types=1);

namespace App\Tests\Association;

use App\Association\ImportAssociation;
use App\Entity\Association;
use App\Entity\Ville;
use PHPUnit\Framework\TestCase;

/** Les correspondances de l'import d'association : noms d'onglets et de villes, colonne « caisse ». */
final class ImportAssociationTest extends TestCase
{
    public function testUneVilleCorrespondSansAccentsNiEspacesNiCasse(): void
    {
        $association = new Association('Association de Moudery', 'moudery');
        $villes = [new Ville($association, 'Le Mans'), new Ville($association, 'Haute-Savoie'), new Ville($association, 'Paris')];

        self::assertSame('Le Mans', ImportAssociation::villeCorrespondante('LEMANS', $villes)?->getNom());
        self::assertSame('Le Mans', ImportAssociation::villeCorrespondante('Le mans', $villes)?->getNom());
        self::assertSame('Haute-Savoie', ImportAssociation::villeCorrespondante('HAUTE SAVOIE', $villes)?->getNom());
        self::assertSame('Paris', ImportAssociation::villeCorrespondante('paris ', $villes)?->getNom());
        self::assertNull(ImportAssociation::villeCorrespondante('Creil', $villes));
        self::assertNull(ImportAssociation::villeCorrespondante(null, $villes));
    }

    public function testLaColonneCaisseEstCelleMemoriseeSinonUnEnTeteConnu(): void
    {
        $enTetes = ['N°', 'Nom', 'Prénom', 'Section', 'Antenne'];

        self::assertSame(3, ImportAssociation::colonneCaisse($enTetes, null));
        self::assertSame(4, ImportAssociation::colonneCaisse($enTetes, 'antenne'));
        self::assertSame(3, ImportAssociation::colonneCaisse($enTetes, 'Inconnue'));
        self::assertNull(ImportAssociation::colonneCaisse(['Nom', 'Prénom'], null));
    }
}
