<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927123904 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal des événements (F-38) et historique des paiements d’abonnement ; reprise de l’existant : créations, connexions, invitations, état initial des abonnements, dernier paiement connu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE evenement (id INT AUTO_INCREMENT NOT NULL, quand DATETIME NOT NULL, type VARCHAR(40) NOT NULL, cible VARCHAR(180) DEFAULT NULL, details JSON DEFAULT NULL, acteur_id INT DEFAULT NULL, association_id INT DEFAULT NULL, INDEX idx_evenement_quand (quand), INDEX idx_evenement_type (type), INDEX IDX_B26681EDA6F574A (acteur_id), INDEX IDX_B26681EEFB9C8A5 (association_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE paiement (id INT AUTO_INCREMENT NOT NULL, montant INT NOT NULL, recu_le DATE NOT NULL, echeance_couverte DATE DEFAULT NULL, enregistre_le DATETIME NOT NULL, note VARCHAR(255) DEFAULT NULL, abonnement_id INT NOT NULL, association_id INT NOT NULL, enregistre_par_id INT DEFAULT NULL, INDEX idx_paiement_recu_le (recu_le), INDEX IDX_B1DC7A1EF1D74413 (abonnement_id), INDEX IDX_B1DC7A1EEFB9C8A5 (association_id), INDEX IDX_B1DC7A1ECB5FDB3E (enregistre_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE evenement ADD CONSTRAINT FK_B26681EDA6F574A FOREIGN KEY (acteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE evenement ADD CONSTRAINT FK_B26681EEFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EF1D74413 FOREIGN KEY (abonnement_id) REFERENCES abonnement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EEFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1ECB5FDB3E FOREIGN KEY (enregistre_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');

        // Reprise de l'existant, pour que les graphiques aient un passé.
        $this->addSql("INSERT INTO evenement (quand, type, cible, details, acteur_id, association_id) SELECT cree_le, 'association.creee', nom, NULL, NULL, id FROM association");
        $this->addSql("INSERT INTO evenement (quand, type, cible, details, acteur_id, association_id) SELECT cree_le, 'ville.creee', nom, NULL, NULL, association_id FROM ville");
        $this->addSql("INSERT INTO evenement (quand, type, cible, details, acteur_id, association_id) SELECT cree_le, 'compte.cree', email, NULL, id, association_id FROM utilisateur");
        $this->addSql("INSERT INTO evenement (quand, type, cible, details, acteur_id, association_id) SELECT derniere_connexion_le, 'connexion', email, NULL, id, association_id FROM utilisateur WHERE derniere_connexion_le IS NOT NULL");
        $this->addSql("INSERT INTO evenement (quand, type, cible, details, acteur_id, association_id) SELECT envoyee_le, 'invitation.envoyee', email, JSON_OBJECT('role', role), invitee_par_id, association_id FROM invitation");
        $this->addSql("INSERT INTO evenement (quand, type, cible, details, acteur_id, association_id) SELECT acceptee_le, 'invitation.acceptee', email, JSON_OBJECT('role', role), compte_id, association_id FROM invitation WHERE acceptee_le IS NOT NULL");
        $this->addSql("INSERT INTO evenement (quand, type, cible, details, acteur_id, association_id) SELECT debut_le, 'abonnement.initial', NULL, JSON_OBJECT('statut', statut, 'formule', formule, 'montant', montant, 'periodicite', periodicite, 'mensuel', CASE WHEN statut IN ('actif', 'en-retard') THEN (CASE WHEN periodicite = 'annuelle' THEN FLOOR(montant / 12) ELSE montant END) ELSE 0 END), NULL, association_id FROM abonnement");
        $this->addSql("INSERT INTO paiement (montant, recu_le, echeance_couverte, enregistre_le, note, abonnement_id, association_id, enregistre_par_id) SELECT montant, dernier_paiement_le, NULL, NOW(), 'Repris de l’ancien champ « dernier paiement »', id, association_id, NULL FROM abonnement WHERE dernier_paiement_le IS NOT NULL AND montant > 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evenement DROP FOREIGN KEY FK_B26681EDA6F574A');
        $this->addSql('ALTER TABLE evenement DROP FOREIGN KEY FK_B26681EEFB9C8A5');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EF1D74413');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EEFB9C8A5');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1ECB5FDB3E');
        $this->addSql('DROP TABLE evenement');
        $this->addSql('DROP TABLE paiement');
    }
}
