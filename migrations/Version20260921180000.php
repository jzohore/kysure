<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée feedbacks (widget de feedback flottant, testeur pilote CIF Pilot)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE feedbacks (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, message TEXT NOT NULL, page_url VARCHAR(2048) NOT NULL, page_title VARCHAR(255) DEFAULT NULL, submitted_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7E6C3F8979F7D87D ON feedbacks (submitted_by_id)');
        $this->addSql('ALTER TABLE feedbacks ADD CONSTRAINT FK_FEEDBACKS_SUBMITTED_BY FOREIGN KEY (submitted_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedbacks DROP CONSTRAINT FK_FEEDBACKS_SUBMITTED_BY');
        $this->addSql('DROP TABLE feedbacks');
    }
}
