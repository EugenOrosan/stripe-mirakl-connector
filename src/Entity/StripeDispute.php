<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(indexes={@ORM\Index(name="dispute_recovery_currency", columns={"currency", "closed", "fully_recovered"})})
 */
class StripeDispute
{
    /**
     * @ORM\Id()
     * @ORM\Column(type="string")
     */
    private string $id;

    /**
     * @ORM\Column(type="string")
     */
    private string $chargeId;

    /**
     * @ORM\Column(type="string", length=3)
     */
    private string $currency;

    /**
     * @ORM\Column(type="integer")
     */
    private int $cost = 0;

    /**
     * @ORM\Column(type="integer")
     */
    private int $alreadyRecovered = 0;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $fullyRecovered = true;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $closed = false;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $closedWhileRecoveryEnabled = false;

    /**
     * @ORM\Column(type="json")
     */
    private array $balanceTransactions = [];

    /**
     * @ORM\Column(type="integer")
     */
    private int $lastEventCreated = 0;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $chargeId, string $currency)
    {
        $this->id = $id;
        $this->chargeId = $chargeId;
        $this->currency = $currency;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function synchronize(array $transactions, string $currency, bool $closed, int $eventCreated, bool $recoveryEnabled): void
    {
        if ($currency !== $this->currency && ([] !== $this->balanceTransactions || 0 !== $this->alreadyRecovered)) {
            throw new \UnexpectedValueException('A dispute cannot change its settlement currency after a balance impact.');
        }

        // Balance transactions are immutable. Merge snapshots by ID so late webhooks cannot undo a reinstatement.
        foreach ($transactions as $id => $transaction) {
            if (isset($this->balanceTransactions[$id]) && $this->balanceTransactions[$id] !== $transaction) {
                throw new \UnexpectedValueException(sprintf('Conflicting balance transaction %s.', $id));
            }
        }
        $merged = array_replace($this->balanceTransactions, $transactions);
        $net = 0;
        foreach ($merged as $transaction) {
            $net += $transaction['net'];
            if (!is_int($net) || PHP_INT_MIN === $net) {
                throw new \UnexpectedValueException('Dispute balance exceeds the supported integer range.');
            }
        }

        $this->balanceTransactions = $merged;
        $this->currency = $currency;
        $this->cost = -$net;
        if (!$this->closed && $closed) {
            $this->closedWhileRecoveryEnabled = $recoveryEnabled;
        }
        $this->closed = $this->closed || $closed;
        $this->lastEventCreated = max($this->lastEventCreated, $eventCreated);
        $this->fullyRecovered = $this->cost === $this->alreadyRecovered;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function applyRecovery(int $amount): void
    {
        $this->alreadyRecovered += $amount;
        $this->fullyRecovered = $this->cost === $this->alreadyRecovered;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getChargeId(): string
    {
        return $this->chargeId;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCost(): int
    {
        return $this->cost;
    }

    public function getAlreadyRecovered(): int
    {
        return $this->alreadyRecovered;
    }

    public function getOutstanding(): int
    {
        return $this->cost - $this->alreadyRecovered;
    }

    public function isClosedWhileRecoveryEnabled(): bool
    {
        return $this->closedWhileRecoveryEnabled;
    }

    public function isFullyRecovered(): bool
    {
        return $this->fullyRecovered;
    }
}
