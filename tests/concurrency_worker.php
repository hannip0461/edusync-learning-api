<?php

declare(strict_types=1);

use EduSync\Config;
use EduSync\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

if ($argc !== 5) {
    fwrite(STDERR, "Expected run scope, worker id, mode, and payload.\n");
    exit(2);
}

[$runScope, $workerId, $mode, $encodedPayload] = array_slice($argv, 1);

try {
    $payload = json_decode(base64_decode($encodedPayload, true) ?: '', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Worker payload must be an object.');
    }

    $config = Config::fromEnvironment($_ENV + $_SERVER);
    $connection = (new Database($config))->connect();
    $statement = $connection->prepare(
        'INSERT INTO dbo.edusync_test_barrier_participants (run_scope, worker_id) VALUES (?, ?)'
    );
    $statement->execute([$runScope, $workerId]);

    $deadline = microtime(true) + 15;
    while (true) {
        $released = $connection->prepare(
            'SELECT released FROM dbo.edusync_test_barrier_control WHERE run_scope = ?'
        );
        $released->execute([$runScope]);
        if ((int) $released->fetchColumn() === 1) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrent request barrier was not released.');
        }

        usleep(10000);
    }

    $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $headers = ['Content-Type: application/json'];
    if ($mode === 'learner') {
        $headers[] = 'Authorization: Bearer ' . $config->appBearerToken();
        $path = '/api/v1/learning-events';
    } else {
        throw new RuntimeException('Unsupported concurrent worker mode.');
    }

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $raw,
        'ignore_errors' => true,
        'timeout' => 20,
    ]]);
    $body = file_get_contents('http://127.0.0.1' . $path, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s([0-9]{3})\s/', $statusLine, $matches)) {
        throw new RuntimeException('Concurrent HTTP request did not return a status line.');
    }

    $decoded = json_decode($body === false ? '' : $body, true);
    $eventCount = $connection->prepare('SELECT COUNT(*) FROM dbo.learning_events WHERE event_id = ?');
    $eventCount->execute([$payload['event_id']]);
    echo json_encode([
        'status' => (int) $matches[1],
        'json' => is_array($decoded) ? $decoded : null,
        'event_count' => (int) $eventCount->fetchColumn(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
