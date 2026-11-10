<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 5 adéquation : date et empreinte du PDF mis à disposition du client (preuve de remise).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE adequacy_reports ADD made_available_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE adequacy_reports ADD pdf_sha256 VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE adequacy_reports DROP made_available_at');
        $this->addSql('ALTER TABLE adequacy_reports DROP pdf_sha256');
    }
}
