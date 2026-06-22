<?php
/**
 * Yassota Store — منصة متجر إلكتروني + نظام أرباح ومحفظة + لوحة إدارة
 * ملف واحد يحتوي كل شيء: PHP + HTML + CSS + JS
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
session_set_cookie_params(60 * 60 * 24 * 7); // أسبوع
session_start();

if (!file_exists(__DIR__ . '/config.php')) {
    die('يرجى إنشاء config.php من config.sample.php أولاً.');
}
require __DIR__ . '/config.php';

/* ======================================================================
   1) DB CONNECTION + AUTO SCHEMA
   ====================================================================== */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        if (DB_DRIVER === 'sqlite') {
            $pdo = new PDO('sqlite:' . __DIR__ . '/database.sqlite');
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

function migrate(): void
{
    $pdo = db();
    $engine = DB_DRIVER === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $id = DB_DRIVER === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $ts = DB_DRIVER === 'sqlite' ? "TEXT DEFAULT (datetime('now'))" : 'DATETIME DEFAULT CURRENT_TIMESTAMP';

    $tables = [
    "CREATE TABLE IF NOT EXISTS users (
        id $id,
        google_id VARCHAR(64) NULL,
        username VARCHAR(60) NULL UNIQUE,
        password_hash VARCHAR(255) NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        name VARCHAR(190) NULL,
        avatar VARCHAR(500) NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'user',
        points INT NOT NULL DEFAULT 0,
        wallet_type VARCHAR(20) NULL,
        wallet_address VARCHAR(190) NULL,
        is_banned TINYINT NOT NULL DEFAULT 0,
        last_login $ts,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS categories (
        id $id,
        name VARCHAR(120) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0
    )$engine",
    "CREATE TABLE IF NOT EXISTS products (
        id $id,
        name VARCHAR(190) NOT NULL,
        icon VARCHAR(20) NULL,
        image VARCHAR(500) NULL,
        category_id INT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        old_price DECIMAL(12,2) NULL,
        description TEXT NULL,
        tag VARCHAR(40) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS orders (
        id $id,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        price DECIMAL(12,2) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        admin_note VARCHAR(255) NULL,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS wallets (
        id $id,
        type VARCHAR(20) NOT NULL,
        label VARCHAR(120) NOT NULL,
        address VARCHAR(190) NOT NULL,
        active TINYINT NOT NULL DEFAULT 1
    )$engine",
    "CREATE TABLE IF NOT EXISTS topup_requests (
        id $id,
        user_id INT NOT NULL,
        wallet_id INT NULL,
        amount DECIMAL(12,2) NOT NULL,
        note VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS withdraw_requests (
        id $id,
        user_id INT NOT NULL,
        amount_points INT NOT NULL,
        amount_usd DECIMAL(12,4) NOT NULL,
        wallet_type VARCHAR(20) NOT NULL,
        wallet_address VARCHAR(190) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS tasks (
        id $id,
        title VARCHAR(190) NOT NULL,
        url VARCHAR(500) NOT NULL,
        seconds INT NOT NULL DEFAULT 15,
        reward INT NOT NULL DEFAULT 50,
        active TINYINT NOT NULL DEFAULT 1
    )$engine",
    "CREATE TABLE IF NOT EXISTS task_completions (
        id $id,
        user_id INT NOT NULL,
        task_id INT NOT NULL,
        day VARCHAR(10) NOT NULL
    )$engine",
    "CREATE TABLE IF NOT EXISTS earn_logs (
        id $id,
        user_id INT NOT NULL,
        amount INT NOT NULL,
        source VARCHAR(30) NOT NULL,
        description VARCHAR(255) NULL,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(80) PRIMARY KEY,
        v TEXT NULL
    )$engine",
    "CREATE TABLE IF NOT EXISTS banners (
        id $id,
        image VARCHAR(500) NOT NULL,
        link VARCHAR(500) NULL,
        active TINYINT NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0
    )$engine",
    "CREATE TABLE IF NOT EXISTS pages (
        slug VARCHAR(40) PRIMARY KEY,
        content TEXT NULL
    )$engine",
    "CREATE TABLE IF NOT EXISTS telegram_users (
        chat_id VARCHAR(40) PRIMARY KEY,
        user_id INT NULL,
        username VARCHAR(120) NULL,
        joined_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS captcha_logs (
        id $id,
        user_id INT NOT NULL,
        day VARCHAR(10) NOT NULL,
        count INT NOT NULL DEFAULT 0
    )$engine",
    ];

    foreach ($tables as $sql) $pdo->exec($sql);

    // أعمدة username/password_hash قد تكون مفقودة على تنصيب قديم — نضيفها بأمان
    $existingCols = [];
    try {
        $colSql = DB_DRIVER === 'sqlite' ? "PRAGMA table_info(users)" : "SHOW COLUMNS FROM users";
        foreach ($pdo->query($colSql)->fetchAll() as $row) {
            $existingCols[] = DB_DRIVER === 'sqlite' ? $row['name'] : $row['Field'];
        }
    } catch (Throwable $e) {}
    foreach (['username' => 'VARCHAR(60) NULL', 'password_hash' => 'VARCHAR(255) NULL'] as $col => $def) {
        if (!in_array($col, $existingCols, true)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN $col $def"); } catch (Throwable $e) {}
        }
    }

    // seed default settings
    $defaults = [
        'site_name' => 'Yassota Store',
        'site_description' => 'منصة Yassota للتسوق وكسب العملات الرقمية مجاناً',
        'site_keywords' => 'متجر,تسوق,أرباح,عملات,Yassota',
        'logo_url' => '',
        'banner_title' => 'مرحباً بك في Yassota',
        'banner_subtitle' => 'تسوّق، اربح نقاط، واسحبها أموالاً حقيقية',
        'points_rate' => '0.001',      // 1 نقطة = كم دولار
        'min_withdraw_usd' => '25',
        'captcha_reward' => '10',
        'captcha_max_per_day' => '40',
        'task_max_per_day' => '10',
        'profit_split_admin' => '95',
        'profit_split_user' => '5',
        'policy_version' => '1',
        'welcome_bonus_points' => '200',
    ];
    $stmt = $pdo->prepare("INSERT INTO settings (k, v) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM settings WHERE k = ?)");
    foreach ($defaults as $k => $v) $stmt->execute([$k, $v, $k]);

    foreach (['privacy' => 'سياسة الخصوصية الخاصة بمنصة Yassota...', 'terms' => 'شروط الاستخدام الخاصة بمنصة Yassota...'] as $slug => $content) {
        $st = $pdo->prepare("INSERT INTO pages (slug, content) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM pages WHERE slug = ?)");
        $st->execute([$slug, $content, $slug]);
    }
}
migrate();

/* ======================================================================
   2) HELPERS
   ====================================================================== */
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

function flash(?string $msg = null, string $type = 'success')
{
    if ($msg !== null) { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; return; }
    if (!empty($_SESSION['flash'])) { $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }
    return null;
}

function points_to_usd($pts): float { return round($pts * (float)setting('points_rate', 0.001), 4); }

function add_points(int $uid, int $amount, string $source, string $desc = ''): void
{
    db()->prepare("UPDATE users SET points = points + ? WHERE id = ?")->execute([$amount, $uid]);
    db()->prepare("INSERT INTO earn_logs (user_id, amount, source, description) VALUES (?,?,?,?)")
        ->execute([$uid, $amount, $source, $desc]);
}

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

/* ---- Telegram helpers ---- */
function tg_send(string $chatId, string $text, array $extra = []): void
{
    if (!BOT_TOKEN) return;
    $params = array_merge(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'], $extra);
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
function tg_broadcast_product(array $product): void
{
    if (!BOT_TOKEN) return;
    $chats = db()->query("SELECT chat_id FROM telegram_users")->fetchAll();
    $text = "🆕 <b>منتج جديد!</b>\n\n"
        . ($product['icon'] ? $product['icon'] . ' ' : '') . "<b>" . e($product['name']) . "</b>\n"
        . "💵 السعر: <b>{$product['price']}$</b>\n"
        . ($product['description'] ? e(mb_substr($product['description'], 0, 200)) . "\n" : "")
        . "\n🔗 " . SITE_URL;
    foreach ($chats as $c) tg_send($c['chat_id'], $text);
    if (OWNER_ID) tg_send(OWNER_ID, "📢 تم بث المنتج لعدد " . count($chats) . " محادثة.");
}

/* ======================================================================
   3) GOOGLE OAUTH + TRADITIONAL AUTH
   ====================================================================== */
function google_redirect_uri(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return "$scheme://$host$path?action=google_callback";
}

function gen_username(string $base): string
{
    $base = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower(explode('@', $base)[0]));
    if ($base === '') $base = 'user';
    $base = mb_substr($base, 0, 20);
    $username = $base;
    $i = 0;
    while (true) {
        $st = db()->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$username]);
        if (!$st->fetch()) return $username;
        $i++;
        $username = $base . $i;
    }
}

