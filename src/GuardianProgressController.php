<?php

declare(strict_types=1);

namespace EduSync;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class GuardianProgressController
{
    public function __construct(private readonly ProgressRepository $repository)
    {
    }

    /** @param array<string, string> $arguments */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $arguments): ResponseInterface
    {
        $guardianId = $this->pathId($arguments['guardianId'] ?? null, 'guardianId');
        $learnerId = $this->pathId($arguments['learnerId'] ?? null, 'learnerId');
        $authenticatedGuardianId = $request->getAttribute('authenticated_guardian_id');
        if (!is_int($authenticatedGuardianId) || $guardianId !== $authenticatedGuardianId) {
            throw new ApiException(403, 'Authenticated guardian does not match guardianId.');
        }

        $connection = $this->repository->connection();
        if (!$this->repository->guardianExists($connection, $guardianId)) {
            throw new ApiException(404, 'Guardian was not found.');
        }
        if (!$this->repository->learnerExists($connection, $learnerId)) {
            throw new ApiException(404, 'Learner was not found.');
        }
        if (!$this->repository->guardianLinked($connection, $guardianId, $learnerId)) {
            throw new ApiException(403, 'Guardian is not linked to this learner.');
        }

        $progress = array_map(static function (array $row): array {
            return [
                'lecture_id' => (int) $row['lecture_id'],
                'resume_position_seconds' => (int) $row['resume_position_seconds'],
                'furthest_position_seconds' => (int) $row['furthest_position_seconds'],
                'last_studied_at' => $row['last_studied_at'] === null
                    ? null
                    : ProgressRepository::databaseTime((string) $row['last_studied_at'])->format('Y-m-d\\TH:i:s.v\\Z'),
                'completed_at' => $row['completed_at'] === null
                    ? null
                    : ProgressRepository::databaseTime((string) $row['completed_at'])->format('Y-m-d\\TH:i:s.v\\Z'),
            ];
        }, $this->repository->guardianProgress($connection, $learnerId));

        return JsonResponse::write($response, [
            'guardian_id' => $guardianId,
            'learner_id' => $learnerId,
            'progress' => $progress,
        ]);
    }

    private function pathId(mixed $value, string $name): int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,18}$/', $value) || (string) (int) $value !== $value) {
            throw new ApiException(400, sprintf('%s must be a positive signed BIGINT integer.', $name));
        }

        return (int) $value;
    }
}
