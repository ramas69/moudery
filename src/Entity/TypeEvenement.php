<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Ce que le journal sait consigner (F-38, traçabilité). La valeur est stable : elle sert de clé de traduction
 * (journal.type.*) et de critère de recherche ; la catégorie regroupe les types pour les statistiques.
 */
enum TypeEvenement: string
{
    case Connexion = 'connexion';
    case CompteCree = 'compte.cree';
    case InvitationEnvoyee = 'invitation.envoyee';
    case InvitationAcceptee = 'invitation.acceptee';
    case InvitationRedemandee = 'invitation.redemandee';
    case CompteModifie = 'compte.modifie';
    case CompteStatut = 'compte.statut';
    case CompteLienMotDePasse = 'compte.lien_mot_de_passe';
    case RoleAttribue = 'role.attribue';
    case RoleRetire = 'role.retire';
    case CompteVuComme = 'compte.vu_comme';
    case CompteMotDePasseChange = 'compte.mot_de_passe_change';
    case AssociationCreee = 'association.creee';
    case AssociationStatut = 'association.statut';
    case AssociationParametres = 'association.parametres';
    case VilleCreee = 'ville.creee';
    case VilleStatut = 'ville.statut';
    case MembreAjoute = 'membre.ajoute';
    case MembresImportes = 'membre.importes';
    case MembreRetire = 'membre.retire';
    case MembreModifie = 'membre.modifie';
    case MembreStatut = 'membre.statut';
    case MembreTransfere = 'membre.transfere';
    case AbonnementInitial = 'abonnement.initial';
    case AbonnementSouscrit = 'abonnement.souscrit';
    case AbonnementModifie = 'abonnement.modifie';
    case AbonnementPaye = 'abonnement.paye';
    case AppelEnregistre = 'appel.enregistre';
    case AppelLance = 'appel.lance';
    case TypeContributionModifie = 'contribution.type';
    case DepenseSoumise = 'depense.soumise';
    case DepenseValidee = 'depense.validee';
    case DepenseRefusee = 'depense.refusee';
    case DepensePayee = 'depense.payee';
    case DepenseSignalee = 'depense.signalee';
    case CotisationOuverte = 'cotisation.ouverte';
    case CotisationModifiee = 'cotisation.modifiee';
    case PaiementEnregistre = 'paiement.enregistre';
    case PaiementAnnule = 'paiement.annule';
    case RelanceEnvoyee = 'relance.envoyee';
    case MembreAppele = 'relance.appel';
    case ReversementDeclare = 'reversement.declare';
    case ReversementConfirme = 'reversement.confirme';

    public function categorie(): string
    {
        return match ($this) {
            self::Connexion => 'connexion',
            self::CompteCree, self::InvitationEnvoyee, self::InvitationAcceptee, self::InvitationRedemandee,
            self::CompteModifie, self::CompteStatut, self::CompteLienMotDePasse, self::RoleAttribue, self::RoleRetire, self::CompteVuComme, self::CompteMotDePasseChange => 'compte',
            self::AssociationCreee, self::AssociationStatut, self::AssociationParametres => 'association',
            self::VilleCreee, self::VilleStatut => 'ville',
            self::MembreAjoute, self::MembresImportes, self::MembreRetire, self::MembreModifie, self::MembreStatut, self::MembreTransfere => 'membre',
            self::AbonnementInitial, self::AbonnementSouscrit, self::AbonnementModifie, self::AbonnementPaye => 'abonnement',
            self::AppelEnregistre, self::AppelLance, self::TypeContributionModifie => 'appel',
            self::DepenseSoumise, self::DepenseValidee, self::DepenseRefusee, self::DepensePayee, self::DepenseSignalee => 'depense',
            self::CotisationOuverte, self::CotisationModifiee => 'cotisation',
            self::PaiementEnregistre, self::PaiementAnnule => 'paiement',
            self::RelanceEnvoyee, self::MembreAppele => 'relance',
            self::ReversementDeclare, self::ReversementConfirme => 'reversement',
        };
    }

    /** Les événements d'abonnement portent un instantané (statut, montant, périodicité) qui permet de retracer le revenu. */
    public function concerneUnAbonnement(): bool
    {
        return 'abonnement' === $this->categorie();
    }

    /** @return list<self> */
    public static function abonnements(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type->concerneUnAbonnement()));
    }
}
