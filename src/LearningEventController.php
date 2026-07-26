<?php

declare(strict_types=1);

namespace EduSync;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class LearningEventController
{
    public function __construct(private readonly ProgressService $service)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rawBody = $request->getAttribute('raw_body');
        $event = EventInput::fromRawJson(is_string($rawBody) ? $rawBody : (string) $request->getBody());

        $authenticatedLearnerId = $request->getAttribute('authenticated_learner_id');
        if (is_int($authenticatedLearnerId) && $event->learnerId !== $authenticatedLearnerId) {
            throw new ApiException(403, 'Authenticated learner does not match learner_id.');
        }

        $source = $request->getAttribute('authenticated_source');
        if (!is_string($source)) {
            throw new \RuntimeException('Missing authenticated event source.');
        }

        return JsonResponse::write($response, $this->service->record($event, $source));
    }
}
