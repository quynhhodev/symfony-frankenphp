<?php

namespace App\MessageHandler;

use App\Message\CustomerChangedMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CustomerChangedMessageHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(CustomerChangedMessage $message): void
    {
        $this->logger->info('customer changed', [
            'id' => (string) $message->customerId,
            'email' => $message->email,
        ]);
    }
}
