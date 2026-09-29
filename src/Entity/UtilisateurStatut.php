<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Cycle de vie d'un compte (M1) : en attente de validation par un responsable de ville,
 * actif, ou désactivé. Seul un compte actif peut se connecter.
 */
enum UtilisateurStatut: string
{
    case EnAttente = 'en-attente';
    case Actif = 'actif';
    case Desactive = 'desactive';
}
