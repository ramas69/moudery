<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Association : statut (active, suspendue, archivée), identité (village, contact, RNA, SIREN)
 * et paramètres de gestion (exercice, reversement par défaut, relances, compte Stripe du central).
 */
final class Version20260927090649 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Association : statut, identité et paramètres de gestion.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration est écrite pour MySQL 8.');

        $this->addSql('ALTER TABLE association ADD statut VARCHAR(20) DEFAULT \'active\' NOT NULL, ADD village VARCHAR(80) DEFAULT NULL, ADD email_contact VARCHAR(180) DEFAULT NULL, ADD telephone_contact VARCHAR(30) DEFAULT NULL, ADD adresse_siege LONGTEXT DEFAULT NULL, ADD numero_rna VARCHAR(10) DEFAULT NULL, ADD siren VARCHAR(9) DEFAULT NULL, ADD debut_exercice_mois SMALLINT DEFAULT 1 NOT NULL, ADD taux_reversement_defaut SMALLINT DEFAULT 0 NOT NULL, ADD calendrier_relances JSON DEFAULT NULL, ADD compte_stripe VARCHAR(64) DEFAULT NULL');
        // Une colonne JSON n'a pas de valeur par défaut en MySQL : les associations existantes reçoivent le calendrier standard, puis la colonne devient obligatoire.
        $this->addSql('UPDATE association SET calendrier_relances = \'[-7, 0, 15]\' WHERE calendrier_relances IS NULL');
        $this->addSql('ALTER TABLE association MODIFY calendrier_relances JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration est écrite pour MySQL 8.');

        $this->addSql('ALTER TABLE association DROP statut, DROP village, DROP email_contact, DROP telephone_contact, DROP adresse_siege, DROP numero_rna, DROP siren, DROP debut_exercice_mois, DROP taux_reversement_defaut, DROP calendrier_relances, DROP compte_stripe');
    }
}
