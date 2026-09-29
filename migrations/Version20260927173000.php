<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** L'assistant de création d'une ville passe de sept à trois étapes : identité, membres, activation. */
final class Version20260927173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assistant de création d’une ville en trois étapes : reprise des points de reprise existants.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE ville SET etape_assistant = 'membres' WHERE etape_assistant = 'compte-bancaire'");
        $this->addSql("UPDATE ville SET etape_assistant = 'activation' WHERE etape_assistant IN ('cotisations', 'projets', 'historique', 'recapitulatif')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE ville SET etape_assistant = 'recapitulatif' WHERE etape_assistant = 'activation'");
    }
}
