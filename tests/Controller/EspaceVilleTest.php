<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\Depense;
use App\Entity\DepenseStatut;
use App\Entity\Echeance;
use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\ModeMontant;
use App\Entity\Paiement;
use App\Entity\Reversement;
use App\Entity\ReversementStatut;
use App\Entity\TypeContribution;
use App\Entity\UniteContribution;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/**
 * L'espace de la ville (29 septembre 2026) : trésorier, président et secrétaire entrent dans l'espace de l'association
 * avec un périmètre figé sur leur ville, chacun avec ses droits (cahier des charges, section 2) ; le trésorier de Lyon
 * n'a aucun accès à Évry.
 */
final class EspaceVilleTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $evry;
    private int $tresorier;
    private int $president;
    private int $secretaire;
    private int $central;
    private int $mamadou;
    private int $oumar;
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->annee = (int) date('Y');
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', [], EtapeAssistant::Activation);
        $this->evry = $this->creerVille($this->moudery, 'Évry', [], EtapeAssistant::Activation);
        $this->tresorier = $this->creerUtilisateur($this->moudery, 'tresorier@lyon.fr', Role::Tresorier, $this->lyon);
        $this->president = $this->creerUtilisateur($this->moudery, 'president@lyon.fr', Role::President, $this->lyon);
        $this->secretaire = $this->creerUtilisateur($this->moudery, 'secretaire@lyon.fr', Role::Secretaire, $this->lyon);
        $this->central = $this->creerUtilisateur($this->moudery, 'central@moudery.fr', Role::BureauCentral);

        $em = $this->em();
        $association = $em->find(Association::class, $this->moudery);
        $lyon = $em->find(Ville::class, $this->lyon);
        $evry = $em->find(Ville::class, $this->evry);
        \assert($association instanceof Association && $lyon instanceof Ville && $evry instanceof Ville);
        $association->definirParametres(1, 30, $association->getCalendrierRelances());
        $lyon->changerStatut(VilleStatut::Active);
        $evry->changerStatut(VilleStatut::Active);
        $em->persist(new TypeContribution($association, 'cotisation', 'Cotisation', UniteContribution::Personne, ModeMontant::Fixe, 1000, null));
        $mamadou = new Membre($lyon, 'Mamadou', 'Diaby', 'mamadou@example.org');
        $oumar = new Membre($evry, 'Oumar', 'Sy', 'oumar@example.org');
        $em->persist($mamadou);
        $em->persist(new Membre($lyon, 'Fanta', 'Traoré'));
        $em->persist($oumar);
        $em->flush();
        $this->mamadou = (int) $mamadou->getId();
        $this->oumar = (int) $oumar->getId();
    }

    public function testLeTresorierArriveSurSaVilleEtNeVoitQuElle(): void
    {
        $this->connecter($this->tresorier);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/associations/moudery?ville='.$this->lyon);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.console__perimetre--fixe', 'Une seule ville : le périmètre est figé, sans menu.');
        self::assertSelectorTextContains('.console__perimetre-nom', 'Lyon');
        self::assertSelectorNotExists('.console__nav a[href$="/villes"]');
        self::assertSelectorExists('.console__nav a[href$="/rapport"]', 'Le rapport d’AG de sa ville (29 septembre 2026).');
        self::assertSelectorNotExists('section[aria-labelledby="a-traiter"]');
        self::assertSelectorNotExists('section[aria-labelledby="journal-titre"]');
        self::assertSelectorExists('.kpis');

        // Demander Évry ou toute l'association ne change rien : Lyon reste le périmètre.
        $this->client->request('GET', '/associations/moudery?ville='.$this->evry);
        self::assertSelectorTextContains('.console__perimetre-nom', 'Lyon');
        $crawler = $this->client->request('GET', '/associations/moudery/membres?ville=association&annee=toutes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Mamadou Diaby');
        self::assertStringNotContainsString('Oumar Sy', $crawler->filter('tbody')->text());

        // La fiche d'un membre d'Évry est refusée ; celle d'un membre de Lyon s'ouvre et se modifie.
        $this->client->request('GET', '/associations/moudery/membres/'.$this->oumar);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/associations/moudery/membres/'.$this->mamadou.'/modifier');
        self::assertResponseIsSuccessful();

        // Les pages réservées au bureau central restent fermées.
        foreach (['/associations/moudery/villes', '/associations/moudery/parametres', '/associations/moudery/parametres/contributions', '/associations/moudery/parametres/responsables', '/associations/moudery/reversements/nouveau'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        // Le rapport d'AG s'ouvre, mais seulement pour sa ville (29 septembre 2026).
        $this->client->request('GET', '/associations/moudery/rapport');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__sous-titre', 'caisse de Lyon');
    }

    public function testLeTresorierOuvreLesCotisationsSaisitUnPaiementEtDeclareUnReversement(): void
    {
        $this->connecter($this->tresorier);
        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/cotisations/ouvrir?annee=%d', $this->annee));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Lyon');
        $formulaire = $crawler->filter('form[name="cotisation"]')->form();
        $formulaire['cotisation[montantMensuel]'] = '10,00';
        $this->client->submit($formulaire);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.cotisation-panneau', '10,00');
        self::assertSelectorExists('.cotisation-panneau a[href*="/paiements/nouveau"]');

        $crawler = $this->client->request('GET', '/associations/moudery/paiements/nouveau?membre='.$this->mamadou);
        self::assertResponseIsSuccessful();
        $echeances = $this->em()->getRepository(Echeance::class)->findBy(['membre' => $this->mamadou], ['mois' => 'ASC']);
        $formulaire = $crawler->filter('form[name="paiement"]')->form();
        foreach ($formulaire['paiement[echeances]'] as $case) {
            \in_array($case->availableOptionValues()[0], [(string) $echeances[0]->getId(), (string) $echeances[1]->getId()], true) ? $case->tick() : $case->untick();
        }
        $formulaire['paiement[moyen]'] = 'especes';
        $formulaire['paiement[recuLe]'] = date('Y-m-d');
        $this->client->submit($formulaire);
        self::assertResponseRedirects('/associations/moudery/paiements', 303);
        self::assertQueuedEmailCount(1, null, 'Le reçu part.');
        $paiement = $this->em()->getRepository(Paiement::class)->findOneBy([]);
        self::assertSame(2000, $paiement?->getMontant());
        self::assertSame($this->tresorier, $paiement?->getEnregistrePar()?->getId());

        // Un membre d'Évry : hors de portée.
        $this->client->request('GET', '/associations/moudery/paiements/nouveau?membre='.$this->oumar);
        self::assertResponseStatusCodeSame(404);

        // Déclarer le reversement : 30 % de 20 € = 6 €, proposé d'office.
        $crawler = $this->client->request('GET', '/associations/moudery/reversements/declarer');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Déclarer un reversement effectué');
        $formulaire = $crawler->filter('form[name="reversement"]')->form();
        self::assertSame('6.00', $formulaire['reversement[montant]']->getValue());
        $formulaire['reversement[reference]'] = 'VIR LYON 09';
        $this->client->submit($formulaire);
        self::assertResponseRedirects();
        $reversement = $this->em()->getRepository(Reversement::class)->findOneBy([]);
        self::assertSame(ReversementStatut::Declare, $reversement?->getStatut());
        self::assertSame(600, $reversement?->getMontant());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.reversements__a-confirmer', 'En attente du central');
        self::assertSelectorNotExists('.reversements__confirmation');

        // Le bureau central confirme.
        $this->connecter($this->central);
        $crawler = $this->client->request('GET', '/associations/moudery/reversements?ville=association');
        self::assertSelectorTextContains('.reversements__a-confirmer', 'déclaré par Awa Cissé');
        $this->client->submit($crawler->filter('.reversements__a-confirmer form')->form());
        self::assertResponseRedirects();
        self::assertSame(ReversementStatut::Confirme, $this->em()->getRepository(Reversement::class)->findOneBy([])?->getStatut());
    }

    public function testLePresidentValideLesDepensesDeSaVilleQueLeTresorierSaisitEtPaie(): void
    {
        $this->connecter($this->tresorier);
        $crawler = $this->client->request('GET', '/associations/moudery/depenses/nouvelle');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__sous-titre', 'Caisse de Lyon');
        self::assertSelectorTextContains('.depense-circuit', 'Awa Cissé', 'Le président de Lyon est le valideur.');
        $formulaire = $crawler->filter('button[value="soumettre"]')->form();
        $formulaire['depense[libelle]'] = 'Location de la salle';
        $formulaire['depense[montant]'] = '90,00';
        $formulaire['depense[date]'] = date('Y-m-d');
        $formulaire['depense[categorie]'] = 'fetes';
        $formulaire['depense[beneficiaire]'] = 'Mairie de Lyon';
        $formulaire['depense[justificatif]']->upload($this->pdf());
        $this->client->submit($formulaire);
        self::assertQueuedEmailCount(1, null, 'Le président est prévenu.');
        $depense = $this->em()->getRepository(Depense::class)->findOneBy([]);
        \assert($depense instanceof Depense);
        self::assertSame($this->lyon, $depense->getVille()?->getId());
        self::assertSame(DepenseStatut::Soumise, $depense->getStatut());
        $id = (int) $depense->getId();
        self::assertResponseRedirects('/associations/moudery/depenses/'.$id, 303);

        // Le trésorier ne valide pas ; le président ne saisit pas.
        $this->client->request('POST', \sprintf('/associations/moudery/depenses/%d/valider', $id), ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->connecter($this->president);
        $this->client->request('GET', '/associations/moudery/depenses/nouvelle');
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/associations/moudery/depenses/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/valider"]');
        $this->client->submit($crawler->filter('form[action$="/valider"]')->form());
        self::assertResponseRedirects();
        self::assertSame(DepenseStatut::Validee, $this->em()->find(Depense::class, $id)?->getStatut());
        self::assertQueuedEmailCount(1, null, 'Le trésorier est prévenu de la validation.');

        // Payée par le trésorier ; le président ne marque pas payée.
        $this->client->request('POST', \sprintf('/associations/moudery/depenses/%d/payer', $id), ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->connecter($this->tresorier);
        $crawler = $this->client->request('GET', '/associations/moudery/depenses/'.$id);
        $this->client->submit($crawler->filter('form[action$="/payer"]')->form());
        self::assertSame(DepenseStatut::Payee, $this->em()->find(Depense::class, $id)?->getStatut());

        // Le bureau central voit la dépense de Lyon mais ne la décide pas (point ouvert tranché à non).
        $this->connecter($this->central);
        $this->client->request('GET', '/associations/moudery/depenses?ville=association');
        self::assertSelectorTextContains('.tableau--depenses', 'Location de la salle');
    }

    public function testLePresidentLanceUnAppelPourSaVilleSeulement(): void
    {
        $this->connecter($this->president);
        $crawler = $this->client->request('GET', '/associations/moudery/appels/nouveau');
        self::assertResponseIsSuccessful();
        $options = $crawler->filter('select[name="appel[perimetre]"] option, input[name="appel[perimetre]"]');
        self::assertCount(1, $options, 'Une seule cible : Lyon.');
        self::assertStringContainsString('Lyon', $options->first()->text() ?: (string) $options->first()->attr('value'));

        $this->connecter($this->tresorier);
        $this->client->request('GET', '/associations/moudery/appels/nouveau');
        self::assertResponseStatusCodeSame(403, 'Le trésorier ne lance pas d’appel.');
        $this->client->request('GET', '/associations/moudery/appels');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href$="/appels/nouveau"]');
    }

    public function testLeSecretaireGereLesMembresMaisPasLArgent(): void
    {
        $this->connecter($this->secretaire);
        $this->client->request('GET', '/associations/moudery/membres/'.$this->mamadou.'/modifier');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant/membres', $this->lyon));
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/associations/moudery/paiements/nouveau');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', \sprintf('/associations/moudery/cotisations/ouvrir?annee=%d', $this->annee));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/associations/moudery/impayes');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button[form="impayes-relancer"]');
        $this->client->request('GET', '/associations/moudery/reversements/declarer');
        self::assertResponseStatusCodeSame(403);
    }

    private function pdf(): string
    {
        $chemin = sys_get_temp_dir().'/justificatif-'.bin2hex(random_bytes(4)).'.pdf';
        file_put_contents($chemin, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return $chemin;
    }
}
