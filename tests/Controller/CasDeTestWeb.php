<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Association;
use App\Entity\EtapeAssistant;
use App\Entity\RoleVille;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Entity\Ville;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Socle des tests de parcours : base SQLite recréée à chaque test, associations, villes et comptes. */
abstract class CasDeTestWeb extends WebTestCase
{
    public const string MOT_DE_PASSE = 'Registre-Moudery-2026!';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $em = $this->em();
        $schema = new SchemaTool($em);
        $metadonnees = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadonnees);
        $schema->createSchema($metadonnees);

        // Les compteurs des limiteurs (tentatives de connexion, mot de passe oublié) repartent de zéro.
        $limiteurs = static::getContainer()->get('cache.rate_limiter');
        \assert($limiteurs instanceof CacheItemPoolInterface);
        $limiteurs->clear();
    }

    protected function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();

        return $em;
    }

    protected function creerAssociation(string $nom, string $slug): int
    {
        $em = $this->em();
        $association = new Association($nom, $slug);
        $em->persist($association);
        $em->flush();

        return (int) $association->getId();
    }

    /** @param array<string, string> $responsables rôle => email */
    protected function creerVille(int $associationId, string $nom, array $responsables = [], EtapeAssistant $etape = EtapeAssistant::Membres): int
    {
        $em = $this->em();
        $association = $em->find(Association::class, $associationId);
        \assert($association instanceof Association);

        $ville = new Ville($association, $nom);
        foreach ($responsables as $role => $email) {
            $ville->definirResponsable(RoleVille::from($role), $email);
        }
        $ville->avancerA($etape);
        $em->persist($ville);
        $em->flush();

        return (int) $ville->getId();
    }

    /** Un compte, actif par défaut, avec au plus une affectation. Sans association : super-admin plateforme. */
    protected function creerUtilisateur(
        ?int $associationId,
        string $email,
        ?Role $role = null,
        ?int $villeId = null,
        UtilisateurStatut $statut = UtilisateurStatut::Actif,
        string $motDePasse = self::MOT_DE_PASSE,
    ): int {
        $em = $this->em();
        $association = null !== $associationId ? $em->find(Association::class, $associationId) : null;

        $utilisateur = new Utilisateur($association, $email, 'Awa', 'Cissé', '', $statut);
        $hacheur = static::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hacheur instanceof UserPasswordHasherInterface);
        $utilisateur->definirMotDePasse($hacheur->hashPassword($utilisateur, $motDePasse));

        if (null !== $role) {
            $ville = null !== $villeId ? $em->find(Ville::class, $villeId) : null;
            $utilisateur->affecter($role, $ville);
        }

        $em->persist($utilisateur);
        $em->flush();

        return (int) $utilisateur->getId();
    }

    protected function connecter(int $utilisateurId): Utilisateur
    {
        $utilisateur = $this->em()->find(Utilisateur::class, $utilisateurId);
        \assert($utilisateur instanceof Utilisateur);
        $this->client->loginUser($utilisateur);

        return $utilisateur;
    }
}
