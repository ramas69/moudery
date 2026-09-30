<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AppelContribution;
use App\Entity\Association;
use App\Entity\Echeance;
use App\Entity\EtapeAssistant;
use App\Entity\Membre;
use App\Entity\ModeMontant;
use App\Entity\TypeContribution;
use App\Entity\UniteContribution;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Onglet Projets de l'espace membre : collecté, reste, par ville et au total, jamais un nom, et sa propre situation. */
final class MonEspaceProjetsTest extends CasDeTestWeb
{
    public function testLeMembreVoitLesAppelsParVilleEtAuTotal(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $idLyon = $this->creerVille($moudery, 'Lyon', [], EtapeAssistant::Activation);
        $idParis = $this->creerVille($moudery, 'Paris', [], EtapeAssistant::Activation);
        $em = $this->em();
        $association = $em->find(Association::class, $moudery);
        $lyon = $em->find(Ville::class, $idLyon);
        $paris = $em->find(Ville::class, $idParis);
        \assert($association instanceof Association && $lyon instanceof Ville && $paris instanceof Ville);
        $lyon->changerStatut(VilleStatut::Active);
        $paris->changerStatut(VilleStatut::Active);
        $hawa = new Membre($lyon, 'Hawa', 'Soumaré', 'hawa@example.org');
        $oumar = new Membre($lyon, 'Oumar', 'Kanouté');
        $fanta = new Membre($paris, 'Fanta', 'Diawara');
        $type = new TypeContribution($association, 'deces', 'Décès', UniteContribution::Personne, ModeMontant::Fixe, 1000, null);
        $appel = new AppelContribution($association, $type, null);
        $appel->definir($type, 'Décès famille Sakho', 'Solidarité.', ModeMontant::Fixe, 1000, new \DateTimeImmutable('+10 days'), null, 0, false);
        $appel->ouvrir(new \DateTimeImmutable('-2 days'));
        foreach ([$hawa, $oumar, $fanta, $type, $appel] as $objet) {
            $em->persist($objet);
        }
        $echeances = [];
        foreach ([$hawa, $oumar, $fanta] as $m) {
            $echeances[] = $e = new Echeance($appel, $m, new \DateTimeImmutable('-2 days'));
            $appel->getEcheances()->add($e);
            $em->persist($e);
        }
        $echeances[1]->payer(null, new \DateTimeImmutable('-1 day'));
        $echeances[2]->payer(null, new \DateTimeImmutable('-1 day'));
        $em->flush();
        $this->connecter($this->creerUtilisateur($moudery, 'hawa@example.org', Role::Membre, $idLyon));

        $this->client->request('GET', '/mon-espace/projets');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.espace-membre__onglet[aria-current="page"]', 'Projets');
        self::assertSelectorTextContains('.projet h2', 'Décès famille Sakho');
        // 3 échéances de 10 € : 20 € collectés, 10 € restent ; Lyon 1 sur 2, Paris 1 sur 1.
        self::assertSelectorTextContains('.projet .espace-membre__bilan', '20');
        self::assertSelectorTextContains('.projet__villes tbody', 'Lyon');
        self::assertSelectorTextContains('.projet__villes tbody', 'Paris');
        self::assertSelectorTextContains('.projet__villes tfoot', '2 / 3');
        self::assertSelectorTextContains('.projet__moi', 'il vous reste 10');
        self::assertSelectorTextNotContains('.espace-membre__contenu', 'Kanouté', 'Jamais le nom d’un autre membre.');
        self::assertSelectorTextNotContains('.espace-membre__contenu', 'Diawara');
    }
}
