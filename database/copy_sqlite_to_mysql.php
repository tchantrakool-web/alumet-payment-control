<?php
/**
 * One-off copy: today's SQLite data -> an already-existing, empty MySQL/
 * MariaDB database. This script never creates a database or a database
 * user — DB_NAME in .env must already exist, created by you.
 *
 * Usage:
 *   php database/copy_sqlite_to_mysql.php --dry-run
 *   php database/copy_sqlite_to_mysql.php --force
 *
 * --dry-run   Report what would be copied (row counts per table). No writes.
 * --force     Required if the target tables already contain any rows —
 *             without it, the script refuses to touch a non-empty target.
 *             With it, every target table is truncated first, then the
 *             whole copy runs inside a single transaction.
 *
 * Tables are copied in foreign-key-safe order. Every row's id is preserved
 * (MySQL accepts explicit values for AUTO_INCREMENT columns); each table's
 * AUTO_INCREMENT counter is bumped past the copied max afterward so the next
 * natural insert on MySQL doesn't collide with a copied id.
 */

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/env.php';
require_once ROOT_PATH . '/config/database.php';

loadEnv(ROOT_PATH . '/.env');

const TABLE_ORDER = [
    'roles',
    'users',
    'vendors',
    'sap_import_batches',
    'sap_ap_invoices',
    'finance_import_batches',
    'finance_ap_records',
    'payment_requests',
    'payment_request_items',
    'approval_matrix',
    'approval_tasks',
    'approval_history',
    'payment_batches',
    'payment_batch_items',
    'cheques',
    'attachments',
    'audit_logs',
    'system_settings',
    'import_error_logs',
    'notification_logs',
    'notification_reads',
];

function main(array $argv): int {
    $dryRun = in_array('--dry-run', $argv, true);
    $force = in_array('--force', $argv, true);

    if (!$dryRun && !$force) {
        fwrite(STDERR, "Refusing to run without --dry-run or --force. See the file header for usage.\n");
        return 1;
    }

    $sqlitePath = env('DB_SQLITE_PATH', 'db/payment_control.sqlite');
    if (!preg_match('#^([a-zA-Z]:)?[/\\\\]#', $sqlitePath)) {
        $sqlitePath = ROOT_PATH . '/' . $sqlitePath;
    }
    if (!is_file($sqlitePath)) {
        fwrite(STDERR, "SQLite source not found: {$sqlitePath}\n");
        return 1;
    }

    $sqlite = new PDO('sqlite:' . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $mysql = connectMysql();
    $mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mysql->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Ensure the target has tables — this is DDL inside a database the user
    // already created, not creating the database itself.
    if (isDatabaseEmpty($mysql, 'mysql')) {
        echo "Target database has no tables yet — applying database/schema.mysql.sql...\n";
        applySchemaFile($mysql, 'mysql');
        runPendingMigrations($mysql, 'mysql');
    }

    echo "\n" . str_pad('TABLE', 28) . str_pad('SOURCE ROWS', 14) . "TARGET ROWS\n";
    $sourceCounts = [];
    $targetCounts = [];
    $nonEmptyTargets = [];
    foreach (TABLE_ORDER as $table) {
        $sourceCounts[$table] = (int)$sqlite->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        $targetCounts[$table] = (int)$mysql->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        if ($targetCounts[$table] > 0) {
            $nonEmptyTargets[] = $table;
        }
        echo str_pad($table, 28) . str_pad((string)$sourceCounts[$table], 14) . $targetCounts[$table] . "\n";
    }
    echo "\n";

    if ($dryRun) {
        echo "Dry run only — no writes made.\n";
        return 0;
    }

    if (!empty($nonEmptyTargets) && !$force) {
        fwrite(STDERR, "Target already has rows in: " . implode(', ', $nonEmptyTargets) . "\n");
        fwrite(STDERR, "Re-run with --force to truncate and copy anyway.\n");
        return 1;
    }

    $columnTypeCache = [];
    $maxIds = [];

    // TRUNCATE and ALTER TABLE (used below to reset AUTO_INCREMENT) are both
    // DDL, and MySQL implicitly commits the current transaction on any DDL
    // statement. So the --force clear uses DELETE (which IS transactional and
    // rolls back with everything else), and every AUTO_INCREMENT reset is
    // deferred until after commit — keeping the data copy itself as the one
    // real transaction this script promises.
    $mysql->beginTransaction();
    try {
        if (!empty($nonEmptyTargets)) {
            echo "Clearing target tables (--force)...\n";
            $mysql->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach (array_reverse(TABLE_ORDER) as $table) {
                $mysql->exec("DELETE FROM {$table}");
            }
            $mysql->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        foreach (TABLE_ORDER as $table) {
            $rows = $sqlite->query("SELECT * FROM {$table}")->fetchAll();
            if (empty($rows)) {
                echo "  {$table}: 0 rows, skipped\n";
                continue;
            }

            $columns = array_keys($rows[0]);
            $dateColumns = dateTypedColumns($mysql, $table, $columnTypeCache);

            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $columnList = implode(',', $columns);
            $insert = $mysql->prepare("INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})");

            $maxId = 0;
            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $col) {
                    $value = $row[$col];
                    if ($value === '' && isset($dateColumns[$col])) {
                        $value = null; // MySQL strict mode rejects '' for DATE/DATETIME columns
                    }
                    $values[] = $value;
                }
                $insert->execute($values);
                if (isset($row['id']) && (int)$row['id'] > $maxId) {
                    $maxId = (int)$row['id'];
                }
            }

            if ($maxId > 0) {
                $maxIds[$table] = $maxId;
            }

            echo "  {$table}: " . count($rows) . " row(s) copied\n";
        }

        $mysql->commit();
        echo "\nData copy committed in one transaction.\n";
    } catch (Throwable $e) {
        // FOREIGN_KEY_CHECKS is session-scoped and is not restored by a
        // rollback. Always put the connection back in its safe default state.
        try {
            $mysql->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable) {
            // Preserve the original copy error below.
        }
        if ($mysql->inTransaction()) {
            $mysql->rollBack();
        }
        fwrite(STDERR, "\nCopy failed, transaction rolled back: " . $e->getMessage() . "\n");
        return 1;
    }

    // AUTO_INCREMENT resets happen after commit since ALTER TABLE would
    // otherwise implicitly commit the transaction above early. Each one is
    // independently idempotent (just sets a counter), so a failure here
    // doesn't put already-committed data at risk — it's reported, not fatal.
    foreach ($maxIds as $table => $maxId) {
        try {
            $mysql->exec("ALTER TABLE {$table} AUTO_INCREMENT = " . ($maxId + 1));
        } catch (Throwable $e) {
            fwrite(STDERR, "Warning: could not reset AUTO_INCREMENT on {$table}: " . $e->getMessage() . "\n");
        }
    }

    echo "Done.\n";
    return 0;
}

/** @return array<string,true> column name => true, for DATE/DATETIME/TIMESTAMP columns */
function dateTypedColumns(PDO $mysql, string $table, array &$cache): array {
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $stmt = $mysql->prepare("
        SELECT COLUMN_NAME FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND DATA_TYPE IN ('date', 'datetime', 'timestamp')
    ");
    $stmt->execute([$table]);
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $cache[$table] = array_fill_keys($cols, true);
}

exit(main($argv));
