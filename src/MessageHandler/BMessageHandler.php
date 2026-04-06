<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\AMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Repository\UserRepository;

#[AsMessageHandler]
final class BMessageHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function __invoke(AMessage $message): void
    {
        $this->logger->info('Handling AMessage in BMessageHandler', ['message' => $message]);
        $user = new User();
        $user->setName('John Doe');
        $user->setAge(30);
        $user->setRecidentId(123457);
        $this->userRepository->save($user);
    }
}
