<?php

declare(strict_types=1);

namespace App\Tests\Administration;

use App\Abonnement\Catalogue;
use App\Administration\GestionAbonnements;
use App\Administration\Periode;
use App\Administration\Statistiques;
use App\Entity\AbonnementStatut;
use App\Entity\Association;
use App\Entity\AssociationStatut;
use App\Entity\EtapeAssistant;
use App\Entity\Invitation;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Journal\Journal;
use App\Security\Role;
use App\Tests\Controller\CasDeTestWeb;

/** Les séries des graphiques, calculées sur un jeu de données daté à la main. */
final class StatistiquesTest extends CasDeTestWeb
{
    private \DateTimeImmutable $aujourdhui;
    private Periode $douzeMois;
    private int $moudery;
    private int $bakel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aujourdhui = new \DateTimeImmutable();
        $this->douzeMois = Periode::depuis('12m', null, null, $this->aujourdhui);

        $this->moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $this->bakel = $this->creerAssociation('Association de Bakel', 'bakel');
        $lyon = $this->creerVille($this->moudery, 'Lyon', [], EtapeAssistant::Activation);
        $this->creerVille($this->moudery, 'Évry', [], EtapeAssistant::Membres);
        $rouen = $this->creerVille($this->bakel, 'Rouen');
        $central = $this->creerUtilisateur($this->moudery, 'central@moudery.fr', Role::BureauCentral);
        $this->creerUtilisateur($this->moudery, 'tresorier@moudery.fr', Role::Tresorier, $lyon);
        $this->creerUtilisateur($this->bakel, 'central@bakel.fr', Role::BureauCentral);
        $this->creerUtilisateur(null, 'admin@example.org', Role::SuperAdmin);

