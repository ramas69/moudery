<?php

declare(strict_types=1);

namespace App\Command;

use App\Association\CreationAssociation;
use App\Form\Model\AssociationData;
use App\Repository\AssociationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Crée une association (le tenant), comme la page d'administration du super-admin, mais en ligne de commande. */
#[AsCommand(name: 'app:association:creer', description: 'Crée une association (tenant) à partir de son nom.')]
final class CreerAssociationCommand extends Command
{
    public function __construct(
        private readonly CreationAssociation $creation,
        private readonly AssociationRepository $associations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('nom', InputArgument::REQUIRED, 'Nom de l’association, par exemple « Association de Moudery »')
            ->addArgument('slug', InputArgument::OPTIONAL, 'Identifiant dans les URL (déduit du nom par défaut)')
            ->addOption('bureau-central', 'b', InputOption::VALUE_REQUIRED, 'Adresse email du premier bureau central : une invitation à créer son compte lui est envoyée');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $nom = trim((string) $input->getArgument('nom'));
        $slug = CreationAssociation::slugPour($nom, $input->getArgument('slug'));

        if ('' === $nom) {
            $io->error('Le nom est obligatoire.');

            return Command::INVALID;
        }

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $io->error(\sprintf('Le slug « %s » doit contenir uniquement des minuscules, des chiffres et des tirets.', $slug));

            return Command::INVALID;
        }

        if (null !== $this->associations->trouverParSlug($slug)) {
            $io->error(\sprintf('Le slug « %s » est déjà utilisé.', $slug));

            return Command::FAILURE;
        }

        $emailBureauCentral = $input->getOption('bureau-central');
        if (null !== $emailBureauCentral && false === filter_var($emailBureauCentral, \FILTER_VALIDATE_EMAIL)) {
            $io->error(\sprintf('L’adresse « %s » n’est pas valide.', $emailBureauCentral));

            return Command::INVALID;
        }

        $donnees = new AssociationData();
        $donnees->nom = $nom;
        $donnees->slug = $slug;
        $donnees->emailBureauCentral = $emailBureauCentral;
        $this->creation->creer($donnees);

        $io->success(\sprintf('Association « %s » créée (slug : %s).', $nom, $slug));
        if (null !== $emailBureauCentral) {
            $io->text(\sprintf('Invitation envoyée à %s, valable 7 jours.', $emailBureauCentral));
        }
        $io->text(\sprintf('Assistant de création d’une ville : /associations/%s/villes/nouvelle', $slug));

        return Command::SUCCESS;
    }
}
