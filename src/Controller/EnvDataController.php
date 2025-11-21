<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class EnvDataController extends AbstractController
{
    #[Route('/env/data', name: 'app_env_data')]
    public function index(): JsonResponse
    {
        $a  = 0;
        while($a < 10000000){
            $a += 1;
        }
        
        return new JsonResponse(['data' => $a, 200]);
    }
}
