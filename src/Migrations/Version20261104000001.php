<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track dispute costs and per-transfer T&C adjustments';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        $this->addSql('CREATE TABLE stripe_dispute (id VARCHAR(255) NOT NULL, charge_id VARCHAR(255) NOT NULL, currency VARCHAR(3) NOT NULL, cost INT NOT NULL, already_recovered INT NOT NULL, fully_recovered BOOLEAN NOT NULL, closed BOOLEAN NOT NULL, closed_while_recovery_enabled BOOLEAN NOT NULL, balance_transactions JSON NOT NULL, last_event_created INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql("COMMENT ON COLUMN stripe_dispute.updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX dispute_recovery_currency ON stripe_dispute (currency, closed, fully_recovered)');
        $this->addSql('CREATE SEQUENCE stripe_dispute_adjustment_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE stripe_dispute_adjustment (id INT NOT NULL, dispute_id VARCHAR(255) NOT NULL, transfer_id INT NOT NULL, amount INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX dispute_transfer_adjustment ON stripe_dispute_adjustment (dispute_id, transfer_id)');
        $this->addSql('CREATE INDEX dispute_adjustment_dispute ON stripe_dispute_adjustment (dispute_id)');
        $this->addSql('CREATE INDEX dispute_adjustment_transfer ON stripe_dispute_adjustment (transfer_id)');
        $this->addSql('ALTER TABLE stripe_dispute_adjustment ADD CONSTRAINT adjustment_dispute_fk FOREIGN KEY (dispute_id) REFERENCES stripe_dispute (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stripe_dispute_adjustment ADD CONSTRAINT adjustment_transfer_fk FOREIGN KEY (transfer_id) REFERENCES stripe_transfer (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stripe_transfer ADD commission_tax_request JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        $this->addSql('DROP TABLE stripe_dispute_adjustment');
        $this->addSql('DROP SEQUENCE stripe_dispute_adjustment_id_seq');
        $this->addSql('DROP TABLE stripe_dispute');
        $this->addSql('ALTER TABLE stripe_transfer DROP commission_tax_request');
    }
}
