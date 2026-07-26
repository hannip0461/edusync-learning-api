<?php

declare(strict_types=1);

use EduSync\Config;
use EduSync\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @return list<string>
 */
function sqlBatches(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException(sprintf('Unable to read SQL file: %s', $path));
    }

    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
    $batches = preg_split('/^\s*GO\s*(?:--.*)?$/mi', $contents) ?: [];

    return array_values(array_filter(array_map('trim', $batches), static fn (string $batch): bool => $batch !== ''));
}

function executeSqlFile(PDO $connection, string $path): void
{
    foreach (sqlBatches($path) as $batch) {
        $connection->exec($batch);
    }
}

function quoteIdentifier(string $identifier): string
{
    return '[' . str_replace(']', ']]', $identifier) . ']';
}

try {
    $config = Config::fromEnvironment($_ENV + $_SERVER);
    $database = new Database($config);
    $master = $database->connect('master');
    $master->exec(sprintf(
        'IF DB_ID(N\'%s\') IS NULL CREATE DATABASE %s;',
        str_replace("'", "''", $config->database()),
        quoteIdentifier($config->database()),
    ));

    $connection = $database->connect();
    $connection->exec(
        'IF OBJECT_ID(N\'dbo.schema_migrations\', N\'U\') IS NULL
         CREATE TABLE dbo.schema_migrations (
             migration_name NVARCHAR(255) NOT NULL CONSTRAINT PK_schema_migrations PRIMARY KEY,
             applied_at DATETIME2(3) NOT NULL CONSTRAINT DF_schema_migrations_applied_at DEFAULT SYSUTCDATETIME()
         );'
    );

    $migrationDirectory = dirname(__DIR__) . '/db/migrations';
    $migrations = glob($migrationDirectory . '/*.sql') ?: [];
    sort($migrations, SORT_STRING);

    foreach ($migrations as $path) {
        $name = basename($path);
        $check = $connection->prepare('SELECT 1 FROM dbo.schema_migrations WHERE migration_name = ?');
        $check->execute([$name]);
        if ($check->fetchColumn() !== false) {
            fwrite(STDOUT, sprintf("Migration already applied: %s\n", $name));
            continue;
        }

        $connection->beginTransaction();
        try {
            executeSqlFile($connection, $path);
            $record = $connection->prepare('INSERT INTO dbo.schema_migrations (migration_name) VALUES (?)');
            $record->execute([$name]);
            $connection->commit();
            fwrite(STDOUT, sprintf("Migration applied: %s\n", $name));
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    $seedDirectory = dirname(__DIR__) . '/db/seeds';
    $seeds = glob($seedDirectory . '/*.sql') ?: [];
    sort($seeds, SORT_STRING);
    foreach ($seeds as $path) {
        executeSqlFile($connection, $path);
        fwrite(STDOUT, sprintf("Seed applied: %s\n", basename($path)));
    }

    fwrite(STDOUT, "Migration and seed completed.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Migration failed: %s\n", $exception->getMessage()));
    exit(1);
}
