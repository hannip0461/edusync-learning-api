<?php

declare(strict_types=1);

namespace EduSync;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class JsonResponse
{
    /** @param array<string, mixed> $payload */
    public static function write(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public static function error(ResponseInterface $response, ServerRequestInterface $request, int $status, string $message): ResponseInterface
    {
        return self::write($response, [
            'error' => [
                'status' => $status,
                'message' => $message,
                'correlation_id' => (string) $request->getAttribute('correlation_id', ''),
            ],
        ], $status);
    }
}
