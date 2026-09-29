<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques obligatoires avant la mise en ligne (29 septembre 2026) : mentions légales (LCEN) et politique de
 * confidentialité (RGPD). L'éditeur vient des variables LEGAL_* du .env (globale Twig `legal`).
 */
final class LegalController extends AbstractController
{
    #[Route('/mentions-legales', name: 'mentions_legales', methods: ['GET'])]
    public function mentions(): Response
    {
        return $this->render('legal/mentions.html.twig');
    }

    #[Route('/confidentialite', name: 'confidentialite', methods: ['GET'])]
    public function confidentialite(): Response
    {
        return $this->render('legal/confidentialite.html.twig');
    }
}
