<?php

declare(strict_types=1);

use EduSync\Config;
use EduSync\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

const CONCURRENCY_COURSE_ID = 930000040;
const CONCURRENCY_LECTURE_T01 = 930000041;
const CONCURRENCY_LECTURE_T02 = 930000042;
const CONCURRENCY_LECTURE_T03 = 930000043;
const CONCURRENCY_LECTURE_T04 = 930000044;
const CONCURRENCY_LECTURE_T08 = 930000045;
const CONCURRENCY_LECTURE_T12 = 930000046;

/** @param mixed $actual */
function assertConcurrencySame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s. Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
    }
}

function assertConcurrencyTrue(bool $actual, string $message): void
{
    assertConcurrencySame(true, $actual, $message);
}

/** @param list<mixed> $parameters */
function executeConcurrency(PDO $connection, string $sql, array $parameters = []): void
{
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
}

function execConcurrency(PDO $connection, string $sql): void
{
    if ($connection->exec($sql) !== false) {
        return;
    }

    $errorInfo = $connection->errorInfo();
    $exception = new PDOException((string) ($errorInfo[2] ?? 'SQL Server execution failed.'));
    $exception->errorInfo = $errorInfo;

    throw $exception;
}

/** @param list<mixed> $parameters */
function scalarConcurrency(PDO $connection, string $sql, array $parameters = []): mixed
{
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchColumn();
}

/** @return array<string, mixed> */
function rowConcurrency(PDO $connection, string $sql, array $parameters = []): array
{
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    $row = $statement->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Expected concurrency database row was not found.');
    }

    return $row;
}

/** @return array<string, mixed> */
function concurrencyPayload(string $eventId, int $learnerId, int $lectureId, string $sessionId, int $sequenceNo, int $positionSeconds, DateTimeImmutable $occurredAt, string $eventType = 'CHECKPOINT'): array
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

/** @param list<array{worker_id:string,mode:string,payload:array<string,mixed>}> $workers @return list<array{process:resource,pipes:array<int,resource>}> */
function startConcurrentWorkers(PDO $connection, string $runScope, array $workers): array
{
    executeConcurrency(
        $connection,
        'INSERT INTO dbo.edusync_test_barrier_control (run_scope, expected_workers, released) VALUES (?, ?, 0)',
        [$runScope, count($workers)],
    );

    $children = [];
    foreach ($workers as $worker) {
        $payload = base64_encode(json_encode($worker['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/concurrency_worker.php', $runScope, $worker['worker_id'], $worker['mode'], $payload],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start concurrent HTTP worker.');
        }
        $children[] = ['process' => $process, 'pipes' => $pipes];
    }

    $deadline = microtime(true) + 15;
    while ((int) scalarConcurrency($connection, 'SELECT COUNT(*) FROM dbo.edusync_test_barrier_participants WHERE run_scope = ?', [$runScope]) < count($workers)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrent HTTP workers did not reach the barrier.');
        }

        usleep(10000);
    }

    executeConcurrency($connection, 'UPDATE dbo.edusync_test_barrier_control SET released = 1 WHERE run_scope = ?', [$runScope]);

    return $children;
}

/** @param list<array{process:resource,pipes:array<int,resource>}> $children @return list<array{status:int,json:array<string,mixed>|null,event_count:int}> */
function finishConcurrentWorkers(array $children): array
{
    $results = [];
    foreach ($children as $child) {
        $stdout = stream_get_contents($child['pipes'][1]);
        $stderr = stream_get_contents($child['pipes'][2]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        $exitCode = proc_close($child['process']);
        if ($exitCode !== 0) {
            throw new RuntimeException('Concurrent HTTP worker failed: ' . trim($stderr));
        }
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !isset($result['status'])) {
            throw new RuntimeException('Concurrent HTTP worker returned an invalid result.');
        }
        $results[] = [
            'status' => (int) $result['status'],
            'json' => is_array($result['json'] ?? null) ? $result['json'] : null,
            'event_count' => (int) ($result['event_count'] ?? -1),
        ];
    }

    return $results;
}

