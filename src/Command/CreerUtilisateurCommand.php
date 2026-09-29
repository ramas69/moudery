<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Repository\AssociationRepository;
use App\Repository\UtilisateurRepository;
use App\Repository\VilleRepository;
use App\Security\Perimetre;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée un compte actif avec une affectation (F-07) : le super-admin plateforme, le premier bureau central
 * d'une association, ou un responsable de ville. Les membres viendront de l'inscription (F-01) et des invitations (F-02).
 */
#[AsCommand(name: 'app:utilisateur:creer', description: 'Crée un compte actif et lui attribue un rôle sur la plateforme, une association ou une ville.')]
final class CreerUtilisateurCommand extends Command
{
    private const int LONGUEUR_MINIMALE_MOT_DE_PASSE = 12;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssociationRepository $associations,
        private readonly VilleRepository $villes,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hacheur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse email, identifiant de connexion')
            ->addArgument('prenom', InputArgument::REQUIRED, 'Prénom')
            ->addArgument('nom', InputArgument::REQUIRED, 'Nom')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED, 'Rôle : super-admin, bureau-central, tresorier, president, secretaire ou membre', Role::BureauCentral->value)
            ->addOption('association', 'a', InputOption::VALUE_REQUIRED, 'Slug de l’association, par exemple « moudery » ; inutile pour le super-admin')
            ->addOption('ville', null, InputOption::VALUE_REQUIRED, 'Nom de la ville, obligatoire pour un rôle de ville')
            ->addOption('mot-de-passe', 'p', InputOption::VALUE_REQUIRED, 'Mot de passe (12 caractères minimum) ; demandé de façon masquée s’il est absent')
            ->setHelp(<<<'AIDE'
                Exemples :
                  <info>%command.name% admin@example.org Rama Diallo --role=super-admin</info>
                  <info>%command.name% central@moudery.fr Mamadou Diaby --association=moudery</info>
                  <info>%command.name% tresorier@example.org Seydou Diakité --association=moudery --role=tresorier --ville=Lyon</info>
                AIDE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = Utilisateur::normaliserEmail((string) $input->getArgument('email'));
        if (null === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('L’adresse email n’est pas valide.');

            return Command::INVALID;
        }
        if (null !== $this->utilisateurs->trouverParEmail($email)) {
            $io->error(\sprintf('Un compte existe déjà pour « %s ».', $email));

            return Command::FAILURE;
        }

        $role = Role::tryFrom((string) $input->getOption('role'));
        if (null === $role) {
            $io->error(\sprintf('Rôle inconnu « %s ». Rôles possibles : %s.', $input->getOption('role'), implode(', ', array_map(static fn (Role $r) => $r->value, Role::cases()))));

            return Command::INVALID;
        }

        $association = null;
        if (Perimetre::Plateforme !== $role->perimetre()) {
            $slug = (string) $input->getOption('association');
            if ('' === $slug) {
                $io->error(\sprintf('Le rôle « %s » s’attribue dans une association : précisez --association.', $role->value));

                return Command::INVALID;
            }
            $association = $this->associations->trouverParSlug($slug);
            if (null === $association) {
                $io->error(\sprintf('Aucune association avec le slug « %s ».', $slug));

                return Command::FAILURE;
            }
        }

        $ville = null;
        if (Perimetre::Ville === $role->perimetre()) {
            $nomVille = (string) $input->getOption('ville');
            if ('' === $nomVille) {
                $io->error(\sprintf('Le rôle « %s » s’attribue sur une ville : précisez --ville.', $role->value));

                return Command::INVALID;
            }
            \assert(null !== $association);
            $ville = $this->villes->trouverParNom($association, $nomVille);
            if (null === $ville) {
                $io->error(\sprintf('Aucune ville « %s » dans l’association « %s ».', $nomVille, $association->getNom()));

                return Command::FAILURE;
            }
        }

        $motDePasse = $input->getOption('mot-de-passe');
        if (null === $motDePasse) {
            if (!$input->isInteractive()) {
                $io->error('Précisez --mot-de-passe en mode non interactif.');

                return Command::INVALID;
            }
            $motDePasse = $io->askHidden('Mot de passe (12 caractères minimum)');
        }
        $motDePasse = (string) $motDePasse;
        if (mb_strlen($motDePasse) < self::LONGUEUR_MINIMALE_MOT_DE_PASSE) {
            $io->error(\sprintf('Le mot de passe doit contenir au moins %d caractères.', self::LONGUEUR_MINIMALE_MOT_DE_PASSE));

            return Command::INVALID;
        }

        $utilisateur = new Utilisateur($association, $email, (string) $input->getArgument('prenom'), (string) $input->getArgument('nom'), '', UtilisateurStatut::Actif);
        $utilisateur->definirMotDePasse($this->hacheur->hashPassword($utilisateur, $motDePasse));
        $utilisateur->affecter($role, $ville);

        $this->entityManager->persist($utilisateur);
        $this->entityManager->flush();

        $perimetre = match ($role->perimetre()) {
            Perimetre::Plateforme => 'toute la plateforme',
            Perimetre::Association => \sprintf('toute l’association « %s »', $association?->getNom()),
            Perimetre::Ville => \sprintf('la ville « %s »', $ville?->getNom()),
        };
        $io->success(\sprintf('Compte « %s » créé pour %s, rôle %s sur %s.', $email, $utilisateur->getNomComplet(), $role->value, $perimetre));

        return Command::SUCCESS;
    }
}
