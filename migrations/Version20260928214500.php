<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les paiements d'abonnement prennent le nom `paiement_abonnement` : le nom `Paiement` revient aux encaissements des
 * membres, comme dans le cahier des charges. Les lignes existantes sont conservées.
 */
final class Version20260928214500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renomme la table paiement (abonnements) en paiement_abonnement, sans perte de données.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1ECB5FDB3E');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EEFB9C8A5');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EF1D74413');
        $this->addSql('RENAME TABLE paiement TO paiement_abonnement');
        $this->addSql('ALTER TABLE paiement_abonnement RENAME INDEX IDX_B1DC7A1EF1D74413 TO IDX_A3DD064BF1D74413');
        $this->addSql('ALTER TABLE paiement_abonnement RENAME INDEX IDX_B1DC7A1EEFB9C8A5 TO IDX_A3DD064BEFB9C8A5');
        $this->addSql('ALTER TABLE paiement_abonnement RENAME INDEX IDX_B1DC7A1ECB5FDB3E TO IDX_A3DD064BCB5FDB3E');
        $this->addSql('ALTER TABLE paiement_abonnement RENAME INDEX idx_paiement_recu_le TO idx_paiement_abonnement_recu_le');
        $this->addSql('ALTER TABLE paiement_abonnement ADD CONSTRAINT FK_A3DD064BF1D74413 FOREIGN KEY (abonnement_id) REFERENCES abonnement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement_abonnement ADD CONSTRAINT FK_A3DD064BEFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement_abonnement ADD CONSTRAINT FK_A3DD064BCB5FDB3E FOREIGN KEY (enregistre_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE paiement_abonnement DROP FOREIGN KEY FK_A3DD064BF1D74413');
        $this->addSql('ALTER TABLE paiement_abonnement DROP FOREIGN KEY FK_A3DD064BEFB9C8A5');
        $this->addSql('ALTER TABLE paiement_abonnement DROP FOREIGN KEY FK_A3DD064BCB5FDB3E');
        $this->addSql('RENAME TABLE paiement_abonnement TO paiement');
        $this->addSql('ALTER TABLE paiement RENAME INDEX IDX_A3DD064BF1D74413 TO IDX_B1DC7A1EF1D74413');
        $this->addSql('ALTER TABLE paiement RENAME INDEX IDX_A3DD064BEFB9C8A5 TO IDX_B1DC7A1EEFB9C8A5');
        $this->addSql('ALTER TABLE paiement RENAME INDEX IDX_A3DD064BCB5FDB3E TO IDX_B1DC7A1ECB5FDB3E');
        $this->addSql('ALTER TABLE paiement RENAME INDEX idx_paiement_abonnement_recu_le TO idx_paiement_recu_le');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EF1D74413 FOREIGN KEY (abonnement_id) REFERENCES abonnement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EEFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1ECB5FDB3E FOREIGN KEY (enregistre_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }
}