/** @param list<array{worker_id:string,mode:string,payload:array<string,mixed>}> $workers @return list<array{status:int,json:array<string,mixed>|null,event_count:int}> */
function concurrentRequests(PDO $connection, string $runScope, array $workers): array
{
    return finishConcurrentWorkers(startConcurrentWorkers($connection, $runScope, $workers));
}

function cleanupConcurrencyObjects(PDO $connection, int $learnerId): void
{
    executeConcurrency($connection, 'DROP TRIGGER IF EXISTS dbo.tr_edusync_test_snapshot_failure');
    executeConcurrency($connection, 'DROP TRIGGER IF EXISTS dbo.tr_edusync_test_deadlock');
    executeConcurrency($connection, 'DROP TABLE IF EXISTS dbo.edusync_test_barrier_participants');
    executeConcurrency($connection, 'DROP TABLE IF EXISTS dbo.edusync_test_barrier_control');
    executeConcurrency($connection, 'DROP TABLE IF EXISTS dbo.edusync_test_deadlock_left');
    executeConcurrency($connection, 'DROP TABLE IF EXISTS dbo.edusync_test_deadlock_right');
    executeConcurrency(
        $connection,
        'DELETE FROM dbo.lecture_progress WHERE learner_id = ? AND lecture_id IN (?, ?, ?, ?, ?, ?)',
        [$learnerId, CONCURRENCY_LECTURE_T01, CONCURRENCY_LECTURE_T02, CONCURRENCY_LECTURE_T03, CONCURRENCY_LECTURE_T04, CONCURRENCY_LECTURE_T08, CONCURRENCY_LECTURE_T12],
    );
    executeConcurrency(
        $connection,
        'DELETE FROM dbo.learning_events WHERE learner_id = ? AND lecture_id IN (?, ?, ?, ?, ?, ?)',
        [$learnerId, CONCURRENCY_LECTURE_T01, CONCURRENCY_LECTURE_T02, CONCURRENCY_LECTURE_T03, CONCURRENCY_LECTURE_T04, CONCURRENCY_LECTURE_T08, CONCURRENCY_LECTURE_T12],
    );
    executeConcurrency($connection, 'DELETE FROM dbo.enrollments WHERE learner_id = ? AND course_id = ?', [$learnerId, CONCURRENCY_COURSE_ID]);
    executeConcurrency($connection, 'DELETE FROM dbo.lectures WHERE lecture_id IN (?, ?, ?, ?, ?, ?)', [CONCURRENCY_LECTURE_T01, CONCURRENCY_LECTURE_T02, CONCURRENCY_LECTURE_T03, CONCURRENCY_LECTURE_T04, CONCURRENCY_LECTURE_T08, CONCURRENCY_LECTURE_T12]);
    executeConcurrency($connection, 'DELETE FROM dbo.courses WHERE course_id = ?', [CONCURRENCY_COURSE_ID]);
}

function createConcurrencyFixture(PDO $connection, int $learnerId): void
{
    executeConcurrency($connection, 'INSERT INTO dbo.courses (course_id, title, is_active) VALUES (?, N\'Concurrency Test Fixture\', 1)', [CONCURRENCY_COURSE_ID]);
    executeConcurrency(
        $connection,
        'INSERT INTO dbo.lectures (lecture_id, course_id, title, lecture_order, duration_seconds, is_active) VALUES (?, ?, N\'T01\', 1, 600, 1), (?, ?, N\'T02\', 2, 600, 1), (?, ?, N\'T03\', 3, 600, 1), (?, ?, N\'T04\', 4, 600, 1), (?, ?, N\'T08\', 5, 600, 1), (?, ?, N\'T12\', 6, 600, 1)',
        [CONCURRENCY_LECTURE_T01, CONCURRENCY_COURSE_ID, CONCURRENCY_LECTURE_T02, CONCURRENCY_COURSE_ID, CONCURRENCY_LECTURE_T03, CONCURRENCY_COURSE_ID, CONCURRENCY_LECTURE_T04, CONCURRENCY_COURSE_ID, CONCURRENCY_LECTURE_T08, CONCURRENCY_COURSE_ID, CONCURRENCY_LECTURE_T12, CONCURRENCY_COURSE_ID],
    );
    executeConcurrency(
        $connection,
        'INSERT INTO dbo.enrollments (learner_id, course_id, enrollment_status, starts_at, ends_at) VALUES (?, ?, N\'ACTIVE\', DATEADD(day, -1, SYSUTCDATETIME()), DATEADD(day, 1, SYSUTCDATETIME()))',
        [$learnerId, CONCURRENCY_COURSE_ID],
    );
    executeConcurrency($connection, 'CREATE TABLE dbo.edusync_test_barrier_control (run_scope varchar(64) NOT NULL PRIMARY KEY, expected_workers int NOT NULL, released bit NOT NULL)');
    executeConcurrency($connection, 'CREATE TABLE dbo.edusync_test_barrier_participants (run_scope varchar(64) NOT NULL, worker_id varchar(64) NOT NULL, CONSTRAINT PK_edusync_test_barrier_participants PRIMARY KEY (run_scope, worker_id))');
}

