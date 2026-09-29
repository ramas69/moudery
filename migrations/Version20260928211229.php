<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928211229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dépenses (F-21 à F-23) : table depense.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE depense (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(12) NOT NULL, libelle VARCHAR(160) NOT NULL, montant INT NOT NULL, date_depense DATE NOT NULL, categorie VARCHAR(20) NOT NULL, beneficiaire VARCHAR(160) NOT NULL, moyen VARCHAR(12) NOT NULL, commentaire LONGTEXT NOT NULL, justificatif VARCHAR(120) DEFAULT NULL, justificatif_nom VARCHAR(200) DEFAULT NULL, justificatif_type VARCHAR(80) DEFAULT NULL, justificatif_taille INT DEFAULT NULL, statut VARCHAR(12) NOT NULL, saisie_le DATETIME NOT NULL, soumise_le DATETIME DEFAULT NULL, decision_le DATETIME DEFAULT NULL, motif_refus LONGTEXT DEFAULT NULL, payee_le DATETIME DEFAULT NULL, association_id INT NOT NULL, ville_id INT DEFAULT NULL, appel_id INT DEFAULT NULL, saisie_par_id INT DEFAULT NULL, decision_par_id INT DEFAULT NULL, payee_par_id INT DEFAULT NULL, INDEX idx_depense_association_statut (association_id, statut), UNIQUE INDEX uniq_depense_numero (association_id, numero), INDEX IDX_34059757EFB9C8A5 (association_id), INDEX IDX_34059757A73F0036 (ville_id), INDEX IDX_34059757270B0E02 (appel_id), INDEX IDX_34059757C74AC7FE (saisie_par_id), INDEX IDX_340597573F7F7BC9 (decision_par_id), INDEX IDX_3405975768BD7452 (payee_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757EFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757A73F0036 FOREIGN KEY (ville_id) REFERENCES ville (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757270B0E02 FOREIGN KEY (appel_id) REFERENCES appel_contribution (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757C74AC7FE FOREIGN KEY (saisie_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_340597573F7F7BC9 FOREIGN KEY (decision_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_3405975768BD7452 FOREIGN KEY (payee_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_34059757EFB9C8A5');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_34059757A73F0036');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_34059757270B0E02');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_34059757C74AC7FE');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_340597573F7F7BC9');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_3405975768BD7452');
        $this->addSql('DROP TABLE depense');
    }
}
