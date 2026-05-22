<?php

declare(strict_types=1);

use App\Config;
use App\Database;

require __DIR__ . '/../../vendor/autoload.php';

define('ABS_PATH', dirname(__DIR__, 2));

$config = Config::load();
$db = Database::connect($config['db']);

$migrationsDir = ABS_PATH . '/public_html/database/migrations';

$argv = $_SERVER['argv'] ?? [];
$dryRun = in_array('--dry-run', $argv, true);
$baseline = in_array('--baseline', $argv, true);

// Ensure the migrations tracking table exists even if 001_init.sql hasn't been applied yet.
$db->executeStatement(
    "CREATE TABLE IF NOT EXISTS schema_migrations (\n"
    . "  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n"
    . "  name VARCHAR(255) NOT NULL,\n"
    . "  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
    . "  UNIQUE KEY name_unique (name)\n"
    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$applied = $db->fetchFirstColumn('SELECT name FROM schema_migrations');
$appliedSet = array_fill_keys(array_map('strval', $applied ?: []), true);

$files = glob($migrationsDir . '/*.sql');
if (!$files) {
    fwrite(STDOUT, "No migrations found in {$migrationsDir}.\n");
    exit(0);
}

sort($files, SORT_STRING);

$pending = [];
foreach ($files as $path) {
    $name = basename($path);
    if (!isset($appliedSet[$name])) {
        $pending[] = $path;
    }
}

if (!$pending) {
    fwrite(STDOUT, "No pending migrations.\n");
    exit(0);
}

// If the DB already has schema but no recorded migrations, force an explicit baseline.
$schemaMigrationsEmpty = count($appliedSet) === 0;
$looksInitialized = (bool) $db->fetchOne("SHOW TABLES LIKE 'events'");
if ($schemaMigrationsEmpty && $looksInitialized && !$baseline) {
    fwrite(STDERR, "Detected existing schema but schema_migrations is empty.\n");
    fwrite(STDERR, "If this DB was initialized manually or via docker init scripts, run:\n");
    fwrite(STDERR, "  php public_html/scripts/migrate.php --baseline\n");
    exit(2);
}

fwrite(STDOUT, $dryRun ? "Pending migrations (dry run):\n" : ($baseline ? "Baselining migrations (no SQL executed):\n" : "Applying migrations:\n"));
foreach ($pending as $path) {
    fwrite(STDOUT, ' - ' . basename($path) . "\n");
}

if ($dryRun) {
    exit(0);
}

if ($baseline) {
    foreach ($pending as $path) {
        $name = basename($path);
        $db->executeStatement('INSERT IGNORE INTO schema_migrations (name) VALUES (?)', [$name]);
        fwrite(STDOUT, "Baselined: {$name}\n");
    }
    fwrite(STDOUT, "Done.\n");
    exit(0);
}

function splitSqlStatements(string $sql): array
{
    $lines = preg_split('/\r\n|\r|\n/', $sql);
    $buf = '';

    foreach ($lines as $line) {
        // Strip full-line comments ("-- ...")
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $buf .= $line . "\n";
    }

    // Split on semicolons. Intentionally simple: assumes no stored procedures/triggers.
    $parts = explode(';', $buf);
    $stmts = [];
    foreach ($parts as $part) {
        $stmt = trim($part);
        if ($stmt !== '') {
            $stmts[] = $stmt;
        }
    }

    return $stmts;
}

foreach ($pending as $path) {
    $name = basename($path);
    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "Failed to read migration: {$name}\n");
        exit(1);
    }

    $stmts = splitSqlStatements($sql);
    if (!$stmts) {
        fwrite(STDOUT, "Skipping empty migration: {$name}\n");
        continue;
    }

    $db->beginTransaction();
    try {
        foreach ($stmts as $stmt) {
            $db->executeStatement($stmt);
        }

        $db->executeStatement('INSERT IGNORE INTO schema_migrations (name) VALUES (?)', [$name]);
        $db->commit();
        fwrite(STDOUT, "Applied: {$name}\n");
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "Failed migration {$name}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "Done.\n");
