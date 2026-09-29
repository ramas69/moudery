<?php

declare(strict_types=1);

namespace App\Entity;

/** Catégories de dépense (graphique G-08 « à quoi sert l'argent ») ; libellés dans `depenses_association.categorie`. */
enum CategorieDepense: string
{
    case AideSociale = 'aide-sociale';
    case ProjetsVillage = 'projets-village';
    case Fetes = 'fetes';
    case Fonctionnement = 'fonctionnement';
    case Transport = 'transport';
    case Autre = 'autre';
}
