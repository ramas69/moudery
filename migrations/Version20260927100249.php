<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927100249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Abonnement de chaque association (préparation de la facturation) ; une ligne offerte par association existante.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE abonnement (id INT AUTO_INCREMENT NOT NULL, formule VARCHAR(60) NOT NULL, statut VARCHAR(20) NOT NULL, montant INT NOT NULL, periodicite VARCHAR(20) NOT NULL, debut_le DATE NOT NULL, prochaine_echeance_le DATE DEFAULT NULL, dernier_paiement_le DATE DEFAULT NULL, notes LONGTEXT DEFAULT NULL, modifie_le DATETIME NOT NULL, association_id INT NOT NULL, UNIQUE INDEX UNIQ_351268BBEFB9C8A5 (association_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE abonnement ADD CONSTRAINT FK_351268BBEFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id) ON DELETE CASCADE');
        $this->addSql("INSERT INTO abonnement (association_id, formule, statut, montant, periodicite, debut_le, prochaine_echeance_le, dernier_paiement_le, notes, modifie_le) SELECT id, 'Gratuit', 'offert', 0, 'mensuelle', DATE(cree_le), NULL, NULL, NULL, NOW() FROM association");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE abonnement DROP FOREIGN KEY FK_351268BBEFB9C8A5');
        $this->addSql('DROP TABLE abonnement');
    }
}
