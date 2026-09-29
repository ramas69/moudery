<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\EtapeAssistant;
use App\Entity\Evenement;
use App\Entity\Foyer;
use App\Entity\Membre;
use App\Entity\MembreStatut;
use App\Entity\TypeEvenement;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;
use App\Tests\Ville\ClasseurExcel;
use Symfony\Component\DomCrawler\Crawler;

/** Étape 3 « Membres » de l'assistant (F-42) : ajout à la main, import CSV avec aperçu, liste, retrait, isolation. */
final class VilleAssistantMembresTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', ['tresorier' => 'tresorier@example.org'], EtapeAssistant::Membres);
        foreach (glob(self::dossierDepot().'/*.bin') ?: [] as $fichier) {
            unlink($fichier);
        }
    }

    public function testLaPageDesMembresEstVideAuDepart(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Membres de Lyon');
        self::assertSelectorTextContains('.membres__vide', 'Aucun membre pour l’instant');
        self::assertSelectorExists('details#volet-import[open]', 'Sans membre, le volet d’import est ouvert d’office.');
        self::assertSelectorExists('form[action$="/assistant/membres/ajouter"]');
        self::assertSelectorExists('form[action$="/assistant/membres/importer"] input[type="file"]');
        self::assertSelectorExists('form[action$="/assistant/membres/continuer"] button');
        self::assertSelectorTextContains('.panneau__faits', 'Membres');
        self::assertCount(1, $crawler->filter('.etapes__item--courante'), 'L’étape 2 est la courante.');
    }

    public function testLeBureauCentralAjouteUnMembreAvecUnNouveauFoyer(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', $this->url());
        $formulaire = $crawler->filter('form[action$="/assistant/membres/ajouter"]')->form([
            'membre[prenom]' => 'Mamadou',
            'membre[nom]' => 'Diaby',
            'membre[email]' => 'Mamadou.Diaby@Example.org',
            'membre[telephone]' => '06 12 34 56 78',
            'membre[nouveauFoyer]' => 'Famille Diaby',
        ]);
        $this->client->submit($formulaire);

        self::assertResponseRedirects($this->url(), 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Mamadou Diaby est ajouté aux membres.');
        self::assertSelectorTextContains('.tableau--membres', 'Mamadou Diaby');
        self::assertSelectorTextContains('.tableau--membres', 'mamadou.diaby@example.org');
        self::assertSelectorTextContains('.tableau--membres', '06 12 34 56 78');
        self::assertSelectorTextContains('.tableau--membres', 'Famille Diaby');
        self::assertSelectorTextContains('.tableau--membres', 'Payeur');
        self::assertStringContainsString('Famille Diaby', $crawler->filter('select#membre_foyer')->text(), 'Le nouveau foyer est proposé aux membres suivants.');

        $em = $this->em();
        $membre = $em->getRepository(Membre::class)->findOneBy(['email' => 'mamadou.diaby@example.org']);
        self::assertInstanceOf(Membre::class, $membre);
        self::assertSame($this->lyon, $membre->getVille()->getId());
        self::assertSame($this->moudery, $membre->getAssociation()->getId());
        self::assertSame(MembreStatut::Actif, $membre->getStatut());
        self::assertSame('Famille Diaby', $membre->getFoyer()?->getNom());
        self::assertSame($membre->getId(), $membre->getFoyer()->getPayeur()?->getId());

        $evenement = $em->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::MembreAjoute]);
        self::assertInstanceOf(Evenement::class, $evenement);
        self::assertSame('Mamadou Diaby', $evenement->getCible());
        self::assertSame('central@example.org', $evenement->getActeur()?->getEmail());
    }

    public function testUnAjoutInvalideRepond422EtGardeLeVoletOuvert(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $this->ajouterMembre('Awa', 'Cissé', 'awa@example.org');

        $crawler = $this->client->request('GET', $this->url());
        $formulaire = $crawler->filter('form[action$="/assistant/membres/ajouter"]')->form([
            'membre[prenom]' => '',
            'membre[nom]' => 'Diaby',
            'membre[email]' => 'awa@example.org',
        ]);
        $this->client->submit($formulaire);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('details#volet-ajout[open]');
        self::assertSelectorTextContains('#membre_prenom_erreur', 'Le prénom est obligatoire.');
        self::assertSelectorTextContains('#membre_email_erreur', 'Un membre de cette ville utilise déjà cette adresse.');
        self::assertSame(1, $this->em()->getRepository(Membre::class)->count([]));
    }

    public function testLImportPasseParUnApercuAvantDEnregistrer(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $this->ajouterMembre('Awa', 'Cissé', 'awa@example.org');

        $csv = "\xEF\xBB\xBFPrénom;Nom;E-mail;Téléphone;Foyer\r\n"
            ."Mamadou;Diaby;mamadou@example.org;06 12 34 56 78;Famille Diaby\r\n"
            ."Fatoumata;Diaby;;07 00 00 00 00;Famille Diaby\r\n"
            ."Seydou;Sylla;pas-une-adresse;;\r\n"
            .";Konaté;;;\r\n"
            ."Awa;Cissé;awa@example.org;;\r\n"
            ."Mamadou;Diaby;mamadou@example.org;;\r\n";

        $crawler = $this->importer($csv);
        self::assertSelectorTextContains('h1', 'Réglages de l’import');
        self::assertSelectorNotExists('.filtres', 'Un CSV n’a qu’une feuille : pas de choix.');
        self::assertSame('1', $crawler->filter('#reglages_import_ligneEnTete')->attr('value'));
        self::assertSame(['prenom', 'nom', 'email', 'telephone', 'foyer'], array_map(static fn ($v) => (string) $v, [
            $crawler->filter('#reglages_import_colonnes_c0 option[selected]')->attr('value'),
            $crawler->filter('#reglages_import_colonnes_c1 option[selected]')->attr('value'),
            $crawler->filter('#reglages_import_colonnes_c2 option[selected]')->attr('value'),
            $crawler->filter('#reglages_import_colonnes_c3 option[selected]')->attr('value'),
            $crawler->filter('#reglages_import_colonnes_c4 option[selected]')->attr('value'),
        ]), 'Les colonnes sont attribuées d’après leurs en-têtes.');

        $this->regler($crawler);
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Aperçu de l’import');
        self::assertSelectorExists('a[href$="/importer/reglages"]');
        self::assertSelectorTextContains('.membres__stat--succes .membres__stat-valeur', '2');
        self::assertSelectorTextContains('.membres__stat--erreur .membres__stat-valeur', '2');
        self::assertCount(2, $crawler->filter('.import__ligne--erreur'));
        self::assertCount(2, $crawler->filter('.import__ligne--ignoree'), 'Awa est déjà membre, Mamadou apparaît deux fois : ignorés, pas en erreur.');
        self::assertSelectorTextContains('.tableau--membres', 'Adresse e-mail invalide');
        self::assertSelectorTextContains('.tableau--membres', 'Prénom et nom obligatoires');
        self::assertSelectorTextContains('.tableau--membres', 'Déjà membre de la ville');
        self::assertSelectorTextContains('.tableau--membres', 'Même personne qu’à la ligne 2');
        self::assertSelectorTextContains('button.bouton--primaire', 'Importer 2 membres');
        self::assertSame(1, $this->em()->getRepository(Membre::class)->count([]), 'Rien n’est enregistré avant la confirmation.');

        $this->client->submit($crawler->filter('form[action$="/importer/confirmer"]')->form());

        self::assertResponseRedirects($this->url(), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', '2 membres importés.');
        self::assertSelectorTextContains('.membres__stats', '3');

        $em = $this->em();
        self::assertSame(3, $em->getRepository(Membre::class)->count([]));
        $foyer = $em->getRepository(Foyer::class)->findOneBy(['nom' => 'Famille Diaby']);
        self::assertInstanceOf(Foyer::class, $foyer);
        self::assertCount(2, $foyer->getMembres());
        self::assertSame('Mamadou', $foyer->getPayeur()?->getPrenom());
        $importe = $em->getRepository(Membre::class)->findOneBy(['email' => 'mamadou@example.org']);
        self::assertSame(Membre::ORIGINE_IMPORT, $importe?->getOrigine());
        self::assertSame('0612345678', $importe?->getTelephone());

        $evenement = $em->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::MembresImportes]);
        self::assertSame(['nombre' => 2], $evenement?->getDetails());

        // L'aperçu est consommé : y revenir renvoie à la liste, et le fichier déposé a disparu.
        $this->client->request('GET', $this->url().'/importer/apercu');
        self::assertResponseRedirects($this->url(), 303);
        self::assertSame([], glob(self::dossierDepot().'/*.bin') ?: []);
    }

    public function testLImportAccepteLaVirguleEtDesEnTetesAnglais(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $this->regler($this->importer("first name,last name,mail,phone\n\"Aïssata\",\"Doucouré\",aissata@example.org,\"+33 6 00 00 00 01\"\nMoussa,Camara,,\n"));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.membres__stat--succes .membres__stat-valeur', '2');
        self::assertCount(0, $crawler->filter('.import__ligne--erreur'));
        self::assertSelectorTextContains('.tableau--membres', '+33600000001');
    }

    public function testUnClasseurExcelSImporteCommeUnCsv(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $this->regler($this->importer(ClasseurExcel::membres()));

        self::assertResponseRedirects($this->url().'/importer/apercu', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.membres__stat--succes .membres__stat-valeur', '3');
        self::assertCount(0, $crawler->filter('.import__ligne--erreur'));
        self::assertSelectorTextContains('.tableau--membres', '0612345678', 'Le zéro de tête perdu par Excel est rétabli.');
        self::assertSelectorTextContains('.tableau--membres', 'Seydou Sylla');

        $this->client->submit($crawler->filter('form[action$="/importer/confirmer"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', '3 membres importés.');
        $membre = $this->em()->getRepository(Membre::class)->findOneBy(['email' => 'mamadou@example.org']);
        self::assertSame('0612345678', $membre?->getTelephone());
        self::assertSame('Famille Diaby', $membre?->getFoyer()?->getNom());
    }

    public function testUnClasseurExcelIllisibleEstRefuse(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $this->importer("PK\x03\x04ceci n'est pas un classeur");

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#import_membres_fichier_erreur', 'Ce classeur Excel ne se lit pas.');
    }

    public function testUnFichierSansEnTetesReconnusSeRegleALaMain(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->importer("Mamadou;Diaby;06 12 34 56 78\nAwa;Cissé;\n");
        self::assertSame('0', $crawler->filter('#reglages_import_ligneEnTete')->attr('value'), 'Aucun en-tête reconnu : toutes les lignes sont des membres.');
        self::assertCount(0, $crawler->filter('select option[selected]'), 'Rien n’est attribué.');
        self::assertSelectorTextContains('.tableau--reglages', 'Colonne A');

        // Sans prénom ni nom, impossible de continuer.
        $this->regler($crawler, attendreApercu: false);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.reglages__bloc .champ__erreur', 'Indiquez la colonne du prénom.');
        self::assertSelectorTextContains('.reglages__bloc .champ__erreur', 'Indiquez la colonne du nom.');

        // Le même champ sur deux colonnes est refusé.
        $crawler = $this->client->getCrawler();
        $this->regler($crawler, ['reglages_import[colonnes][c0]' => 'nom', 'reglages_import[colonnes][c1]' => 'nom', 'reglages_import[colonnes][c2]' => 'prenom'], attendreApercu: false);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.import__ligne--erreur .champ__erreur', 'Ce champ est déjà attribué à une autre colonne.');

        // Avec les bonnes colonnes, les deux lignes sont prêtes.
        $this->regler($this->client->getCrawler(), ['reglages_import[colonnes][c0]' => 'prenom', 'reglages_import[colonnes][c1]' => 'nom', 'reglages_import[colonnes][c2]' => 'telephone']);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.membres__stat--succes .membres__stat-valeur', '2');
        self::assertSelectorTextContains('.tableau--membres', 'Mamadou Diaby');
        self::assertSelectorTextContains('.tableau--membres', '0612345678');
        self::assertCount(0, $crawler->filter('.import__ligne--erreur'));
    }

    public function testUnTableauDeSuiviAvecTitreAnneesEtCapitalesSImporte(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        // Comme le vrai classeur : un titre au-dessus, des en-têtes en ligne 2, une ligne par membre et par année, des noms en capitales.
        $csv = ";;;;;;CAISSE DE MOUDERI LYON\n"
            ."N°;Noms;Prénoms;Villes;Année;JANVIER;FÉVRIER;Total\n"
            ."1;DIABY;MAMADOU;Lyon;2019;10;10;20\n"
            ."2;CISSÉ;AWA  BOYE;Lyon;2019;5;5;10\n"
            ."3;DIABY;MAMADOU;Lyon;2020;10;0;10\n"
            ."4;;;Lyon;2020;;;\n";

        $crawler = $this->importer($csv);
        self::assertSame('2', $crawler->filter('#reglages_import_ligneEnTete')->attr('value'), 'La ligne d’en-têtes est la deuxième ligne non vide.');
        self::assertSame('nom', $crawler->filter('#reglages_import_colonnes_c1 option[selected]')->attr('value'));
        self::assertSame('prenom', $crawler->filter('#reglages_import_colonnes_c2 option[selected]')->attr('value'));
        self::assertCount(6, $crawler->filter('select option[selected]'), 'N° et Total restent ignorés ; Villes, Année, JANVIER et FÉVRIER sont reconnus.');
        self::assertSame('mois_1', $crawler->filter('#reglages_import_colonnes_c5 option[selected]')->attr('value'));
        self::assertSame('localite', $crawler->filter('#reglages_import_colonnes_c3 option[selected]')->attr('value'));
        self::assertSame('annee', $crawler->filter('#reglages_import_colonnes_c4 option[selected]')->attr('value'));
        self::assertSelectorTextContains('.tableau--reglages', 'JANVIER');

        // « Appliquer » avec une autre ligne d'en-têtes réattribue les colonnes sans valider : la ligne de titre n'en contient aucun.
        $crawler = $this->appliquerLigneEnTete($crawler, 1);
        self::assertSame('1', $crawler->filter('#reglages_import_ligneEnTete')->attr('value'));
        self::assertCount(0, $crawler->filter('select option[selected]'));

        // Retour à la ligne 2 : les colonnes sont de nouveau reconnues ; Mamadou Diaby n'est créé qu'une fois, sa ligne 2020 devient une adhésion.
        $crawler = $this->appliquerLigneEnTete($crawler, 2);
        self::assertSame('nom', $crawler->filter('#reglages_import_colonnes_c1 option[selected]')->attr('value'));
        $this->regler($crawler);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.membres__stat--succes .membres__stat-valeur', '2');
        self::assertSelectorNotExists('.membres__stat--erreur', 'La ligne numérotée sans personne n’est pas une erreur.');
        self::assertSelectorTextContains('.membres__stats li:nth-child(2) .membres__stat-valeur', '0');
        self::assertCount(1, $crawler->filter('.import__ligne--ignoree'));
        self::assertSelectorTextContains('.import__ligne--ignoree', 'Aucun nom ni prénom sur cette ligne');
        self::assertCount(1, $crawler->filter('.import__ligne--adhesion'), 'Mamadou Diaby 2020 est la même personne que 2019 : une année d’adhésion de plus, pas une ligne ignorée.');
        self::assertSelectorTextContains('.import__ligne--adhesion', 'Adhésion 2020');
        self::assertSelectorTextContains('.tableau--membres', 'Mamadou Diaby');
        self::assertSelectorTextContains('.tableau--membres', 'Awa Boye Cissé');
        self::assertSelectorTextContains('.tableau--membres', 'Même personne qu’à la ligne 3');
        self::assertSelectorTextContains('.tableau--membres', 'Lyon · 2019', 'La localité et l’année se lisent dans l’aperçu.');
        self::assertSelectorTextContains('button.bouton--primaire', 'Importer 2 membres et 1 adhésion');

        $this->client->submit($crawler->filter('form[action$="/importer/confirmer"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', '2 membres importés, une adhésion ajoutée.');
        self::assertSelectorTextContains('.membres__adhesions', '2019 · 2');
        self::assertSelectorTextContains('.membres__adhesions', '2020 · 1');
        $membres = $this->em()->getRepository(Membre::class)->findBy([], ['nom' => 'ASC']);
        self::assertSame(['Awa Boye Cissé', 'Mamadou Diaby'], array_map(static fn (Membre $m): string => $m->getNomComplet(), $membres));
        self::assertSame([2019, 2020], $membres[1]->getAnneesAdhesion(), 'Les deux années de Mamadou Diaby sont gardées pour l’historique.');
        self::assertSame([2019], $membres[0]->getAnneesAdhesion());
        self::assertSame('Lyon', $membres[1]->getLocalite());

        // Les mois du classeur sont posés sur chaque adhésion : janvier et février 2019 de Mamadou, février 2020 à zéro.
        $adhesion2019 = $membres[1]->adhesionPour(2019);
        self::assertNotNull($adhesion2019);
        self::assertSame(1000, $adhesion2019->getMontantMois(1));
        self::assertSame(1000, $adhesion2019->getMontantMois(2));
        self::assertNull($adhesion2019->getMontantMois(3));
        self::assertSame(2000, $adhesion2019->getTotal());
        self::assertSame(10000, $adhesion2019->getReste(), 'Douze mois à 10 € attendus, deux versés.');
        self::assertSame(0, $membres[1]->adhesionPour(2020)?->getMontantMois(2));
        self::assertSame(500, $membres[0]->adhesionPour(2019)?->getTarifMensuel());

        // Réimporter le même fichier n'ajoute rien : les personnes et leurs années sont déjà connues.
        $crawler = $this->importer($csv);
        $this->regler($crawler);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.membres__stat--succes .membres__stat-valeur', '0');
        self::assertCount(4, $crawler->filter('.import__ligne--ignoree'), 'Trois lignes déjà connues, avec leurs années, plus la ligne sans personne.');
        self::assertCount(0, $crawler->filter('.import__ligne--adhesion'));
        self::assertSelectorExists('button.bouton--primaire[disabled]');
    }

    public function testUnNomCompletGlisseDansLaColonnePrenomEstSepare(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        // Comme l'onglet Lyon du vrai classeur : en 2020, le nom entier a été tapé dans « Prénoms », « Noms » restant vide.
        $csv = "Noms;Prénoms;Année\n"
            ."DIABY;MAMADOU;2019\n"
            .";DIABY MAMADOU;2020\n"
            .";CISSÉ AWA BOYE;2020\n"
            .";SEYDOU;2020\n";

        $this->regler($this->importer($csv));
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.membres__stat--succes .membres__stat-valeur', '2');
        self::assertSelectorTextContains('.membres__stat--erreur .membres__stat-valeur', '1', 'Un seul mot dans une seule colonne reste une erreur.');
        self::assertSelectorTextContains('.tableau--membres', 'Mamadou Diaby');
        self::assertSelectorTextContains('.tableau--membres', 'Awa Boye Cissé');
        self::assertCount(1, $crawler->filter('.import__ligne--adhesion'), 'Mamadou Diaby 2020 retrouve sa fiche de 2019 : une adhésion de plus.');
    }

    public function testOnChoisitLaFeuilleDUnClasseur(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->importer(ClasseurExcel::membresEnDeuxiemeFeuille());
        self::assertSelectorTextContains('.filtres a[aria-current="true"]', 'Membres · 1 ligne');
        self::assertSelectorTextContains('.filtres', 'Autre · 4 lignes');
        self::assertSelectorTextContains('.tableau--reglages', 'Notes de réunion');

        $crawler = $this->client->click($crawler->filter('.filtres a')->eq(1)->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.filtres a[aria-current="true"]', 'Autre · 4 lignes');
        self::assertSame('prenom', $crawler->filter('#reglages_import_colonnes_c0 option[selected]')->attr('value'));
        self::assertSame('1', $crawler->filter('#reglages_import_feuille')->attr('value'));

        $this->regler($crawler);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.membres__stat--succes .membres__stat-valeur', '3');
        self::assertSelectorTextContains('.tableau--membres', 'Seydou Sylla');
    }

    public function testLAnnulationOublieLApercu(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $this->regler($this->importer("Prénom;Nom\nMamadou;Diaby\n"));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[action$="/importer/annuler"]')->form());

        self::assertResponseRedirects($this->url(), 303);
        self::assertSame(0, $this->em()->getRepository(Membre::class)->count([]));
        self::assertSame([], glob(self::dossierDepot().'/*.bin') ?: [], 'Le fichier déposé est supprimé.');
        $this->client->request('GET', $this->url().'/importer/apercu');
        self::assertResponseRedirects($this->url(), 303);
        $this->client->request('GET', $this->url().'/importer/reglages');
        self::assertResponseRedirects($this->url(), 303);
    }

    public function testLeModeleCsvSeTelecharge(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $this->client->request('GET', $this->url().'/importer/modele.csv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringStartsWith("\xEF\xBB\xBFPrénom;Nom;E-mail;Téléphone;Foyer", (string) $this->client->getResponse()->getContent());
    }

    public function testLaListeSeChercheSeFiltreEtSePagine(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $em = $this->em();
        $ville = $em->find(Ville::class, $this->lyon);
        \assert($ville instanceof Ville);
        for ($i = 1; $i <= 27; ++$i) {
            $em->persist(new Membre($ville, 'Membre', \sprintf('Numéro %02d', $i), \sprintf('membre%02d@example.org', $i), null, 26 === $i ? MembreStatut::EnAttente : MembreStatut::Actif));
        }
        $em->persist(new Membre($ville, 'Kadiatou', 'Tandia', null, '0700000000'));
        $em->flush();

        $crawler = $this->client->request('GET', $this->url());
        self::assertCount(25, $crawler->filter('.tableau--membres tbody tr'));
        self::assertSelectorTextContains('.pagination__position', 'Page 1 sur 2');
        self::assertSelectorTextContains('.filtres', 'Tous · 28');
        self::assertSelectorTextContains('.filtres', 'En attente · 1');

        $crawler = $this->client->request('GET', $this->url().'?page=2');
        self::assertCount(3, $crawler->filter('.tableau--membres tbody tr'));

        $crawler = $this->client->request('GET', $this->url().'?q=tandia');
        self::assertCount(1, $crawler->filter('.tableau--membres tbody tr'));
        self::assertSelectorTextContains('.tableau--membres', 'Kadiatou Tandia');
        self::assertSelectorTextContains('.tableau--membres', '07 00 00 00 00');

        $crawler = $this->client->request('GET', $this->url().'?q=0700');
        self::assertCount(1, $crawler->filter('.tableau--membres tbody tr'), 'La recherche par téléphone ignore les espaces.');

        $crawler = $this->client->request('GET', $this->url().'?statut=en-attente');
        self::assertCount(1, $crawler->filter('.tableau--membres tbody tr'));
        self::assertSelectorTextContains('.tableau--membres', 'Numéro 26');

        $this->client->request('GET', $this->url().'?q=personne');
        self::assertSelectorTextContains('.membres__vide', 'Aucun membre ne correspond');
    }

    public function testRetirerUnMembreLeSupprimeEtLeConsigne(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $id = $this->ajouterMembre('Mamadou', 'Diaby', 'mamadou@example.org');

        $crawler = $this->client->request('GET', $this->url());
        $this->client->submit($crawler->filter(\sprintf('form[action$="/membres/%d/retirer"]', $id))->form());

        self::assertResponseRedirects($this->url(), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Mamadou Diaby est retiré des membres.');
        $em = $this->em();
        self::assertNull($em->find(Membre::class, $id));
        self::assertInstanceOf(Evenement::class, $em->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::MembreRetire]));
    }

    public function testRetirerSansJetonEstRefuse(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $id = $this->ajouterMembre('Mamadou', 'Diaby', 'mamadou@example.org');

        $this->client->request('POST', \sprintf('%s/%d/retirer', $this->url(), $id), ['_token' => 'faux']);

        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(Membre::class, $this->em()->find(Membre::class, $id));
    }

    public function testContinuerFaitAvancerLAssistant(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', $this->url());
        $this->client->submit($crawler->filter('form[action$="/assistant/membres/continuer"]')->form());

        self::assertResponseRedirects(\sprintf('/associations/moudery/villes/%d/assistant/activation', $this->lyon), 303);
        $ville = $this->em()->find(Ville::class, $this->lyon);
        self::assertSame(EtapeAssistant::Activation, $ville?->getEtapeAssistant());

        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant', $this->lyon));
        self::assertResponseRedirects(\sprintf('/associations/moudery/villes/%d/assistant/activation', $this->lyon), 303);
    }

    public function testLaRepriseDeLAssistantConduitAuxMembres(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));

        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant', $this->lyon));

        self::assertResponseRedirects($this->url(), 303);
    }

    public function testLeTresorierDeLaVilleGereSesMembresMaisPasCeuxDUneAutreVille(): void
    {
        $marseille = $this->creerVille($this->moudery, 'Marseille', [], EtapeAssistant::Membres);
        $this->connecter($this->creerUtilisateur($this->moudery, 'tresorier@example.org', Role::Tresorier, $this->lyon));

        $this->client->request('GET', $this->url());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->url($marseille));
        self::assertResponseStatusCodeSame(403);
    }

    public function testUneAutreAssociationNeVoitPasCesMembres(): void
    {
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.example.org', Role::BureauCentral));
        $idMembre = $this->ajouterMembreDirectement('Mamadou', 'Diaby');

        // Le filtre multi-tenant cache la ville de Moudery au bureau central de Bakel : 404, rien à modifier.
        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(404, 'Le bureau central de Bakel ne voit même pas une ville de Moudery.');

        $this->client->request('GET', \sprintf('/associations/bakel/villes/%d/assistant/membres', $this->lyon));
        self::assertResponseStatusCodeSame(404, 'Sous le slug de Bakel, la ville de Moudery n’existe pas.');

        $this->client->request('POST', \sprintf('/associations/bakel/villes/%d/assistant/membres/%d/retirer', $this->lyon, $idMembre));
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnMembreDUneAutreVilleNeSeRetirePasDIci(): void
    {
        $marseille = $this->creerVille($this->moudery, 'Marseille', [], EtapeAssistant::Membres);
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $em = $this->em();
        $ville = $em->find(Ville::class, $marseille);
        \assert($ville instanceof Ville);
        $membre = new Membre($ville, 'Seydou', 'Sylla');
        $em->persist($membre);
        $em->flush();

        $this->client->request('POST', \sprintf('%s/%d/retirer', $this->url(), $membre->getId()), ['_token' => 'peu-importe']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testLaListeDesMembresResteOuverteUneFoisLaVilleActive(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral));
        $em = $this->em();
        $ville = $em->find(Ville::class, $this->lyon);
        \assert($ville instanceof Ville);
        $ville->changerStatut(VilleStatut::Active);
        $em->flush();

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful('Le trésorier gère ses membres après l’activation.');
        self::assertSelectorNotExists('form[action$="/assistant/membres/continuer"]', 'Plus d’assistant : le bouton Continuer disparaît.');
        self::assertSelectorNotExists('.etapes');
    }

    private function url(?int $ville = null): string
    {
        return \sprintf('/associations/moudery/villes/%d/assistant/membres', $ville ?? $this->lyon);
    }

    private function ajouterMembre(string $prenom, string $nom, ?string $email): int
    {
        $crawler = $this->client->request('GET', $this->url());
        $this->client->submit($crawler->filter('form[action$="/assistant/membres/ajouter"]')->form([
            'membre[prenom]' => $prenom,
            'membre[nom]' => $nom,
            'membre[email]' => (string) $email,
        ]));
        self::assertResponseRedirects($this->url(), 303);
        $membre = $this->em()->getRepository(Membre::class)->findOneBy(['prenom' => $prenom, 'nom' => $nom]);
        \assert($membre instanceof Membre);

        return (int) $membre->getId();
    }

    private function ajouterMembreDirectement(string $prenom, string $nom): int
    {
        $em = $this->em();
        $ville = $em->find(Ville::class, $this->lyon);
        \assert($ville instanceof Ville);
        $membre = new Membre($ville, $prenom, $nom);
        $em->persist($membre);
        $em->flush();

        return (int) $membre->getId();
    }

    /** Dépose le fichier ; s'il est accepté, suit la redirection vers les réglages et rend cette page. */
    private function importer(string $contenu): Crawler
    {
        $chemin = tempnam(sys_get_temp_dir(), 'membres');
        \assert(false !== $chemin);
        file_put_contents($chemin, $contenu);

        $crawler = $this->client->request('GET', $this->url());
        $formulaire = $crawler->filter('form[action$="/assistant/membres/importer"]')->form();
        $formulaire['import_membres[fichier]']->upload($chemin);
        $this->client->submit($formulaire);
        if (!$this->client->getResponse()->isRedirect()) {
            return $this->client->getCrawler();
        }
        self::assertResponseRedirects($this->url().'/importer/reglages', 303);

        return $this->client->followRedirect();
    }

    /**
     * Valide les réglages tels que proposés, avec d'éventuelles modifications ; attend la redirection vers l'aperçu.
     *
     * @param array<string, string> $modifications
     */
    private function regler(Crawler $crawler, array $modifications = [], bool $attendreApercu = true): void
    {
        $formulaire = $crawler->filter('form[action$="/importer/reglages"]')->form();
        foreach ($modifications as $champ => $valeur) {
            $formulaire[$champ]->select($valeur);
        }
        $this->client->submit($formulaire);
        if ($attendreApercu) {
            self::assertResponseRedirects($this->url().'/importer/apercu', 303);
        }
    }

    /** Soumet les réglages par le bouton « Appliquer » avec cette ligne d'en-têtes : la page se recharge sans valider. */
    private function appliquerLigneEnTete(Crawler $crawler, int $ligne): Crawler
    {
        $formulaire = $crawler->filter('form[action$="/importer/reglages"]')->form(['reglages_import[ligneEnTete]' => (string) $ligne]);
        $valeurs = $formulaire->getPhpValues();
        $valeurs['reglages_import']['appliquer'] = '';
        $crawler = $this->client->request($formulaire->getMethod(), $formulaire->getUri(), $valeurs);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private static function dossierDepot(): string
    {
        return (string) static::getContainer()->getParameter('kernel.project_dir').'/var/import/test';
    }
}
