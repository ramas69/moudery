<?php

declare(strict_types=1);

namespace App\Command;

use App\Appel\Appels;
use App\Depense\Depenses;
use App\Entity\AppelContribution;
use App\Entity\Association;
use App\Entity\CategorieDepense;
use App\Entity\Depense;
use App\Entity\Echeance;
use App\Entity\ModeMontant;
use App\Entity\MoyenPaiement;
use App\Entity\Paiement;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Entity\Ville;
use App\Form\Model\DepenseData;
use App\Paiement\Paiements;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Données de démonstration d'un appel à projet dans une ville (demande de Rama du 29 septembre 2026 : « voir comment il
 * s'affiche côté trésorier ou président ») : un appel « Projet du village » lancé par le président il y a trois
 * semaines, des contributions en espèces et par virement réparties jour par jour et saisies par le trésorier, et une
 * dépense liée soumise par le trésorier, en attente de la décision du président. Les comptes de trésorier et de
 * président de la ville sont réutilisés ; à défaut, deux comptes de démonstration sont créés. `--purger` retire tout.
 * Aucun e-mail réel ne part en local (MAILER_DSN=null).
 */
#[AsCommand(name: 'app:demo:appel', description: 'Crée un appel à projet de démonstration dans une ville, avec des contributions et une dépense liée.')]
final class DemoAppelCommand extends Command
{
    public const string OBJET = 'Rénovation de l’école du village';
    public const string DEPENSE = 'Sacs de ciment pour l’école';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Appels $appels,
        private readonly Paiements $paiements,
        private readonly Depenses $depenses,
        private readonly UserPasswordHasherInterface $hacheur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('association', InputArgument::REQUIRED, 'Identifiant de l’association, par exemple « moudery »')
            ->addArgument('ville', InputArgument::REQUIRED, 'Nom de la ville active, par exemple « Lyon »')
            ->addOption('mot-de-passe', null, InputOption::VALUE_REQUIRED, 'Mot de passe des comptes de démonstration créés (jamais des comptes existants)', 'caisses-moudery')
            ->addOption('purger', null, InputOption::VALUE_NONE, 'Retire l’appel de démonstration, ses contributions et la dépense liée');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $association = $this->em->getRepository(Association::class)->findOneBy(['slug' => (string) $input->getArgument('association')]);
        $ville = null === $association ? null : $this->em->getRepository(Ville::class)->findOneBy(['association' => $association, 'nom' => (string) $input->getArgument('ville')]);
        if (null === $association || null === $ville) {
            $io->error('Association ou ville introuvable.');

            return Command::FAILURE;
        }
        if ($input->getOption('purger')) {
            $io->success($this->purger($ville));

            return Command::SUCCESS;
        }
        if (!$ville->estActive()) {
            $io->error('La ville doit être active pour lancer un appel.');

            return Command::FAILURE;
        }
        if (null !== $this->em->getRepository(AppelContribution::class)->findOneBy(['ville' => $ville, 'objet' => self::OBJET])) {
            $io->warning('L’appel de démonstration existe déjà. Relancez avec --purger pour le retirer, puis recréez-le.');

            return Command::SUCCESS;
        }

        $motDePasse = (string) $input->getOption('mot-de-passe');
        [$tresorier, $tresorierCree] = $this->responsable($association, $ville, Role::Tresorier, 'tresoriere.demo@example.org', 'Fatoumata', 'Sylla', $motDePasse);
        [$president, $presidentCree] = $this->responsable($association, $ville, Role::President, 'president.demo@example.org', 'Ousmane', 'Camara', $motDePasse);

        // L'appel, lancé par le président il y a vingt et un jours, pour trente jours encore.
        $maintenant = new \DateTimeImmutable();
        $ouverture = $maintenant->modify('-21 days')->setTime(10, 0);
        $type = Appels::typeParCode($this->appels->types($association), 'projet') ?? $this->appels->types($association)[0];
        $appel = new AppelContribution($association, $type, $president);
        $appel->definir(
            $type,
            self::OBJET,
            'La toiture de l’école du village s’est effondrée pendant l’hivernage. Chaque membre contribue selon ses moyens ; 50 € permettent d’acheter deux sacs de ciment et une tôle. Merci pour les enfants du village.',
            ModeMontant::Libre,
            5000,
            $maintenant->modify('+30 days'),
            $ville,
            20,
            true,
        );
        $this->em->persist($appel);
        $this->em->flush();
        $bilan = $this->appels->lancer($appel, $president, $ouverture);

