<?php

declare(strict_types=1);

namespace App\Compte;

use App\Abonnement\Catalogue;
use App\Entity\Abonnement;
use App\Entity\Association;
use App\Entity\Invitation;
use App\Entity\Membre;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurStatut;
use App\Entity\Ville;
use App\Form\Model\ActivationCompteData;
use App\Form\Model\SouscriptionData;
use App\Journal\Journal;
use App\Repository\InvitationRepository;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Invitations à créer son compte (F-02) : envoi du lien, renvoi, acceptation.
 * Le compte naît actif, avec l'affectation promise par l'invitation. Pour le bureau central d'une association
 * qui n'a pas encore souscrit, l'e-mail présente les offres et le lien mène à la page de souscription.
 */
final class Invitations
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvitationRepository $invitations,
        private readonly UserPasswordHasherInterface $hacheur,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $traducteur,
        private readonly Journal $journal,
    ) {
    }

    public function inviter(Association $association, Role $role, string $email, ?Utilisateur $par = null, ?Ville $ville = null): Invitation
    {
        $jeton = self::nouveauJeton();
        $invitation = new Invitation($association, $role, $email, self::empreinte($jeton), new \DateTimeImmutable(), $par, $ville);

        $this->entityManager->persist($invitation);
        $this->journal->consigner(TypeEvenement::InvitationEnvoyee, $par, $association, $invitation->getEmail(), ['role' => $role->value, 'ville' => $ville?->getNom()]);
        $this->entityManager->flush();
        $this->envoyer($invitation, $jeton);

        return $invitation;
    }

    /**
     * Un responsable de ville invite un membre à ouvrir son espace (décision de Rama du 29 septembre 2026 : pas
     * d'inscription libre, le membre reçoit un lien de sa ville). Une invitation en cours pour cette adresse est
     * renvoyée plutôt que doublée. Rend l'invitation, ou null si le membre n'a pas d'adresse ou déjà un compte.
     */
    public function inviterMembre(Membre $membre, ?Utilisateur $par): ?Invitation
    {
        $email = $membre->getEmail();
        if (null === $email || '' === $email || null !== $membre->getCompte()) {
            return null;
        }
        foreach ($this->invitations->enCoursPourEmail($membre->getAssociation(), $email) as $enCours) {
            if (Role::Membre === $enCours->getRole() && $enCours->getVille() === $membre->getVille()) {
                $this->renvoyer($enCours);

                return $enCours;
            }
        }

        return $this->inviter($membre->getAssociation(), Role::Membre, $email, $par, $membre->getVille());
    }

    /** Nouveau lien, nouvelle échéance : l'ancien lien ne vaut plus rien. */
    public function renvoyer(Invitation $invitation): void
    {
        $jeton = self::nouveauJeton();
        $invitation->emettre(self::empreinte($jeton), new \DateTimeImmutable());

        $this->journal->consigner(TypeEvenement::InvitationEnvoyee, null, $invitation->getAssociation(), $invitation->getEmail(), ['role' => $invitation->getRole()->value, 'renvoi' => true]);
        $this->entityManager->flush();
        $this->envoyer($invitation, $jeton);
    }

    /** L'invitation qui porte ce jeton, quel que soit son état ; null si le jeton est inconnu. */
    public function pourJeton(string $jeton): ?Invitation
    {
        return $this->invitations->trouverParJeton(self::empreinte($jeton));
    }

    /** Crée le compte actif avec son affectation et clôt l'invitation. */
    public function accepter(Invitation $invitation, ActivationCompteData $donnees): Utilisateur
    {
        $compte = new Utilisateur($invitation->getAssociation(), $invitation->getEmail(), (string) $donnees->prenom, (string) $donnees->nom, '', UtilisateurStatut::Actif);
        $compte->definirMotDePasse($this->hacheur->hashPassword($compte, (string) $donnees->motDePasse));
        $compte->definirCoordonnees($donnees->telephone, $donnees->consentEmail);
        $compte->affecter($invitation->getRole(), $invitation->getVille());
        $quand = new \DateTimeImmutable();
        $invitation->accepter($compte, $quand);

        // Une même personne invitée pour plusieurs rôles (par exemple trésorière et secrétaire d'une ville) les reçoit tous d'un coup.
        foreach ($this->invitations->enCoursPourEmail($invitation->getAssociation(), $invitation->getEmail()) as $autre) {
            if ($autre->getId() !== $invitation->getId()) {
                $compte->affecter($autre->getRole(), $autre->getVille());
                $autre->accepter($compte, $quand);
            }
        }

        $this->entityManager->persist($compte);
        if (Role::Membre === $invitation->getRole() && null !== $invitation->getVille()) {
            $this->rattacherFiche($invitation, $compte, $donnees);
        }
        $this->journal->consigner(TypeEvenement::CompteCree, $compte, $invitation->getAssociation(), $compte->getEmail(), ['role' => $invitation->getRole()->value], $quand);
        $this->journal->consigner(TypeEvenement::InvitationAcceptee, $compte, $invitation->getAssociation(), $compte->getEmail(), ['role' => $invitation->getRole()->value, 'envoyee_le' => $invitation->getEnvoyeeLe()->format(\DateTimeInterface::ATOM)], $quand);
        $this->entityManager->flush();

        return $compte;
    }

    /** Le bureau central souscrit l'offre choisie pour son association, puis son compte est créé comme pour toute invitation. */
    public function souscrire(Invitation $invitation, SouscriptionData $donnees): Utilisateur
    {
        $offre = Catalogue::offre((string) $donnees->offre);
        if (null === $offre) {
            throw new \InvalidArgumentException(\sprintf('Offre inconnue : « %s ».', $donnees->offre));
        }

        $abonnement = $invitation->getAssociation()->ouvrirAbonnement();
        $abonnement->souscrire($offre, new \DateTimeImmutable());
        $compte = $this->accepter($invitation, $donnees);

        $this->journal->consigner(TypeEvenement::AbonnementSouscrit, $compte, $invitation->getAssociation(), $offre->code, Journal::instantane($abonnement));
        $this->entityManager->flush();

        return $compte;
    }

    /**
     * La personne invitée demande un nouveau lien (maquette « Invitation expirée ») : la personne qui l'a invitée, ou à
     * défaut les comptes actifs du bureau central, reçoivent un e-mail pour la renvoyer. Au plus une demande par jour
     * et par invitation ; rend faux si une demande a déjà été faite dans les dernières 24 heures.
     */
    public function demanderNouveauLien(Invitation $invitation, \DateTimeImmutable $quand): bool
    {
        $deja = $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(\App\Entity\Evenement::class, 'e')
            ->andWhere('e.type = :type')
            ->andWhere('e.cible = :cible')
            ->andWhere('e.quand > :depuis')
            ->setParameter('type', TypeEvenement::InvitationRedemandee)
            ->setParameter('cible', $invitation->getEmail())
            ->setParameter('depuis', $quand->modify('-24 hours'))
            ->getQuery()
            ->getSingleScalarResult();
        if ((int) $deja > 0) {
            return false;
        }

        $destinataires = [];
        $invitePar = $invitation->getInviteePar();
        if (null !== $invitePar && $invitePar->estActif()) {
            $destinataires[] = $invitePar;
        } else {
            foreach ($this->entityManager->getRepository(Utilisateur::class)->findBy(['association' => $invitation->getAssociation()]) as $compte) {
                if ($compte->estActif() && $compte->aLeRole(Role::BureauCentral)) {
                    $destinataires[] = $compte;
                }
            }
        }
        $this->journal->consigner(TypeEvenement::InvitationRedemandee, null, $invitation->getAssociation(), $invitation->getEmail(), ['role' => $invitation->getRole()->value, 'ville' => $invitation->getVille()?->getNom()], $quand);
        $this->entityManager->flush();

        foreach ($destinataires as $destinataire) {
            $this->mailer->send((new TemplatedEmail())
                ->to(new Address($destinataire->getEmail(), $destinataire->getNomComplet()))
                ->subject($this->traducteur->trans('invitation.email.objet_redemande', ['email' => $invitation->getEmail()]))
                ->htmlTemplate('emails/invitation_redemandee.html.twig')
                ->textTemplate('emails/invitation_redemandee.txt.twig')
                ->context([
                    'invitation' => $invitation,
                    'destinataire' => $destinataire,
                    'lien' => $this->urls->generate('association_responsables', ['slug' => $invitation->getAssociation()->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                ]));
        }

        return true;
    }

    /**
     * Un membre invité par sa ville retrouve sa fiche : celle de la ville à son adresse, sans compte (import, saisie),
     * sinon une fiche active est créée (invitée par un responsable, elle n'a pas à être validée).
     */
    private function rattacherFiche(Invitation $invitation, Utilisateur $compte, ActivationCompteData $donnees): void
    {
        $ville = $invitation->getVille();
        \assert(null !== $ville);
        $fiche = $this->entityManager->getRepository(Membre::class)->findOneBy(['ville' => $ville, 'email' => $compte->getEmail(), 'compte' => null]);
        if (null === $fiche) {
            $fiche = new Membre($ville, (string) $donnees->prenom, (string) $donnees->nom, $compte->getEmail(), $donnees->telephone);
            $this->entityManager->persist($fiche);
        } elseif (null !== $donnees->telephone && '' !== trim($donnees->telephone) && null === $fiche->getTelephone()) {
            $fiche->modifier($fiche->getPrenom(), $fiche->getNom(), $fiche->getEmail(), $donnees->telephone);
        }
        $fiche->rattacherCompte($compte);
        $fiche->definirConsentementEmail($donnees->consentEmail);
    }

    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    private static function nouveauJeton(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function envoyer(Invitation $invitation, string $jeton): void
    {
        $souscription = $invitation->ouvreLaSouscription();
        $gabarit = $souscription ? 'souscription' : 'invitation';

        $this->mailer->send((new TemplatedEmail())
            ->to(new Address($invitation->getEmail()))
            ->subject($this->traducteur->trans($souscription ? 'invitation.email.objet_souscription' : 'invitation.email.objet', ['association' => $invitation->getAssociation()->getNom()]))
            ->htmlTemplate('emails/'.$gabarit.'.html.twig')
            ->textTemplate('emails/'.$gabarit.'.txt.twig')
            ->context([
                'invitation' => $invitation,
                'lien' => $this->urls->generate('invitation_activer', ['jeton' => $jeton], UrlGeneratorInterface::ABSOLUTE_URL),
                'offres' => Catalogue::offres(),
                'economie' => Catalogue::economieAnnuelle(),
                'joursPremierPaiement' => Abonnement::JOURS_PREMIER_PAIEMENT,
            ]));
    }
}
