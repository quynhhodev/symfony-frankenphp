<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Entity\User;
use App\Message\AMessage;
use App\Repository\UserRepository;

final class UsersController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepo
    )
    {
        throw new \Exception('Not implemented');
    }
    #[Route('/users', name: 'app_env_data')]
    public function index(MessageBusInterface $bus, EntityManagerInterface $em): JsonResponse
    {
        $users = $this->userRepo->findAll();

        return new JsonResponse(['data' => $a, 'users' => $users], 200);
    }
}
