<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928183738 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE appel_contribution (id INT AUTO_INCREMENT NOT NULL, objet VARCHAR(160) NOT NULL, message LONGTEXT NOT NULL, mode VARCHAR(12) NOT NULL, montant INT DEFAULT NULL, date_limite DATE NOT NULL, taux_reversement SMALLINT NOT NULL, relances_auto TINYINT NOT NULL, statut VARCHAR(12) NOT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, ouvert_le DATETIME DEFAULT NULL, association_id INT NOT NULL, ville_id INT DEFAULT NULL, type_id INT NOT NULL, cree_par_id INT DEFAULT NULL, INDEX idx_appel_association_statut (association_id, statut), INDEX IDX_BCDB13C4EFB9C8A5 (association_id), INDEX IDX_BCDB13C4A73F0036 (ville_id), INDEX IDX_BCDB13C4C54C8C93 (type_id), INDEX IDX_BCDB13C4FC29C013 (cree_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE echeance (id INT AUTO_INCREMENT NOT NULL, montant INT DEFAULT NULL, date_limite DATE NOT NULL, statut VARCHAR(12) NOT NULL, cree_le DATETIME NOT NULL, association_id INT NOT NULL, appel_id INT NOT NULL, membre_id INT NOT NULL, UNIQUE INDEX uniq_echeance_appel_membre (appel_id, membre_id), INDEX IDX_40D9893BEFB9C8A5 (association_id), INDEX IDX_40D9893B270B0E02 (appel_id), INDEX IDX_40D9893B6A99F74A (membre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE type_contribution (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(40) NOT NULL, nom VARCHAR(80) NOT NULL, unite VARCHAR(12) NOT NULL, mode VARCHAR(12) NOT NULL, montant_defaut INT DEFAULT NULL, taux_reversement SMALLINT DEFAULT NULL, ordre SMALLINT NOT NULL, cree_le DATETIME NOT NULL, association_id INT NOT NULL, UNIQUE INDEX uniq_type_contribution_code (association_id, code), INDEX IDX_6D76A410EFB9C8A5 (association_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE appel_contribution ADD CONSTRAINT FK_BCDB13C4EFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE appel_contribution ADD CONSTRAINT FK_BCDB13C4A73F0036 FOREIGN KEY (ville_id) REFERENCES ville (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE appel_contribution ADD CONSTRAINT FK_BCDB13C4C54C8C93 FOREIGN KEY (type_id) REFERENCES type_contribution (id)');
        $this->addSql('ALTER TABLE appel_contribution ADD CONSTRAINT FK_BCDB13C4FC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE echeance ADD CONSTRAINT FK_40D9893BEFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE echeance ADD CONSTRAINT FK_40D9893B270B0E02 FOREIGN KEY (appel_id) REFERENCES appel_contribution (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE echeance ADD CONSTRAINT FK_40D9893B6A99F74A FOREIGN KEY (membre_id) REFERENCES membre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE type_contribution ADD CONSTRAINT FK_6D76A410EFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE appel_contribution DROP FOREIGN KEY FK_BCDB13C4EFB9C8A5');
        $this->addSql('ALTER TABLE appel_contribution DROP FOREIGN KEY FK_BCDB13C4A73F0036');
        $this->addSql('ALTER TABLE appel_contribution DROP FOREIGN KEY FK_BCDB13C4C54C8C93');
        $this->addSql('ALTER TABLE appel_contribution DROP FOREIGN KEY FK_BCDB13C4FC29C013');
        $this->addSql('ALTER TABLE echeance DROP FOREIGN KEY FK_40D9893BEFB9C8A5');
        $this->addSql('ALTER TABLE echeance DROP FOREIGN KEY FK_40D9893B270B0E02');
        $this->addSql('ALTER TABLE echeance DROP FOREIGN KEY FK_40D9893B6A99F74A');
        $this->addSql('ALTER TABLE type_contribution DROP FOREIGN KEY FK_6D76A410EFB9C8A5');
        $this->addSql('DROP TABLE appel_contribution');
        $this->addSql('DROP TABLE echeance');
        $this->addSql('DROP TABLE type_contribution');
    }
}
