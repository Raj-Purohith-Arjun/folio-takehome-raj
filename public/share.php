<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$docParam = trim($_GET['doc'] ?? '');
$q = trim($_GET['q'] ?? '');
$error = null;
$created_token = null;
$doc = null;
$matches = [];
$now = date('Y-m-d H:i:s');

if ($docParam !== '') {
    $stmt = db()->prepare('SELECT * FROM documents WHERE id = ?');
    $stmt->execute([(int) $docParam]);
    $doc = $stmt->fetch();

    if (!$doc) {
        http_response_code(404);
        render_header('Not found', $staff);
        ?>
        <div class="banner banner-error">Document not found.</div>
        <p><a href="/share.php" class="back-link">← search documents</a></p>
        <?php
        render_footer();
        exit;
    }
} elseif ($q !== '') {
    $stmt = db()->prepare('
        SELECT *
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
    $contains = '%' . $q . '%';
    $prefix = $q . '%';
    $stmt->execute([$contains, $contains, $now, $q, $prefix]);
    $matches = $stmt->fetchAll();
}

$canShare = $doc && is_published($doc);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canShare) {
    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        $error = 'Recipient email is required.';
    } else {
        $token = unique_share_token();
        $stmt = db()->prepare('
            INSERT INTO shares (document_id, token, recipient_email)
            VALUES (?, ?, ?)
        ');
        $stmt->execute([$doc['id'], $token, $email]);
        $shareId = (int) db()->lastInsertId();
        audit_log('create', 'share', $shareId, [
            'document_id' => $doc['id'],
            'token_shape' => 'slug',
            'recipient_email' => $email,
        ]);
        $created_token = $token;
    }
}

render_header($doc ? 'Share · ' . $doc['title'] : 'Find document to share', $staff);
?>

<a href="/admin.php" class="back-link">← back to admin</a>

<?php if (!$doc): ?>
    <h1 class="page-title">Find document to share</h1>
    <p class="page-subtitle">Search published documents by title or numeric ID. Results prefer exact and prefix title matches, then partial matches.</p>

    <section class="card">
        <h2 class="card-title">Search documents</h2>
        <form method="get" class="search-form">
            <div class="form-field">
                <label for="q">Document title or ID</label>
                <input type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="Welcome Packet" required>
            </div>
            <button type="submit" class="btn">Search</button>
        </form>
    </section>

    <?php if ($q !== ''): ?>
        <section class="card">
            <h2 class="card-title">Search results</h2>
            <?php if (empty($matches)): ?>
                <p class="empty">No published documents matched “<?= h($q) ?>”.</p>
            <?php else: ?>
                <table class="data">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matches as $match): ?>
                            <tr>
                                <td class="id">#<?= (int) $match['id'] ?></td>
                                <td><?= h($match['title']) ?></td>
                                <td><span class="status status-live">Published</span></td>
                                <td><a href="/share.php?doc=<?= (int) $match['id'] ?>" class="btn-link">Create share →</a></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            <?php endif ?>
        </section>
    <?php endif ?>
<?php else: ?>
    <h1 class="page-title">Share "<?= h($doc['title']) ?>"</h1>
    <p class="page-subtitle">Generate a recipient link with a human-readable token.</p>

    <?php if (!$canShare): ?>
        <div class="banner banner-warn">This document is scheduled for <?= h($doc['publish_at']) ?>, so it is not available for sharing yet.</div>
    <?php endif ?>

    <?php if ($error): ?>
        <div class="banner banner-error"><?= h($error) ?></div>
    <?php endif ?>

    <?php if ($created_token): ?>
        <div class="banner banner-success">
            <p><strong>Human-readable token:</strong> <code><?= h($created_token) ?></code></p>
            <p>Share link ready: <code>http://<?= h($_SERVER['HTTP_HOST']) ?>/view.php?token=<?= h($created_token) ?></code></p>
        </div>
    <?php endif ?>

    <?php if ($canShare): ?>
        <section class="card">
            <h2 class="card-title">Create share link</h2>
            <form method="post">
                <div class="form-field">
                    <label for="email">Recipient email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <button type="submit" class="btn">Generate link</button>
            </form>
        </section>
    <?php endif ?>
<?php endif ?>

<?php render_footer(); ?>