        $em = $this->em();
        // Tout a été créé « maintenant » : on antidate pour avoir un passé.
        $this->dater(Association::class, $this->bakel, 'creeLe', '-4 months');
        $this->dater(Ville::class, $rouen, 'creeLe', '-3 months');
        $this->dater(Ville::class, $lyon, 'creeLe', '-8 months');
        $lyonEntite = $em->find(Ville::class, $lyon);
        \assert($lyonEntite instanceof Ville);
        $lyonEntite->changerStatut(VilleStatut::Active);
        $em->find(Utilisateur::class, $central)?->marquerConnexion($this->aujourdhui->modify('-2 days'));
        $em->flush();
    }

    public function testLaCroissanceParMoisEtEnCumul(): void
    {
        $croissance = $this->statistiques()->croissance($this->douzeMois, null, null);

        self::assertCount(12, $croissance['associations']);
        self::assertSame(2, array_sum($croissance['associations']));
        self::assertSame(1, $croissance['associations'][11], 'Moudery créée ce mois-ci.');
        self::assertSame(1, $croissance['associations'][7], 'Bakel créée il y a quatre mois.');
        self::assertSame(3, array_sum($croissance['villes']));
        self::assertSame(3, array_sum($croissance['comptes']), 'Le super-admin ne compte pas.');
        self::assertSame(2, $croissance['cumulAssociations'][11]);
        self::assertSame(1, $croissance['cumulAssociations'][8]);
        self::assertSame(0, $croissance['cumulAssociations'][3]);

        $em = $this->em();
        $bakel = $em->find(Association::class, $this->bakel);
        $seule = $this->statistiques()->croissance($this->douzeMois, $bakel, null);
        self::assertSame(1, array_sum($seule['associations']));
        self::assertSame(1, array_sum($seule['villes']));

        $bakel?->changerStatut(AssociationStatut::Suspendue);
        $em->flush();
        $actives = $this->statistiques()->croissance($this->douzeMois, null, AssociationStatut::Active);
        self::assertSame(1, array_sum($actives['associations']));
        self::assertSame(2, array_sum($actives['villes']), 'Les villes de l’association suspendue sortent du filtre.');
    }

    public function testLesVillesParEtapeEtParStatut(): void
    {
        $villes = $this->statistiques()->villesParEtape(null, null);
        self::assertSame(3, $villes['total']);
        self::assertSame(1, $villes['actives']);
        self::assertSame(0, $villes['archivees']);
        self::assertSame(2, $villes['etapes'][2], 'Évry et Rouen sont à l’étape Membres.');
        self::assertSame(0, $villes['etapes'][1]);
        self::assertSame(0, $villes['etapes'][3], 'Lyon est active, plus dans l’assistant.');

        $brouillons = $this->statistiques()->villesParEtape(null, VilleStatut::Brouillon);
        self::assertSame(2, $brouillons['total']);
        self::assertSame(0, $brouillons['actives']);
    }

    public function testLesAbonnementsLesEncaissementsEtLeRevenu(): void
    {
        $em = $this->em();
        $moudery = $em->find(Association::class, $this->moudery);
        $bakel = $em->find(Association::class, $this->bakel);
        \assert($moudery instanceof Association && $bakel instanceof Association);
        $journal = static::getContainer()->get(Journal::class);
        $gestion = static::getContainer()->get(GestionAbonnements::class);
        \assert($journal instanceof Journal && $gestion instanceof GestionAbonnements);

        // Bakel souscrit l'annuel il y a trois mois, Moudery reste à souscrire.
        $abonnementBakel = $gestion->pour($bakel);
        $annuel = Catalogue::offre(Catalogue::CODE_ANNUEL);
        \assert(null !== $annuel);
        $abonnementBakel->souscrire($annuel, $this->aujourdhui->modify('-3 months'));
        $journal->consigner(TypeEvenement::AbonnementSouscrit, null, $bakel, $annuel->code, Journal::instantane($abonnementBakel), $this->aujourdhui->modify('-3 months'));
        $em->flush();
        $gestion->pour($moudery);
        $paiement = $gestion->marquerPaye($abonnementBakel);

        $repartition = $this->statistiques()->abonnementsParStatut(null, $this->aujourdhui);
        self::assertSame(1, $repartition[AbonnementStatut::Actif->value]['nombre']);
        self::assertSame(intdiv(Catalogue::ANNUEL, 12), $repartition[AbonnementStatut::Actif->value]['mensuel']);
        self::assertSame(1, $repartition[AbonnementStatut::ASouscrire->value]['nombre']);

        $encaissements = $this->statistiques()->encaissements($this->douzeMois, null, $this->aujourdhui);
        self::assertSame(Catalogue::ANNUEL, $encaissements['totalRecu'], 'Le paiement d’aujourd’hui est reçu ce mois-ci.');
        self::assertSame(Catalogue::ANNUEL, $encaissements['recus'][11]);
        self::assertSame(0, $encaissements['enRetard']);
        self::assertSame(0, $encaissements['totalAttendu'], 'La prochaine échéance est dans un an : hors période.');
        self::assertSame(Catalogue::ANNUEL, $paiement->getMontant());

        $revenu = $this->statistiques()->revenuRecurrent($this->douzeMois, null);
        self::assertSame(0, $revenu['mensuel'][7], 'Avant la souscription, rien.');
        self::assertSame(intdiv(Catalogue::ANNUEL, 12), $revenu['mensuel'][11], 'Depuis la souscription, le revenu mensuel de Bakel.');
        self::assertSame(1, $revenu['payants'][11]);
        self::assertSame(0, $revenu['payants'][5]);
    }

    public function testLesInvitationsLesComptesEtLActivite(): void
    {
        $compteA = $this->creerUtilisateur($this->moudery, 'a@example.org');
        $em = $this->em();
        $moudery = $em->find(Association::class, $this->moudery);
        \assert($moudery instanceof Association);
        $acceptee = new Invitation($moudery, Role::BureauCentral, 'a@example.org', hash('sha256', 'a'), $this->aujourdhui->modify('-10 days'));
        $acceptee->accepter($em->find(Utilisateur::class, $compteA) ?? throw new \RuntimeException(), $this->aujourdhui->modify('-9 days'));
        $expiree = new Invitation($moudery, Role::Tresorier, 'b@example.org', hash('sha256', 'b'), $this->aujourdhui->modify('-20 days'), null, $em->getRepository(Ville::class)->findOneBy(['nom' => 'Lyon']));
        $enCours = new Invitation($moudery, Role::Tresorier, 'c@example.org', hash('sha256', 'c'), $this->aujourdhui->modify('-1 day'), null, $em->getRepository(Ville::class)->findOneBy(['nom' => 'Lyon']));
        $em->persist($acceptee);
        $em->persist($expiree);
        $em->persist($enCours);
        $journal = static::getContainer()->get(Journal::class);
        \assert($journal instanceof Journal);
        $journal->consigner(TypeEvenement::Connexion, null, $moudery, 'central@moudery.fr', [], $this->aujourdhui->modify('-2 days'));
        $journal->consigner(TypeEvenement::VilleCreee, null, $moudery, 'Lyon', [], $this->aujourdhui->modify('-8 months'));
        $journal->consigner(TypeEvenement::CompteCree, null, $moudery, 'a@example.org', [], $this->aujourdhui->modify('-9 days'));
        $em->flush();

        $invitations = $this->statistiques()->invitations($this->douzeMois, null, $this->aujourdhui);
        self::assertSame(3, $invitations['totalEnvoyees']);
        self::assertSame(1, $invitations['totalAcceptees']);
        self::assertSame(1, array_sum($invitations['expirees']));
        self::assertEqualsWithDelta(1 / 3, $invitations['taux'], 0.001);
        self::assertEqualsWithDelta(24.0, $invitations['delaiMoyenHeures'], 0.1, 'Acceptée un jour après l’envoi.');

        $comptes = $this->statistiques()->comptes(null, null, $this->aujourdhui);
        self::assertSame(['connectes' => 1, 'inactifs' => 0, 'jamaisConnectes' => 3, 'enAttente' => 0, 'desactives' => 0, 'total' => 4], $comptes, 'Le super-admin ne compte pas.');
        $tresoriers = $this->statistiques()->comptes(null, Role::Tresorier, $this->aujourdhui);
        self::assertSame(1, $tresoriers['total']);

        $activite = $this->statistiques()->activite($this->douzeMois, null);
        self::assertSame(1, $activite['totalConnexions']);
        self::assertSame(2, $activite['totalActions']);
        self::assertEqualsCanonicalizing(['connexion' => 1, 'ville' => 1, 'compte' => 1], $activite['parCategorie']);
        self::assertSame(1, $activite['actions'][3], 'La ville créée il y a huit mois.');
    }

    private function statistiques(): Statistiques
    {
        $service = static::getContainer()->get(Statistiques::class);
        \assert($service instanceof Statistiques);

        return $service;
    }

    /** @param class-string $classe */
    private function dater(string $classe, int $id, string $champ, string $decalage): void
    {
        $this->em()->createQuery(\sprintf('UPDATE %s e SET e.%s = :quand WHERE e.id = :id', $classe, $champ))
            ->setParameters(['quand' => $this->aujourdhui->modify($decalage), 'id' => $id])
            ->execute();
    }
}
