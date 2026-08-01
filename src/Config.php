<?php

declare(strict_types=1);

namespace EduSync;

final class Config
{
    /**
     * @param array<string, string|false|null> $environment
     */
    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly bool $encrypt,
        private readonly bool $trustServerCertificate,
        private readonly string $appBearerToken,
        private readonly int $appBearerLearnerId,
        private readonly string $appEventSource,
        private readonly string $guardianBearerToken,
        private readonly int $guardianBearerId,
        private readonly string $playerHmacSecret,
        private readonly string $playerEventSource,
        private readonly int $hmacToleranceSeconds,
    ) {
    }

    /**
     * @param array<string, string|false|null> $environment
     */
    public static function fromEnvironment(array $environment): self
    {
        $required = static function (string $name) use ($environment): string {
            $value = $environment[$name] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new \RuntimeException(sprintf('%s must be configured.', $name));
            }

            return trim($value);
        };

        $port = filter_var($environment['DB_PORT'] ?? '1433', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new \RuntimeException('DB_PORT must be an integer between 1 and 65535.');
        }

        $tolerance = filter_var($environment['HMAC_TOLERANCE_SECONDS'] ?? '300', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 3600],
        ]);
        if ($tolerance === false) {
            throw new \RuntimeException('HMAC_TOLERANCE_SECONDS must be an integer between 1 and 3600.');
        }

        return new self(
            $required('DB_HOST'),
            $port,
            $required('DB_DATABASE'),
            $required('DB_USERNAME'),
            $required('DB_PASSWORD'),
            self::toBoolean($environment['DB_ENCRYPT'] ?? 'true'),
            self::toBoolean($environment['DB_TRUST_SERVER_CERTIFICATE'] ?? 'false'),
            self::token($required('APP_BEARER_TOKEN'), 'APP_BEARER_TOKEN'),
            self::positiveInteger($required('APP_BEARER_LEARNER_ID'), 'APP_BEARER_LEARNER_ID'),
            self::source($required('APP_EVENT_SOURCE'), 'APP_EVENT_SOURCE'),
            self::token($required('GUARDIAN_BEARER_TOKEN'), 'GUARDIAN_BEARER_TOKEN'),
            self::positiveInteger($required('GUARDIAN_BEARER_ID'), 'GUARDIAN_BEARER_ID'),
            self::token($required('PLAYER_HMAC_SECRET'), 'PLAYER_HMAC_SECRET'),
            self::source($required('PLAYER_EVENT_SOURCE'), 'PLAYER_EVENT_SOURCE'),
            $tolerance,
        );
    }

    public function dsn(?string $database = null): string
    {
        $targetDatabase = $database ?? $this->database;

        return sprintf(
            'sqlsrv:Server=%s,%d;Database=%s;Encrypt=%s;TrustServerCertificate=%s;LoginTimeout=5',
            $this->host,
            $this->port,
            $targetDatabase,
            $this->encrypt ? 'yes' : 'no',
            $this->trustServerCertificate ? 'yes' : 'no',
        );
    }

    public function database(): string
    {
        return $this->database;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function appBearerToken(): string
    {
        return $this->appBearerToken;
    }

    public function appBearerLearnerId(): int
    {
        return $this->appBearerLearnerId;
    }

    public function appEventSource(): string
    {
        return $this->appEventSource;
    }

    public function guardianBearerToken(): string
    {
        return $this->guardianBearerToken;
    }

    public function guardianBearerId(): int
    {
        return $this->guardianBearerId;
    }

    public function playerHmacSecret(): string
    {
        return $this->playerHmacSecret;
    }

    public function playerEventSource(): string
    {
        return $this->playerEventSource;
    }

    public function hmacToleranceSeconds(): int
    {
        return $this->hmacToleranceSeconds;
    }

    private static function toBoolean(string|false|null $value): bool
    {
        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new \RuntimeException('Boolean environment values must be true/false, yes/no, on/off, or 1/0.');
    }

    private static function token(string $value, string $name): string
    {
        if (strlen($value) > 512) {
            throw new \RuntimeException(sprintf('%s must be at most 512 bytes.', $name));
        }

        return $value;
    }

    private static function positiveInteger(string $value, string $name): int
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/', $value) || (string) (int) $value !== $value) {
            throw new \RuntimeException(sprintf('%s must be a positive signed BIGINT integer.', $name));
        }

        return (int) $value;
    }

    private static function source(string $value, string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,39}$/', $value)) {
            throw new \RuntimeException(sprintf('%s must be 1-40 ASCII source characters.', $name));
        }

        return $value;
    }
}
