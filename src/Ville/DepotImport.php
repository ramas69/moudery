<?php

declare(strict_types=1);

namespace App\Ville;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Garde un fichier déposé pour import le temps des réglages et de l'aperçu (F-31), hors session pour ne pas
 * la charger de plusieurs mégaoctets. Un jeton aléatoire désigne le fichier ; il est supprimé à la confirmation ou à
 * l'annulation, et tout fichier oublié disparaît après un jour. Un dossier par environnement : les tests ne voient
 * pas les fichiers du serveur local.
 */
final class DepotImport
{
    public const int DUREE_DE_VIE = 86400;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/import/%kernel.environment%')]
        private readonly string $dossier,
    ) {
    }

    public function deposer(string $contenu): string
    {
        if (!is_dir($this->dossier) && !mkdir($this->dossier, 0770, true) && !is_dir($this->dossier)) {
            throw new \RuntimeException(\sprintf('Impossible de créer le dossier « %s ».', $this->dossier));
        }
        $this->purger();

        $jeton = bin2hex(random_bytes(16));
        if (false === file_put_contents($this->chemin($jeton), $contenu)) {
            throw new \RuntimeException('Impossible de garder le fichier déposé.');
        }

        return $jeton;
    }

    public function lire(string $jeton): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $jeton)) {
            return null;
        }
        $contenu = @file_get_contents($this->chemin($jeton));

        return false === $contenu ? null : $contenu;
    }

    public function supprimer(string $jeton): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $jeton)) {
            @unlink($this->chemin($jeton));
        }
    }

    private function purger(): void
    {
        $limite = time() - self::DUREE_DE_VIE;
        foreach (glob($this->dossier.'/*.bin') ?: [] as $fichier) {
            if (filemtime($fichier) < $limite) {
                @unlink($fichier);
            }
        }
    }

    private function chemin(string $jeton): string
    {
        return $this->dossier.'/'.$jeton.'.bin';
    }
}
