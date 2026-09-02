<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

final class UsersController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepo,
        private LoggerInterface $logger
    ) {}

    #[Route('/users', name: 'app_users')]
    public function index(MessageBusInterface $bus, EntityManagerInterface $em): JsonResponse
    {
        $users = $this->userRepo->findAll();
        $this->logger->debug("Client called Users");

        return new JsonResponse(['users' => $users], 200);
    }
}
