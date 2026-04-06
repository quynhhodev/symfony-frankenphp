<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\AMessage;

final class EnvDataController extends AbstractController
{
    #[Route('/env/data', name: 'app_env_data')]
    public function index(MessageBusInterface $bus): JsonResponse
    {
        $a = getenv('APP_ENV');
        $bus->dispatch(new AMessage());
        return new JsonResponse(['data' => $a, 200]);
    }
}
