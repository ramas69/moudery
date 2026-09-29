<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mot de passe oublié (F-03) : un lien à usage unique, valable une heure, envoyé par email.
 * Seule l'empreinte du jeton est conservée en base.
 */
final class ReinitialisationMotDePasse
{
    public function __construct(
        private readonly UtilisateurRepository $utilisateurs,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $hacheur,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $traducteur,
    ) {
    }

    /** Silencieux si l'adresse est inconnue ou le compte inactif : on ne révèle pas l'existence des comptes. */
    public function demander(string $email): void
    {
        $utilisateur = $this->utilisateurs->trouverParEmail($email);
        if (null === $utilisateur || !$utilisateur->estActif()) {
            return;
        }

        $jeton = bin2hex(random_bytes(32));
        $expireLe = (new \DateTimeImmutable())->add(new \DateInterval(Utilisateur::DUREE_JETON_REINITIALISATION));
        $utilisateur->demanderReinitialisation(self::empreinte($jeton), $expireLe);
        $this->entityManager->flush();

        $this->mailer->send((new TemplatedEmail())
            ->to(new Address($utilisateur->getEmail(), $utilisateur->getNomComplet()))
            ->subject($this->traducteur->trans('reinitialisation.email.objet'))
            ->htmlTemplate('emails/reinitialisation_mot_de_passe.html.twig')
            ->textTemplate('emails/reinitialisation_mot_de_passe.txt.twig')
            ->context([
                'utilisateur' => $utilisateur,
                'lien' => $this->urls->generate('reinitialisation_mot_de_passe', ['jeton' => $jeton], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
    }

    /** Le compte dont le lien est encore valable, ou null. */
    public function utilisateurPourJeton(string $jeton): ?Utilisateur
    {
        $utilisateur = $this->utilisateurs->trouverParJetonReinitialisation(self::empreinte($jeton));

        return null !== $utilisateur && $utilisateur->jetonReinitialisationValide(new \DateTimeImmutable()) ? $utilisateur : null;
    }

    public function reinitialiser(Utilisateur $utilisateur, string $motDePasse): void
    {
        $utilisateur->definirMotDePasse($this->hacheur->hashPassword($utilisateur, $motDePasse));
        $utilisateur->terminerReinitialisation();
        $this->entityManager->flush();
    }

    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }
}
