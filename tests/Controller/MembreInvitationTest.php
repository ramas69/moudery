<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\EtapeAssistant;
use App\Entity\Invitation;
use App\Entity\Membre;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Un membre rejoint son espace par un lien de sa ville (pas d'inscription libre) : invitation, activation, fiche reliée. */
final class MembreInvitationTest extends CasDeTestWeb
{
    public function testLaTresoriereInviteUnMembreQuiActiveSonEspace(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyonId = $this->creerVille($moudery, 'Lyon', [], EtapeAssistant::Activation);
        $em = $this->em();
        $lyon = $em->find(Ville::class, $lyonId);
        \assert($lyon instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        $hawa = new Membre($lyon, 'Hawa', 'Soumaré', 'hawa@example.org');
        $sansAdresse = new Membre($lyon, 'Awa', 'Cissé');
        $em->persist($hawa);
        $em->persist($sansAdresse);
        $em->flush();
        $hawaId = (int) $hawa->getId();

        $this->connecter($this->creerUtilisateur($moudery, 'tresoriere@example.org', Role::Tresorier, $lyonId));
        $crawler = $this->client->request('GET', '/associations/moudery/membres/'.$hawaId);
        self::assertSelectorExists('form[action$="/inviter"]', 'Inviter à son espace, dans le menu de la fiche.');
        $this->client->submit($crawler->filter('form[action$="/inviter"]')->form());
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertSame(1, preg_match('#/invitation/([a-f0-9]{64})#', (string) $email->getTextBody(), $lien));
        self::assertResponseRedirects('/associations/moudery/membres/'.$hawaId, 303);
        $this->client->request('GET', '/associations/moudery/membres/'.$sansAdresse->getId());
        self::assertSelectorNotExists('form[action$="/inviter"]', 'Sans adresse, pas d’invitation.');

        // L'action groupée de la page Membres exige son jeton.
        $this->client->request('POST', '/associations/moudery/membres/inviter', ['_token' => 'x', 'membres' => [$hawaId]]);
        self::assertResponseStatusCodeSame(403, 'Jeton obligatoire.');
        // Hawa ouvre le lien : texte d'un membre, puis son espace, avec sa fiche (pas une seconde).
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/invitation/'.$lien[1]);
        self::assertSelectorTextContains('.invitation__intro', 'rejoindre la caisse de Lyon');
        $this->client->submitForm('Activer mon compte', [
            'activation_compte[prenom]' => 'Hawa',
            'activation_compte[nom]' => 'Soumaré',
            'activation_compte[motDePasse]' => 'Caisse-de-Lyon-2026!',
        ]);
        self::assertResponseRedirects('/', 303);
        $this->client->followRedirect();
        self::assertResponseRedirects('/mon-espace');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.espace-membre__surtitre', 'Bonjour Hawa · Lyon');
        $fiches = $this->em()->getRepository(Membre::class)->findBy(['email' => 'hawa@example.org']);
        self::assertCount(1, $fiches);
        self::assertSame('hawa@example.org', $fiches[0]->getCompte()?->getEmail());
        self::assertTrue($fiches[0]->getCompte()?->aLeRole(Role::Membre));

        // La route publique d'inscription libre n'existe plus.
        $this->client->request('GET', '/associations/moudery/inscription');
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->em()->getRepository(Invitation::class)->findAll());
    }
}
