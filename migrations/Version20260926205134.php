<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Authentification (M1, F-03) et affectations (F-07) :
 * comptes de connexion (utilisateur) et rôles attribués sur un périmètre (affectation).
 */
final class Version20260926205134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Utilisateur (compte de connexion) et Affectation (rôle sur une association ou une ville).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration est écrite pour MySQL 8.');

        $this->addSql('CREATE TABLE affectation (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) NOT NULL, cree_le DATETIME NOT NULL, association_id INT NOT NULL, utilisateur_id INT NOT NULL, ville_id INT DEFAULT NULL, INDEX IDX_F4DD61D3EFB9C8A5 (association_id), INDEX IDX_F4DD61D3FB88E14F (utilisateur_id), INDEX IDX_F4DD61D3A73F0036 (ville_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE utilisateur (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, prenom VARCHAR(80) NOT NULL, nom VARCHAR(80) NOT NULL, mot_de_passe VARCHAR(255) NOT NULL, statut VARCHAR(20) NOT NULL, jeton_reinitialisation VARCHAR(64) DEFAULT NULL, jeton_reinitialisation_expire_le DATETIME DEFAULT NULL, cree_le DATETIME NOT NULL, derniere_connexion_le DATETIME DEFAULT NULL, association_id INT NOT NULL, UNIQUE INDEX uniq_utilisateur_email (email), INDEX IDX_1D1C63B3EFB9C8A5 (association_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE affectation ADD CONSTRAINT FK_F4DD61D3EFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id)');
        $this->addSql('ALTER TABLE affectation ADD CONSTRAINT FK_F4DD61D3FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE affectation ADD CONSTRAINT FK_F4DD61D3A73F0036 FOREIGN KEY (ville_id) REFERENCES ville (id)');
        $this->addSql('ALTER TABLE utilisateur ADD CONSTRAINT FK_1D1C63B3EFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration est écrite pour MySQL 8.');

        $this->addSql('ALTER TABLE affectation DROP FOREIGN KEY FK_F4DD61D3EFB9C8A5');
        $this->addSql('ALTER TABLE affectation DROP FOREIGN KEY FK_F4DD61D3FB88E14F');
        $this->addSql('ALTER TABLE affectation DROP FOREIGN KEY FK_F4DD61D3A73F0036');
        $this->addSql('ALTER TABLE utilisateur DROP FOREIGN KEY FK_1D1C63B3EFB9C8A5');
        $this->addSql('DROP TABLE affectation');
        $this->addSql('DROP TABLE utilisateur');
    }
}
