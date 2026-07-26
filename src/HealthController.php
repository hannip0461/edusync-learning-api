<?php

declare(strict_types=1);

namespace EduSync;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HealthController
{
    public function __construct(private readonly Database $database)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $payload = [
                'status' => 'ok',
                'database' => 'connected',
                'driver' => 'pdo_sqlsrv',
                'probe' => $this->database->probe(),
            ];
        } catch (\Throwable) {
            $payload = [
                'status' => 'error',
                'database' => 'unavailable',
            ];
            $response = $response->withStatus(503);
        }

        $response->getBody()->write((string) json_encode($payload, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
