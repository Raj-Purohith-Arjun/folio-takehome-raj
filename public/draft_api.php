<?php
/**
 * POST endpoint for AI-assisted document drafting.
 * Accepts: title (form field)
 * Returns: {"body":"..."} or {"error":"..."}
 */

require __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$title = trim($_POST['title'] ?? '');
if ($title === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Title is required.']);
    exit;
}

try {
    $body = ai_draft($title);
    audit_log('ai_draft', 'document', 0, ['title' => $title]);
    echo json_encode(['body' => $body]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
