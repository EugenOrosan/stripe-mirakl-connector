<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251006000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the invoice_ids column (JSON) to the stripe_topup table.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        // Add the invoice_ids column as JSON
        $this->addSql('ALTER TABLE stripe_topup ADD invoice_ids JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        // Remove the invoice_ids column
        $this->addSql('ALTER TABLE stripe_topup DROP COLUMN invoice_ids');
    }
}
