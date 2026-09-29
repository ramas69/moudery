<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\Evenement;
use App\Entity\Membre;
use App\Entity\TypeEvenement;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;
use App\Tests\Ville\ClasseurExcel;
use Symfony\Component\DomCrawler\Crawler;

/** Import d'un fichier pour toute l'association (F-31) : onglets ou colonne « caisse » rattachés aux villes, création en brouillon, bilan. */
final class AssociationImportTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', ['tresorier' => 'tresorier@example.org']);
        foreach (glob(self::dossierDepot().'/*.bin') ?: [] as $fichier) {
            unlink($fichier);
        }
    }

    public function testSeulLeBureauCentralImporte(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'tresorier@example.org', Role::Tresorier, $this->lyon));
        $this->client->request('GET', '/associations/moudery/import');
        self::assertResponseStatusCodeSame(403);

        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery/import');
        self::assertResponseStatusCodeSame(403, 'Le bureau central de Bakel n’importe rien chez Moudery.');
    }

    public function testUnClasseurAvecUnOngletParVilleCreeLesVillesManquantes(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $classeur = ClasseurExcel::association([
            'Lyon' => [['DIABY', 'MAMADOU', '2024', 'Lyon'], ['DIABY', 'MAMADOU', '2025', 'Lyon'], ['CISSÉ', 'AWA', '2025', 'Villeurbanne'], ['', 'SANS', '2025', ''], ['', '', '2025', 'Ligne vide']],
            'Marseille' => [['SYLLA', 'SEYDOU', '2025', 'Marseille'], ['TRAORÉ', 'FANTA', '2025', 'Aubagne']],
        ]);

        $this->client->request('GET', '/associations/moudery/import');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Importer un fichier');
        $crawler = $this->deposer($classeur);

        // Rattachement : l'onglet Synthèse est écarté, Lyon proposée, Marseille à créer.
        self::assertSelectorTextContains('h1', 'Rattacher aux villes');
        self::assertSelectorExists('.filtres a[aria-current="true"][href$="mode=onglets"]');
        self::assertCount(3, $crawler->filter('.tableau tbody tr'));
        self::assertSelectorTextContains('.import__ligne--ignoree', 'Synthèse');
        self::assertSelectorTextContains('.import__ligne--ignoree', 'Pas de colonnes Nom et Prénom');
        self::assertSame('ville:'.$this->lyon, $crawler->filter('#rattachement_import_cibles_f1 option[selected]')->attr('value'));
        self::assertSame('creer', $crawler->filter('#rattachement_import_cibles_f2 option[selected]')->attr('value'));
        self::assertStringContainsString('Créer la ville « Marseille »', $crawler->filter('#rattachement_import_cibles_f2')->text());

        $this->client->submit($crawler->filter('form[action$="/import/rattachement"]')->form());
        self::assertResponseRedirects('/associations/moudery/import/apercu', 303);
        $crawler = $this->client->followRedirect();

        // Aperçu : deux membres pour Lyon (une adhésion de plus pour Mamadou 2025), deux pour Marseille, une erreur.
        self::assertSelectorTextContains('h1', 'Aperçu de l’import');
        self::assertCount(2, $crawler->filter('section[aria-labelledby="import-par-ville"] tbody tr'));
        self::assertSelectorTextContains('section[aria-labelledby="import-par-ville"] tbody tr:first-child', 'Lyon');
        self::assertSelectorTextContains('section[aria-labelledby="import-par-ville"] tbody tr:last-child', 'À créer');
        self::assertSelectorTextContains('section[aria-labelledby="import-par-ville"] tfoot', '4');
        self::assertSelectorTextContains('.kpis', 'Villes à créer');
        self::assertSelectorTextContains('button.bouton--primaire', 'Importer 4 membres dans 2 villes');
        self::assertSelectorTextContains('.import__erreurs-bloc', 'Prénom et nom obligatoires');
        self::assertSelectorTextContains('.kpis', 'Lignes ignorées');
        self::assertSelectorTextContains('section[aria-labelledby="import-par-ville"] tbody tr:first-child', '1', 'La ligne numérotée sans personne est ignorée, pas en erreur.');
        self::assertSame(0, $this->em()->getRepository(Membre::class)->count([]), 'Rien n’est enregistré avant la confirmation.');

        $this->client->submit($crawler->filter('form[action$="/import/confirmer"]')->form());
        self::assertResponseRedirects('/associations/moudery/import/bilan', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Import terminé');
        self::assertSelectorTextContains('.alerte--succes', '4 membres créés, 1 adhésion ajoutée, 1 ville créée.');
        self::assertSelectorTextContains('.tableau', 'Créée');
        self::assertCount(2, $crawler->filter('a[href$="/assistant/membres"]'));

        $em = $this->em();
        $marseille = $em->getRepository(Ville::class)->findOneBy(['nom' => 'Marseille']);
        self::assertInstanceOf(Ville::class, $marseille);
        self::assertSame(VilleStatut::Brouillon, $marseille->getStatut());
        self::assertSame(EtapeAssistant::Membres, $marseille->getEtapeAssistant());
        self::assertSame($this->moudery, $marseille->getAssociation()->getId());
        self::assertSame(4, $em->getRepository(Membre::class)->count([]));
        $mamadou = $em->getRepository(Membre::class)->findOneBy(['nom' => 'Diaby']);
        self::assertSame('Mamadou', $mamadou?->getPrenom(), 'Les capitales sont remises en casse.');
        self::assertSame([2024, 2025], $mamadou?->getAnneesAdhesion());
        self::assertSame('Villeurbanne', $em->getRepository(Membre::class)->findOneBy(['nom' => 'Cissé'])?->getLocalite());
        self::assertSame('Marseille', $em->getRepository(Membre::class)->findOneBy(['nom' => 'Sylla'])?->getVille()->getNom());
        self::assertInstanceOf(Evenement::class, $em->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::VilleCreee, 'cible' => 'Marseille']));
        self::assertSame(2, $em->getRepository(Evenement::class)->count(['type' => TypeEvenement::MembresImportes]));

        $association = $em->find(Association::class, $this->moudery);
        self::assertSame('onglets', $association?->getReglagesImport()['mode'] ?? null, 'Le mode est mémorisé pour la prochaine fois.');
        self::assertSame('nom', $association?->getDictionnaireImport()['noms'] ?? null);
        self::assertSame([], glob(self::dossierDepot().'/*.bin') ?: [], 'Le fichier déposé est supprimé.');

        // Revenir sur l'aperçu sans import en cours renvoie au dépôt.
        $this->client->request('GET', '/associations/moudery/import/apercu');
        self::assertResponseRedirects('/associations/moudery/import', 303);
    }

    public function testUneColonneCaisseRepartitLesLignesEntreLesVilles(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $csv = "Cotisant;Section;Contact;Année\n"
            ."DIABY Mamadou;LYON;06 12 34 56 78;2025\n"
            ."CISSÉ Awa;Lyon;;2025\n"
            ."SYLLA Seydou;Marseille;;2025\n"
            ."TRAORÉ Fanta;Paris;;2025\n";

        $crawler = $this->deposer($csv);
        self::assertSelectorExists('.filtres a[aria-current="true"][href$="mode=colonne"]', 'Une seule feuille : le mode colonne est proposé.');
        self::assertSelectorTextContains('h2', 'Feuille et colonne');
        self::assertSame('1', $crawler->filter('#rattachement_import_colonne option[selected]')->attr('value'), '« Section » est reconnue comme colonne de caisse.');
        self::assertSelectorTextContains('#import-valeurs', 'Valeurs de la colonne « Section »');
        self::assertCount(3, $crawler->filter('section[aria-labelledby="import-valeurs"] tbody tr'), 'LYON et Lyon ne font qu’une valeur.');
        self::assertSelectorTextContains('section[aria-labelledby="import-valeurs"] tbody tr:first-child', 'Lyon');
        self::assertSame('ville:'.$this->lyon, $crawler->filter('#rattachement_import_cibles_v0 option[selected]')->attr('value'));
        self::assertSame('creer', $crawler->filter('#rattachement_import_cibles_v1 option[selected]')->attr('value'));

        // Paris ne sera pas importée cette fois.
        $formulaire = $crawler->filter('form[action$="/import/rattachement"]')->form();
        $formulaire['rattachement_import[cibles][v2]']->select('');
        $this->client->submit($formulaire);
        self::assertResponseRedirects('/associations/moudery/import/apercu', 303);
        $crawler = $this->client->followRedirect();
        self::assertCount(2, $crawler->filter('section[aria-labelledby="import-par-ville"] tbody tr'));
        self::assertSelectorTextContains('button.bouton--primaire', 'Importer 3 membres dans 2 villes');

        $this->client->submit($crawler->filter('form[action$="/import/confirmer"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', '3 membres créés');

        $em = $this->em();
        self::assertSame(3, $em->getRepository(Membre::class)->count([]));
        self::assertNull($em->getRepository(Ville::class)->findOneBy(['nom' => 'Paris']));
        $mamadou = $em->getRepository(Membre::class)->findOneBy(['nom' => 'Diaby']);
        self::assertSame('Mamadou', $mamadou?->getPrenom(), 'Le nom complet « DIABY Mamadou » est séparé.');
        self::assertSame('Lyon', $mamadou?->getVille()->getNom());
        self::assertSame('Marseille', $em->getRepository(Membre::class)->findOneBy(['nom' => 'Sylla'])?->getVille()->getNom());
        $association = $em->find(Association::class, $this->moudery);
        self::assertSame('Section', $association?->getReglagesImport()['colonne_caisse'] ?? null);
        self::assertSame('nom_complet', $association?->getDictionnaireImport()['cotisant'] ?? null);
    }

    public function testSansAucunRattachementLAnalyseEstRefusee(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $this->deposer(ClasseurExcel::association(['Lyon' => [['DIABY', 'MAMADOU', '2025', '']]]));
        // Une seule feuille importable : le mode colonne est proposé d'office, on repasse en onglets.
        $crawler = $this->client->request('GET', '/associations/moudery/import/rattachement?mode=onglets');
        $formulaire = $crawler->filter('form[action$="/import/rattachement"]')->form();
        $formulaire['rattachement_import[cibles][f1]']->select('');
        $this->client->submit($formulaire);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.champ__erreur', 'Rattachez au moins un onglet ou une valeur à une ville.');
    }

    public function testUnFichierSansListeEstRefuseAuDepot(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $this->deposer("PK\x03\x04pas un classeur");

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#import_membres_fichier_erreur', 'Ce classeur Excel ne se lit pas.');
    }

    public function testLAnnulationOublieLeFichier(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $crawler = $this->deposer(ClasseurExcel::association(['Lyon' => [['DIABY', 'MAMADOU', '2025', '']]]));

        $this->client->submit($crawler->filter('form[action$="/import/annuler"]')->form());

        self::assertResponseRedirects('/associations/moudery/import', 303);
        self::assertSame([], glob(self::dossierDepot().'/*.bin') ?: []);
        $this->client->request('GET', '/associations/moudery/import/rattachement');
        self::assertResponseRedirects('/associations/moudery/import', 303);
    }

    /** Dépose le fichier et, s'il est accepté, rend la page de rattachement. */
    private function deposer(string $contenu): Crawler
    {
        $chemin = tempnam(sys_get_temp_dir(), 'import');
        \assert(false !== $chemin);
        file_put_contents($chemin, $contenu);

        $crawler = $this->client->request('GET', '/associations/moudery/import');
        $formulaire = $crawler->filter('form[action$="/associations/moudery/import"]')->form();
        $formulaire['import_membres[fichier]']->upload($chemin);
        $this->client->submit($formulaire);
        if (!$this->client->getResponse()->isRedirect()) {
            return $this->client->getCrawler();
        }
        self::assertResponseRedirects('/associations/moudery/import/rattachement', 303);

        return $this->client->followRedirect();
    }

    private static function dossierDepot(): string
    {
        return (string) static::getContainer()->getParameter('kernel.project_dir').'/var/import/test';
    }
}
