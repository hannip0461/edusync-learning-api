<?php

declare(strict_types=1);

use EduSync\Config;
use EduSync\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

const TEST_GUARDIAN_ID = 1001;
const TEST_LEARNER_ID = 2001;
// Bearer 주체나 보호자 연결에 포함되지 않은 수강 학습자
const TEST_UNLINKED_LEARNER_ID = 900000003;
const TEST_COURSE_ID = 900000004;
const TEST_LECTURE_ID = 900000005;
const TEST_UNENROLLED_COURSE_ID = 900000006;
const TEST_UNENROLLED_LECTURE_ID = 900000007;

/** @param mixed $actual */
function assertIntegrationSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s. Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
    }
}

function assertIntegrationTrue(bool $actual, string $message): void
{
    assertIntegrationSame(true, $actual, $message);
}

/** @param array<string, string> $headers @return array{status:int,body:string,json:array<string,mixed>|null} */
function requestIntegration(string $method, string $path, array $headers = [], string $body = ''): array
{
    $lines = ['Content-Type: application/json'];
    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $lines),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $responseBody = file_get_contents('http://127.0.0.1' . $path, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s([0-9]{3})\s/', $statusLine, $matches)) {
        throw new RuntimeException('HTTP response did not include a status line.');
    }
    $decoded = json_decode($responseBody === false ? '' : $responseBody, true);

    return [
        'status' => (int) $matches[1],
        'body' => $responseBody === false ? '' : $responseBody,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

/** @param array<string, mixed> $payload */
function playerRequest(Config $config, array $payload, ?string $signature = null, ?string $timestamp = null): array
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $timestamp ??= (string) time();
    $signature ??= 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $raw, $config->playerHmacSecret());

    return requestIntegration('POST', '/api/v1/player-events', [
        'X-Player-Timestamp' => $timestamp,
        'X-Player-Signature' => $signature,
    ], $raw);
}

/** @return array<string, mixed> */
function eventPayload(string $eventId, int $learnerId, int $lectureId, string $sessionId, int $sequenceNo, int $positionSeconds, string $occurredAt, string $eventType = 'CHECKPOINT'): array
{
    return [
        'event_id' => $eventId,
        'learner_id' => $learnerId,
        'lecture_id' => $lectureId,
        'session_id' => $sessionId,
        'sequence_no' => $sequenceNo,
        'event_type' => $eventType,
        'position_seconds' => $positionSeconds,
        'occurred_at' => $occurredAt,
    ];
}

/** @param array<string, mixed> $payload */
function learnerRequest(Config $config, array $payload, ?string $token = null, ?string $raw = null): array
{
    $raw ??= json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return requestIntegration('POST', '/api/v1/learning-events', [
        'Authorization' => 'Bearer ' . ($token ?? $config->appBearerToken()),
    ], $raw);
}

/** @param list<mixed> $parameters */
function executeIntegration(PDO $connection, string $sql, array $parameters = []): void
{
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
}

/** @return array{0:int,1:int,2:int,3:string|null} */
function snapshotValues(PDOStatement $statement): array
{
    $row = $statement->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Expected integration progress snapshot was not found.');
    }

    return [
        (int) $row['resume_position_seconds'],
        (int) $row['furthest_position_seconds'],
        (int) $row['last_sequence_no'],
        $row['completed_at'] === null ? null : (string) $row['completed_at'],
    ];
}

