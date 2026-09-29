<?php

declare(strict_types=1);

namespace App\Tests\Administration;

use App\Administration\TableauAssociations;
use App\Entity\AbonnementStatut;
use App\Entity\Association;
use App\Entity\AssociationStatut;
use App\Entity\Invitation;
use App\Entity\Periodicite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Security\Role;
use PHPUnit\Framework\TestCase;

/** Recherche, filtres, tri et compteurs de la liste des associations. */
final class TableauAssociationsTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $lignes;

    protected function setUp(): void
    {
        $moudery = new Association('Association de Moudery', 'moudery');
        $moudery->definirVillage('Moudery');
        $central = new Utilisateur($moudery, 'central@moudery.fr', 'Mamadou', 'Diaby', 'hache', UtilisateurStatut::Actif);
        $central->affecter(Role::BureauCentral);

        $bakel = new Association('Association de Bakél', 'bakel');
        $bakel->changerStatut(AssociationStatut::Suspendue);
        $bakel->ouvrirAbonnement()->definir('Standard', AbonnementStatut::EnRetard, 2000, Periodicite::Mensuelle, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-09-01'), null);
        $invitationBakel = new Invitation($bakel, Role::BureauCentral, 'aissata@example.org', 'empreinte', new \DateTimeImmutable('-1 day'));

        $dakar = new Association('Amicale de Dakar', 'dakar');
        $invitationDakar = new Invitation($dakar, Role::BureauCentral, 'ousmane@example.org', 'empreinte-2', new \DateTimeImmutable('-30 days'));

        $this->lignes = [
            $this->ligne($moudery, [4, 1], [30, 2], [$central], null, null, new \DateTimeImmutable('2026-09-20 10:00')),
            $this->ligne($bakel, [1, 1], [0, 0], [], $invitationBakel, 'en_attente', null),
            $this->ligne($dakar, [0, 0], [0, 0], [], $invitationDakar, 'expiree', new \DateTimeImmutable('2026-01-05 09:00')),
        ];
    }

    public function testLaRechercheIgnoreAccentsEtCasseEtRegardeContactsEtInvitations(): void
    {
        self::assertSame(['bakel'], $this->slugs(TableauAssociations::filtrer($this->lignes, 'BAKEL', 'toutes', 'tous')), 'Accents et majuscules sont ignorés.');
        self::assertSame(['moudery'], $this->slugs(TableauAssociations::filtrer($this->lignes, 'diaby', 'toutes', 'tous')), 'Le bureau central est cherché.');
        self::assertSame(['dakar'], $this->slugs(TableauAssociations::filtrer($this->lignes, 'ousmane@', 'toutes', 'tous')), 'L’adresse invitée est cherchée.');
        self::assertSame([], TableauAssociations::filtrer($this->lignes, 'introuvable', 'toutes', 'tous'));
        self::assertCount(3, TableauAssociations::filtrer($this->lignes, '   ', 'toutes', 'tous'));
    }

    public function testLesFiltresDeStatutEtDeBureauCentralSeCombinent(): void
    {
        self::assertSame(['moudery', 'dakar'], $this->slugs(TableauAssociations::filtrer($this->lignes, '', 'actives', 'tous')));
        self::assertSame(['bakel'], $this->slugs(TableauAssociations::filtrer($this->lignes, '', 'suspendues', 'tous')));
        self::assertSame(['moudery'], $this->slugs(TableauAssociations::filtrer($this->lignes, '', 'toutes', 'avec')));
        self::assertSame(['bakel', 'dakar'], $this->slugs(TableauAssociations::filtrer($this->lignes, '', 'toutes', 'sans')));
        self::assertSame(['bakel'], $this->slugs(TableauAssociations::filtrer($this->lignes, '', 'toutes', 'invitation-en-attente')));
        self::assertSame(['dakar'], $this->slugs(TableauAssociations::filtrer($this->lignes, '', 'toutes', 'invitation-expiree')));
        self::assertSame(['dakar'], $this->slugs(TableauAssociations::filtrer($this->lignes, '', 'actives', 'sans')));
        self::assertSame('toutes', TableauAssociations::statut('n-importe-quoi'), 'Une valeur inconnue revient au défaut.');
        self::assertSame('tous', TableauAssociations::bureau(null));
    }

    public function testLeTriEtSonSens(): void
    {
        self::assertSame(['dakar', 'bakel', 'moudery'], $this->slugs(TableauAssociations::trier($this->lignes, 'nom', 'asc')), 'Par nom, « Amicale » passe avant « Association ».');
        self::assertSame(['moudery', 'bakel', 'dakar'], $this->slugs(TableauAssociations::trier($this->lignes, 'nom', 'desc')));
        self::assertSame(['moudery', 'bakel', 'dakar'], $this->slugs(TableauAssociations::trier($this->lignes, 'villes', 'desc')));
        self::assertSame(['bakel', 'dakar', 'moudery'], $this->slugs(TableauAssociations::trier($this->lignes, 'activite', 'asc')), 'Sans activité en premier, puis la plus ancienne.');
        self::assertSame(['moudery', 'bakel', 'dakar'], $this->slugs(TableauAssociations::trier($this->lignes, 'comptes', 'desc')), 'À égalité, l’ordre alphabétique (« Amicale » avant « Association ») est inversé avec le sens.');
        self::assertSame('nom', TableauAssociations::tri('inconnu'));
        self::assertSame('asc', TableauAssociations::sens('inconnu'));
    }

    public function testLesCompteursEtLesTotaux(): void
    {
        $compteurs = TableauAssociations::compter($this->lignes);
        self::assertSame(['toutes' => 3, 'actives' => 2, 'suspendues' => 1, 'archivees' => 0], $compteurs['statut']);
        self::assertSame(['tous' => 3, 'a-souscrire' => 2, 'offert' => 0, 'actif' => 0, 'en-retard' => 1, 'echeance-proche' => 0, 'resilie' => 0], $compteurs['abonnement']);
        self::assertSame(['bakel'], $this->slugs(TableauAssociations::filtrer($this->lignes, '', 'toutes', 'tous', 'en-retard')), 'Filtre abonnement.');
        self::assertSame(['tous' => 3, 'avec' => 1, 'sans' => 2, 'invitation-en-attente' => 1, 'invitation-expiree' => 1], $compteurs['bureau']);

        self::assertSame(['associations' => 3, 'villes' => 5, 'villesBrouillon' => 2, 'comptes' => 30, 'comptesEnAttente' => 2], TableauAssociations::totaux($this->lignes));
    }

    /**
     * @param array{int, int}  $villes
     * @param array{int, int}  $comptes
     * @param list<Utilisateur> $bureau
     *
     * @return array<string, mixed>
     */
    private function ligne(Association $association, array $villes, array $comptes, array $bureau, ?Invitation $invitation, ?string $etat, ?\DateTimeImmutable $activite): array
    {
        return [
            'association' => $association,
            'villes' => ['total' => $villes[0], 'brouillon' => $villes[1]],
            'comptes' => ['total' => $comptes[0], 'en_attente' => $comptes[1]],
            'bureauCentral' => $bureau,
            'invitation' => $invitation,
            'invitationEtat' => $etat,
            'derniereActivite' => $activite,
            'suppressionPossible' => 0 === $villes[0] && 0 === $comptes[0],
            'abonnement' => $association->ouvrirAbonnement(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $lignes
     *
     * @return list<string>
     */
    private function slugs(array $lignes): array
    {
        return array_map(static fn (array $l): string => $l['association']->getSlug(), $lignes);
    }
}
