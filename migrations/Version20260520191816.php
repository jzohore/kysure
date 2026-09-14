<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520191816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE workspaces_invitations DROP CONSTRAINT fk_47a29ea282d40a1f');
        $this->addSql('ALTER TABLE workspaces_invitations DROP CONSTRAINT fk_47a29ea27e3c61f9');
        $this->addSql('ALTER TABLE workspaces_invitations ALTER workspace_id SET NOT NULL');
        $this->addSql('ALTER TABLE workspaces_invitations ALTER owner_id SET NOT NULL');
        $this->addSql('ALTER TABLE workspaces_invitations ADD CONSTRAINT FK_47A29EA282D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workspaces_invitations ADD CONSTRAINT FK_47A29EA27E3C61F9 FOREIGN KEY (owner_id) REFERENCES "users" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE workspaces_invitations DROP CONSTRAINT FK_47A29EA282D40A1F');
        $this->addSql('ALTER TABLE workspaces_invitations DROP CONSTRAINT FK_47A29EA27E3C61F9');
        $this->addSql('ALTER TABLE workspaces_invitations ALTER workspace_id DROP NOT NULL');
        $this->addSql('ALTER TABLE workspaces_invitations ALTER owner_id DROP NOT NULL');
        $this->addSql('ALTER TABLE workspaces_invitations ADD CONSTRAINT fk_47a29ea282d40a1f FOREIGN KEY (workspace_id) REFERENCES workspaces (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE workspaces_invitations ADD CONSTRAINT fk_47a29ea27e3c61f9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