function award_welcome_bonus(int $uid): void
{
    $bonus = (int)setting('welcome_bonus_points', 200);
    if ($bonus > 0) add_points($uid, $bonus, 'welcome', 'هدية الترحيب بالتسجيل');
}

function google_login_url(): string
{
    if (!GOOGLE_CLIENT_ID) return '#';
    $params = [
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'access_type' => 'online',
        'prompt' => 'select_account',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function google_handle_callback(string $code): void
{
    $tokenRes = json_decode(file_get_contents('https://oauth2.googleapis.com/token?' . http_build_query([
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => google_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]), false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n"]])), true);

    if (empty($tokenRes['access_token'])) { flash('فشل تسجيل الدخول بجوجل.', 'error'); redirect('?'); }

    $info = json_decode(file_get_contents('https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . $tokenRes['access_token']), true);
    if (empty($info['email'])) { flash('تعذّر جلب بيانات حسابك من جوجل.', 'error'); redirect('?'); }

    $email = $info['email'];
    $name = $info['name'] ?? $email;
    $avatar = $info['picture'] ?? '';
    $gid = $info['sub'] ?? '';

    $st = db()->prepare("SELECT * FROM users WHERE email = ?");
    $st->execute([$email]);
    $u = $st->fetch();
    $role = ($email === ADMIN_EMAIL) ? 'admin' : 'user';
    $isNew = !$u;

    if ($u) {
        db()->prepare("UPDATE users SET name=?, avatar=?, google_id=?, role=?, last_login=" . (DB_DRIVER === 'sqlite' ? "datetime('now')" : 'NOW()') . " WHERE id=?")
            ->execute([$name, $avatar, $gid, $role, $u['id']]);
        $uid = $u['id'];
    } else {
        $username = gen_username($email);
        db()->prepare("INSERT INTO users (google_id, email, name, avatar, role, username) VALUES (?,?,?,?,?,?)")
            ->execute([$gid, $email, $name, $avatar, $role, $username]);
        $uid = db()->lastInsertId();
        award_welcome_bonus((int)$uid);
    }
    $_SESSION['uid'] = $uid;
    redirect($isNew ? '?page=welcome' : ('?' . ($role === 'admin' ? 'page=admin' : '')));
}

function handle_register(): void
{
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (!$username || !$email || !$password) { flash('يرجى تعبئة جميع الحقول.', 'error'); redirect('?'); }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('البريد الإلكتروني غير صالح.', 'error'); redirect('?'); }
    if (mb_strlen($password) < 6) { flash('كلمة المرور يجب أن تكون 6 أحرف على الأقل.', 'error'); redirect('?'); }
    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) { flash('اسم المستخدم يجب أن يكون 3-20 حرف/رقم إنجليزي بدون مسافات.', 'error'); redirect('?'); }

    $st = db()->prepare("SELECT id FROM users WHERE email = ?");
    $st->execute([$email]);
    if ($st->fetch()) { flash('هذا البريد مسجل مسبقاً، سجّل الدخول.', 'error'); redirect('?'); }

    $st = db()->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$username]);
    if ($st->fetch()) { flash('اسم المستخدم محجوز، اختر اسماً آخر.', 'error'); redirect('?'); }

    $role = ($email === ADMIN_EMAIL) ? 'admin' : 'user';
    db()->prepare("INSERT INTO users (username, email, password_hash, name, role) VALUES (?,?,?,?,?)")
        ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $username, $role]);
    $uid = db()->lastInsertId();
    award_welcome_bonus((int)$uid);
    $_SESSION['uid'] = $uid;
    redirect('?page=welcome');
}

