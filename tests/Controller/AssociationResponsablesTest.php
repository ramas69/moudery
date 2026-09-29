<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Compte\Invitations;
use App\Entity\Affectation;
use App\Entity\EtapeAssistant;
use App\Entity\Evenement;
use App\Entity\Invitation;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** « Paramètres › Responsables et rôles » (F-02, F-07) : affectations, invitations, responsables à envoyer, filtres, actions, droits. */
final class AssociationResponsablesTest extends CasDeTestWeb
{
    private int $moudery;
    private int $lyon;
    private int $evry;
    private int $central;
    private int $tresorier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->lyon = $this->creerVille($this->moudery, 'Lyon', ['tresorier' => 'awa@example.org'], EtapeAssistant::Activation);
        $this->evry = $this->creerVille($this->moudery, 'Évry', ['tresorier' => 'seydou@example.org', 'secretaire' => 'fanta@example.org'], EtapeAssistant::Membres);
        $this->central = $this->creerUtilisateur($this->moudery, 'central@example.org', Role::BureauCentral);
        $this->tresorier = $this->creerUtilisateur($this->moudery, 'awa@example.org', Role::Tresorier, $this->lyon);

        $em = $this->em();
        $lyon = $em->find(Ville::class, $this->lyon);
        \assert($lyon instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        // Awa cumule : trésorière et membre de Lyon.
        $awa = $em->find(Utilisateur::class, $this->tresorier);
        \assert($awa instanceof Utilisateur);
        $awa->affecter(Role::Membre, $lyon);
        $em->flush();

        // Une invitation de président en cours pour Lyon.
        $invitations = static::getContainer()->get(Invitations::class);
        \assert($invitations instanceof Invitations);
        $association = $em->find(\App\Entity\Association::class, $this->moudery);
        \assert(null !== $association);
        $invitations->inviter($association, Role::President, 'ousmane@example.org', $em->find(Utilisateur::class, $this->central), $lyon);
    }

    public function testLeBureauCentralVoitAffectationsInvitationsEtResponsablesAEnvoyer(): void
    {
        $this->connecter($this->central);

        $crawler = $this->client->request('GET', '/associations/moudery/parametres/responsables');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Paramètres');
        self::assertSelectorTextContains('.onglets-parametres .onglet-parametres[aria-current="page"]', 'Responsables et rôles');
        self::assertSelectorTextContains('.onglets .onglet[aria-current="page"]', 'Responsables');
        self::assertSelectorTextContains('.onglets .onglet[aria-current="page"] .compteur', '6', 'Bureau central, trésorière, membre, président invité, et deux responsables d’Évry à envoyer.');
        self::assertSelectorTextContains('.responsables__compte', '6 affectations · 5 personnes');
        self::assertSelectorExists('.console__actions a[href="/associations/moudery/parametres/responsables/inviter"]');

        $lignes = $crawler->filter('tbody tr');
        self::assertCount(6, $lignes);
        self::assertStringContainsString('Association', $lignes->eq(0)->text(), 'L’association d’abord.');
        self::assertStringContainsString('Toutes les villes', $lignes->eq(0)->text());
        self::assertStringContainsString('Bureau central', $lignes->eq(0)->text());
        self::assertStringContainsString('Évry', $lignes->eq(1)->text(), 'Puis les villes par nom : Évry avant Lyon.');
        self::assertStringContainsString('À envoyer à l’activation', $lignes->eq(1)->text());
        self::assertStringContainsString('Ville en brouillon', $lignes->eq(1)->text());
        self::assertStringContainsString('Trésorier', $lignes->eq(3)->text(), 'À Lyon, la trésorière avant le président invité.');
        self::assertStringContainsString('Cumule deux rôles', $lignes->eq(3)->text());
        self::assertStringContainsString('Retirer le rôle', $lignes->eq(3)->text());
        self::assertStringContainsString('Invitation envoyée', $lignes->eq(4)->text());
        self::assertStringContainsString('expire le', $lignes->eq(4)->text());
        self::assertStringContainsString('Renvoyer l’invitation', $lignes->eq(4)->text());
        self::assertStringContainsString('Membre', $lignes->eq(5)->text());
        self::assertStringNotContainsString('Retirer le rôle', $lignes->eq(0)->text(), 'Le bureau central ne retire pas son propre rôle.');

        self::assertSelectorTextContains('.responsables__aside', 'Ce que chaque rôle peut faire');
        self::assertSelectorTextContains('.responsables__aside', 'Membres, foyers, paiements manuels');
        self::assertSelectorTextContains('.responsables__aside .journal-roles', 'a invité ousmane@example.org comme Président ou présidente de Lyon.');
    }

