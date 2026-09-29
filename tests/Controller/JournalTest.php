<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\Evenement;
use App\Entity\PaiementAbonnement;
use App\Entity\TypeEvenement;
use App\Security\Role;
use Symfony\Component\Mime\Email;

/** Le journal (F-38) : connexion, création d'association, invitation, souscription et paiement laissent une trace. */
final class JournalTest extends CasDeTestWeb
{
    public function testLeParcoursCompletLaisseSesTraces(): void
    {
        $adminId = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);

        // Connexion par le formulaire.
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'admin@example.org', 'mot_de_passe' => self::MOT_DE_PASSE]);
        self::assertSame(1, $this->compter(TypeEvenement::Connexion));
        $connexion = $this->dernier(TypeEvenement::Connexion);
        self::assertSame('admin@example.org', $connexion->getActeur()?->getEmail());
        self::assertNull($connexion->getAssociation(), 'Un super-admin n’a pas d’association.');

        // Création d'une association : association, abonnement initial, invitation.
        $this->client->request('GET', '/administration/associations/nouvelle');
        $this->client->submitForm('Créer l’association et inviter', ['association[nom]' => 'Association de Bakel', 'association[emailBureauCentral]' => 'mamadou@example.org']);
        self::assertSame(1, $this->compter(TypeEvenement::AssociationCreee));
        self::assertSame(1, $this->compter(TypeEvenement::AbonnementInitial));
        self::assertSame(1, $this->compter(TypeEvenement::InvitationEnvoyee));
        $initial = $this->dernier(TypeEvenement::AbonnementInitial);
        self::assertSame('a-souscrire', $initial->detail('statut'));
        self::assertSame(0, $initial->detail('mensuel'));
        self::assertSame('admin@example.org', $initial->getActeur()?->getEmail());
        self::assertSame('bakel', $initial->getAssociation()?->getSlug() ? 'bakel' : null);

        // Souscription par le bureau central invité.
        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        preg_match('#/invitation/([a-f0-9]{64})#', (string) $email->getTextBody(), $correspondance);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/invitation/'.$correspondance[1]);
        $this->client->submitForm('Souscrire et ouvrir Association de Bakel', [
            'souscription[offre]' => 'standard-mensuel',
            'souscription[prenom]' => 'Mamadou',
            'souscription[nom]' => 'Diaby',
            'souscription[motDePasse]' => self::MOT_DE_PASSE,
            'souscription[conditions]' => '1',
        ]);
        self::assertSame(1, $this->compter(TypeEvenement::CompteCree));
        self::assertSame(1, $this->compter(TypeEvenement::InvitationAcceptee));
        self::assertSame(1, $this->compter(TypeEvenement::AbonnementSouscrit));
        $souscrit = $this->dernier(TypeEvenement::AbonnementSouscrit);
        self::assertSame('actif', $souscrit->detail('statut'));
        self::assertSame(2500, $souscrit->detail('mensuel'));
        self::assertSame('mamadou@example.org', $souscrit->getActeur()?->getEmail());
        self::assertSame(2, $this->compter(TypeEvenement::Connexion), 'La connexion automatique après souscription compte aussi.');

        // Paiement enregistré par le super-admin : un Paiement et une ligne de journal.
        $association = $this->em()->getRepository(Association::class)->findOneBy(['slug' => 'association-de-bakel']);
        self::assertNotNull($association);
        $this->connecter($adminId);
        $this->client->request('GET', '/administration/associations/'.$association->getId());
        $this->client->submitForm('Marquer comme payé');
        $paiements = $this->em()->getRepository(PaiementAbonnement::class)->findAll();
        self::assertCount(1, $paiements);
        self::assertSame(2500, $paiements[0]->getMontant());
        self::assertSame('admin@example.org', $paiements[0]->getEnregistrePar()?->getEmail());
        self::assertSame(date('Y-m-d'), $paiements[0]->getRecuLe()->format('Y-m-d'));
        self::assertSame((new \DateTimeImmutable('+14 days'))->format('Y-m-d'), $paiements[0]->getEcheanceCouverte()?->format('Y-m-d'), 'Le paiement solde le premier paiement attendu.');
        $paye = $this->dernier(TypeEvenement::AbonnementPaye);
        self::assertSame(2500, $paye->detail('paiement'));

        // La page Activité affiche ces événements.
        $this->client->request('GET', '/administration/activite');
        self::assertResponseIsSuccessful();
    }

    private function compter(TypeEvenement $type): int
    {
        return $this->em()->getRepository(Evenement::class)->count(['type' => $type]);
    }

    private function dernier(TypeEvenement $type): Evenement
    {
        $evenement = $this->em()->getRepository(Evenement::class)->findOneBy(['type' => $type], ['id' => 'DESC']);
        self::assertNotNull($evenement);

        return $evenement;
    }
}
