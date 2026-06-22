<?php
/**
 * ============================================================================
 *  YASSOTA  —  منصة متكاملة (متجر + ربح نقاط) في ملف واحد index.php
 * ----------------------------------------------------------------------------
 *  • قاعدة البيانات تُنشأ ذاتياً (SQLite) من نفس الملف — لا حاجة لملفات أخرى.
 *  • لوحة إدارة كاملة تظهر فقط لإيميل المالك.
 *  • منتجات / طلبات شراء / محافظ / سحب / مهام / كابتشا محلية / إعلانات Monetag.
 *  • تسجيل دخول جوجل (OAuth) + جلسة أسبوع، مع وضع دخول بالإيميل للاختبار.
 *  • صفحات سياسة الخصوصية والشروط + موافقة الكوكيز مرة واحدة.
 *
 *  للنشر: ارفع هذا الملف على أي استضافة PHP تدعم PDO SQLite. سيُنشئ DB تلقائياً.
 * ============================================================================
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('UTC');

/* ========================= 1) الإعدادات العامة ========================= */
const ADMIN_EMAIL   = 'sadoo1234999@gmail.com';   // إيميل المالك (لوحة الإدارة)
const SITE_NAME     = 'Yassota';
const COIN_NAME     = 'Yassota';                  // اسم العملة الافتراضية
const SESSION_DAYS  = 7;                          // مدة حفظ الجلسة (أسبوع)
const MIN_WITHDRAW_USD = 25.0;                    // الحد الأدنى للسحب
const COINS_PER_USD = 1000;                       // 1000 نقطة = 1$ (قابل للتعديل من الإدارة)
const CAPTCHA_REWARD = 5;                         // نقاط الكابتشا الناجحة
const TASK_REWARD    = 10;                         // نقاط افتراضية للمهمة
const TASK_WAIT_SEC  = 15;                         // ثواني التحقق من زيارة الرابط
// توزيع أرباح الإعلانات: 95% للموقع، 5% للمستخدم
const USER_AD_SHARE  = 0.05;

// Google OAuth (ضع بياناتك هنا للتفعيل الحقيقي)
const GOOGLE_CLIENT_ID     = '';   // مثال: 1234.apps.googleusercontent.com
const GOOGLE_CLIENT_SECRET = '';
// Monetag
const MONETAG_ZONE_ID = '';        // ضع معرّف منطقة Monetag

const DB_FILE = __DIR__ . '/yassota.sqlite';

/* ========================= 2) الجلسة ========================= */
session_set_cookie_params([
    'lifetime' => SESSION_DAYS * 86400,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('YASSOTA_SESS');
session_start();

/* ========================= 3) قاعدة البيانات ========================= */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
        init_db($pdo);
    }
    return $pdo;
}

