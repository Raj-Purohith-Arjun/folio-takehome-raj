<?php
/**
 * migrate.php — run from repo root: php migrate.php
 *
 * Scans migrations/ for *.sql files in filename order and applies any that
 * have not been recorded in the migrations table yet. Safe to re-run.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/migrations.php';

$pdo = db();
$pdo->exec('
    CREATE TABLE IF NOT EXISTS migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL UNIQUE,
        applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )
');

$applied = $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files);

$ran = 0;
foreach ($files as $path) {
    $filename = basename($path);
    if (isset($applied[$filename])) {
        echo "  [skip] {$filename} (already applied)\n";
        continue;
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec(file_get_contents($path));
        $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)')->execute([$filename]);
        $pdo->commit();
        echo "  [ok]   {$filename}\n";
        $ran++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "  [FAIL] {$filename}: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo $ran > 0 ? "\n{$ran} migration(s) applied.\n" : "\nNothing to migrate.\n";
