<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251128000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds date_created_from_mirakl column to stripe_topup_invoice_data table.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        $this->addSql("
            ALTER TABLE stripe_topup_invoice_data ADD date_created_from_mirakl TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        $this->addSql("
            ALTER TABLE stripe_topup_invoice_data
            DROP COLUMN date_created_from_mirakl
        ");
    }
}
