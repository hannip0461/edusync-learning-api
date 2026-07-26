<?php

declare(strict_types=1);

namespace EduSync;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Psr7\Factory\ResponseFactory;

final class ApiErrorMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ApiException $exception) {
            return JsonResponse::error(
                (new ResponseFactory())->createResponse(),
                $request,
                $exception->status(),
                $exception->getMessage(),
            );
        } catch (HttpException $exception) {
            $status = $exception->getCode();
            $response = JsonResponse::error(
                (new ResponseFactory())->createResponse(),
                $request,
                $status,
                $status === 405 ? 'Method not allowed.' : 'Route not found.',
            );

            if ($exception instanceof HttpMethodNotAllowedException && $exception->getAllowedMethods() !== []) {
                return $response->withHeader('Allow', implode(', ', $exception->getAllowedMethods()));
            }

            return $response;
        } catch (\Throwable $exception) {
            error_log(sprintf(
                'Unhandled API error [%s]: %s',
                (string) $request->getAttribute('correlation_id', 'unknown'),
                $exception::class,
            ));

            return JsonResponse::error(
                (new ResponseFactory())->createResponse(),
                $request,
                500,
                'Internal server error.',
            );
        }
    }
}
