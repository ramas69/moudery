<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Types de contribution (F-11) : un type s'archive au lieu de se supprimer. */
final class Version20260928215812 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Types de contribution : colonne actif (archivage sans suppression).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE type_contribution ADD actif TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE type_contribution DROP actif');
    }
}
