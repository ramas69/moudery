<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** La ligne du classeur de la ville sur chaque adhésion : les douze mois, le rapatriement, le projet, le tarif déduit (F-45). */
final class Version20260927172949 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Historique du classeur sur les adhésions : mois (JSON), rapatriement, projet, tarif mensuel.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE adhesion ADD mois JSON DEFAULT NULL, ADD rapatriement INT DEFAULT NULL, ADD projet INT DEFAULT NULL, ADD tarif_mensuel INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE adhesion DROP mois, DROP rapatriement, DROP projet, DROP tarif_mensuel');
    }
}
