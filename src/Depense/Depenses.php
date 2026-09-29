<?php

declare(strict_types=1);

namespace App\Depense;

use App\Entity\Association;
use App\Entity\Depense;
use App\Entity\DepenseStatut;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Form\Model\DepenseData;
use App\Journal\Journal;
use App\Repository\AppelContributionRepository;
use App\Repository\DepenseRepository;
use App\Repository\UtilisateurRepository;
use App\Security\Permission;
use App\Security\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les dépenses de la caisse du bureau central (F-21 à F-23) : saisie avec justificatif, soumission, validation ou refus
 * par une autre personne du bureau central, paiement. Chaque étape est consignée au journal et notifiée par e-mail
 * (soumise : aux autres membres du bureau central ; validée ou refusée : à la personne qui l'a saisie). Les justificatifs
 * sont rangés hors du dossier public, un sous-dossier par association, sous un nom aléatoire.
 */
final class Depenses
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DepenseRepository $depenses,
        private readonly AppelContributionRepository $appels,
        private readonly UtilisateurRepository $utilisateurs,
        private readonly Journal $journal,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $traducteur,
        #[Autowire('%kernel.project_dir%/var/justificatifs/%kernel.environment%')]
        private readonly string $dossier,
    ) {
    }

    /** Une dépense de la caisse du bureau central (ville null) ou de la caisse d'une ville. */
    public function nouvelle(Association $association, ?Utilisateur $acteur, \DateTimeImmutable $quand, ?Ville $ville = null): Depense
    {
        return new Depense($association, $ville, $this->depenses->prochainNumero($association, (int) $quand->format('Y')), $acteur, $quand);
    }

    /** Enregistre la saisie (et le justificatif déposé) ; si demandé, soumet aussitôt pour validation. */
    public function enregistrer(Depense $depense, DepenseData $donnees, bool $soumettre, ?Utilisateur $acteur, \DateTimeImmutable $quand): void
    {
        $appel = null;
        if (null !== $donnees->appel && '' !== $donnees->appel) {
            $appel = $this->appels->find((int) $donnees->appel);
            if (null === $appel || $appel->getAssociation() !== $depense->getAssociation()) {
                $appel = null;
            }
        }
        $depense->definir((string) $donnees->libelle, $donnees->montantEnCentimes(), $donnees->date ?? $quand, $donnees->categorie, $appel, (string) $donnees->beneficiaire, $donnees->moyen, (string) $donnees->commentaire);
        if ($donnees->justificatif instanceof UploadedFile) {
            $this->ranger($depense, $donnees->justificatif);
        }
        if (null === $depense->getId()) {
            $this->em->persist($depense);
        }
        if ($soumettre) {
            $depense->soumettre($quand);
            $this->journal->consigner(TypeEvenement::DepenseSoumise, $acteur, $depense->getAssociation(), $depense->getLibelle(), self::details($depense), $quand);
        }
        $this->em->flush();
        if ($soumettre) {
            foreach ($this->valideurs($depense) as $valideur) {
                $this->notifier($valideur, $depense, 'depense_soumise', 'depenses_association.email.objet_soumise');
            }
        }
    }

    public function valider(Depense $depense, Utilisateur $valideur, \DateTimeImmutable $quand): void
    {
        $depense->valider($valideur, $quand);
        $this->journal->consigner(TypeEvenement::DepenseValidee, $valideur, $depense->getAssociation(), $depense->getLibelle(), self::details($depense), $quand);
        $this->em->flush();
        $this->notifierAuteur($depense, 'depenses_association.email.objet_validee');
    }

    public function refuser(Depense $depense, Utilisateur $valideur, string $motif, \DateTimeImmutable $quand): void
    {
        $depense->refuser($valideur, $motif, $quand);
        $this->journal->consigner(TypeEvenement::DepenseRefusee, $valideur, $depense->getAssociation(), $depense->getLibelle(), [...self::details($depense), 'motif' => $depense->getMotifRefus()], $quand);
        $this->em->flush();
        $this->notifierAuteur($depense, 'depenses_association.email.objet_refusee');
    }

    public function marquerPayee(Depense $depense, Utilisateur $personne, \DateTimeImmutable $quand): void
    {
        $depense->marquerPayee($personne, $quand);
        $this->journal->consigner(TypeEvenement::DepensePayee, $personne, $depense->getAssociation(), $depense->getLibelle(), self::details($depense), $quand);
        $this->em->flush();
    }

    /**
     * Les personnes qui peuvent valider cette dépense : pour la caisse du central, les autres comptes actifs du bureau
     * central ; pour une ville, les comptes actifs qui valident les dépenses de cette ville (le président), sauf l'auteur.
     *
     * @return list<Utilisateur>
     */
    public function valideurs(Depense $depense): array
    {
        $ville = $depense->getVille();

        return array_values(array_filter(
            $this->utilisateurs->listerPourAssociation($depense->getAssociation()),
            static fn (Utilisateur $u): bool => $u->estActif() && !$depense->aEteSaisiePar($u)
                && (null === $ville ? $u->aLeRole(Role::BureauCentral) : $u->aLaPermission(Permission::DEPENSE_VALIDER, $ville)),
        ));
    }

    /**
     * Une dépense de ville soumise sans président pour la valider : son auteur prévient le bureau central, qui peut
     * nommer un président. Un e-mail à chaque compte actif du bureau central, consigné au journal.
     */
    public function prevenirLeCentral(Depense $depense, Utilisateur $auteur, \DateTimeImmutable $quand): int
    {
        $destinataires = array_values(array_filter(
            $this->utilisateurs->listerPourAssociation($depense->getAssociation()),
            static fn (Utilisateur $u): bool => $u->estActif() && $u->aLeRole(Role::BureauCentral),
        ));
        foreach ($destinataires as $destinataire) {
            $this->notifier($destinataire, $depense, 'depense_sans_valideur', 'depenses_association.email.objet_sans_valideur');
        }
        $this->journal->consigner(TypeEvenement::DepenseSignalee, $auteur, $depense->getAssociation(), $depense->getLibelle(), [...self::details($depense), 'destinataires' => \count($destinataires)], $quand);
        $this->em->flush();

        return \count($destinataires);
    }

    public function cheminJustificatif(Depense $depense): ?string
    {
        $chemin = $depense->getJustificatif();

        return null === $chemin ? null : $this->dossier.'/'.$chemin;
    }

    /**
     * Les chiffres de la page : à valider, validées à payer, payées sur l'exercice, brouillons.
     *
     * @param list<Depense> $depenses
     *
     * @return array{aValider: int, aValiderMontant: int, aPayer: int, aPayerMontant: int, payees: int, payeesMontant: int, brouillons: int}
     */
    public static function synthese(array $depenses): array
    {
        $synthese = ['aValider' => 0, 'aValiderMontant' => 0, 'aPayer' => 0, 'aPayerMontant' => 0, 'payees' => 0, 'payeesMontant' => 0, 'brouillons' => 0];
        foreach ($depenses as $depense) {
            match ($depense->getStatut()) {
                DepenseStatut::Soumise => [++$synthese['aValider'], $synthese['aValiderMontant'] += $depense->getMontant()],
                DepenseStatut::Validee => [++$synthese['aPayer'], $synthese['aPayerMontant'] += $depense->getMontant()],
                DepenseStatut::Payee => [++$synthese['payees'], $synthese['payeesMontant'] += $depense->getMontant()],
                DepenseStatut::Brouillon, DepenseStatut::Refusee => ++$synthese['brouillons'],
            };
        }

        return $synthese;
    }

    private function ranger(Depense $depense, UploadedFile $fichier): void
    {
        $sousDossier = (string) $depense->getAssociation()->getId();
        $dossier = $this->dossier.'/'.$sousDossier;
        if (!is_dir($dossier) && !mkdir($dossier, 0770, true) && !is_dir($dossier)) {
            throw new \RuntimeException(\sprintf('Impossible de créer le dossier « %s ».', $dossier));
        }
        $type = (string) $fichier->getMimeType();
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$type] ?? 'bin';
        $nom = bin2hex(random_bytes(16)).'.'.$extension;
        $taille = (int) $fichier->getSize();
        $origine = mb_substr($fichier->getClientOriginalName(), 0, 200);
        $fichier->move($dossier, $nom);
        $ancien = $this->cheminJustificatif($depense);
        $depense->joindreJustificatif($sousDossier.'/'.$nom, '' !== $origine ? $origine : $nom, $type, $taille);
        if (null !== $ancien && is_file($ancien)) {
            unlink($ancien);
        }
    }

    private function notifierAuteur(Depense $depense, string $objet): void
    {
        $auteur = $depense->getSaisiePar();
        if (null !== $auteur && $auteur->estActif()) {
            $this->notifier($auteur, $depense, 'depense_decision', $objet);
        }
    }

    private function notifier(Utilisateur $destinataire, Depense $depense, string $gabarit, string $objet): void
    {
        $this->mailer->send((new TemplatedEmail())
            ->to(new Address($destinataire->getEmail(), $destinataire->getNomComplet()))
            ->subject($this->traducteur->trans($objet, ['libelle' => $depense->getLibelle(), 'numero' => $depense->getNumero()]))
            ->htmlTemplate('emails/'.$gabarit.'.html.twig')
            ->textTemplate('emails/'.$gabarit.'.txt.twig')
            ->context([
                'depense' => $depense,
                'destinataire' => $destinataire,
                'lien' => $this->urls->generate('association_depense', ['slug' => $depense->getAssociation()->getSlug(), 'id' => $depense->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
    }

    /** @return array<string, mixed> */
    private static function details(Depense $depense): array
    {
        return ['numero' => $depense->getNumero(), 'montant' => $depense->getMontant(), 'ville' => $depense->getVille()?->getNom(), 'categorie' => $depense->getCategorie()->value];
    }
}
