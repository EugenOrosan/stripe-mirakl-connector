<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20241114000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the stripe_topup table with the necessary fields and constraints.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE SEQUENCE stripe_topup_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE stripe_topup (
            id INT NOT NULL,
            amount INT DEFAULT 0,
            currency VARCHAR(255) DEFAULT NULL,
            topup_id VARCHAR(255) DEFAULT NULL,
            status VARCHAR(255) NOT NULL,
            status_reason VARCHAR(1024) DEFAULT NULL,
            mirakl_created_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            creation_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
            modification_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY(id)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('DROP SEQUENCE stripe_topup_id_seq CASCADE');
        $this->addSql('DROP TABLE stripe_topup');
    }
}
