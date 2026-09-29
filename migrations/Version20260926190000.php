<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assistant de création d'une ville, étape Identité (F-40) :
 * association (le tenant), ville en brouillon, invitations des responsables.
 * La table messenger_messages est créée ici car le transport Doctrine a auto_setup=0.
 */
final class Version20260926190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Association, Ville et InvitationResponsable (assistant de création d’une ville, étape Identité), plus messenger_messages.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration est écrite pour MySQL 8.');

        $this->addSql('CREATE TABLE association (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, slug VARCHAR(80) NOT NULL, cree_le DATETIME NOT NULL, UNIQUE INDEX UNIQ_FD8521CC989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ville (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, statut VARCHAR(20) NOT NULL, etape_assistant VARCHAR(20) NOT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, association_id INT NOT NULL, INDEX IDX_43C3D9C3EFB9C8A5 (association_id), UNIQUE INDEX uniq_ville_association_nom (association_id, nom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE invitation_responsable (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) NOT NULL, email VARCHAR(180) NOT NULL, token VARCHAR(64) DEFAULT NULL, envoyee_le DATETIME DEFAULT NULL, expire_le DATETIME DEFAULT NULL, acceptee_le DATETIME DEFAULT NULL, cree_le DATETIME NOT NULL, association_id INT NOT NULL, ville_id INT NOT NULL, UNIQUE INDEX UNIQ_5AA501075F37A13B (token), INDEX IDX_5AA50107EFB9C8A5 (association_id), INDEX IDX_5AA50107A73F0036 (ville_id), UNIQUE INDEX uniq_invitation_ville_role (ville_id, role), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ville ADD CONSTRAINT FK_43C3D9C3EFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id)');
        $this->addSql('ALTER TABLE invitation_responsable ADD CONSTRAINT FK_5AA50107EFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id)');
        $this->addSql('ALTER TABLE invitation_responsable ADD CONSTRAINT FK_5AA50107A73F0036 FOREIGN KEY (ville_id) REFERENCES ville (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration est écrite pour MySQL 8.');

        $this->addSql('ALTER TABLE invitation_responsable DROP FOREIGN KEY FK_5AA50107A73F0036');
        $this->addSql('ALTER TABLE invitation_responsable DROP FOREIGN KEY FK_5AA50107EFB9C8A5');
        $this->addSql('ALTER TABLE ville DROP FOREIGN KEY FK_43C3D9C3EFB9C8A5');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE invitation_responsable');
        $this->addSql('DROP TABLE ville');
        $this->addSql('DROP TABLE association');
    }
}
