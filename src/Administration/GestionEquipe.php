<?php

declare(strict_types=1);

namespace App\Administration;

use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Form\Model\EquipeInvitationData;
use App\Journal\Journal;
use App\Repository\UtilisateurRepository;
use App\Security\ReinitialisationMotDePasse;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * L'équipe de la plateforme : les comptes qui portent le rôle super-admin, avec ou sans association
 * (règle du 27 septembre 2026 : une personne peut être super-admin et appartenir à une association).
 * Un nouveau membre est créé actif avec un mot de passe aléatoire jamais communiqué, puis reçoit un lien
 * de 7 jours pour choisir le sien (même page que « mot de passe oublié »). Un compte existant se promeut.
 * Chaque action est consignée au journal avant d'être enregistrée.
 */
final class GestionEquipe
{
    public const string DUREE_LIEN = 'P7D';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hacheur,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $traducteur,
        private readonly Journal $journal,
        private readonly Security $securite,
    ) {
    }

    /** @return list<Utilisateur> */
    public function membres(): array
    {
        return $this->utilisateurs->listerSuperAdmins();
    }

    /** Les comptes qui ne sont pas encore super-admins : candidats à la promotion. @return list<Utilisateur> */
    public function candidats(): array
    {
        return array_values(array_filter(
            $this->utilisateurs->listerTous(),
            static fn (Utilisateur $compte): bool => !$compte->aLeRole(Role::SuperAdmin),
        ));
    }

    public function ajouter(EquipeInvitationData $donnees, ?Utilisateur $par = null): Utilisateur
    {
        $compte = new Utilisateur($donnees->association, (string) $donnees->email, (string) $donnees->prenom, (string) $donnees->nom, '', UtilisateurStatut::Actif);
        $compte->definirMotDePasse($this->hacheur->hashPassword($compte, bin2hex(random_bytes(32))));
        $compte->affecter(Role::SuperAdmin);

        $this->entityManager->persist($compte);
        $this->journal->consigner(TypeEvenement::CompteCree, $par ?? $this->acteur(), $compte->getAssociation(), $compte->getEmail(), ['role' => Role::SuperAdmin->value, 'par_administration' => true]);
        $this->envoyerLien($compte, $par);

        return $compte;
    }

    /** Le compte garde ses autres rôles : le bureau central d'une association peut aussi administrer la plateforme. */
    public function promouvoir(Utilisateur $compte): void
    {
        if ($compte->aLeRole(Role::SuperAdmin)) {
            throw new \LogicException(\sprintf('%s est déjà super-admin.', $compte->getNomComplet()));
        }

        $compte->affecter(Role::SuperAdmin);
        $this->journal->consigner(TypeEvenement::RoleAttribue, $this->acteur(), $compte->getAssociation(), $compte->getEmail(), ['role' => Role::SuperAdmin->value, 'ville' => null]);
        $this->entityManager->flush();
    }

    /** Un nouveau lien de 7 jours pour choisir son mot de passe ; l'ancien lien ne vaut plus. */
    public function envoyerLien(Utilisateur $compte, ?Utilisateur $par = null): void
    {
        $jeton = bin2hex(random_bytes(32));
        $expireLe = (new \DateTimeImmutable())->add(new \DateInterval(self::DUREE_LIEN));
        $compte->demanderReinitialisation(ReinitialisationMotDePasse::empreinte($jeton), $expireLe);
        $this->journal->consigner(TypeEvenement::CompteLienMotDePasse, $par ?? $this->acteur(), $compte->getAssociation(), $compte->getEmail(), ['validite' => self::DUREE_LIEN]);
        $this->entityManager->flush();

        $this->mailer->send((new TemplatedEmail())
            ->to(new Address($compte->getEmail(), $compte->getNomComplet()))
            ->subject($this->traducteur->trans('administration.equipe.email_objet'))
            ->htmlTemplate('emails/equipe_invitation.html.twig')
            ->textTemplate('emails/equipe_invitation.txt.twig')
            ->context([
                'compte' => $compte,
                'par' => $par,
                'expireLe' => $expireLe,
                'lien' => $this->urls->generate('reinitialisation_mot_de_passe', ['jeton' => $jeton], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
    }

    private function acteur(): ?Utilisateur
    {
        $acteur = $this->securite->getUser();

        return $acteur instanceof Utilisateur ? $acteur : null;
    }
}
