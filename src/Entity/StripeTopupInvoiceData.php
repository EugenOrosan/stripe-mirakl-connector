<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(name="stripe_topup_invoice_data")
 */
class StripeTopupInvoiceData
{
    public const INVOICE_TOPUP_ON_HOLD = 'INVOICE_TOPUP_ON_HOLD';
    public const INVOICE_TOPUP_PENDING = 'INVOICE_TOPUP_PENDING';
    public const INVOICE_TOPUP_COMPLETED = 'INVOICE_TOPUP_COMPLETED';

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $topupInternalId;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $topupStripeId;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $invoiceNumber;

    /**
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

    /**
     * @ORM\Column(type="datetime")
     */
    private $updatedAt;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $amount;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private $status;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $statusReason;

    /**
     * @ORM\Column(type="datetime")
     */
    private $dateCreatedFromMirakl;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTopupInternalId(): ?int
    {
        return $this->topupInternalId;
    }

    public function setTopupInternalId(int $topupInternalId): self
    {
        $this->topupInternalId = $topupInternalId;
        return $this;
    }

    public function getTopupStripeId(): ?string
    {
        return $this->topupStripeId;
    }

    public function setTopupStripeId(string $topupStripeId): self
    {
        $this->topupStripeId = $topupStripeId;
        return $this;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(string $invoiceNumber): self
    {
        $this->invoiceNumber = $invoiceNumber;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(?int $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStatusReason(): ?string
    {
        return $this->statusReason;
    }

    public function setStatusReason(?string $statusReason): self
    {
        $this->statusReason = $statusReason;
        return $this;
    }

    public function getDateCreatedFromMirakl(): \DateTimeInterface
    {
        return $this->dateCreatedFromMirakl;
    }

    public function setDateCreatedFromMirakl(\DateTimeInterface $dateCreatedFromMirakl): self
    {
        $this->dateCreatedFromMirakl = $dateCreatedFromMirakl;
        return $this;
    }
}
