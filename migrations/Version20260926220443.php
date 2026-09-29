<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Invitations à créer son compte (F-02) : un rôle sur un périmètre, un lien de 7 jours à usage unique. */
final class Version20260926220443 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invitation (compte à créer par email, lien de 7 jours à usage unique).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration est écrite pour MySQL 8.');

        $this->addSql('CREATE TABLE invitation (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) NOT NULL, email VARCHAR(180) NOT NULL, jeton VARCHAR(64) DEFAULT NULL, envoyee_le DATETIME NOT NULL, expire_le DATETIME NOT NULL, acceptee_le DATETIME DEFAULT NULL, cree_le DATETIME NOT NULL, association_id INT NOT NULL, ville_id INT DEFAULT NULL, compte_id INT DEFAULT NULL, invitee_par_id INT DEFAULT NULL, UNIQUE INDEX uniq_invitation_jeton (jeton), INDEX IDX_F11D61A2EFB9C8A5 (association_id), INDEX IDX_F11D61A2A73F0036 (ville_id), INDEX IDX_F11D61A2F2C56620 (compte_id), INDEX IDX_F11D61A2B44DA268 (invitee_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2EFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id)');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2A73F0036 FOREIGN KEY (ville_id) REFERENCES ville (id)');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2F2C56620 FOREIGN KEY (compte_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2B44DA268 FOREIGN KEY (invitee_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration est écrite pour MySQL 8.');

        $this->addSql('ALTER TABLE invitation DROP FOREIGN KEY FK_F11D61A2EFB9C8A5');
        $this->addSql('ALTER TABLE invitation DROP FOREIGN KEY FK_F11D61A2A73F0036');
        $this->addSql('ALTER TABLE invitation DROP FOREIGN KEY FK_F11D61A2F2C56620');
        $this->addSql('ALTER TABLE invitation DROP FOREIGN KEY FK_F11D61A2B44DA268');
        $this->addSql('DROP TABLE invitation');
    }
}
