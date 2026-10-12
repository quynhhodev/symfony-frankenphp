<?php

namespace App\Tests\Controller;

use App\Controller\HealthController;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testLiveNeverTouchesDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeQuery');

        $response = (new HealthController($connection))->live();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testReadyIsOkWhenDatabaseAnswers(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeQuery')->with('SELECT 1');

        $response = (new HealthController($connection))->ready();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testReadyIsUnavailableWhenDatabaseIsDown(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willThrowException($this->createMock(ConnectionException::class));

        $response = (new HealthController($connection))->ready();

        $this->assertSame(503, $response->getStatusCode());
    }
}
