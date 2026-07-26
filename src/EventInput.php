<?php

declare(strict_types=1);

namespace EduSync;

final class EventInput
{
    private const FIELDS = [
        'event_id',
        'learner_id',
        'lecture_id',
        'session_id',
        'sequence_no',
        'event_type',
        'position_seconds',
        'occurred_at',
    ];

    private function __construct(
        public readonly string $eventId,
        public readonly int $learnerId,
        public readonly int $lectureId,
        public readonly string $sessionId,
        public readonly int $sequenceNo,
        public readonly string $eventType,
        public readonly int $positionSeconds,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly string $occurredAtUtc,
        public readonly string $payloadHash,
    ) {
    }

    public static function fromRawJson(string $raw): self
    {
        if ($raw === '' || strlen($raw) > 20_000) {
            throw new ApiException(400, 'Request body must contain one small JSON object.');
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'Malformed JSON request body.');
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new ApiException(400, 'Request body must be a JSON object.');
        }

        $unexpected = array_diff(array_keys($payload), self::FIELDS);
        $missing = array_diff(self::FIELDS, array_keys($payload));
        if ($unexpected !== [] || $missing !== []) {
            throw new ApiException(400, 'Request body fields are invalid.');
        }

        $eventId = self::identifier($payload['event_id'] ?? null, 'event_id');
        $learnerId = self::positiveInteger($payload['learner_id'] ?? null, 'learner_id');
        $lectureId = self::positiveInteger($payload['lecture_id'] ?? null, 'lecture_id');
        $sessionId = self::identifier($payload['session_id'] ?? null, 'session_id');
        $sequenceNo = self::nonNegativeInteger($payload['sequence_no'] ?? null, 'sequence_no');
        $positionSeconds = self::nonNegativeInteger($payload['position_seconds'] ?? null, 'position_seconds', 2_147_483_647);

        if (!is_string($payload['event_type'] ?? null) || !in_array($payload['event_type'], ['CHECKPOINT', 'COMPLETED'], true)) {
            throw new ApiException(400, 'event_type must be CHECKPOINT or COMPLETED.');
        }

        $occurredAt = self::occurredAt($payload['occurred_at'] ?? null);
        $occurredAtUtc = $occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');
        $canonical = json_encode([
            'event_id' => $eventId,
            'learner_id' => $learnerId,
            'lecture_id' => $lectureId,
            'session_id' => $sessionId,
            'sequence_no' => $sequenceNo,
            'event_type' => $payload['event_type'],
            'position_seconds' => $positionSeconds,
            'occurred_at' => $occurredAtUtc,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self(
            $eventId,
            $learnerId,
            $lectureId,
            $sessionId,
            $sequenceNo,
            $payload['event_type'],
            $positionSeconds,
            $occurredAt->setTimezone(new \DateTimeZone('UTC')),
            $occurredAtUtc,
            hash('sha256', $canonical),
        );
    }

    public function occurredAtSql(): string
    {
        return $this->occurredAt->format('Y-m-d H:i:s.v');
    }

    private static function identifier(mixed $value, string $name): string
    {
        if (!is_string($value) || self::unicodeLength($value) < 1 || self::unicodeLength($value) > 100 || preg_match('/[\p{C}]/u', $value)) {
            throw new ApiException(400, sprintf('%s must be a 1-100 character string.', $name));
        }

        return $value;
    }

    private static function positiveInteger(mixed $value, string $name): int
    {
        if (!is_int($value) || $value < 1) {
            throw new ApiException(400, sprintf('%s must be a positive integer.', $name));
        }

        return $value;
    }

    private static function nonNegativeInteger(mixed $value, string $name, int $maximum = PHP_INT_MAX): int
    {
        if (!is_int($value) || $value < 0 || $value > $maximum) {
            throw new ApiException(400, sprintf('%s must be a non-negative integer.', $name));
        }

        return $value;
    }

    private static function occurredAt(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value)
            || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\\.[0-9]{1,3})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/', $value)) {
            throw new ApiException(400, 'occurred_at must be an RFC3339 timestamp with timezone.');
        }

        try {
            $parsed = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \Exception('Invalid RFC3339 calendar value.');
            }

            return $parsed;
        } catch (\Exception) {
            throw new ApiException(400, 'occurred_at must be a valid RFC3339 timestamp.');
        }
    }

    private static function unicodeLength(string $value): int
    {
        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new ApiException(400, 'String fields must be valid UTF-8.');
        }

        return $length;
    }
}
