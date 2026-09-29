<?php

declare(strict_types=1);

namespace App\Ville;

use App\Entity\EtapeAssistant;
use App\Entity\Foyer;
use App\Entity\Membre;
use App\Repository\MembreRepository;
use App\Repository\EcheanceRepository;
use App\Cotisation\Cotisations;
use App\Entity\MembreStatut;
use App\Entity\TypeEvenement;
use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Form\Model\MembreData;
use App\Journal\Journal;
use App\Repository\FoyerRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Les membres d'une ville dans l'assistant (F-42) : ajout à la main, retrait, foyers, fin de l'étape. */
final class Membres
{
    public function __construct(
        private readonly MembreRepository $membres,
        private readonly EcheanceRepository $echeances,
        private readonly Cotisations $cotisations,
        private readonly EntityManagerInterface $entityManager,
        private readonly FoyerRepository $foyers,
        private readonly Journal $journal,
    ) {
    }

    public function ajouter(Ville $ville, MembreData $donnees, ?Utilisateur $par = null): Membre
    {
        $membre = new Membre($ville, (string) $donnees->prenom, (string) $donnees->nom, $donnees->email, $donnees->telephone, MembreStatut::Actif, Membre::ORIGINE_SAISIE);
        $membre->definirLocalite($donnees->localite);
        $membre->definirAnneeNaissance($donnees->anneeNaissance);
        // Ajouté à la main aujourd'hui, le membre est adhérent de l'année en cours.
        $membre->adherer((int) date('Y'), Membre::ORIGINE_SAISIE);
        $nouveauFoyer = Membre::normaliserNom((string) $donnees->nouveauFoyer);
        if ('' !== $nouveauFoyer) {
            $membre->rejoindreFoyer($this->foyerPour($ville, $nouveauFoyer));
        } elseif (null !== $donnees->foyer) {
            $membre->rejoindreFoyer($donnees->foyer);
        }

        $this->entityManager->persist($membre);
        $this->journal->consigner(TypeEvenement::MembreAjoute, $par, $ville->getAssociation(), $membre->getNomComplet(), ['ville' => $ville->getNom()]);
        $this->entityManager->flush();

        return $membre;
    }

    /** Modifie l'identité, le contact, la localité, l'année de naissance et le foyer ; consigné avec l'avant et l'après. */
    public function modifier(Membre $membre, MembreData $donnees, ?Utilisateur $par = null): void
    {
        $avant = self::instantane($membre);
        $membre->modifier((string) $donnees->prenom, (string) $donnees->nom, $donnees->email, $donnees->telephone);
        $membre->definirLocalite($donnees->localite);
        $membre->definirAnneeNaissance($donnees->anneeNaissance);
        $nouveauFoyer = Membre::normaliserNom((string) $donnees->nouveauFoyer);
        if ('' !== $nouveauFoyer) {
            $membre->rejoindreFoyer($this->foyerPour($membre->getVille(), $nouveauFoyer));
        } else {
            $membre->rejoindreFoyer($donnees->foyer);
        }
        $apres = self::instantane($membre);
        if ($avant !== $apres) {
            $this->journal->consigner(TypeEvenement::MembreModifie, $par, $membre->getAssociation(), $membre->getNomComplet(), ['ville' => $membre->getVille()->getNom(), 'avant' => $avant, 'apres' => $apres]);
        }
        $this->entityManager->flush();
    }

    /** Valide d'un coup toutes les inscriptions en attente de la ville ; rend le nombre de fiches passées actives. */
    public function validerEnAttente(Ville $ville, ?Utilisateur $par = null): int
    {
        $nombre = 0;
        foreach ($ville->getMembres() as $membre) {
            if (MembreStatut::EnAttente !== $membre->getStatut()) {
                continue;
            }
            $membre->valider();
            $this->journal->consigner(TypeEvenement::MembreStatut, $par, $ville->getAssociation(), $membre->getNomComplet(), ['ville' => $ville->getNom(), 'statut' => MembreStatut::Actif->value]);
            ++$nombre;
        }
        $this->entityManager->flush();

        return $nombre;
    }