function init_db(PDO $pdo): void {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE,
        name TEXT,
        avatar TEXT,
        balance INTEGER DEFAULT 0,       -- نقاط
        is_admin INTEGER DEFAULT 0,
        is_banned INTEGER DEFAULT 0,
        ad_earned INTEGER DEFAULT 0,     -- ما ربحه المستخدم من الإعلانات
        created_at TEXT DEFAULT (datetime('now')),
        last_seen TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS products(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        icon TEXT DEFAULT '🛍️',
        description TEXT,
        category TEXT DEFAULT 'عام',
        price REAL NOT NULL DEFAULT 0,   -- بالنقاط
        old_price REAL DEFAULT 0,        -- لإظهار الخصم
        image TEXT,
        tag TEXT DEFAULT 'جديد',
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS orders(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        product_id INTEGER,
        status TEXT DEFAULT 'pending',   -- pending/approved/rejected
        note TEXT,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS wallets(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT,                       -- usdt/sham/btc...
        label TEXT,
        address TEXT,
        is_active INTEGER DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS topups(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        method TEXT,
        amount_usd REAL,
        txid TEXT,
        status TEXT DEFAULT 'pending',
        note TEXT,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS withdrawals(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        method TEXT,
        address TEXT,
        coins INTEGER,
        amount_usd REAL,
        status TEXT DEFAULT 'pending',
        note TEXT,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS tasks(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        url TEXT,
        reward INTEGER DEFAULT 10,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS task_done(
        user_id INTEGER, task_id INTEGER, day TEXT,
        PRIMARY KEY(user_id, task_id, day)
    );
    CREATE TABLE IF NOT EXISTS txns(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER, amount INTEGER, type TEXT, note TEXT,
        created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT);
    ");

    // قيم افتراضية
    $defaults = [
        'coins_per_usd'   => (string)COINS_PER_USD,
        'min_withdraw'    => (string)MIN_WITHDRAW_USD,
        'captcha_reward'  => (string)CAPTCHA_REWARD,
        'telegram_bot'    => '@YassotaBot',
        'telegram_info'   => 'انضم لبوت تيليجرام واربح نقاطاً يومية عبر المهام والإحالات.',
        'banner_text'     => 'منصة Yassota — تسوّق واربح نقاطاً حقيقية قابلة للسحب!',
        'seo_desc'        => 'Yassota منصة عربية للتسوق وربح المال عبر المهام والكابتشا والإحالات، سحب بالشام كاش وUSDT وبيتكوين.',
    ];
    $st = $pdo->prepare("INSERT OR IGNORE INTO settings(k,v) VALUES(?,?)");
    foreach ($defaults as $k => $v) $st->execute([$k, $v]);
}

function setting(string $k, ?string $def = null): ?string {
    $r = db()->prepare("SELECT v FROM settings WHERE k=?");
    $r->execute([$k]);
    $v = $r->fetchColumn();
    return $v === false ? $def : $v;
}
function set_setting(string $k, string $v): void {
    db()->prepare("INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=?")
        ->execute([$k, $v, $v]);
}

/* ========================= 4) أدوات مساعدة ========================= */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function coins_to_usd(int $coins): float {
    $rate = (int)setting('coins_per_usd', (string)COINS_PER_USD);
    return $rate > 0 ? round($coins / $rate, 4) : 0.0;
}
function usd_to_coins(float $usd): int {
    $rate = (int)setting('coins_per_usd', (string)COINS_PER_USD);
    return (int)round($usd * $rate);
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function check_csrf(): bool {
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
}
function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $r = db()->prepare("SELECT * FROM users WHERE id=?");
    $r->execute([$_SESSION['uid']]);
    $u = $r->fetch();
    if ($u) db()->prepare("UPDATE users SET last_seen=datetime('now') WHERE id=?")->execute([$u['id']]);
    return $u ?: null;
}
function is_admin(?array $u): bool {
    return $u && ((int)$u['is_admin'] === 1 || strtolower($u['email']) === strtolower(ADMIN_EMAIL));
}
function add_coins(int $uid, int $amount, string $type, string $note = ''): void {
    db()->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$amount, $uid]);
    db()->prepare("INSERT INTO txns(user_id,amount,type,note) VALUES(?,?,?,?)")
        ->execute([$uid, $amount, $type, $note]);
}
function json_out($data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function redirect(string $to): void { header("Location: $to"); exit; }

function login_user(string $email, string $name, string $avatar = ''): array {
    $pdo = db();
    $r = $pdo->prepare("SELECT * FROM users WHERE email=?");
    $r->execute([$email]);
    $u = $r->fetch();
    $admin = (strtolower($email) === strtolower(ADMIN_EMAIL)) ? 1 : 0;
    if (!$u) {
        $pdo->prepare("INSERT INTO users(email,name,avatar,is_admin) VALUES(?,?,?,?)")
            ->execute([$email, $name, $avatar, $admin]);
        $uid = (int)$pdo->lastInsertId();
        if ($admin) add_coins($uid, 0, 'welcome', 'حساب المالك');
    } else {
        $uid = (int)$u['id'];
        $pdo->prepare("UPDATE users SET name=?, avatar=?, is_admin=? WHERE id=?")
            ->execute([$name ?: $u['name'], $avatar ?: $u['avatar'], $admin ?: $u['is_admin'], $uid]);
    }
    $_SESSION['uid'] = $uid;
    return current_user();
}

/* ========================= 5) المعالجات (Actions / API) ========================= */
$action = $_GET['action'] ?? '';
$page   = $_GET['page'] ?? 'home';
$me     = current_user();

/* ---- Google OAuth ---- */
if ($action === 'google_login') {
    if (!GOOGLE_CLIENT_ID) redirect('?action=demo_form');
    $state = bin2hex(random_bytes(8));
    $_SESSION['oauth_state'] = $state;
    $redirect = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . '?action=google_callback';
    $params = http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account',
    ]);
    redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $params);
}
if ($action === 'google_callback') {
    if (($_GET['state'] ?? '') !== ($_SESSION['oauth_state'] ?? 'x')) redirect('?');
    $code = $_GET['code'] ?? '';
    $redirect = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . '?action=google_callback';
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code, 'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET, 'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
        ]),
    ]);
    $tok = json_decode((string)curl_exec($ch), true); curl_close($ch);
    if (!empty($tok['access_token'])) {
        $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok['access_token']]]);
        $info = json_decode((string)curl_exec($ch), true); curl_close($ch);
        if (!empty($info['email'])) {
            login_user($info['email'], $info['name'] ?? 'مستخدم', $info['picture'] ?? '');
        }
    }
    redirect('?');
}
// دخول تجريبي بالإيميل (يعمل بدون إعداد جوجل)
if ($action === 'demo_login' && $_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf()) {
    $email = trim(strtolower($_POST['email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        login_user($email, ucfirst(explode('@', $email)[0]));
    }
    redirect('?');
}
if ($action === 'logout') { session_destroy(); redirect('?'); }

/* ---- موافقة الكوكيز ---- */
if ($action === 'accept_cookies') { setcookie('policy_ok', '1', time() + 31536000, '/'); json_out(['ok' => true]); }

/* ---- كابتشا محلية: ربح نقاط ---- */
if ($action === 'captcha_new') {
    $a = random_int(1000, 9999);
    $_SESSION['captcha'] = $a;
    json_out(['code' => (string)$a]);
}
if ($action === 'captcha_verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$me) json_out(['ok' => false, 'msg' => 'سجّل الدخول أولاً']);
    if (!check_csrf()) json_out(['ok' => false, 'msg' => 'جلسة غير صالحة']);
    $input = trim($_POST['answer'] ?? '');
    if ((string)($_SESSION['captcha'] ?? '') !== '' && $input === (string)$_SESSION['captcha']) {
        unset($_SESSION['captcha']);
        $reward = (int)setting('captcha_reward', (string)CAPTCHA_REWARD);
        add_coins((int)$me['id'], $reward, 'captcha', 'كابتشا محلية');
        // حصة الإعلانات: 95% للموقع / 5% للمستخدم (تتبع فقط)
        db()->prepare("UPDATE users SET ad_earned = ad_earned + ? WHERE id=?")
            ->execute([$reward, $me['id']]);
        json_out(['ok' => true, 'reward' => $reward, 'msg' => "✅ حصلت على $reward نقطة!"]);
    }
    json_out(['ok' => false, 'msg' => '❌ الرقم غير صحيح، حاول مجدداً']);
}

/* ---- إتمام مهمة (بعد انتظار 15 ثانية) ---- */
if ($action === 'task_complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$me) json_out(['ok' => false, 'msg' => 'سجّل الدخول']);
    if (!check_csrf()) json_out(['ok' => false, 'msg' => 'جلسة غير صالحة']);
    $tid = (int)($_POST['task_id'] ?? 0);
    $started = (int)($_SESSION['task_start'][$tid] ?? 0);
    if (!$started || (time() - $started) < TASK_WAIT_SEC) {
        json_out(['ok' => false, 'msg' => '⏳ يجب البقاء ' . TASK_WAIT_SEC . ' ثانية في الرابط']);
    }
    $day = date('Y-m-d');
    $chk = db()->prepare("SELECT 1 FROM task_done WHERE user_id=? AND task_id=? AND day=?");
    $chk->execute([$me['id'], $tid, $day]);
    if ($chk->fetch()) json_out(['ok' => false, 'msg' => '✅ أنجزت هذه المهمة اليوم']);
    $t = db()->prepare("SELECT * FROM tasks WHERE id=? AND is_active=1");
    $t->execute([$tid]); $task = $t->fetch();
    if (!$task) json_out(['ok' => false, 'msg' => 'المهمة غير متاحة']);
    db()->prepare("INSERT INTO task_done(user_id,task_id,day) VALUES(?,?,?)")->execute([$me['id'], $tid, $day]);
    add_coins((int)$me['id'], (int)$task['reward'], 'task', 'مهمة: ' . $task['title']);
    json_out(['ok' => true, 'msg' => '✅ +' . $task['reward'] . ' نقطة!']);
}
if ($action === 'task_start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tid = (int)($_POST['task_id'] ?? 0);
    $_SESSION['task_start'][$tid] = time();
    json_out(['ok' => true, 'wait' => TASK_WAIT_SEC]);
}

/* ---- طلب شراء منتج ---- */
if ($action === 'buy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$me) json_out(['ok' => false, 'msg' => 'سجّل الدخول أولاً']);
    if (!check_csrf()) json_out(['ok' => false, 'msg' => 'جلسة غير صالحة']);
    $pid = (int)($_POST['product_id'] ?? 0);
    $p = db()->prepare("SELECT * FROM products WHERE id=? AND is_active=1");
    $p->execute([$pid]); $prod = $p->fetch();
    if (!$prod) json_out(['ok' => false, 'msg' => 'المنتج غير متاح']);
    if ((int)$me['balance'] < (int)$prod['price']) {
        json_out(['ok' => false, 'msg' => '❌ رصيدك غير كافٍ. اشحن رصيدك أو اربح نقاطاً.']);
    }
    db()->prepare("INSERT INTO orders(user_id,product_id) VALUES(?,?)")->execute([$me['id'], $pid]);
    json_out(['ok' => true, 'msg' => '✅ تم إرسال طلب الشراء للإدارة للموافقة.']);
}

/* ---- طلب شحن رصيد ---- */
if ($action === 'topup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$me) json_out(['ok' => false, 'msg' => 'سجّل الدخول']);
    if (!check_csrf()) json_out(['ok' => false, 'msg' => 'جلسة غير صالحة']);
    $method = $_POST['method'] ?? 'usdt';
    $amount = (float)($_POST['amount'] ?? 0);
    $txid   = trim($_POST['txid'] ?? '');
    if ($amount <= 0) json_out(['ok' => false, 'msg' => 'مبلغ غير صحيح']);
    db()->prepare("INSERT INTO topups(user_id,method,amount_usd,txid) VALUES(?,?,?,?)")
        ->execute([$me['id'], $method, $amount, $txid]);
    json_out(['ok' => true, 'msg' => '✅ تم إرسال طلب الشحن، بانتظار موافقة الإدارة.']);
}

/* ---- طلب سحب ---- */
if ($action === 'withdraw' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$me) json_out(['ok' => false, 'msg' => 'سجّل الدخول']);
    if (!check_csrf()) json_out(['ok' => false, 'msg' => 'جلسة غير صالحة']);
    $coins = (int)$me['balance'];
    $usd = coins_to_usd($coins);
    $minw = (float)setting('min_withdraw', (string)MIN_WITHDRAW_USD);
    if ($usd < $minw) json_out(['ok' => false, 'msg' => "❌ الحد الأدنى للسحب \${$minw}"]);
    $method  = $_POST['method'] ?? 'sham';
    $address = trim($_POST['address'] ?? '');
    if (strlen($address) < 4) json_out(['ok' => false, 'msg' => 'أدخل عنوان/رقم استلام صحيح']);
    db()->prepare("UPDATE users SET balance=0 WHERE id=?")->execute([$me['id']]);
    db()->prepare("INSERT INTO withdrawals(user_id,method,address,coins,amount_usd) VALUES(?,?,?,?,?)")
        ->execute([$me['id'], $method, $address, $coins, $usd]);
    db()->prepare("INSERT INTO txns(user_id,amount,type,note) VALUES(?,?,?,?)")
        ->execute([$me['id'], -$coins, 'withdraw', "طلب سحب \${$usd}"]);
    json_out(['ok' => true, 'msg' => '✅ تم إرسال طلب السحب للإدارة.']);
}

/* ========================= 6) معالجات الإدارة ========================= */
if (strpos($action, 'admin_') === 0) {
    if (!is_admin($me)) { http_response_code(403); exit('forbidden'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !check_csrf()) { http_response_code(400); exit('bad csrf'); }
    $pdo = db();

    switch ($action) {
        case 'admin_product_save':
            $id = (int)($_POST['id'] ?? 0);
            $fields = [
                trim($_POST['title'] ?? ''), trim($_POST['icon'] ?? '🛍️'),
                trim($_POST['description'] ?? ''), trim($_POST['category'] ?? 'عام'),
                (float)($_POST['price'] ?? 0), (float)($_POST['old_price'] ?? 0),
                trim($_POST['image'] ?? ''), trim($_POST['tag'] ?? 'جديد'),
                isset($_POST['is_active']) ? 1 : 0,
            ];
            if ($id) {
                $pdo->prepare("UPDATE products SET title=?,icon=?,description=?,category=?,price=?,old_price=?,image=?,tag=?,is_active=? WHERE id=?")
                    ->execute([...$fields, $id]);
            } else {
                $pdo->prepare("INSERT INTO products(title,icon,description,category,price,old_price,image,tag,is_active) VALUES(?,?,?,?,?,?,?,?,?)")
                    ->execute($fields);
            }
            redirect('?page=admin&tab=products&ok=1');
        case 'admin_product_del':
            $pdo->prepare("DELETE FROM products WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=products');
        case 'admin_order':
            $st = $_POST['status'] === 'approved' ? 'approved' : 'rejected';
            $oid = (int)$_POST['id'];
            $o = $pdo->prepare("SELECT o.*, p.price, p.title FROM orders o JOIN products p ON p.id=o.product_id WHERE o.id=?");
            $o->execute([$oid]); $order = $o->fetch();
            if ($order && $order['status'] === 'pending') {
                if ($st === 'approved') {
                    $u = $pdo->prepare("SELECT balance FROM users WHERE id=?");
                    $u->execute([$order['user_id']]); $bal = (int)$u->fetchColumn();
                    if ($bal >= (int)$order['price']) {
                        add_coins((int)$order['user_id'], -(int)$order['price'], 'purchase', 'شراء: ' . $order['title']);
                    } else { $st = 'rejected'; }
                }
                $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$st, $oid]);
            }
            redirect('?page=admin&tab=orders');
        case 'admin_topup':
            $st = $_POST['status'] === 'approved' ? 'approved' : 'rejected';
            $tid = (int)$_POST['id'];
            $t = $pdo->prepare("SELECT * FROM topups WHERE id=?"); $t->execute([$tid]); $tp = $t->fetch();
            if ($tp && $tp['status'] === 'pending') {
                if ($st === 'approved') add_coins((int)$tp['user_id'], usd_to_coins((float)$tp['amount_usd']), 'topup', 'شحن رصيد');
                $pdo->prepare("UPDATE topups SET status=? WHERE id=?")->execute([$st, $tid]);
            }
            redirect('?page=admin&tab=topups');
        case 'admin_withdraw':
            $st = $_POST['status'] === 'approved' ? 'approved' : 'rejected';
            $wid = (int)$_POST['id'];
            $w = $pdo->prepare("SELECT * FROM withdrawals WHERE id=?"); $w->execute([$wid]); $wd = $w->fetch();
            if ($wd && $wd['status'] === 'pending') {
                if ($st === 'rejected') add_coins((int)$wd['user_id'], (int)$wd['coins'], 'refund', 'استرداد سحب');
                $pdo->prepare("UPDATE withdrawals SET status=?, note=? WHERE id=?")
                    ->execute([$st, trim($_POST['note'] ?? ''), $wid]);
            }
            redirect('?page=admin&tab=withdrawals');
        case 'admin_wallet_save':
            $pdo->prepare("INSERT INTO wallets(type,label,address) VALUES(?,?,?)")
                ->execute([trim($_POST['type']), trim($_POST['label']), trim($_POST['address'])]);
            redirect('?page=admin&tab=wallets');
        case 'admin_wallet_del':
            $pdo->prepare("DELETE FROM wallets WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=wallets');
        case 'admin_task_save':
            $pdo->prepare("INSERT INTO tasks(title,url,reward) VALUES(?,?,?)")
                ->execute([trim($_POST['title']), trim($_POST['url']), (int)$_POST['reward']]);
            redirect('?page=admin&tab=tasks');
        case 'admin_task_del':
            $pdo->prepare("DELETE FROM tasks WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=tasks');
        case 'admin_user':
            $uid = (int)$_POST['id'];
            if ($_POST['do'] === 'ban')   $pdo->prepare("UPDATE users SET is_banned=1 WHERE id=?")->execute([$uid]);
            if ($_POST['do'] === 'unban') $pdo->prepare("UPDATE users SET is_banned=0 WHERE id=?")->execute([$uid]);
            if ($_POST['do'] === 'addpts') add_coins($uid, (int)$_POST['pts'], 'admin', 'إضافة من الإدارة');
            redirect('?page=admin&tab=users');
        case 'admin_settings':
            foreach (['coins_per_usd','min_withdraw','captcha_reward','telegram_bot','telegram_info','banner_text','seo_desc'] as $k) {
                if (isset($_POST[$k])) set_setting($k, trim($_POST[$k]));
            }
            redirect('?page=admin&tab=settings&ok=1');
    }
    exit;
}

/* ========================= 7) جلب البيانات للعرض ========================= */
$products = db()->query("SELECT * FROM products WHERE is_active=1 ORDER BY id DESC")->fetchAll();
$categories = db()->query("SELECT DISTINCT category FROM products WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
$tasks = db()->query("SELECT * FROM tasks WHERE is_active=1 ORDER BY id DESC")->fetchAll();
$wallets = db()->query("SELECT * FROM wallets WHERE is_active=1")->fetchAll();
$CSRF = csrf_token();
$policy_ok = isset($_COOKIE['policy_ok']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(SITE_NAME) ?> | تسوّق واربح نقاطاً حقيقية — سحب شام كاش وUSDT وبيتكوين</title>
<meta name="description" content="<?= h(setting('seo_desc')) ?>">
<meta name="keywords" content="ربح المال, شام كاش, USDT, بيتكوين, مهام, كابتشا, نقاط, متجر عربي, Yassota">
<meta name="robots" content="index, follow">
<meta property="og:title" content="<?= h(SITE_NAME) ?> — تسوّق واربح نقاطاً حقيقية">
<meta property="og:description" content="<?= h(setting('seo_desc')) ?>">
<meta property="og:type" content="website">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💎</text></svg>">
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"WebSite","name":"<?= h(SITE_NAME) ?>","description":"<?= h(setting('seo_desc')) ?>"}
</script>
<?php if (MONETAG_ZONE_ID): ?>
<script src="https://libtl.com/sdk.js" data-zone="<?= h(MONETAG_ZONE_ID) ?>" data-sdk="show_<?= h(MONETAG_ZONE_ID) ?>"></script>
<?php endif; ?>
<style>
:root{
  --bg:#0b1020; --bg2:#121a35; --card:#161f3d; --line:#26315c;
  --txt:#e9edff; --mut:#9aa6d6; --pri:#6c5ce7; --pri2:#a29bfe;
  --ok:#19c37d; --warn:#ffb020; --bad:#ff5470; --gold:#ffd166;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Tahoma,system-ui,sans-serif;background:linear-gradient(160deg,#0b1020,#0e1530);color:var(--txt);min-height:100vh}
a{color:inherit;text-decoration:none}
.btn{cursor:pointer;border:none;border-radius:12px;padding:10px 16px;font-weight:700;font-size:14px;
  background:linear-gradient(135deg,var(--pri),var(--pri2));color:#fff;transition:.2s;display:inline-flex;align-items:center;gap:6px}
.btn:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(108,92,231,.4)}
.btn.ghost{background:transparent;border:1px solid var(--line);color:var(--txt)}
.btn.ok{background:linear-gradient(135deg,#0fb96b,#19c37d)}
.btn.bad{background:linear-gradient(135deg,#e0395a,#ff5470)}
.btn.gold{background:linear-gradient(135deg,#f0a500,#ffd166);color:#3a2c00}
.btn.sm{padding:6px 10px;font-size:12px;border-radius:9px}

/* Splash / Lazy loading */
#splash{position:fixed;inset:0;background:radial-gradient(circle at 50% 40%,#1a2452,#0b1020);
  display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:9999;transition:opacity .6s}
#splash .logo{font-size:64px;animation:pulse 1.4s infinite}
#splash h1{margin-top:14px;font-size:28px;background:linear-gradient(90deg,var(--pri2),var(--gold));-webkit-background-clip:text;background-clip:text;color:transparent}
#splash .bar{width:200px;height:6px;background:#1d2750;border-radius:99px;overflow:hidden;margin-top:18px}
#splash .bar i{display:block;height:100%;width:40%;background:linear-gradient(90deg,var(--pri),var(--gold));animation:load 1.2s infinite}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.15)}}
@keyframes load{0%{margin-left:-40%}100%{margin-left:100%}}

/* Header */
header{position:sticky;top:0;z-index:50;background:rgba(11,16,32,.85);backdrop-filter:blur(10px);
  border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px;padding:12px 18px}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:20px}
.brand .mark{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,var(--pri),var(--gold));
  display:grid;place-items:center;font-size:20px}
.menu-btn{font-size:22px;background:none;border:none;color:var(--txt);cursor:pointer}
.spacer{flex:1}
.balance-chip{background:var(--card);border:1px solid var(--line);border-radius:99px;padding:6px 14px;font-weight:800;color:var(--gold)}

/* Sidebar */
.sidebar{position:fixed;top:0;right:-320px;width:300px;height:100%;background:linear-gradient(180deg,var(--bg2),var(--bg));
  border-left:1px solid var(--line);z-index:100;transition:right .3s;overflow-y:auto;padding:18px}
.sidebar.open{right:0}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99;opacity:0;pointer-events:none;transition:.3s}
.overlay.show{opacity:1;pointer-events:auto}
.side-user{display:flex;align-items:center;gap:10px;padding:12px;background:var(--card);border-radius:14px;margin-bottom:14px}
.side-user img,.side-user .ph{width:46px;height:46px;border-radius:50%;background:var(--pri);object-fit:cover;display:grid;place-items:center;font-size:22px}
.drop{border:1px solid var(--line);border-radius:14px;margin-bottom:10px;overflow:hidden;background:var(--card)}
.drop>summary{list-style:none;cursor:pointer;padding:13px 15px;font-weight:700;display:flex;justify-content:space-between;align-items:center}
.drop>summary::-webkit-details-marker{display:none}
.drop[open]>summary{color:var(--pri2)}
.drop .body{padding:6px 10px 12px}
.drop .body a{display:flex;align-items:center;gap:9px;padding:10px;border-radius:9px;color:var(--mut);font-weight:600}
.drop .body a:hover{background:var(--bg);color:var(--txt)}

/* Banner */
.banner{margin:18px;border-radius:20px;padding:30px 24px;background:
  linear-gradient(135deg,rgba(108,92,231,.35),rgba(255,209,102,.18)),
  radial-gradient(circle at 80% 20%,rgba(162,155,254,.4),transparent);
  border:1px solid var(--line);position:relative;overflow:hidden}
.banner h2{font-size:26px;margin-bottom:8px}
.banner p{color:var(--mut);max-width:560px}
.banner .cta{margin-top:16px;display:flex;gap:10px;flex-wrap:wrap}

main{max-width:1100px;margin:0 auto;padding:0 14px 90px}
.sec-title{display:flex;align-items:center;gap:8px;margin:24px 6px 12px;font-size:20px;font-weight:800}
.chips{display:flex;gap:8px;overflow-x:auto;padding:4px 6px 10px}
.chip{white-space:nowrap;border:1px solid var(--line);background:var(--card);border-radius:99px;padding:7px 14px;font-size:13px;cursor:pointer;font-weight:600}
.chip.active{background:var(--pri);border-color:var(--pri)}

/* Product grid */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;overflow:hidden;position:relative;transition:.2s}
.card:hover{transform:translateY(-4px);border-color:var(--pri)}
.card .imgwrap{height:140px;background:linear-gradient(135deg,#1a2350,#0e1530);display:grid;place-items:center;font-size:46px;overflow:hidden}
.card .imgwrap img{width:100%;height:100%;object-fit:cover}
.card .tag{position:absolute;top:10px;right:10px;background:var(--bad);color:#fff;font-size:11px;font-weight:800;padding:4px 9px;border-radius:99px}
.card .tag.new{background:var(--ok)}
.card .body{padding:12px}
.card h3{font-size:15px;margin-bottom:4px}
.card .cat{color:var(--mut);font-size:12px}
.card .desc{color:var(--mut);font-size:12px;margin:6px 0;min-height:32px}
.card .price{display:flex;align-items:center;gap:8px;margin:8px 0}
.card .price b{color:var(--gold);font-size:17px}
.card .price s{color:var(--mut);font-size:13px}
.empty{text-align:center;color:var(--mut);padding:50px 20px;border:1px dashed var(--line);border-radius:18px}

/* Earn box */
.panel{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px;margin-top:14px}
.captcha-code{font-size:34px;font-weight:900;letter-spacing:10px;background:repeating-linear-gradient(45deg,#1a2350,#1a2350 8px,#141d3d 8px,#141d3d 16px);
  padding:14px 20px;border-radius:12px;text-align:center;user-select:none;color:var(--gold);text-shadow:1px 1px 3px #000}
.inp{width:100%;background:var(--bg);border:1px solid var(--line);border-radius:11px;padding:11px 13px;color:var(--txt);font-size:15px}
label{display:block;font-size:13px;color:var(--mut);margin:10px 0 4px}
.row{display:flex;gap:10px;flex-wrap:wrap}
.row>*{flex:1;min-width:160px}

/* Bottom nav */
.bottomnav{position:fixed;bottom:0;left:0;right:0;background:rgba(11,16,32,.95);backdrop-filter:blur(10px);
  border-top:1px solid var(--line);display:flex;justify-content:space-around;padding:8px 4px;z-index:40}
.bottomnav a{display:flex;flex-direction:column;align-items:center;gap:2px;font-size:11px;color:var(--mut);padding:5px 10px}
.bottomnav a.active,.bottomnav a:hover{color:var(--pri2)}
.bottomnav a span{font-size:20px}

/* Modal */
.modal{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;z-index:200;padding:16px}
.modal.show{display:flex}
.modal .box{background:var(--bg2);border:1px solid var(--line);border-radius:18px;padding:22px;max-width:440px;width:100%;max-height:90vh;overflow:auto}
.modal h3{margin-bottom:12px}
.close{float:left;cursor:pointer;color:var(--mut);font-size:22px}

/* Toast */
#toast{position:fixed;bottom:80px;right:50%;transform:translateX(50%);background:var(--card);border:1px solid var(--pri);
  padding:12px 20px;border-radius:12px;z-index:300;opacity:0;transition:.3s;font-weight:700;pointer-events:none;max-width:90%}
#toast.show{opacity:1;bottom:90px}

/* Cookie */
#cookie{position:fixed;bottom:0;left:0;right:0;background:var(--bg2);border-top:1px solid var(--pri);padding:16px;z-index:500;display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:center}
#cookie p{color:var(--mut);font-size:13px;flex:1;min-width:220px}

/* Admin */
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}
.tabs a{padding:9px 14px;border-radius:11px;background:var(--card);border:1px solid var(--line);font-weight:700;font-size:13px}
.tabs a.active{background:var(--pri);border-color:var(--pri)}
table{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px}
th,td{padding:9px;border-bottom:1px solid var(--line);text-align:right}
th{color:var(--mut);font-weight:700}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin:14px 0}
.stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}
.stat b{font-size:24px;display:block;color:var(--gold)}
.stat span{color:var(--mut);font-size:13px}
.badge{font-size:11px;padding:3px 8px;border-radius:99px;font-weight:700}
.badge.p{background:rgba(255,176,32,.2);color:var(--warn)}
.badge.a{background:rgba(25,195,125,.2);color:var(--ok)}
.badge.r{background:rgba(255,84,112,.2);color:var(--bad)}
@media(max-width:560px){.banner h2{font-size:21px}.card .imgwrap{height:115px}}
</style>
</head>
<body>

<!-- ============ SPLASH / LAZY LOADING ============ -->
<div id="splash">
  <div class="logo">💎</div>
  <h1><?= h(SITE_NAME) ?></h1>
  <div class="bar"><i></i></div>
  <p style="color:var(--mut);margin-top:12px;font-size:13px">جاري التحميل…</p>
</div>

<?php
$me = current_user(); // refresh
$balUsd = $me ? coins_to_usd((int)$me['balance']) : 0;
?>

<!-- ============ HEADER ============ -->
<header>
  <button class="menu-btn" onclick="toggleSide()">☰</button>
  <div class="brand"><span class="mark">💎</span><?= h(SITE_NAME) ?></div>
  <div class="spacer"></div>
  <?php if ($me): ?>
    <div class="balance-chip">💰 <?= number_format((int)$me['balance']) ?> <small>(≈$<?= $balUsd ?>)</small></div>
  <?php else: ?>
    <a class="btn sm" href="<?= GOOGLE_CLIENT_ID ? '?action=google_login' : '#' ?>" <?= GOOGLE_CLIENT_ID ? '' : 'onclick="openModal(\'loginModal\');return false"' ?>>🔐 دخول</a>
  <?php endif; ?>
</header>

<!-- ============ SIDEBAR ============ -->
<div class="overlay" id="overlay" onclick="toggleSide()"></div>
<aside class="sidebar" id="sidebar">
  <span class="close" onclick="toggleSide()">✕</span>
  <?php if ($me): ?>
    <div class="side-user">
      <?php if ($me['avatar']): ?><img src="<?= h($me['avatar']) ?>" alt="">
      <?php else: ?><div class="ph">👤</div><?php endif; ?>
      <div>
        <div style="font-weight:800"><?= h($me['name']) ?></div>
        <div style="color:var(--mut);font-size:12px"><?= h($me['email']) ?></div>
      </div>
    </div>
  <?php else: ?>
    <div class="side-user"><div class="ph">👤</div><div>زائر — <a style="color:var(--pri2)" href="<?= GOOGLE_CLIENT_ID ? '?action=google_login' : '#' ?>" <?= GOOGLE_CLIENT_ID ? '' : 'onclick="openModal(\'loginModal\');toggleSide();return false"' ?>>سجّل الدخول</a></div></div>
  <?php endif; ?>

  <details class="drop" open><summary>🏠 الرئيسية ▾</summary><div class="body">
    <a href="?">🛍️ المنتجات</a>
    <a href="#earn" onclick="toggleSide()">🎯 اربح نقاطاً</a>
    <a href="#tasks" onclick="toggleSide()">📋 المهام اليومية</a>
  </div></details>

  <details class="drop"><summary>💼 حسابي ▾</summary><div class="body">
    <a href="#" onclick="openModal('walletModal');toggleSide();return false">💳 محفظتي ورصيدي</a>
    <a href="#" onclick="openModal('topupModal');toggleSide();return false">➕ شحن رصيد</a>
    <a href="#" onclick="openModal('withdrawModal');toggleSide();return false">💸 سحب الأرباح</a>
    <a href="?page=orders">📦 طلباتي</a>
  </div></details>

  <details class="drop"><summary>💰 الربح ▾</summary><div class="body">
    <a href="#earn" onclick="toggleSide()">🔢 الكابتشا المحلية</a>
    <a href="#tasks" onclick="toggleSide()">🔗 مهام الروابط</a>
    <a href="#" onclick="openModal('telegramModal');toggleSide();return false">🤖 بوت تيليجرام</a>
  </div></details>

  <details class="drop"><summary>📄 معلومات ▾</summary><div class="body">
    <a href="?page=privacy">🔒 سياسة الخصوصية</a>
    <a href="?page=terms">📜 شروط الاستخدام</a>
  </div></details>

  <?php if (is_admin($me)): ?>
  <details class="drop"><summary>👑 الإدارة ▾</summary><div class="body">
    <a href="?page=admin">🎛️ لوحة التحكم</a>
    <a href="?page=admin&tab=products">🛍️ المنتجات</a>
    <a href="?page=admin&tab=orders">📦 الطلبات</a>
    <a href="?page=admin&tab=users">👥 المستخدمون</a>
  </div></details>
  <?php endif; ?>

  <?php if ($me): ?>
    <a class="btn ghost" style="width:100%;justify-content:center;margin-top:8px" href="?action=logout">🚪 تسجيل خروج</a>
  <?php endif; ?>
</aside>

<?php
/* ============ ROUTER للصفحات ============ */
if ($page === 'privacy') { render_privacy(); }
elseif ($page === 'terms') { render_terms(); }
elseif ($page === 'orders') { render_orders($me); }
elseif ($page === 'admin' && is_admin($me)) { render_admin(); }
else { render_home($products, $categories, $tasks, $me); }

/* ============ التذييل + أزرار سفلية + مودالات ============ */
?>

<!-- ============ BOTTOM NAV ============ -->
<nav class="bottomnav">
  <a href="?" class="<?= $page==='home'?'active':'' ?>"><span>🏠</span>الرئيسية</a>
  <a href="#earn"><span>💰</span>اربح</a>
  <a href="#" onclick="openModal('walletModal');return false"><span>💳</span>محفظتي</a>
  <a href="#tasks"><span>📋</span>المهام</a>
  <?php if (is_admin($me)): ?>
    <a href="?page=admin" class="<?= $page==='admin'?'active':'' ?>"><span>👑</span>الإدارة</a>
  <?php else: ?>
    <a href="#" onclick="openModal('telegramModal');return false"><span>🤖</span>البوت</a>
  <?php endif; ?>
</nav>

<!-- ============ MODALS ============ -->
<div class="modal" id="loginModal"><div class="box">
  <span class="close" onclick="closeModal('loginModal')">✕</span>
  <h3>🔐 تسجيل الدخول</h3>
  <p style="color:var(--mut);font-size:13px;margin-bottom:10px">
    <?= GOOGLE_CLIENT_ID ? 'ادخل عبر حساب جوجل.' : 'وضع تجريبي: أدخل إيميلك. (لدخول الإدارة استخدم '.h(ADMIN_EMAIL).')' ?>
  </p>
  <?php if (GOOGLE_CLIENT_ID): ?>
    <a class="btn" style="width:100%;justify-content:center" href="?action=google_login">دخول بجوجل</a>
  <?php else: ?>
    <form method="post" action="?action=demo_login">
      <input type="hidden" name="csrf" value="<?= $CSRF ?>">
      <input class="inp" type="email" name="email" placeholder="example@gmail.com" required>
      <button class="btn" style="width:100%;justify-content:center;margin-top:12px">دخول</button>
    </form>
  <?php endif; ?>
</div></div>

<div class="modal" id="walletModal"><div class="box">
  <span class="close" onclick="closeModal('walletModal')">✕</span>
  <h3>💳 محفظتي</h3>
  <?php if ($me): ?>
    <div class="panel" style="margin-top:0">
      <div style="font-size:30px;font-weight:900;color:var(--gold)"><?= number_format((int)$me['balance']) ?> <small style="font-size:14px">نقطة</small></div>
      <div style="color:var(--mut)">≈ $<?= $balUsd ?> أمريكي</div>
    </div>
    <div class="row" style="margin-top:12px">
      <button class="btn ok" onclick="closeModal('walletModal');openModal('topupModal')">➕ شحن</button>
      <button class="btn gold" onclick="closeModal('walletModal');openModal('withdrawModal')">💸 سحب</button>
    </div>
    <p style="color:var(--mut);font-size:12px;margin-top:12px">الحد الأدنى للسحب: $<?= h(setting('min_withdraw')) ?></p>
  <?php else: ?>
    <p style="color:var(--mut)">سجّل الدخول لعرض محفظتك.</p>
  <?php endif; ?>
</div></div>

<div class="modal" id="topupModal"><div class="box">
  <span class="close" onclick="closeModal('topupModal')">✕</span>
  <h3>➕ شحن رصيد</h3>
  <?php if ($wallets): ?>
    <p style="color:var(--mut);font-size:13px">حوّل للمحفظة ثم أرسل الطلب للموافقة:</p>
    <?php foreach ($wallets as $w): ?>
      <div class="panel" style="margin-top:8px;padding:12px">
        <b><?= h($w['label']) ?></b> <span class="badge a"><?= h($w['type']) ?></span>
        <div style="font-family:monospace;color:var(--gold);word-break:break-all;font-size:13px;margin-top:4px"><?= h($w['address']) ?></div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p style="color:var(--mut)">لم تُضف محافظ بعد. تواصل مع الإدارة.</p>
  <?php endif; ?>
  <form onsubmit="return ajaxForm(this,'topup')">
    <input type="hidden" name="csrf" value="<?= $CSRF ?>">
    <label>طريقة الدفع</label>
    <select class="inp" name="method"><option value="usdt">USDT (TRC20)</option><option value="sham">الشام كاش</option><option value="btc">Bitcoin</option></select>
    <label>المبلغ بالدولار</label>
    <input class="inp" type="number" step="0.01" name="amount" placeholder="25" required>
    <label>رقم العملية / TXID</label>
    <input class="inp" name="txid" placeholder="hash أو رقم الحوالة">
    <button class="btn ok" style="width:100%;justify-content:center;margin-top:12px">إرسال طلب الشحن</button>
  </form>
</div></div>

<div class="modal" id="withdrawModal"><div class="box">
  <span class="close" onclick="closeModal('withdrawModal')">✕</span>
  <h3>💸 سحب الأرباح</h3>
  <?php if ($me): ?>
    <p style="color:var(--mut);font-size:13px">رصيدك: <?= number_format((int)$me['balance']) ?> ≈ $<?= $balUsd ?> · الحد الأدنى $<?= h(setting('min_withdraw')) ?></p>
    <form onsubmit="return ajaxForm(this,'withdraw')">
      <input type="hidden" name="csrf" value="<?= $CSRF ?>">
      <label>طريقة الاستلام</label>
      <select class="inp" name="method"><option value="sham">الشام كاش</option><option value="usdt">USDT (TRC20)</option><option value="btc">Bitcoin</option></select>
      <label>العنوان / رقم الاستلام</label>
      <input class="inp" name="address" required>
      <button class="btn gold" style="width:100%;justify-content:center;margin-top:12px">طلب سحب كامل الرصيد</button>
    </form>
  <?php else: ?>
    <p style="color:var(--mut)">سجّل الدخول أولاً.</p>
  <?php endif; ?>
</div></div>

<div class="modal" id="telegramModal"><div class="box">
  <span class="close" onclick="closeModal('telegramModal')">✕</span>
  <h3>🤖 بوت تيليجرام للربح</h3>
  <p style="color:var(--mut);font-size:14px;line-height:1.7"><?= h(setting('telegram_info')) ?></p>
  <a class="btn" style="width:100%;justify-content:center;margin-top:14px" target="_blank"
     href="https://t.me/<?= h(ltrim(setting('telegram_bot'), '@')) ?>">فتح البوت <?= h(setting('telegram_bot')) ?></a>
</div></div>

<!-- buy modal placeholder -->
<div class="modal" id="buyModal"><div class="box">
  <span class="close" onclick="closeModal('buyModal')">✕</span>
  <h3>🛒 تأكيد الشراء</h3>
  <div id="buyContent"></div>
</div></div>

<div id="toast"></div>

<!-- ============ COOKIE CONSENT ============ -->
<?php if (!$policy_ok): ?>
<div id="cookie">
  <p>نستخدم الكوكيز لتحسين تجربتك. بالمتابعة فأنت توافق على
    <a style="color:var(--pri2)" href="?page=privacy">سياسة الخصوصية</a> و
    <a style="color:var(--pri2)" href="?page=terms">شروط الاستخدام</a>.</p>
  <button class="btn ok" onclick="acceptCookies()">موافق ✓</button>
</div>
<?php endif; ?>

<script>
const CSRF = <?= json_encode($CSRF) ?>;
const LOGGED = <?= $me ? 'true':'false' ?>;

// Splash
window.addEventListener('load', ()=>{ setTimeout(()=>{ const s=document.getElementById('splash'); s.style.opacity=0; setTimeout(()=>s.remove(),600); }, 700); });

// Sidebar
function toggleSide(){ document.getElementById('sidebar').classList.toggle('open'); document.getElementById('overlay').classList.toggle('show'); }
// Modals
function openModal(id){ if(!LOGGED && ['walletModal','topupModal','withdrawModal'].includes(id)){ openModal('loginModal'); return; } document.getElementById(id).classList.add('show'); }
function closeModal(id){ document.getElementById(id).classList.remove('show'); }
// Toast
function toast(msg){ const t=document.getElementById('toast'); t.textContent=msg; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),3000); }
// Cookies
function acceptCookies(){ fetch('?action=accept_cookies').then(()=>{ const c=document.getElementById('cookie'); if(c)c.remove(); }); }

// Generic AJAX form
function ajaxForm(form, action){
  const fd = new FormData(form);
  fetch('?action='+action, {method:'POST', body:fd})
    .then(r=>r.json()).then(d=>{ toast(d.msg||''); if(d.ok){ form.reset(); document.querySelectorAll('.modal.show').forEach(m=>m.classList.remove('show')); if(action==='withdraw'||action==='topup') setTimeout(()=>location.reload(),1200);} })
    .catch(()=>toast('حدث خطأ'));
  return false;
}

// Buy product
function buyProduct(id, title, price){
  if(!LOGGED){ openModal('loginModal'); return; }
  document.getElementById('buyContent').innerHTML =
    '<p style="color:var(--mut)">المنتج: <b style="color:var(--txt)">'+title+'</b><br>السعر: <b style="color:var(--gold)">'+price+' نقطة</b></p>'+
    '<p style="color:var(--mut);font-size:12px;margin:10px 0">سيُرسل الطلب للإدارة للموافقة وسيُخصم الرصيد عند القبول.</p>'+
    '<button class="btn ok" style="width:100%;justify-content:center" onclick="confirmBuy('+id+')">تأكيد الطلب</button>';
  openModal('buyModal');
}
function confirmBuy(id){
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('product_id',id);
  fetch('?action=buy',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ closeModal('buyModal'); toast(d.msg); });
}

// Category filter
function filterCat(cat, el){
  document.querySelectorAll('.chip').forEach(c=>c.classList.remove('active')); el.classList.add('active');
  document.querySelectorAll('.card[data-cat]').forEach(c=>{ c.style.display = (cat==='all'||c.dataset.cat===cat)?'':'none'; });
}

// ===== Captcha =====
function newCaptcha(){
  fetch('?action=captcha_new').then(r=>r.json()).then(d=>{
    document.getElementById('capCode').textContent = d.code;
  });
}
function verifyCaptcha(e){
  e.preventDefault();
  if(!LOGGED){ openModal('loginModal'); return false; }
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('answer',document.getElementById('capInput').value);
  fetch('?action=captcha_verify',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    toast(d.msg);
    document.getElementById('capInput').value='';
    newCaptcha();
    if(d.ok){
      // عرض إعلان Monetag (تغطية التكلفة) إن وُجد
      if(typeof show_<?= MONETAG_ZONE_ID ?: 'undefined' ?> === 'function'){ try{ show_<?= MONETAG_ZONE_ID ?: 'x' ?>(); }catch(e){} }
      setTimeout(()=>location.reload(), 1400);
    }
  });
  return false;
}
if(document.getElementById('capCode')) newCaptcha();

// ===== Tasks: visit link 15s then claim =====
const taskTimers={};
function startTask(id, url){
  if(!LOGGED){ openModal('loginModal'); return; }
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('task_id',id);
  fetch('?action=task_start',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    window.open(url,'_blank');
    let left=d.wait; const btn=document.getElementById('task'+id);
    btn.disabled=true;
    taskTimers[id]=setInterval(()=>{
      left--; btn.textContent='⏳ '+left+'s';
      if(left<=0){ clearInterval(taskTimers[id]); btn.disabled=false; btn.textContent='✅ استلام النقاط'; btn.onclick=()=>claimTask(id); }
    },1000);
  });
}
function claimTask(id){
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('task_id',id);
  fetch('?action=task_complete',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    toast(d.msg); if(d.ok) setTimeout(()=>location.reload(),1200);
  });
}
</script>
</body>
</html>

<?php
/* ========================= 8) دوال العرض ========================= */

function render_home(array $products, array $categories, array $tasks, ?array $me): void {
    global $CSRF;
    ?>
    <!-- BANNER -->
    <section class="banner">
      <h2>🚀 <?= h(setting('banner_text')) ?></h2>
      <p>تسوّق منتجات رقمية، أنجز مهاماً يومية، حُلّ الكابتشا واربح عملات <?= h(COIN_NAME) ?> القابلة للسحب نقداً عبر الشام كاش، USDT أو بيتكوين.</p>
      <div class="cta">
        <a class="btn gold" href="#earn">💰 ابدأ الربح الآن</a>
        <a class="btn ghost" href="#products">🛍️ تصفح المنتجات</a>
      </div>
    </section>

    <main>
      <!-- EARN: CAPTCHA -->
      <h2 class="sec-title" id="earn">🎯 اربح نقاطاً — كابتشا محلية</h2>
      <div class="panel">
        <p style="color:var(--mut);margin-bottom:12px">اكتب الأرقام الظاهرة للحصول على نقاط <?= h(COIN_NAME) ?> فوراً. (تُموَّل من إعلانات Monetag — 95% للموقع / 5% للمستخدم)</p>
        <div class="captcha-code" id="capCode">----</div>
        <form onsubmit="return verifyCaptcha(event)">
          <label>اكتب الأرقام هنا</label>
          <input class="inp" id="capInput" inputmode="numeric" placeholder="مثال: 1234" required>
          <button class="btn ok" style="width:100%;justify-content:center;margin-top:12px">تحقّق واربح</button>
        </form>
      </div>

      <!-- TASKS -->
      <h2 class="sec-title" id="tasks">📋 المهام اليومية</h2>
      <?php if ($tasks): ?>
        <div class="grid">
        <?php foreach ($tasks as $t): ?>
          <div class="card"><div class="body">
            <h3>🔗 <?= h($t['title']) ?></h3>
            <div class="cat">زر الرابط <?= TASK_WAIT_SEC ?> ثانية ثم استلم</div>
            <div class="price"><b>+<?= (int)$t['reward'] ?></b> نقطة</div>
            <button class="btn" id="task<?= (int)$t['id'] ?>" style="width:100%;justify-content:center"
                    onclick="startTask(<?= (int)$t['id'] ?>, '<?= h($t['url']) ?>')">▶️ بدء المهمة</button>
          </div></div>
        <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty">لا توجد مهام حالياً. عُد لاحقاً!</div>
      <?php endif; ?>

      <!-- PRODUCTS -->
      <h2 class="sec-title" id="products">🛍️ المنتجات — الأحدث أولاً</h2>
      <?php if ($categories): ?>
      <div class="chips">
        <div class="chip active" onclick="filterCat('all',this)">الكل</div>
        <?php foreach ($categories as $c): ?>
          <div class="chip" onclick="filterCat('<?= h($c) ?>',this)"><?= h($c) ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($products): ?>
        <div class="grid">
        <?php foreach ($products as $p):
          $hasDisc = (float)$p['old_price'] > (float)$p['price'] && (float)$p['old_price'] > 0; ?>
          <div class="card" data-cat="<?= h($p['category']) ?>">
            <?php if ($p['tag']): ?><span class="tag <?= $p['tag']==='جديد'?'new':'' ?>"><?= h($p['tag']) ?></span><?php endif; ?>
            <div class="imgwrap">
              <?php if ($p['image']): ?>
                <img loading="lazy" src="<?= h($p['image']) ?>" alt="<?= h($p['title']) ?>">
              <?php else: ?>
                <span><?= h($p['icon'] ?: '🛍️') ?></span>
              <?php endif; ?>
            </div>
            <div class="body">
              <h3><?= h($p['icon']) ?> <?= h($p['title']) ?></h3>
              <div class="cat">📂 <?= h($p['category']) ?></div>
              <div class="desc"><?= h($p['description']) ?></div>
              <div class="price">
                <b><?= number_format((float)$p['price']) ?> نقطة</b>
                <?php if ($hasDisc): ?><s><?= number_format((float)$p['old_price']) ?></s><?php endif; ?>
              </div>
              <button class="btn" style="width:100%;justify-content:center"
                onclick="buyProduct(<?= (int)$p['id'] ?>, '<?= h(addslashes($p['title'])) ?>', <?= (int)$p['price'] ?>)">🛒 طلب شراء</button>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty">🛒 لا توجد منتجات منشورة بعد. (تُضاف من لوحة الإدارة وتظهر هنا فوراً)</div>
      <?php endif; ?>
    </main>
    <?php
}

function render_privacy(): void { ?>
  <main><h2 class="sec-title">🔒 سياسة الخصوصية</h2>
  <div class="panel" style="line-height:1.9;color:var(--mut)">
    <p>نحن في <?= h(SITE_NAME) ?> نحترم خصوصيتك. نجمع الحد الأدنى من البيانات (الإيميل والاسم عند تسجيل الدخول بجوجل) لإدارة حسابك ورصيدك.</p>
    <p>• لا نشارك بياناتك مع أطراف ثالثة لأغراض تسويقية.<br>
       • نستخدم الكوكيز لحفظ جلستك لمدة أسبوع.<br>
       • تُعرض إعلانات Monetag لتغطية تكاليف المكافآت.<br>
       • يمكنك طلب حذف حسابك بالتواصل مع الإدارة.</p>
    <p>باستخدامك للموقع فإنك توافق على هذه السياسة.</p>
  </div></main>
<?php }

function render_terms(): void { ?>
  <main><h2 class="sec-title">📜 شروط الاستخدام</h2>
  <div class="panel" style="line-height:1.9;color:var(--mut)">
    <p>باستخدامك منصة <?= h(SITE_NAME) ?> فإنك توافق على ما يلي:</p>
    <p>• النقاط (<?= h(COIN_NAME) ?>) عملة افتراضية تُكتسب عبر المهام والكابتشا والإحالات.<br>
       • الحد الأدنى للسحب $<?= h(setting('min_withdraw')) ?>، وتتم المعالجة بعد موافقة الإدارة.<br>
       • يُمنع التلاعب أو استخدام برامج آلية؛ ويُحظر الحساب المخالف ويُصادر رصيده.<br>
       • طلبات الشراء والشحن والسحب تخضع لمراجعة الإدارة (قبول/رفض).<br>
       • قد تتغير الأسعار ونسب التحويل في أي وقت.</p>
  </div></main>
<?php }

function render_orders(?array $me): void {
    if (!$me) { echo '<main><div class="empty">سجّل الدخول لعرض طلباتك.</div></main>'; return; }
    $o = db()->prepare("SELECT o.*, p.title, p.price, p.icon FROM orders o JOIN products p ON p.id=o.product_id WHERE o.user_id=? ORDER BY o.id DESC");
    $o->execute([$me['id']]); $orders = $o->fetchAll();
    $w = db()->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY id DESC"); $w->execute([$me['id']]); $wd = $w->fetchAll();
    ?>
    <main>
      <h2 class="sec-title">📦 طلبات الشراء</h2>
      <?php if ($orders): foreach ($orders as $od): ?>
        <div class="panel" style="display:flex;justify-content:space-between;align-items:center">
          <div><b><?= h($od['icon']) ?> <?= h($od['title']) ?></b><br><small style="color:var(--mut)"><?= number_format((float)$od['price']) ?> نقطة · <?= h($od['created_at']) ?></small></div>
          <?= status_badge($od['status']) ?>
        </div>
      <?php endforeach; else: ?><div class="empty">لا طلبات شراء.</div><?php endif; ?>

      <h2 class="sec-title">💸 طلبات السحب</h2>
      <?php if ($wd): foreach ($wd as $x): ?>
        <div class="panel" style="display:flex;justify-content:space-between;align-items:center">
          <div><b>$<?= h($x['amount_usd']) ?></b> · <?= h($x['method']) ?><br><small style="color:var(--mut)"><?= h($x['address']) ?> · <?= h($x['created_at']) ?></small></div>
          <?= status_badge($x['status']) ?>
        </div>
      <?php endforeach; else: ?><div class="empty">لا طلبات سحب.</div><?php endif; ?>
    </main>
    <?php
}

function status_badge(string $s): string {
    $map = ['pending' => ['p','⏳ معلّق'], 'approved' => ['a','✅ مقبول'], 'rejected' => ['r','❌ مرفوض']];
    [$cls, $txt] = $map[$s] ?? ['p', $s];
    return "<span class='badge $cls'>$txt</span>";
}

/* ========================= 9) لوحة الإدارة ========================= */
function render_admin(): void {
    global $CSRF;
    $pdo = db();
    $tab = $_GET['tab'] ?? 'dash';
    $tabs = ['dash'=>'🎛️ الرئيسية','products'=>'🛍️ المنتجات','orders'=>'📦 الطلبات',
             'topups'=>'➕ الشحن','withdrawals'=>'💸 السحب','wallets'=>'💳 المحافظ',
             'tasks'=>'📋 المهام','users'=>'👥 المستخدمون','settings'=>'⚙️ الإعدادات'];
    echo '<main><h2 class="sec-title">👑 لوحة الإدارة</h2><div class="tabs">';
    foreach ($tabs as $k => $v) {
        $a = $k === $tab ? 'active' : '';
        echo "<a class='$a' href='?page=admin&tab=$k'>$v</a>";
    }
    echo '</div>';

    if ($tab === 'dash') {
        $stats = [
            'مستخدمون' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'منتجات' => $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
            'طلبات معلّقة' => $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
            'شحن معلّق' => $pdo->query("SELECT COUNT(*) FROM topups WHERE status='pending'")->fetchColumn(),
            'سحب معلّق' => $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn(),
            'مهام' => $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn(),
        ];
        echo '<div class="stat-grid">';
        foreach ($stats as $label => $val) echo "<div class='stat'><b>".number_format((int)$val)."</b><span>$label</span></div>";
        echo '</div>';
        echo '<div class="panel"><b>أحدث المستخدمين</b>';
        $us = $pdo->query("SELECT * FROM users ORDER BY id DESC LIMIT 8")->fetchAll();
        echo '<table><tr><th>#</th><th>الاسم</th><th>الإيميل</th><th>الرصيد</th></tr>';
        foreach ($us as $u) echo "<tr><td>{$u['id']}</td><td>".h($u['name'])."</td><td>".h($u['email'])."</td><td>".number_format((int)$u['balance'])."</td></tr>";
        echo '</table></div>';
    }

    elseif ($tab === 'products') {
        ?>
        <div class="panel"><b>➕ إضافة / تعديل منتج</b>
          <form method="post" action="?action=admin_product_save">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>"><input type="hidden" name="id" id="pid" value="">
            <div class="row">
              <div><label>الاسم</label><input class="inp" name="title" id="ptitle" required></div>
              <div><label>الأيقونة (اختياري)</label><input class="inp" name="icon" id="picon" value="🛍️"></div>
            </div>
            <div class="row">
              <div><label>القسم</label><input class="inp" name="category" id="pcat" value="عام"></div>
              <div><label>الوسم</label><input class="inp" name="tag" id="ptag" value="جديد"></div>
            </div>
            <div class="row">
              <div><label>السعر (نقاط)</label><input class="inp" type="number" name="price" id="pprice" required></div>
              <div><label>السعر القديم (للخصم)</label><input class="inp" type="number" name="old_price" id="pold" value="0"></div>
            </div>
            <label>رابط الصورة (اختياري)</label><input class="inp" name="image" id="pimg" placeholder="https://...">
            <label>الوصف</label><textarea class="inp" name="description" id="pdesc" rows="2"></textarea>
            <label style="display:flex;align-items:center;gap:8px;margin-top:8px"><input type="checkbox" name="is_active" id="pact" checked> نشط</label>
            <button class="btn ok" style="margin-top:10px">حفظ المنتج</button>
            <button type="button" class="btn ghost" onclick="resetP()">جديد</button>
          </form>
        </div>
        <table><tr><th>#</th><th>المنتج</th><th>القسم</th><th>السعر</th><th>الحالة</th><th>إجراءات</th></tr>
        <?php foreach ($pdo->query("SELECT * FROM products ORDER BY id DESC") as $p): ?>
          <tr>
            <td><?= $p['id'] ?></td>
            <td><?= h($p['icon']) ?> <?= h($p['title']) ?></td>
            <td><?= h($p['category']) ?></td>
            <td><?= number_format((float)$p['price']) ?></td>
            <td><?= $p['is_active']?'🟢':'⏸️' ?></td>
            <td>
              <button class="btn sm" onclick='editP(<?= json_encode($p, JSON_UNESCAPED_UNICODE) ?>)'>✏️</button>
              <form method="post" action="?action=admin_product_del" style="display:inline" onsubmit="return confirm('حذف؟')">
                <input type="hidden" name="csrf" value="<?= $CSRF ?>"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn sm bad">🗑️</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </table>
        <script>
        function editP(p){ pid.value=p.id; ptitle.value=p.title; picon.value=p.icon; pcat.value=p.category; ptag.value=p.tag;
          pprice.value=p.price; pold.value=p.old_price; pimg.value=p.image||''; pdesc.value=p.description||''; pact.checked=p.is_active==1;
          window.scrollTo(0,0); }
        function resetP(){ document.querySelector('#pid').value=''; ptitle.value=''; pprice.value=''; }
        </script>
        <?php
    }

    elseif ($tab === 'orders') {
        admin_request_table($pdo, 'orders', 'admin_order',
            "SELECT o.*, p.title, p.price, u.name FROM orders o JOIN products p ON p.id=o.product_id JOIN users u ON u.id=o.user_id ORDER BY o.id DESC",
            fn($r) => h($r['name']).' → '.h($r['title']).' ('.number_format((float)$r['price']).' نقطة)');
    }
    elseif ($tab === 'topups') {
        admin_request_table($pdo, 'topups', 'admin_topup',
            "SELECT t.*, u.name FROM topups t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC",
            fn($r) => h($r['name']).' → $'.h($r['amount_usd']).' ('.h($r['method']).') TX:'.h($r['txid']));
    }
    elseif ($tab === 'withdrawals') {
        admin_request_table($pdo, 'withdrawals', 'admin_withdraw',
            "SELECT w.*, u.name FROM withdrawals w JOIN users u ON u.id=w.user_id ORDER BY w.id DESC",
            fn($r) => h($r['name']).' → $'.h($r['amount_usd']).' ('.h($r['method']).') '.h($r['address']));
    }

    elseif ($tab === 'wallets') {
        ?>
        <div class="panel"><b>➕ إضافة محفظة استلام</b>
          <form method="post" action="?action=admin_wallet_save">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            <div class="row">
              <div><label>النوع</label><input class="inp" name="type" placeholder="usdt / sham / btc" required></div>
              <div><label>الاسم الظاهر</label><input class="inp" name="label" placeholder="USDT TRC20" required></div>
            </div>
            <label>العنوان / الرقم</label><input class="inp" name="address" required>
            <button class="btn ok" style="margin-top:10px">حفظ</button>
          </form>
        </div>
        <table><tr><th>#</th><th>النوع</th><th>الاسم</th><th>العنوان</th><th></th></tr>
        <?php foreach ($pdo->query("SELECT * FROM wallets ORDER BY id DESC") as $w): ?>
          <tr><td><?= $w['id'] ?></td><td><?= h($w['type']) ?></td><td><?= h($w['label']) ?></td>
          <td style="font-family:monospace;font-size:12px;word-break:break-all"><?= h($w['address']) ?></td>
          <td><form method="post" action="?action=admin_wallet_del" style="display:inline"><input type="hidden" name="csrf" value="<?= $CSRF ?>"><input type="hidden" name="id" value="<?= $w['id'] ?>"><button class="btn sm bad">🗑️</button></form></td></tr>
        <?php endforeach; ?>
        </table>
        <?php
    }

    elseif ($tab === 'tasks') {
        ?>
        <div class="panel"><b>➕ إضافة مهمة</b>
          <form method="post" action="?action=admin_task_save">
            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
            <label>العنوان</label><input class="inp" name="title" required>
            <div class="row">
              <div><label>الرابط</label><input class="inp" name="url" placeholder="https://..." required></div>
              <div><label>النقاط</label><input class="inp" type="number" name="reward" value="<?= TASK_REWARD ?>" required></div>
            </div>
            <button class="btn ok" style="margin-top:10px">حفظ</button>
          </form>
        </div>
        <table><tr><th>#</th><th>المهمة</th><th>الرابط</th><th>النقاط</th><th></th></tr>
        <?php foreach ($pdo->query("SELECT * FROM tasks ORDER BY id DESC") as $t): ?>
          <tr><td><?= $t['id'] ?></td><td><?= h($t['title']) ?></td><td style="font-size:12px"><?= h($t['url']) ?></td><td><?= (int)$t['reward'] ?></td>
          <td><form method="post" action="?action=admin_task_del" style="display:inline"><input type="hidden" name="csrf" value="<?= $CSRF ?>"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn sm bad">🗑️</button></form></td></tr>
        <?php endforeach; ?>
        </table>
        <?php
    }

    elseif ($tab === 'users') {
        ?>
        <table><tr><th>#</th><th>الاسم</th><th>الإيميل</th><th>الرصيد</th><th>الحالة</th><th>إجراءات</th></tr>
        <?php foreach ($pdo->query("SELECT * FROM users ORDER BY id DESC") as $u): ?>
          <tr>
            <td><?= $u['id'] ?></td><td><?= h($u['name']) ?></td><td><?= h($u['email']) ?></td>
            <td><?= number_format((int)$u['balance']) ?></td>
            <td><?= $u['is_banned']?'<span class="badge r">محظور</span>':'<span class="badge a">نشط</span>' ?><?= $u['is_admin']?' 👑':'' ?></td>
            <td>
              <form method="post" action="?action=admin_user" style="display:inline-flex;gap:4px;align-items:center">
                <input type="hidden" name="csrf" value="<?= $CSRF ?>"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <input class="inp" style="width:70px;padding:4px" type="number" name="pts" placeholder="نقاط">
                <button class="btn sm ok" name="do" value="addpts">➕</button>
                <?php if ($u['is_banned']): ?><button class="btn sm" name="do" value="unban">رفع</button>
                <?php else: ?><button class="btn sm bad" name="do" value="ban">حظر</button><?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </table>
        <?php
    }

    elseif ($tab === 'settings') {
        $keys = [
            'banner_text' => 'نص البنر الرئيسي',
            'seo_desc' => 'وصف SEO',
            'coins_per_usd' => 'عدد النقاط لكل 1$',
            'min_withdraw' => 'الحد الأدنى للسحب ($)',
            'captcha_reward' => 'نقاط الكابتشا',
            'telegram_bot' => 'معرّف بوت تيليجرام',
            'telegram_info' => 'وصف بوت تيليجرام',
        ];
        echo '<div class="panel"><form method="post" action="?action=admin_settings"><input type="hidden" name="csrf" value="'.$CSRF.'">';
        foreach ($keys as $k => $lbl) {
            $v = h(setting($k));
            echo "<label>$lbl</label><input class='inp' name='$k' value=\"$v\">";
        }
        echo '<button class="btn ok" style="margin-top:12px">حفظ الإعدادات</button></form></div>';
    }
    echo '</main>';
}

function admin_request_table(PDO $pdo, string $name, string $action, string $sql, callable $fmt): void {
    global $CSRF;
    $rows = $pdo->query($sql)->fetchAll();
    if (!$rows) { echo '<div class="empty">لا طلبات.</div>'; return; }
    echo '<table><tr><th>#</th><th>التفاصيل</th><th>الحالة</th><th>إجراءات</th></tr>';
    foreach ($rows as $r) {
        echo "<tr><td>{$r['id']}</td><td>".$fmt($r)."</td><td>".status_badge($r['status'])."</td><td>";
        if ($r['status'] === 'pending') {
            echo "<form method='post' action='?action=$action' style='display:inline'>
                  <input type='hidden' name='csrf' value='$CSRF'><input type='hidden' name='id' value='{$r['id']}'>
                  <button class='btn sm ok' name='status' value='approved'>✅ قبول</button>
                  <button class='btn sm bad' name='status' value='rejected'>❌ رفض</button></form>";
        } else echo '—';
        echo "</td></tr>";
    }
    echo '</table>';
}
