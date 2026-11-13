<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(
 *     uniqueConstraints={@ORM\UniqueConstraint(name="dispute_transfer_adjustment", columns={"dispute_id", "transfer_id"})},
 *     indexes={
 *         @ORM\Index(name="dispute_adjustment_dispute", columns={"dispute_id"}),
 *         @ORM\Index(name="dispute_adjustment_transfer", columns={"transfer_id"})
 *     }
 * )
 */
class StripeDisputeAdjustment
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private int $id;

    /**
     * @ORM\ManyToOne(targetEntity="StripeDispute")
     * @ORM\JoinColumn(nullable=false)
     */
    private StripeDispute $dispute;

    /**
     * @ORM\ManyToOne(targetEntity="StripeTransfer")
     * @ORM\JoinColumn(nullable=false)
     */
    private StripeTransfer $transfer;

    /**
     * @ORM\Column(type="integer")
     */
    private int $amount;

    public function __construct(StripeDispute $dispute, StripeTransfer $transfer, int $amount)
    {
        $this->dispute = $dispute;
        $this->transfer = $transfer;
        $this->amount = $amount;
    }

    public function getDispute(): StripeDispute
    {
        return $this->dispute;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }
}
