<?php
/**
 * SQLite Database Connection
 */

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dbPath = DB_PATH;
            $dbDir = dirname($dbPath);

            // Create data directory if it doesn't exist
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }

            $isNewDb = !file_exists($dbPath);

            self::$instance = new PDO(
                "sqlite:$dbPath",
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            // Enable foreign keys
            self::$instance->exec('PRAGMA foreign_keys = ON');

            // Enable WAL mode for better concurrency (reduces "database is locked" errors)
            self::$instance->exec('PRAGMA journal_mode = WAL');

            // Set busy timeout to 5 seconds (wait instead of failing immediately)
            self::$instance->exec('PRAGMA busy_timeout = 5000');

            // Run migrations if new database
            if ($isNewDb) {
                self::runMigrations();
            }
        }

        return self::$instance;
    }

    public static function runMigrations(): void
    {
        $db = self::getInstance();
        $migrationsPath = __DIR__ . '/../migrations/';

        // Get all migration files
        $files = glob($migrationsPath . '*.sql');
        sort($files);

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            $db->exec($sql);
        }
    }
}

// Helper function for easy access
function db(): PDO
{
    return Database::getInstance();
}
