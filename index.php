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

// ثوابت اختيارية قد لا تكون موجودة في config.php القديم
foreach ([
    'ADMOB_APP_ID', 'ADMOB_REWARDED_ID', 'ADMOB_INTERSTitial_ID', 'OPENROUTER_KEY',
] as $opt) { if (!defined($opt)) define($opt, ''); }

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
    "CREATE TABLE IF NOT EXISTS wheel_spins (
        id $id,
        user_id INT NOT NULL,
        prize INT NOT NULL,
        cost INT NOT NULL,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS ad_watch_logs (
        id $id,
        user_id INT NOT NULL,
        day VARCHAR(10) NOT NULL,
        count INT NOT NULL DEFAULT 0
    )$engine",
    "CREATE TABLE IF NOT EXISTS chat_groups (
        id $id,
        name VARCHAR(190) NOT NULL,
        icon VARCHAR(20) NULL,
        link VARCHAR(500) NOT NULL,
        members VARCHAR(40) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT NOT NULL DEFAULT 1
    )$engine",
    ];

    foreach ($tables as $sql) $pdo->exec($sql);

    // أعمدة إضافية (الإحالة + قياس البنر) بشكل آمن لو لم تكن موجودة
    $addCol = function (string $table, string $col, string $def) use ($pdo) {
        try { $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def"); } catch (Throwable $e) { /* موجود مسبقاً */ }
    };
    $addCol('users', 'ref_code', "VARCHAR(20) NULL");
    $addCol('users', 'referred_by', "INT NULL");
    $addCol('banners', 'title', "VARCHAR(190) NULL");
    $addCol('banners', 'size_label', "VARCHAR(60) NULL");

    // توليد رموز إحالة لمن لا يملكها
    foreach ($pdo->query("SELECT id FROM users WHERE ref_code IS NULL OR ref_code=''")->fetchAll() as $row) {
        $pdo->prepare("UPDATE users SET ref_code=? WHERE id=?")
            ->execute([substr(strtoupper(bin2hex(random_bytes(4))), 0, 7), $row['id']]);
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
        // عجلة الحظ
        'wheel_cost' => '20',          // تكلفة الدورة بالعملات
        'wheel_prizes' => '0,5,10,20,50,100,200,500', // جوائز العجلة (تفصلها فاصلة)
        // الإحالة
        'referral_reward' => '100',     // مكافأة دعوة صديق
        // مكافأة مشاهدة إعلان 30 ثانية
        'ad_watch_reward' => '50',
        'ad_watch_seconds' => '30',
        'ad_watch_max_per_day' => '20',
        // إعلان الكابتشا الإجباري (ثوانٍ)
        'captcha_ad_seconds' => '5',
        // شريط الأخبار (كل سطر خبر)
        'news_ticker' => "🎉 مرحباً بك في منصة Yassota — اربح العملات مجاناً\n💰 اسحب أرباحك عبر USDT والشام كاش\n🔥 عجلة الحظ متاحة الآن — جرّب حظك!",
        // OpenRouter
        'openrouter_key' => '',
        'openrouter_model' => 'openai/gpt-4o-mini',
        // قياسات البنرات (نص إرشادي للأدمن)
        'banner_size_hint' => '1200×400 بكسل (نسبة 3:1) — صيغة JPG/PNG/WebP',
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

/**
 * شعار Yassota الافتراضي (SVG مدمج) — يظهر دائماً حتى بدون رفع صورة.
 * عملة ذهبية مع حرف Y، تتدرّج بألوان الهوية.
 */
function brand_logo_svg(int $size = 36): string
{
    $s = (int)$size;
    return '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Yassota">'
        . '<defs><linearGradient id="yg" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="#6c5ce7"/><stop offset=".55" stop-color="#a08bff"/><stop offset="1" stop-color="#00d2a0"/>'
        . '</linearGradient></defs>'
        . '<rect x="2" y="2" width="60" height="60" rx="16" fill="url(#yg)"/>'
        . '<circle cx="32" cy="32" r="20" fill="#0f1320" opacity=".18"/>'
        . '<path d="M22 20l10 13 10-13" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<path d="M32 33v12" stroke="#fff" stroke-width="5" stroke-linecap="round"/>'
        . '</svg>';
}

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

/* ---- OpenRouter AI helpers ---- */
function openrouter_key(): string
{
    $k = trim((string)setting('openrouter_key', ''));
    return $k !== '' ? $k : (string)OPENROUTER_KEY;
}
function openrouter_request(string $path, string $method = 'GET', ?array $body = null): array
{
    $key = openrouter_key();
    if (!$key) return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'لم يتم ضبط مفتاح OpenRouter.'];
    $ch = curl_init('https://openrouter.ai/api/v1' . $path);
    $headers = ['Authorization: Bearer ' . $key, 'Content-Type: application/json'];
    if (defined('SITE_URL') && SITE_URL) { $headers[] = 'HTTP-Referer: ' . SITE_URL; }
    $headers[] = 'X-Title: ' . setting('site_name', 'Yassota');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return ['ok' => false, 'status' => $status, 'data' => null, 'error' => $err ?: 'فشل الاتصال.'];
    $data = json_decode($res, true);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data, 'error' => $data['error']['message'] ?? ($status >= 400 ? 'خطأ HTTP ' . $status : '')];
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
   3) GOOGLE OAUTH
   ====================================================================== */
