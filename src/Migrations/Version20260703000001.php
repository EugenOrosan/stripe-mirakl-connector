<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260703000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add original_amount and original_currency fields to stripe_payout table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        $this->addSql('ALTER TABLE stripe_payout ADD original_amount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE stripe_payout ADD original_currency VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'postgresql' !== $this->connection->getDatabasePlatform()->getName(),
            "Migration can only be executed safely on 'postgresql'."
        );

        $this->addSql('ALTER TABLE stripe_payout DROP original_amount');
        $this->addSql('ALTER TABLE stripe_payout DROP original_currency');
    }
}
