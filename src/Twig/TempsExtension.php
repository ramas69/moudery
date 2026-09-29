<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Moments relatifs des fils d'activité, comme sur le canevas Console : « il y a 12 min », « hier », puis la date.
 * Le moment exact reste dans l'attribut datetime de l'élément time.
 */
final class TempsExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $traducteur)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('il_y_a', $this->ilYA(...))];
    }

    public function ilYA(\DateTimeInterface $quand, ?\DateTimeImmutable $maintenant = null): string
    {
        $maintenant ??= new \DateTimeImmutable();
        $secondes = max(0, $maintenant->getTimestamp() - $quand->getTimestamp());

        if ($secondes < 60) {
            return $this->traducteur->trans('temps.a_l_instant');
        }
        if ($secondes < 3600) {
            return $this->traducteur->trans('temps.minutes', ['n' => intdiv($secondes, 60)]);
        }
        if ($secondes < 86400 && $quand->format('Y-m-d') === $maintenant->format('Y-m-d')) {
            return $this->traducteur->trans('temps.heures', ['n' => intdiv($secondes, 3600)]);
        }

        $jours = (int) \DateTimeImmutable::createFromInterface($quand)->setTime(0, 0)->diff($maintenant->setTime(0, 0))->days;
        if (1 === $jours) {
            return $this->traducteur->trans('temps.hier');
        }
        if ($jours < 7) {
            return $this->traducteur->trans('temps.jours', ['n' => $jours]);
        }

        $formateur = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'Europe/Paris');

        return (string) $formateur->format($quand);
    }
}