    public function testLesFiltresEtLaRecherche(): void
    {
        $this->connecter($this->central);

        $crawler = $this->client->request('GET', '/associations/moudery/parametres/responsables?perimetre='.$this->lyon);
        self::assertCount(3, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('.menu--selecteur summary', 'Périmètre : Lyon');

        $crawler = $this->client->request('GET', '/associations/moudery/parametres/responsables?perimetre=association');
        self::assertCount(1, $crawler->filter('tbody tr'));

        $crawler = $this->client->request('GET', '/associations/moudery/parametres/responsables?role=tresorier');
        self::assertCount(2, $crawler->filter('tbody tr'), 'La trésorière de Lyon et celui d’Évry à envoyer.');

        $crawler = $this->client->request('GET', '/associations/moudery/parametres/responsables?q=ousmane');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('.responsables__compte', '1 affectation · 1 personne');

        $this->client->request('GET', '/associations/moudery/parametres/responsables?q=personne-inconnue');
        self::assertSelectorTextContains('.section__vide', 'Personne ne correspond');
    }

    public function testLesOngletsRolesEtJournal(): void
    {
        $this->connecter($this->central);

        $this->client->request('GET', '/associations/moudery/parametres/responsables?onglet=roles');
        self::assertSelectorTextContains('.onglet[aria-current="page"]', 'Rôles et droits');
        self::assertSelectorTextContains('.responsables__principal .roles', 'Bureau central');
        self::assertSelectorTextContains('.responsables__principal .roles', 'Créer une ville');
        self::assertSelectorTextContains('.responsables__principal .roles', 'Aucun droit de gestion dans l’application aujourd’hui');

        $this->client->request('GET', '/associations/moudery/parametres/responsables?onglet=journal');
        self::assertSelectorTextContains('.onglet[aria-current="page"]', 'Journal');
        self::assertSelectorTextContains('.responsables__principal .journal-roles', 'a invité ousmane@example.org');
        self::assertSelectorNotExists('.responsables__aside .journal-roles', 'Le journal complet remplace l’aperçu de la colonne de droite.');
    }

    public function testInviterUnResponsableDeVille(): void
    {
        $this->connecter($this->central);

        $crawler = $this->client->request('GET', '/associations/moudery/parametres/responsables/inviter');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Inviter un responsable');
        self::assertCount(3, $crawler->filter('input[name="invitation_responsable[role]"]'), 'Trésorier, président, secrétaire : le bureau central n’attribue pas son propre rôle.');
        self::assertStringContainsString('Lyon', $crawler->filter('select#invitation_responsable_ville')->text());
        self::assertStringNotContainsString('Évry', $crawler->filter('select#invitation_responsable_ville')->text(), 'Un brouillon reçoit ses responsables dans l’assistant.');

        // Sans ville pour un rôle de ville : refusé.
        $formulaire = $crawler->filter('form[name="invitation_responsable"]')->form(['invitation_responsable[email]' => 'kadiatou@example.org']);
        $formulaire['invitation_responsable[role]']->select('secretaire');
        $this->client->submit($formulaire);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#invitation_responsable_ville_erreur', 'Choisissez la ville pour ce rôle.');

        // Avec Lyon : l'invitation part.
        $formulaire = $this->client->getCrawler()->filter('form[name="invitation_responsable"]')->form();
        $formulaire['invitation_responsable[ville]']->select((string) $this->lyon);
        $this->client->submit($formulaire);
        self::assertResponseRedirects('/associations/moudery/parametres/responsables', 303);
        self::assertQueuedEmailCount(1);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'L’invitation est envoyée à kadiatou@example.org.');
        self::assertSelectorTextContains('tbody', 'kadiatou@example.org');

        $em = $this->em();
        $invitation = $em->getRepository(Invitation::class)->findOneBy(['email' => 'kadiatou@example.org']);
        self::assertInstanceOf(Invitation::class, $invitation);
        self::assertSame(Role::Secretaire, $invitation->getRole());
        self::assertSame($this->lyon, $invitation->getVille()?->getId());
        self::assertSame('central@example.org', $invitation->getInviteePar()?->getEmail());

        // La même invitation deux fois : refusée, renvoyez-la plutôt.
        $crawler = $this->client->request('GET', '/associations/moudery/parametres/responsables/inviter');
        $formulaire = $crawler->filter('form[name="invitation_responsable"]')->form(['invitation_responsable[email]' => 'kadiatou@example.org']);
        $formulaire['invitation_responsable[role]']->select('secretaire');
        $formulaire['invitation_responsable[ville]']->select((string) $this->lyon);
        $this->client->submit($formulaire);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#invitation_responsable_email_erreur', 'Une invitation pour ce rôle est déjà en cours');
    }

