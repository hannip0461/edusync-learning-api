<?php

declare(strict_types=1);

namespace EduSync;

use PDO;
use PDOException;
use PDOStatement;

final class ProgressRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function connection(): PDO
    {
        return $this->database->connect();
    }

    public function verifyEventTarget(PDO $connection, EventInput $event): \DateTimeImmutable
    {
        $receivedAt = $this->serverNow($connection);

        $learner = $connection->prepare('SELECT 1 FROM dbo.learners WHERE learner_id = ?');
        $learner->execute([$event->learnerId]);
        if ($learner->fetchColumn() === false) {
            throw new ApiException(404, 'Learner was not found.');
        }

        $lecture = $connection->prepare('SELECT course_id FROM dbo.lectures WHERE lecture_id = ?');
        $lecture->execute([$event->lectureId]);
        $courseId = $lecture->fetchColumn();
        if ($courseId === false) {
            throw new ApiException(404, 'Lecture was not found.');
        }

        $enrollment = $connection->prepare(
            'SELECT enrollment_status, starts_at, ends_at
             FROM dbo.enrollments
             WHERE learner_id = ? AND course_id = ?'
        );
        $enrollment->execute([$event->learnerId, $courseId]);
        $row = $enrollment->fetch();
        if (!is_array($row)
            || $row['enrollment_status'] !== 'ACTIVE'
            || $receivedAt < self::databaseTime((string) $row['starts_at'])
            || ($row['ends_at'] !== null && $receivedAt > self::databaseTime((string) $row['ends_at']))) {
            throw new ApiException(403, 'Active enrollment is required.');
        }

        $startsAt = self::databaseTime((string) $row['starts_at']);
        $endsAt = $row['ends_at'] === null ? null : self::databaseTime((string) $row['ends_at']);
        if ($event->occurredAt < $startsAt || ($endsAt !== null && $event->occurredAt > $endsAt)) {
            throw new ApiException(422, 'occurred_at is outside the enrollment period.');
        }

        if ($event->occurredAt > $receivedAt->modify('+5 minutes')) {
            throw new ApiException(422, 'occurred_at is more than five minutes in the future.');
        }

        return $receivedAt;
    }

    /** @return array{event_seq:int,received_at:\DateTimeImmutable} */
    public function insertEvent(PDO $connection, EventInput $event, string $source, \DateTimeImmutable $receivedAt): array
    {
        $statement = $connection->prepare(
            'INSERT INTO dbo.learning_events (
                source, event_id, learner_id, lecture_id, session_id, sequence_no,
                event_type, position_seconds, occurred_at, received_at, payload_hash
             )
             OUTPUT INSERTED.event_seq, CONVERT(varchar(33), INSERTED.received_at, 126) AS received_at
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        self::execute($statement, [
            $source,
            $event->eventId,
            $event->learnerId,
            $event->lectureId,
            $event->sessionId,
            $event->sequenceNo,
            $event->eventType,
            $event->positionSeconds,
            $event->occurredAtSql(),
            self::sqlTime($receivedAt),
            $event->payloadHash,
        ]);
        $row = $statement->fetch();
        $statement->closeCursor();
        if (!is_array($row)) {
            throw new \RuntimeException('Event insert did not return its server fields.');
        }

        return [
            'event_seq' => (int) $row['event_seq'],
            'received_at' => self::databaseTime((string) $row['received_at']),
        ];
    }

    /** @return array<string, mixed>|null */
    public function lockProgress(PDO $connection, int $learnerId, int $lectureId): ?array
    {
        $statement = $connection->prepare(
            'SELECT learner_id, lecture_id, resume_position_seconds, furthest_position_seconds,
                    last_studied_at, last_session_id, last_sequence_no, last_received_at,
                    last_event_seq, completed_at
             FROM dbo.lecture_progress WITH (UPDLOCK, HOLDLOCK, INDEX(UQ_lecture_progress_learner_lecture))
             WHERE learner_id = ? AND lecture_id = ?'
        );
        $statement->execute([$learnerId, $lectureId]);
        $row = $statement->fetch();
        $statement->closeCursor();

        return is_array($row) ? $row : null;
    }

    /** @param array{event_seq:int,received_at:\DateTimeImmutable} $inserted */
    public function insertProgress(PDO $connection, EventInput $event, array $inserted): void
    {
        $statement = $connection->prepare(
            'INSERT INTO dbo.lecture_progress (
                learner_id, lecture_id, resume_position_seconds, furthest_position_seconds,
                last_studied_at, last_session_id, last_sequence_no, last_received_at,
                last_event_seq, completed_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        self::executeAndConsume($statement, [
            $event->learnerId,
            $event->lectureId,
            $event->positionSeconds,
            $event->positionSeconds,
            $event->occurredAtSql(),
            $event->sessionId,
            $event->sequenceNo,
            self::sqlTime($inserted['received_at']),
            $inserted['event_seq'],
            $event->eventType === 'COMPLETED' ? $event->occurredAtSql() : null,
        ]);
    }

    /** @param array{event_seq:int,received_at:\DateTimeImmutable} $inserted */
    public function updateProgress(PDO $connection, EventInput $event, array $inserted, bool $newer): void
    {
        $statement = $connection->prepare(
            'UPDATE dbo.lecture_progress
             SET resume_position_seconds = CASE WHEN ? = 1 THEN ? ELSE resume_position_seconds END,
                 furthest_position_seconds = CASE
                     WHEN furthest_position_seconds < ? THEN ?
                     ELSE furthest_position_seconds
                 END,
                 last_studied_at = CASE WHEN ? = 1 THEN ? ELSE last_studied_at END,
                 last_session_id = CASE WHEN ? = 1 THEN ? ELSE last_session_id END,
                 last_sequence_no = CASE WHEN ? = 1 THEN ? ELSE last_sequence_no END,
                 last_received_at = CASE WHEN ? = 1 THEN ? ELSE last_received_at END,
                 last_event_seq = CASE WHEN ? = 1 THEN ? ELSE last_event_seq END,
                 completed_at = CASE
                     WHEN completed_at IS NULL AND ? = N\'COMPLETED\' THEN ?
                     ELSE completed_at
                 END,
                 updated_at = SYSUTCDATETIME()
             WHERE learner_id = ? AND lecture_id = ?'
        );
        $isNewer = $newer ? 1 : 0;
        self::executeAndConsume($statement, [
            $isNewer, $event->positionSeconds,
            $event->positionSeconds, $event->positionSeconds,
            $isNewer, $event->occurredAtSql(),
            $isNewer, $event->sessionId,
            $isNewer, $event->sequenceNo,
            $isNewer, self::sqlTime($inserted['received_at']),
            $isNewer, $inserted['event_seq'],
            $event->eventType, $event->occurredAtSql(),
            $event->learnerId, $event->lectureId,
        ]);
    }

    public function existingPayloadHash(PDO $connection, string $source, string $eventId): ?string
    {
        $statement = $connection->prepare(
            'SELECT payload_hash FROM dbo.learning_events WHERE source = ? AND event_id = ?'
        );
        $statement->execute([$source, $eventId]);
        $hash = $statement->fetchColumn();

        return $hash === false ? null : (string) $hash;
    }

    public function guardianExists(PDO $connection, int $guardianId): bool
    {
        $statement = $connection->prepare('SELECT 1 FROM dbo.guardians WHERE guardian_id = ?');
        $statement->execute([$guardianId]);

        return $statement->fetchColumn() !== false;
    }

    public function learnerExists(PDO $connection, int $learnerId): bool
    {
        $statement = $connection->prepare('SELECT 1 FROM dbo.learners WHERE learner_id = ?');
        $statement->execute([$learnerId]);

        return $statement->fetchColumn() !== false;
    }

    public function guardianLinked(PDO $connection, int $guardianId, int $learnerId): bool
    {
        $statement = $connection->prepare(
            'SELECT 1 FROM dbo.guardian_links WHERE guardian_id = ? AND learner_id = ?'
        );
        $statement->execute([$guardianId, $learnerId]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<array<string, mixed>> */
    public function guardianProgress(PDO $connection, int $learnerId): array
    {
        $statement = $connection->prepare(
            'SELECT lecture_id, resume_position_seconds, furthest_position_seconds,
                    last_studied_at, completed_at
             FROM dbo.lecture_progress
             WHERE learner_id = ?
             ORDER BY lecture_id'
        );
        $statement->execute([$learnerId]);

        return $statement->fetchAll();
    }

    private function serverNow(PDO $connection): \DateTimeImmutable
    {
        $value = $connection->query('SELECT CONVERT(varchar(23), CAST(SYSUTCDATETIME() AS datetime2(3)), 126)')->fetchColumn();
        if ($value === false) {
            throw new \RuntimeException('SQL Server did not return its current time.');
        }

        return self::databaseTime((string) $value);
    }

    public static function databaseTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public static function sqlTime(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** @param list<mixed> $parameters */
    private static function execute(PDOStatement $statement, array $parameters): void
    {
        $executed = $statement->execute($parameters);
        $errorInfo = $statement->errorInfo();
        if ($executed !== false && ($errorInfo[0] ?? '00000') === '00000') {
            return;
        }

        $exception = new PDOException((string) ($errorInfo[2] ?? 'SQL Server statement execution failed.'));
        $exception->errorInfo = $errorInfo;

        throw $exception;
    }

    /** @param list<mixed> $parameters */
    private static function executeAndConsume(PDOStatement $statement, array $parameters): void
    {
        self::execute($statement, $parameters);
        while ($statement->nextRowset()) {
        }

        $errorInfo = $statement->errorInfo();
        if (($errorInfo[0] ?? '00000') === '00000') {
            return;
        }

        $exception = new PDOException((string) ($errorInfo[2] ?? 'SQL Server statement execution failed.'));
        $exception->errorInfo = $errorInfo;

        throw $exception;
    }
}
