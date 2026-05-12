<?php

function run_migrations(PDO $pdo): void {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL UNIQUE,
            applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');

    $migrationFiles = glob(__DIR__ . '/../migrations/*.sql') ?: [];
    sort($migrationFiles);

    $stmt = $pdo->query('SELECT filename FROM migrations');
    $applied = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

    foreach ($migrationFiles as $file) {
        $filename = basename($file);
        if (isset($applied[$filename])) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec(file_get_contents($file));
            $insert = $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)');
            $insert->execute([$filename]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
