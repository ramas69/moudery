<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AbonnementStatut;
use App\Entity\Association;
use App\Entity\Periodicite;
use App\Entity\Utilisateur;
use App\Security\Role;

/** Abonnements des associations et tableau de bord retravaillé (chiffres, à traiter, activité récente). */
final class AdministrationAbonnementTest extends CasDeTestWeb
{
    public function testUneAssociationCreeeParLAdministrationRecoitUnAbonnementASouscrire(): void
    {
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/associations/nouvelle');
        $this->client->submitForm('Créer l’association et inviter', ['association[nom]' => 'Association de Bakel', 'association[emailBureauCentral]' => 'mamadou@example.org']);
        $association = $this->em()->getRepository(Association::class)->findOneBy(['slug' => 'association-de-bakel']);

        self::assertNotNull($association?->getAbonnement());
        self::assertSame(AbonnementStatut::ASouscrire, $association->getAbonnement()->getStatut());

        $this->client->request('GET', '/administration/associations/'.$association->getId());
        self::assertSelectorTextContains('section#abonnement h2 .pastille', 'À souscrire');
        self::assertSelectorTextContains('section#abonnement', 'Gratuit');
        self::assertSelectorNotExists('section#abonnement form[action$="/abonnement/paye"]', 'À souscrire : rien à payer encore.');
    }

    public function testLeSuperAdminRenseigneUnAbonnementPuisEnregistreUnPaiement(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        // Une association d'avant la notion d'abonnement en reçoit un à la première visite.
        $this->client->request('GET', '/administration/associations/'.$moudery.'/abonnement');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Abonnement de l’association');
        $echeance = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $this->client->submitForm('Enregistrer l’abonnement', [
            'abonnement[formule]' => 'Standard',
            'abonnement[statut]' => 'actif',
            'abonnement[montant]' => '25.50',
            'abonnement[periodicite]' => 'mensuelle',
            'abonnement[debutLe]' => '2026-09-01',
            'abonnement[prochaineEcheanceLe]' => $echeance,
            'abonnement[notes]' => 'Facture par virement.',
        ]);
        self::assertResponseRedirects('/administration/associations/'.$moudery.'#abonnement', 303);

        $abonnement = $this->em()->find(Association::class, $moudery)?->getAbonnement();
        self::assertNotNull($abonnement);
        self::assertSame('Standard', $abonnement->getFormule());
        self::assertSame(2550, $abonnement->getMontant(), 'Le montant est stocké en centimes.');
        self::assertSame(Periodicite::Mensuelle, $abonnement->getPeriodicite());
        self::assertSame($echeance, $abonnement->getProchaineEcheanceLe()?->format('Y-m-d'));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'enregistré');
        self::assertSelectorTextContains('section#abonnement', '25,50');
        self::assertSelectorTextContains('section#abonnement', 'sous 30 jours');
        self::assertSelectorTextContains('section#abonnement', 'Facture par virement.');

