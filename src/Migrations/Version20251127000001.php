<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251127000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the nullable status and status_reason columns to the stripe_topup_invoice_data table.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        // Add the status and status_reason columns
        $this->addSql("ALTER TABLE stripe_topup_invoice_data ADD status VARCHAR(50) DEFAULT NULL");
        $this->addSql("ALTER TABLE stripe_topup_invoice_data ADD status_reason VARCHAR(255) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        // Remove the status and status_reason columns
        $this->addSql('ALTER TABLE stripe_topup_invoice_data DROP COLUMN status');
        $this->addSql('ALTER TABLE stripe_topup_invoice_data DROP COLUMN status_reason');
    }
}
