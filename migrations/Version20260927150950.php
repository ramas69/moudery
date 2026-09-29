<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927150950 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Membres et foyers d’une ville (F-42, F-09) : tables membre et foyer.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE foyer (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, cree_le DATETIME NOT NULL, association_id INT NOT NULL, ville_id INT NOT NULL, payeur_id INT DEFAULT NULL, UNIQUE INDEX uniq_foyer_ville_nom (ville_id, nom), INDEX IDX_4EB44E88EFB9C8A5 (association_id), INDEX IDX_4EB44E88A73F0036 (ville_id), INDEX IDX_4EB44E88422667C5 (payeur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE membre (id INT AUTO_INCREMENT NOT NULL, prenom VARCHAR(80) NOT NULL, nom VARCHAR(80) NOT NULL, email VARCHAR(180) DEFAULT NULL, telephone VARCHAR(30) DEFAULT NULL, statut VARCHAR(20) NOT NULL, consent_email TINYINT NOT NULL, origine VARCHAR(20) NOT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, association_id INT NOT NULL, ville_id INT NOT NULL, foyer_id INT DEFAULT NULL, compte_id INT DEFAULT NULL, INDEX idx_membre_ville_nom (ville_id, nom, prenom), UNIQUE INDEX uniq_membre_ville_email (ville_id, email), INDEX IDX_F6B4FB29EFB9C8A5 (association_id), INDEX IDX_F6B4FB29A73F0036 (ville_id), INDEX IDX_F6B4FB292B919A58 (foyer_id), INDEX IDX_F6B4FB29F2C56620 (compte_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE foyer ADD CONSTRAINT FK_4EB44E88EFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id)');
        $this->addSql('ALTER TABLE foyer ADD CONSTRAINT FK_4EB44E88A73F0036 FOREIGN KEY (ville_id) REFERENCES ville (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE foyer ADD CONSTRAINT FK_4EB44E88422667C5 FOREIGN KEY (payeur_id) REFERENCES membre (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE membre ADD CONSTRAINT FK_F6B4FB29EFB9C8A5 FOREIGN KEY (association_id) REFERENCES association (id)');
        $this->addSql('ALTER TABLE membre ADD CONSTRAINT FK_F6B4FB29A73F0036 FOREIGN KEY (ville_id) REFERENCES ville (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE membre ADD CONSTRAINT FK_F6B4FB292B919A58 FOREIGN KEY (foyer_id) REFERENCES foyer (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE membre ADD CONSTRAINT FK_F6B4FB29F2C56620 FOREIGN KEY (compte_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE foyer DROP FOREIGN KEY FK_4EB44E88EFB9C8A5');
        $this->addSql('ALTER TABLE foyer DROP FOREIGN KEY FK_4EB44E88A73F0036');
        $this->addSql('ALTER TABLE foyer DROP FOREIGN KEY FK_4EB44E88422667C5');
        $this->addSql('ALTER TABLE membre DROP FOREIGN KEY FK_F6B4FB29EFB9C8A5');
        $this->addSql('ALTER TABLE membre DROP FOREIGN KEY FK_F6B4FB29A73F0036');
        $this->addSql('ALTER TABLE membre DROP FOREIGN KEY FK_F6B4FB292B919A58');
        $this->addSql('ALTER TABLE membre DROP FOREIGN KEY FK_F6B4FB29F2C56620');
        $this->addSql('DROP TABLE foyer');
        $this->addSql('DROP TABLE membre');
    }
}
