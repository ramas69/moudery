<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\EtapeAssistant;
use App\Entity\Evenement;
use App\Entity\TypeEvenement;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Villes de toutes les associations, vues et modifiées par le super-admin. */
final class AdministrationVilleControllerTest extends CasDeTestWeb
{
    public function testLeBureauCentralNAccedePasAuxVillesDeLAdministration(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $this->connecter($this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral));

        $this->client->request('GET', '/administration/villes');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/administration/villes/'.$lyon.'/modifier');
        self::assertResponseStatusCodeSame(403);
    }

    public function testLeSuperAdminListeLesVillesDeToutesLesAssociations(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->creerVille($moudery, 'Lyon');
        $this->creerVille($moudery, 'Marseille', [], EtapeAssistant::Activation);
        $this->creerVille($bakel, 'Rouen');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/villes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Villes');
        self::assertSelectorTextContains('.console__sous-titre', '3 villes');
        self::assertSelectorTextContains('.tableau', 'Lyon');
        self::assertSelectorTextContains('.tableau', 'Marseille');
        self::assertSelectorTextContains('.tableau', 'Rouen');
        self::assertSelectorTextContains('.tableau', '3/3 · Activation');

        $this->client->request('GET', '/administration/villes?association=bakel');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__sous-titre', '1 ville');
        self::assertSelectorTextContains('.tableau', 'Rouen');
        self::assertSelectorTextNotContains('.tableau', 'Lyon');

        $this->client->request('GET', '/administration/villes?statut=active');
        self::assertSelectorTextContains('.tableau', 'Aucune ville ne correspond');
        self::assertSelectorTextContains('nav[aria-label="Statut"] a[aria-current="true"]', 'Actives · 0');
    }

    public function testLaListeSeChercheSeTrieSeGroupeEtSExporte(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $bakel = $this->creerAssociation('Amicale de Bakel', 'bakel');
        $lyon = $this->creerVille($moudery, 'Lyon', [], EtapeAssistant::Activation);
        $this->creerVille($moudery, 'Évry');
        $this->creerVille($bakel, 'Rouen');
        $em = $this->em();
        $em->createQuery('UPDATE App\\Entity\\Ville v SET v.modifieLe = :quand WHERE v.id = :id')
            ->setParameters(['quand' => new \DateTimeImmutable('-45 days'), 'id' => $lyon])
            ->execute();
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        // Recherche sans accents, sur la ville comme sur l'association.
        $this->client->request('GET', '/administration/villes?q=evry');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__sous-titre', '1 ville sur 3');
        self::assertSelectorTextContains('.tableau tbody', 'Évry');
        self::assertSelectorTextNotContains('.tableau tbody', 'Lyon');
        self::assertSelectorExists('input[name="q"][value="evry"]');
        self::assertSelectorExists('a[href="/administration/villes/export.csv?q=evry&statut=tous&tri=association&sens=asc"]', 'L’export reprend les filtres.');

        // Le sélecteur d'association accepte un nom, même partiel.
        $this->client->request('GET', '/administration/villes?association=amicale');
        self::assertSelectorTextContains('.tableau tbody', 'Rouen');
        self::assertSelectorTextNotContains('.tableau tbody', 'Lyon');
        self::assertSelectorExists('input[name="association"][value="Amicale de Bakel"]', 'Le nom complet est rappelé dans le champ.');
        self::assertSelectorExists('[role="listbox"] [role="option"][data-valeur="Association de Moudery"]');
        self::assertSelectorNotExists('a[href*="villes/nouvelle"]', 'Le super-admin ne crée pas de ville.');

        // Brouillons bloqués : sans changement depuis 30 jours.
        $this->client->request('GET', '/administration/villes?statut=bloquees');
        self::assertSelectorTextContains('nav[aria-label="Statut"] a[aria-current="true"]', 'Bloquées · 1');
        self::assertSelectorTextContains('.tableau tbody', 'Lyon');
        self::assertSelectorTextContains('.tableau tbody', 'Sans changement depuis 45 jours');
        self::assertSelectorTextNotContains('.tableau tbody', 'Rouen');

        // Tri par colonne.
        $this->client->request('GET', '/administration/villes?tri=ville&sens=desc');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(1) .entite__nom', 'Rouen');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(3) .entite__nom', 'Évry');
        self::assertSelectorExists('th[aria-sort="descending"] a[href="/administration/villes?statut=tous&tri=ville&sens=asc"]', 'Le lien de la colonne active inverse le sens.');

        // Regroupement par association.
        $this->client->request('GET', '/administration/villes?groupe=1&tri=ville&sens=asc');
        self::assertSelectorCount(2, '.tableau__groupe');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(1)', 'Amicale de Bakel');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(1)', '1 ville');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(2) .entite__nom', 'Rouen');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(3)', 'Association de Moudery');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(3)', '2 villes');
        self::assertSelectorExists('a.filtre--bascule[aria-pressed="true"]');

        // Menu d'actions par ligne.
        self::assertSelectorExists('.tableau tbody details.menu a[href$="/assistant"]', 'Un brouillon ouvre l’assistant.');
        self::assertSelectorExists(\sprintf('.tableau tbody details.menu a[href="/administration/villes/%d/modifier"]', $lyon));

        // Export CSV.
        $this->client->request('GET', '/administration/villes/export.csv?statut=brouillon&tri=ville&sens=asc');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}Ville;Association;Identifiant;Village;Statut;Étape;Responsables;\"Créée le\";\"Modifiée le\"", $csv);
        self::assertStringContainsString('Évry;"Association de Moudery";moudery;"Association de Moudery";Brouillon;"2/3 · Membres";0;', $csv, 'Sans village renseigné, le nom de l’association tient lieu de village.');
        self::assertStringContainsString('Lyon;"Association de Moudery";moudery;', $csv);
        self::assertStringContainsString(';oui', $csv, 'Lyon est bloquée.');
        self::assertStringContainsString('Rouen;"Amicale de Bakel";bakel;', $csv, 'Rouen est en brouillon aussi.');
        self::assertSame(4, substr_count($csv, "\n"), 'En-tête et trois brouillons.');
    }

    public function testLaListeSePagine(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        for ($i = 1; $i <= 27; ++$i) {
            $this->creerVille($moudery, \sprintf('Ville %02d', $i));
        }
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/villes?tri=ville&sens=asc');
        self::assertSelectorCount(25, '.tableau tbody tr');
        self::assertSelectorTextContains('.pagination__resume', '1 à 25 sur 27 villes');
        self::assertSelectorTextContains('.pagination__position', 'Page 1 sur 2');
        self::assertSelectorExists('.pagination a[href="/administration/villes?statut=tous&tri=ville&sens=asc&par=25&page=2"]');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(1) .entite__nom', 'Ville 01');

        $this->client->request('GET', '/administration/villes?tri=ville&sens=asc&page=2');
        self::assertSelectorCount(2, '.tableau tbody tr');
        self::assertSelectorTextContains('.pagination__resume', '26 à 27 sur 27 villes');
        self::assertSelectorTextContains('.tableau tbody tr:nth-child(1) .entite__nom', 'Ville 26');

        $this->client->request('GET', '/administration/villes?par=50');
        self::assertSelectorCount(27, '.tableau tbody tr');
        self::assertSelectorNotExists('.pagination__position');
        self::assertSelectorTextContains('.pagination__par a[aria-current="true"]', '50');

        $this->client->request('GET', '/administration/villes?page=9');
        self::assertSelectorTextContains('.pagination__resume', '26 à 27 sur 27 villes', 'Une page hors limite ramène à la dernière.');
    }

    public function testLeSuperAdminNeCreePasDeVilleCestLeBureauCentral(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/villes');
        self::assertSelectorNotExists('a[href*="villes/nouvelle"]', 'Aucun bouton de création dans l’administration.');
        $this->client->request('GET', '/administration/associations/'.$moudery);
        self::assertSelectorNotExists('a[href*="villes/nouvelle"]');

        $this->client->request('GET', '/administration/villes/nouvelle');
        self::assertResponseStatusCodeSame(404, 'La route de création n’existe plus.');
        $this->client->request('GET', '/associations/moudery/villes/nouvelle');
        self::assertResponseStatusCodeSame(403, 'Seul le bureau central crée une ville, même via l’assistant.');
        self::assertCount(0, $this->em()->getRepository(Ville::class)->findAll());
    }

    public function testLeSuperAdminRenommeEtFaitVivreUneVille(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/administration/villes/'.$lyon.'/modifier');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="ville_administration[association]"][disabled]', 'Une ville ne change pas d’association.');

        $this->client->submitForm('Enregistrer les modifications', ['ville_administration[nom]' => 'Lyon Métropole', 'ville_administration[statut]' => 'active']);
        self::assertResponseRedirects('/administration/villes/'.$lyon.'/modifier', 303);
        $ville = $this->em()->find(Ville::class, $lyon);
        self::assertSame('Lyon Métropole', $ville?->getNom());
        self::assertSame(VilleStatut::Active, $ville?->getStatut());
        $activation = $this->dernier(TypeEvenement::VilleStatut);
        self::assertSame('active', $activation->detail('statut'));
        self::assertSame('Lyon Métropole', $activation->getCible());
        self::assertSame('moudery', $activation->getAssociation()?->getSlug());
        self::assertSame('admin@example.org', $activation->getActeur()?->getEmail());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'modifiée');
        self::assertSelectorNotExists('a[href$="/assistant"]', 'L’assistant ne concerne que les brouillons.');

        $this->client->submitForm('Enregistrer les modifications', ['ville_administration[nom]' => 'Lyon Métropole', 'ville_administration[statut]' => 'brouillon']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#ville_administration_statut_erreur', 'ne peut pas passer');
        self::assertSame(VilleStatut::Active, $this->em()->find(Ville::class, $lyon)?->getStatut());
        self::assertSame(1, $this->compter(TypeEvenement::VilleStatut), 'Un changement refusé ne laisse pas de trace.');

        $this->client->submitForm('Enregistrer les modifications', ['ville_administration[nom]' => 'Lyon Métropole', 'ville_administration[statut]' => 'archivee']);
        self::assertResponseRedirects('/administration/villes/'.$lyon.'/modifier', 303);
        self::assertSame(VilleStatut::Archivee, $this->em()->find(Ville::class, $lyon)?->getStatut());

        $this->client->followRedirect();
        $this->client->submitForm('Enregistrer les modifications', ['ville_administration[nom]' => 'Lyon Métropole', 'ville_administration[statut]' => 'active']);
        self::assertResponseRedirects('/administration/villes/'.$lyon.'/modifier', 303);
        self::assertSame(VilleStatut::Active, $this->em()->find(Ville::class, $lyon)?->getStatut(), 'Une ville archivée se réactive.');
        self::assertSame(3, $this->compter(TypeEvenement::VilleStatut), 'Activation, archivage, réactivation : trois lignes de journal.');

        $this->client->followRedirect();
        $this->client->submitForm('Enregistrer les modifications', ['ville_administration[nom]' => 'Lyon', 'ville_administration[statut]' => 'active']);
        self::assertResponseRedirects('/administration/villes/'.$lyon.'/modifier', 303);
        self::assertSame('Lyon', $this->em()->find(Ville::class, $lyon)?->getNom());
        self::assertSame(3, $this->compter(TypeEvenement::VilleStatut), 'Un renommage sans changement de statut n’est pas consigné.');
    }

    public function testLeSuperAdminOuvreLAssistantDeNImporteQuelleVille(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $this->connecter($this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin));

        $this->client->request('GET', '/associations/moudery/villes/'.$lyon.'/assistant/identite');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="ville_identite[nom]"][value="Lyon"]');

        $this->client->request('GET', '/associations/moudery/villes/nouvelle');
        self::assertResponseStatusCodeSame(403, 'Ouvrir l’assistant d’une ville existante, oui ; en créer une, non.');
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
