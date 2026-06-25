<?php
/**
 * zanxpk Bot — بوت تيليجرام مستقل (Webhook) لتصفح أحدث التطبيقات والألعاب
 * يستخدم نفس config.php الخاص بالموقع، ونفس قاعدة البيانات.
 *
 * تفعيل الـWebhook بعد رفع الملف وملء BOT_TOKEN في config.php:
 *   https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://YOURDOMAIN/telegram_bot.php
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require __DIR__ . '/config.php';

if (!BOT_TOKEN) { http_response_code(200); echo 'BOT_TOKEN not configured'; exit; }

/* ---------------------------------------------------------------------
   DB
   --------------------------------------------------------------------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (DB_DRIVER === 'sqlite') {
        $pdo = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
    } else {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function migrate_bot(): void
{
    $pdo = db();
    $ts = DB_DRIVER === 'sqlite' ? "TEXT DEFAULT (datetime('now'))" : 'DATETIME DEFAULT CURRENT_TIMESTAMP';
    $engine = DB_DRIVER === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    // الجدول الأساسي قد يكون أُنشئ من index.php، فقط نضمن وجوده هنا أيضاً
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_users (
        chat_id VARCHAR(40) PRIMARY KEY,
        user_id INT NULL,
        username VARCHAR(120) NULL,
        joined_at $ts
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(80) PRIMARY KEY, v TEXT NULL)$engine");

    // أعمدة إضافية على telegram_users — تُضاف فقط إن لم تكن موجودة
    $existing = [];
    try {
        $col = DB_DRIVER === 'sqlite' ? "PRAGMA table_info(telegram_users)" : "SHOW COLUMNS FROM telegram_users";
        foreach ($pdo->query($col)->fetchAll() as $row) {
            $existing[] = DB_DRIVER === 'sqlite' ? $row['name'] : $row['Field'];
        }
    } catch (Throwable $e) {}

    $wanted = [
        'is_banned' => 'TINYINT NOT NULL DEFAULT 0',
        'state'     => 'TEXT NULL',
    ];
    foreach ($wanted as $col => $def) {
        if (!in_array($col, $existing, true)) {
            try { $pdo->exec("ALTER TABLE telegram_users ADD COLUMN $col $def"); } catch (Throwable $e) {}
        }
    }
}
migrate_bot();

/* ---------------------------------------------------------------------
   Helpers
   --------------------------------------------------------------------- */
function setting(string $k, $default = '')
{
    $st = db()->prepare("SELECT v FROM settings WHERE k = ?");
    $st->execute([$k]);
    $row = $st->fetch();
    return $row ? $row['v'] : $default;
}

