<?php

namespace App\MessageHandler;

use App\Message\WelcomeCustomerMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

// Message nào redpanda publish cũng BẮT BUỘC có handler: thiếu thì Messenger ném
// NoHandlerForMessageException, retry hết lượt rồi message nằm luôn trong `failed`.
#[AsMessageHandler]
final readonly class WelcomeCustomerMessageHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(WelcomeCustomerMessage $message): void
    {
        $this->logger->info('welcome customer', [
            'id' => (string) $message->customerId,
        ]);
    }
}
