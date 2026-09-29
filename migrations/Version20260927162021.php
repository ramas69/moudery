<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Ce que les classeurs des villes savent d'un membre et qu'on garde pour l'historique (F-45) : localité, année de naissance, années d'adhésion. */
final class Version20260927162021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Historique des villes : localité et année de naissance du membre, adhésions par année (table adhesion).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE adhesion (id INT AUTO_INCREMENT NOT NULL, annee SMALLINT NOT NULL, origine VARCHAR(20) NOT NULL, cree_le DATETIME NOT NULL, association_id INT NOT NULL, ville_id INT NOT NULL, membre_id INT NOT NULL, INDEX idx_adhesion_ville_annee (ville_id, annee), UNIQUE INDEX uniq_adhesion_membre_annee (membre_id, annee), INDEX IDX_C50CA65AEFB9C8A5 (association_id), INDEX IDX_C50CA65AA73F0036 (ville_id), INDEX IDX_C50CA65A6A99F74A (membre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE adhesion ADD CONSTRAINT FK_C50CA65AEFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id)');
        $this->addSql('ALTER TABLE adhesion ADD CONSTRAINT FK_C50CA65AA73F0036 FOREIGN KEY (ville_id) REFERENCES ville (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE adhesion ADD CONSTRAINT FK_C50CA65A6A99F74A FOREIGN KEY (membre_id) REFERENCES membre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE membre ADD localite VARCHAR(80) DEFAULT NULL, ADD annee_naissance SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE adhesion DROP FOREIGN KEY FK_C50CA65AEFB9C8A5');
        $this->addSql('ALTER TABLE adhesion DROP FOREIGN KEY FK_C50CA65AA73F0036');
        $this->addSql('ALTER TABLE adhesion DROP FOREIGN KEY FK_C50CA65A6A99F74A');
        $this->addSql('DROP TABLE adhesion');
        $this->addSql('ALTER TABLE membre DROP localite, DROP annee_naissance');
    }
}
