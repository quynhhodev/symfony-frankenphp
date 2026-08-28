<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\AMessage;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AMessageHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function __invoke(AMessage $message): void
    {
       $this->logger->info('Handling AMessage', ['message' => $message]);
       $user = new User();
       $user->setName('John Doe');
       $user->setAge(30);
       // recident_id là cột unique. Hardcode số cố định thì message thứ hai trở đi ném
       // UniqueConstraintViolationException; mà vì AMessage có HAI handler cùng ghi một
       // giá trị nên ngay message đầu tiên đã đụng — đủ để đầu độc queue vĩnh viễn.
       $user->setRecidentId(random_int(100000, 999999));
       $this->userRepository->save($user, true);
    }
}
