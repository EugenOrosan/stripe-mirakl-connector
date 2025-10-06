<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251003000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the stripe_topup_invoice_data table with the necessary fields and constraints.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        $this->addSql('CREATE SEQUENCE stripe_topup_invoice_data_id_seq INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql('CREATE TABLE stripe_topup_invoice_data (
            id INT NOT NULL,
            topup_internal_id INT DEFAULT NULL,
            topup_stripe_id VARCHAR(255) DEFAULT NULL,
            invoice_number VARCHAR(255) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        $this->addSql('DROP SEQUENCE stripe_topup_invoice_data_id_seq CASCADE');
        $this->addSql('DROP TABLE stripe_topup_invoice_data');
    }
}
