<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\AMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Repository\UserRepository;

#[AsMessageHandler]
// Handler THỨ HAI cho AMessage, không phải handler của BMessage. Nó tồn tại để
// minh hoạ fan-out: một message được Messenger đưa qua mọi handler đã đăng ký.
final class AMessageSecondHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function __invoke(AMessage $message): void
    {
        $this->logger->info('Handling AMessage in AMessageSecondHandler', ['message' => $message]);
        $user = new User();
        $user->setName('John Doe');
        $user->setAge(31);
        // recident_id là cột unique — xem AMessageHandler.
        $user->setRecidentId(random_int(100000, 999999));
        $this->userRepository->save($user);
    }
}