function waitForDeadlockFirstLock(Database $database): void
{
    $deadline = microtime(true) + 10;
    $lastLockError = null;
    while (microtime(true) < $deadline) {
        $probe = $database->connect();
        try {
            $probe->exec('SET LOCK_TIMEOUT 0');
            $probe->beginTransaction();
            $probe->query('SELECT lock_id FROM dbo.edusync_test_deadlock_left WITH (XLOCK, ROWLOCK, NOWAIT) WHERE lock_id = 1')->fetchColumn();
            $probe->rollBack();
        } catch (PDOException $exception) {
            if ($probe->inTransaction()) {
                $probe->rollBack();
            }
            $sqlCode = $exception->errorInfo[1] ?? $exception->getCode();
            if ((int) $sqlCode === 1222) {
                return;
            }
            $lastLockError = $exception->getMessage();
        }

        usleep(10000);
    }

    throw new RuntimeException('Deadlock fixture did not observe the application first-row lock' . ($lastLockError === null ? '.' : ': ' . $lastLockError));
}

$json = in_array('--json', $argv, true);
$results = [];
$exitCode = 0;
$children = [];
$external = null;

try {
    $config = Config::fromEnvironment($_ENV + $_SERVER);
    $database = new Database($config);
    $connection = $database->connect();
    $learnerId = $config->appBearerLearnerId();
    $runPrefix = 'concurrency-' . bin2hex(random_bytes(6));

    cleanupConcurrencyObjects($connection, $learnerId);
    createConcurrencyFixture($connection, $learnerId);
    $baseTime = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-2 minutes');

    $t01 = concurrentRequests($connection, $runPrefix . '-t01', [
        ['worker_id' => 'one', 'mode' => 'learner', 'payload' => concurrencyPayload($runPrefix . '-t01-a', $learnerId, CONCURRENCY_LECTURE_T01, 't01', 1, 15, $baseTime)],
        ['worker_id' => 'two', 'mode' => 'learner', 'payload' => concurrencyPayload($runPrefix . '-t01-b', $learnerId, CONCURRENCY_LECTURE_T01, 't01', 2, 20, $baseTime->modify('+1 second'))],
    ]);
    assertConcurrencySame([200, 200], array_column($t01, 'status'), 'T01 concurrent first inserts must both succeed');
    assertConcurrencySame(2, (int) scalarConcurrency($connection, 'SELECT COUNT(*) FROM dbo.learning_events WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T01]), 'T01 must retain two events');
    $t01Snapshot = rowConcurrency($connection, 'SELECT resume_position_seconds, furthest_position_seconds, last_sequence_no FROM dbo.lecture_progress WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T01]);
    assertConcurrencySame(20, (int) $t01Snapshot['resume_position_seconds'], 'T01 latest same-session sequence must determine resume');
    assertConcurrencySame(20, (int) $t01Snapshot['furthest_position_seconds'], 'T01 furthest position must be retained');
    assertConcurrencySame(2, (int) $t01Snapshot['last_sequence_no'], 'T01 last sequence must be deterministic');
    $results[] = 'T01';

    $t02Workers = [];
    for ($sequence = 1; $sequence <= 12; $sequence++) {
        $t02Workers[] = [
            'worker_id' => 'checkpoint-' . $sequence,
            'mode' => 'learner',
            'payload' => concurrencyPayload($runPrefix . '-t02-' . $sequence, $learnerId, CONCURRENCY_LECTURE_T02, 't02', $sequence, $sequence * 10, $baseTime->modify('+' . $sequence . ' seconds')),
        ];
    }
    $t02 = concurrentRequests($connection, $runPrefix . '-t02', $t02Workers);
    assertConcurrencySame(12, count(array_filter($t02, static fn (array $result): bool => $result['status'] === 200)), 'T02 every concurrent checkpoint must succeed');
    assertConcurrencySame(12, (int) scalarConcurrency($connection, 'SELECT COUNT(*) FROM dbo.learning_events WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T02]), 'T02 must not lose events');
    $t02Snapshot = rowConcurrency($connection, 'SELECT resume_position_seconds, furthest_position_seconds, last_sequence_no FROM dbo.lecture_progress WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T02]);
    assertConcurrencySame(120, (int) $t02Snapshot['resume_position_seconds'], 'T02 latest sequence must determine resume');
    assertConcurrencySame(120, (int) $t02Snapshot['furthest_position_seconds'], 'T02 furthest position must be maximum');
    assertConcurrencySame(12, (int) $t02Snapshot['last_sequence_no'], 'T02 last sequence must be deterministic');
    $results[] = 'T02';

    $duplicatePayload = concurrencyPayload($runPrefix . '-t03', $learnerId, CONCURRENCY_LECTURE_T03, 't03', 1, 30, $baseTime);
    $t03Workers = [];
    for ($worker = 1; $worker <= 6; $worker++) {
        $t03Workers[] = ['worker_id' => 'duplicate-' . $worker, 'mode' => 'learner', 'payload' => $duplicatePayload];
    }
    $t03 = concurrentRequests($connection, $runPrefix . '-t03', $t03Workers);
    assertConcurrencySame(1, count(array_filter($t03, static fn (array $result): bool => ($result['json']['applied'] ?? null) === true)), 'T03 must apply exactly one request');
    assertConcurrencySame(5, count(array_filter($t03, static fn (array $result): bool => ($result['json']['duplicate'] ?? null) === true)), 'T03 must classify remaining requests as duplicates');
    assertConcurrencySame(1, (int) scalarConcurrency($connection, 'SELECT COUNT(*) FROM dbo.learning_events WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T03]), 'T03 must retain one event');
    assertConcurrencySame(1, (int) scalarConcurrency($connection, 'SELECT COUNT(*) FROM dbo.lecture_progress WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T03]), 'T03 snapshot must remain singular');
    $results[] = 'T03';

    $conflictId = $runPrefix . '-t04';
    $t04 = concurrentRequests($connection, $runPrefix . '-t04', [
        ['worker_id' => 'left', 'mode' => 'learner', 'payload' => concurrencyPayload($conflictId, $learnerId, CONCURRENCY_LECTURE_T04, 't04', 1, 31, $baseTime)],
        ['worker_id' => 'right', 'mode' => 'learner', 'payload' => concurrencyPayload($conflictId, $learnerId, CONCURRENCY_LECTURE_T04, 't04', 2, 77, $baseTime->modify('+1 second'))],
    ]);
    $t04Statuses = array_column($t04, 'status');
    sort($t04Statuses);
    assertConcurrencySame([200, 409], $t04Statuses, 'T04 must preserve one winner and one conflict');
    assertConcurrencySame(1, (int) scalarConcurrency($connection, 'SELECT COUNT(*) FROM dbo.learning_events WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T04]), 'T04 must retain only the winner event');
    assertConcurrencySame(1, (int) scalarConcurrency($connection, 'SELECT COUNT(*) FROM dbo.lecture_progress WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T04]), 'T04 conflict must not create another snapshot');
    $results[] = 'T04';

    executeConcurrency(
        $connection,
        'CREATE TRIGGER dbo.tr_edusync_test_snapshot_failure ON dbo.lecture_progress AFTER INSERT AS BEGIN IF EXISTS (SELECT 1 FROM inserted WHERE learner_id = ' . $learnerId . ' AND lecture_id = ' . CONCURRENCY_LECTURE_T08 . ') THROW 51000, N\'Concurrency snapshot failure\', 1; END',
    );
    $t08 = concurrentRequests($connection, $runPrefix . '-t08', [
        ['worker_id' => 'failure', 'mode' => 'learner', 'payload' => concurrencyPayload($runPrefix . '-t08', $learnerId, CONCURRENCY_LECTURE_T08, 't08', 1, 40, $baseTime)],
    ]);
    assertConcurrencySame(500, $t08[0]['status'], 'T08 controlled snapshot failure must return 500');
    assertConcurrencySame(0, (int) scalarConcurrency($connection, 'SELECT COUNT(*) FROM dbo.learning_events WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T08]), 'T08 must roll back the event');
    assertConcurrencySame(0, (int) scalarConcurrency($connection, 'SELECT COUNT(*) FROM dbo.lecture_progress WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T08]), 'T08 must roll back the snapshot');
    executeConcurrency($connection, 'DROP TRIGGER dbo.tr_edusync_test_snapshot_failure');
    $results[] = 'T08';

    executeConcurrency($connection, 'CREATE TABLE dbo.edusync_test_deadlock_left (lock_id int NOT NULL PRIMARY KEY, touch_count int NOT NULL CONSTRAINT DF_edusync_test_deadlock_left_touch_count DEFAULT 0)');
    executeConcurrency($connection, 'CREATE TABLE dbo.edusync_test_deadlock_right (lock_id int NOT NULL PRIMARY KEY, touch_count int NOT NULL CONSTRAINT DF_edusync_test_deadlock_right_touch_count DEFAULT 0)');
    executeConcurrency($connection, 'INSERT INTO dbo.edusync_test_deadlock_left (lock_id) VALUES (1)');
    executeConcurrency($connection, 'INSERT INTO dbo.edusync_test_deadlock_right (lock_id) VALUES (1)');
    $deadlockEventId = $runPrefix . '-t12';
    executeConcurrency(
        $connection,
        'CREATE TRIGGER dbo.tr_edusync_test_deadlock ON dbo.lecture_progress AFTER INSERT AS BEGIN IF EXISTS (SELECT 1 FROM inserted WHERE learner_id = ' . $learnerId . ' AND lecture_id = ' . CONCURRENCY_LECTURE_T12 . ') BEGIN UPDATE dbo.edusync_test_deadlock_left SET touch_count = touch_count + 1 WHERE lock_id = 1; UPDATE dbo.edusync_test_deadlock_right SET touch_count = touch_count + 1 WHERE lock_id = 1; END END',
    );
    $beforeIdentity = (int) scalarConcurrency($connection, 'SELECT IDENT_CURRENT(N\'dbo.learning_events\')');
    $external = $database->connect();
    execConcurrency($external, 'SET DEADLOCK_PRIORITY HIGH; SET LOCK_TIMEOUT 10000');
    $external->beginTransaction();
    execConcurrency($external, 'UPDATE dbo.edusync_test_deadlock_right SET touch_count = touch_count + 1 WHERE lock_id = 1');
    $children = startConcurrentWorkers($connection, $runPrefix . '-t12', [
        ['worker_id' => 'deadlock', 'mode' => 'learner', 'payload' => concurrencyPayload($deadlockEventId, $learnerId, CONCURRENCY_LECTURE_T12, 't12', 1, 50, $baseTime)],
    ]);
    try {
        waitForDeadlockFirstLock($database);
    } catch (Throwable $exception) {
        $external->rollBack();
        $external = null;
        $diagnostic = finishConcurrentWorkers($children);
        $children = [];
        throw new RuntimeException($exception->getMessage() . ' Worker status: ' . (string) $diagnostic[0]['status']);
    }
    execConcurrency($external, 'UPDATE dbo.edusync_test_deadlock_left SET touch_count = touch_count + 1 WHERE lock_id = 1');
    $external->rollBack();
    $external = null;
    $t12 = finishConcurrentWorkers($children);
    $children = [];
    assertConcurrencySame(200, $t12[0]['status'], 'T12 deadlock victim must retry and succeed');
    $t12EventCount = (int) scalarConcurrency($connection, 'SELECT COUNT(*) FROM dbo.learning_events WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T12]);
    $t12Events = $connection->prepare('SELECT event_id, learner_id, lecture_id FROM dbo.learning_events WHERE event_id = ?');
    $t12Events->execute([$deadlockEventId]);
    $t12SnapshotCount = (int) scalarConcurrency($connection, 'SELECT COUNT(*) FROM dbo.lecture_progress WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T12]);
    $currentIdentity = (int) scalarConcurrency($connection, 'SELECT IDENT_CURRENT(N\'dbo.learning_events\')');
    assertConcurrencySame(1, $t12EventCount, 'T12 must commit one event after retry. Result: ' . json_encode($t12, JSON_THROW_ON_ERROR) . ' Events: ' . json_encode($t12Events->fetchAll(), JSON_THROW_ON_ERROR) . ' Snapshot: ' . $t12SnapshotCount . ' Identity: ' . $currentIdentity . ' Expected identity: ' . ($beforeIdentity + 2));
    assertConcurrencySame(1, $t12SnapshotCount, 'T12 must commit one snapshot after retry');
    assertConcurrencySame(1, $t12[0]['event_count'], 'T12 worker must observe its committed event');
    assertConcurrencySame(1, (int) scalarConcurrency($connection, 'SELECT touch_count FROM dbo.edusync_test_deadlock_left WHERE lock_id = 1'), 'T12 retry must commit the left lock touch once');
    assertConcurrencySame(1, (int) scalarConcurrency($connection, 'SELECT touch_count FROM dbo.edusync_test_deadlock_right WHERE lock_id = 1'), 'T12 retry must commit the right lock touch once');
    assertConcurrencySame($beforeIdentity + 2, $currentIdentity, 'T12 identity must include one rolled-back insert and one retry insert');
    assertConcurrencySame($beforeIdentity + 2, (int) scalarConcurrency($connection, 'SELECT event_seq FROM dbo.learning_events WHERE learner_id = ? AND lecture_id = ?', [$learnerId, CONCURRENCY_LECTURE_T12]), 'T12 must show one rolled-back insert followed by one retry insert');
    $results[] = 'T12';
} catch (Throwable $exception) {
    $exitCode = 1;
    fwrite(STDERR, 'FAIL ' . $exception->getMessage() . "\n");
} finally {
    if ($external instanceof PDO && $external->inTransaction()) {
        $external->rollBack();
    }
    if ($children !== []) {
        try {
            finishConcurrentWorkers($children);
        } catch (Throwable) {
        }
    }
    if (isset($connection, $learnerId)) {
        try {
            cleanupConcurrencyObjects($connection, $learnerId);
            assertConcurrencySame(0, (int) scalarConcurrency($connection, 'SELECT COUNT(*) FROM sys.objects WHERE name IN (N\'edusync_test_barrier_control\', N\'edusync_test_barrier_participants\', N\'edusync_test_deadlock_left\', N\'edusync_test_deadlock_right\', N\'tr_edusync_test_snapshot_failure\', N\'tr_edusync_test_deadlock\')'), 'Concurrency test objects must be removed');
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, 'Cleanup failed: ' . $cleanupException->getMessage() . "\n");
            $exitCode = 1;
        }
    }
}

if ($json) {
    echo json_encode(['passed' => $exitCode === 0, 'scenarios' => $results], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} elseif ($exitCode === 0) {
    fwrite(STDOUT, 'PASS actual MSSQL concurrency scenarios: ' . implode(', ', $results) . "\n");
}

exit($exitCode);
