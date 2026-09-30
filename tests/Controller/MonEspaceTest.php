<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\MoyenPaiement;
use App\Entity\Paiement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Espace membre (F-26, F-27, F-04) : échéances, historique et reçus, ma ville anonymisée, profil ; cloisonné par membre. */
final class MonEspaceTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $compteHawa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', [], EtapeAssistant::Activation);
        $em = $this->em();
        $lyon = $em->find(Ville::class, $this->lyon);
        \assert($lyon instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        $hawa = new Membre($lyon, 'Hawa', 'Soumaré', 'hawa@example.org');
        $moussa = new Membre($lyon, 'Moussa', 'Camara', 'moussa@example.org');
        $em->persist($hawa);
        $em->persist($moussa);
        $em->flush();
        // Le compte de Hawa, sans lien explicite : la fiche se rattache par l'adresse à la première visite.
        $this->compteHawa = $this->creerUtilisateur($this->moudery, 'hawa@example.org', Role::Membre, $this->lyon);
    }

    public function testLeMembreArriveSurSesEcheances(): void
    {
        $this->connecter($this->compteHawa);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/mon-espace');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mes échéances');
        self::assertSelectorTextContains('.espace-membre__surtitre', 'Bonjour Hawa · Lyon');
        self::assertSelectorTextContains('.espace-membre__vide', 'Vous êtes à jour');
        self::assertSelectorCount(5, '.espace-membre__onglet');

        $fiche = $this->em()->getRepository(Membre::class)->findOneBy(['email' => 'hawa@example.org']);
        self::assertSame($this->compteHawa, $fiche?->getCompte()?->getId(), 'La fiche est rattachée au compte.');
    }

    public function testHistoriqueRecuEtCloisonnement(): void
    {
        [$paiementHawa, $paiementMoussa] = $this->deuxPaiements();
        $this->connecter($this->compteHawa);

        $this->client->request('GET', '/mon-espace/historique');
        self::assertSelectorTextContains('.espace-membre__liste', '25,00');
        self::assertSelectorExists(\sprintf('a[href="/mon-espace/recus/%d"]', $paiementHawa));
        $this->client->request('GET', '/mon-espace/recus/'.$paiementHawa);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/mon-espace/historique"]', 'Le reçu revient à l’historique du membre.');
        $this->client->request('GET', '/mon-espace/recus/'.$paiementMoussa);
        self::assertResponseStatusCodeSame(404, 'Jamais le reçu d’un autre membre.');

        // L'année des paiements, le total et l'attestation annuelle.
        $this->client->request('GET', '/mon-espace/historique');
        self::assertSelectorTextContains('.espace-membre__total', '25,00');
        $this->client->request('GET', '/mon-espace/attestation/'.date('Y', strtotime('-1 day')));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Attestation de paiements');
        self::assertSelectorTextContains('.recu__table', '25,00');
        self::assertSelectorTextNotContains('.recu', 'Moussa');
        $this->client->request('GET', '/mon-espace/attestation/1999');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/mon-espace/ma-ville/depenses');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Les dépenses de ma ville');

        // Ma ville : les entrées sont anonymes.
        $this->client->request('GET', '/mon-espace/ma-ville');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Où va l’argent de ma ville');
        self::assertSelectorTextContains('.espace-membre__contenu', 'un membre');
        self::assertSelectorTextNotContains('.espace-membre__contenu', 'Moussa');
        self::assertSelectorTextNotContains('.espace-membre__contenu', 'Camara');

        // L'espace de l'association reste fermé à un membre.
        $this->client->request('GET', '/associations/moudery');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeProfilSEnregistre(): void
    {
        $this->connecter($this->compteHawa);
        $crawler = $this->client->request('GET', '/mon-espace/profil');
        self::assertSelectorTextContains('.espace-membre__identite', 'Hawa Soumaré');
        $formulaire = $crawler->filter('form.espace-membre__formulaire')->form();
        $formulaire['profil_membre[telephone]'] = '06 12 34 56 78';
        $formulaire['profil_membre[consentEmail]']->untick();
        $this->client->submit($formulaire);
        self::assertResponseRedirects('/mon-espace/profil', 303);
        $fiche = $this->em()->getRepository(Membre::class)->findOneBy(['email' => 'hawa@example.org']);
        self::assertSame('0612345678', $fiche?->getTelephone());
        self::assertFalse($fiche?->aConsentiEmail());

        // Les paramètres du compte d'un membre s'ouvrent dans la coquille simple, pas dans l'espace de l'association.
        $this->client->request('GET', '/parametres');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.console__nav');
    }

    public function testUnCompteSansFicheResteSurLAccueil(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'inconnu@example.org', Role::Membre, $this->lyon));
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/mon-espace');
        self::assertResponseRedirects('/');
    }

    /** @return array{0: int, 1: int} */
    private function deuxPaiements(): array
    {
        $em = $this->em();
        $hawa = $em->getRepository(Membre::class)->findOneBy(['email' => 'hawa@example.org']);
        $moussa = $em->getRepository(Membre::class)->findOneBy(['email' => 'moussa@example.org']);
        \assert($hawa instanceof Membre && $moussa instanceof Membre);
        $hier = new \DateTimeImmutable('-1 day');
        $p1 = new Paiement($hawa, 2500, MoyenPaiement::Especes, $hier, null, null, null, $hier);
        $p2 = new Paiement($moussa, 1000, MoyenPaiement::Especes, $hier, null, null, null, $hier);
        $em->persist($p1);
        $em->persist($p2);
        $em->flush();

        return [(int) $p1->getId(), (int) $p2->getId()];
    }
}