    public function testRetirerUnRoleEtRenvoyerUneInvitation(): void
    {
        $this->connecter($this->central);
        $em = $this->em();
        $awa = $em->find(Utilisateur::class, $this->tresorier);
        \assert($awa instanceof Utilisateur);
        $membre = $awa->affectationPour(Role::Membre, $em->find(Ville::class, $this->lyon));
        \assert($membre instanceof Affectation);
        $invitation = $em->getRepository(Invitation::class)->findOneBy(['email' => 'ousmane@example.org']);
        \assert($invitation instanceof Invitation);
        $ancienneEcheance = $invitation->getExpireLe();

        $crawler = $this->client->request('GET', '/associations/moudery/parametres/responsables');
        $this->client->submit($crawler->filter(\sprintf('form[action$="/affectations/%d/retirer"]', $membre->getId()))->form());
        self::assertResponseRedirects('/associations/moudery/parametres/responsables', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Le rôle Membre est retiré à Awa Cissé.');
        $em = $this->em();
        self::assertNull($em->find(Affectation::class, $membre->getId()));
        self::assertInstanceOf(Evenement::class, $em->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::RoleRetire]));

        $crawler = $this->client->request('GET', '/associations/moudery/parametres/responsables');
        $this->client->submit($crawler->filter(\sprintf('form[action$="/invitations/%d/renvoyer"]', $invitation->getId()))->form());
        self::assertResponseRedirects('/associations/moudery/parametres/responsables', 303);
        self::assertQueuedEmailCount(1);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'renvoyée à ousmane@example.org');
        $renvoyee = $this->em()->find(Invitation::class, $invitation->getId());
        self::assertGreaterThanOrEqual($ancienneEcheance, $renvoyee?->getExpireLe());

        // Sans jeton : refusé.
        $this->client->request('POST', \sprintf('/associations/moudery/parametres/responsables/invitations/%d/renvoyer', $invitation->getId()), ['_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSeulLeBureauCentralDeLAssociationOuvreLaPage(): void
    {
        $this->connecter($this->tresorier);
        $this->client->request('GET', '/associations/moudery/parametres/responsables');
        self::assertResponseStatusCodeSame(403);

        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.example.org', Role::BureauCentral));
        $this->client->request('GET', '/associations/moudery/parametres/responsables');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/associations/moudery/parametres/responsables/inviter');
        self::assertResponseStatusCodeSame(403);
    }

    public function testParametresEnDeuxOngletsAvecInviter(): void
    {
        $this->connecter($this->central);
        $this->client->request('GET', '/parametres');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.onglet-parametres[aria-current="page"]', 'Mon compte');
        self::assertSelectorExists('.onglets-parametres a[href="/associations/moudery/parametres/responsables"]', 'Deux onglets : Mon compte, Responsables et rôles.');
        self::assertSelectorExists('.console__actions a[href="/associations/moudery/parametres/responsables/inviter"]', 'Inviter un responsable depuis Paramètres.');

        $this->connecter($this->tresorier);
        $this->client->request('GET', '/parametres');
        self::assertSelectorNotExists('.onglets-parametres', 'Un trésorier ne pilote pas l’association.');
        self::assertSelectorNotExists('.console__actions a[href$="/inviter"]');
    }
}
