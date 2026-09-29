<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Abonnement\Catalogue;
use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Le tableau de bord du bureau central (F-28) : chiffres, à traiter, villes, activité ; étanche entre associations. */
final class AssociationTableauDeBordTest extends CasDeTestWeb
{
    public function testLeBureauCentralArriveSurSonTableauDeBord(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon', ['tresorier' => 'tresorier@example.org'], EtapeAssistant::Activation);
        $evry = $this->creerVille($moudery, 'Évry', [], EtapeAssistant::Membres);
        $central = $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $em = $this->em();
        $lyonEntite = $em->find(Ville::class, $lyon);
        \assert($lyonEntite instanceof Ville);
        $lyonEntite->changerStatut(VilleStatut::Active);
        $em->find(Association::class, $moudery)?->ouvrirAbonnement();
        $em->createQuery('UPDATE App\Entity\Ville v SET v.modifieLe = :quand WHERE v.id = :id')->setParameters(['quand' => new \DateTimeImmutable('-40 days'), 'id' => $evry])->execute();
        $em->flush();

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'central@moudery.fr', 'mot_de_passe' => self::MOT_DE_PASSE]);
        $this->client->followRedirect();
        self::assertResponseRedirects('/associations/moudery', 302, 'Le bureau central est conduit à son tableau de bord.');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertSelectorTextContains('.console__perimetre-nom', 'Toute l’association');
        self::assertSelectorTextContains('.console__nom', 'Awa Cissé');
        self::assertSelectorExists('.console__marque a.logo[href="/"]');
        self::assertSelectorTextContains('.console__sous-titre', '2 villes');
        self::assertSelectorExists('a.bouton--primaire[href="/associations/moudery/villes/nouvelle"]', 'Créer une ville reste à portée.');

        // Les cinq chiffres de la maquette : sans encaissement connu, des zéros, jamais un montant inventé.
        self::assertSelectorCount(5, '.kpis .kpi');
        self::assertSelectorTextContains('.kpis .kpi:nth-child(1)', 'Collecté');
        self::assertSelectorTextContains('.kpis .kpi:nth-child(1)', '0 %', 'Aucune variation connue.');
        self::assertSelectorTextContains('.kpis .kpi:nth-child(2)', 'Dépensé');
        self::assertSelectorTextContains('.kpis .kpi:nth-child(3)', 'Solde des caisses');
        self::assertSelectorTextContains('.kpis .kpi:nth-child(4)', 'Membres à jour 0 %');
        self::assertSelectorTextContains('.kpis .kpi:nth-child(4)', '0 / 0');
        self::assertSelectorTextContains('.kpis .kpi:nth-child(5)', 'Reversements dus');
        self::assertSelectorTextContains('.kpis .kpi:nth-child(5)', 'aucune ville sur 2 villes');
        self::assertSelectorNotExists('.etat', 'Plus de ligne d’état : la maquette n’en a pas.');

        // À traiter : Évry bloquée et sans trésorier renseigné → la ville bloquée passe en premier.
        self::assertSelectorTextContains('#a-traiter', 'À traiter 1');
        self::assertSelectorTextContains('section[aria-labelledby="a-traiter"] .section__ligne', 'Évry : la création est en pause depuis 40 jours');
        self::assertSelectorExists(\sprintf('section[aria-labelledby="a-traiter"] a[href="/associations/moudery/villes/%d/assistant"]', $evry));

        // Les villes, actives d'abord, en colonnes de la maquette : membres à jour, collecté, reversement dû ; un brouillon se reprend.
        self::assertSelectorTextContains('#villes thead', 'Membres à jour');
        self::assertSelectorTextContains('#villes thead', 'Reversement dû');
        self::assertSelectorTextContains('#villes tbody tr:nth-child(1) .entite__nom', 'Lyon');
        self::assertSelectorTextContains('#villes tbody tr:nth-child(1) .entite__nom', 'aucun membre');
        self::assertSelectorTextContains('#villes tbody tr:nth-child(1)', '0 %');
        self::assertSelectorTextNotContains('#villes tbody tr:nth-child(1)', 'Reprendre');
        self::assertSelectorTextContains('#villes tbody tr:nth-child(2) .entite__nom', 'Évry');
        self::assertSelectorTextContains('#villes tbody tr:nth-child(2)', 'Brouillon');
        self::assertSelectorTextContains('#villes tbody tr:nth-child(2)', 'Reprendre');

        // L'activité : la connexion qui vient d'avoir lieu.
        self::assertSelectorTextContains('section[aria-labelledby="journal-titre"]', 'Awa Cissé');
        self::assertSelectorTextContains('section[aria-labelledby="journal-titre"]', 'Connexion');
        self::assertSelectorTextContains('section[aria-labelledby="journal-titre"] time', 'à l’instant', 'Le moment est relatif, la date exacte reste dans l’attribut.');
        // Les encaissements par mois de l'exercice : douze colonnes, en barres CSS, avec la bascule Mois / Cumul.
        self::assertSelectorTextContains('.graphique h2', 'Encaissements par mois');
        self::assertSelectorCount(12, '.graphique__barres .graphique__colonne');
        self::assertSelectorCount(12, 'section[aria-labelledby="encaissements-titre"] .graphique__donnees tbody tr');
        self::assertSelectorTextContains('.graphique__legende', 'Cotisations');
        self::assertSelectorTextContains('.graphique__legende', 'Contributions ponctuelles');
        self::assertSelectorTextContains('nav[aria-label="Vue du graphique"] a[aria-current="true"]', 'Mois');

        // L'en-tête : l'exercice en cours, le rapport d'AG annoncé, Paramètres en bas du menu.
        self::assertSelectorTextContains('.console__actions .exercice', 'Exercice '.date('Y'));
        self::assertCount((int) date('Y') - 2025 + 1, $this->client->getCrawler()->filter('.exercice-menu .menu__item'), 'Les exercices suivis partent de 2025.');
        self::assertSelectorTextContains('.exercice-menu .menu__item--actif', 'Exercice '.date('Y'));
        self::assertSelectorTextContains('.exercice-menu .menu__item:last-child', 'Exercice 2025');
        self::assertSelectorTextContains('.exercice-menu .menu__item:last-child .menu__note', 'premier exercice suivi');

        // Un exercice passé se consulte ; avant 2025, on retombe sur l'exercice en cours.
        $this->client->request('GET', '/associations/moudery?exercice=2025');
        self::assertSelectorTextContains('.console__actions .exercice', 'Exercice 2025');
        self::assertSelectorTextContains('.kpis', 'premier exercice suivi', 'Pas de comparaison avant le premier exercice.');
        self::assertSelectorExists('nav[aria-label="Vue du graphique"] a[href="/associations/moudery?vue=cumul&exercice=2025"]', 'La bascule garde l’exercice.');
        $this->client->request('GET', '/associations/moudery?exercice=1999');
        self::assertSelectorTextContains('.console__actions .exercice', 'Exercice '.date('Y'));
        $crawler = $this->client->request('GET', '/associations/moudery');
        self::assertSelectorExists('.console__actions a[href="/associations/moudery/rapport"]', 'Le bouton « Rapport d’AG » mène au rapport.');
        self::assertSelectorExists('.console__bas a[href="/parametres"]');

        // La navigation annonce les modules à venir sans les rendre cliquables.
        self::assertSelectorNotExists('.console__lien--bientot', 'Plus d’entrées mortes (décision de Rama du 28 septembre 2026).');
        self::assertSelectorNotExists('.console__nav .console__a-venir', 'Tous les modules de l’association existent : plus de ligne « Modules à venir ».');
        self::assertSelectorExists('.console__nav a[href="/associations/moudery/rapport"]');
        self::assertSelectorExists('.console__lien[aria-current="page"][href="/associations/moudery"]');

        // La vue en cumul.
        $this->client->request('GET', '/associations/moudery?vue=cumul');
        self::assertSelectorTextContains('nav[aria-label="Vue du graphique"] a[aria-current="true"]', 'Cumul');
        self::assertSelectorCount(12, '.graphique__barres .graphique__colonne');
    }

    public function testSansVilleLeTableauDeBordInviteACreerLaPremiere(): void
    {
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $this->connecter($this->creerUtilisateur($bakel, 'central@bakel.fr', Role::BureauCentral));

        $this->client->request('GET', '/associations/bakel');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.section__accueil-titre', 'Aucune ville pour l’instant');
        self::assertSelectorExists('.section__accueil a[href="/associations/bakel/villes/nouvelle"]');
        self::assertSelectorTextContains('section[aria-labelledby="a-traiter"] .section__vide', 'Commencez par créer votre première ville');
        self::assertSelectorTextContains('.kpis .kpi:last-child', 'aucune ville encore');
    }

    public function testLAbonnementEtLesResponsablesSeLisentSurLeTableauDeBord(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $central = $this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral);
        $em = $this->em();
        $association = $em->find(Association::class, $moudery);
        \assert($association instanceof Association);
        $mensuel = Catalogue::offre(Catalogue::CODE_MENSUEL);
        \assert(null !== $mensuel);
        $association->ouvrirAbonnement()->souscrire($mensuel, new \DateTimeImmutable('-20 days'));
        $em->flush();

        $this->connecter($central);
        $this->client->request('GET', '/associations/moudery');
        self::assertSelectorTextContains('section[aria-labelledby="a-traiter"] .section__ligne', 'Votre abonnement est en retard', 'Souscrit il y a 20 jours, premier paiement attendu sous 14 : en retard.');
        self::assertSelectorTextContains('section[aria-labelledby="a-traiter"] .a-traiter__abonnement', '25,00');
    }

    public function testLeTableauDeBordEstEtancheEntreAssociationsEtRoles(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $lyon = $this->creerVille($moudery, 'Lyon');
        $centralBakel = $this->creerUtilisateur($bakel, 'central@bakel.fr', Role::BureauCentral);
        $tresorier = $this->creerUtilisateur($moudery, 'tresorier@moudery.fr', Role::Tresorier, $lyon);
        $admin = $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);

        $this->connecter($centralBakel);
        $this->client->request('GET', '/associations/moudery');
        self::assertResponseStatusCodeSame(403, 'Le bureau central de Bakel ne voit pas Moudery.');
        $this->client->request('GET', '/associations/bakel');
        self::assertResponseIsSuccessful();

        $this->connecter($tresorier);
        $this->client->request('GET', '/associations/moudery');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__perimetre-nom', 'Lyon', 'Le trésorier a le tableau de bord de sa ville, jamais de toute l’association.');
        self::assertSelectorNotExists('section[aria-labelledby="a-traiter"]');
        // Depuis le 29 septembre 2026, le trésorier ouvre le rapport d'AG de sa ville (jamais le consolidé).
        self::assertSelectorExists('.console__nav a[href$="/rapport"]');
        self::assertSelectorNotExists('.console__actions a[href*="/rapport"]');

        $this->connecter($admin);
        $this->client->request('GET', '/associations/moudery');
        self::assertResponseIsSuccessful('Le super-admin peut consulter, en soutien.');
        self::assertSelectorNotExists('a.bouton--primaire[href="/associations/moudery/villes/nouvelle"]', 'Mais il ne crée pas de ville.');

        $this->client->request('GET', '/associations/inconnue');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUneVilleChoisieDansLEnTeteRecentreLeTableauDeBord(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon', ['tresorier' => 'tresorier@example.org'], EtapeAssistant::Activation);
        $evry = $this->creerVille($moudery, 'Évry', [], EtapeAssistant::Membres);
        $em = $this->em();
        $lyonEntite = $em->find(Ville::class, $lyon);
        \assert($lyonEntite instanceof Ville);
        $annee = (int) date('Y');
        $mamadou = new Membre($lyonEntite, 'Mamadou', 'Diaby');
        $mamadou->adherer($annee - 1);
        $mamadou->adherer($annee);
        $awa = new Membre($lyonEntite, 'Awa', 'Cissé', 'awa@example.org');
        $awa->adherer($annee);
        // L'historique des classeurs : Mamadou à jour (douze mois à 10 €), Awa a versé janvier seulement.
        $mamadou->adhesionPour($annee)?->definirHistorique(array_fill(1, 12, 1000), null, null);
        $awa->adhesionPour($annee)?->definirHistorique([1 => 1000], null, null);
        $em->persist($mamadou);
        $em->persist($awa);
        $em->flush();
        $this->connecter($this->creerUtilisateur($moudery, 'central@moudery.fr', Role::BureauCentral));

        // Sans ville : le sélecteur propose toute l'association et chaque ville ; les chiffres viennent de l'historique.
        $crawler = $this->client->request('GET', '/associations/moudery');
        self::assertSelectorTextContains('.perimetre .console__perimetre-nom', 'Toute l’association');
        self::assertCount(3, $crawler->filter('.perimetre .menu__item'));
        self::assertSelectorTextContains('.perimetre .menu__item--actif', 'Toute l’association');
        self::assertStringContainsString('130', $crawler->filter('.kpis .kpi:nth-child(1) .kpi__valeur')->text(), '120 € de Mamadou et 10 € d’Awa : 130 € collectés.');
        self::assertSelectorTextContains('.kpis .kpi:nth-child(4)', 'Membres à jour 50 %');
        self::assertSelectorTextContains('.kpis .kpi:nth-child(4)', '1 / 2');
        self::assertStringContainsString('130', $crawler->filter('.kpis .kpi:nth-child(3) .kpi__valeur')->text(), 'Solde = collecté, rien de dépensé.');
        self::assertSelectorTextContains('#villes tbody tr:nth-child(1)', '2 membres');
        self::assertSelectorTextContains('#villes tbody tr:nth-child(1)', '50 %');
        self::assertStringContainsString('130', $crawler->filter('#villes tbody tr:nth-child(1)')->text());
        self::assertStringContainsString('20', $crawler->filter('.graphique__donnees tbody tr:nth-child(1)')->text(), 'Janvier : 10 € de Mamadou et 10 € d’Awa.');

        // Évry choisie : sous-titre, ligne d'état, villes et résumé se limitent à elle.
        $crawler = $this->client->request('GET', '/associations/moudery?ville='.$evry);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console__sous-titre', 'Évry · Association de Moudery');
        self::assertSelectorTextContains('.perimetre .console__perimetre-nom', 'Évry');
        self::assertSelectorTextContains('.perimetre .menu__item--actif', 'Évry');
        self::assertSelectorNotExists('.console__nav a[href$="#villes"]', 'En périmètre de ville, plus d’entrée Villes ni Importer.');
        self::assertSelectorTextContains('.console__nav', 'Cotisations');
        self::assertCount(1, $crawler->filter('#villes tbody tr'));
        self::assertSelectorTextContains('#villes tbody', 'Évry');
        self::assertSelectorTextContains('.kpis .kpi:nth-child(4)', '0 / 0', 'Évry n’a aucun adhérent.');
        self::assertSelectorTextContains('#a-traiter', '1', 'Évry sans trésorier reste à traiter ; l’abonnement, propre à l’association, ne s’affiche pas ici.');

        // Lyon choisie : ses deux membres et ses chiffres.
        $crawler = $this->client->request('GET', '/associations/moudery?ville='.$lyon);
        self::assertSelectorTextContains('.kpis .kpi:nth-child(4)', '1 / 2');
        self::assertCount(1, $crawler->filter('#villes tbody tr'));
        self::assertSelectorTextContains('.console__actions .exercice', 'Exercice '.$annee, 'L’exercice reste affiché dans l’en-tête.');

        // Le périmètre est mémorisé : la page suivante, sans paramètre, reste sur Lyon ; le sélecteur ramène à l'association.
        $this->client->request('GET', '/associations/moudery');
        self::assertSelectorTextContains('.console__sous-titre', 'Lyon · Association de Moudery');
        $this->client->request('GET', '/associations/moudery/perimetre/association?retour=%2Fassociations%2Fmoudery%3Fville%3D'.$lyon.'%26periode%3D30j');
        self::assertResponseRedirects('/associations/moudery?periode=30j', 302, 'Retour à la page d’origine, sans son paramètre ville.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.perimetre .console__perimetre-nom', 'Toute l’association');
        self::assertSelectorTextContains('.console__sous-titre', '2 villes');

        // Une ville d'une autre association ne se choisit pas : on retombe sur toute l'association.
        $bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $dakar = $this->creerVille($bakel, 'Dakar');
        $this->client->request('GET', '/associations/moudery?ville='.$dakar);
        self::assertSelectorTextContains('.perimetre .console__perimetre-nom', 'Toute l’association');
        $this->client->request('GET', '/associations/moudery/perimetre/'.$dakar);
        self::assertResponseStatusCodeSame(404);
    }
}
