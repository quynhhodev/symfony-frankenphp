<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;


final class SentMessageController extends AbstractController
{
    public function __construct(
        public HttpClientInterface $whatsappClient
    ) {}



    #[Route('/sent/message', name: 'app_sent_message')]
    public function index(): JsonResponse
    {
        $data = [
            'messaging_product' => 'whatsapp',
            'to' => '84981617653',
            'type' => 'template',
            'template' => [
                'name' => 'hello_world',
                'language' => [
                    'code' => 'en_US'
                ]
            ]
                ];

        $res = $this->whatsappClient->request(
            'POST',
            'https://graph.facebook.com/v22.0/794938290379865/messages',
            [
                'json' => $data
            ]
        );

        return $this->json([
            'message' => 'abc!',
            'path' => 'src/Controller/SentMessageController.php',
        ]);
    }
}
