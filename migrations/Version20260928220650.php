<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cotisations périodiques (F-12) et paiements manuels (F-17) : tables `cotisation` et `paiement` ; une échéance
 * devient une mensualité de cotisation ou une échéance d'appel (appel facultatif, mois et année, paiement, payée le).
 */
final class Version20260928220650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cotisations périodiques et paiements : tables cotisation et paiement, échéances de cotisation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cotisation (id INT AUTO_INCREMENT NOT NULL, annee SMALLINT NOT NULL, montant_mensuel INT NOT NULL, jour_echeance SMALLINT NOT NULL, statut VARCHAR(12) NOT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, association_id INT NOT NULL, ville_id INT NOT NULL, type_id INT NOT NULL, cree_par_id INT DEFAULT NULL, UNIQUE INDEX uniq_cotisation_ville_annee (ville_id, annee), INDEX IDX_AE64D2EDEFB9C8A5 (association_id), INDEX IDX_AE64D2EDA73F0036 (ville_id), INDEX IDX_AE64D2EDC54C8C93 (type_id), INDEX IDX_AE64D2EDFC29C013 (cree_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE paiement (id INT AUTO_INCREMENT NOT NULL, montant INT NOT NULL, moyen VARCHAR(12) NOT NULL, recu_le DATE NOT NULL, reference VARCHAR(80) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, statut VARCHAR(12) NOT NULL, identifiant_stripe VARCHAR(120) DEFAULT NULL, enregistre_le DATETIME NOT NULL, annule_le DATETIME DEFAULT NULL, association_id INT NOT NULL, ville_id INT NOT NULL, membre_id INT NOT NULL, enregistre_par_id INT DEFAULT NULL, INDEX idx_paiement_association_recu_le (association_id, recu_le), INDEX idx_paiement_ville_recu_le (ville_id, recu_le), INDEX IDX_B1DC7A1EEFB9C8A5 (association_id), INDEX IDX_B1DC7A1EA73F0036 (ville_id), INDEX IDX_B1DC7A1E6A99F74A (membre_id), INDEX IDX_B1DC7A1ECB5FDB3E (enregistre_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cotisation ADD CONSTRAINT FK_AE64D2EDEFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cotisation ADD CONSTRAINT FK_AE64D2EDA73F0036 FOREIGN KEY (ville_id) REFERENCES ville (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cotisation ADD CONSTRAINT FK_AE64D2EDC54C8C93 FOREIGN KEY (type_id) REFERENCES type_contribution (id)');
        $this->addSql('ALTER TABLE cotisation ADD CONSTRAINT FK_AE64D2EDFC29C013 FOREIGN KEY (cree_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EEFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EA73F0036 FOREIGN KEY (ville_id) REFERENCES ville (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E6A99F74A FOREIGN KEY (membre_id) REFERENCES membre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1ECB5FDB3E FOREIGN KEY (enregistre_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE echeance ADD mois SMALLINT DEFAULT NULL, ADD annee SMALLINT DEFAULT NULL, ADD payee_le DATETIME DEFAULT NULL, ADD cotisation_id INT DEFAULT NULL, ADD paiement_id INT DEFAULT NULL, CHANGE appel_id appel_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE echeance ADD CONSTRAINT FK_40D9893B3EAA84B1 FOREIGN KEY (cotisation_id) REFERENCES cotisation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE echeance ADD CONSTRAINT FK_40D9893B2A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiement (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_echeance_association_statut_date ON echeance (association_id, statut, date_limite)');
        $this->addSql('CREATE UNIQUE INDEX uniq_echeance_cotisation_membre_mois ON echeance (cotisation_id, membre_id, mois)');
        $this->addSql('CREATE INDEX IDX_40D9893B3EAA84B1 ON echeance (cotisation_id)');
        $this->addSql('CREATE INDEX IDX_40D9893B2A4C4478 ON echeance (paiement_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cotisation DROP FOREIGN KEY FK_AE64D2EDEFB9C8A5');
        $this->addSql('ALTER TABLE cotisation DROP FOREIGN KEY FK_AE64D2EDA73F0036');
        $this->addSql('ALTER TABLE cotisation DROP FOREIGN KEY FK_AE64D2EDC54C8C93');
        $this->addSql('ALTER TABLE cotisation DROP FOREIGN KEY FK_AE64D2EDFC29C013');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EEFB9C8A5');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EA73F0036');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E6A99F74A');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1ECB5FDB3E');
        $this->addSql('DROP TABLE cotisation');
        $this->addSql('DROP TABLE paiement');
        $this->addSql('ALTER TABLE echeance DROP FOREIGN KEY FK_40D9893B3EAA84B1');
        $this->addSql('ALTER TABLE echeance DROP FOREIGN KEY FK_40D9893B2A4C4478');
        $this->addSql('DROP INDEX idx_echeance_association_statut_date ON echeance');
        $this->addSql('DROP INDEX uniq_echeance_cotisation_membre_mois ON echeance');
        $this->addSql('DROP INDEX IDX_40D9893B3EAA84B1 ON echeance');
        $this->addSql('DROP INDEX IDX_40D9893B2A4C4478 ON echeance');
        $this->addSql('ALTER TABLE echeance DROP mois, DROP annee, DROP payee_le, DROP cotisation_id, DROP paiement_id, CHANGE appel_id appel_id INT NOT NULL');
    }
}
