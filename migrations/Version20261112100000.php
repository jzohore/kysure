<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DER, lot 7 : précisions propres au cabinet (services, rémunération, conflits d\'intérêts).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE regulatory_profiles ADD der_additional_information TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE regulatory_profiles DROP der_additional_information');
    }
}
