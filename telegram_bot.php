<?php
/**
 * Yassota Bot — بوت تيليجرام مستقل (Webhook) لربح النقاط
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
    $id = DB_DRIVER === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $engine = DB_DRIVER === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $ts = DB_DRIVER === 'sqlite' ? "TEXT DEFAULT (datetime('now'))" : 'DATETIME DEFAULT CURRENT_TIMESTAMP';

    // الجدول الأساسي قد يكون أُنشئ من index.php، فقط نضمن وجوده هنا أيضاً
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_users (
        chat_id VARCHAR(40) PRIMARY KEY,
        user_id INT NULL,
        username VARCHAR(120) NULL,
        joined_at $ts
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(80) PRIMARY KEY, v TEXT NULL)$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id $id, title VARCHAR(190) NOT NULL, url VARCHAR(500) NOT NULL,
        seconds INT NOT NULL DEFAULT 15, reward INT NOT NULL DEFAULT 50, active TINYINT NOT NULL DEFAULT 1
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wallets (
        id $id, type VARCHAR(20) NOT NULL, label VARCHAR(120) NOT NULL, address VARCHAR(190) NOT NULL, active TINYINT NOT NULL DEFAULT 1
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_task_done (
        id $id, chat_id VARCHAR(40) NOT NULL, task_id INT NOT NULL, day VARCHAR(10) NOT NULL
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_captcha_logs (
        id $id, chat_id VARCHAR(40) NOT NULL, day VARCHAR(10) NOT NULL, count INT NOT NULL DEFAULT 0
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_withdraw_requests (
        id $id, chat_id VARCHAR(40) NOT NULL, amount_points INT NOT NULL, amount_usd DECIMAL(12,4) NOT NULL,
        wallet_type VARCHAR(20) NOT NULL, wallet_address VARCHAR(190) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at $ts
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_broadcast_queue (
        id $id, product_id INT NOT NULL, sent TINYINT NOT NULL DEFAULT 0, created_at $ts
    )$engine");

    // أعمدة إضافية على telegram_users — تُضاف فقط إن لم تكن موجودة
    $existing = [];
    try {
        $col = DB_DRIVER === 'sqlite' ? "PRAGMA table_info(telegram_users)" : "SHOW COLUMNS FROM telegram_users";
        foreach ($pdo->query($col)->fetchAll() as $row) {
            $existing[] = DB_DRIVER === 'sqlite' ? $row['name'] : $row['Field'];
        }
    } catch (Throwable $e) {}

    $wanted = [
        'points'         => 'INT NOT NULL DEFAULT 0',
        'wallet_type'    => 'VARCHAR(20) NULL',
        'wallet_address' => 'VARCHAR(190) NULL',
        'is_banned'      => 'TINYINT NOT NULL DEFAULT 0',
        'state'          => 'TEXT NULL',
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
function points_to_usd($pts): float { return round($pts * (float)setting('points_rate', 0.001), 4); }

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
        [['text' => '💰 رصيدي'], ['text' => '🪙 كسب نقاط']],
        [['text' => '📋 المهام'], ['text' => '🛍️ المنتجات']],
        [['text' => '💳 محفظتي'], ['text' => '💸 سحب الرصيد']],
        [['text' => '❓ مساعدة']],
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
function add_points(string $chatId, int $amount): void
{
    db()->prepare("UPDATE telegram_users SET points = points + ? WHERE chat_id = ?")->execute([$amount, $chatId]);
}
function is_owner(string $chatId): bool { return OWNER_ID && (string)OWNER_ID === $chatId; }

/* ---------------------------------------------------------------------
   بث المنتجات الجديدة (يعمل عبر استدعاء دوري Cron من سيرفر VPS الخاص بالبوت)
   مثال إعداد Cron: * * * * * curl -s "https://YOURDOMAIN/telegram_bot.php?cron=broadcast" >/dev/null
   هذا الملف يقرأ فقط من bot_broadcast_queue (التي يكتبها الموقع عند نشر منتج) ولا يتلقى أي
   استدعاء مباشر من index.php — التواصل بينهما يتم فقط عبر قاعدة البيانات المشتركة.
   --------------------------------------------------------------------- */
