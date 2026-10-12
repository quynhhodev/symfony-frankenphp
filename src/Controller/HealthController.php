<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

// Endpoint cho probe của k8s (devops/deployment/frankenphp-deployment.yaml).
// tcpSocket chỉ biết cổng 80 có mở hay không, nên Symfony lỗi 500 mà pod vẫn Ready.
final class HealthController
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    // Liveness: chỉ cần PHP trả lời được. KHÔNG chạm DB, vì nếu DB chết mà liveness
    // fail thì k8s restart hàng loạt pod, và restart không sửa được DB.
    #[Route('/health/live', name: 'app_health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    // Readiness: DB không trả lời thì pod bị rút khỏi Service cho tới khi DB hồi lại.
    #[Route('/health/ready', name: 'app_health_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (DbalException) {
            return new JsonResponse(['status' => 'unavailable'], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
