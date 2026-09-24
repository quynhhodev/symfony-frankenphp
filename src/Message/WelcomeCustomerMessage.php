<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

// Do redpanda-connect publish khi có customer mới (op `c`) — xem CustomerChangedMessage.
final readonly class WelcomeCustomerMessage
{
    public function __construct(
        public Uuid $customerId,
    ) {
    }
}