if (($_GET['cron'] ?? '') === 'broadcast') {
    process_broadcast_queue();
    echo 'ok'; exit;
}

function process_broadcast_queue(): void
{
    $rows = db()->query("SELECT q.id qid, p.* FROM bot_broadcast_queue q JOIN products p ON p.id = q.product_id WHERE q.sent = 0 ORDER BY q.id ASC LIMIT 20")->fetchAll();
    if (!$rows) return;
    $chats = db()->query("SELECT chat_id FROM telegram_users")->fetchAll();
    foreach ($rows as $p) {
        $text = "🆕 <b>منتج جديد!</b>\n\n"
            . ($p['icon'] ? $p['icon'] . ' ' : '') . "<b>" . e_($p['name']) . "</b>\n"
            . "💵 السعر: <b>{$p['price']}$</b>\n"
            . ($p['description'] ? e_(mb_substr($p['description'], 0, 200)) . "\n" : "")
            . "\n🔗 " . SITE_URL;
        foreach ($chats as $c) tg_send($c['chat_id'], $text);
        if (OWNER_ID) tg_send((string)OWNER_ID, "📢 تم بث المنتج \"{$p['name']}\" لعدد " . count($chats) . " محادثة.");
        db()->prepare("UPDATE bot_broadcast_queue SET sent = 1 WHERE id = ?")->execute([$p['qid']]);
    }
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
        "👋 <b>أهلاً بك في " . e_(setting('site_name', 'Yassota')) . "!</b>\n\n" .
        "💎 اجمع <b>عملات Yassota</b> عبر:\n🪙 كسب نقاط (كابتشا) · 📋 المهام اليومية\n\n" .
        "💸 واسحبها دولاراً حقيقياً عبر USDT أو الشام كاش، الحد الأدنى " . e_(setting('min_withdraw_usd', 25)) . "$.\n\n" .
        "استخدم الأزرار بالأسفل:",
        main_keyboard()
    );
    exit;
}

if ($text === '/admin') {
    if (!is_owner($chatId)) { tg_send($chatId, '⛔ هذا الأمر للمالك فقط.'); exit; }
    $totalUsers = db()->query("SELECT COUNT(*) c FROM telegram_users")->fetch()['c'];
    $pendingW = db()->query("SELECT COUNT(*) c FROM telegram_withdraw_requests WHERE status='pending'")->fetch()['c'];
    tg_send($chatId,
        "🎛️ <b>لوحة تحكم البوت</b>\n\n👥 المستخدمون: <b>$totalUsers</b>\n💸 طلبات سحب معلّقة: <b>$pendingW</b>\n\n" .
        "لإدارة الموقع كاملاً (منتجات، طلبات، إعدادات) ادخل من لوحة التحكم على الموقع."
    );
    exit;
}

// التحقق من حالة بانتظار إدخال (محفظة، إجابة كابتشا...)
$state = $tgUser['state'] ? json_decode($tgUser['state'], true) : null;

if ($state && ($state['awaiting'] ?? '') === 'wallet_address') {
    if (mb_strlen($text) < 5) { tg_send($chatId, '❌ عنوان غير صالح، أعد الإرسال:'); exit; }
    db()->prepare("UPDATE telegram_users SET wallet_type=?, wallet_address=? WHERE chat_id=?")
        ->execute([$state['wallet_type'], $text, $chatId]);
    set_state($chatId, null);
    tg_send($chatId, "✅ تم حفظ محفظتك بنجاح.", main_keyboard());
    exit;
}

