<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la priorité et l'assignation à un opérateur sur les tickets de support.
 */
final class Version20260914140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute support_threads.priority et support_threads.assigned_to_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE support_threads ADD priority VARCHAR(255) DEFAULT 'normal' NOT NULL");
        $this->addSql('ALTER TABLE support_threads ADD assigned_to_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_D2BB3521F4BD7827 ON support_threads (assigned_to_id)');
        $this->addSql('ALTER TABLE support_threads ADD CONSTRAINT FK_D2BB3521FEDCB2C6 FOREIGN KEY (assigned_to_id) REFERENCES "admins" (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_threads DROP CONSTRAINT FK_D2BB3521FEDCB2C6');
        $this->addSql('DROP INDEX IDX_D2BB3521F4BD7827');
        $this->addSql('ALTER TABLE support_threads DROP priority');
        $this->addSql('ALTER TABLE support_threads DROP assigned_to_id');
    }
}
