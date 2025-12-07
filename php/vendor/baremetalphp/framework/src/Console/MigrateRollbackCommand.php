<?php

declare(strict_types=1);

namespace BareMetalPHP\Console;

use BareMetalPHP\Database\Connection;
use BareMetalPHP\Database\ConnectionManager;
use BareMetalPHP\Database\Migration;
use PDO;

/**
 * Console command responsible for rolling back the most recent batch of migrations.
 *
 * This command uses the ConnectionManager when available and falls back
 * to a local SQLite database when no configured connection exists.
 */
class MigrateRollbackCommand
{
    /**
     * Create a new rollback command instance.
     *
     * @param ConnectionManager $manager
     */
    public function __construct(
        private ConnectionManager $manager
    ) {
    }

    /**
     * Execute the rollback command.
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

        // Determine the most recent batch
        $stmt     = $pdo->query("SELECT MAX(batch) AS max_batch FROM {$table}");
        $result   = $stmt->fetch(PDO::FETCH_ASSOC);
        $maxBatch = (int) ($result['max_batch'] ?? 0);

        if ($maxBatch === 0) {
            echo "Nothing to rollback.\n";
            return;
        }

        // Retrieve all migrations belonging to that batch, in reverse order
        $select = $pdo->prepare("
            SELECT migration
            FROM {$table}
            WHERE batch = :batch
            ORDER BY id DESC
        ");
        $select->execute([':batch' => $maxBatch]);

        $migrations    = $select->fetchAll(PDO::FETCH_ASSOC);
        $migrationsDir = getcwd() . '/database/migrations';

        foreach ($migrations as $row) {
            $name = $row['migration'];
            $file = "{$migrationsDir}/{$name}.php";

            if (!file_exists($file)) {
                echo "Skipping missing migration: {$file}\n";
                continue;
            }

            /** @var Migration $migration */
            $migration = require $file;

            echo "Rolling back: {$name}...\n";
            $migration->down($connection);

            $delete = $pdo->prepare("DELETE FROM {$table} WHERE migration = :migration");
            $delete->execute([':migration' => $name]);
        }

        echo "Rollback of batch {$maxBatch} completed.\n";
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
