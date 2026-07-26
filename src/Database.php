<?php

declare(strict_types=1);

namespace EduSync;

use PDO;

final class Database
{
    public function __construct(private readonly Config $config)
    {
    }

    public function connect(?string $database = null): PDO
    {
        return new PDO(
            $this->config->dsn($database),
            $this->config->username(),
            $this->config->password(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }

    public function probe(): int
    {
        $statement = $this->connect()->query('SELECT 1 AS database_probe');

        return (int) $statement->fetchColumn();
    }
}
