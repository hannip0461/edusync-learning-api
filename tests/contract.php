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

    // The loopback boundary must live in the deployed source, not only in the IIS
    // installer. Presence is not enough: it has to gate the request before any
    // credential is used or any row is read, otherwise the check is decorative.
    assertSameValue(
        true,
        str_contains($aspSource, 'Request.ServerVariables("REMOTE_ADDR")'),
        'Classic ASP source must read the peer address from REMOTE_ADDR',
    );
    assertSameValue(
        true,
        str_contains($aspSource, 'Response.Status = "403 Forbidden"'),
        'Classic ASP source must refuse non-loopback clients itself',
    );
    assertSameValue(
        false,
        (bool) preg_match('/ServerVariables\("HTTP_(?:X_FORWARDED_FOR|CLIENT_IP)"\)/i', $aspSource),
        'Classic ASP loopback check must not trust a client-settable forwarding header',
    );
    $remoteAddressAt = strpos($aspSource, 'REMOTE_ADDR');
    $connectionOpenAt = strpos($aspSource, 'connection.Open Application(');
    $queryStringAt = strpos($aspSource, 'Request.QueryString(');
    assertSameValue(
        true,
        $remoteAddressAt !== false && $connectionOpenAt !== false && $remoteAddressAt < $connectionOpenAt,
        'Loopback check must run before the database connection is opened',
    );
    assertSameValue(
        true,
        $queryStringAt !== false && $remoteAddressAt < $queryStringAt,
        'Loopback check must run before any query string input is read',
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

    $baseEnvironment = [
        'DB_HOST' => 'db',
        'DB_PORT' => '1433',
        'DB_DATABASE' => 'edusync',
        'DB_USERNAME' => 'sa',
        'DB_PASSWORD' => 'test-password',
        'APP_BEARER_TOKEN' => 'local-app-token-change-me',
        'APP_BEARER_LEARNER_ID' => '2001',
        'APP_EVENT_SOURCE' => 'local-app',
        'GUARDIAN_BEARER_TOKEN' => 'local-guardian-token-change-me',
        'GUARDIAN_BEARER_ID' => '1001',
        'PLAYER_HMAC_SECRET' => 'local-player-hmac-secret-change-me',
        'PLAYER_EVENT_SOURCE' => 'local-player',
        'HMAC_TOLERANCE_SECONDS' => '300',
    ];

    $config = Config::fromEnvironment($baseEnvironment + [
        'DB_ENCRYPT' => 'true',
        'DB_TRUST_SERVER_CERTIFICATE' => 'true',
    ]);
    assertSameValue(
        'sqlsrv:Server=db,1433;Database=edusync;Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=5',
        $config->dsn(),
        'PDO_SQLSRV configuration changed',
    );

    // An unset DB_TRUST_SERVER_CERTIFICATE must keep certificate validation on.
    // Trusting any presented certificate has to be an explicit local opt-in.
    assertSameValue(
        'sqlsrv:Server=db,1433;Database=edusync;Encrypt=yes;TrustServerCertificate=no;LoginTimeout=5',
        Config::fromEnvironment($baseEnvironment)->dsn(),
        'Default configuration must encrypt and validate the server certificate',
    );
    assertSameValue(
        'sqlsrv:Server=db,1433;Database=edusync;Encrypt=yes;TrustServerCertificate=no;LoginTimeout=5',
        Config::fromEnvironment($baseEnvironment + ['DB_TRUST_SERVER_CERTIFICATE' => 'false'])->dsn(),
        'Explicit DB_TRUST_SERVER_CERTIFICATE=false must validate the server certificate',
    );

    fwrite(STDOUT, "PASS Classic ASP contract and PDO_SQLSRV configuration\n");
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("FAIL %s\n", $exception->getMessage()));
    exit(1);
}
