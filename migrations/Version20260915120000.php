<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P3 support : échéance SLA (support_threads.due_at) et pièces jointes (support_messages.attachment_*).
 */
final class Version20260915120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute support_threads.due_at et support_messages.attachment_*';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_threads ADD due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE support_threads SET due_at = created_at + (CASE priority WHEN 'urgent' THEN INTERVAL '30 minutes' ELSE INTERVAL '2 hours' END)");
        $this->addSql('ALTER TABLE support_threads ALTER COLUMN due_at SET NOT NULL');

        $this->addSql('ALTER TABLE support_messages ADD attachment_storage_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE support_messages ADD attachment_filename VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE support_messages ADD attachment_mime_type VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE support_messages ADD attachment_size INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_messages DROP attachment_storage_path');
        $this->addSql('ALTER TABLE support_messages DROP attachment_filename');
        $this->addSql('ALTER TABLE support_messages DROP attachment_mime_type');
        $this->addSql('ALTER TABLE support_messages DROP attachment_size');
        $this->addSql('ALTER TABLE support_threads DROP due_at');
    }
}