        // Des contributions saisies par le trésorier, réparties sur les vingt et un jours, montants de 10 à 100 €.
        $echeances = $this->em->getRepository(Echeance::class)->findBy(['appel' => $appel], ['id' => 'ASC']);
        mt_srand(2026);
        $montants = [1000, 2000, 2000, 3000, 5000, 5000, 5000, 10000];
        $nombre = min(\count($echeances), max(12, (int) round(\count($echeances) * 0.14)));
        $total = 0;
        foreach (\array_slice($echeances, 0, $nombre) as $i => $echeance) {
            $jour = $ouverture->modify(\sprintf('+%d days', min(20, (int) floor($i * 21 / $nombre + mt_rand(0, 2)))))->setTime(12, 0);
            $montant = $montants[mt_rand(0, \count($montants) - 1)];
            $moyen = 0 === $i % 3 ? MoyenPaiement::Virement : MoyenPaiement::Especes;
            $this->paiements->enregistrer($echeance->getMembre(), [$echeance], $moyen, $jour, MoyenPaiement::Virement === $moyen ? 'VIR-'.(1000 + $i) : null, 'Démonstration', $tresorier, $jour, [(int) $echeance->getId() => $montant]);
            $total += $montant;
        }

        // Une dépense liée, soumise par le trésorier : elle attend la décision du président.
        $pdf = tempnam(sys_get_temp_dir(), 'facture');
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $donnees = new DepenseData();
        $donnees->libelle = self::DEPENSE;
        $donnees->montant = 480.0;
        $donnees->date = $maintenant->modify('-2 days');
        $donnees->categorie = CategorieDepense::ProjetsVillage;
        $donnees->appel = (string) $appel->getId();
        $donnees->beneficiaire = 'Quincaillerie Diallo, Bakel';
        $donnees->moyen = MoyenPaiement::Virement;
        $donnees->commentaire = 'Devis retenu parmi trois, le moins cher.';
        $donnees->justificatif = new UploadedFile($pdf, 'facture-ciment.pdf', 'application/pdf', null, true);
        $depense = $this->depenses->nouvelle($association, $tresorier, $maintenant, $ville);
        $this->depenses->enregistrer($depense, $donnees, true, $tresorier, $maintenant);

        $io->success(\sprintf('Appel « %s » lancé à %s : %d échéances, %d contributions pour %s €, et la dépense n° %s en attente du président.', self::OBJET, $ville->getNom(), $bilan['echeances'], $nombre, number_format($total / 100, 2, ',', ' '), $depense->getNumero()));
        $io->table(['Rôle', 'Adresse', 'Mot de passe'], [
            ['Trésorier', $tresorier->getEmail(), $tresorierCree ? $motDePasse : 'inchangé (compte existant)'],
            ['Président', $president->getEmail(), $presidentCree ? $motDePasse : 'inchangé (compte existant)'],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Le premier compte actif qui tient ce rôle sur la ville, ou un compte de démonstration créé pour l'occasion.
     *
     * @return array{0: Utilisateur, 1: bool} le compte, et vrai s'il vient d'être créé
     */
    private function responsable(Association $association, Ville $ville, Role $role, string $email, string $prenom, string $nom, string $motDePasse): array
    {
        foreach ($this->em->getRepository(Utilisateur::class)->findBy(['association' => $association], ['id' => 'ASC']) as $compte) {
            if ($compte->estActif() && !$compte->aLeRole(Role::SuperAdmin) && null !== $compte->affectationPour($role, $ville)) {
                return [$compte, false];
            }
        }
        $compte = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        $cree = null === $compte;
        if ($cree) {
            $compte = new Utilisateur($association, $email, $prenom, $nom, '', UtilisateurStatut::Actif);
            $compte->definirMotDePasse($this->hacheur->hashPassword($compte, $motDePasse));
            $this->em->persist($compte);
        }
        $compte->affecter($role, $ville);
        $this->em->flush();

        return [$compte, $cree];
    }

    private function purger(Ville $ville): string
    {
        $appel = $this->em->getRepository(AppelContribution::class)->findOneBy(['ville' => $ville, 'objet' => self::OBJET]);
        if (null === $appel) {
            return 'Aucun appel de démonstration à retirer.';
        }
        $paiements = 0;
        foreach ($this->em->getRepository(Echeance::class)->findBy(['appel' => $appel]) as $echeance) {
            $paiement = $echeance->getPaiement();
            if ($paiement instanceof Paiement) {
                $this->em->remove($paiement);
                ++$paiements;
            }
            $this->em->remove($echeance);
        }
        foreach ($this->em->getRepository(Depense::class)->findBy(['ville' => $ville, 'libelle' => self::DEPENSE]) as $depense) {
            $this->em->remove($depense);
        }
        $this->em->remove($appel);
        $this->em->flush();

        return \sprintf('Appel de démonstration retiré, avec %d contributions et sa dépense liée. Les comptes de démonstration sont conservés.', $paiements);
    }
}
