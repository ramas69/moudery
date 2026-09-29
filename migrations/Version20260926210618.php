<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Super-admin plateforme : un compte et son affectation peuvent n'appartenir à aucune association. */
final class Version20260926210618 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Association facultative sur utilisateur et affectation (super-admin plateforme).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration est écrite pour MySQL 8.');

        $this->addSql('ALTER TABLE affectation CHANGE association_id association_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE utilisateur CHANGE association_id association_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration est écrite pour MySQL 8.');

        $this->addSql('ALTER TABLE affectation CHANGE association_id association_id INT NOT NULL');
        $this->addSql('ALTER TABLE utilisateur CHANGE association_id association_id INT NOT NULL');
    }
}
