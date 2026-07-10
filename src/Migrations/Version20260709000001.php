<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260709000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reversed flag to stripe_transfer table for negative commission tax transfers';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        $this->addSql('ALTER TABLE stripe_transfer ADD reversed BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        $this->addSql('ALTER TABLE stripe_transfer DROP reversed');
    }
}