if ($state && ($state['awaiting'] ?? '') === 'captcha') {
    $day = date('Y-m-d');
    if ($text !== ($state['code'] ?? '')) {
        tg_send($chatId, '❌ رقم خاطئ، حاول مجدداً من زر «🪙 كسب نقاط».');
        set_state($chatId, null);
        exit;
    }
    set_state($chatId, null);
    $reward = (int)setting('captcha_reward', 10);
    add_points($chatId, $reward);
    $st = db()->prepare("SELECT * FROM telegram_captcha_logs WHERE chat_id=? AND day=?");
    $st->execute([$chatId, $day]);
    $log = $st->fetch();
    if ($log) db()->prepare("UPDATE telegram_captcha_logs SET count=count+1 WHERE id=?")->execute([$log['id']]);
    else db()->prepare("INSERT INTO telegram_captcha_logs (chat_id, day, count) VALUES (?,?,1)")->execute([$chatId, $day]);
    tg_send($chatId, "✅ صحيح! +<b>$reward</b> عملة Yassota 🎉");
    exit;
}

switch ($text) {
    case '💰 رصيدي':
        $pts = (int)($tgUser['points'] ?? 0);
        $usd = points_to_usd($pts);
        tg_send($chatId, "💰 <b>رصيدك</b>\n\n🪙 النقاط: <b>$pts</b>\n💵 القيمة: <b>{$usd}$</b>");
        break;

    case '🪙 كسب نقاط':
        $day = date('Y-m-d');
        $st = db()->prepare("SELECT count FROM telegram_captcha_logs WHERE chat_id=? AND day=?");
        $st->execute([$chatId, $day]);
        $done = $st->fetch()['count'] ?? 0;
        $max = (int)setting('captcha_max_per_day', 40);
        if ($done >= $max) { tg_send($chatId, "✅ أكملت {$max}/{$max} كابتشا اليوم. عُد غداً."); break; }
        $code = (string)random_int(1000, 9999);
        set_state($chatId, ['awaiting' => 'captcha', 'code' => $code]);
        tg_send($chatId, "🔢 أدخل هذا الرقم كما هو:\n\n<b>$code</b>\n\n(" . ($done) . "/$max اليوم)");
        break;

    case '📋 المهام':
        $tasks = db()->query("SELECT * FROM tasks WHERE active=1")->fetchAll();
        if (!$tasks) { tg_send($chatId, '📋 لا توجد مهام حالياً.'); break; }
        $day = date('Y-m-d');
        $kb = [];
        $textOut = "📋 <b>المهام اليومية</b>\n\n";
        foreach ($tasks as $t) {
            $st = db()->prepare("SELECT 1 FROM telegram_task_done WHERE chat_id=? AND task_id=? AND day=?");
            $st->execute([$chatId, $t['id'], $day]);
            $done = (bool)$st->fetch();
            $textOut .= ($done ? '✅' : '🔵') . " <b>" . e_($t['title']) . "</b> (+{$t['reward']})\n";
            if (!$done) {
                $kb[] = [
                    ['text' => '🔗 فتح', 'url' => $t['url']],
                    ['text' => "✅ جمعت +{$t['reward']}", 'callback_data' => 'task:' . $t['id']],
                ];
            }
        }
        tg_send($chatId, $textOut, $kb ? ['inline_keyboard' => $kb] : null);
        break;

    case '🛍️ المنتجات':
        $products = db()->query("SELECT * FROM products WHERE status='active' ORDER BY id DESC LIMIT 5")->fetchAll();
        if (!$products) { tg_send($chatId, '🛍️ لا توجد منتجات حالياً.'); break; }
        $textOut = "🛍️ <b>أحدث المنتجات</b>\n\n";
        foreach ($products as $p) {
            $textOut .= ($p['icon'] ?: '📦') . " <b>" . e_($p['name']) . "</b> — {$p['price']}$\n";
        }
        $textOut .= "\n🌐 للشراء سجّل دخولك على الموقع: " . e_(SITE_URL);
        tg_send($chatId, $textOut);
        break;

    case '💳 محفظتي':
        $wt = $tgUser['wallet_type'] ?? null;
        $wa = $tgUser['wallet_address'] ?? null;
        $textOut = $wa ? "💳 محفظتك الحالية:\n" . ($wt === 'usdt' ? '💎 USDT' : '📱 شام كاش') . "\n<code>" . e_($wa) . "</code>"
            : '❌ لم تُضف محفظة بعد.';
        tg_send($chatId, $textOut, ['inline_keyboard' => [
            [['text' => '💎 USDT (TRC20)', 'callback_data' => 'wset:usdt'], ['text' => '📱 شام كاش', 'callback_data' => 'wset:sham']],
        ]]);
        break;

    case '💸 سحب الرصيد':
        $pts = (int)($tgUser['points'] ?? 0);
        $usd = points_to_usd($pts);
        $min = (float)setting('min_withdraw_usd', 25);
        if ($usd < $min) { tg_send($chatId, "❌ رصيدك ({$usd}$) أقل من الحد الأدنى ({$min}$)."); break; }
        if (empty($tgUser['wallet_address'])) { tg_send($chatId, '❌ أضف محفظتك أولاً من «💳 محفظتي».'); break; }
        db()->prepare("INSERT INTO telegram_withdraw_requests (chat_id, amount_points, amount_usd, wallet_type, wallet_address) VALUES (?,?,?,?,?)")
            ->execute([$chatId, $pts, $usd, $tgUser['wallet_type'], $tgUser['wallet_address']]);
        db()->prepare("UPDATE telegram_users SET points = 0 WHERE chat_id = ?")->execute([$chatId]);
        tg_send($chatId, "✅ تم إرسال طلب سحب بقيمة {$usd}$، بانتظار موافقة الإدارة.");
        if (OWNER_ID) tg_send((string)OWNER_ID, "💸 طلب سحب جديد من @{$username} ({$chatId})\nالمبلغ: {$usd}$");
        break;

    case '❓ مساعدة':
        tg_send($chatId,
            "❓ <b>دليل الاستخدام</b>\n\n🪙 كسب نقاط: كابتشا أرقام بسيطة\n📋 المهام: زيارة روابط مقابل نقاط\n" .
            "💳 محفظتي: لإضافة عنوان USDT/شام كاش\n💸 سحب: من " . e_(setting('min_withdraw_usd', 25)) . "$ فأكثر\n\n" .
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
    $chatId = (string)$cb['message']['chat']['id'];
    $data = $cb['data'] ?? '';
    $cbId = $cb['id'];

    if (str_starts_with($data, 'task:')) {
        $tid = (int)substr($data, 5);
        $day = date('Y-m-d');
        $st = db()->prepare("SELECT * FROM tasks WHERE id=? AND active=1");
        $st->execute([$tid]);
        $task = $st->fetch();
        if (!$task) { tg_answer_cb($cbId, '❌ المهمة غير موجودة.', true); return; }
        $st = db()->prepare("SELECT 1 FROM telegram_task_done WHERE chat_id=? AND task_id=? AND day=?");
        $st->execute([$chatId, $tid, $day]);
        if ($st->fetch()) { tg_answer_cb($cbId, '✅ أنجزتها بالفعل اليوم.', true); return; }
        db()->prepare("INSERT INTO telegram_task_done (chat_id, task_id, day) VALUES (?,?,?)")->execute([$chatId, $tid, $day]);
        add_points($chatId, (int)$task['reward']);
        tg_answer_cb($cbId, "✅ +{$task['reward']} عملة!", true);
        return;
    }

    if (str_starts_with($data, 'wset:')) {
        $type = substr($data, 5);
        set_state($chatId, ['awaiting' => 'wallet_address', 'wallet_type' => $type]);
        tg_answer_cb($cbId);
        tg_send($chatId, $type === 'usdt' ? '💎 أرسل عنوان USDT (TRC20):' : '📱 أرسل رقم الشام كاش:');
        return;
    }

    tg_answer_cb($cbId);
}

function e_($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