function tg_api(string $method, array $params = [])
{
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/$method");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}
function tg_send(string $chatId, string $text, ?array $keyboard = null): void
{
    $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard !== null) $params['reply_markup'] = json_encode($keyboard);
    tg_api('sendMessage', $params);
}
function tg_answer_cb(string $cbId, string $text = '', bool $alert = false): void
{
    tg_api('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => $text, 'show_alert' => $alert]);
}

function main_keyboard(): array
{
    return ['keyboard' => [
        [['text' => '📱 أحدث التطبيقات'], ['text' => '🎮 أحدث الألعاب']],
        [['text' => '🔍 بحث عن تطبيق'], ['text' => '🛍️ المنتجات']],
        [['text' => '📣 قناتنا'], ['text' => '❓ مساعدة']],
    ], 'resize_keyboard' => true];
}

function get_tg_user(string $chatId): ?array
{
    $st = db()->prepare("SELECT * FROM telegram_users WHERE chat_id = ?");
    $st->execute([$chatId]);
    return $st->fetch() ?: null;
}
function set_state(string $chatId, ?array $state): void
{
    db()->prepare("UPDATE telegram_users SET state = ? WHERE chat_id = ?")
        ->execute([$state ? json_encode($state) : null, $chatId]);
}
function is_owner(string $chatId): bool { return OWNER_ID && (string)OWNER_ID === $chatId; }

function app_link(int $id): string
{
    return rtrim(SITE_URL, '/') . '/index.php?page=app&id=' . $id;
}

function send_apps_list(string $chatId, string $kind): void
{
    $st = db()->prepare("SELECT * FROM apps WHERE status='published' AND kind=? ORDER BY id DESC LIMIT 8");
    $st->execute([$kind]);
    $apps = $st->fetchAll();
    if (!$apps) { tg_send($chatId, $kind === 'game' ? '🎮 لا توجد ألعاب منشورة حالياً.' : '📱 لا توجد تطبيقات منشورة حالياً.'); return; }
    $textOut = ($kind === 'game' ? "🎮 <b>أحدث الألعاب</b>\n\n" : "📱 <b>أحدث التطبيقات</b>\n\n");
    $kb = [];
    foreach ($apps as $a) {
        $textOut .= "▫️ <b>" . e_($a['name']) . "</b>" . ($a['version'] ? " — v" . e_($a['version']) : '') . "\n";
        $kb[] = [['text' => '⬇️ ' . $a['name'], 'url' => app_link((int)$a['id'])]];
    }
    tg_send($chatId, $textOut, ['inline_keyboard' => $kb]);
}

/* ---------------------------------------------------------------------
   Update intake
   --------------------------------------------------------------------- */
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { echo 'ok'; exit; }

if (!empty($update['callback_query'])) {
    handle_callback($update['callback_query']);
    echo 'ok'; exit;
}

if (empty($update['message'])) { echo 'ok'; exit; }
$msg = $update['message'];
$chatId = (string)$msg['chat']['id'];
$username = $msg['from']['username'] ?? '';
$text = trim($msg['text'] ?? '');

// تسجيل/تحديث المستخدم
$st = db()->prepare(DB_DRIVER === 'sqlite'
    ? "INSERT INTO telegram_users (chat_id, username) VALUES (?,?) ON CONFLICT(chat_id) DO UPDATE SET username = ?"
    : "INSERT INTO telegram_users (chat_id, username) VALUES (?,?) ON DUPLICATE KEY UPDATE username = ?");
$st->execute([$chatId, $username, $username]);

$tgUser = get_tg_user($chatId);
if ($tgUser && $tgUser['is_banned']) { echo 'ok'; exit; }

if ($text === '/start') {
    set_state($chatId, null);
    tg_send($chatId,
        "👋 <b>أهلاً بك في " . e_(setting('site_name', 'zanxpk')) . "!</b>\n\n" .
        "📱 تصفّح أحدث التطبيقات والألعاب وحمّلها مباشرة، أو ابحث عن اسم تطبيق معيّن.\n\n" .
        "استخدم الأزرار بالأسفل:",
        main_keyboard()
    );
    exit;
}

if ($text === '/admin') {
    if (!is_owner($chatId)) { tg_send($chatId, '⛔ هذا الأمر للمالك فقط.'); exit; }
    $totalUsers = db()->query("SELECT COUNT(*) c FROM telegram_users")->fetch()['c'];
    $appsCount = db()->query("SELECT COUNT(*) c FROM apps WHERE status='published'")->fetch()['c'];
    $pendingOrders = db()->query("SELECT COUNT(*) c FROM orders WHERE status='pending'")->fetch()['c'];
    tg_send($chatId,
        "🎛️ <b>لوحة تحكم البوت</b>\n\n👥 مستخدمو البوت: <b>$totalUsers</b>\n📱 تطبيقات منشورة: <b>$appsCount</b>\n🛍️ طلبات معلّقة: <b>$pendingOrders</b>\n\n" .
        "لإدارة الموقع كاملاً (تطبيقات، منتجات، طلبات، إعدادات) ادخل من لوحة التحكم على الموقع."
    );
    exit;
}

// التحقق من حالة بانتظار إدخال (بحث...)
$state = $tgUser['state'] ? json_decode($tgUser['state'], true) : null;

if ($state && ($state['awaiting'] ?? '') === 'search') {
    set_state($chatId, null);
    $q = '%' . $text . '%';
    $st = db()->prepare("SELECT * FROM apps WHERE status='published' AND name LIKE ? ORDER BY id DESC LIMIT 8");
    $st->execute([$q]);
    $apps = $st->fetchAll();
    if (!$apps) { tg_send($chatId, '❌ لم يتم العثور على نتائج لـ «' . e_($text) . '».', main_keyboard()); exit; }
    $textOut = "🔍 <b>نتائج البحث عن «" . e_($text) . "»</b>\n\n";
    $kb = [];
    foreach ($apps as $a) {
        $textOut .= "▫️ <b>" . e_($a['name']) . "</b>\n";
        $kb[] = [['text' => '⬇️ ' . $a['name'], 'url' => app_link((int)$a['id'])]];
    }
    tg_send($chatId, $textOut, ['inline_keyboard' => $kb]);
    exit;
}

switch ($text) {
    case '📱 أحدث التطبيقات':
        send_apps_list($chatId, 'app');
        break;

    case '🎮 أحدث الألعاب':
        send_apps_list($chatId, 'game');
        break;

    case '🔍 بحث عن تطبيق':
        set_state($chatId, ['awaiting' => 'search']);
        tg_send($chatId, '✏️ اكتب اسم التطبيق أو اللعبة الذي تبحث عنه:');
        break;

    case '🛍️ المنتجات':
        $products = db()->query("SELECT * FROM products WHERE status='active' ORDER BY id DESC LIMIT 8")->fetchAll();
        if (!$products) { tg_send($chatId, '🛍️ لا توجد منتجات حالياً.'); break; }
        $textOut = "🛍️ <b>أحدث المنتجات</b>\n\n";
        foreach ($products as $p) {
            $textOut .= "▫️ <b>" . e_($p['name']) . "</b> — {$p['price']}$\n";
        }
        $textOut .= "\n🌐 لطلب الشراء زر المتجر على الموقع: " . e_(rtrim(SITE_URL, '/') . '/index.php?page=store');
        tg_send($chatId, $textOut);
        break;

    case '📣 قناتنا':
        $ch = setting('telegram_channel_url', '');
        tg_send($chatId, $ch ? "📣 تابع قناتنا للحصول على آخر التحديثات:\n" . e_($ch) : '📣 لا توجد قناة مضافة حالياً.');
        break;

    case '❓ مساعدة':
        tg_send($chatId,
            "❓ <b>دليل الاستخدام</b>\n\n📱 أحدث التطبيقات / 🎮 أحدث الألعاب: عرض آخر الإضافات مع رابط تحميل مباشر\n" .
            "🔍 بحث عن تطبيق: اكتب اسم التطبيق للبحث عنه\n🛍️ المنتجات: تصفّح متجر المنتجات الرقمية\n\n" .
            "🌐 الموقع: " . e_(SITE_URL)
        );
        break;

    default:
        tg_send($chatId, 'استخدم الأزرار بالأسفل 👇', main_keyboard());
}
echo 'ok';
exit;

/* ---------------------------------------------------------------------
   Callback (inline buttons)
   --------------------------------------------------------------------- */
function handle_callback(array $cb): void
{
    $cbId = $cb['id'];
    tg_answer_cb($cbId);
}

function e_($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
