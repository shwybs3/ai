<?php
/**
 * newsletter.php — subscriber capture + optional Brevo sync
 * =========================================================
 * POST { email } (JSON or form) → stores the subscriber locally and,
 * if a Brevo (Sendinblue) API key is configured, adds them to a Brevo
 * contact list so you can run real email campaigns from Brevo's UI
 * (free tier: 300 emails/day). No third-party paid "IndexerNow" needed.
 *
 * Settings used (from the settings table, set in admin):
 *   brevo_api_key   — your Brevo v3 API key (kept server-side only)
 *   brevo_list_id   — numeric Brevo list id to add contacts to
 *
 * Returns JSON: { ok: bool, msg: string }
 */

header('Content-Type: application/json; charset=utf-8');

$rootConfig = __DIR__ . '/config.php';
if (is_file($rootConfig)) require_once $rootConfig;
if (is_file(__DIR__ . '/includes/bootstrap.php')) require_once __DIR__ . '/includes/bootstrap.php';

/* read email from JSON body or form */
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$email = trim($data['email'] ?? $_POST['email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'msg' => 'بريد غير صالح']);
    exit;
}

/* 1) store locally (best-effort; never blocks the response) */
$storedLocally = false;
if (function_exists('db')) {
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscribers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email VARCHAR(190) NOT NULL UNIQUE,
            source VARCHAR(60) NULL,
            synced TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $stmt = $pdo->prepare('INSERT INTO subscribers (email, source) VALUES (?, ?)');
        try { $stmt->execute([$email, 'site']); } catch (Throwable $e) { /* duplicate is fine */ }
        $storedLocally = true;
    } catch (Throwable $e) { /* fall through to file store */ }
}
if (!$storedLocally) {
    // fallback: append to a newline file outside webroot if possible
    $f = is_writable(dirname(__DIR__)) ? dirname(__DIR__) . '/subscribers.txt' : __DIR__ . '/subscribers.txt';
    @file_put_contents($f, $email . "\t" . date('c') . "\n", FILE_APPEND | LOCK_EX);
}

/* 2) sync to Brevo if configured */
$brevoKey = function_exists('setting') ? setting('brevo_api_key') : '';
$listId   = function_exists('setting') ? (int)setting('brevo_list_id') : 0;

if ($brevoKey) {
    $payload = ['email' => $email, 'updateEnabled' => true];
    if ($listId) $payload['listIds'] = [$listId];
    $ch = curl_init('https://api.brevo.com/v3/contacts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $brevoKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 201 created, 204 updated. Brevo returns "Contact already exist" as 400 — still success for us.
    if ($code === 201 || $code === 204 || ($code === 400 && stripos((string)$resp, 'already') !== false)) {
        if (function_exists('db')) { try { db()->prepare('UPDATE subscribers SET synced=1 WHERE email=?')->execute([$email]); } catch (Throwable $e) {} }
        echo json_encode(['ok' => true, 'msg' => 'تم الاشتراك بنجاح!']);
        exit;
    }
    // Brevo failed but we stored locally — still a success for the user
    echo json_encode(['ok' => true, 'msg' => 'تم تسجيل بريدك.']);
    exit;
}

/* no Brevo configured — local store is enough */
echo json_encode(['ok' => true, 'msg' => 'تم تسجيل بريدك، شكرًا لك!']);
