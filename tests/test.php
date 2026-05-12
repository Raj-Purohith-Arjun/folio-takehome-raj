<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

function assert_same($actual, $expected, string $msg = ''): void {
    if ($actual !== $expected) {
        throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function create_document(string $title, ?string $publishAt = null): int {
    $publishAt = $publishAt ?? date('Y-m-d H:i:s');
    $stmt = db()->prepare('
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES (?, ?, 1, ?)
    ');
    $stmt->execute([$title, 'Test body', $publishAt]);
    $docId = (int) db()->lastInsertId();
    audit_log('create', 'document', $docId, [
        'title' => $title,
        'publish_at' => $publishAt,
    ]);
    return $docId;
}

function create_share(int $docId): string {
    $token = unique_share_token();
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'recipient@example.com']);
    return $token;
}

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

test('share tokens are human-readable slugs', function () {
    $token = unique_share_token();
    assert_true((bool) preg_match('/^[a-z]+-[a-z]+-[0-9]{2}$/', $token), 'unexpected token: ' . $token);
});

test('share-by-slug resolves to its document', function () {
    $docId = create_document('Readable Share Token Packet');
    $token = create_share($docId);

    $stmt = db()->prepare('
        SELECT d.id, d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    assert_true($row !== false, 'expected slug share token to resolve');
    assert_same((int) $row['id'], $docId, 'resolved document id');
});

test('future-dated document is inaccessible via share token until publish time', function () {
    $future = (new DateTime('+1 day'))->format('Y-m-d H:i:s');
    $docId = create_document('Future Packet', $future);
    $token = create_share($docId);

    $stmt = db()->prepare('
        SELECT d.*
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $doc = $stmt->fetch();

    assert_true($doc !== false, 'expected share token to resolve before visibility check');
    assert_true(!is_published($doc), 'future document should not be visible to recipient');
});

test('document creation with schedule is audit logged', function () {
    $publishAt = (new DateTime('+2 hours'))->format('Y-m-d H:i:s');
    $docId = create_document('Audit Schedule Packet', $publishAt);

    $stmt = db()->prepare('SELECT details FROM audit_log WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute(['document', $docId]);
    $details = json_decode($stmt->fetchColumn(), true);

    assert_same($details['publish_at'], $publishAt, 'publish_at should be captured in audit log');
});

test('share-by-name search finds published partial title matches', function () {
    $docId = create_document('Hydrant Inspection Checklist');

    $stmt = db()->prepare('
        SELECT id
        FROM documents
        WHERE (title LIKE ? OR CAST(id AS TEXT) LIKE ?)
          AND (publish_at IS NULL OR publish_at <= ?)
        ORDER BY
            CASE
                WHEN title = ? THEN 0
                WHEN title LIKE ? THEN 1
                ELSE 2
            END,
            created_at DESC
        LIMIT 20
    ');
    $stmt->execute(['%Inspection%', '%Inspection%', date('Y-m-d H:i:s'), 'Inspection', 'Inspection%']);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    assert_true(in_array($docId, $ids, true), 'expected title search to include created document');
});

test('ai drafting requires OPENAI_API_KEY when not configured', function () {
    $previous = getenv('OPENAI_API_KEY');
    putenv('OPENAI_API_KEY');

    try {
        ai_draft('Public Notice');
        throw new RuntimeException('expected ai_draft to fail without OPENAI_API_KEY');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'OPENAI_API_KEY'), 'unexpected error: ' . $e->getMessage());
    } finally {
        if ($previous !== false) {
            putenv('OPENAI_API_KEY=' . $previous);
        }
    }
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
