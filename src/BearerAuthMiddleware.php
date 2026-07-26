<?php

declare(strict_types=1);

namespace EduSync;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BearerAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly string $role,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->token($request->getHeaderLine('Authorization'));
        $expected = $this->role === 'learner'
            ? $this->config->appBearerToken()
            : $this->config->guardianBearerToken();

        if ($token === null || !hash_equals($expected, $token)) {
            throw new ApiException(401, 'Bearer authentication failed.');
        }

        if ($this->role === 'learner') {
            return $handler->handle($request
                ->withAttribute('authenticated_learner_id', $this->config->appBearerLearnerId())
                ->withAttribute('authenticated_source', $this->config->appEventSource()));
        }

        return $handler->handle($request
            ->withAttribute('authenticated_guardian_id', $this->config->guardianBearerId()));
    }

    private function token(string $header): ?string
    {
        if (!preg_match('/^Bearer ([^\s]+)$/', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
