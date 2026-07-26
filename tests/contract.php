<?php

declare(strict_types=1);

use EduSync\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @param mixed $actual */
function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s. Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
    }
}

try {
    $aspPath = dirname(__DIR__) . '/legacy/progress.asp';
    $aspSource = file_get_contents($aspPath);
    if ($aspSource === false) {
        throw new RuntimeException('Unable to read legacy/progress.asp.');
    }

    assertSameValue(false, str_contains($aspSource, '\\"'), 'Classic ASP must not use PHP-style backslash quote escaping');
    assertSameValue(true, str_contains($aspSource, 'WHERE learner_id = ? AND lecture_id = ?'), 'Classic ASP query must use two positional placeholders');
    assertSameValue(
        2,
        substr_count($aspSource, 'command.Parameters.Append command.CreateParameter('),
        'Classic ASP query must bind exactly two ADO parameters',
    );
    assertSameValue(true, str_contains($aspSource, 'connection.Open Application("EduSyncConnectionString")'), 'Classic ASP connection string must come from IIS Application settings');
    assertSameValue(false, (bool) preg_match('/(?:MSSQL_SA_PASSWORD|DB_PASSWORD|Password\\s*=)/i', $aspSource), 'Classic ASP source must not contain a hard-coded password');
    assertSameValue(false, str_contains($aspSource, 'IsNumeric('), 'Classic ASP int64 validation must not use permissive IsNumeric');
    assertSameValue(true, str_contains($aspSource, 'Function IsInt64Text(value)'), 'Classic ASP source must define strict int64 text validation');
    assertSameValue(
        true,
        str_contains($aspSource, 'limit = "9223372036854775807"')
            && str_contains($aspSource, 'limit = "9223372036854775808"'),
        'Classic ASP int64 validation must enforce both signed boundaries',
    );
    assertSameValue(
        true,
        str_contains($aspSource, 'learner_id and lecture_id must be int64 decimal integers'),
        'Invalid Classic ASP int64 input must have a stable 400 error contract',
    );
    assertSameValue(
        true,
        str_contains($aspSource, 'adParamInput, , learnerId)')
            && str_contains($aspSource, 'adParamInput, , lectureId)'),
        'BIGINT parameters must preserve the validated numeric text',
    );
    assertSameValue(
        false,
        (bool) preg_match('/C(?:Dec|Dbl|Lng)\\((?:learnerId|lectureId)\\)/', $aspSource),
        'Classic ASP BIGINT parameters must not use unsupported or lossy VBScript conversions',
    );

    $fixturePath = __DIR__ . '/fixtures/classic-asp-progress.json';
    $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
    $response = $fixture['response'] ?? null;
    if (!is_array($response)) {
        throw new RuntimeException('Classic ASP progress fixture must contain a response object.');
    }

    $expectedKeys = [
        'completed_at',
        'furthest_position_seconds',
        'last_studied_at',
        'learner_id',
        'lecture_id',
        'resume_position_seconds',
    ];
    $actualKeys = array_keys($response);
    sort($actualKeys, SORT_STRING);
    assertSameValue($expectedKeys, $actualKeys, 'Classic ASP progress response field set changed');
    assertSameValue(true, is_int($response['learner_id']), 'learner_id must be an integer');
    assertSameValue(true, is_int($response['lecture_id']), 'lecture_id must be an integer');
    assertSameValue(true, is_int($response['resume_position_seconds']), 'resume_position_seconds must be an integer');
    assertSameValue(true, is_int($response['furthest_position_seconds']), 'furthest_position_seconds must be an integer');
    assertSameValue(true, is_string($response['last_studied_at']), 'last_studied_at must be an ISO-8601 string');
    assertSameValue(true, $response['completed_at'] === null || is_string($response['completed_at']), 'completed_at must be null or an ISO-8601 string');

    $config = Config::fromEnvironment([
        'DB_HOST' => 'db',
        'DB_PORT' => '1433',
        'DB_DATABASE' => 'edusync',
        'DB_USERNAME' => 'sa',
        'DB_PASSWORD' => 'test-password',
        'DB_ENCRYPT' => 'true',
        'DB_TRUST_SERVER_CERTIFICATE' => 'true',
        'APP_BEARER_TOKEN' => 'local-app-token-change-me',
        'APP_BEARER_LEARNER_ID' => '2001',
        'APP_EVENT_SOURCE' => 'local-app',
        'GUARDIAN_BEARER_TOKEN' => 'local-guardian-token-change-me',
        'GUARDIAN_BEARER_ID' => '1001',
        'PLAYER_HMAC_SECRET' => 'local-player-hmac-secret-change-me',
        'PLAYER_EVENT_SOURCE' => 'local-player',
        'HMAC_TOLERANCE_SECONDS' => '300',
    ]);
    assertSameValue(
        'sqlsrv:Server=db,1433;Database=edusync;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=5',
        $config->dsn(),
        'PDO_SQLSRV configuration changed',
    );

    fwrite(STDOUT, "PASS Classic ASP contract and PDO_SQLSRV configuration\n");
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("FAIL %s\n", $exception->getMessage()));
    exit(1);
}
