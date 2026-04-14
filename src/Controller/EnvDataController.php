<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Entity\User;
use App\Message\AMessage;

final class EnvDataController extends AbstractController
{
    #[Route('/env/data', name: 'app_env_data')]
    public function index(MessageBusInterface $bus, EntityManagerInterface $em): JsonResponse
    {
        $a = getenv('APP_ENV');
        $bus->dispatch(new AMessage());

        $user = new User();
        $user->setName('John Doe');
        $user->setAge(25);
        $user->setRecidentId(random_int(100000, 999999));

        $em->persist($user);
        $em->flush();

        return new JsonResponse(['data' => $a, 'user_id' => $user->getId()], 200);
    }
}
