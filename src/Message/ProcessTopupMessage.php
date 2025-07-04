<?php

namespace App\Message;

class ProcessTopupMessage
{
    /**
     * @var int
     */
    private $stripeTopupId;

    public function __construct(int $stripeTopupId)
    {
        $this->stripeTopupId = $stripeTopupId;
    }

    public function getStripeTopupId(): int
    {
        return $this->stripeTopupId;
    }
}
