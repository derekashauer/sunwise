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

            // Bring the schema up to date. Cheap when nothing is pending.
            if ($isNewDb || self::hasPendingMigrations()) {
                self::runMigrations();
            }
        }

        return self::$instance;
    }

    /**
     * Apply any migration files that have not run yet.
     *
     * Every deploy calls this, so it has to be safe on a database that already
     * has some or all of the schema. Applied files are tracked in
     * schema_migrations. Each statement runs on its own, and "duplicate column"
     * or "already exists" errors are treated as already applied. That lets a
     * database created before tracking existed catch up without failing on the
     * first ALTER TABLE it has already seen.
     */
    public static function runMigrations(): void
    {
        $db = self::getInstance();
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            filename TEXT PRIMARY KEY,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        $applied = $db->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $applied = array_flip($applied);

        foreach (self::migrationFiles() as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                continue;
            }

            foreach (self::splitStatements(file_get_contents($file)) as $statement) {
                try {
                    $db->exec($statement);
                } catch (PDOException $e) {
                    $message = $e->getMessage();
                    if (stripos($message, 'duplicate column name') !== false
                        || stripos($message, 'already exists') !== false) {
                        continue;
                    }
                    throw new RuntimeException("Migration {$name} failed: {$message}", 0, $e);
                }
            }

            $stmt = $db->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
            $stmt->execute([$name]);
        }
    }

    /**
     * Whether any migration file has not been applied yet.
     */
    public static function hasPendingMigrations(): bool
    {
        $db = self::getInstance();
        $exists = $db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'")->fetch();
        if (!$exists) {
            return true;
        }
        $count = (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        return $count < count(self::migrationFiles());
    }

    /**
     * Migration files in the order they should run.
     */
    private static function migrationFiles(): array
    {
        $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
        sort($files);
        return $files;
    }

    /**
     * Split a migration file into single statements.
     *
     * Migrations here are plain DDL/DML with no triggers, so splitting on
     * semicolons outside of quotes, after removing line comments, is enough.
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inQuote = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if (!$inQuote && $char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline;
                $current .= "\n";
                continue;
            }

            if ($char === "'") {
                $inQuote = !$inQuote;
            }

            if ($char === ';' && !$inQuote) {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }
}

// Helper function for easy access
function db(): PDO
{
    return Database::getInstance();
}
