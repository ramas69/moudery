<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Foyer;
use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\TypeEvenement;
use App\Journal\Journal;
use App\Repository\AssociationRepository;
use App\Repository\FoyerRepository;
use App\Repository\MembreRepository;
use App\Repository\VilleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Jeu d'essai : des membres et des foyers plausibles pour une ville (prénoms et noms de famille soninkés courants
 * dans la diaspora de la vallée du fleuve Sénégal). Réservé aux environnements de démonstration : les fiches
 * portent l'origine « generation » et les adresses sont en example.org, aucun email ne peut leur parvenir.
 */
#[AsCommand(name: 'app:membres:generer', description: 'Génère des membres et des foyers fictifs dans une ville, pour essayer l’application.')]
final class GenererMembresCommand extends Command
{
    private const array PRENOMS = [
        'Mamadou', 'Awa', 'Fatoumata', 'Ibrahima', 'Seydou', 'Aïssata', 'Moussa', 'Kadiatou', 'Ousmane', 'Mariam',
        'Bakary', 'Hawa', 'Souleymane', 'Djénéba', 'Abdoulaye', 'Coumba', 'Amadou', 'Oumou', 'Boubacar', 'Fanta',
        'Samba', 'Aminata', 'Lassana', 'Ramata', 'Modibo', 'Kandia', 'Yacouba', 'Sira', 'Cheikhna', 'Maïmouna',
        'Demba', 'Haby', 'Sékou', 'Néné', 'Diadié', 'Fatimata', 'Gaye', 'Assa', 'Hamady', 'Binta',
    ];

