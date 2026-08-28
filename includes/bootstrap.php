<?php
/**
 * bootstrap.php — shared DB/session/auth helpers for every entry point.
 * ======================================================================
 * index.php originally defined db()/e()/setting()/is_admin()/csrf_*()
 * inline, which meant every OTHER standalone entry file (tools.php,
 * sitemap.php, newsletter.php, articles.php) that only loaded config.php
 * never actually got a working db() — function_exists('db') was always
 * false outside index.php, so DB-backed features on those pages silently
 * no-op. This file is the single source of truth; index.php requires it
 * too (and no longer defines these itself) so behavior is identical
 * everywhere.
 *
 * Usage: require_once __DIR__ . '/config.php'; then
 *        require_once __DIR__ . '/includes/bootstrap.php';
 * Call session_start() yourself first if the page needs auth/CSRF.
 */

if (defined('YASSOTA_BOOTSTRAP')) return;
define('YASSOTA_BOOTSTRAP', 1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        if (DB_DRIVER === 'sqlite') {
            $pdo = new PDO('sqlite:' . __DIR__ . '/../database.sqlite');
        } else {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        }
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        die('فشل الاتصال بقاعدة البيانات: ' . htmlspecialchars($e->getMessage()));
    }
    return $pdo;
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function setting(string $k, $default = '')
{
    static $cache = [];
    if (isset($cache[$k])) return $cache[$k];
    $st = db()->prepare("SELECT v FROM settings WHERE k = ?");
    $st->execute([$k]);
    $row = $st->fetch();
    return $cache[$k] = ($row ? $row['v'] : $default);
}

function set_setting(string $k, $v): void
{
    $st = db()->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = ?");
    if (DB_DRIVER === 'sqlite') {
        $st = db()->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = ?");
    }
    $st->execute([$k, $v, $v]);
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    static $u = null;
    if ($u) return $u;
    $st = db()->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch() ?: null;
    if ($u && $u['is_banned']) { logout(); return null; }
    return $u;
}

function is_admin(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

function require_admin(): void
{
    if (!is_admin()) { http_response_code(403); die('🚫 ممنوع — هذه الصفحة للإدارة فقط.'); }
}

function logout(): void { $_SESSION = []; session_destroy(); }

function redirect(string $url): void { header("Location: $url"); exit; }

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $t = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    if (!$t || !hash_equals($_SESSION['csrf'] ?? '', $t)) { http_response_code(419); die('انتهت صلاحية الجلسة، أعد تحميل الصفحة.'); }
}
