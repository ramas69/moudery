<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\EtapeAssistant;
use App\Entity\Evenement;
use App\Entity\TypeEvenement;
use App\Entity\Ville;
use App\Entity\VilleStatut;
use App\Security\Role;

/** Le bureau central archive une ville depuis l'espace, et la réactive ; jamais de suppression. */
final class AssociationVillesStatutTest extends CasDeTestWeb
{
    public function testArchiverPuisReactiverUneVille(): void
    {
        $moudery = $this->creerAssociation('Association de Moudery', 'moudery');
        $lyon = $this->creerVille($moudery, 'Lyon', ['tresorier' => 'awa@example.org'], EtapeAssistant::Activation);
        $em = $this->em();
        $ville = $em->find(Ville::class, $lyon);
        \assert($ville instanceof Ville);
        $ville->changerStatut(VilleStatut::Active);
        $em->flush();
        $this->connecter($this->creerUtilisateur($moudery, 'central@example.org', Role::BureauCentral));

        $crawler = $this->client->request('GET', '/associations/moudery/villes');
        $this->client->submit($crawler->filter(\sprintf('form[action="/associations/moudery/villes/%d/statut/archiver"]', $lyon))->form());
        self::assertResponseRedirects('/associations/moudery/villes', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Lyon est archivée');
        self::assertSelectorTextContains('.filtres', 'Archivées · 1');
        self::assertSame(VilleStatut::Archivee, $this->em()->find(Ville::class, $lyon)?->getStatut());
        self::assertInstanceOf(Evenement::class, $this->em()->getRepository(Evenement::class)->findOneBy(['type' => TypeEvenement::VilleStatut]));

        $crawler = $this->client->request('GET', '/associations/moudery/villes');
        $this->client->submit($crawler->filter(\sprintf('form[action="/associations/moudery/villes/%d/statut/reactiver"]', $lyon))->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alerte--succes', 'Lyon est de nouveau active');
        self::assertSame(VilleStatut::Active, $this->em()->find(Ville::class, $lyon)?->getStatut());

        // Un trésorier ne pilote pas l'association : refus.
        $this->connecter($this->creerUtilisateur($moudery, 'awa@example.org', Role::Tresorier, $lyon));
        $this->client->request('POST', \sprintf('/associations/moudery/villes/%d/statut/archiver', $lyon), ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }
}
