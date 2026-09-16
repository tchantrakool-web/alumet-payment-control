<?php
/**
 * Connects to whichever database DB_DRIVER (.env) selects, applies the
 * matching baseline schema (database/schema.<driver>.sql) the first time it
 * finds an empty database, then runs any migrations that haven't been
 * recorded yet (database/migrations/<driver>/NNN_name.sql). getDB()'s
 * signature and behavior are unchanged for every existing caller.
 *
 * This app never issues CREATE DATABASE / CREATE USER for MySQL — the
 * database and its user must already exist. Applying DDL (CREATE TABLE, ...)
 * inside that already-existing, already-empty database is not the same
 * thing and is exactly what this file does.
 */

function dbDriver(): string {
    return env('DB_DRIVER', 'sqlite');
}

/**
 * Marks a value as a raw SQL expression (e.g. CURRENT_TIMESTAMP) rather than
 * a bound parameter — upsert() and any other helper that builds VALUES(...)
 * lists splices $sql directly into the statement instead of binding it as a
 * string. Never wrap anything containing untrusted input in this — $sql is
 * emitted verbatim.
 */
final class SqlExpression {
    public function __construct(public readonly string $sql) {}
}

function sqlNow(): SqlExpression {
    return new SqlExpression('CURRENT_TIMESTAMP');
}

/** Portable SQL expression for whole calendar days between two trusted SQL expressions. */
function sqlDaysBetween(string $later, string $earlier): string {
    return dbDriver() === 'mysql'
        ? "DATEDIFF({$later}, {$earlier})"
        : "CAST(julianday({$later}) - julianday({$earlier}) AS INTEGER)";
}

/** Portable SQL expression for fractional hours between two trusted SQL expressions. */
function sqlHoursBetween(string $later, string $earlier): string {
    return dbDriver() === 'mysql'
        ? "(TIMESTAMPDIFF(SECOND, {$earlier}, {$later}) / 3600.0)"
        : "((julianday({$later}) - julianday({$earlier})) * 24.0)";
}

/**
 * Insert a row, or update it in place if a row already exists with the same
 * value(s) in the unique/primary-key column(s) named by $uniqueBy — one
 * statement, one round trip, no SELECT-then-branch race window, and the
 * exact same call on both SQLite (ON CONFLICT) and MySQL (ON DUPLICATE KEY
 * UPDATE), picked automatically via dbDriver(). $uniqueBy must name column(s)
 * already covered by a UNIQUE or PRIMARY KEY constraint on $table — that
 * constraint is what either dialect's upsert relies on to detect a conflict.
 * Every column in $data other than $uniqueBy and $insertOnly is refreshed on
 * conflict too; $insertOnly columns (e.g. created_by) are written on first
 * insert only and left untouched on an update. Pass sqlNow() instead of a
 * PHP value for a column that should be set to the current time.
 */
function upsert(PDO $db, string $table, array $data, array $uniqueBy, array $insertOnly = []): void {
    $columns = array_keys($data);
    $bindings = [];
    $valueSql = [];
    foreach ($data as $value) {
        if ($value instanceof SqlExpression) {
            $valueSql[] = $value->sql;
        } else {
            $valueSql[] = '?';
            $bindings[] = $value;
        }
    }
    $columnList = implode(', ', $columns);
    $valuesList = implode(', ', $valueSql);
    $updateColumns = array_values(array_diff($columns, $uniqueBy, $insertOnly));

    if (dbDriver() === 'mysql') {
        $setClause = implode(', ', array_map(static fn($c) => "{$c} = VALUES({$c})", $updateColumns));
        $sql = "INSERT INTO {$table} ({$columnList}) VALUES ({$valuesList}) ON DUPLICATE KEY UPDATE {$setClause}";
    } else {
        $conflictColumns = implode(', ', $uniqueBy);
        $setClause = implode(', ', array_map(static fn($c) => "{$c} = excluded.{$c}", $updateColumns));
        $sql = "INSERT INTO {$table} ({$columnList}) VALUES ({$valuesList}) ON CONFLICT({$conflictColumns}) DO UPDATE SET {$setClause}";
    }

    $db->prepare($sql)->execute($bindings);
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $driver = env('DB_DRIVER', 'sqlite');
    $pdo = match ($driver) {
        'sqlite' => connectSqlite(),
        'mysql' => connectMysql(),
        default => throw new RuntimeException("Unsupported DB_DRIVER '{$driver}'. Use 'sqlite' or 'mysql'."),
    };

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if (isDatabaseEmpty($pdo, $driver)) {
        applySchemaFile($pdo, $driver);
    }
    runPendingMigrations($pdo, $driver);

    return $pdo;
}

