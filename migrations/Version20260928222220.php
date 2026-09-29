<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Relances (F-24, F-25) : la table `relance`, une ligne par e-mail de rappel envoyé à un membre. */
final class Version20260928222220 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relances : table relance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE relance (id INT AUTO_INCREMENT NOT NULL, canal VARCHAR(12) NOT NULL, automatique TINYINT NOT NULL, jour DATE NOT NULL, envoyee_le DATETIME NOT NULL, nombre_echeances SMALLINT NOT NULL, montant INT NOT NULL, echeances JSON NOT NULL, association_id INT NOT NULL, ville_id INT NOT NULL, membre_id INT NOT NULL, envoyee_par_id INT DEFAULT NULL, INDEX idx_relance_membre_jour (membre_id, jour), INDEX idx_relance_association_envoyee_le (association_id, envoyee_le), INDEX IDX_50BBC126EFB9C8A5 (association_id), INDEX IDX_50BBC126A73F0036 (ville_id), INDEX IDX_50BBC1266A99F74A (membre_id), INDEX IDX_50BBC12683F372B6 (envoyee_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE relance ADD CONSTRAINT FK_50BBC126EFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE relance ADD CONSTRAINT FK_50BBC126A73F0036 FOREIGN KEY (ville_id) REFERENCES ville (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE relance ADD CONSTRAINT FK_50BBC1266A99F74A FOREIGN KEY (membre_id) REFERENCES membre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE relance ADD CONSTRAINT FK_50BBC12683F372B6 FOREIGN KEY (envoyee_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE relance DROP FOREIGN KEY FK_50BBC126EFB9C8A5');
        $this->addSql('ALTER TABLE relance DROP FOREIGN KEY FK_50BBC126A73F0036');
        $this->addSql('ALTER TABLE relance DROP FOREIGN KEY FK_50BBC1266A99F74A');
        $this->addSql('ALTER TABLE relance DROP FOREIGN KEY FK_50BBC12683F372B6');
        $this->addSql('DROP TABLE relance');
    }
}
