<?php

declare(strict_types=1);

namespace BareMetalPHP\Console;

use BareMetalPHP\Database\Connection;
use BareMetalPHP\Database\ConnectionManager;
use BareMetalPHP\Database\Migration;
use PDO;

/**
 * Console command responsible for applying all outstanding migrations.
 *
 * This command uses the ConnectionManager when available and falls back
 * to a local SQLite database when no configured connection exists.
 */
class MigrateCommand
{
    /**
     * Create a new migrate command instance.
     *
     * @param ConnectionManager $manager
     */
    public function __construct(
        private ConnectionManager $manager
    ) {
    }

    /**
     * Execute the migration command.
     *
     * @param array $args
     * @return void
     */
    public function handle(array $args = []): void
    {
        $connection = $this->resolveConnection();
        $this->ensureMigrationsTable($connection);

        $pdo    = $connection->pdo();
        $driver = $connection->getDriver();
        $table  = $driver->quoteIdentifier('migrations');

        // Determine next batch number
        $stmt      = $pdo->query("SELECT MAX(batch) AS max_batch FROM {$table}");
        $result    = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextBatch = ((int) ($result['max_batch'] ?? 0)) + 1;

        $migrationsDir = getcwd() . '/database/migrations';

        if (!is_dir($migrationsDir)) {
            echo "No migrations directory found at {$migrationsDir}\n";
            return;
        }

        $files = glob($migrationsDir . '/*.php');
        $ran   = false;

        if (!$files) {
            echo "No migration files found.\n";
            return;
        }

        // Run all outstanding migrations
        foreach ($files as $file) {
            $name = basename($file, '.php');

            $check = $pdo->prepare("SELECT COUNT(*) AS count FROM {$table} WHERE migration = :migration");
            $check->execute([':migration' => $name]);
            $exists = (int) $check->fetch(PDO::FETCH_ASSOC)['count'] > 0;

            if ($exists) {
                continue;
            }

            /** @var Migration $migration */
            $migration = require $file;

            echo "Running migration: {$name}...\n";
            $migration->up($connection);

            $insert = $pdo->prepare("
                INSERT INTO {$table} (migration, batch, ran_at)
                VALUES (:migration, :batch, :ran_at)
            ");
            $insert->execute([
                ':migration' => $name,
                ':batch'     => $nextBatch,
                ':ran_at'    => date('c'),
            ]);

            $ran = true;
        }

        echo $ran
            ? "Migrations completed.\n"
            : "Nothing to migrate.\n";
    }

    /**
     * Resolve the database connection to be used for migrations.
     *
     * Attempts to use the ConnectionManager and falls back to a
     * local SQLite database when no configured connection exists.
     *
     * @return Connection
     */
    private function resolveConnection(): Connection
    {
        try {
            $connection = $this->manager->connection();
        } catch (\Throwable) {
            $connection = null;
        }

        if (!$connection) {
            $dsn        = 'sqlite:' . getcwd() . '/database.sqlite';
            $connection = new Connection($dsn);
        }

        return $connection;
    }

    /**
     * Ensure that the migrations table exists on the given connection.
     *
     * The table definition is adapted for the underlying driver.
     *
     * @param Connection $connection
     * @return void
     */
    private function ensureMigrationsTable(Connection $connection): void
    {
        $pdo    = $connection->pdo();
        $driver = $connection->getDriver();

        $quotedTable = $driver->quoteIdentifier('migrations');
        $driverName  = $driver->getName();
        $idType      = $driver->getAutoIncrementType();

        if ($driverName === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$quotedTable} (
                    id {$idType},
                    migration TEXT NOT NULL,
                    batch INTEGER NOT NULL,
                    ran_at TEXT NOT NULL
                )
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$quotedTable} (
                    id {$idType},
                    migration VARCHAR(255) NOT NULL,
                    batch INT NOT NULL,
                    ran_at TIMESTAMP NOT NULL
                )
            ");
        }
    }
}
