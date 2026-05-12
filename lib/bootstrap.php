<?php

date_default_timezone_set('America/Chicago');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function slug_token(): string {
    static $adjectives = [
        'amber', 'azure', 'bold', 'bright', 'calm', 'cedar', 'cobalt', 'coral', 'crisp',
        'dawn', 'dusk', 'ember', 'frost', 'gold', 'jade', 'lake', 'lark', 'lime', 'maple',
        'mint', 'moss', 'oak', 'pine', 'rose', 'ruby', 'sage', 'salt', 'sand', 'sky',
        'slate', 'snow', 'steel', 'storm', 'swift', 'teal', 'tide', 'warm', 'wild',
    ];
    static $nouns = [
        'arch', 'bay', 'bird', 'bluff', 'brook', 'canyon', 'cave', 'cliff', 'cloud',
        'coast', 'creek', 'crest', 'delta', 'dune', 'falls', 'field', 'ford', 'glen',
        'grove', 'haven', 'hill', 'inlet', 'isle', 'marsh', 'mesa', 'mill', 'moor',
        'peak', 'plain', 'pond', 'ridge', 'river', 'rock', 'shore', 'spring', 'stone',
        'summit', 'trail', 'vale', 'view', 'wave', 'wood',
    ];

    $adj = $adjectives[array_rand($adjectives)];
    $noun = $nouns[array_rand($nouns)];
    $num = random_int(10, 99);

    return "{$adj}-{$noun}-{$num}";
}

function unique_share_token(): string {
    do {
        $token = slug_token();
        $stmt = db()->prepare('SELECT 1 FROM shares WHERE token = ?');
        $stmt->execute([$token]);
    } while ($stmt->fetch());

    return $token;
}

function normalize_publish_at(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return date('Y-m-d H:i:s');
    }

    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value) ?: DateTime::createFromFormat('Y-m-d H:i:s', $value);
    if (!$dt) {
        throw new InvalidArgumentException('Publish date must be a valid date and time.');
    }

    return $dt->format('Y-m-d H:i:s');
}

function is_published(array $doc): bool {
    if (empty($doc['publish_at'])) {
        return true;
    }

    return strtotime($doc['publish_at']) <= time();
}

function ai_draft_mode(): string {
    $mode = strtolower(trim(getenv('AI_DRAFT_MODE') ?: ''));
    if ($mode === '') {
        return (getenv('AI_API_KEY') || getenv('OPENAI_API_KEY')) ? 'api' : 'local';
    }

    return $mode === 'api' ? 'api' : 'local';
}

function ai_draft_enabled(): bool {
    return ai_draft_mode() === 'local' || getenv('AI_API_KEY') || getenv('OPENAI_API_KEY');
}

function ai_draft_label(): string {
    return ai_draft_mode() === 'local' ? 'local no-cost draft mode' : 'API draft mode';
}

function local_draft(string $title): string {
    $safeTitle = trim($title) !== '' ? trim($title) : 'this notice';

    return "This draft provides clear public information about {$safeTitle}. Please review the details below and update any dates, locations, contact information, or district-specific requirements before publishing.\n\nOur goal is to make this information easy for residents, board members, and staff to understand. If this document describes a service change, meeting, maintenance window, or policy update, include who is affected, what action is needed, and where readers can find help.\n\nFor questions, please contact the district office during regular business hours. This draft should be reviewed by staff before it is shared publicly.";
}

function ai_draft(string $title): array {
    if (ai_draft_mode() === 'local') {
        return [
            'body' => local_draft($title),
            'source' => 'local',
            'model' => 'local-template',
        ];
    }

    $apiKey = getenv('AI_API_KEY') ?: getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        throw new RuntimeException('Set AI_DRAFT_MODE=local for no-cost local drafts, or configure AI_API_KEY/OPENAI_API_KEY for API drafting.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP curl extension is required for API drafting.');
    }

    $model = getenv('AI_MODEL') ?: getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
    $endpoint = getenv('AI_API_BASE_URL') ?: 'https://api.openai.com/v1/chat/completions';

    $payload = json_encode([
        'model' => $model,
        'max_tokens' => 512,
        'temperature' => 0.7,
        'messages' => [
            [
                'role' => 'system',
                'content' => "You write body text for official documents published by U.S. local-government special districts. Use a clear, professional, accessible tone. Do not repeat the title, add a sign-off, or use legalese. Write 2-4 short paragraphs of plain prose.",
            ],
            [
                'role' => 'user',
                'content' => 'Write the body for a district document titled: "' . $title . '"',
            ],
        ],
    ]);

    if ($payload === false) {
        throw new RuntimeException('Could not encode AI request payload.');
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Could not reach AI API: ' . $curlError);
    }

    $data = json_decode($raw, true);
    if ($httpCode !== 200) {
        $message = $data['error']['message'] ?? 'HTTP ' . $httpCode . ' from AI API';
        $allowLocalFallback = strtolower(getenv('AI_DRAFT_FALLBACK') ?: 'local') === 'local';
        if ($allowLocalFallback && ($httpCode === 429 || stripos($message, 'quota') !== false || stripos($message, 'rate limit') !== false)) {
            return [
                'body' => local_draft($title),
                'source' => 'local-fallback',
                'model' => 'local-template',
                'error' => $message,
            ];
        }

        throw new RuntimeException('AI API error: ' . $message);
    }

    $body = trim($data['choices'][0]['message']['content'] ?? '');
    if ($body === '') {
        throw new RuntimeException('AI API returned an empty response.');
    }

    return [
        'body' => $body,
        'source' => 'api',
        'model' => $model,
    ];
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