        $this->client->submitForm('Marquer comme payé');
        self::assertResponseRedirects('/administration/associations/'.$moudery.'#abonnement', 303);
        $abonnement = $this->em()->find(Association::class, $moudery)?->getAbonnement();
        self::assertSame((new \DateTimeImmutable($echeance))->modify('+1 month')->format('Y-m-d'), $abonnement?->getProchaineEcheanceLe()?->format('Y-m-d'), 'L’échéance avance d’un mois.');
        self::assertSame(date('Y-m-d'), $abonnement?->getDernierPaiementLe()?->format('Y-m-d'));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Paiement enregistré');
    }

    public function testUnMontantNegatifOuUneEcheanceAvantLeDebutSontRefuses(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/associations/'.$moudery.'/abonnement');
        $this->client->submitForm('Enregistrer l’abonnement', [
            'abonnement[formule]' => '',
            'abonnement[montant]' => '-5',
            'abonnement[debutLe]' => '2026-09-01',
            'abonnement[prochaineEcheanceLe]' => '2026-08-01',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#abonnement_formule_erreur', 'obligatoire');
        self::assertSelectorTextContains('#abonnement_montant_erreur', 'négatif');
        self::assertSelectorTextContains('#abonnement_prochaineEcheanceLe_erreur', 'précéder le début');
    }

    public function testLeTableauDeBordSignaleLesRetardsEtRaconteLActivite(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->creerUtilisateur($bakel, 'central@bakel.fr', Role::BureauCentral);
        $admin = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);

        $em = $this->em();
        $enRetard = $em->find(Association::class, $moudery);
        \assert($enRetard instanceof Association);
        $enRetard->ouvrirAbonnement()->definir('Standard', AbonnementStatut::Actif, 3000, Periodicite::Mensuelle, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('-3 days'), null);
        $bientot = $em->find(Association::class, $bakel);
        \assert($bientot instanceof Association);
        $bientot->ouvrirAbonnement()->definir('Standard', AbonnementStatut::Actif, 12000, Periodicite::Annuelle, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('+5 days'), null);
        $em->find(Utilisateur::class, $admin)?->marquerConnexion(new \DateTimeImmutable('+1 minute'));
        $em->flush();

        $this->connecter($admin);
        $this->client->request('GET', '/administration');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertSelectorTextContains('.kpis', 'Revenu mensuel');
        self::assertSelectorTextContains('.kpis', '40,00', '30 € mensuels et 120 € annuels font 40 € par mois.');
        self::assertSelectorTextContains('.kpis', '1 en retard');
        self::assertSelectorExists('.kpis a[href="/administration/abonnements"]');
        self::assertSelectorTextContains('.kpis', 'À encaisser');
        self::assertSelectorTextContains('.kpis', '150,00', '30 € en retard et 120 € à échéance sous 30 jours.');
        self::assertSelectorTextContains('#a-traiter', 'À traiter 2');
        self::assertSelectorTextContains('section[aria-labelledby="a-traiter"] .section__ligne', 'Association de Moudery : abonnement en retard', 'Le retard passe en premier.');
        self::assertSelectorExists(\sprintf('section[aria-labelledby="a-traiter"] a[href="/administration/associations/%d#abonnement"]', $moudery), 'La ligne mène à l’abonnement de la fiche.');
        self::assertSelectorTextContains('section[aria-labelledby="a-traiter"]', 'Association de Bakel : premier paiement attendu le', 'Aucun paiement encore reçu : c’est le premier.');
        self::assertSelectorCount(8, 'canvas[data-controller="symfony--ux-chartjs--chart"]', 'Huit graphiques Chart.js.');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-encaissements-titre"] .section__sous-titre', '30,00', 'Le retard de Moudery est attendu.');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-abonnements-titre"] .section__sous-titre', '2 abonnements');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-abonnements-titre"] .graphique__donnees', 'En retard');
        self::assertSelectorNotExists('section[aria-labelledby="activite-titre"]', 'L’activité a sa propre page.');

        // L'activité, jour par jour, sur sa page.
        $this->client->request('GET', '/administration/activite');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Activité');
        self::assertSelectorTextContains('.fil__jour', 'Aujourd’hui');
        self::assertSelectorTextContains('.fil__ligne', 'Awa Cissé');
        self::assertSelectorTextContains('.fil__ligne', 's’est connecté', 'La connexion du super-admin est la plus récente.');
        self::assertSelectorTextContains('section', 'a été créée');

        // Un paiement depuis la fiche, puis le retard disparaît du tableau de bord.
        $this->client->request('GET', '/administration/associations/'.$moudery);
        $this->client->submitForm('Marquer comme payé');
        self::assertResponseRedirects('/administration/associations/'.$moudery.'#abonnement', 303);
        $this->client->request('GET', '/administration');
        self::assertSelectorTextNotContains('section[aria-labelledby="a-traiter"]', 'en retard', 'Payé, l’abonnement n’est plus en retard ; l’échéance suivante peut rester dans la fenêtre des 30 jours.');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-encaissements-titre"] .section__sous-titre', '30,00', 'Le paiement reçu apparaît dans les encaissements.');
        self::assertSelectorTextContains('section[aria-labelledby="graphique-encaissements-titre"] .section__sous-titre', '0,00', 'Plus rien en retard.');
    }

    public function testLaPageDesAbonnementsEtLeFiltreDeLaListe(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $admin = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);

        $em = $this->em();
        $payant = $em->find(Association::class, $moudery);
        \assert($payant instanceof Association);
        $payant->ouvrirAbonnement()->definir('Standard', AbonnementStatut::EnRetard, 5000, Periodicite::Mensuelle, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-09-01'), null);
        $em->find(Association::class, $bakel)?->ouvrirAbonnement();
        $em->flush();

        $this->connecter($admin);
        $this->client->request('GET', '/administration/abonnements');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Abonnements 2');
        self::assertSelectorTextContains('.kpis', '50,00');
        self::assertSelectorTextContains('.kpis', 'soit 600,00');
        self::assertSelectorTextContains('tbody tr:nth-child(1)', 'Association de Moudery', 'L’échéance la plus proche d’abord, les abonnements sans échéance à la fin.');
        self::assertSelectorTextContains('tbody tr:nth-child(1) .pastille--erreur', 'En retard');
        self::assertSelectorTextContains('tbody tr:nth-child(1)', 'Échéance dépassée');
        self::assertSelectorTextContains('tbody tr:nth-child(2)', 'Association de Bakel');
        self::assertSelectorTextContains('tbody tr:nth-child(2)', 'À souscrire');
        self::assertSelectorExists(\sprintf('tbody tr:nth-child(1) form[action="/administration/associations/%d/abonnement/paye"]', $moudery));
        self::assertSelectorNotExists(\sprintf('tbody tr:nth-child(2) form[action="/administration/associations/%d/abonnement/paye"]', $bakel));

        $this->client->request('GET', '/administration/associations?abonnement=en-retard');
        self::assertSelectorTextContains('nav[aria-label="Abonnement"] a[aria-current="true"]', 'En retard · 1');
        self::assertSelectorTextContains('tbody', 'Moudery');
        self::assertSelectorTextNotContains('tbody', 'Bakel');

        $this->client->request('GET', '/administration/associations/export.csv?abonnement=a-souscrire');
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('"Abonnement · formule";"Abonnement · statut";"Abonnement · montant (€)";"Abonnement · échéance"', $csv);
        self::assertStringContainsString('"Association de Bakel";bakel;', $csv);
        self::assertStringContainsString(';Gratuit;"À souscrire";0,00;', $csv);
        self::assertStringNotContainsString('Moudery', $csv);
    }

    public function testLeBureauCentralNeTouchePasAuxAbonnements(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral));

        $this->client->request('GET', '/administration/abonnements');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/administration/associations/'.$moudery.'/abonnement');
        self::assertResponseStatusCodeSame(403);
    }
}
