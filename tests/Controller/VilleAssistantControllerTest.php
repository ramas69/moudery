<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\EtapeAssistant;
use App\Entity\Evenement;
use App\Entity\Invitation;
use App\Entity\RoleVille;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Étape Identité de l'assistant de création d'une ville (F-40, US-14), et les droits qui l'entourent (F-07). */
final class VilleAssistantControllerTest extends CasDeTestWeb
{
    private const string BOUTON_ENREGISTRER = 'Enregistrer et continuer';

    private int $moudery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
    }

    public function testUnVisiteurAnonymeEstRenvoyeVersLaConnexion(): void
    {
        $this->client->request('GET', '/associations/moudery/villes/nouvelle');

        self::assertResponseRedirects('http://localhost/connexion', 302);
    }

    public function testUnMembreNePeutPasCreerNiModifierUneVille(): void
    {
        $lyon = $this->creerVille($this->moudery, 'Lyon');
        $this->connecter($this->creerUtilisateur($this->moudery, 'awa@example.org', Role::Membre, $lyon));

        $this->client->request('GET', '/associations/moudery/villes/nouvelle');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant/identite', $lyon));
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeTresorierDUneVilleNeModifieQueLaSienne(): void
    {
        $lyon = $this->creerVille($this->moudery, 'Lyon');
        $marseille = $this->creerVille($this->moudery, 'Marseille');
        $this->connecter($this->creerUtilisateur($this->moudery, 'tresorier@example.org', Role::Tresorier, $lyon));

        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant/identite', $lyon));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant/identite', $marseille));
        self::assertResponseStatusCodeSame(403, 'Le trésorier de Lyon n’a aucun accès à Marseille.');

        $this->client->request('GET', '/associations/moudery/villes/nouvelle');
        self::assertResponseStatusCodeSame(403, 'Seul le bureau central crée une ville.');
    }

    public function testLeBureauCentralDUneAutreAssociationEstRefuse(): void
    {
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.fr', Role::BureauCentral));

        $this->client->request('GET', '/associations/moudery/villes/nouvelle');

        self::assertResponseStatusCodeSame(403);
    }

    public function testLeFormulaireDIdentiteSAffiche(): void
    {
        $this->connecterBureauCentral();
        $this->client->request('GET', '/associations/moudery/villes/nouvelle');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Identité de la ville');
        self::assertSelectorExists('form[data-controller="ville-identite"]');
        self::assertSelectorExists('input[type="text"][name="ville_identite[nom]"][required]');
        self::assertSelectorExists('input[type="email"][name="ville_identite[emailTresorier]"]');
        self::assertSelectorExists('input[type="email"][name="ville_identite[emailPresident]"]');
        self::assertSelectorExists('input[type="email"][name="ville_identite[emailSecretaire]"]');
        self::assertSelectorTextContains('[aria-current="step"]', 'Identité');
        self::assertSelectorTextContains('.etapes__resume', 'Étape 1 sur 3');
        self::assertSelectorTextContains('.assistant__titre', 'Nouvelle ville');
        self::assertSelectorTextContains('.assistant__utilisateur', 'Awa Cissé');
        self::assertSelectorExists('form.deconnexion button', 'La déconnexion est un formulaire POST, pas un lien.');
        self::assertSelectorTextContains('.panneau', 'aucun email ne part');
        self::assertSelectorNotExists('.etapes a', 'Tant que la ville n’existe pas, aucune autre étape n’est accessible.');
    }

    public function testUneAssociationInconnueRepond404(): void
    {
        $this->connecterBureauCentral();
        $this->client->request('GET', '/associations/inconnue/villes/nouvelle');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUneVilleEstCreeeEnBrouillonAvecSesInvitations(): void
    {
        $this->connecterBureauCentral();
        $this->client->request('GET', '/associations/moudery/villes/nouvelle');
        $this->client->submitForm(self::BOUTON_ENREGISTRER, [
            'ville_identite[nom]' => '  Lyon  ',
            'ville_identite[emailTresorier]' => 'Tresorier@Example.org',
            'ville_identite[emailPresident]' => '',
            'ville_identite[emailSecretaire]' => 'secretaire@example.org',
        ]);

        $ville = $this->em()->getRepository(Ville::class)->findOneBy(['nom' => 'Lyon']);
        self::assertNotNull($ville, 'Le nom est enregistré sans les espaces superflus.');
        self::assertResponseRedirects(\sprintf('/associations/moudery/villes/%d/assistant/membres', $ville->getId()), 303);

        self::assertSame(VilleStatut::Brouillon, $ville->getStatut());
        self::assertSame(EtapeAssistant::Membres, $ville->getEtapeAssistant());
        self::assertSame($this->moudery, $ville->getAssociation()->getId());
        self::assertCount(2, $ville->getInvitations());
        self::assertSame('tresorier@example.org', $ville->invitationPour(RoleVille::Tresorier)?->getEmail());
        self::assertNull($ville->invitationPour(RoleVille::President));
        self::assertSame('secretaire@example.org', $ville->invitationPour(RoleVille::Secretaire)?->getEmail());
        foreach ($ville->getInvitations() as $invitation) {
            self::assertFalse($invitation->estEnvoyee(), 'Aucune invitation ne part tant que la ville est en brouillon (F-46).');
            self::assertSame($this->moudery, $invitation->getAssociation()->getId());
        }
        self::assertEmailCount(0);

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alerte--succes', 'enregistrée');
        self::assertSelectorTextContains('[aria-current="step"]', 'Membres');
        self::assertSelectorTextContains('.etapes__item--terminee', 'Identité');
        self::assertSelectorExists('.assistant__pied a[href$="/assistant/identite"]', 'Le pied de page propose l’étape précédente.');
    }

    public function testUnNomDejaUtiliseDansLAssociationEstRefuse(): void
    {
        $this->creerVille($this->moudery, 'Lyon');
        $this->connecterBureauCentral();
        $this->client->request('GET', '/associations/moudery/villes/nouvelle');
        $this->client->submitForm(self::BOUTON_ENREGISTRER, ['ville_identite[nom]' => 'lyon']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.champ--erreur .champ__erreur', 'déjà utilisé');
        self::assertSelectorExists('input[name="ville_identite[nom]"][aria-invalid="true"]');
        self::assertCount(1, $this->em()->getRepository(Ville::class)->findAll());
    }

    public function testLeMemeNomEstAutoriseDansUneAutreAssociation(): void
    {
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->creerVille($bakel, 'Lyon');

        $this->connecterBureauCentral();
        $this->client->request('GET', '/associations/moudery/villes/nouvelle');
        $this->client->submitForm(self::BOUTON_ENREGISTRER, ['ville_identite[nom]' => 'Lyon']);

        self::assertResponseStatusCodeSame(303);
        self::assertCount(2, $this->em()->getRepository(Ville::class)->findBy(['nom' => 'Lyon']));
    }

    public function testUnNomVideOuUnEmailInvalideSontRefuses(): void
    {
        $this->connecterBureauCentral();
        $this->client->request('GET', '/associations/moudery/villes/nouvelle');
        $this->client->submitForm(self::BOUTON_ENREGISTRER, [
            'ville_identite[nom]' => '   ',
            'ville_identite[emailTresorier]' => 'pas-un-email',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.alerte--erreur');
        self::assertSelectorTextContains('#ville_identite_nom_erreur', 'obligatoire');
        self::assertSelectorTextContains('#ville_identite_emailTresorier_erreur', 'pas valide');
        self::assertCount(0, $this->em()->getRepository(Ville::class)->findAll());
    }

    public function testLIdentiteDUneVilleEnBrouillonSeModifie(): void
    {
        $id = $this->creerVille($this->moudery, 'Lyon', [
            RoleVille::Tresorier->value => 'ancien@example.org',
            RoleVille::Secretaire->value => 'secretaire@example.org',
        ], EtapeAssistant::Membres);

        $this->connecterBureauCentral();
        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant/identite', $id));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="ville_identite[nom]"][value="Lyon"]');
        self::assertSelectorExists('input[name="ville_identite[emailTresorier]"][value="ancien@example.org"]');
        self::assertSelectorTextContains('.assistant__titre', "Nouvelle ville\u{a0}: Lyon", 'Espace insécable avant les deux-points, typographie française.');
        self::assertSelectorTextContains('.pastille', 'Brouillon');
        self::assertSelectorTextContains('.panneau', '2 sur 3');
        self::assertSelectorTextContains('.responsable .pastille', 'activation', 'L’invitation existante est signalée comme à envoyer à l’activation.');
        self::assertSelectorExists('.etapes a[href$="/assistant/membres"]', 'Les étapes déjà atteintes sont accessibles.');

        $this->client->submitForm(self::BOUTON_ENREGISTRER, [
            'ville_identite[nom]' => 'Lyon 3e',
            'ville_identite[emailTresorier]' => 'nouveau@example.org',
            'ville_identite[emailPresident]' => 'president@example.org',
            'ville_identite[emailSecretaire]' => '',
        ]);

        self::assertResponseRedirects(\sprintf('/associations/moudery/villes/%d/assistant/membres', $id), 303);

        $ville = $this->em()->find(Ville::class, $id);
        self::assertNotNull($ville);
        self::assertSame('Lyon 3e', $ville->getNom());
        self::assertSame(EtapeAssistant::Membres, $ville->getEtapeAssistant(), 'Revenir sur l’identité ne fait pas reculer la reprise.');
        self::assertCount(2, $ville->getInvitations());
        self::assertSame('nouveau@example.org', $ville->invitationPour(RoleVille::Tresorier)?->getEmail());
        self::assertSame('president@example.org', $ville->invitationPour(RoleVille::President)?->getEmail());
        self::assertNull($ville->invitationPour(RoleVille::Secretaire));
    }

    public function testRenommerUneVilleAvecSonPropreNomEstAccepte(): void
    {
        $id = $this->creerVille($this->moudery, 'Lyon');

        $this->connecterBureauCentral();
        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant/identite', $id));
        $this->client->submitForm(self::BOUTON_ENREGISTRER, ['ville_identite[nom]' => 'LYON']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('LYON', $this->em()->find(Ville::class, $id)?->getNom());
    }

    public function testUneVilleDUneAutreAssociationEstIntrouvable(): void
    {
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $id = $this->creerVille($bakel, 'Lyon');

        $this->connecterBureauCentral();
        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant/identite', $id));

        self::assertResponseStatusCodeSame(404);
    }

    public function testReprendreRedirigeVersLEtapeEnCours(): void
    {
        $id = $this->creerVille($this->moudery, 'Lyon', [], EtapeAssistant::Membres);

        $this->connecterBureauCentral();
        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant', $id));

        self::assertResponseRedirects(\sprintf('/associations/moudery/villes/%d/assistant/membres', $id), 303);

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Membres');
        self::assertSelectorTextContains('[aria-current="step"]', 'Membres');
    }

    public function testLaPageDActivationRecapituleEtActiveLaVille(): void
    {
        $id = $this->creerVille($this->moudery, 'Lyon', [
            RoleVille::Tresorier->value => 'tresorier@example.org',
            RoleVille::President->value => 'central@moudery.fr',
        ], EtapeAssistant::Activation);
        $this->connecterBureauCentral();

        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant/activation', $id));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Activer Lyon');
        self::assertSelectorTextContains('[aria-current="step"]', 'Activation');
        self::assertSelectorTextContains('.etapes__resume', 'Étape 3 sur 3');
        self::assertSelectorTextContains('.recapitulatif', 'tresorier@example.org');
        self::assertSelectorTextContains('.recapitulatif', 'Aucun membre pour l’instant');
        self::assertSelectorExists('form.activation button:not([disabled])');

        $this->client->submitForm('Activer la ville');
        self::assertResponseRedirects('/associations/moudery', 303);
        // Le trésorier, sans compte, reçoit son invitation ; le bureau central, qui a déjà le sien, n'en reçoit pas.
        self::assertQueuedEmailCount(1);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alerte--succes', 'Lyon est active');
        self::assertSelectorTextContains('.alerte--succes', 'Une invitation est partie');

        $ville = $this->em()->find(Ville::class, $id);
        self::assertNotNull($ville);
        self::assertSame(VilleStatut::Active, $ville->getStatut());
        self::assertSame(EtapeAssistant::Activation, $ville->getEtapeAssistant());

        // Le trésorier n'a pas de compte : il reçoit une invitation (F-02) sur sa ville, avec un e-mail.
        self::assertTrue($ville->invitationPour(RoleVille::Tresorier)?->estEnvoyee());
        $invitations = $this->em()->getRepository(Invitation::class)->findBy(['email' => 'tresorier@example.org']);
        self::assertCount(1, $invitations);
        self::assertSame(Role::Tresorier, $invitations[0]->getRole());
        self::assertSame('Lyon', $invitations[0]->getVille()?->getNom());

        // Le bureau central, qui a déjà un compte dans l'association, reçoit le rôle de président sans e-mail.
        $central = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => 'central@moudery.fr']);
        self::assertTrue($central?->aLeRole(Role::President));
        self::assertNotNull($ville->invitationPour(RoleVille::President)?->getAccepteeLe());

        $evenement = $this->em()->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::VilleStatut]);
        self::assertSame('Lyon', $evenement?->getCible(), 'L’activation est consignée.');

        // Une ville active n'a plus d'assistant : reprendre mène au récapitulatif, sans progression ni bouton d'activation.
        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant', $id));
        self::assertResponseRedirects(\sprintf('/associations/moudery/villes/%d/assistant/activation', $id), 303);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form.activation');
        self::assertSelectorNotExists('.etapes');
        self::assertSelectorTextContains('.assistant__titre .pastille', 'Active');
        self::assertSelectorExists(\sprintf('a[href="/associations/moudery/villes/%d/assistant/membres"]', $id), 'Les membres restent accessibles.');

        $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant/identite', $id));
        self::assertResponseRedirects(\sprintf('/associations/moudery/villes/%d/assistant/activation', $id), 302, 'Une ville active : « Paramètres de la ville » mène à sa fiche récapitulative, jamais à une erreur.');
        $this->client->request('POST', \sprintf('/associations/moudery/villes/%d/assistant/identite', $id), ['ville_identite' => ['nom' => 'Autre nom']]);
        self::assertResponseRedirects(\sprintf('/associations/moudery/villes/%d/assistant/activation', $id), 302, 'Et son nom ne se modifie pas par l’assistant.');
    }

    public function testSansTresorierLaVilleNeSActivePas(): void
    {
        $id = $this->creerVille($this->moudery, 'Lyon', [RoleVille::President->value => 'president@example.org'], EtapeAssistant::Activation);
        $this->connecterBureauCentral();

        $crawler = $this->client->request('GET', \sprintf('/associations/moudery/villes/%d/assistant/activation', $id));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.activation button[disabled]');
        self::assertSelectorTextContains('.alerte--attention', 'trésorier');
        self::assertSelectorTextContains('.recapitulatif', 'Non renseigné');

        $jeton = $crawler->filter('form.activation input[name="_token"]')->attr('value');
        $this->client->request('POST', \sprintf('/associations/moudery/villes/%d/assistant/activer', $id), ['_token' => $jeton]);
        self::assertResponseRedirects(\sprintf('/associations/moudery/villes/%d/assistant/activation', $id), 303);
        self::assertQueuedEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'trésorier');

        self::assertSame(VilleStatut::Brouillon, $this->em()->find(Ville::class, $id)?->getStatut());
    }

    private function connecterBureauCentral(): void
    {
        $this->connecter($this->creerUtilisateur($this->moudery, 'central@moudery.fr', Role::BureauCentral));
    }
}
