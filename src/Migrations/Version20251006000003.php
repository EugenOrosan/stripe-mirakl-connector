<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251006000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the amount column (integer) to the stripe_topup_invoice_data table.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        // Add the amount column
        $this->addSql('ALTER TABLE stripe_topup_invoice_data ADD amount INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        // Remove the amount column
        $this->addSql('ALTER TABLE stripe_topup_invoice_data DROP COLUMN amount');
    }
}