function google_login_url(): string
{
    if (!GOOGLE_CLIENT_ID) return '#';
    $params = [
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
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
        'redirect_uri' => GOOGLE_REDIRECT_URI,
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

    if ($u) {
        db()->prepare("UPDATE users SET name=?, avatar=?, google_id=?, role=?, last_login=" . (DB_DRIVER === 'sqlite' ? "datetime('now')" : 'NOW()') . " WHERE id=?")
            ->execute([$name, $avatar, $gid, $role, $u['id']]);
        $uid = $u['id'];
    } else {
        $refCode = substr(strtoupper(bin2hex(random_bytes(4))), 0, 7);
        // ربط الإحالة لو وُجد رمز محفوظ
        $referrer = null;
        if (!empty($_COOKIE['ref_code'])) {
            $rc = preg_replace('/[^A-Za-z0-9]/', '', $_COOKIE['ref_code']);
            $rs = db()->prepare("SELECT id FROM users WHERE ref_code=?");
            $rs->execute([$rc]);
            $referrer = $rs->fetch()['id'] ?? null;
        }
        db()->prepare("INSERT INTO users (google_id, email, name, avatar, role, ref_code, referred_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$gid, $email, $name, $avatar, $role, $refCode, $referrer]);
        $uid = db()->lastInsertId();
        if ($referrer) {
            $rw = (int)setting('referral_reward', 100);
            add_points((int)$referrer, $rw, 'referral', 'مكافأة دعوة صديق جديد');
            add_points((int)$uid, (int)round($rw / 2), 'referral', 'مكافأة ترحيب عبر دعوة');
        }
    }
    $_SESSION['uid'] = $uid;
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

if ($action === 'logout') { logout(); redirect('?'); }

// التقاط رمز الإحالة وتخزينه لحين التسجيل
if (!empty($_GET['ref'])) {
    setcookie('ref_code', preg_replace('/[^A-Za-z0-9]/', '', $_GET['ref']), time() + 60 * 60 * 24 * 30, '/');
}

// ملف PWA Manifest (لتحويل الموقع إلى تطبيق APK عبر TWA / Bubblewrap)
if ($action === 'manifest') {
    header('Content-Type: application/manifest+json; charset=utf-8');
    $name = setting('site_name', 'Yassota Store');
    $logo = setting('logo_url', '');
    $icon = $logo ?: '?action=appicon';
    echo json_encode([
        'name' => $name,
        'short_name' => $name,
        'description' => setting('site_description', ''),
        'start_url' => './',
        'display' => 'standalone',
        'orientation' => 'portrait',
        'background_color' => '#0f1320',
        'theme_color' => '#0f1320',
        'lang' => 'ar',
        'dir' => 'rtl',
        'icons' => [
            ['src' => $icon, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// أيقونة التطبيق الافتراضية (SVG) — تُستخدم عند عدم وجود شعار مرفوع
if ($action === 'appicon') {
    header('Content-Type: image/svg+xml; charset=utf-8');
    echo brand_logo_svg(512);
    exit;
}

// Service Worker بسيط لدعم العمل دون اتصال وتثبيت التطبيق
if ($action === 'sw') {
    header('Content-Type: application/javascript; charset=utf-8');
    echo "const C='yassota-v1';self.addEventListener('install',e=>self.skipWaiting());self.addEventListener('activate',e=>self.clients.claim());self.addEventListener('fetch',e=>{e.respondWith(fetch(e.request).catch(()=>caches.match(e.request)))});";
    exit;
}

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

        case 'api_spin_wheel':
            csrf_check();
            $cost = (int)setting('wheel_cost', 20);
            if ($u['points'] < $cost) { echo json_encode(['ok' => false, 'msg' => 'رصيدك من العملات غير كافٍ للدوران.']); exit; }
            $prizes = array_values(array_filter(array_map('intval', explode(',', setting('wheel_prizes', '0,5,10,20,50,100')))));
            if (!$prizes) $prizes = [0, 5, 10, 20, 50, 100];
            $idx = random_int(0, count($prizes) - 1);
            $prize = $prizes[$idx];
            // خصم التكلفة ثم إضافة الجائزة
            db()->prepare("UPDATE users SET points = points - ? WHERE id = ?")->execute([$cost, $u['id']]);
            if ($prize > 0) add_points($u['id'], $prize, 'wheel', 'جائزة عجلة الحظ');
            db()->prepare("INSERT INTO wheel_spins (user_id, prize, cost) VALUES (?,?,?)")->execute([$u['id'], $prize, $cost]);
            $st = db()->prepare("SELECT points FROM users WHERE id=?"); $st->execute([$u['id']]);
            $bal = (int)$st->fetch()['points'];
            echo json_encode(['ok' => true, 'prize' => $prize, 'index' => $idx, 'prizes' => $prizes, 'balance' => $bal,
                'msg' => $prize > 0 ? "🎉 ربحت {$prize} عملة!" : '💨 حظ أوفر المرة القادمة!']);
            exit;

        case 'api_watch_ad':
            csrf_check();
            $day = date('Y-m-d');
            $max = (int)setting('ad_watch_max_per_day', 20);
            $st = db()->prepare("SELECT * FROM ad_watch_logs WHERE user_id=? AND day=?");
            $st->execute([$u['id'], $day]);
            $log = $st->fetch();
            $count = $log['count'] ?? 0;
            if ($count >= $max) { echo json_encode(['ok' => false, 'msg' => 'وصلت للحد اليومي من مشاهدة الإعلانات.']); exit; }
            $reward = (int)setting('ad_watch_reward', 50);
            add_points($u['id'], $reward, 'ad_watch', 'مشاهدة إعلان مكافأ');
            if ($log) db()->prepare("UPDATE ad_watch_logs SET count=count+1 WHERE id=?")->execute([$log['id']]);
            else db()->prepare("INSERT INTO ad_watch_logs (user_id, day, count) VALUES (?,?,1)")->execute([$u['id'], $day]);
            echo json_encode(['ok' => true, 'msg' => "تم! +{$reward} عملة", 'reward' => $reward, 'remaining' => $max - $count - 1]);
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
            db()->prepare("INSERT INTO banners (image, link, title, size_label) VALUES (?,?,?,?)")
                ->execute([$_POST['image'], $_POST['link'] ?? '', $_POST['title'] ?? '', $_POST['size_label'] ?? '']);
            redirect('?page=admin&tab=banners');

        case 'admin_save_chat_group':
            db()->prepare("INSERT INTO chat_groups (name, icon, link, members) VALUES (?,?,?,?)")
                ->execute([$_POST['name'], $_POST['icon'] ?? '💬', $_POST['link'], $_POST['members'] ?? '']);
            flash('تمت إضافة المجموعة.');
            redirect('?page=admin&tab=chats');

        case 'admin_delete_chat_group':
            db()->prepare("DELETE FROM chat_groups WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=chats');

        case 'admin_openrouter_models':
            header('Content-Type: application/json; charset=utf-8');
            $r = openrouter_request('/models');
            $models = [];
            if ($r['ok'] && !empty($r['data']['data'])) {
                foreach ($r['data']['data'] as $m) {
                    $models[] = ['id' => $m['id'] ?? '', 'name' => $m['name'] ?? ($m['id'] ?? ''),
                        'ctx' => $m['context_length'] ?? null,
                        'prompt' => $m['pricing']['prompt'] ?? null, 'completion' => $m['pricing']['completion'] ?? null];
                }
            }
            echo json_encode(['ok' => $r['ok'], 'error' => $r['error'], 'count' => count($models), 'models' => $models], JSON_UNESCAPED_UNICODE);
            exit;

        case 'admin_openrouter_test':
            header('Content-Type: application/json; charset=utf-8');
            $model = trim($_POST['model'] ?? '') ?: setting('openrouter_model', 'openai/gpt-4o-mini');
            $prompt = trim($_POST['prompt'] ?? '') ?: 'قل "مرحباً" بكلمة واحدة فقط.';
            $r = openrouter_request('/chat/completions', 'POST', [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => 256,
            ]);
            $reply = $r['data']['choices'][0]['message']['content'] ?? '';
            echo json_encode(['ok' => $r['ok'], 'error' => $r['error'], 'model' => $model, 'reply' => $reply], JSON_UNESCAPED_UNICODE);
            exit;

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
<meta name="theme-color" content="#0f1320">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="manifest" href="?action=manifest">
<?php if (!$logo): ?><link rel="apple-touch-icon" href="?action=appicon"><?php endif; ?>
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

/* ===== شارة الرصيد في الشريط العلوي ===== */
.coin-chip{display:flex;align-items:center;gap:6px;background:linear-gradient(135deg,#3a2f12,#5a4a1c);color:#ffd86b;padding:6px 12px;border-radius:30px;font-weight:700;font-size:13px;border:1px solid #6b5620;white-space:nowrap}
.coin-chip small{color:#cdb98a;font-weight:600}

/* ===== كاروسيل البنرات ===== */
.bnr-wrap{margin:16px 18px;border-radius:var(--radius);overflow:hidden;position:relative}
.bnr-track{display:flex;transition:transform .6s cubic-bezier(.4,0,.2,1)}
.bnr-slide{min-width:100%;position:relative;aspect-ratio:3/1;background:linear-gradient(135deg,#3d2c8d,#6c5ce7 55%,#00d2a0);display:flex;align-items:center;justify-content:center}
.bnr-slide img{width:100%;height:100%;object-fit:cover;display:block}
.bnr-slide .bnr-cap{position:absolute;inset:auto 0 0 0;background:linear-gradient(transparent,rgba(0,0,0,.75));padding:30px 18px 14px;font-weight:700;font-size:16px}
.bnr-ph{color:#fff;text-align:center;padding:10px}
.bnr-ph .sz{font-size:12px;opacity:.85;margin-top:6px;background:rgba(0,0,0,.25);display:inline-block;padding:3px 10px;border-radius:20px}
.bnr-dots{position:absolute;bottom:8px;left:0;right:0;display:flex;gap:6px;justify-content:center;z-index:3}
.bnr-dots span{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.45);cursor:pointer;transition:.2s}
.bnr-dots span.on{background:#fff;width:22px;border-radius:5px}

/* ===== شريط الأخبار المتحرك ===== */
.news-bar{margin:0 18px 16px;display:flex;align-items:stretch;background:linear-gradient(90deg,#1a1030,#241540);border:1px solid #3a2a5e;border-radius:12px;overflow:hidden;box-shadow:0 0 24px rgba(108,92,231,.25)}
.news-live{display:flex;align-items:center;gap:6px;background:var(--danger);color:#fff;font-weight:800;font-size:12px;padding:0 12px;white-space:nowrap}
.news-live .dot{width:8px;height:8px;border-radius:50%;background:#fff;animation:pulse 1s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
.news-track-wrap{flex:1;overflow:hidden;position:relative}
.news-track{display:inline-flex;white-space:nowrap;padding:10px 0;animation:news-scroll 22s linear infinite;will-change:transform}
.news-track:hover{animation-play-state:paused}
.news-track .item{display:inline-flex;align-items:center;gap:6px;padding:0 26px;font-size:14px;font-weight:600;color:#e9e4ff;position:relative}
.news-track .item::after{content:"•";position:absolute;left:-3px;color:var(--accent)}
@keyframes news-scroll{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}

/* ===== مجموعة الأقسام (قائمة منسدلة) ===== */
.group-box{margin:0 18px 8px}
.group-head{display:flex;align-items:center;justify-content:space-between;gap:8px;background:var(--card);border:1px solid #2a3050;border-radius:12px;padding:12px 16px;cursor:pointer;font-weight:700}
.group-head .chev{transition:.3s}
.group-box.open .group-head .chev{transform:rotate(180deg)}
.group-body{display:none;flex-wrap:wrap;gap:8px;padding:12px 2px 4px}
.group-box.open .group-body{display:flex}
.chip{background:#232a45;border:1px solid #2f3656;padding:8px 14px;border-radius:20px;font-size:13px;cursor:pointer;transition:.2s}
.chip:hover,.chip.on{background:var(--accent);border-color:var(--accent)}

/* ===== تحسين كروت الشراء ===== */
.card{background:linear-gradient(160deg,#1d2440,#161b2e);border:1px solid #2a3257}
.card:hover{transform:translateY(-4px);border-color:var(--accent);box-shadow:0 12px 30px rgba(108,92,231,.25)}
.card .pimg-wrap{width:100%;aspect-ratio:1/1;border-radius:12px;overflow:hidden;background:#11152a;display:flex;align-items:center;justify-content:center;margin-bottom:10px}
.card .pimg-wrap img{width:100%;height:100%;object-fit:cover}
.card .pimg-wrap .ph-icon{font-size:46px}
.card h3{font-size:15px;margin:2px 0 8px;text-align:center;min-height:auto;font-weight:700}
.card .price-row{display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:4px}
.card .price{font-size:18px;font-weight:800;background:linear-gradient(135deg,#00d2a0,#5fffd0);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.card .buy{background:linear-gradient(135deg,#6c5ce7,#00d2a0);color:#fff}

/* ===== الشبكة الميزات (أيقونات) ===== */
.feature-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:12px;padding:0 18px 8px}
.feature{background:linear-gradient(160deg,#1d2440,#161b2e);border:1px solid #2a3257;border-radius:16px;padding:14px 8px;text-align:center;transition:.2s}
.feature:hover{transform:translateY(-3px);border-color:var(--accent)}
.feature .fi{font-size:30px;display:block;margin-bottom:6px}
.feature .fl{font-size:12px;color:var(--text);font-weight:600}
.feature .fb{display:block;font-size:10px;color:var(--accent2);margin-top:3px}

/* ===== عجلة الحظ ===== */
.wheel-stage{display:flex;flex-direction:column;align-items:center;gap:18px;padding:10px}
.wheel-outer{position:relative;width:300px;max-width:86vw;aspect-ratio:1/1}
.wheel-outer::before{content:"";position:absolute;inset:-10px;border-radius:50%;background:conic-gradient(from 0deg,#6c5ce7,#00d2a0,#ffd86b,#ff5c5c,#6c5ce7);filter:blur(14px);opacity:.55;animation:spin 6s linear infinite;z-index:0}
.wheel{position:relative;z-index:1;width:100%;height:100%;border-radius:50%;border:8px solid #ffd86b;box-shadow:0 0 40px rgba(255,216,107,.5),inset 0 0 30px rgba(0,0,0,.5);transition:transform 5s cubic-bezier(.17,.67,.12,1)}
.wheel-pointer{position:absolute;top:-6px;left:50%;transform:translateX(-50%);z-index:3;font-size:34px;filter:drop-shadow(0 3px 4px rgba(0,0,0,.6))}
.wheel-hub{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:2;width:64px;height:64px;border-radius:50%;background:radial-gradient(circle,#ffd86b,#b8860b);display:flex;align-items:center;justify-content:center;font-weight:800;color:#3a2a00;border:4px solid #fff;box-shadow:0 0 20px rgba(255,216,107,.7)}
.wheel-result{font-size:20px;font-weight:800;min-height:28px;text-align:center;background:linear-gradient(135deg,#ffd86b,#00d2a0);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.confetti{position:fixed;top:-10px;width:10px;height:14px;z-index:400;pointer-events:none;animation:fall linear forwards}
@keyframes fall{to{transform:translateY(105vh) rotate(540deg);opacity:.2}}

/* ===== مركز الإحالة + المتصدرين ===== */
.ref-card{background:linear-gradient(135deg,#3d2c8d,#6c5ce7 60%,#00d2a0);border-radius:var(--radius);padding:20px;margin-bottom:14px}
.ref-link{display:flex;gap:8px;margin-top:12px}
.ref-link input{flex:1;padding:11px;border-radius:10px;border:none;background:rgba(0,0,0,.25);color:#fff;font-size:13px}
.lb-row{display:flex;align-items:center;gap:12px;padding:12px 4px;border-bottom:1px solid #232a45}
.lb-rank{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;background:#232a45;flex-shrink:0}
.lb-rank.r1{background:linear-gradient(135deg,#ffd86b,#b8860b);color:#3a2a00}
.lb-rank.r2{background:linear-gradient(135deg,#d8d8e8,#9a9ab0);color:#22232e}
.lb-rank.r3{background:linear-gradient(135deg,#e0a479,#a9603a);color:#2a1408}
.lb-row img{width:34px;height:34px;border-radius:50%}
</style>
</head>
<body>
<div id="preloader">
  <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="logo" onerror="this.outerHTML='<?= addslashes(brand_logo_svg(64)) ?>'"><?php else: ?><?= brand_logo_svg(64) ?><?php endif; ?>
  <div class="spinner"></div>
  <div style="color:var(--muted);font-size:13px">جاري التحميل...</div>
</div>

<div class="topbar">
  <button class="burger" onclick="toggleSidebar()">☰</button>
  <a href="?" class="brand"><?php if ($logo): ?><img src="<?= e($logo) ?>" onerror="this.outerHTML='<?= addslashes(brand_logo_svg(30)) ?>'"><?php else: ?><?= brand_logo_svg(30) ?><?php endif; ?> <?= e($siteName) ?></a>
  <div class="grow"></div>
  <?php if ($user): ?>
    <?php $u_usd = points_to_usd($user['points']); ?>
    <a href="?page=wallet" class="coin-chip" title="رصيدك">🪙 <?= number_format((int)$user['points']) ?> <small>≈ <?= e($u_usd) ?>$</small></a>
    <div class="user-chip">
      <?php if ($user['avatar']): ?><img src="<?= e($user['avatar']) ?>" onerror="this.style.display='none'"><?php endif; ?>
      <span><?= e($user['name']) ?></span>
    </div>
  <?php else: ?>
    <a href="<?= e(google_login_url()) ?>" class="btn btn-primary">تسجيل الدخول بجوجل</a>
  <?php endif; ?>
</div>

<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>
<div class="sidebar" id="sidebar">
  <div class="sb-head"><strong><?= e($siteName) ?></strong><button class="burger" onclick="toggleSidebar()">✕</button></div>
  <nav>
    <a href="?">🏠 الرئيسية</a>
    <a href="?page=earn">🪙 اكسب عملات (كابتشا)</a>
    <a href="?page=watch">📺 شاهد إعلان واربح</a>
    <a href="?page=wheel">🎡 عجلة الحظ</a>
    <a href="?page=tasks">📋 المهام اليومية</a>
    <a href="?page=referral">🎁 مركز الإحالة</a>
    <a href="?page=leaderboard">🏆 المتصدرون</a>
    <a href="?page=chats">💬 مجموعات الدردشة</a>
    <a href="?page=wallet">💳 محفظتي</a>
    <a href="?page=orders">📦 طلباتي</a>
    <a href="?page=privacy">🔒 سياسة الخصوصية</a>
    <a href="?page=terms">📜 شروط الاستخدام</a>
    <?php if (is_admin()): ?><a href="?page=admin">🎛️ لوحة الإدارة</a><?php endif; ?>
    <?php if ($user): ?><a href="?action=logout" style="color:var(--danger)">🚪 تسجيل الخروج</a><?php endif; ?>
    <div style="height:90px"></div>
  </nav>
</div>

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
    $banners = db()->query("SELECT * FROM banners WHERE active=1 ORDER BY sort_order, id")->fetchAll();
    $products = db()->query("SELECT * FROM products WHERE status='active' ORDER BY id DESC")->fetchAll();
    $cats = db()->query("SELECT * FROM categories ORDER BY sort_order, name")->fetchAll();
    $sizeHint = setting('banner_size_hint', '1200×400');
    // نضمن 3 شرائح كحد أدنى (placeholders تحمل القياس المطلوب)
    $slides = $banners;
    while (count($slides) < 3) {
        $i = count($slides) + 1;
        $slides[] = ['image' => '', 'link' => '', 'title' => "بنر إعلاني رقم $i", 'size_label' => $sizeHint, '_ph' => true];
    }
    $newsLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', setting('news_ticker', '')))));
    ?>
    <!-- ===== كاروسيل البنرات الثلاثة (يتحرك كل 5 ثوانٍ) ===== -->
    <div class="bnr-wrap" id="bnrWrap">
      <div class="bnr-track" id="bnrTrack">
        <?php foreach ($slides as $b): ?>
          <a class="bnr-slide" href="<?= e($b['link'] ?: '#') ?>">
            <?php if (!empty($b['image'])): ?>
              <img src="<?= e($b['image']) ?>" loading="lazy" alt="<?= e($b['title'] ?? 'بنر') ?>" onerror="this.parentNode.classList.add('noimg');this.remove()">
            <?php else: ?>
              <div class="bnr-ph">
                <div style="font-size:18px;font-weight:800"><?= e($b['title'] ?: 'بنر إعلاني / أخبار') ?></div>
                <div class="sz">📐 القياس المطلوب: <?= e($b['size_label'] ?: $sizeHint) ?></div>
              </div>
            <?php endif; ?>
            <?php if (!empty($b['title']) && !empty($b['image'])): ?><div class="bnr-cap"><?= e($b['title']) ?></div><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="bnr-dots" id="bnrDots">
        <?php for ($i = 0; $i < count($slides); $i++): ?><span class="<?= $i === 0 ? 'on' : '' ?>" onclick="goSlide(<?= $i ?>)"></span><?php endfor; ?>
      </div>
    </div>

    <!-- ===== شريط الأخبار المتحرك ===== -->
    <?php if ($newsLines): ?>
    <div class="news-bar">
      <div class="news-live"><span class="dot"></span> مباشر</div>
      <div class="news-track-wrap">
        <div class="news-track">
          <?php for ($r = 0; $r < 2; $r++): foreach ($newsLines as $n): ?><span class="item"><?= e($n) ?></span><?php endforeach; endfor; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ===== شبكة الميزات (أيقونات) ===== -->
    <div class="feature-grid">
      <a class="feature" href="?page=wheel"><span class="fi">🎡</span><span class="fl">عجلة الحظ</span><span class="fb">جوائز ضخمة</span></a>
      <a class="feature" href="?page=earn"><span class="fi">🪙</span><span class="fl">كابتشا</span><span class="fb">+<?= e(setting('captcha_reward')) ?></span></a>
      <a class="feature" href="?page=watch"><span class="fi">📺</span><span class="fl">شاهد إعلان</span><span class="fb">+<?= e(setting('ad_watch_reward')) ?></span></a>
      <a class="feature" href="?page=tasks"><span class="fi">📋</span><span class="fl">المهام</span><span class="fb">يومية</span></a>
      <a class="feature" href="?page=referral"><span class="fi">🎁</span><span class="fl">ادعُ صديق</span><span class="fb">+<?= e(setting('referral_reward')) ?></span></a>
      <a class="feature" href="?page=leaderboard"><span class="fi">🏆</span><span class="fl">المتصدرون</span></a>
      <a class="feature" href="?page=chats"><span class="fi">💬</span><span class="fl">المجموعات</span></a>
      <a class="feature" href="?page=wallet"><span class="fi">💳</span><span class="fl">محفظتي</span></a>
    </div>

    <!-- ===== مجموعة الأقسام (منسدلة) ===== -->
    <?php if ($cats): ?>
    <div class="group-box" id="catGroup">
      <div class="group-head" onclick="document.getElementById('catGroup').classList.toggle('open')">
        <span>🗂️ الأقسام</span><span class="chev">▾</span>
      </div>
      <div class="group-body">
        <span class="chip on" onclick="filterCat(this,0)">الكل</span>
        <?php foreach ($cats as $c): ?><span class="chip" onclick="filterCat(this,<?= (int)$c['id'] ?>)"><?= e($c['name']) ?></span><?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="section-title">🛍️ أحدث المنتجات</div>
    <?php if (!$products): ?>
      <div class="empty">لا توجد منتجات حالياً، تابعنا قريباً 🚀</div>
    <?php else: ?>
      <div class="grid" id="productGrid">
        <?php foreach ($products as $p): ?>
          <div class="card" data-cat="<?= (int)$p['category_id'] ?>">
            <?php if ($p['tag']): ?><span class="tag"><?= e($p['tag']) ?></span><?php endif; ?>
            <div class="pimg-wrap">
              <?php if ($p['image']): ?>
                <img loading="lazy" src="<?= e($p['image']) ?>" alt="<?= e($p['name']) ?>" onerror="this.parentNode.innerHTML='<span class=ph-icon><?= e($p['icon'] ?: '🛍️') ?></span>'">
              <?php else: ?>
                <span class="ph-icon"><?= e($p['icon'] ?: '🛍️') ?></span>
              <?php endif; ?>
            </div>
            <h3><?= e($p['name']) ?></h3>
            <div class="price-row">
              <span class="price"><?= e($p['price']) ?>$</span>
              <?php if ($p['old_price']): ?><span class="old"><?= e($p['old_price']) ?>$</span><?php endif; ?>
            </div>
            <button class="btn buy" onclick="buyProduct(<?= (int)$p['id'] ?>)">🛒 طلب شراء</button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php
    break;

case 'earn':
    if (!$user) { echo '<div class="empty">سجّل الدخول لتبدأ بكسب العملات.</div>'; break; }
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
      <p style="color:var(--muted);font-size:12px;margin-top:10px">📺 يُعرض إعلان قصير (<?= (int)setting('captcha_ad_seconds', 5) ?> ثوانٍ) قبل التحقق لدعم استمرار المنصة.</p>
    </div>
    <?php
    break;

case 'watch':
    if (!$user) { echo '<div class="empty">سجّل الدخول لمشاهدة الإعلانات وكسب العملات.</div>'; break; }
    $day = date('Y-m-d');
    $st = db()->prepare("SELECT count FROM ad_watch_logs WHERE user_id=? AND day=?");
    $st->execute([$user['id'], $day]);
    $doneAds = $st->fetch()['count'] ?? 0;
    $maxAds = (int)setting('ad_watch_max_per_day', 20);
    $adReward = (int)setting('ad_watch_reward', 50);
    $adSecs = (int)setting('ad_watch_seconds', 30);
    ?>
    <div class="admin-box" style="margin-top:18px;text-align:center">
      <h2>📺 شاهد إعلان واربح المزيد</h2>
      <p style="color:var(--muted);margin:10px 0">شاهد إعلاناً لمدة <?= $adSecs ?> ثانية واحصل على <strong style="color:var(--accent2)"><?= $adReward ?> عملة</strong>.</p>
      <p style="color:var(--muted);font-size:13px">المتبقي اليوم: <?= max(0, $maxAds - $doneAds) ?>/<?= $maxAds ?></p>
      <div style="font-size:64px;margin:18px 0">🎬</div>
      <button class="btn btn-success" id="watchBtn" style="width:100%" onclick="watchAd(<?= $adSecs ?>)">▶️ ابدأ مشاهدة الإعلان</button>
    </div>
    <?php
    break;

case 'wheel':
    if (!$user) { echo '<div class="empty">سجّل الدخول لتدوير عجلة الحظ.</div>'; break; }
    $prizes = array_values(array_filter(array_map('intval', explode(',', setting('wheel_prizes', '0,5,10,20,50,100')))));
    if (!$prizes) $prizes = [0, 5, 10, 20, 50, 100];
    $cost = (int)setting('wheel_cost', 20);
    $n = count($prizes);
    $seg = 360 / $n;
    $cols = ['#6c5ce7', '#00d2a0', '#ffd86b', '#ff5c5c', '#a08bff', '#33c1ff', '#ff8fcf', '#ffd86b'];
    $grad = [];
    for ($i = 0; $i < $n; $i++) {
        $c = $cols[$i % count($cols)];
        $grad[] = "$c " . ($i * $seg) . "deg " . (($i + 1) * $seg) . "deg";
    }
    ?>
    <div class="section-title">🎡 عجلة الحظ</div>
    <div class="admin-box wheel-stage">
      <p style="color:var(--muted)">كل دورة تكلّف <strong style="color:#ffd86b"><?= $cost ?> عملة</strong> — العب بلا حدود!</p>
      <div class="wheel-outer">
        <div class="wheel-pointer">🔻</div>
        <div class="wheel" id="wheel" style="background:conic-gradient(<?= implode(',', $grad) ?>)">
          <?php for ($i = 0; $i < $n; $i++):
            $ang = $i * $seg + $seg / 2; ?>
            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(<?= $ang ?>deg);width:50%;height:0">
              <span style="position:absolute;right:14px;top:-10px;transform:rotate(90deg);font-weight:800;font-size:15px;color:#10131f;text-shadow:0 1px 1px rgba(255,255,255,.4)"><?= $prizes[$i] ?></span>
            </div>
          <?php endfor; ?>
        </div>
        <div class="wheel-hub">SPIN</div>
      </div>
      <div class="wheel-result" id="wheelResult"></div>
      <button class="btn btn-success" id="spinBtn" style="width:100%;font-size:16px" onclick="spinWheel()">🎯 أدِر العجلة (<?= $cost ?> 🪙)</button>
      <p style="color:var(--muted);font-size:12px">رصيدك الحالي: <span id="wheelBal"><?= (int)$user['points'] ?></span> عملة</p>
    </div>
    <script>window.WHEEL_SEG = <?= $seg ?>; window.WHEEL_N = <?= $n ?>;</script>
    <?php
    break;

case 'referral':
    if (!$user) { echo '<div class="empty">سجّل الدخول لعرض رابط الإحالة.</div>'; break; }
    $refUrl = (SITE_URL ?: '') . '/?ref=' . $user['ref_code'];
    $invited = db()->prepare("SELECT COUNT(*) c FROM users WHERE referred_by=?");
    $invited->execute([$user['id']]);
    $invitedCount = $invited->fetch()['c'];
    $refReward = (int)setting('referral_reward', 100);
    ?>
    <div class="section-title">🎁 مركز الإحالة</div>
    <div style="padding:0 18px">
      <div class="ref-card">
        <div style="font-size:22px;font-weight:800">ادعُ أصدقاءك واربح <?= $refReward ?> عملة!</div>
        <p style="opacity:.9;margin-top:6px">يحصل صديقك على مكافأة ترحيب أيضاً عند التسجيل عبر رابطك.</p>
        <div class="ref-link">
          <input id="refInput" value="<?= e($refUrl) ?>" readonly>
          <button class="btn btn-success" onclick="copyRef()">📋 نسخ</button>
        </div>
        <button class="btn btn-ghost" style="margin-top:8px;width:100%" onclick="shareRef('<?= e($refUrl) ?>')">📤 مشاركة الرابط</button>
      </div>
      <div class="admin-box">
        <div style="display:flex;justify-content:space-around;text-align:center">
          <div><div style="font-size:26px;font-weight:800;color:var(--accent2)"><?= (int)$invitedCount ?></div><div style="color:var(--muted);font-size:13px">صديق مدعو</div></div>
          <div><div style="font-size:26px;font-weight:800;color:#ffd86b"><?= (int)$invitedCount * $refReward ?></div><div style="color:var(--muted);font-size:13px">عملة مكتسبة</div></div>
          <div><div style="font-size:26px;font-weight:800;color:var(--accent)"><?= e($user['ref_code']) ?></div><div style="color:var(--muted);font-size:13px">رمزك</div></div>
        </div>
      </div>
    </div>
    <?php
    break;

case 'leaderboard':
    $top = db()->query("SELECT name, avatar, points FROM users WHERE is_banned=0 ORDER BY points DESC LIMIT 30")->fetchAll();
    ?>
    <div class="section-title">🏆 قائمة المتصدرين</div>
    <div class="admin-box">
      <?php if (!$top): ?><div class="empty">لا يوجد متصدرون بعد.</div><?php endif; ?>
      <?php foreach ($top as $i => $t): $rank = $i + 1; ?>
        <div class="lb-row">
          <div class="lb-rank <?= $rank <= 3 ? 'r' . $rank : '' ?>"><?= $rank <= 3 ? ['🥇','🥈','🥉'][$rank-1] : $rank ?></div>
          <?php if ($t['avatar']): ?><img src="<?= e($t['avatar']) ?>" onerror="this.style.display='none'"><?php endif; ?>
          <div style="flex:1;font-weight:600"><?= e($t['name'] ?: 'مستخدم') ?></div>
          <div style="color:#ffd86b;font-weight:800">🪙 <?= number_format((int)$t['points']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    break;

case 'chats':
    $groups = db()->query("SELECT * FROM chat_groups WHERE active=1 ORDER BY sort_order, id")->fetchAll();
    ?>
    <div class="section-title">💬 مجموعات الدردشة</div>
    <div class="admin-box">
      <?php if (!$groups): ?><div class="empty">لا توجد مجموعات حالياً.</div><?php endif; ?>
      <?php foreach ($groups as $g): ?>
        <a href="<?= e($g['link']) ?>" target="_blank" class="lb-row" style="text-decoration:none">
          <div class="lb-rank" style="font-size:20px"><?= e($g['icon'] ?: '💬') ?></div>
          <div style="flex:1"><div style="font-weight:700"><?= e($g['name']) ?></div><?php if ($g['members']): ?><div style="color:var(--muted);font-size:12px">👥 <?= e($g['members']) ?> عضو</div><?php endif; ?></div>
          <span class="btn btn-primary">انضمام</span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php
    break;

case 'tasks':
    if (!$user) { echo '<div class="empty">سجّل الدخول لعرض المهام.</div>'; break; }
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
    if (!$user) { echo '<div class="empty">سجّل الدخول لعرض محفظتك.</div>'; break; }
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
    if (!$user) { echo '<div class="empty">سجّل الدخول لعرض طلباتك.</div>'; break; }
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

case 'admin':
    require_admin();
    $tab = $_GET['tab'] ?? 'dashboard';
    ?>
    <div class="admin-tabs">
      <?php foreach (['dashboard'=>'📊 لوحة البيانات','products'=>'🛍️ المنتجات','orders'=>'📦 الطلبات','topups'=>'💵 طلبات الشحن','withdraws'=>'💸 طلبات السحب','wallets'=>'🏦 المحافظ','tasks'=>'📋 المهام','banners'=>'🖼️ البنرات','chats'=>'💬 المجموعات','ai'=>'🤖 OpenRouter','pages'=>'📜 الصفحات','users'=>'👥 المستخدمون','settings'=>'⚙️ الإعدادات'] as $k=>$label): ?>
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
        <p style="color:var(--muted);font-size:13px;margin-bottom:10px">📐 القياس الموصى به للبنرات الثلاثة: <strong><?= e(setting('banner_size_hint')) ?></strong>. تتحرك تلقائياً كل 5 ثوانٍ في الصفحة الرئيسية.</p>
        <form method="post" action="?action=admin_save_banner" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input name="image" placeholder="رابط صورة البنر" required>
          <input name="title" placeholder="عنوان/نص البنر (اختياري)">
          <input name="link" placeholder="رابط عند الضغط (اختياري)">
          <input name="size_label" placeholder="القياس مثل 1200×400" value="<?= e(setting('banner_size_hint')) ?>">
          <button class="btn btn-primary">إضافة بنر</button>
        </form>
      </div>
      <div class="admin-box">
        <?php foreach ($banners as $b): ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
          <img src="<?= e($b['image']) ?>" style="width:80px;height:40px;object-fit:cover;border-radius:6px" onerror="this.style.opacity=.3">
          <span style="flex:1"><strong><?= e($b['title'] ?? '') ?></strong><br><small style="color:var(--muted)"><?= e($b['link']) ?> · <?= e($b['size_label'] ?? '') ?></small></span>
          <form method="post" action="?action=admin_delete_banner"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-danger">🗑️</button></form>
        </div>
        <?php endforeach; ?>
      </div>

    <?php elseif ($tab === 'chats'):
        $groups = db()->query("SELECT * FROM chat_groups ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <h3>➕ إضافة مجموعة دردشة</h3>
        <form method="post" action="?action=admin_save_chat_group" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input name="name" placeholder="اسم المجموعة" required>
          <input name="icon" placeholder="إيقونة (emoji)" value="💬">
          <input name="link" placeholder="رابط الانضمام (تيليجرام/واتساب)" required>
          <input name="members" placeholder="عدد الأعضاء (اختياري)">
          <button class="btn btn-primary">حفظ</button>
        </form>
      </div>
      <div class="admin-box">
        <table>
          <tr><th>المجموعة</th><th>الرابط</th><th>الأعضاء</th><th></th></tr>
          <?php foreach ($groups as $g): ?>
          <tr>
            <td><?= e($g['icon']) ?> <?= e($g['name']) ?></td>
            <td style="font-family:monospace;font-size:11px"><?= e($g['link']) ?></td>
            <td><?= e($g['members']) ?></td>
            <td><form method="post" action="?action=admin_delete_chat_group" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button class="btn btn-danger">🗑️</button></form></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'ai'): ?>
      <div class="admin-box">
        <h3>🤖 إعدادات OpenRouter AI</h3>
        <form method="post" action="?action=admin_save_settings" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <label style="grid-column:1/-1">مفتاح OpenRouter API
            <input name="openrouter_key" placeholder="sk-or-..." value="<?= e(setting('openrouter_key')) ?>"></label>
          <label>الموديل الافتراضي<input name="openrouter_model" id="orModelDefault" value="<?= e(setting('openrouter_model')) ?>"></label>
          <button class="btn btn-primary" style="align-self:end">💾 حفظ المفتاح</button>
        </form>
        <p style="color:var(--muted);font-size:12px">احصل على المفتاح من <span style="color:var(--accent2)">openrouter.ai/keys</span>. يمكنك أيضاً ضبطه في config.php عبر OPENROUTER_KEY.</p>
      </div>
      <div class="admin-box">
        <h3>🧪 اختبار الاتصال والموديلات</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
          <button class="btn btn-ghost" onclick="orLoadModels()">📥 جلب كل الموديلات</button>
        </div>
        <select id="orModelSelect" style="width:100%;padding:10px;border-radius:10px;border:1px solid #2a3050;background:#11152a;color:#fff;margin-bottom:10px"><option value="">— اختر موديل بعد الجلب —</option></select>
        <div id="orModelsInfo" style="color:var(--muted);font-size:12px;margin-bottom:10px"></div>
        <input id="orPrompt" placeholder="رسالة اختبار" value="قل مرحباً بكلمة واحدة" style="width:100%;padding:10px;border-radius:10px;border:1px solid #2a3050;background:#11152a;color:#fff;margin-bottom:10px">
        <button class="btn btn-success" onclick="orTest()">🚀 اختبر الموديل المحدد</button>
        <pre id="orResult" style="white-space:pre-wrap;background:#11152a;border-radius:10px;padding:12px;margin-top:12px;min-height:40px;font-size:13px;color:#cfe9df"></pre>
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
          <label>🎡 تكلفة دورة العجلة (عملات)<input name="wheel_cost" value="<?= e(setting('wheel_cost')) ?>"></label>
          <label>🎡 جوائز العجلة (مفصولة بفاصلة)<input name="wheel_prizes" value="<?= e(setting('wheel_prizes')) ?>"></label>
          <label>🎁 مكافأة الإحالة<input name="referral_reward" value="<?= e(setting('referral_reward')) ?>"></label>
          <label>📺 مكافأة مشاهدة الإعلان<input name="ad_watch_reward" value="<?= e(setting('ad_watch_reward')) ?>"></label>
          <label>📺 مدة الإعلان (ثوانٍ)<input name="ad_watch_seconds" value="<?= e(setting('ad_watch_seconds')) ?>"></label>
          <label>📺 أقصى مشاهدات باليوم<input name="ad_watch_max_per_day" value="<?= e(setting('ad_watch_max_per_day')) ?>"></label>
          <label>⏱️ مدة إعلان الكابتشا (ثوانٍ)<input name="captcha_ad_seconds" value="<?= e(setting('captcha_ad_seconds')) ?>"></label>
          <label>📐 قياس البنرات (نص إرشادي)<input name="banner_size_hint" value="<?= e(setting('banner_size_hint')) ?>"></label>
          <label style="grid-column:1/-1">📰 شريط الأخبار (كل سطر = خبر)<textarea name="news_ticker" rows="4"><?= e(setting('news_ticker')) ?></textarea></label>
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
const CAPTCHA_AD_SECS = <?= (int)setting('captcha_ad_seconds', 5) ?>;

/* إعلان إجباري قصير (interstitial) — يستدعي AdMob في نسخة APK */
function forcedAd(secs, cb){
  if (window.Android && window.Android.showInterstitial){ window.Android.showInterstitial(); cb(); return; }
  let ov = document.createElement('div');
  ov.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:600;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;color:#fff;text-align:center;padding:20px';
  ov.innerHTML='<div style="font-size:13px;opacity:.7">إعلان</div><div style="font-size:50px">📺</div><div style="font-size:18px;font-weight:700">إعلان برعاية Yassota</div><div id="adCount" style="font-size:14px;opacity:.85"></div>';
  document.body.appendChild(ov);
  let r=secs; const c=ov.querySelector('#adCount');
  c.textContent='يمكنك المتابعة بعد '+r+' ثانية';
  const iv=setInterval(()=>{ r--; c.textContent= r>0 ? ('يمكنك المتابعة بعد '+r+' ثانية') : 'جارٍ المتابعة...'; if(r<=0){clearInterval(iv);ov.remove();cb();} },1000);
}
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
  const ans = document.getElementById('captchaAnswer').value.trim();
  if (!ans){ toast('أدخل الرقم أولاً'); return; }
  // إعلان إجباري قصير قبل التحقق
  forcedAd(CAPTCHA_AD_SECS, ()=>{
    const d = new FormData(); d.append('answer', ans);
    post('api_solve_captcha', d).then(res => {
      toast(res.msg);
      document.getElementById('captchaAnswer').value = '';
      if (res.ok) loadCaptcha();
    });
  });
}
function startTask(id, url, seconds){
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
  const d = new FormData();
  d.append('type', document.getElementById('wType').value);
  d.append('address', document.getElementById('wAddr').value);
  post('api_save_wallet', d).then(res => toast(res.msg));
}
function requestWithdraw(){
  if (!confirm('تأكيد طلب سحب الرصيد بالكامل؟')) return;
  post('api_request_withdraw', new FormData()).then(res => { toast(res.msg); if (res.ok) setTimeout(() => location.reload(), 1200); });
}
function requestTopup(){
  const d = new FormData();
  d.append('wallet_id', document.getElementById('topupWallet').value);
  d.append('amount', document.getElementById('topupAmount').value);
  d.append('note', document.getElementById('topupNote').value);
  post('api_request_topup', d).then(res => toast(res.msg));
}

/* ===== كاروسيل البنرات (كل 5 ثوانٍ) ===== */
(function(){
  const track = document.getElementById('bnrTrack');
  if (!track) return;
  const slides = track.children.length;
  let idx = 0;
  const dots = document.querySelectorAll('#bnrDots span');
  window.goSlide = function(i){ idx = (i + slides) % slides; render(); reset(); };
  function render(){
    track.style.transform = 'translateX(' + (idx * 100) + '%)'; // RTL: موجب لليسار
    dots.forEach((d,k)=>d.classList.toggle('on', k===idx));
  }
  let timer;
  function reset(){ clearInterval(timer); timer = setInterval(()=>{ idx=(idx+1)%slides; render(); }, 5000); }
  render(); reset();
})();

/* ===== تصفية الأقسام ===== */
function filterCat(el, cat){
  document.querySelectorAll('#catGroup .chip').forEach(c=>c.classList.remove('on'));
  el.classList.add('on');
  document.querySelectorAll('#productGrid .card').forEach(c=>{
    c.style.display = (cat===0 || +c.dataset.cat===cat) ? '' : 'none';
  });
}

/* ===== مشاهدة إعلان مكافأ (مع AdMob في نسخة APK) ===== */
function watchAd(secs){
  const btn = document.getElementById('watchBtn');
  // في تطبيق APK: استدعِ هنا إعلان AdMob المكافأ عبر الجسر، ثم نادِ grantAd() عند الإكمال.
  if (window.Android && window.Android.showRewardedAd){ window.Android.showRewardedAd(); return; }
  btn.disabled = true; let r = secs;
  const iv = setInterval(()=>{
    r--; btn.textContent = '⏳ جارٍ العرض... ' + r + ' ث';
    if (r<=0){ clearInterval(iv); grantAd(); }
  }, 1000);
  btn.textContent = '⏳ جارٍ العرض... ' + r + ' ث';
}
function grantAd(){
  post('api_watch_ad', new FormData()).then(res=>{ toast(res.msg); setTimeout(()=>location.reload(), 1400); });
}
window.grantAd = grantAd; // متاح لجسر AdMob في APK

/* ===== عجلة الحظ ===== */
let wheelSpinning = false, wheelRot = 0;
function spinWheel(){
  if (wheelSpinning) return; wheelSpinning = true;
  const btn = document.getElementById('spinBtn'); btn.disabled = true;
  document.getElementById('wheelResult').textContent = '';
  post('api_spin_wheel', new FormData()).then(res=>{
    if (!res.ok){ toast(res.msg); wheelSpinning=false; btn.disabled=false; return; }
    const seg = window.WHEEL_SEG, target = res.index;
    // المؤشر بالأعلى؛ ندوّر 6 لفّات كاملة + الوصول لمنتصف القطاع الفائز
    const dest = 360*6 + (360 - (target*seg + seg/2));
    wheelRot += dest;
    const w = document.getElementById('wheel');
    w.style.transform = 'rotate(' + wheelRot + 'deg)';
    setTimeout(()=>{
      document.getElementById('wheelResult').textContent = res.msg;
      document.getElementById('wheelBal').textContent = res.balance;
      if (res.prize>0) confetti();
      toast(res.msg);
      wheelSpinning=false; btn.disabled=false;
    }, 5200);
  });
}
function confetti(){
  const cols=['#6c5ce7','#00d2a0','#ffd86b','#ff5c5c','#33c1ff'];
  for(let i=0;i<60;i++){
    const c=document.createElement('div'); c.className='confetti';
    c.style.left=Math.random()*100+'vw';
    c.style.background=cols[i%cols.length];
    c.style.animationDuration=(2+Math.random()*2)+'s';
    c.style.animationDelay=(Math.random())+'s';
    document.body.appendChild(c);
    setTimeout(()=>c.remove(),4500);
  }
}

/* ===== الإحالة ===== */
function copyRef(){
  const i=document.getElementById('refInput'); i.select();
  navigator.clipboard.writeText(i.value).then(()=>toast('تم نسخ الرابط ✅')).catch(()=>{document.execCommand('copy');toast('تم النسخ');});
}
function shareRef(url){
  if (navigator.share){ navigator.share({title:'انضم إلي', url}); }
  else copyRef();
}

/* ===== OpenRouter (لوحة الإدارة) ===== */
function orLoadModels(){
  const sel=document.getElementById('orModelSelect'), info=document.getElementById('orModelsInfo');
  info.textContent='⏳ جارٍ الجلب...';
  post('admin_openrouter_models', new FormData()).then(res=>{
    if(!res.ok){ info.textContent='❌ '+(res.error||'فشل'); return; }
    sel.innerHTML='';
    res.models.forEach(m=>{ const o=document.createElement('option'); o.value=m.id; o.textContent=m.name+' ('+m.id+')'; sel.appendChild(o); });
    const def=document.getElementById('orModelDefault'); if(def&&def.value) sel.value=def.value;
    info.textContent='✅ تم جلب '+res.count+' موديل.';
  });
}
function orTest(){
  const out=document.getElementById('orResult');
  const model=document.getElementById('orModelSelect').value || document.getElementById('orModelDefault').value;
  out.textContent='⏳ جارٍ الاختبار على: '+model;
  const d=new FormData(); d.append('model', model); d.append('prompt', document.getElementById('orPrompt').value);
  post('admin_openrouter_test', d).then(res=>{
    out.textContent = res.ok ? ('✅ ['+res.model+']\n\n'+res.reply) : ('❌ '+(res.error||'فشل')+' (الموديل: '+res.model+')');
  });
}

/* ===== Service Worker (لتثبيت التطبيق وAPK) ===== */
if ('serviceWorker' in navigator){ navigator.serviceWorker.register('?action=sw').catch(()=>{}); }
</script>
</body>
</html>
