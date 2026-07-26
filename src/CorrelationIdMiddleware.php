<?php

declare(strict_types=1);

namespace EduSync;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $candidate = trim($request->getHeaderLine('X-Correlation-Id'));
        $correlationId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $candidate)
            ? $candidate
            : bin2hex(random_bytes(16));

        return $handler
            ->handle($request->withAttribute('correlation_id', $correlationId))
            ->withHeader('X-Correlation-Id', $correlationId);
    }
}
