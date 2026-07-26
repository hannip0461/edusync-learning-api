<?php

declare(strict_types=1);

namespace EduSync;

use PDO;
use PDOException;

final class ProgressService
{
    public function __construct(private readonly ProgressRepository $repository)
    {
    }

    /** @return array{applied:bool,duplicate:bool} */
    public function record(EventInput $event, string $source): array
    {
        $connection = $this->repository->connection();
        $receivedAt = $this->repository->verifyEventTarget($connection, $event);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $phase = 'begin';
            try {
                $connection->beginTransaction();
                $phase = 'event_insert';
                $inserted = $this->repository->insertEvent($connection, $event, $source, $receivedAt);
                $phase = 'progress_lock';
                $progress = $this->repository->lockProgress($connection, $event->learnerId, $event->lectureId);

                if ($progress === null) {
                    $this->repository->insertProgress($connection, $event, $inserted);
                } else {
                    $this->repository->updateProgress(
                        $connection,
                        $event,
                        $inserted,
                        $this->isNewer($progress, $event, $inserted),
                    );
                }

                $connection->commit();

                return ['applied' => true, 'duplicate' => false];
            } catch (PDOException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }

                if ($phase === 'event_insert' && $this->isDuplicateKey($exception)) {
                    $existingHash = $this->repository->existingPayloadHash($connection, $source, $event->eventId);
                    if ($existingHash === null) {
                        throw $exception;
                    }

                    if (hash_equals($existingHash, $event->payloadHash)) {
                        return ['applied' => false, 'duplicate' => true];
                    }

                    throw new ApiException(409, 'event_id is already used with a different payload.');
                }

                if ($attempt === 0 && $this->isRetryable($exception)) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new \RuntimeException('The event transaction did not finish.');
    }

    /** @param array<string, mixed> $progress @param array{event_seq:int,received_at:\DateTimeImmutable} $inserted */
    private function isNewer(array $progress, EventInput $event, array $inserted): bool
    {
        if ($progress['last_session_id'] === null || $progress['last_event_seq'] === null) {
            return true;
        }

        if ((string) $progress['last_session_id'] === $event->sessionId) {
            $sequenceComparison = $event->sequenceNo <=> (int) $progress['last_sequence_no'];
            if ($sequenceComparison !== 0) {
                return $sequenceComparison > 0;
            }

            $occurredComparison = $event->occurredAt <=> ProgressRepository::databaseTime((string) $progress['last_studied_at']);
            if ($occurredComparison !== 0) {
                return $occurredComparison > 0;
            }

            return $inserted['event_seq'] > (int) $progress['last_event_seq'];
        }

        $occurredComparison = $event->occurredAt <=> ProgressRepository::databaseTime((string) $progress['last_studied_at']);
        if ($occurredComparison !== 0) {
            return $occurredComparison > 0;
        }

        $receivedComparison = $inserted['received_at'] <=> ProgressRepository::databaseTime((string) $progress['last_received_at']);
        if ($receivedComparison !== 0) {
            return $receivedComparison > 0;
        }

        return $inserted['event_seq'] > (int) $progress['last_event_seq'];
    }

    private function isDuplicateKey(PDOException $exception): bool
    {
        return in_array($this->sqlServerCode($exception), [2601, 2627], true);
    }

    private function isRetryable(PDOException $exception): bool
    {
        return in_array($this->sqlServerCode($exception), [1205, 3960], true);
    }

    private function sqlServerCode(PDOException $exception): int
    {
        $code = $exception->errorInfo[1] ?? $exception->getCode();

        return is_numeric($code) ? (int) $code : 0;
    }

}