$exitCode = 0;
try {
    $config = Config::fromEnvironment($_ENV + $_SERVER);
    assertIntegrationSame(TEST_LEARNER_ID, $config->appBearerLearnerId(), 'Integration app subject must use the seed learner ID');
    assertIntegrationSame(TEST_GUARDIAN_ID, $config->guardianBearerId(), 'Integration guardian subject must use the seed guardian ID');
    $database = new Database($config);
    $connection = $database->connect();

    $docs = requestIntegration('GET', '/docs');
    assertIntegrationSame(200, $docs['status'], 'Swagger UI document must be served');
    assertIntegrationTrue(str_contains($docs['body'], "url: '../openapi.yaml'"), 'Swagger UI must load the single OpenAPI route');
    $docsSlash = requestIntegration('GET', '/docs/');
    assertIntegrationSame(200, $docsSlash['status'], 'Swagger UI document with a trailing slash must be served');
    assertIntegrationSame($docs['body'], $docsSlash['body'], 'Swagger UI routes must serve the same document');
    assertIntegrationSame(200, requestIntegration('GET', '/swagger-ui/swagger-ui.css')['status'], 'Swagger UI CSS asset must be served');
    assertIntegrationSame(200, requestIntegration('GET', '/swagger-ui/swagger-ui-bundle.js')['status'], 'Swagger UI bundle asset must be served');
    assertIntegrationSame(200, requestIntegration('GET', '/openapi.yaml')['status'], 'OpenAPI document must be served');

    executeIntegration($connection, 'DELETE FROM dbo.lecture_progress WHERE learner_id IN (?, ?) AND lecture_id IN (?, ?)', [TEST_LEARNER_ID, TEST_UNLINKED_LEARNER_ID, TEST_LECTURE_ID, TEST_UNENROLLED_LECTURE_ID]);
    executeIntegration($connection, 'DELETE FROM dbo.learning_events WHERE learner_id IN (?, ?) AND lecture_id IN (?, ?)', [TEST_LEARNER_ID, TEST_UNLINKED_LEARNER_ID, TEST_LECTURE_ID, TEST_UNENROLLED_LECTURE_ID]);
    executeIntegration($connection, 'DELETE FROM dbo.enrollments WHERE learner_id IN (?, ?) AND course_id IN (?, ?)', [TEST_LEARNER_ID, TEST_UNLINKED_LEARNER_ID, TEST_COURSE_ID, TEST_UNENROLLED_COURSE_ID]);
    executeIntegration($connection, 'DELETE FROM dbo.lectures WHERE lecture_id IN (?, ?)', [TEST_LECTURE_ID, TEST_UNENROLLED_LECTURE_ID]);
    executeIntegration($connection, 'DELETE FROM dbo.courses WHERE course_id IN (?, ?)', [TEST_COURSE_ID, TEST_UNENROLLED_COURSE_ID]);
    executeIntegration($connection, 'DELETE FROM dbo.learners WHERE learner_id = ?', [TEST_UNLINKED_LEARNER_ID]);

    executeIntegration($connection, 'INSERT INTO dbo.learners (learner_id, display_name) VALUES (?, N\'Unlinked Learner\')', [TEST_UNLINKED_LEARNER_ID]);
    executeIntegration($connection, 'INSERT INTO dbo.courses (course_id, title, is_active) VALUES (?, N\'Integration Course\', 1), (?, N\'Unenrolled Course\', 1)', [TEST_COURSE_ID, TEST_UNENROLLED_COURSE_ID]);
    executeIntegration($connection, 'INSERT INTO dbo.lectures (lecture_id, course_id, title, lecture_order, duration_seconds, is_active) VALUES (?, ?, N\'Integration Lecture\', 1, 600, 1), (?, ?, N\'Unenrolled Lecture\', 1, 600, 1)', [TEST_LECTURE_ID, TEST_COURSE_ID, TEST_UNENROLLED_LECTURE_ID, TEST_UNENROLLED_COURSE_ID]);
    executeIntegration($connection, 'INSERT INTO dbo.enrollments (learner_id, course_id, enrollment_status, starts_at, ends_at) VALUES (?, ?, N\'ACTIVE\', DATEADD(day, -1, SYSUTCDATETIME()), DATEADD(day, 1, SYSUTCDATETIME())), (?, ?, N\'ACTIVE\', DATEADD(day, -1, SYSUTCDATETIME()), DATEADD(day, 1, SYSUTCDATETIME()))', [TEST_LEARNER_ID, TEST_COURSE_ID, TEST_UNLINKED_LEARNER_ID, TEST_COURSE_ID]);

    $baseTime = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-2 minutes');
    $occurred = $baseTime->format('Y-m-d\\TH:i:s.v\\Z');
    $unknownRoute = requestIntegration('GET', '/api/v1/not-registered');
    assertIntegrationSame(404, $unknownRoute['status'], 'Unknown route must return JSON 404');
    assertIntegrationSame(404, $unknownRoute['json']['error']['status'] ?? null, 'Unknown route JSON error status changed');
    $wrongMethod = requestIntegration('POST', '/health');
    assertIntegrationSame(405, $wrongMethod['status'], 'Wrong method must return JSON 405');
    assertIntegrationSame(405, $wrongMethod['json']['error']['status'] ?? null, 'Wrong method JSON error status changed');
    $valid = eventPayload('auth-check', TEST_LEARNER_ID, TEST_LECTURE_ID, 'auth-session', 1, 10, $occurred);
    assertIntegrationSame(401, requestIntegration('POST', '/api/v1/learning-events', [], json_encode($valid, JSON_THROW_ON_ERROR))['status'], 'Missing bearer must be rejected');
    assertIntegrationSame(401, learnerRequest($config, $valid, 'incorrect-token')['status'], 'Incorrect bearer must be rejected');
    assertIntegrationSame(403, learnerRequest($config, eventPayload('subject-mismatch', TEST_UNLINKED_LEARNER_ID, TEST_LECTURE_ID, 'auth-session', 1, 10, $occurred))['status'], 'Bearer subject mismatch must be forbidden');
    assertIntegrationSame(400, learnerRequest($config, $valid, null, '{')['status'], 'Malformed JSON must be rejected');
    $wrongType = $valid;
    $wrongType['sequence_no'] = '1';
    assertIntegrationSame(400, learnerRequest($config, $wrongType)['status'], 'Wrong JSON field type must be rejected');
    $unknown = $valid;
    $unknown['source'] = 'forbidden';
    assertIntegrationSame(400, learnerRequest($config, $unknown)['status'], 'Unknown JSON field must be rejected');
    assertIntegrationSame(404, playerRequest($config, eventPayload('missing-learner', 900000099, TEST_LECTURE_ID, 'missing', 1, 1, $occurred))['status'], 'Missing learner must return 404');
    assertIntegrationSame(404, playerRequest($config, eventPayload('missing-lecture', TEST_LEARNER_ID, 900000098, 'missing', 1, 1, $occurred))['status'], 'Missing lecture must return 404');
    assertIntegrationSame(403, playerRequest($config, eventPayload('unenrolled', TEST_LEARNER_ID, TEST_UNENROLLED_LECTURE_ID, 'none', 1, 1, $occurred))['status'], 'Missing enrollment must return 403');
    assertIntegrationSame(422, playerRequest($config, eventPayload('before-enrollment', TEST_LEARNER_ID, TEST_LECTURE_ID, 'range', 1, 1, $baseTime->modify('-2 days')->format('Y-m-d\\TH:i:s.v\\Z')))['status'], 'Event before enrollment must return 422');
    assertIntegrationSame(422, playerRequest($config, eventPayload('after-enrollment', TEST_LEARNER_ID, TEST_LECTURE_ID, 'range', 2, 1, $baseTime->modify('+2 days')->format('Y-m-d\\TH:i:s.v\\Z')))['status'], 'Event after enrollment must return 422');
    assertIntegrationSame(422, playerRequest($config, eventPayload('future', TEST_LEARNER_ID, TEST_LECTURE_ID, 'future', 1, 1, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+6 minutes')->format('Y-m-d\\TH:i:s.v\\Z')))['status'], 'Far-future event must return 422');

    $checkpoint = eventPayload('checkpoint-new', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-one', 1, 100, $occurred);
    $newResponse = learnerRequest($config, $checkpoint);
    assertIntegrationSame(200, $newResponse['status'], 'New checkpoint must succeed');
    assertIntegrationSame(['applied' => true, 'duplicate' => false], $newResponse['json'], 'New checkpoint response changed');
    $eventCount = $connection->prepare('SELECT COUNT(*) FROM dbo.learning_events WHERE source = ? AND event_id = ?');
    $eventCount->execute([$config->appEventSource(), 'checkpoint-new']);
    assertIntegrationSame(1, (int) $eventCount->fetchColumn(), 'New checkpoint event must be stored once');
    $snapshot = $connection->prepare('SELECT resume_position_seconds, furthest_position_seconds, last_sequence_no, completed_at FROM dbo.lecture_progress WHERE learner_id = ? AND lecture_id = ?');
    $snapshot->execute([TEST_LEARNER_ID, TEST_LECTURE_ID]);
    assertIntegrationSame([100, 100, 1, null], snapshotValues($snapshot), 'New checkpoint snapshot must be stored with the event');

    $reordered = json_encode(array_reverse($checkpoint, true), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $duplicateResponse = learnerRequest($config, $checkpoint, null, $reordered);
    assertIntegrationSame(200, $duplicateResponse['status'], 'Semantic duplicate must succeed');
    assertIntegrationSame(['applied' => false, 'duplicate' => true], $duplicateResponse['json'], 'Semantic duplicate response changed');
    $eventCount->execute([$config->appEventSource(), 'checkpoint-new']);
    assertIntegrationSame(1, (int) $eventCount->fetchColumn(), 'Semantic duplicate must not add an event');
    $snapshot->execute([TEST_LEARNER_ID, TEST_LECTURE_ID]);
    assertIntegrationSame([100, 100, 1, null], snapshotValues($snapshot), 'Semantic duplicate must not change the snapshot');
    $conflict = $checkpoint;
    $conflict['position_seconds'] = 101;
    assertIntegrationSame(409, learnerRequest($config, $conflict)['status'], 'Conflicting duplicate event_id must return 409');

    $late = eventPayload('checkpoint-late', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-one', 0, 150, $baseTime->modify('-10 minutes')->format('Y-m-d\\TH:i:s.v\\Z'));
    assertIntegrationSame(200, learnerRequest($config, $late)['status'], 'Late checkpoint must still be stored');
    $snapshot->execute([TEST_LEARNER_ID, TEST_LECTURE_ID]);
    assertIntegrationSame([100, 150, 1, null], snapshotValues($snapshot), 'Late checkpoint must only advance furthest position');

    $rewind = eventPayload('checkpoint-rewind', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-one', 2, 50, $baseTime->modify('+1 minute')->format('Y-m-d\\TH:i:s.v\\Z'));
    assertIntegrationSame(200, learnerRequest($config, $rewind)['status'], 'Newer rewind checkpoint must succeed');
    $snapshot->execute([TEST_LEARNER_ID, TEST_LECTURE_ID]);
    assertIntegrationSame([50, 150, 2, null], snapshotValues($snapshot), 'Newer rewind must lower resume and preserve furthest position');

    $differentSessionNewer = eventPayload('different-session-newer', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-two', 1, 80, $baseTime->modify('+1 minute')->format('Y-m-d\\TH:i:s.v\\Z'));
    assertIntegrationSame(200, learnerRequest($config, $differentSessionNewer)['status'], 'Newer different-session checkpoint must succeed');
    $snapshot->execute([TEST_LEARNER_ID, TEST_LECTURE_ID]);
    assertIntegrationSame([80, 150, 1, null], snapshotValues($snapshot), 'Different-session tie must use later received event for resume');
    $differentSessionOlder = eventPayload('different-session-older', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-three', 1, 175, $baseTime->format('Y-m-d\\TH:i:s.v\\Z'));
    assertIntegrationSame(200, learnerRequest($config, $differentSessionOlder)['status'], 'Older different-session checkpoint must be stored');
    $snapshot->execute([TEST_LEARNER_ID, TEST_LECTURE_ID]);
    assertIntegrationSame([80, 175, 1, null], snapshotValues($snapshot), 'Older different-session checkpoint must only advance furthest position');

    $completed = eventPayload('completed', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-one', 3, 150, $baseTime->modify('+2 minutes')->format('Y-m-d\\TH:i:s.v\\Z'), 'COMPLETED');
    assertIntegrationSame(200, learnerRequest($config, $completed)['status'], 'Completed event must succeed');
    $snapshot->execute([TEST_LEARNER_ID, TEST_LECTURE_ID]);
    $completedRow = $snapshot->fetch();
    assertIntegrationTrue($completedRow['completed_at'] !== null, 'Completed event must set completed_at');
    $completedAt = (string) $completedRow['completed_at'];
    $afterCompleteCheckpoint = eventPayload('after-completed', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-one', 4, 70, $baseTime->modify('+3 minutes')->format('Y-m-d\\TH:i:s.v\\Z'));
    assertIntegrationSame(200, learnerRequest($config, $afterCompleteCheckpoint)['status'], 'Checkpoint after completed event must succeed');
    $snapshot->execute([TEST_LEARNER_ID, TEST_LECTURE_ID]);
    $afterCheckpointRow = $snapshot->fetch();
    assertIntegrationSame($completedAt, (string) $afterCheckpointRow['completed_at'], 'Checkpoint must not clear completed_at');
    $secondCompleted = eventPayload('completed-again', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-one', 5, 160, $baseTime->modify('+4 minutes')->format('Y-m-d\\TH:i:s.v\\Z'), 'COMPLETED');
    assertIntegrationSame(200, learnerRequest($config, $secondCompleted)['status'], 'Second completed event must succeed');
    $snapshot->execute([TEST_LEARNER_ID, TEST_LECTURE_ID]);
    $secondCompletedRow = $snapshot->fetch();
    assertIntegrationSame($completedAt, (string) $secondCompletedRow['completed_at'], 'Second completed event must not replace completed_at');

    $crossSource = eventPayload('cross-source', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-two', 1, 80, $baseTime->modify('+4 minutes')->format('Y-m-d\\TH:i:s.v\\Z'));
    assertIntegrationSame(200, learnerRequest($config, $crossSource)['status'], 'Bearer source event must succeed');
    assertIntegrationSame(200, playerRequest($config, $crossSource)['status'], 'Player source event with same event_id must succeed');
    $crossCount = $connection->prepare('SELECT COUNT(*) FROM dbo.learning_events WHERE event_id = ?');
    $crossCount->execute(['cross-source']);
    assertIntegrationSame(2, (int) $crossCount->fetchColumn(), 'Same event_id must be independent across normalized sources');

    $playerOk = eventPayload('player-ok', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-player', 1, 90, $baseTime->modify('+4 minutes')->format('Y-m-d\\TH:i:s.v\\Z'));
    assertIntegrationSame(200, playerRequest($config, $playerOk)['status'], 'Valid HMAC must succeed');
    $beforeFailures = (int) $connection->query("SELECT COUNT(*) FROM dbo.learning_events WHERE event_id IN ('player-bad-signature', 'player-stale')")->fetchColumn();
    $badSignature = eventPayload('player-bad-signature', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-player', 2, 91, $occurred);
    assertIntegrationSame(401, playerRequest($config, $badSignature, 'sha256=' . str_repeat('0', 64))['status'], 'Bad HMAC must return 401');
    $stale = eventPayload('player-stale', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-player', 3, 92, $occurred);
    assertIntegrationSame(401, playerRequest($config, $stale, null, (string) (time() - $config->hmacToleranceSeconds() - 1))['status'], 'Stale HMAC timestamp must return 401');
    $afterFailures = (int) $connection->query("SELECT COUNT(*) FROM dbo.learning_events WHERE event_id IN ('player-bad-signature', 'player-stale')")->fetchColumn();
    assertIntegrationSame($beforeFailures, $afterFailures, 'Failed HMAC requests must not modify the database');

    // Bearer는 학습자 한 명에, HMAC은 신뢰된 발행자에 결속된다(DECISIONS D10).
    $foreignEvent = eventPayload('foreign-learner-write', TEST_UNLINKED_LEARNER_ID, TEST_LECTURE_ID, 'session-foreign', 1, 60, $occurred);
    $foreignCount = $connection->prepare('SELECT COUNT(*) FROM dbo.learning_events WHERE event_id = ?');
    assertIntegrationSame(403, learnerRequest($config, $foreignEvent)['status'], 'Bearer path must refuse a learner_id other than its bound subject');
    $foreignCount->execute(['foreign-learner-write']);
    assertIntegrationSame(0, (int) $foreignCount->fetchColumn(), 'Refused bearer write must not store an event');
    assertIntegrationSame(200, playerRequest($config, $foreignEvent)['status'], 'HMAC publisher path must accept an enrolled learner it does not own');
    $foreignCount->execute(['foreign-learner-write']);
    assertIntegrationSame(1, (int) $foreignCount->fetchColumn(), 'Accepted HMAC publisher write must store exactly one event');
    assertIntegrationSame(404, playerRequest($config, eventPayload('foreign-missing-learner', 900000097, TEST_LECTURE_ID, 'session-foreign', 2, 60, $occurred))['status'], 'HMAC publisher path must still require an existing learner');
    assertIntegrationSame(403, playerRequest($config, eventPayload('foreign-unenrolled-course', TEST_UNLINKED_LEARNER_ID, TEST_UNENROLLED_LECTURE_ID, 'session-foreign', 3, 60, $occurred))['status'], 'HMAC publisher path must still require an active enrollment');

    // HMAC 재전송은 유니크 제약에서 멱등 처리된다(DECISIONS D11).
    $replayEvent = eventPayload('hmac-replay', TEST_LEARNER_ID, TEST_LECTURE_ID, 'session-replay', 9, 140, $occurred);
    $replayTimestamp = (string) time();
    $replaySignature = 'sha256=' . hash_hmac(
        'sha256',
        $replayTimestamp . "\n" . json_encode($replayEvent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        $config->playerHmacSecret(),
    );
    $firstSend = playerRequest($config, $replayEvent, $replaySignature, $replayTimestamp);
    assertIntegrationSame(200, $firstSend['status'], 'First signed player request must be accepted');
    assertIntegrationSame(['applied' => true, 'duplicate' => false], $firstSend['json'], 'First signed player request must apply the event');
    $snapshot->execute([TEST_LEARNER_ID, TEST_LECTURE_ID]);
    $beforeReplay = snapshotValues($snapshot);
    $replaySend = playerRequest($config, $replayEvent, $replaySignature, $replayTimestamp);
    assertIntegrationSame(200, $replaySend['status'], 'Replayed signature must still pass the HMAC layer: there is no nonce store');
    assertIntegrationSame(['applied' => false, 'duplicate' => true], $replaySend['json'], 'Replay must be absorbed as a duplicate by the unique constraint');
    $replayCount = $connection->prepare('SELECT COUNT(*) FROM dbo.learning_events WHERE source = ? AND event_id = ?');
    $replayCount->execute([$config->playerEventSource(), 'hmac-replay']);
    assertIntegrationSame(1, (int) $replayCount->fetchColumn(), 'Replay must not store a second event row');
    $snapshot->execute([TEST_LEARNER_ID, TEST_LECTURE_ID]);
    assertIntegrationSame($beforeReplay, snapshotValues($snapshot), 'Replay must not change the progress snapshot');

    assertIntegrationSame(403, requestIntegration('GET', '/api/v1/guardians/' . (TEST_GUARDIAN_ID + 1) . '/learners/' . TEST_LEARNER_ID . '/progress', ['Authorization' => 'Bearer ' . $config->guardianBearerToken()])['status'], 'Guardian path subject mismatch must return 403');
    assertIntegrationSame(403, requestIntegration('GET', '/api/v1/guardians/' . TEST_GUARDIAN_ID . '/learners/' . TEST_UNLINKED_LEARNER_ID . '/progress', ['Authorization' => 'Bearer ' . $config->guardianBearerToken()])['status'], 'Missing guardian link must return 403');
    $guardianSuccess = requestIntegration('GET', '/api/v1/guardians/' . TEST_GUARDIAN_ID . '/learners/' . TEST_LEARNER_ID . '/progress', ['Authorization' => 'Bearer ' . $config->guardianBearerToken()]);
    assertIntegrationSame(200, $guardianSuccess['status'], 'Linked guardian progress request must succeed');
    assertIntegrationSame(TEST_GUARDIAN_ID, $guardianSuccess['json']['guardian_id'] ?? null, 'Guardian response must be scoped to the authenticated guardian');
    assertIntegrationSame(TEST_LEARNER_ID, $guardianSuccess['json']['learner_id'] ?? null, 'Guardian response must contain the requested learner');
    assertIntegrationTrue(count($guardianSuccess['json']['progress'] ?? []) > 0, 'Guardian response must include learner progress');

    fwrite(STDOUT, "PASS MSSQL HTTP integration tests\n");
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("FAIL %s\n", $exception->getMessage()));
    $exitCode = 1;
} finally {
    if (isset($connection) && $connection instanceof PDO) {
        try {
            executeIntegration($connection, 'DELETE FROM dbo.lecture_progress WHERE learner_id IN (?, ?) AND lecture_id IN (?, ?)', [TEST_LEARNER_ID, TEST_UNLINKED_LEARNER_ID, TEST_LECTURE_ID, TEST_UNENROLLED_LECTURE_ID]);
            executeIntegration($connection, 'DELETE FROM dbo.learning_events WHERE learner_id IN (?, ?) AND lecture_id IN (?, ?)', [TEST_LEARNER_ID, TEST_UNLINKED_LEARNER_ID, TEST_LECTURE_ID, TEST_UNENROLLED_LECTURE_ID]);
            executeIntegration($connection, 'DELETE FROM dbo.enrollments WHERE learner_id IN (?, ?) AND course_id IN (?, ?)', [TEST_LEARNER_ID, TEST_UNLINKED_LEARNER_ID, TEST_COURSE_ID, TEST_UNENROLLED_COURSE_ID]);
            executeIntegration($connection, 'DELETE FROM dbo.lectures WHERE lecture_id IN (?, ?)', [TEST_LECTURE_ID, TEST_UNENROLLED_LECTURE_ID]);
            executeIntegration($connection, 'DELETE FROM dbo.courses WHERE course_id IN (?, ?)', [TEST_COURSE_ID, TEST_UNENROLLED_COURSE_ID]);
            executeIntegration($connection, 'DELETE FROM dbo.learners WHERE learner_id = ?', [TEST_UNLINKED_LEARNER_ID]);
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, sprintf("Cleanup failed: %s\n", $cleanupException->getMessage()));
            $exitCode = 1;
        }
    }
}

exit($exitCode);
