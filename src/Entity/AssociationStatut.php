<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * État d'une association : active, suspendue (ses comptes ne se connectent plus, rien n'est perdu),
 * ou archivée (fermée, consultable par le super-admin). Une association ne se supprime que vide.
 */
enum AssociationStatut: string
{
    case Active = 'active';
    case Suspendue = 'suspendue';
    case Archivee = 'archivee';
}