    /** Sorti (quitte son foyer, ne reçoit plus d'échéance) ou de nouveau actif (une fiche en attente est validée). */
    public function changerStatut(Membre $membre, MembreStatut $statut, ?Utilisateur $par = null): void
    {
        if ($statut === $membre->getStatut()) {
            return;
        }
        match ($statut) {
            MembreStatut::Sorti => $membre->sortir(),
            MembreStatut::Actif => $membre->valider(),
            MembreStatut::EnAttente => throw new \LogicException('Une fiche ne repasse pas en attente.'),
        };
        $this->journal->consigner(TypeEvenement::MembreStatut, $par, $membre->getAssociation(), $membre->getNomComplet(), ['ville' => $membre->getVille()->getNom(), 'statut' => $statut->value]);
        $this->entityManager->flush();
    }

    /**
     * Transfert vers une autre ville (F-10) : l'historique reste à l'ancienne caisse, les mensualités encore dues de
     * l'ancienne ville sont annulées, et si la nouvelle ville a ouvert ses cotisations de l'année, le membre y reçoit
     * ses mensualités. Refusé si l'adresse est déjà prise dans la ville d'arrivée.
     */
    public function transferer(Membre $membre, Ville $villeCible, ?Utilisateur $par = null): void
    {
        $email = $membre->getEmail();
        if (null !== $email && $this->membres->emailPris($villeCible, $email)) {
            throw new \LogicException('Cette adresse est déjà celle d\'un membre de la ville d\'arrivée.');
        }
        $origine = $membre->getVille();
        foreach ($this->echeances->duesPourMembre($membre) as $echeance) {
            if (null !== $echeance->getCotisation()) {
                $echeance->annuler();
            }
        }
        $membre->transfererVers($villeCible);
        // La génération relit les membres de la ville en base : le changement de ville doit être écrit avant.
        $this->entityManager->flush();
        $cotisation = $this->cotisations->pour($villeCible, (int) date('Y'));
        if (null !== $cotisation && $cotisation->estOuverte() && $membre->estActif()) {
            $this->cotisations->generer($cotisation, new \DateTimeImmutable());
        }
        $this->journal->consigner(TypeEvenement::MembreTransfere, $par, $membre->getAssociation(), $membre->getNomComplet(), ['de' => $origine->getNom(), 'vers' => $villeCible->getNom()]);
        $this->entityManager->flush();
    }

    /** @return array<string, mixed> */
    private static function instantane(Membre $membre): array
    {
        return [
            'prenom' => $membre->getPrenom(),
            'nom' => $membre->getNom(),
            'email' => $membre->getEmail(),
            'telephone' => $membre->getTelephoneAffiche(),
            'localite' => $membre->getLocalite(),
            'annee_naissance' => $membre->getAnneeNaissance(),
            'foyer' => $membre->getFoyer()?->getNom(),
        ];
    }

    /** Retire la fiche ; tant qu'aucun paiement n'existe, une fiche se supprime vraiment. */
    public function retirer(Membre $membre, ?Utilisateur $par = null): void
    {
        $ville = $membre->getVille();
        $membre->rejoindreFoyer(null);
        $this->journal->consigner(TypeEvenement::MembreRetire, $par, $ville->getAssociation(), $membre->getNomComplet(), ['ville' => $ville->getNom()]);
        $this->entityManager->remove($membre);
        $this->entityManager->flush();
    }

    /** L'étape 2 est terminée : l'assistant reprendra à l'activation. */
    public function terminerEtape(Ville $ville): void
    {
        $ville->avancerA(EtapeAssistant::Activation);
        $this->entityManager->flush();
    }

    /** Le foyer de ce nom dans la ville, créé s'il n'existe pas encore (persisté, pas encore flushé). */
    public function foyerPour(Ville $ville, string $nom): Foyer
    {
        $nom = Membre::normaliserNom($nom);
        $foyer = $this->foyers->trouverParNom($ville, $nom);
        if (null === $foyer) {
            foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $enAttente) {
                if ($enAttente instanceof Foyer && $enAttente->getVille() === $ville && $enAttente->getNom() === $nom) {
                    return $enAttente;
                }
            }
            $foyer = new Foyer($ville, $nom);
            $this->entityManager->persist($foyer);
        }

        return $foyer;
    }
}