    private const array NOMS = [
        'Diaby', 'Cissé', 'Sylla', 'Doucouré', 'Camara', 'Traoré', 'Konaté', 'Fofana', 'Diakité', 'Soumaré',
        'Tandia', 'Sakho', 'Dramé', 'Diallo', 'Bathily', 'Kébé', 'Niakaté', 'Coulibaly', 'Gassama', 'Touré',
        'Sissoko', 'Dabo', 'Diawara', 'Yatabaré', 'Sidibé', 'Danfakha', 'Wagué', 'Magassa', 'Timera', 'Sy',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssociationRepository $associations,
        private readonly VilleRepository $villes,
        private readonly MembreRepository $membres,
        private readonly FoyerRepository $foyers,
        private readonly Journal $journal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('association', InputArgument::REQUIRED, 'Slug de l’association, par exemple « moudery »')
            ->addArgument('ville', InputArgument::REQUIRED, 'Nom de la ville, par exemple « Lyon »')
            ->addOption('nombre', null, InputOption::VALUE_REQUIRED, 'Nombre de membres à créer', '40')
            ->addOption('graine', null, InputOption::VALUE_REQUIRED, 'Graine du tirage, pour rejouer le même jeu')
            ->addOption('purger', null, InputOption::VALUE_NONE, 'Supprime les membres fictifs de la ville (et leurs foyers vidés) au lieu d’en créer')
            ->setHelp(<<<'AIDE'
                Exemples :
                  <info>%command.name% moudery Lyon</info>
                  <info>%command.name% moudery Lyon --nombre=120 --graine=2026</info>
                  <info>%command.name% moudery Lyon --purger</info>   retire les membres fictifs avant d'importer les vrais

                Environ six membres sur dix vivent en foyer (deux à quatre personnes du même nom, un payeur désigné),
                15 % n'ont pas d'adresse e-mail, 10 % attendent encore la validation de leur inscription. Chacun a
                entre une et trois années d'adhésion parmi l'année en cours et les deux précédentes.
                AIDE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $association = $this->associations->trouverParSlug((string) $input->getArgument('association'));
        if (null === $association) {
            $io->error(\sprintf('Aucune association avec le slug « %s ».', $input->getArgument('association')));

            return Command::FAILURE;
        }
        $ville = $this->villes->trouverParNom($association, (string) $input->getArgument('ville'));
        if (null === $ville) {
            $io->error(\sprintf('Aucune ville « %s » dans l’association « %s ».', $input->getArgument('ville'), $association->getNom()));

            return Command::FAILURE;
        }
        if ($input->getOption('purger')) {
            [$membres, $foyers] = $this->purger($ville);
            $io->success(\sprintf('%d membres fictifs et %d foyers vidés supprimés dans %s (%s).', $membres, $foyers, $ville->getNom(), $association->getNom()));

            return Command::SUCCESS;
        }

        $nombre = (int) $input->getOption('nombre');
        if ($nombre < 1 || $nombre > 5000) {
            $io->error('Le nombre de membres doit être compris entre 1 et 5000.');

            return Command::INVALID;
        }
        $graine = $input->getOption('graine');
        mt_srand(null !== $graine ? (int) $graine : random_int(1, \PHP_INT_MAX >> 1));

        $reperes = $this->membres->reperesPourImport($ville);
        $emailsPris = $reperes['emails'];
        $anneeCourante = (int) date('Y');
        $foyersCrees = 0;
        $adhesions = 0;
        $crees = 0;
        $tailleFoyer = 0;
        $foyer = null;
        $nomFoyer = null;

        while ($crees < $nombre) {
            if (0 === $tailleFoyer) {
                // Un nouveau groupe : soit un foyer de 2 à 4 personnes du même nom (six fois sur dix), soit une personne seule.
                $nomFoyer = self::NOMS[mt_rand(0, \count(self::NOMS) - 1)];
                if (mt_rand(1, 10) <= 6) {
                    $tailleFoyer = min(mt_rand(2, 4), $nombre - $crees);
                    $foyer = $this->foyerLibre($ville, 'Famille '.$nomFoyer);
                    ++$foyersCrees;
                } else {
                    $tailleFoyer = 1;
                    $foyer = null;
                }
            }

            $prenom = self::PRENOMS[mt_rand(0, \count(self::PRENOMS) - 1)];
            $email = mt_rand(1, 100) <= 85 ? $this->emailLibre($prenom, (string) $nomFoyer, $emailsPris) : null;
            $telephone = mt_rand(1, 100) <= 90 ? \sprintf('0%d%08d', mt_rand(6, 7), mt_rand(0, 99999999)) : null;
            $statut = mt_rand(1, 100) <= 10 ? MembreStatut::EnAttente : MembreStatut::Actif;

            $membre = new Membre($ville, $prenom, (string) $nomFoyer, $email, $telephone, $statut, Membre::ORIGINE_GENERATION);
            if (null !== $foyer) {
                $membre->rejoindreFoyer($foyer);
            }
            foreach (self::anneesAdhesion($anneeCourante) as $annee) {
                $membre->adherer($annee, Membre::ORIGINE_GENERATION);
                ++$adhesions;
            }
            $this->entityManager->persist($membre);

            ++$crees;
            --$tailleFoyer;
        }

        $this->journal->consigner(TypeEvenement::MembresImportes, null, $association, $ville->getNom(), ['nombre' => $crees, 'generation' => true]);
        $this->entityManager->flush();

        $io->success(\sprintf('%d membres, %d foyers et %d adhésions fictifs créés dans %s (%s).', $crees, $foyersCrees, $adhesions, $ville->getNom(), $association->getNom()));

        return Command::SUCCESS;
    }

    /**
     * Retire les fiches d'origine « generation » de la ville, puis les foyers restés sans membre.
     *
     * @return array{int, int} membres et foyers supprimés
     */
    private function purger(\App\Entity\Ville $ville): array
    {
        $membres = $this->membres->findBy(['ville' => $ville, 'origine' => Membre::ORIGINE_GENERATION]);
        foreach ($membres as $membre) {
            $membre->rejoindreFoyer(null);
            $this->entityManager->remove($membre);
        }
        $this->entityManager->flush();

        $foyersSupprimes = 0;
        foreach ($this->foyers->listerPourVille($ville) as $foyer) {
            if ($foyer->getMembres()->isEmpty()) {
                $this->entityManager->remove($foyer);
                ++$foyersSupprimes;
            }
        }
        $this->entityManager->flush();

        return [\count($membres), $foyersSupprimes];
    }

    /**
     * Les années d'adhésion d'un membre fictif : l'année en cours huit fois sur dix, chacune des deux précédentes six
     * fois sur dix, et au moins une année (comme le classeur réel : une ligne par membre et par année).
     *
     * @return list<int>
     */
    private static function anneesAdhesion(int $anneeCourante): array
    {
        $annees = [];
        foreach ([$anneeCourante - 2 => 6, $anneeCourante - 1 => 6, $anneeCourante => 8] as $annee => $chancesSurDix) {
            if (mt_rand(1, 10) <= $chancesSurDix) {
                $annees[] = $annee;
            }
        }

        return [] === $annees ? [$anneeCourante] : $annees;
    }

    /** Un foyer au nom encore libre dans la ville : « Famille Diaby », puis « Famille Diaby 2 »… */
    private function foyerLibre(\App\Entity\Ville $ville, string $nom): Foyer
    {
        $candidat = $nom;
        $suffixe = 1;
        while (null !== $this->foyers->trouverParNom($ville, $candidat) || $this->foyerEnAttente($ville, $candidat)) {
            $candidat = $nom.' '.++$suffixe;
        }
        $foyer = new Foyer($ville, $candidat);
        $this->entityManager->persist($foyer);

        return $foyer;
    }

    private function foyerEnAttente(\App\Entity\Ville $ville, string $nom): bool
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof Foyer && $entite->getVille() === $ville && $entite->getNom() === $nom) {
                return true;
            }
        }

        return false;
    }

    /** « Aïssata » devient « aissata » : minuscules sans accent, lettres seules. */
    private static function ascii(string $texte): string
    {
        $translittere = iconv('UTF-8', 'ASCII//TRANSLIT', $texte);

        return (string) preg_replace('/[^a-z]+/', '', strtolower(false === $translittere ? $texte : $translittere));
    }

    /**
     * @param array<string, true> $pris mis à jour
     */
    private function emailLibre(string $prenom, string $nom, array &$pris): string
    {
        $base = self::ascii($prenom).'.'.self::ascii($nom);
        $candidat = $base.'@example.org';
        $suffixe = 1;
        while (isset($pris[$candidat])) {
            $candidat = $base.++$suffixe.'@example.org';
        }
        $pris[$candidat] = true;

        return $candidat;
    }
}
