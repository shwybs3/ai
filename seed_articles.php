<?php
/**
 * seed_articles.php — one-time seeder for 20 original, long-form Arabic
 * articles (5,000–10,000 characters of content each).
 * =======================================================================
 * Idempotent: article_upsert() matches by slug, so running this file
 * again just updates the same 20 rows instead of duplicating them.
 *
 * Run once via CLI:   php seed_articles.php
 * Or visit once in the browser as an admin (guarded below), then delete
 * this file or leave it — reruns are harmless.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/articles.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    session_start();
    if (!is_admin()) { http_response_code(403); die('🚫 هذا السكربت للأدمن فقط، أو نفّذه عبر CLI: php seed_articles.php'); }
}

articles_migrate();

$articles = require __DIR__ . '/seed/articles_data.php';

$done = 0;
foreach ($articles as $a) {
    article_upsert($a);
    $done++;
}

$msg = "تم استيراد/تحديث {$done} مقالًا بنجاح.";
if ($isCli) { echo $msg . "\n"; } else { echo $msg; }