function connectSqlite(): PDO {
    $path = env('DB_SQLITE_PATH', 'db/payment_control.sqlite');
    if (!preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path)) {
        $path = ROOT_PATH . '/' . $path;
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    return $pdo;
}

function connectMysql(): PDO {
    $host = env('DB_HOST', '127.0.0.1');
    $port = env('DB_PORT', '3306');
    $name = env('DB_NAME');
    $user = env('DB_USER');
    $password = env('DB_PASSWORD', '');
    $charset = env('DB_CHARSET', 'utf8mb4');

    if (!$name || !$user) {
        throw new RuntimeException('DB_NAME and DB_USER must be set in .env when DB_DRIVER=mysql. This app never creates the database or user for you — create them first (see database/MIGRATION_MYSQL.md).');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function isDatabaseEmpty(PDO $pdo, string $driver): bool {
    if ($driver === 'mysql') {
        $count = $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    } else {
        $count = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
    }
    return (int)$count === 0;
}

function applySchemaFile(PDO $pdo, string $driver): void {
    $path = ROOT_PATH . "/database/schema.{$driver}.sql";
    if (!is_file($path)) {
        throw new RuntimeException("Missing baseline schema file: {$path}");
    }
    runSqlFile($pdo, $path);
}

function runPendingMigrations(PDO $pdo, string $driver): void {
    // Defensive: works the first time this ships against a database that
    // predates this whole system (e.g. today's live SQLite file) and so has
    // no schema_migrations table yet, without needing a migration of its own.
    $pdo->exec($driver === 'mysql'
        ? "CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(255) PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        : "CREATE TABLE IF NOT EXISTS schema_migrations (migration TEXT PRIMARY KEY, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)"
    );

    $applied = array_flip($pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));

    $dir = ROOT_PATH . "/database/migrations/{$driver}";
    if (!is_dir($dir)) {
        return;
    }
    $files = glob($dir . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            continue;
        }

        if ($driver === 'mysql') {
            // MySQL and MariaDB implicitly commit most DDL, so a transaction
            // here would already be gone by the time PDO::commit() runs.
            try {
                runSqlFile($pdo, $file);
                $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (?, CURRENT_TIMESTAMP)');
                $stmt->execute([$name]);
            } catch (Throwable $e) {
                throw new RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
            }
        } else {
            $pdo->beginTransaction();
            try {
                runSqlFile($pdo, $file);
                $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (?, CURRENT_TIMESTAMP)');
                $stmt->execute([$name]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw new RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
            }
        }
    }
}

function runSqlFile(PDO $pdo, string $path): void {
    $sql = file_get_contents($path);
    foreach (splitSqlStatements($sql) as $statement) {
        $pdo->exec($statement);
    }
}

/**
 * Splits a plain SQL script (no stored procedures, no DELIMITER changes) on
 * statement-terminating semicolons, respecting single/double-quoted string
 * literals and -- line comments so a semicolon inside a quoted value never
 * causes a false split.
 */
function splitSqlStatements(string $sql): array {
    $statements = [];
    $current = '';
    $inSingleQuote = false;
    $inDoubleQuote = false;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];

        if (!$inSingleQuote && !$inDoubleQuote && $char === '-' && ($sql[$i + 1] ?? '') === '-') {
            $eol = strpos($sql, "\n", $i);
            $i = ($eol === false) ? $len : $eol;
            continue;
        }

        if ($char === "'" && !$inDoubleQuote) {
            $inSingleQuote = !$inSingleQuote;
        } elseif ($char === '"' && !$inSingleQuote) {
            $inDoubleQuote = !$inDoubleQuote;
        }

        if ($char === ';' && !$inSingleQuote && !$inDoubleQuote) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}