function handle_login(): void
{
    csrf_check();
    $identity = trim($_POST['identity'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if (!$identity || !$password) { flash('يرجى تعبئة جميع الحقول.', 'error'); redirect('?'); }

    $st = db()->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
    $st->execute([$identity, $identity]);
    $u = $st->fetch();

    if (!$u || !$u['password_hash'] || !password_verify($password, $u['password_hash'])) {
        flash('بيانات الدخول غير صحيحة.', 'error'); redirect('?');
    }
    if ($u['is_banned']) { flash('هذا الحساب محظور.', 'error'); redirect('?'); }

    $role = ($u['email'] === ADMIN_EMAIL) ? 'admin' : $u['role'];
    db()->prepare("UPDATE users SET role=?, last_login=" . (DB_DRIVER === 'sqlite' ? "datetime('now')" : 'NOW()') . " WHERE id=?")
        ->execute([$role, $u['id']]);
    $_SESSION['uid'] = $u['id'];
    redirect('?' . ($role === 'admin' ? 'page=admin' : ''));
}

/* ======================================================================
   4) ROUTING
   ====================================================================== */
$action = $_GET['action'] ?? '';
$page = $_GET['page'] ?? 'home';

// ملاحظة: استقبال أوامر بوت تيليجرام الكاملة (القوائم/الأرباح/المحفظة) يتم في telegram_bot.php
// هذا الملف فقط يستخدم tg_broadcast_product() للبث عند نشر منتج جديد.

if ($action === 'google_callback') {
    google_handle_callback($_GET['code'] ?? '');
    exit;
}

if ($action === 'register') { handle_register(); exit; }
if ($action === 'login') { handle_login(); exit; }

if ($action === 'logout') { logout(); redirect('?'); }

if ($page === 'admin' && !is_admin()) { http_response_code(403); die('🚫 ممنوع — هذه الصفحة للإدارة فقط. سجّل الدخول ببريد الأدمن.'); }

if ($action === 'accept_policy') {
    setcookie('policy_accepted', setting('policy_version', '1'), time() + 60 * 60 * 24 * 365, '/');
    echo 'ok'; exit;
}

/* ---- JSON API actions (AJAX) ---- */
if ($action && str_starts_with($action, 'api_')) {
    header('Content-Type: application/json; charset=utf-8');
    $u = current_user();

    if (!$u && !in_array($action, ['api_ping'])) {
        echo json_encode(['ok' => false, 'msg' => 'يجب تسجيل الدخول أولاً.']); exit;
    }

    switch ($action) {
        case 'api_buy_product':
            csrf_check();
            $pid = (int)($_POST['product_id'] ?? 0);
            $st = db()->prepare("SELECT * FROM products WHERE id=? AND status='active'");
            $st->execute([$pid]);
            $p = $st->fetch();
            if (!$p) { echo json_encode(['ok' => false, 'msg' => 'المنتج غير متوفر.']); exit; }
            if ($u['points'] < $p['price'] / max(points_to_usd(1), 0.0000001)) {
                // fallback simple check using usd balance equivalent
            }
            $usd_balance = points_to_usd($u['points']);
            if ($usd_balance < $p['price']) {
                echo json_encode(['ok' => false, 'msg' => 'رصيدك غير كافٍ لإتمام الشراء.']); exit;
            }
            db()->prepare("INSERT INTO orders (user_id, product_id, price) VALUES (?,?,?)")
                ->execute([$u['id'], $pid, $p['price']]);
            echo json_encode(['ok' => true, 'msg' => 'تم إرسال طلب الشراء، بانتظار موافقة الإدارة.']);
            exit;

        case 'api_solve_captcha':
            csrf_check();
            $answer = trim($_POST['answer'] ?? '');
            $expected = $_SESSION['captcha_code'] ?? null;
            $day = date('Y-m-d');
            $st = db()->prepare("SELECT * FROM captcha_logs WHERE user_id=? AND day=?");
            $st->execute([$u['id'], $day]);
            $log = $st->fetch();
            $count = $log['count'] ?? 0;
            $max = (int)setting('captcha_max_per_day', 40);
            if ($count >= $max) { echo json_encode(['ok' => false, 'msg' => 'وصلت للحد اليومي من الكابتشا.']); exit; }
            if (!$expected || $answer !== $expected) { echo json_encode(['ok' => false, 'msg' => 'الرقم غير صحيح، حاول مجدداً.']); exit; }
            unset($_SESSION['captcha_code']);
            $reward = (int)setting('captcha_reward', 10);
            add_points($u['id'], $reward, 'captcha', 'إنجاز كابتشا');
            if ($log) db()->prepare("UPDATE captcha_logs SET count=count+1 WHERE id=?")->execute([$log['id']]);
            else db()->prepare("INSERT INTO captcha_logs (user_id, day, count) VALUES (?,?,1)")->execute([$u['id'], $day]);
            echo json_encode(['ok' => true, 'msg' => "تم! +{$reward} عملة Yassota", 'reward' => $reward, 'remaining' => $max - $count - 1]);
            exit;

        case 'api_new_captcha':
            $code = (string)random_int(1000, 9999);
            $_SESSION['captcha_code'] = $code;
            echo json_encode(['ok' => true, 'code' => $code]);
            exit;

        case 'api_complete_task':
            csrf_check();
            $tid = (int)($_POST['task_id'] ?? 0);
            $day = date('Y-m-d');
            $st = db()->prepare("SELECT * FROM tasks WHERE id=? AND active=1");
            $st->execute([$tid]);
            $task = $st->fetch();
            if (!$task) { echo json_encode(['ok' => false, 'msg' => 'المهمة غير موجودة.']); exit; }
            $st = db()->prepare("SELECT * FROM task_completions WHERE user_id=? AND task_id=? AND day=?");
            $st->execute([$u['id'], $tid, $day]);
            if ($st->fetch()) { echo json_encode(['ok' => false, 'msg' => 'أنجزت هذه المهمة اليوم بالفعل.']); exit; }
            $st = db()->prepare("SELECT COUNT(*) c FROM task_completions WHERE user_id=? AND day=?");
            $st->execute([$u['id'], $day]);
            if ($st->fetch()['c'] >= (int)setting('task_max_per_day', 10)) {
                echo json_encode(['ok' => false, 'msg' => 'وصلت للحد اليومي من المهام.']); exit;
            }
            db()->prepare("INSERT INTO task_completions (user_id, task_id, day) VALUES (?,?,?)")->execute([$u['id'], $tid, $day]);
            add_points($u['id'], (int)$task['reward'], 'task', 'مهمة: ' . $task['title']);
            echo json_encode(['ok' => true, 'msg' => "+{$task['reward']} عملة Yassota"]);
            exit;

        case 'api_request_topup':
            csrf_check();
            $amount = (float)($_POST['amount'] ?? 0);
            $wid = (int)($_POST['wallet_id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($amount <= 0) { echo json_encode(['ok' => false, 'msg' => 'أدخل مبلغاً صحيحاً.']); exit; }
            db()->prepare("INSERT INTO topup_requests (user_id, wallet_id, amount, note) VALUES (?,?,?,?)")
                ->execute([$u['id'], $wid, $amount, $note]);
            echo json_encode(['ok' => true, 'msg' => 'تم إرسال طلب الشحن، بانتظار مراجعة الإدارة.']);
            exit;

        case 'api_save_wallet':
            csrf_check();
            $type = $_POST['type'] ?? '';
            $addr = trim($_POST['address'] ?? '');
            db()->prepare("UPDATE users SET wallet_type=?, wallet_address=? WHERE id=?")->execute([$type, $addr, $u['id']]);
            echo json_encode(['ok' => true, 'msg' => 'تم حفظ المحفظة.']);
            exit;

        case 'api_request_withdraw':
            csrf_check();
            $min = (float)setting('min_withdraw_usd', 25);
            $usd = points_to_usd($u['points']);
            if ($usd < $min) { echo json_encode(['ok' => false, 'msg' => "الحد الأدنى للسحب {$min}$"]); exit; }
            if (!$u['wallet_address']) { echo json_encode(['ok' => false, 'msg' => 'أضف محفظتك أولاً.']); exit; }
            db()->prepare("INSERT INTO withdraw_requests (user_id, amount_points, amount_usd, wallet_type, wallet_address) VALUES (?,?,?,?,?)")
                ->execute([$u['id'], $u['points'], $usd, $u['wallet_type'], $u['wallet_address']]);
            db()->prepare("UPDATE users SET points = 0 WHERE id = ?")->execute([$u['id']]);
            echo json_encode(['ok' => true, 'msg' => 'تم إرسال طلب السحب.']);
            exit;

        default:
            echo json_encode(['ok' => false, 'msg' => 'غير معروف.']);
            exit;
    }
}

/* ---- Admin POST actions ---- */
if ($action && str_starts_with($action, 'admin_')) {
    require_admin();
    csrf_check();

    switch ($action) {
        case 'admin_save_product':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $icon = trim($_POST['icon'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $old_price = $_POST['old_price'] !== '' ? (float)$_POST['old_price'] : null;
            $cat = (int)($_POST['category_id'] ?? 0) ?: null;
            $desc = trim($_POST['description'] ?? '');
            $tag = trim($_POST['tag'] ?? '');
            $image = trim($_POST['image'] ?? '');
            if ($id) {
                db()->prepare("UPDATE products SET name=?, icon=?, price=?, old_price=?, category_id=?, description=?, tag=?, image=? WHERE id=?")
                    ->execute([$name, $icon, $price, $old_price, $cat, $desc, $tag, $image, $id]);
            } else {
                db()->prepare("INSERT INTO products (name, icon, price, old_price, category_id, description, tag, image) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$name, $icon, $price, $old_price, $cat, $desc, $tag, $image]);
                $id = db()->lastInsertId();
                $st = db()->prepare("SELECT * FROM products WHERE id=?"); $st->execute([$id]);
                tg_broadcast_product($st->fetch());
            }
            flash('تم حفظ المنتج بنجاح.');
            redirect('?page=admin&tab=products');

        case 'admin_delete_product':
            db()->prepare("DELETE FROM products WHERE id=?")->execute([(int)$_POST['id']]);
            flash('تم حذف المنتج.');
            redirect('?page=admin&tab=products');

        case 'admin_save_category':
            $name = trim($_POST['name'] ?? '');
            if ($name) db()->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$name]);
            redirect('?page=admin&tab=products');

        case 'admin_order_decision':
            $oid = (int)$_POST['id']; $dec = $_POST['decision'] === 'approve' ? 'approved' : 'rejected';
            db()->prepare("UPDATE orders SET status=?, admin_note=? WHERE id=?")->execute([$dec, $_POST['note'] ?? '', $oid]);
            flash('تم تحديث حالة الطلب.');
            redirect('?page=admin&tab=orders');

        case 'admin_topup_decision':
            $tid = (int)$_POST['id']; $dec = $_POST['decision'];
            $st = db()->prepare("SELECT * FROM topup_requests WHERE id=?"); $st->execute([$tid]); $tr = $st->fetch();
            if ($tr && $dec === 'approve' && $tr['status'] === 'pending') {
                $pts = (int)round($tr['amount'] / max((float)setting('points_rate', 0.001), 0.0000001));
                add_points($tr['user_id'], $pts, 'topup', 'شحن رصيد معتمد');
            }
            db()->prepare("UPDATE topup_requests SET status=? WHERE id=?")->execute([$dec === 'approve' ? 'approved' : 'rejected', $tid]);
            flash('تم تحديث طلب الشحن.');
            redirect('?page=admin&tab=topups');

        case 'admin_withdraw_decision':
            $wid = (int)$_POST['id']; $dec = $_POST['decision'];
            $st = db()->prepare("SELECT * FROM withdraw_requests WHERE id=?"); $st->execute([$wid]); $wr = $st->fetch();
            if ($wr && $dec === 'reject' && $wr['status'] === 'pending') {
                add_points($wr['user_id'], $wr['amount_points'], 'refund', 'إرجاع بعد رفض السحب');
            }
            db()->prepare("UPDATE withdraw_requests SET status=? WHERE id=?")->execute([$dec === 'approve' ? 'approved' : 'rejected', $wid]);
            flash('تم تحديث طلب السحب.');
            redirect('?page=admin&tab=withdraws');

        case 'admin_save_wallet':
            db()->prepare("INSERT INTO wallets (type, label, address) VALUES (?,?,?)")
                ->execute([$_POST['type'], $_POST['label'], $_POST['address']]);
            flash('تمت إضافة المحفظة.');
            redirect('?page=admin&tab=wallets');

        case 'admin_toggle_wallet':
            db()->prepare("UPDATE wallets SET active = 1 - active WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=wallets');

        case 'admin_delete_wallet':
            db()->prepare("DELETE FROM wallets WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=wallets');

        case 'admin_save_task':
            db()->prepare("INSERT INTO tasks (title, url, seconds, reward) VALUES (?,?,?,?)")
                ->execute([$_POST['title'], $_POST['url'], (int)$_POST['seconds'], (int)$_POST['reward']]);
            flash('تمت إضافة المهمة.');
            redirect('?page=admin&tab=tasks');

        case 'admin_toggle_task':
            db()->prepare("UPDATE tasks SET active = 1 - active WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=tasks');

        case 'admin_save_banner':
            db()->prepare("INSERT INTO banners (image, link) VALUES (?,?)")->execute([$_POST['image'], $_POST['link']]);
            redirect('?page=admin&tab=banners');

        case 'admin_delete_banner':
            db()->prepare("DELETE FROM banners WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=banners');

        case 'admin_save_settings':
            foreach ($_POST as $k => $v) {
                if ($k === 'action' || $k === 'csrf') continue;
                set_setting($k, $v);
            }
            flash('تم حفظ الإعدادات.');
            redirect('?page=admin&tab=settings');

        case 'admin_save_page':
            db()->prepare(DB_DRIVER === 'sqlite'
                ? "INSERT INTO pages (slug, content) VALUES (?,?) ON CONFLICT(slug) DO UPDATE SET content=?"
                : "INSERT INTO pages (slug, content) VALUES (?,?) ON DUPLICATE KEY UPDATE content=?")
                ->execute([$_POST['slug'], $_POST['content'], $_POST['content']]);
            flash('تم حفظ الصفحة.');
            redirect('?page=admin&tab=pages');

        case 'admin_user_action':
            $uid = (int)$_POST['id'];
            if ($_POST['op'] === 'ban') db()->prepare("UPDATE users SET is_banned=1 WHERE id=?")->execute([$uid]);
            if ($_POST['op'] === 'unban') db()->prepare("UPDATE users SET is_banned=0 WHERE id=?")->execute([$uid]);
            if ($_POST['op'] === 'addpoints') add_points($uid, (int)$_POST['points'], 'admin', 'إضافة يدوية من الإدارة');
            redirect('?page=admin&tab=users');

        default:
            die('إجراء غير معروف.');
    }
}

/* ======================================================================
   5) DATA FOR VIEWS
   ====================================================================== */
$user = current_user();
$siteName = setting('site_name');
$logo = setting('logo_url');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title><?= e($siteName) ?> — <?= e(setting('banner_subtitle')) ?></title>
<meta name="description" content="<?= e(setting('site_description')) ?>">
<meta name="keywords" content="<?= e(setting('site_keywords')) ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta property="og:title" content="<?= e($siteName) ?>">
<meta property="og:description" content="<?= e(setting('site_description')) ?>">
<?php if ($logo): ?><meta property="og:image" content="<?= e($logo) ?>"><link rel="icon" href="<?= e($logo) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e(SITE_URL ?: '') ?>">
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Organization","name":"<?= e($siteName) ?>","url":"<?= e(SITE_URL) ?>"<?= $logo ? ',"logo":"' . e($logo) . '"' : '' ?>}
</script>
<style>
:root{--bg:#0f1320;--bg2:#161b2e;--card:#1b2138;--accent:#6c5ce7;--accent2:#00d2a0;--text:#eef0f7;--muted:#8a90ab;--danger:#ff5c5c;--radius:16px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Tahoma,Arial,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
a{color:inherit;text-decoration:none}
#preloader{position:fixed;inset:0;background:var(--bg);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;transition:opacity .4s}
#preloader img{width:64px;height:64px;border-radius:50%}
.spinner{width:46px;height:46px;border:4px solid #2a2f49;border-top-color:var(--accent);border-radius:50%;animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.topbar{position:sticky;top:0;z-index:50;display:flex;align-items:center;gap:12px;padding:12px 18px;background:rgba(20,24,40,.85);backdrop-filter:blur(10px);border-bottom:1px solid #262c47}
.burger{cursor:pointer;font-size:22px;background:none;border:none;color:var(--text)}
.brand{display:flex;align-items:center;gap:8px;font-weight:700;font-size:18px}
.brand img{width:30px;height:30px;border-radius:8px}
.topbar .grow{flex:1}
.btn{padding:9px 16px;border-radius:10px;border:none;cursor:pointer;font-weight:600;font-size:14px;transition:.2s}
.btn-primary{background:linear-gradient(135deg,var(--accent),#a08bff);color:#fff}
.btn-primary:hover{filter:brightness(1.1)}
.btn-ghost{background:#232a45;color:var(--text)}
.btn-success{background:var(--accent2);color:#06251c}
.btn-danger{background:var(--danger);color:#250505}
.user-chip{display:flex;align-items:center;gap:8px;background:#232a45;padding:6px 10px;border-radius:30px}
.user-chip img{width:26px;height:26px;border-radius:50%}
.sidebar{position:fixed;top:0;right:-300px;width:280px;height:100%;background:var(--bg2);z-index:60;transition:right .3s;overflow-y:auto;box-shadow:-10px 0 30px rgba(0,0,0,.3)}
.sidebar.open{right:0}
.sidebar .sb-head{padding:18px;border-bottom:1px solid #262c47;display:flex;justify-content:space-between;align-items:center}
.sidebar nav a{display:flex;align-items:center;gap:10px;padding:14px 18px;color:var(--text);border-bottom:1px solid #1d2238;font-size:15px}
.sidebar nav a:hover{background:#202746}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:55;display:none}
.overlay.show{display:block}
.banner{margin:18px;border-radius:var(--radius);background:linear-gradient(135deg,#3d2c8d,#6c5ce7 60%,#00d2a0);padding:42px 28px;position:relative;overflow:hidden}
.banner h1{font-size:28px;margin-bottom:8px}
.banner p{color:#e6e6ff;opacity:.9}
.section-title{margin:26px 18px 10px;font-size:19px;display:flex;align-items:center;gap:8px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px;padding:0 18px 30px}
.card{background:var(--card);border-radius:var(--radius);padding:16px;position:relative;border:1px solid #232a45;transition:.2s}
.card:hover{transform:translateY(-3px);border-color:var(--accent)}
.card .tag{position:absolute;top:10px;left:10px;background:var(--accent2);color:#06251c;font-size:11px;padding:3px 8px;border-radius:8px;font-weight:700}
.card .icon{font-size:38px;margin-bottom:8px}
.card img.pimg{width:100%;height:120px;object-fit:cover;border-radius:10px;margin-bottom:8px;background:#11152200;lazyload}
.card h3{font-size:15px;margin-bottom:6px;min-height:38px}
.card .price{font-size:17px;font-weight:700;color:var(--accent2)}
.card .old{color:var(--muted);text-decoration:line-through;font-size:13px;margin-right:6px}
.card .desc{font-size:12px;color:var(--muted);margin:6px 0;max-height:36px;overflow:hidden}
.card .buy{width:100%;margin-top:8px}
.empty{padding:60px 20px;text-align:center;color:var(--muted)}
.bottom-nav{position:fixed;bottom:0;left:0;right:0;display:flex;background:#141829;border-top:1px solid #262c47;z-index:40}
.bottom-nav a{flex:1;text-align:center;padding:10px 4px;font-size:11px;color:var(--muted)}
.bottom-nav a.active{color:var(--accent2)}
.bottom-nav .bi{font-size:18px;display:block;margin-bottom:2px}
.container{max-width:1000px;margin:0 auto;padding-bottom:80px}
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;display:flex;align-items:center;justify-content:center;padding:16px}
.modal{background:var(--card);border-radius:var(--radius);padding:22px;max-width:420px;width:100%;max-height:85vh;overflow:auto}
.modal h2{margin-bottom:12px}
.modal input,.modal textarea,.modal select{width:100%;padding:10px;border-radius:10px;border:1px solid #2a3050;background:#11152a;color:var(--text);margin-bottom:10px}
.toast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:#232a45;padding:12px 20px;border-radius:30px;z-index:300;display:none;font-size:14px}
.policy-modal{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:500;display:flex;align-items:center;justify-content:center;padding:16px}
.policy-box{background:var(--card);border-radius:var(--radius);padding:24px;max-width:480px}
.flash{margin:14px 18px;padding:12px 16px;border-radius:10px;background:#1d3b2e;border:1px solid var(--accent2)}
.flash.error{background:#3b1d1d;border-color:var(--danger)}
table{width:100%;border-collapse:collapse;font-size:13px}
table th,table td{padding:8px;border-bottom:1px solid #232a45;text-align:right}
.admin-tabs{display:flex;flex-wrap:wrap;gap:8px;padding:14px 18px}
.admin-tabs a{padding:8px 14px;border-radius:10px;background:#232a45;font-size:13px}
.admin-tabs a.active{background:var(--accent)}
.admin-box{background:var(--card);margin:0 18px 20px;border-radius:var(--radius);padding:18px;overflow-x:auto}
.formrow{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:12px}
.badge{padding:2px 8px;border-radius:8px;font-size:11px}
.badge.pending{background:#5a4a1c}
.badge.approved{background:#1d3b2e}
.badge.rejected{background:#3b1d1d}
footer{text-align:center;color:var(--muted);padding:30px 10px;font-size:12px}
</style>
</head>
<body>
<div id="preloader">
  <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="logo"><?php endif; ?>
  <div class="spinner"></div>
  <div style="color:var(--muted);font-size:13px">جاري التحميل...</div>
</div>

<div class="topbar">
  <button class="burger" onclick="toggleSidebar()">☰</button>
  <a href="?" class="brand"><?php if ($logo): ?><img src="<?= e($logo) ?>"><?php endif; ?> <?= e($siteName) ?></a>
  <div class="grow"></div>
  <?php if ($user): ?>
    <div class="user-chip">
      <?php if ($user['avatar']): ?><img src="<?= e($user['avatar']) ?>"><?php endif; ?>
      <span><?= e($user['name']) ?></span>
    </div>
    <a href="?action=logout" class="btn btn-ghost">خروج</a>
  <?php else: ?>
    <button class="btn btn-primary" onclick="openAuthModal()">تسجيل الدخول</button>
  <?php endif; ?>
</div>

<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>
<div class="sidebar" id="sidebar">
  <div class="sb-head"><strong><?= e($siteName) ?></strong><button class="burger" onclick="toggleSidebar()">✕</button></div>
  <nav>
    <a href="?">🏠 الرئيسية</a>
    <a href="?page=earn">🪙 اكسب عملات (كابتشا)</a>
    <a href="?page=tasks">📋 المهام اليومية</a>
    <a href="?page=wallet">💳 محفظتي</a>
    <a href="?page=orders">📦 طلباتي</a>
    <a href="?page=privacy">🔒 سياسة الخصوصية</a>
    <a href="?page=terms">📜 شروط الاستخدام</a>
    <?php if (is_admin()): ?><a href="?page=admin">🎛️ لوحة الإدارة</a><?php endif; ?>
  </nav>
</div>

<?php if (!$user): ?>
<div class="modal-bg" id="authModal" style="display:none">
  <div class="modal">
    <div style="display:flex;gap:10px;margin-bottom:14px">
      <button type="button" class="btn btn-ghost" style="flex:1" id="tabLoginBtn" onclick="switchAuthTab('login')">تسجيل الدخول</button>
      <button type="button" class="btn btn-ghost" style="flex:1" id="tabRegisterBtn" onclick="switchAuthTab('register')">حساب جديد</button>
    </div>

    <form id="loginForm" method="post" action="?action=login">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <h2>تسجيل الدخول</h2>
      <input type="text" name="identity" placeholder="البريد الإلكتروني أو اسم المستخدم" required>
      <input type="password" name="password" placeholder="كلمة المرور" required>
      <button type="submit" class="btn btn-primary" style="width:100%">دخول</button>
    </form>

    <form id="registerForm" method="post" action="?action=register" style="display:none">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <h2>حساب جديد</h2>
      <input type="text" name="username" placeholder="اسم المستخدم (إنجليزي بدون مسافات)" required>
      <input type="email" name="email" placeholder="البريد الإلكتروني" required>
      <input type="password" name="password" placeholder="كلمة المرور (6 أحرف فأكثر)" required>
      <button type="submit" class="btn btn-success" style="width:100%">إنشاء حساب 🎁</button>
    </form>

    <?php if (GOOGLE_CLIENT_ID): ?>
      <div style="text-align:center;margin:14px 0;color:var(--muted)">— أو —</div>
      <a href="<?= e(google_login_url()) ?>" class="btn btn-ghost" style="display:block;text-align:center">تسجيل الدخول بجوجل</a>
    <?php endif; ?>

    <button type="button" class="btn btn-ghost" style="width:100%;margin-top:10px" onclick="closeAuthModal()">إغلاق</button>
  </div>
</div>
<?php endif; ?>

<?php $f = flash(); if ($f): ?>
  <div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endif; ?>

<div class="container">
<?php
/* ======================================================================
   6) PAGE VIEWS
   ====================================================================== */
switch ($page) {

case 'home':
    $banners = db()->query("SELECT * FROM banners WHERE active=1 ORDER BY sort_order")->fetchAll();
    $products = db()->query("SELECT * FROM products WHERE status='active' ORDER BY id DESC")->fetchAll();
    ?>
    <div class="banner">
      <h1><?= e(setting('banner_title')) ?></h1>
      <p><?= e(setting('banner_subtitle')) ?></p>
    </div>
    <?php foreach ($banners as $b): ?>
      <a href="<?= e($b['link'] ?: '#') ?>"><img src="<?= e($b['image']) ?>" loading="lazy" style="width:100%;border-radius:14px;margin:0 18px 14px;max-width:calc(100% - 36px)"></a>
    <?php endforeach; ?>

    <div class="section-title">🛍️ أحدث المنتجات</div>
    <?php if (!$products): ?>
      <div class="empty">لا توجد منتجات حالياً، تابعنا قريباً 🚀</div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($products as $p): ?>
          <div class="card">
            <?php if ($p['tag']): ?><span class="tag"><?= e($p['tag']) ?></span><?php endif; ?>
            <?php if ($p['image']): ?>
              <img class="pimg" loading="lazy" src="<?= e($p['image']) ?>" alt="<?= e($p['name']) ?>">
            <?php elseif ($p['icon']): ?>
              <div class="icon"><?= e($p['icon']) ?></div>
            <?php endif; ?>
            <h3><?= e($p['name']) ?></h3>
            <?php if ($p['description']): ?><div class="desc"><?= e(mb_substr($p['description'], 0, 60)) ?></div><?php endif; ?>
            <div>
              <span class="price"><?= e($p['price']) ?>$</span>
              <?php if ($p['old_price']): ?><span class="old"><?= e($p['old_price']) ?>$</span><?php endif; ?>
            </div>
            <button class="btn btn-primary buy" onclick="buyProduct(<?= (int)$p['id'] ?>)">🛒 طلب شراء</button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php
    break;

case 'earn':
    if (!$user) { echo '<div class="empty">سجّل الدخول لتبدأ بكسب العملات.<br><button class="btn btn-primary" style="margin-top:14px" onclick="openAuthModal()">تسجيل الدخول</button></div>'; break; }
    $day = date('Y-m-d');
    $st = db()->prepare("SELECT count FROM captcha_logs WHERE user_id=? AND day=?");
    $st->execute([$user['id'], $day]);
    $done = $st->fetch()['count'] ?? 0;
    $max = (int)setting('captcha_max_per_day', 40);
    ?>
    <div class="admin-box" style="margin-top:18px">
      <h2>🪙 اكسب عملات Yassota</h2>
      <p style="color:var(--muted);margin:10px 0">أدخل الرقم الظاهر بالأسفل بشكل صحيح لتحصل على <?= e(setting('captcha_reward')) ?> عملة. (<?= $done ?>/<?= $max ?> اليوم)</p>
      <div id="captchaBox" style="font-size:32px;font-weight:800;letter-spacing:8px;background:#11152a;border-radius:12px;padding:18px;text-align:center;margin:14px 0">----</div>
      <input type="text" id="captchaAnswer" placeholder="أدخل الرقم هنا" style="width:100%;padding:12px;border-radius:10px;border:1px solid #2a3050;background:#11152a;color:#fff;text-align:center;font-size:18px">
      <button class="btn btn-success" style="width:100%;margin-top:12px" onclick="submitCaptcha()">✅ تحقق واحصل على العملات</button>
    </div>
    <?php
    break;

case 'tasks':
    if (!$user) { echo '<div class="empty">سجّل الدخول لعرض المهام.<br><button class="btn btn-primary" style="margin-top:14px" onclick="openAuthModal()">تسجيل الدخول</button></div>'; break; }
    $tasks = db()->query("SELECT * FROM tasks WHERE active=1")->fetchAll();
    $day = date('Y-m-d');
    ?>
    <div class="section-title">📋 المهام اليومية</div>
    <div class="admin-box">
    <?php if (!$tasks): ?>
      <div class="empty">لا توجد مهام حالياً.</div>
    <?php endif; ?>
    <?php foreach ($tasks as $t):
        $st = db()->prepare("SELECT * FROM task_completions WHERE user_id=? AND task_id=? AND day=?");
        $st->execute([$user['id'], $t['id'], $day]);
        $done = (bool)$st->fetch();
    ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #232a45">
        <div>
          <strong><?= e($t['title']) ?></strong>
          <div style="color:var(--muted);font-size:12px">⏱️ <?= (int)$t['seconds'] ?> ثانية · +<?= (int)$t['reward'] ?> عملة</div>
        </div>
        <?php if ($done): ?>
          <span class="badge approved">✅ مكتملة</span>
        <?php else: ?>
          <button class="btn btn-primary" onclick="startTask(<?= (int)$t['id'] ?>, '<?= e($t['url']) ?>', <?= (int)$t['seconds'] ?>)">ابدأ</button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <?php
    break;

case 'wallet':
    if (!$user) { echo '<div class="empty">سجّل الدخول لعرض محفظتك.<br><button class="btn btn-primary" style="margin-top:14px" onclick="openAuthModal()">تسجيل الدخول</button></div>'; break; }
    $wallets = db()->query("SELECT * FROM wallets WHERE active=1")->fetchAll();
    $usd = points_to_usd($user['points']);
    $minw = setting('min_withdraw_usd', 25);
    ?>
    <div class="admin-box">
      <h2>💳 محفظتي</h2>
      <p style="font-size:22px;margin:10px 0">💰 <?= (int)$user['points'] ?> عملة ≈ <strong><?= $usd ?>$</strong></p>
      <p style="color:var(--muted)">الحد الأدنى للسحب: <?= e($minw) ?>$</p>
      <hr style="border-color:#232a45;margin:14px 0">
      <h3>🏦 طريقة السحب الخاصة بك</h3>
      <select id="wType">
        <option value="usdt" <?= $user['wallet_type'] === 'usdt' ? 'selected' : '' ?>>USDT (TRC20)</option>
        <option value="sham" <?= $user['wallet_type'] === 'sham' ? 'selected' : '' ?>>الشام كاش</option>
      </select>
      <input id="wAddr" value="<?= e($user['wallet_address']) ?>" placeholder="عنوان المحفظة / رقم الحساب">
      <button class="btn btn-primary" onclick="saveWallet()">حفظ المحفظة</button>
      <button class="btn btn-success" style="margin-top:8px;width:100%" onclick="requestWithdraw()">💸 طلب سحب الرصيد كامل</button>
    </div>
    <div class="admin-box">
      <h3>➕ شحن الرصيد</h3>
      <p style="color:var(--muted);font-size:13px;margin-bottom:10px">حوّل المبلغ إلى إحدى المحافظ التالية ثم أرسل طلب التحقق:</p>
      <?php foreach ($wallets as $w): ?>
        <div style="background:#11152a;border-radius:10px;padding:10px;margin-bottom:8px">
          <strong><?= $w['type'] === 'usdt' ? '💎 USDT' : '📱 الشام كاش' ?> — <?= e($w['label']) ?></strong>
          <div style="font-family:monospace;word-break:break-all"><?= e($w['address']) ?></div>
        </div>
      <?php endforeach; ?>
      <select id="topupWallet"><?php foreach ($wallets as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['label']) ?></option><?php endforeach; ?></select>
      <input id="topupAmount" type="number" placeholder="المبلغ بالدولار">
      <input id="topupNote" placeholder="ملاحظة / رقم العملية (اختياري)">
      <button class="btn btn-primary" onclick="requestTopup()">إرسال طلب الشحن</button>
    </div>
    <?php
    break;

case 'orders':
    if (!$user) { echo '<div class="empty">سجّل الدخول لعرض طلباتك.<br><button class="btn btn-primary" style="margin-top:14px" onclick="openAuthModal()">تسجيل الدخول</button></div>'; break; }
    $st = db()->prepare("SELECT o.*, p.name, p.icon FROM orders o JOIN products p ON p.id=o.product_id WHERE o.user_id=? ORDER BY o.id DESC");
    $st->execute([$user['id']]);
    $orders = $st->fetchAll();
    ?>
    <div class="section-title">📦 طلباتي</div>
    <div class="admin-box">
    <?php if (!$orders): ?><div class="empty">لا توجد طلبات بعد.</div><?php endif; ?>
    <?php foreach ($orders as $o): ?>
      <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #232a45">
        <div><?= e($o['icon']) ?> <?= e($o['name']) ?> — <?= e($o['price']) ?>$</div>
        <span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span>
      </div>
    <?php endforeach; ?>
    </div>
    <?php
    break;

case 'privacy':
case 'terms':
    $st = db()->prepare("SELECT content FROM pages WHERE slug=?"); $st->execute([$page]); $c = $st->fetch();
    echo '<div class="admin-box" style="margin-top:18px;line-height:1.8">' . nl2br(e($c['content'] ?? '')) . '</div>';
    break;

case 'welcome':
    if (!$user) { redirect('?'); }
    $bonus = (int)setting('welcome_bonus_points', 200);
    $dest = is_admin() ? '?page=admin' : '?';
    ?>
    <div class="admin-box" style="margin-top:40px;text-align:center;padding:40px 20px">
      <div style="font-size:48px;margin-bottom:10px">🎉</div>
      <h2>أهلاً بك، <?= e($user['name'] ?: $user['username']) ?>!</h2>
      <p style="color:var(--muted);margin:14px 0">تم إنشاء حسابك بنجاح، وحصلت على هدية ترحيبية: <strong style="color:var(--accent2)">+<?= $bonus ?> عملة Yassota</strong> 🎁</p>
      <p style="color:var(--muted);font-size:13px">سيتم تحويلك للصفحة الرئيسية بعد لحظات...</p>
      <a href="<?= e($dest) ?>" class="btn btn-primary" style="margin-top:16px;display:inline-block">انتقل الآن</a>
    </div>
    <script>setTimeout(() => { location.href = '<?= e($dest) ?>'; }, 3000);</script>
    <?php
    break;

case 'admin':
    require_admin();
    $tab = $_GET['tab'] ?? 'dashboard';
    ?>
    <div class="admin-tabs">
      <?php foreach (['dashboard'=>'📊 لوحة البيانات','products'=>'🛍️ المنتجات','orders'=>'📦 الطلبات','topups'=>'💵 طلبات الشحن','withdraws'=>'💸 طلبات السحب','wallets'=>'🏦 المحافظ','tasks'=>'📋 المهام','banners'=>'🖼️ البنرات','pages'=>'📜 الصفحات','users'=>'👥 المستخدمون','settings'=>'⚙️ الإعدادات'] as $k=>$label): ?>
        <a href="?page=admin&tab=<?= $k ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($tab === 'dashboard'):
        $users_count = db()->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
        $products_count = db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'];
        $pending_orders = db()->query("SELECT COUNT(*) c FROM orders WHERE status='pending'")->fetch()['c'];
        $pending_topups = db()->query("SELECT COUNT(*) c FROM topup_requests WHERE status='pending'")->fetch()['c'];
        $pending_withdraws = db()->query("SELECT COUNT(*) c FROM withdraw_requests WHERE status='pending'")->fetch()['c'];
        $points_total = db()->query("SELECT COALESCE(SUM(points),0) s FROM users")->fetch()['s'];
    ?>
      <div class="admin-box">
        <div class="formrow">
          <div>👥 المستخدمون<br><strong style="font-size:22px"><?= $users_count ?></strong></div>
          <div>🛍️ المنتجات<br><strong style="font-size:22px"><?= $products_count ?></strong></div>
          <div>📦 طلبات معلّقة<br><strong style="font-size:22px"><?= $pending_orders ?></strong></div>
          <div>💵 شحن معلّق<br><strong style="font-size:22px"><?= $pending_topups ?></strong></div>
          <div>💸 سحب معلّق<br><strong style="font-size:22px"><?= $pending_withdraws ?></strong></div>
          <div>🪙 عملات بالتداول<br><strong style="font-size:22px"><?= number_format($points_total) ?></strong></div>
        </div>
        <p style="color:var(--muted);font-size:13px">⚖️ نسبة الربح الحالية: <?= e(setting('profit_split_admin')) ?>% للإدارة / <?= e(setting('profit_split_user')) ?>% للمستخدم — عدّلها من تبويب الإعدادات بحسب عوائد MoneyTag الفعلية.</p>
      </div>

    <?php elseif ($tab === 'products'):
        $cats = db()->query("SELECT * FROM categories")->fetchAll();
        $products = db()->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <h3>➕ إضافة / تعديل منتج</h3>
        <form method="post" action="?action=admin_save_product">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" id="pid">
          <div class="formrow">
            <input name="name" id="pname" placeholder="اسم المنتج" required>
            <input name="icon" id="picon" placeholder="إيقونة (emoji) اختياري">
            <input name="image" id="pimage" placeholder="رابط صورة (اختياري)">
            <input name="price" id="pprice" type="number" step="0.01" placeholder="السعر $" required>
            <input name="old_price" id="poldprice" type="number" step="0.01" placeholder="السعر قبل الخصم (اختياري)">
            <input name="tag" id="ptag" placeholder="وسم مثل: جديد / خصم">
            <select name="category_id"><option value="">بدون قسم</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
          </div>
          <textarea name="description" id="pdesc" placeholder="الوصف" rows="3"></textarea>
          <button class="btn btn-primary">💾 حفظ المنتج</button>
        </form>
        <form method="post" action="?action=admin_save_category" style="margin-top:10px;display:flex;gap:8px">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input name="name" placeholder="اسم قسم جديد" style="flex:1">
          <button class="btn btn-ghost">➕ قسم</button>
        </form>
      </div>
      <div class="admin-box">
        <table>
          <tr><th>المنتج</th><th>السعر</th><th>الوسم</th><th></th></tr>
          <?php foreach ($products as $p): ?>
          <tr>
            <td><?= e($p['icon']) ?> <?= e($p['name']) ?></td>
            <td><?= e($p['price']) ?>$</td>
            <td><?= e($p['tag']) ?></td>
            <td>
              <form method="post" action="?action=admin_delete_product" style="display:inline">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-danger" onclick="return confirm('حذف المنتج؟')">🗑️</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'orders'):
        $orders = db()->query("SELECT o.*, u.name uname, p.name pname FROM orders o JOIN users u ON u.id=o.user_id JOIN products p ON p.id=o.product_id ORDER BY o.id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <table>
          <tr><th>المستخدم</th><th>المنتج</th><th>السعر</th><th>الحالة</th><th>إجراء</th></tr>
          <?php foreach ($orders as $o): ?>
          <tr>
            <td><?= e($o['uname']) ?></td><td><?= e($o['pname']) ?></td><td><?= e($o['price']) ?>$</td>
            <td><span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span></td>
            <td>
              <?php if ($o['status'] === 'pending'): ?>
              <form method="post" action="?action=admin_order_decision" style="display:flex;gap:4px">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                <button name="decision" value="approve" class="btn btn-success">✅</button>
                <button name="decision" value="reject" class="btn btn-danger">❌</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'topups'):
        $rows = db()->query("SELECT t.*, u.name uname, w.label wlabel FROM topup_requests t JOIN users u ON u.id=t.user_id LEFT JOIN wallets w ON w.id=t.wallet_id ORDER BY t.id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <table>
          <tr><th>المستخدم</th><th>المحفظة</th><th>المبلغ</th><th>ملاحظة</th><th>الحالة</th><th>إجراء</th></tr>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['uname']) ?></td><td><?= e($r['wlabel']) ?></td><td><?= e($r['amount']) ?>$</td><td><?= e($r['note']) ?></td>
            <td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
            <td>
              <?php if ($r['status'] === 'pending'): ?>
              <form method="post" action="?action=admin_topup_decision" style="display:flex;gap:4px">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button name="decision" value="approve" class="btn btn-success">✅</button>
                <button name="decision" value="reject" class="btn btn-danger">❌</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'withdraws'):
        $rows = db()->query("SELECT w.*, u.name uname FROM withdraw_requests w JOIN users u ON u.id=w.user_id ORDER BY w.id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <table>
          <tr><th>المستخدم</th><th>المبلغ</th><th>الطريقة</th><th>العنوان</th><th>الحالة</th><th>إجراء</th></tr>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['uname']) ?></td><td><?= e($r['amount_usd']) ?>$</td><td><?= e($r['wallet_type']) ?></td>
            <td style="font-family:monospace"><?= e($r['wallet_address']) ?></td>
            <td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
            <td>
              <?php if ($r['status'] === 'pending'): ?>
              <form method="post" action="?action=admin_withdraw_decision" style="display:flex;gap:4px">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button name="decision" value="approve" class="btn btn-success">✅</button>
                <button name="decision" value="reject" class="btn btn-danger">❌</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'wallets'):
        $wallets = db()->query("SELECT * FROM wallets ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <h3>➕ إضافة محفظة استقبال</h3>
        <form method="post" action="?action=admin_save_wallet" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <select name="type"><option value="usdt">USDT (TRC20)</option><option value="sham">الشام كاش</option></select>
          <input name="label" placeholder="اسم/وصف">
          <input name="address" placeholder="العنوان / رقم المحفظة">
          <button class="btn btn-primary">حفظ</button>
        </form>
      </div>
      <div class="admin-box">
        <table>
          <tr><th>النوع</th><th>الاسم</th><th>العنوان</th><th>الحالة</th><th></th></tr>
          <?php foreach ($wallets as $w): ?>
          <tr>
            <td><?= $w['type'] === 'usdt' ? '💎 USDT' : '📱 شام كاش' ?></td><td><?= e($w['label']) ?></td>
            <td style="font-family:monospace"><?= e($w['address']) ?></td>
            <td><?= $w['active'] ? '🟢' : '⏸️' ?></td>
            <td>
              <form method="post" action="?action=admin_toggle_wallet" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><button class="btn btn-ghost">تبديل</button></form>
              <form method="post" action="?action=admin_delete_wallet" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><button class="btn btn-danger">🗑️</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'tasks'):
        $tasks = db()->query("SELECT * FROM tasks ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <h3>➕ إضافة مهمة (مثل: زيارة رابط لمدة معينة)</h3>
        <form method="post" action="?action=admin_save_task" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input name="title" placeholder="عنوان المهمة" required>
          <input name="url" placeholder="الرابط" required>
          <input name="seconds" type="number" value="15" placeholder="مدة الانتظار بالثواني">
          <input name="reward" type="number" value="50" placeholder="عدد العملات">
          <button class="btn btn-primary">حفظ</button>
        </form>
      </div>
      <div class="admin-box">
        <table>
          <tr><th>العنوان</th><th>المدة</th><th>المكافأة</th><th>الحالة</th><th></th></tr>
          <?php foreach ($tasks as $t): ?>
          <tr>
            <td><?= e($t['title']) ?></td><td><?= (int)$t['seconds'] ?>s</td><td><?= (int)$t['reward'] ?></td>
            <td><?= $t['active'] ? '🟢' : '⏸️' ?></td>
            <td><form method="post" action="?action=admin_toggle_task" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-ghost">تبديل</button></form></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'banners'):
        $banners = db()->query("SELECT * FROM banners ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <form method="post" action="?action=admin_save_banner" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input name="image" placeholder="رابط صورة البنر" required>
          <input name="link" placeholder="رابط عند الضغط (اختياري)">
          <button class="btn btn-primary">إضافة بنر</button>
        </form>
      </div>
      <div class="admin-box">
        <?php foreach ($banners as $b): ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
          <img src="<?= e($b['image']) ?>" style="width:80px;height:40px;object-fit:cover;border-radius:6px">
          <span style="flex:1"><?= e($b['link']) ?></span>
          <form method="post" action="?action=admin_delete_banner"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-danger">🗑️</button></form>
        </div>
        <?php endforeach; ?>
      </div>

    <?php elseif ($tab === 'pages'):
        $privacy = db()->query("SELECT content FROM pages WHERE slug='privacy'")->fetch();
        $terms = db()->query("SELECT content FROM pages WHERE slug='terms'")->fetch();
    ?>
      <div class="admin-box">
        <h3>🔒 سياسة الخصوصية</h3>
        <form method="post" action="?action=admin_save_page">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="slug" value="privacy">
          <textarea name="content" rows="6"><?= e($privacy['content'] ?? '') ?></textarea>
          <button class="btn btn-primary">حفظ</button>
        </form>
      </div>
      <div class="admin-box">
        <h3>📜 شروط الاستخدام</h3>
        <form method="post" action="?action=admin_save_page">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="slug" value="terms">
          <textarea name="content" rows="6"><?= e($terms['content'] ?? '') ?></textarea>
          <button class="btn btn-primary">حفظ</button>
        </form>
      </div>

    <?php elseif ($tab === 'users'):
        $users = db()->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <table>
          <tr><th>الاسم</th><th>البريد</th><th>النقاط</th><th>الدور</th><th>الحالة</th><th>إجراء</th></tr>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td><td><?= (int)$u['points'] ?></td>
            <td><?= e($u['role']) ?></td><td><?= $u['is_banned'] ? '🚫' : '🟢' ?></td>
            <td style="display:flex;gap:4px;flex-wrap:wrap">
              <form method="post" action="?action=admin_user_action"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="op" value="<?= $u['is_banned'] ? 'unban' : 'ban' ?>"><button class="btn btn-ghost"><?= $u['is_banned'] ? 'رفع حظر' : 'حظر' ?></button></form>
              <form method="post" action="?action=admin_user_action" style="display:flex;gap:4px"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="op" value="addpoints"><input type="number" name="points" placeholder="عملات" style="width:80px;padding:4px"><button class="btn btn-primary">إضافة</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'settings'): ?>
      <div class="admin-box">
        <form method="post" action="?action=admin_save_settings" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <label>اسم الموقع<input name="site_name" value="<?= e(setting('site_name')) ?>"></label>
          <label>الوصف (SEO)<input name="site_description" value="<?= e(setting('site_description')) ?>"></label>
          <label>كلمات مفتاحية<input name="site_keywords" value="<?= e(setting('site_keywords')) ?>"></label>
          <label>رابط الشعار<input name="logo_url" value="<?= e(setting('logo_url')) ?>"></label>
          <label>عنوان البنر<input name="banner_title" value="<?= e(setting('banner_title')) ?>"></label>
          <label>وصف البنر<input name="banner_subtitle" value="<?= e(setting('banner_subtitle')) ?>"></label>
          <label>سعر النقطة بالدولار<input name="points_rate" value="<?= e(setting('points_rate')) ?>"></label>
          <label>الحد الأدنى للسحب $<input name="min_withdraw_usd" value="<?= e(setting('min_withdraw_usd')) ?>"></label>
          <label>مكافأة الكابتشا<input name="captcha_reward" value="<?= e(setting('captcha_reward')) ?>"></label>
          <label>أقصى كابتشا باليوم<input name="captcha_max_per_day" value="<?= e(setting('captcha_max_per_day')) ?>"></label>
          <label>أقصى مهام باليوم<input name="task_max_per_day" value="<?= e(setting('task_max_per_day')) ?>"></label>
          <label>نسبة ربح الإدارة %<input name="profit_split_admin" value="<?= e(setting('profit_split_admin')) ?>"></label>
          <label>نسبة ربح المستخدم %<input name="profit_split_user" value="<?= e(setting('profit_split_user')) ?>"></label>
        </form>
        <button class="btn btn-primary" form="" onclick="document.querySelector('form[action=\'?action=admin_save_settings\']').submit()">💾 حفظ الإعدادات</button>
        <hr style="border-color:#232a45;margin:18px 0">
        <p style="color:var(--muted);font-size:13px">
          ℹ️ بيانات قاعدة البيانات وبوت تيليجرام وGoogle OAuth وMoneyTag تُضبط من ملف <code>config.php</code> في جذر المشروع (غير مرفوع على Git لحمايته). نسبة الربح 95/5 تقديرية ويتم ضبطها يدوياً عبر "سعر النقطة" لأن شبكات الإعلانات لا تعطي API مباشر بالعائد الحقيقي.
        </p>
      </div>
    <?php endif; ?>
    <?php
    break;

default:
    http_response_code(404);
    echo '<div class="empty">الصفحة غير موجودة.</div>';
}
?>
</div>

<div class="bottom-nav">
  <a href="?" class="<?= $page === 'home' ? 'active' : '' ?>"><span class="bi">🏠</span>الرئيسية</a>
  <a href="?page=earn" class="<?= $page === 'earn' ? 'active' : '' ?>"><span class="bi">🪙</span>اكسب</a>
  <a href="?page=tasks" class="<?= $page === 'tasks' ? 'active' : '' ?>"><span class="bi">📋</span>مهام</a>
  <a href="?page=wallet" class="<?= $page === 'wallet' ? 'active' : '' ?>"><span class="bi">💳</span>محفظتي</a>
  <a href="?page=orders" class="<?= $page === 'orders' ? 'active' : '' ?>"><span class="bi">📦</span>طلباتي</a>
</div>

<footer>© <?= date('Y') ?> <?= e($siteName) ?> — جميع الحقوق محفوظة</footer>

<?php if (!isset($_COOKIE['policy_accepted']) || $_COOKIE['policy_accepted'] !== setting('policy_version', '1')): ?>
<div class="policy-modal" id="policyModal">
  <div class="policy-box">
    <h2>👋 أهلاً بك</h2>
    <p style="margin:12px 0;color:var(--muted)">باستخدامك للموقع أنت توافق على <a href="?page=privacy" style="color:var(--accent2)">سياسة الخصوصية</a> و<a href="?page=terms" style="color:var(--accent2)">شروط الاستخدام</a>.</p>
    <button class="btn btn-primary" style="width:100%" onclick="acceptPolicy()">موافق وأستمر</button>
  </div>
</div>
<?php endif; ?>

<div class="toast" id="toast"></div>

<?php if (MONEYTAG_SCRIPT): ?>
<?= MONEYTAG_SCRIPT ?>
<?php endif; ?>

<script>
const CSRF = "<?= csrf_token() ?>";
const LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
function openAuthModal(){ const m = document.getElementById('authModal'); if (m) m.style.display = 'flex'; }
function closeAuthModal(){ const m = document.getElementById('authModal'); if (m) m.style.display = 'none'; }
function switchAuthTab(tab){
  document.getElementById('loginForm').style.display = tab === 'login' ? 'block' : 'none';
  document.getElementById('registerForm').style.display = tab === 'register' ? 'block' : 'none';
  document.getElementById('tabLoginBtn').classList.toggle('btn-primary', tab === 'login');
  document.getElementById('tabRegisterBtn').classList.toggle('btn-primary', tab === 'register');
}
function requireLogin(){ openAuthModal(); return false; }
window.addEventListener('load', () => {
  const pl = document.getElementById('preloader');
  setTimeout(() => { pl.style.opacity = 0; setTimeout(() => pl.remove(), 400); }, 300);
});
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
}
function toast(msg){
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.display = 'block';
  setTimeout(() => t.style.display = 'none', 2600);
}
function acceptPolicy(){
  fetch('?action=accept_policy').then(() => document.getElementById('policyModal').remove());
}
async function post(action, data){
  data.append('csrf', CSRF);
  const r = await fetch('?action=' + action, { method: 'POST', body: data });
  return r.json();
}
function buyProduct(id){
  if (!LOGGED_IN) return requireLogin();
  if (!confirm('تأكيد إرسال طلب الشراء؟')) return;
  const d = new FormData(); d.append('product_id', id);
  post('api_buy_product', d).then(res => { toast(res.msg); });
}
function loadCaptcha(){
  fetch('?action=api_new_captcha').then(r => r.json()).then(res => {
    document.getElementById('captchaBox').textContent = res.code;
  });
}
if (document.getElementById('captchaBox')) loadCaptcha();
function submitCaptcha(){
  if (!LOGGED_IN) return requireLogin();
  const ans = document.getElementById('captchaAnswer').value.trim();
  const d = new FormData(); d.append('answer', ans);
  post('api_solve_captcha', d).then(res => {
    toast(res.msg);
    document.getElementById('captchaAnswer').value = '';
    if (res.ok) loadCaptcha();
  });
}
function startTask(id, url, seconds){
  if (!LOGGED_IN) return requireLogin();
  window.open(url, '_blank');
  let remain = seconds;
  toast('انتظر ' + seconds + ' ثانية ثم سيتم التحقق...');
  const iv = setInterval(() => {
    remain--;
    if (remain <= 0) {
      clearInterval(iv);
      const d = new FormData(); d.append('task_id', id);
      post('api_complete_task', d).then(res => { toast(res.msg); if (res.ok) setTimeout(() => location.reload(), 1200); });
    }
  }, 1000);
}
function saveWallet(){
  if (!LOGGED_IN) return requireLogin();
  const d = new FormData();
  d.append('type', document.getElementById('wType').value);
  d.append('address', document.getElementById('wAddr').value);
  post('api_save_wallet', d).then(res => toast(res.msg));
}
function requestWithdraw(){
  if (!LOGGED_IN) return requireLogin();
  if (!confirm('تأكيد طلب سحب الرصيد بالكامل؟')) return;
  post('api_request_withdraw', new FormData()).then(res => { toast(res.msg); if (res.ok) setTimeout(() => location.reload(), 1200); });
}
function requestTopup(){
  if (!LOGGED_IN) return requireLogin();
  const d = new FormData();
  d.append('wallet_id', document.getElementById('topupWallet').value);
  d.append('amount', document.getElementById('topupAmount').value);
  d.append('note', document.getElementById('topupNote').value);
  post('api_request_topup', d).then(res => toast(res.msg));
}
</script>
</body>
</html>
