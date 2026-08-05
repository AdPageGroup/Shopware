<?php declare(strict_types=1);

namespace Tagging\MessageQueue;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

class SendWebhookMessage implements AsyncMessageInterface
{
    public function __construct(
        private readonly string $orderId,
        private readonly string $transactionId,
    ) {
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }
}
