<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ApiResource(
 *      collectionOperations={
 *          "get"={"path"="/topups"}
 *      },
 *      itemOperations={
 *          "get"={"path"="/topups/{id}", "requirements"={"id"="\d+"}},
 *      }
 * )
 *
 * @ORM\Entity(repositoryClass="App\Repository\StripeTopupRepository")
 */
class StripeTopup
{
    public const TOPUP_ON_HOLD = 'TOPUP_ON_HOLD';
    public const TOPUP_ABORTED = 'TOPUP_ABORTED';
    public const TOPUP_PENDING = 'TOPUP_PENDING';
    public const TOPUP_FAILED = 'TOPUP_FAILED';
    public const TOPUP_CREATED = 'TOPUP_CREATED';

    // TOPUP status reasons: on hold
    public const TOPUP_STATUS_REASON_SHOP_NOT_READY = 'Cannot find Stripe account for shop ID %s';
    public const TOPUP_STATUS_REASON_SHOP_TOPUP_DISABLED = 'TOPUPs are disabled shop ID %s';

    // TOPUP status reasons: aborted
    public const TOPUP_STATUS_REASON_INVALID_AMOUNT = 'Amount must be positive, input was: %d';
    public const TOPUP_STATUS_REASON_NO_SHOP_ID = 'No shop ID provided';

    /**
     * @ORM\Id()
     *
     * @ORM\GeneratedValue()
     *
     * @ORM\Column(type="integer")
     */
    private int $id;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private ?int $amount = 0;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private ?string $currency;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private ?string $topupId = null;

    /**
     * @ORM\Column(type="string")
     */
    private string $status;

    /**
     * @ORM\Column(type="string", length=1024, nullable=true)
     */
    private ?string $statusReason = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $miraklCreatedDate;

    /**
     * @ORM\Column(type="datetime", options={"default": "CURRENT_TIMESTAMP"})
     *
     * @Gedmo\Timestampable(on="create")
     */
    private \DateTimeInterface $creationDatetime;

    /**
     * @ORM\Column(type="datetime", options={"default": "CURRENT_TIMESTAMP"})
     *
     * @Gedmo\Timestampable(on="update")
     */
    private \DateTimeInterface $modificationDatetime;

    public static function getAvailableStatus(): array
    {
        return [
            self::TOPUP_ON_HOLD,
            self::TOPUP_ABORTED,
            self::TOPUP_PENDING,
            self::TOPUP_FAILED,
            self::TOPUP_CREATED,
        ];
    }

    public static function getInvalidStatus(): array
    {
        return [
            self::TOPUP_FAILED,
        ];
    }

    public static function getRetriableStatus(): array
    {
        return [
            self::TOPUP_FAILED,
            self::TOPUP_ON_HOLD,
        ];
    }

    public function isRetriable(): bool
    {
        return in_array($this->getStatus(), self::getRetriableStatus());
    }

    public function isDispatchable(): bool
    {
        return self::TOPUP_PENDING === $this->getStatus();
    }

    public function getId(): ?int
    {
        return $this->id;
    }


    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getTopupId(): ?string
    {
        return $this->topupId;
    }

    public function setTopupId(?string $topupId): self
    {
        $this->topupId = $topupId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, self::getAvailableStatus())) {
            throw new \InvalidArgumentException('Invalid topup status');
        }
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

    public function getMiraklCreatedDate(): ?\DateTimeInterface
    {
        return $this->miraklCreatedDate;
    }

    public function setMiraklCreatedDate(?\DateTimeInterface $miraklCreatedDate): self
    {
        $this->miraklCreatedDate = $miraklCreatedDate;

        return $this;
    }

    public function getCreationDatetime(): ?\DateTimeInterface
    {
        return $this->creationDatetime;
    }

    public function setCreationDatetime(\DateTime $creationDatetime): self
    {
        $this->creationDatetime = $creationDatetime;

        return $this;
    }

    public function getModificationDatetime(): ?\DateTimeInterface
    {
        return $this->modificationDatetime;
    }

    public function setModificationDatetime(\DateTimeInterface $modificationDatetime): self
    {
        $this->modificationDatetime = $modificationDatetime;

        return $this;
    }
}
