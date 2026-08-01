<?php

declare(strict_types=1);

use EduSync\Config;
use EduSync\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

const DEMO_COURSE_ID = 940000040;
const DEMO_LECTURE_ID = 940000041;
const DEMO_UNENROLLED_COURSE_ID = 940000042;
const DEMO_UNENROLLED_LECTURE_ID = 940000043;
const DEMO_UNLINKED_LEARNER_ID = 940000044;

/** @param array<string, string> $headers @return array{status:int,body:string,json:array<string,mixed>|null} */
function demoRequest(string $method, string $path, array $headers = [], string $body = ''): array
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
        'timeout' => 20,
    ]]);
    $responseBody = file_get_contents('http://127.0.0.1' . $path, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s([0-9]{3})\s/', $statusLine, $matches)) {
        throw new RuntimeException('Demo HTTP request did not return a status line.');
    }
    $decoded = json_decode($responseBody === false ? '' : $responseBody, true);

    return [
        'status' => (int) $matches[1],
        'body' => $responseBody === false ? '' : $responseBody,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

/** @param array<string, mixed> $payload @return array{status:int,body:string,json:array<string,mixed>|null} */
function demoLearnerRequest(Config $config, array $payload, ?string $raw = null): array
{
    return demoRequest('POST', '/api/v1/learning-events', [
        'Authorization' => 'Bearer ' . $config->appBearerToken(),
    ], $raw ?? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

/** @param array<string, mixed> $payload @return array{status:int,body:string,json:array<string,mixed>|null} */
function demoPlayerRequest(Config $config, array $payload): array
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();

    return demoRequest('POST', '/api/v1/player-events', [
        'X-Player-Timestamp' => $timestamp,
        'X-Player-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . "\n" . $raw, $config->playerHmacSecret()),
    ], $raw);
}

/** @return array<string, mixed> */
function demoPayload(string $eventId, int $learnerId, int $lectureId, string $sessionId, int $sequenceNo, int $positionSeconds, DateTimeImmutable $occurredAt, string $eventType = 'CHECKPOINT'): array
{
    return [
        'event_id' => $eventId,
        'learner_id' => $learnerId,
        'lecture_id' => $lectureId,
        'session_id' => $sessionId,
        'sequence_no' => $sequenceNo,
        'event_type' => $eventType,
        'position_seconds' => $positionSeconds,
        'occurred_at' => $occurredAt->format('Y-m-d\\TH:i:s.v\\Z'),
    ];
}

/** @param list<mixed> $parameters */
function demoExecute(PDO $connection, string $sql, array $parameters = []): void
{
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
}

/** @param list<mixed> $parameters */
function demoScalar(PDO $connection, string $sql, array $parameters = []): mixed
{
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchColumn();
}

/** @return array<string, mixed>|null */
function demoSnapshot(PDO $connection, int $learnerId): ?array
{
    $statement = $connection->prepare(
        'SELECT resume_position_seconds, furthest_position_seconds, last_session_id,
                last_sequence_no, completed_at
         FROM dbo.lecture_progress
         WHERE learner_id = ? AND lecture_id = ?'
    );
    $statement->execute([$learnerId, DEMO_LECTURE_ID]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

/** @param list<array<string, mixed>> $scenarios @param array<string, mixed> $request @param array<string, mixed>|string|null $response @param array<string, mixed> $details */
function demoScenario(array &$scenarios, string $name, bool $passed, int|string $status, array $request, array|string|null $response, array $details = []): void
{
    $scenarios[] = [
        'name' => $name,
        'passed' => $passed,
        'status' => $status,
        'request' => $request,
        'response' => $response,
        'details' => $details,
    ];
}

function cleanupDemoFixture(PDO $connection, int $learnerId): void
{
    demoExecute($connection, 'DELETE FROM dbo.lecture_progress WHERE learner_id = ? AND lecture_id IN (?, ?)', [$learnerId, DEMO_LECTURE_ID, DEMO_UNENROLLED_LECTURE_ID]);
    demoExecute($connection, 'DELETE FROM dbo.learning_events WHERE learner_id = ? AND lecture_id IN (?, ?)', [$learnerId, DEMO_LECTURE_ID, DEMO_UNENROLLED_LECTURE_ID]);
    demoExecute($connection, 'DELETE FROM dbo.enrollments WHERE learner_id = ? AND course_id IN (?, ?)', [$learnerId, DEMO_COURSE_ID, DEMO_UNENROLLED_COURSE_ID]);
    demoExecute($connection, 'DELETE FROM dbo.lectures WHERE lecture_id IN (?, ?)', [DEMO_LECTURE_ID, DEMO_UNENROLLED_LECTURE_ID]);
    demoExecute($connection, 'DELETE FROM dbo.courses WHERE course_id IN (?, ?)', [DEMO_COURSE_ID, DEMO_UNENROLLED_COURSE_ID]);
    demoExecute($connection, 'DELETE FROM dbo.learners WHERE learner_id = ?', [DEMO_UNLINKED_LEARNER_ID]);
}

function createDemoFixture(PDO $connection, int $learnerId): void
{
    demoExecute($connection, 'INSERT INTO dbo.learners (learner_id, display_name) VALUES (?, N\'Demo Unlinked Learner\')', [DEMO_UNLINKED_LEARNER_ID]);
    demoExecute($connection, 'INSERT INTO dbo.courses (course_id, title, is_active) VALUES (?, N\'Demo Course\', 1), (?, N\'Demo Unenrolled Course\', 1)', [DEMO_COURSE_ID, DEMO_UNENROLLED_COURSE_ID]);
    demoExecute($connection, 'INSERT INTO dbo.lectures (lecture_id, course_id, title, lecture_order, duration_seconds, is_active) VALUES (?, ?, N\'Demo Lecture\', 1, 600, 1), (?, ?, N\'Demo Unenrolled Lecture\', 1, 600, 1)', [DEMO_LECTURE_ID, DEMO_COURSE_ID, DEMO_UNENROLLED_LECTURE_ID, DEMO_UNENROLLED_COURSE_ID]);
    demoExecute($connection, 'INSERT INTO dbo.enrollments (learner_id, course_id, enrollment_status, starts_at, ends_at) VALUES (?, ?, N\'ACTIVE\', DATEADD(day, -1, SYSUTCDATETIME()), DATEADD(day, 1, SYSUTCDATETIME()))', [$learnerId, DEMO_COURSE_ID]);
}

/** @return array{exit_code:int,stdout:string} */
function demoProcess(array $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Demo subprocess could not start.');
    }
    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit_code' => proc_close($process), 'stdout' => $stdout];
}

$scenarios = [];
$report = [
    'generated_at_utc' => gmdate('c'),
    'environment' => [
        'runtime' => 'Docker Compose / PHP ' . PHP_VERSION,
        'database' => 'PDO_SQLSRV를 통한 SQL Server',
        'authentication' => '로컬 테스트 자격 증명',
    ],
    'scenarios' => [],
    'summary' => ['passed' => false, 'count' => 0],
    'final_state' => [],
    'limitations' => [
        'Compose 실행은 Classic ASP 소스와 JSON 계약을 확인합니다. 선택적 Windows IIS 엔드포인트는 scripts/setup-iis-classic-asp.ps1로 별도 구성합니다.',
        '시나리오는 정확성을 검증하며 운영 부하나 처리 용량을 측정하지 않습니다.',
    ],
];
$exitCode = 0;

try {
    $config = Config::fromEnvironment($_ENV + $_SERVER);
    $database = new Database($config);
    $connection = $database->connect();
    $learnerId = $config->appBearerLearnerId();
    $guardianId = $config->guardianBearerId();
    if ($learnerId !== 2001 || $guardianId !== 1001) {
        throw new RuntimeException('Demo requires the local seed subjects.');
    }

    cleanupDemoFixture($connection, $learnerId);
    createDemoFixture($connection, $learnerId);
    $baseTime = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-2 minutes');

    $health = demoRequest('GET', '/health');
    demoScenario($scenarios, '상태 확인', $health['status'] === 200 && ($health['json']['probe'] ?? null) === 1, $health['status'], ['method' => 'GET', 'path' => '/health'], $health['json']);

    $initial = demoPayload('demo-checkpoint', $learnerId, DEMO_LECTURE_ID, 'demo-session', 1, 100, $baseTime);
    $anonymous = demoRequest('POST', '/api/v1/learning-events', [], json_encode($initial, JSON_THROW_ON_ERROR));
    demoScenario($scenarios, '인증 필수', $anonymous['status'] === 401, $anonymous['status'], ['method' => 'POST', 'path' => '/api/v1/learning-events', 'authentication' => '미포함'], $anonymous['json']);

    $unenrolled = demoLearnerRequest($config, demoPayload('demo-unenrolled', $learnerId, DEMO_UNENROLLED_LECTURE_ID, 'demo-none', 1, 1, $baseTime));
    demoScenario($scenarios, '유효한 수강 등록 필수', $unenrolled['status'] === 403, $unenrolled['status'], ['method' => 'POST', 'path' => '/api/v1/learning-events', 'authentication' => '학습자 Bearer 값 제외'], $unenrolled['json']);

    $created = demoLearnerRequest($config, $initial);
    $createdSnapshot = demoSnapshot($connection, $learnerId);
    demoScenario($scenarios, '신규 체크포인트', $created['status'] === 200 && ($created['json']['applied'] ?? null) === true && (int) ($createdSnapshot['resume_position_seconds'] ?? -1) === 100, $created['status'], ['method' => 'POST', 'path' => '/api/v1/learning-events', 'body' => $initial], $created['json'], ['snapshot' => $createdSnapshot]);

    $late = demoLearnerRequest($config, demoPayload('demo-late', $learnerId, DEMO_LECTURE_ID, 'demo-session', 0, 150, $baseTime->modify('-30 seconds')));
    $lateSnapshot = demoSnapshot($connection, $learnerId);
    demoScenario($scenarios, '늦은 체크포인트는 이어보기를 유지하고 최대 위치를 높임', $late['status'] === 200 && (int) ($lateSnapshot['resume_position_seconds'] ?? -1) === 100 && (int) ($lateSnapshot['furthest_position_seconds'] ?? -1) === 150, $late['status'], ['method' => 'POST', 'path' => '/api/v1/learning-events'], $late['json'], ['snapshot' => $lateSnapshot]);

    $rewind = demoLearnerRequest($config, demoPayload('demo-rewind', $learnerId, DEMO_LECTURE_ID, 'demo-session', 2, 50, $baseTime->modify('+20 seconds')));
    $rewindSnapshot = demoSnapshot($connection, $learnerId);
    demoScenario($scenarios, '최신 되감기는 이어보기를 낮추고 최대 위치를 유지함', $rewind['status'] === 200 && (int) ($rewindSnapshot['resume_position_seconds'] ?? -1) === 50 && (int) ($rewindSnapshot['furthest_position_seconds'] ?? -1) === 150, $rewind['status'], ['method' => 'POST', 'path' => '/api/v1/learning-events'], $rewind['json'], ['snapshot' => $rewindSnapshot]);

    $duplicatePayload = demoPayload('demo-duplicate', $learnerId, DEMO_LECTURE_ID, 'demo-session', 3, 60, $baseTime->modify('+30 seconds'));
    demoLearnerRequest($config, $duplicatePayload);
    $duplicateRaw = json_encode([
        'position_seconds' => 60,
        'event_type' => 'CHECKPOINT',
        'sequence_no' => 3,
        'session_id' => 'demo-session',
        'lecture_id' => DEMO_LECTURE_ID,
        'learner_id' => $learnerId,
        'occurred_at' => $duplicatePayload['occurred_at'],
        'event_id' => 'demo-duplicate',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $duplicate = demoLearnerRequest($config, $duplicatePayload, $duplicateRaw);
    demoScenario($scenarios, '의미상 동일한 중복 요청', $duplicate['status'] === 200 && ($duplicate['json']['duplicate'] ?? null) === true, $duplicate['status'], ['method' => 'POST', 'path' => '/api/v1/learning-events', 'body' => '필드 순서 변경, 자격 증명 제외'], $duplicate['json']);

    $conflictPayload = demoPayload('demo-conflict', $learnerId, DEMO_LECTURE_ID, 'demo-session', 4, 70, $baseTime->modify('+40 seconds'));
    demoLearnerRequest($config, $conflictPayload);
    $conflictChanged = $conflictPayload;
    $conflictChanged['position_seconds'] = 71;
    $conflict = demoLearnerRequest($config, $conflictChanged);
    demoScenario($scenarios, '같은 이벤트 ID의 다른 payload', $conflict['status'] === 409, $conflict['status'], ['method' => 'POST', 'path' => '/api/v1/learning-events'], $conflict['json']);

    $crossSource = demoPayload('demo-cross-source', $learnerId, DEMO_LECTURE_ID, 'demo-player', 1, 80, $baseTime->modify('+50 seconds'));
    $crossSourceLearner = demoLearnerRequest($config, $crossSource);
    $crossSourcePlayer = demoPlayerRequest($config, $crossSource);
    $crossSourceCount = (int) demoScalar($connection, 'SELECT COUNT(*) FROM dbo.learning_events WHERE event_id = ?', ['demo-cross-source']);
    demoScenario($scenarios, '서로 다른 source의 같은 이벤트 ID', $crossSourceLearner['status'] === 200 && $crossSourcePlayer['status'] === 200 && $crossSourceCount === 2, $crossSourcePlayer['status'], ['method' => 'POST', 'path' => '/api/v1/player-events', 'authentication' => 'HMAC 헤더 값 제외'], $crossSourcePlayer['json'], ['event_count' => $crossSourceCount]);

    $futureEventId = 'demo-future';
    $future = demoLearnerRequest($config, demoPayload($futureEventId, $learnerId, DEMO_LECTURE_ID, 'demo-future', 1, 1, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+6 minutes')));
    $futureCount = (int) demoScalar($connection, 'SELECT COUNT(*) FROM dbo.learning_events WHERE event_id = ?', [$futureEventId]);
    demoScenario($scenarios, '5분을 초과한 미래 이벤트는 저장하지 않음', $future['status'] === 422 && $futureCount === 0, $future['status'], ['method' => 'POST', 'path' => '/api/v1/learning-events'], $future['json'], ['event_count' => $futureCount]);

    $completed = demoLearnerRequest($config, demoPayload('demo-completed', $learnerId, DEMO_LECTURE_ID, 'demo-complete', 1, 160, $baseTime->modify('+60 seconds'), 'COMPLETED'));
    $completedSnapshot = demoSnapshot($connection, $learnerId);
    $afterCompleted = demoLearnerRequest($config, demoPayload('demo-after-completed', $learnerId, DEMO_LECTURE_ID, 'demo-complete', 2, 70, $baseTime->modify('+70 seconds')));
    $afterCompletedSnapshot = demoSnapshot($connection, $learnerId);
    demoScenario($scenarios, '최초 completed_at 보존', $completed['status'] === 200 && $afterCompleted['status'] === 200 && ($completedSnapshot['completed_at'] ?? null) !== null && ($completedSnapshot['completed_at'] ?? null) === ($afterCompletedSnapshot['completed_at'] ?? null), $afterCompleted['status'], ['method' => 'POST', 'path' => '/api/v1/learning-events'], $afterCompleted['json'], ['before' => $completedSnapshot, 'after' => $afterCompletedSnapshot]);

    $guardianMismatch = demoRequest('GET', '/api/v1/guardians/' . ($guardianId + 1) . '/learners/' . $learnerId . '/progress', ['Authorization' => 'Bearer ' . $config->guardianBearerToken()]);
    demoScenario($scenarios, '보호자 경로 주체 불일치', $guardianMismatch['status'] === 403, $guardianMismatch['status'], ['method' => 'GET', 'path' => '/api/v1/guardians/{other}/learners/{seed}/progress', 'authentication' => '보호자 Bearer 값 제외'], $guardianMismatch['json']);
    $guardianUnlinked = demoRequest('GET', '/api/v1/guardians/' . $guardianId . '/learners/' . DEMO_UNLINKED_LEARNER_ID . '/progress', ['Authorization' => 'Bearer ' . $config->guardianBearerToken()]);
    demoScenario($scenarios, '보호자 연결 필수', $guardianUnlinked['status'] === 403, $guardianUnlinked['status'], ['method' => 'GET', 'path' => '/api/v1/guardians/{seed}/learners/{unlinked}/progress', 'authentication' => '보호자 Bearer 값 제외'], $guardianUnlinked['json']);
    $guardian = demoRequest('GET', '/api/v1/guardians/' . $guardianId . '/learners/' . $learnerId . '/progress', ['Authorization' => 'Bearer ' . $config->guardianBearerToken()]);
    demoScenario($scenarios, '연결된 보호자의 진행 상태 조회', $guardian['status'] === 200, $guardian['status'], ['method' => 'GET', 'path' => '/api/v1/guardians/{seed}/learners/{seed}/progress', 'authentication' => '보호자 Bearer 값 제외'], $guardian['json']);

    $classicAsp = demoProcess([PHP_BINARY, __DIR__ . '/contract.php']);
    demoScenario($scenarios, 'Classic ASP 계약', $classicAsp['exit_code'] === 0, $classicAsp['exit_code'] === 0 ? 200 : 500, ['command' => 'php tests/contract.php', 'target' => 'legacy/progress.asp'], trim($classicAsp['stdout']));

    $concurrency = demoProcess([PHP_BINARY, __DIR__ . '/concurrency.php', '--json']);
    $concurrencyResult = json_decode(trim($concurrency['stdout']), true);
    $concurrencyPassed = $concurrency['exit_code'] === 0 && is_array($concurrencyResult) && ($concurrencyResult['passed'] ?? false) === true && in_array('T01', $concurrencyResult['scenarios'] ?? [], true);
    demoScenario($scenarios, '동시 최초 INSERT와 MSSQL 원자성', $concurrencyPassed, $concurrencyPassed ? 200 : 500, ['method' => '병렬 HTTP 테스트', 'path' => 'tests/concurrency.php'], is_array($concurrencyResult) ? $concurrencyResult : '동시성 보고서 없음');

    $finalSnapshot = demoSnapshot($connection, $learnerId);
    $finalEventCount = (int) demoScalar($connection, 'SELECT COUNT(*) FROM dbo.learning_events WHERE learner_id = ? AND lecture_id = ?', [$learnerId, DEMO_LECTURE_ID]);
    $report['final_state'] = ['event_count' => $finalEventCount, 'snapshot' => $finalSnapshot, 'fixture_cleaned' => false];
} catch (Throwable) {
    $exitCode = 1;
} finally {
    if (isset($connection, $learnerId)) {
        try {
            cleanupDemoFixture($connection, $learnerId);
            $remaining = (int) demoScalar($connection, 'SELECT COUNT(*) FROM dbo.learning_events WHERE learner_id = ? AND lecture_id IN (?, ?)', [$learnerId, DEMO_LECTURE_ID, DEMO_UNENROLLED_LECTURE_ID]);
            $report['final_state']['fixture_cleaned'] = $remaining === 0;
            if ($remaining !== 0) {
                $exitCode = 1;
            }
        } catch (Throwable) {
            $exitCode = 1;
        }
    }
}

$report['scenarios'] = $scenarios;
$report['summary'] = [
    'passed' => $exitCode === 0 && $scenarios !== [] && !in_array(false, array_column($scenarios, 'passed'), true),
    'count' => count($scenarios),
];
if (!$report['summary']['passed']) {
    $exitCode = 1;
}

echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($exitCode);
