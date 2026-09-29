<?php

declare(strict_types=1);

namespace App\Command;

use App\Relance\Relances;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Relances automatiques (F-24) : à lancer une fois par jour (cron, le matin). Chaque association active relance les
 * membres dont une échéance due tombe sur un jour de son calendrier ; jamais deux fois le même jour.
 */
#[AsCommand(name: 'app:relances:envoyer', description: 'Envoie les relances automatiques du jour selon le calendrier de chaque association.')]
final class EnvoyerRelancesCommand extends Command
{
    public function __construct(private readonly Relances $relances)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Le jour à traiter (AAAA-MM-JJ), aujourd’hui par défaut')
            ->addOption('simuler', null, InputOption::VALUE_NONE, 'Compte les relances sans rien envoyer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $date = $input->getOption('date');
        $jour = \is_string($date) && '' !== $date ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : new \DateTimeImmutable();
        if (!$jour instanceof \DateTimeImmutable) {
            $io->error('Date invalide : attendu AAAA-MM-JJ.');

            return Command::INVALID;
        }
        $simuler = (bool) $input->getOption('simuler');

        $bilan = $this->relances->automatiques($jour, $simuler);
        $io->success(\sprintf(
            '%s : %d association(s) parcourue(s), %d relance(s) %s, %d membre(s) ignoré(s) (injoignables ou déjà relancés).',
            $jour->format('d/m/Y'),
            $bilan['associations'],
            $bilan['envoyees'],
            $simuler ? 'à envoyer (simulation)' : 'envoyée(s)',
            $bilan['ignores'],
        ));

        return Command::SUCCESS;
    }
}
