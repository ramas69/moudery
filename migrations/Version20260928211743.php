<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Reversements des villes au bureau central (F-20) : la table `reversement`. */
final class Version20260928211743 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reversements des villes au bureau central : table reversement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reversement (id INT AUTO_INCREMENT NOT NULL, exercice SMALLINT NOT NULL, montant INT NOT NULL, montant_declare INT NOT NULL, recu_le DATE NOT NULL, reference VARCHAR(80) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, statut VARCHAR(12) NOT NULL, confirme_le DATETIME DEFAULT NULL, cree_le DATETIME NOT NULL, association_id INT NOT NULL, ville_id INT NOT NULL, declare_par_id INT DEFAULT NULL, confirme_par_id INT DEFAULT NULL, INDEX idx_reversement_association_exercice (association_id, exercice), INDEX idx_reversement_ville_recu_le (ville_id, recu_le), INDEX IDX_6D601223EFB9C8A5 (association_id), INDEX IDX_6D601223A73F0036 (ville_id), INDEX IDX_6D6012238B43E2A (declare_par_id), INDEX IDX_6D601223A37A83C7 (confirme_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reversement ADD CONSTRAINT FK_6D601223EFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id)');
        $this->addSql('ALTER TABLE reversement ADD CONSTRAINT FK_6D601223A73F0036 FOREIGN KEY (ville_id) REFERENCES ville (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reversement ADD CONSTRAINT FK_6D6012238B43E2A FOREIGN KEY (declare_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reversement ADD CONSTRAINT FK_6D601223A37A83C7 FOREIGN KEY (confirme_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reversement DROP FOREIGN KEY FK_6D601223EFB9C8A5');
        $this->addSql('ALTER TABLE reversement DROP FOREIGN KEY FK_6D601223A73F0036');
        $this->addSql('ALTER TABLE reversement DROP FOREIGN KEY FK_6D6012238B43E2A');
        $this->addSql('ALTER TABLE reversement DROP FOREIGN KEY FK_6D601223A37A83C7');
        $this->addSql('DROP TABLE reversement');
    }
}
