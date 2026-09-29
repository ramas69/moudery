<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\Invitation;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Security\Role;

/** Activation d'un compte invité, à l'identique de la maquette « Activation de compte » : textes, téléphone, accord e-mail. */
final class InvitationActivationTest extends CasDeTestWeb
{
    public function testUneTresoriereActiveSonCompteDeVille(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyonId = $this->creerVille($moudery, 'Lyon', [], EtapeAssistant::Activation);
        $centralId = $this->creerUtilisateur($moudery, 'mamadou@example.org', Role::BureauCentral);

        $em = $this->em();
        $association = $em->find(Association::class, $moudery);
        $lyon = $em->find(Ville::class, $lyonId);
        $central = $em->find(Utilisateur::class, $centralId);
        \assert($association instanceof Association && $lyon instanceof Ville && $central instanceof Utilisateur);
        $jeton = bin2hex(random_bytes(32));
        $em->persist(new Invitation($association, Role::Tresorier, 'fatoumata.sylla@example.org', hash('sha256', $jeton), new \DateTimeImmutable(), $central, $lyon));
        $em->flush();

        $this->client->request('GET', '/invitation/'.$jeton);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.invitation__surtitre', 'Invitation du bureau central');
        self::assertSelectorTextContains('.invitation__intro', 'vous invite à tenir la caisse de Lyon');
        self::assertSelectorTextContains('.fiche__avatar', 'FS');
        self::assertSelectorTextContains('.fiche', 'Lyon · aucun membre');
        self::assertSelectorTextContains('.fiche', ', bureau central');
        self::assertSelectorTextContains('.fiche', 'une seule fois');
        self::assertSelectorExists('#activation_compte_telephone');
        self::assertSelectorExists('.robustesse');
        self::assertSelectorTextContains('.invitation__consentement', 'inscriptions à valider, dépenses, relances, reçus');
        self::assertSelectorExists('.invitation__erreur-lien a[href^="mailto:mamadou@example.org"]');

        $this->client->submitForm('Activer mon compte et ouvrir la caisse de Lyon', [
            'activation_compte[prenom]' => 'Fatoumata',
            'activation_compte[nom]' => 'Sylla',
            'activation_compte[telephone]' => '06 58 20 14 66',
            'activation_compte[motDePasse]' => 'Caisse-de-Lyon-2026!',
        ]);
        self::assertResponseRedirects('/', 303);

        $compte = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => 'fatoumata.sylla@example.org']);
        self::assertInstanceOf(Utilisateur::class, $compte);
        self::assertSame('0658201466', $compte->getTelephone());
        self::assertTrue($compte->aConsentiEmail());
        self::assertTrue($compte->aLeRole(Role::Tresorier));
    }
}
