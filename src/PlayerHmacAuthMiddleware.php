<?php

declare(strict_types=1);

namespace EduSync;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PlayerHmacAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $rawBody = (string) $request->getBody();
        $timestamp = $request->getHeaderLine('X-Player-Timestamp');
        $signature = $request->getHeaderLine('X-Player-Signature');

        if (!preg_match('/^(0|[1-9][0-9]{0,15})$/', $timestamp)) {
            throw new ApiException(401, 'Player authentication failed.');
        }

        $timestampValue = (int) $timestamp;
        if (abs(time() - $timestampValue) > $this->config->hmacToleranceSeconds()) {
            throw new ApiException(401, 'Player authentication failed.');
        }

        if (!preg_match('/^sha256=[0-9a-f]{64}$/', $signature)) {
            throw new ApiException(401, 'Player authentication failed.');
        }

        $expected = 'sha256=' . hash_hmac(
            'sha256',
            $timestamp . "\n" . $rawBody,
            $this->config->playerHmacSecret(),
        );
        if (!hash_equals($expected, $signature)) {
            throw new ApiException(401, 'Player authentication failed.');
        }

        return $handler->handle($request
            ->withAttribute('raw_body', $rawBody)
            ->withAttribute('authenticated_source', $this->config->playerEventSource()));
    }
}
