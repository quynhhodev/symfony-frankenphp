<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

// Do redpanda-connect publish vào transport `cdc` (redpanda-connect/resource/app/customer.yaml),
// PHP không tự gửi. Tên tham số constructor LÀ hợp đồng với key trong body.
final readonly class CustomerChangedMessage
{
    public function __construct(
        public Uuid $customerId,
        public ?string $email = null,
    ) {
    }
}
