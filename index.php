<?php
/**
 * zanxpk — متجر تطبيقات وألعاب أندرويد + متجر منتجات رقمية + لوحة إدارة
 * ملف واحد يحتوي كل شيء: PHP + HTML + CSS + JS
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 7, // أسبوع
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

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

function add_column_if_missing(PDO $pdo, string $table, string $col, string $def): void
{
    try {
        $colSql = DB_DRIVER === 'sqlite' ? "PRAGMA table_info($table)" : "SHOW COLUMNS FROM $table";
        $cols = [];
        foreach ($pdo->query($colSql)->fetchAll() as $row) {
            $cols[] = DB_DRIVER === 'sqlite' ? $row['name'] : $row['Field'];
        }
        if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
    } catch (Throwable $e) {}
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
        content TEXT NULL,
        meta_title VARCHAR(190) NULL,
        meta_description VARCHAR(255) NULL
    )$engine",
    "CREATE TABLE IF NOT EXISTS tickers (
        id $id,
        text VARCHAR(255) NOT NULL,
        link VARCHAR(500) NULL,
        active TINYINT NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at $ts
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
    "CREATE TABLE IF NOT EXISTS activity_log (
        id $id,
        admin_id INT NOT NULL,
        admin_name VARCHAR(120) NOT NULL,
        field VARCHAR(60) NOT NULL,
        filename VARCHAR(255) NOT NULL,
        url VARCHAR(500) NOT NULL,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS bot_broadcast_queue (
        id $id,
        product_id INT NOT NULL,
        sent TINYINT NOT NULL DEFAULT 0,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS apps (
        id $id,
        name VARCHAR(190) NOT NULL,
        slug VARCHAR(190) NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'app',
        package_name VARCHAR(190) NULL,
        version VARCHAR(40) NULL,
        size_label VARCHAR(40) NULL,
        min_android VARCHAR(20) NULL,
        category VARCHAR(80) NULL,
        developer_name VARCHAR(190) NULL,
        developer_website VARCHAR(300) NULL,
        privacy_policy_url VARCHAR(300) NULL,
        icon VARCHAR(500) NULL,
        banner_image VARCHAR(500) NULL,
        screenshots TEXT NULL,
        video_url VARCHAR(500) NULL,
        short_description VARCHAR(300) NULL,
        description TEXT NULL,
        changelog TEXT NULL,
        permissions TEXT NULL,
        download_url VARCHAR(500) NOT NULL DEFAULT '',
        seo_title VARCHAR(190) NULL,
        seo_description VARCHAR(255) NULL,
        seo_keywords VARCHAR(255) NULL,
        rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0,
        views INT NOT NULL DEFAULT 0,
        downloads INT NOT NULL DEFAULT 0,
        likes_count INT NOT NULL DEFAULT 0,
        dislikes_count INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'published',
        publisher_id INT NULL,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS wishlist (
        id $id,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS user_downloads (
        id $id,
        user_id INT NOT NULL,
        app_id INT NOT NULL,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS coupons (
        id $id,
        code VARCHAR(40) NOT NULL,
        discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        max_uses INT NOT NULL DEFAULT 0,
        used_count INT NOT NULL DEFAULT 0,
        active TINYINT NOT NULL DEFAULT 1,
        expires_at VARCHAR(20) NULL,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS app_reports (
        id $id,
        app_id INT NOT NULL,
        user_id INT NULL,
        message VARCHAR(500) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS reviews (
        id $id,
        product_id INT NOT NULL,
        user_id INT NOT NULL,
        rating INT NOT NULL,
        comment TEXT NULL,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS openrouter_keys (
        id $id,
        label VARCHAR(120) NULL,
        api_key VARCHAR(255) NOT NULL,
        active TINYINT NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        last_error VARCHAR(255) NULL,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS login_attempts (
        id $id,
        identity VARCHAR(190) NOT NULL,
        ip VARCHAR(64) NOT NULL,
        success TINYINT NOT NULL DEFAULT 0,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS blocked_ips (
        id $id,
        ip VARCHAR(64) NOT NULL UNIQUE,
        reason VARCHAR(255) NULL,
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS bot_scripts (
        id $id,
        name VARCHAR(190) NOT NULL,
        description TEXT NULL,
        category VARCHAR(40) NOT NULL DEFAULT 'bot',
        icon VARCHAR(20) NULL,
        file_path VARCHAR(500) NULL,
        version VARCHAR(40) NULL,
        is_template TINYINT NOT NULL DEFAULT 0,
        downloads INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at $ts
    )$engine",
    "CREATE TABLE IF NOT EXISTS app_imports (
        id $id,
        source VARCHAR(30) NOT NULL DEFAULT 'telegram',
        channel_id VARCHAR(40) NULL,
        message_id VARCHAR(40) NULL,
        app_id INT NULL,
        app_name VARCHAR(190) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ok',
        note VARCHAR(255) NULL,
        created_at $ts
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

    add_column_if_missing($pdo, 'products', 'meta_description', 'VARCHAR(255) NULL');
    add_column_if_missing($pdo, 'pages', 'meta_title', 'VARCHAR(190) NULL');
    add_column_if_missing($pdo, 'pages', 'meta_description', 'VARCHAR(255) NULL');
    add_column_if_missing($pdo, 'orders', 'account_id', 'VARCHAR(190) NULL');
    add_column_if_missing($pdo, 'orders', 'receipt_image', 'VARCHAR(500) NULL');
    add_column_if_missing($pdo, 'orders', 'tx_note', 'VARCHAR(190) NULL');
    add_column_if_missing($pdo, 'categories', 'image', 'VARCHAR(500) NULL');
    add_column_if_missing($pdo, 'categories', 'color', 'VARCHAR(20) NULL');
    add_column_if_missing($pdo, 'users', 'telegram_id', 'VARCHAR(40) NULL');
    add_column_if_missing($pdo, 'products', 'source', "VARCHAR(20) NULL");
    add_column_if_missing($pdo, 'products', 'external_id', 'VARCHAR(60) NULL');
    add_column_if_missing($pdo, 'users', 'referral_code', 'VARCHAR(20) NULL');
    add_column_if_missing($pdo, 'users', 'referred_by', 'INT NULL');
    add_column_if_missing($pdo, 'users', 'last_bonus_date', 'VARCHAR(10) NULL');
    add_column_if_missing($pdo, 'orders', 'coupon_code', 'VARCHAR(40) NULL');
    add_column_if_missing($pdo, 'users', 'bio', 'VARCHAR(255) NULL');
    add_column_if_missing($pdo, 'users', 'bonus_bio_claimed', 'INT NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'users', 'bonus_profile_claimed', 'INT NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'users', 'last_spin_date', 'VARCHAR(10) NULL');
    add_column_if_missing($pdo, 'users', 'signup_fingerprint', 'VARCHAR(64) NULL');
    add_column_if_missing($pdo, 'users', 'referral_bonus_given', 'INT NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'apps', 'likes_count', 'INT NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'apps', 'dislikes_count', 'INT NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'apps', 'source', "VARCHAR(20) NULL");

    // فهارس على الأعمدة الأكثر استخداماً في الاستعلامات لتسريع تحميل الصفحات وتقليل تجمّد الموقع
    $indexes = [
        'idx_users_email' => ['users', 'email'],
        'idx_users_username' => ['users', 'username'],
        'idx_users_referred_by' => ['users', 'referred_by'],
        'idx_products_category' => ['products', 'category_id'],
        'idx_products_status' => ['products', 'status'],
        'idx_orders_user' => ['orders', 'user_id'],
        'idx_orders_product' => ['orders', 'product_id'],
        'idx_orders_status' => ['orders', 'status'],
        'idx_topups_user' => ['topup_requests', 'user_id'],
        'idx_topups_status' => ['topup_requests', 'status'],
        'idx_withdraws_user' => ['withdraw_requests', 'user_id'],
        'idx_withdraws_status' => ['withdraw_requests', 'status'],
        'idx_wallets_type_active' => ['wallets', 'type, active'],
        'idx_earnlogs_user' => ['earn_logs', 'user_id'],
        'idx_reviews_product' => ['reviews', 'product_id'],
        'idx_userdownloads_user' => ['user_downloads', 'user_id'],
        'idx_userdownloads_app' => ['user_downloads', 'app_id'],
        'idx_app_reports_app' => ['app_reports', 'app_id'],
        'idx_login_attempts_identity' => ['login_attempts', 'identity'],
        'idx_login_attempts_ip' => ['login_attempts', 'ip'],
        'idx_login_attempts_created' => ['login_attempts', 'created_at'],
        'idx_bot_scripts_status' => ['bot_scripts', 'status'],
    ];
    foreach ($indexes as $name => [$table, $cols]) {
        try {
            if (DB_DRIVER === 'sqlite') {
                $pdo->exec("CREATE INDEX IF NOT EXISTS $name ON $table ($cols)");
            } else {
                $exists = $pdo->query("SHOW INDEX FROM $table WHERE Key_name = '$name'")->fetch();
                if (!$exists) $pdo->exec("CREATE INDEX $name ON $table ($cols)");
            }
        } catch (Throwable $e) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_suggestions (
        id " . (DB_DRIVER === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY') . ",
        user_id INT NOT NULL,
        title VARCHAR(190) NOT NULL,
        details VARCHAR(500) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at " . (DB_DRIVER === 'sqlite' ? "TEXT DEFAULT (datetime('now'))" : "DATETIME DEFAULT CURRENT_TIMESTAMP") . "
    )");

    // seed default settings
    $defaults = [
        'site_name' => 'zanxpk',
        'site_description' => 'منصة zanxpk لتحميل أفضل التطبيقات والألعاب والتسوّق الإلكتروني',
        'site_keywords' => 'متجر,تطبيقات,ألعاب,تحميل,zanxpk',
        'logo_url' => '',
        'banner_title' => 'مرحباً بك في zanxpk',
        'banner_subtitle' => 'حمّل أفضل التطبيقات والألعاب وتسوّق منتجاتك المفضّلة',
        'banner_bg_image' => '',
        'footer_text' => '',
        'buy_button_text' => 'طلب شراء',
        'empty_products_text' => 'لا توجد منتجات حالياً، تابعنا قريباً',
        'theme_accent_color' => '#2563eb',
        'theme_accent2_color' => '#06b6d4',
        'moneytag_script' => '',
        'moneytag_sw_enabled' => '0',
        'moneytag_sw_content' => '',
        'turnstile_site_key' => '',
        'turnstile_secret_key' => '',
        'app_download_wait_seconds' => '5',
        'thankyou_retry_seconds' => '4',
        'thankyou_ads_html' => '',
        'adsense_client_id' => '',
        'ads_txt_content' => '',
        'satofill_markup_percent' => '15',
        'satofill_api_base' => 'https://satofill.com/api',
        'referral_bonus_points' => '100',
        'daily_bonus_points' => '20',
        'points_rate' => '0.001',      // 1 نقطة = كم دولار
        'min_withdraw_usd' => '25',
        'captcha_reward' => '10',
        'captcha_max_per_day' => '40',
        'task_max_per_day' => '10',
        'profit_split_admin' => '95',
        'profit_split_user' => '5',
        'policy_version' => '1',
        'bio_bonus_points' => '100',
        'profile_complete_bonus_points' => '350',
        'spin_reward_min' => '5',
        'spin_reward_max' => '200',
        'spin_max_per_day' => '1',
        'live_ticker_enabled' => '1',
        'referral_max_count' => '5',
        'referral_referred_cut_points' => '50',
        'welcome_bonus_points' => '200',
        'ad_enabled' => '1',
        'ad_zone_id' => '11185011',
        'auto_translate_enabled' => '1',
        'support_telegram' => '@layos_he',
        'telegram_channel_url' => '',
        'app_notify_enabled' => '1',
        'google_site_verification' => '',
        'openrouter_api_key' => '',
        'openrouter_model' => 'meta-llama/llama-3.3-70b-instruct:free',
        'openrouter_image_model' => '',
        'product_image_height' => '96',
        'cat_tile_size' => '108',
        'telegram_bot_username' => '',
        'banner_interval' => '4000',
        'banner_height' => '160',
        'home_sections_order' => 'search,carousel,ticker,live_ticker,latest_apps,cat_chips',
        'home_sections_hidden' => 'hero',
        'banner_carousel_enabled' => '1',
        'news_ticker_enabled' => '1',
        'telegram_import_enabled' => '0',
        'telegram_import_channel_id' => '',
        'telegram_import_auto_publish' => '0',
    ];
    $stmt = $pdo->prepare("INSERT INTO settings (k, v) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM settings WHERE k = ?)");
    foreach ($defaults as $k => $v) $stmt->execute([$k, $v, $k]);

    // ترحيل لمرة واحدة: إخفاء صندوق البنر الأحمر الكبير (hero) فوق شريط البحث على المواقع التي تعمل مسبقاً،
    // لأن شريط البنرات الدوّار (carousel) أصبح يغطي نفس الغرض تلقائياً بـ 3 بنرات افتراضية دون الحاجة لصندوق ثابت.
    if ((string)$pdo->query("SELECT v FROM settings WHERE k='hero_hidden_migrated'")->fetchColumn() === '') {
        $hidden = (string)$pdo->query("SELECT v FROM settings WHERE k='home_sections_hidden'")->fetchColumn();
        $hiddenParts = array_filter(array_map('trim', explode(',', $hidden)));
        if (!in_array('hero', $hiddenParts, true)) {
            $hiddenParts[] = 'hero';
            $stmt->execute(['home_sections_hidden', implode(',', $hiddenParts), '__force__']);
            $pdo->prepare(DB_DRIVER === 'sqlite'
                ? "INSERT INTO settings (k, v) VALUES ('home_sections_hidden', ?) ON CONFLICT(k) DO UPDATE SET v = ?"
                : "INSERT INTO settings (k, v) VALUES ('home_sections_hidden', ?) ON DUPLICATE KEY UPDATE v = ?")
                ->execute([implode(',', $hiddenParts), implode(',', $hiddenParts)]);
        }
        $pdo->prepare(DB_DRIVER === 'sqlite'
            ? "INSERT INTO settings (k, v) VALUES ('hero_hidden_migrated', '1') ON CONFLICT(k) DO UPDATE SET v='1'"
            : "INSERT INTO settings (k, v) VALUES ('hero_hidden_migrated', '1') ON DUPLICATE KEY UPDATE v='1'")
            ->execute();
    }

    // ترحيل لمرة واحدة: تحويل الرئيسية لتعرض فقط أحدث التطبيقات والألعاب (latest_apps)
    // ونقل قسم منتجات البيع (products) إلى صفحة "المتجر" المستقلة في القائمة الجانبية.
    if ((string)$pdo->query("SELECT v FROM settings WHERE k='home_apps_first_migrated'")->fetchColumn() === '') {
        $order = (string)$pdo->query("SELECT v FROM settings WHERE k='home_sections_order'")->fetchColumn();
        $orderParts = array_filter(array_map('trim', explode(',', $order)));
        $orderParts = array_values(array_diff($orderParts, ['products']));
        if (!in_array('latest_apps', $orderParts, true)) {
            $pos = array_search('cat_chips', $orderParts, true);
            if ($pos !== false) array_splice($orderParts, $pos + 1, 0, ['latest_apps']);
            else $orderParts[] = 'latest_apps';
        }
        $newOrder = implode(',', $orderParts);
        $pdo->prepare(DB_DRIVER === 'sqlite'
            ? "INSERT INTO settings (k, v) VALUES ('home_sections_order', ?) ON CONFLICT(k) DO UPDATE SET v = ?"
            : "INSERT INTO settings (k, v) VALUES ('home_sections_order', ?) ON DUPLICATE KEY UPDATE v = ?")
            ->execute([$newOrder, $newOrder]);
        $pdo->prepare(DB_DRIVER === 'sqlite'
            ? "INSERT INTO settings (k, v) VALUES ('home_apps_first_migrated', '1') ON CONFLICT(k) DO UPDATE SET v='1'"
            : "INSERT INTO settings (k, v) VALUES ('home_apps_first_migrated', '1') ON DUPLICATE KEY UPDATE v='1'")
            ->execute();
    }

    // ترحيل لمرة واحدة (قديم، أصبح ملغياً): كان يفرض موديل gpt-4o المدفوع، وهذا ما كان يسبب رسائل
    // "رصيد غير كافٍ" لمستخدمي المفاتيح المجانية. تم إلغاؤه بالترحيل التالي.
    if ((string)$pdo->query("SELECT v FROM settings WHERE k='ai_model_default_migrated'")->fetchColumn() === '') {
        $pdo->prepare(DB_DRIVER === 'sqlite'
            ? "INSERT INTO settings (k, v) VALUES ('ai_model_default_migrated', '1') ON CONFLICT(k) DO UPDATE SET v='1'"
            : "INSERT INTO settings (k, v) VALUES ('ai_model_default_migrated', '1') ON DUPLICATE KEY UPDATE v='1'")
            ->execute();
    }

    // ترحيل لمرة واحدة: إصلاح مشكلة "رصيد غير كافٍ" — الترحيل السابق كان يفرض موديل openai/gpt-4o المدفوع
    // على كل المواقع، وهو موديل لا تعمل معه المفاتيح المجانية لـ OpenRouter. نعيد الموديل الافتراضي
    // إلى موديل مجاني يعمل فعلياً مع مفاتيح OpenRouter المجانية، ونفعّل أيضاً نظام التبديل التلقائي
    // بين عدة موديلات مجانية عند فشل الموديل المختار (انظر openrouter_chat).
    if ((string)$pdo->query("SELECT v FROM settings WHERE k='ai_free_model_fix_migrated'")->fetchColumn() === '') {
        $curModel = (string)$pdo->query("SELECT v FROM settings WHERE k='openrouter_model'")->fetchColumn();
        if ($curModel === '' || $curModel === 'openai/gpt-4o') {
            $freeModel = 'meta-llama/llama-3.3-70b-instruct:free';
            $pdo->prepare(DB_DRIVER === 'sqlite'
                ? "INSERT INTO settings (k, v) VALUES ('openrouter_model', ?) ON CONFLICT(k) DO UPDATE SET v=?"
                : "INSERT INTO settings (k, v) VALUES ('openrouter_model', ?) ON DUPLICATE KEY UPDATE v=?")
                ->execute([$freeModel, $freeModel]);
        }
        $pdo->prepare(DB_DRIVER === 'sqlite'
            ? "INSERT INTO settings (k, v) VALUES ('ai_free_model_fix_migrated', '1') ON CONFLICT(k) DO UPDATE SET v='1'"
            : "INSERT INTO settings (k, v) VALUES ('ai_free_model_fix_migrated', '1') ON DUPLICATE KEY UPDATE v='1'")
            ->execute();
    }

    // ترحيل لمرة واحدة: ضمان ظهور شريط البنرات الدوّار (carousel) فوق قسم "أحدث التطبيقات" دوماً،
    // وإضافة مفاتيح تفعيل/تعطيل دائمة للبنرات والشريط الإخباري.
    if ((string)$pdo->query("SELECT v FROM settings WHERE k='banner_top_fix_migrated'")->fetchColumn() === '') {
        $order = (string)$pdo->query("SELECT v FROM settings WHERE k='home_sections_order'")->fetchColumn();
        $orderParts = array_values(array_filter(array_map('trim', explode(',', $order))));
        $carouselPos = array_search('carousel', $orderParts, true);
        $appsPos = array_search('latest_apps', $orderParts, true);
        if ($carouselPos !== false && $appsPos !== false && $carouselPos > $appsPos) {
            array_splice($orderParts, $carouselPos, 1);
            $appsPos = array_search('latest_apps', $orderParts, true);
            array_splice($orderParts, $appsPos, 0, ['carousel']);
            $newOrder = implode(',', $orderParts);
            $pdo->prepare(DB_DRIVER === 'sqlite'
                ? "INSERT INTO settings (k, v) VALUES ('home_sections_order', ?) ON CONFLICT(k) DO UPDATE SET v=?"
                : "INSERT INTO settings (k, v) VALUES ('home_sections_order', ?) ON DUPLICATE KEY UPDATE v=?")
                ->execute([$newOrder, $newOrder]);
        }
        $pdo->prepare(DB_DRIVER === 'sqlite'
            ? "INSERT INTO settings (k, v) VALUES ('banner_top_fix_migrated', '1') ON CONFLICT(k) DO UPDATE SET v='1'"
            : "INSERT INTO settings (k, v) VALUES ('banner_top_fix_migrated', '1') ON DUPLICATE KEY UPDATE v='1'")
            ->execute();
    }

    $siteNameForSeed = setting('site_name', 'Yassota');
    $pagesSeed = [
        'privacy' => "نحن في {$siteNameForSeed} نحترم خصوصيتك ونلتزم بحماية بياناتك الشخصية. توضّح هذه السياسة كيفية جمع واستخدام وحماية معلوماتك عند استخدام الموقع.\n\nالمعلومات التي نجمعها: عند التسجيل نجمع الاسم، البريد الإلكتروني، واسم المستخدم. عند استخدام تسجيل الدخول عبر جوجل أو تيليجرام نستلم فقط البيانات الأساسية للملف العام (الاسم والبريد/الصورة). كما نسجّل بيانات تقنية تلقائية مثل عنوان IP ونوع المتصفح لأغراض الأمان ومنع الاحتيال.\n\nاستخدام البيانات: نستخدم بياناتك لتفعيل حسابك، معالجة الطلبات والتحميلات، التواصل معك بخصوص الدعم الفني، وتحسين تجربة الاستخدام. لا نبيع بياناتك الشخصية لأي طرف ثالث.\n\nملفات تعريف الارتباط (Cookies): يستخدم الموقع ملفات تعريف الارتباط لحفظ جلسة تسجيل الدخول وتفضيلات اللغة، وقد تستخدمها شبكات الإعلانات الخارجية (مثل Google Translate وشبكات الإعلانات المعروضة) لأغراض تحسين المحتوى المعروض.\n\nالإعلانات وأطراف ثالثة: قد يعرض الموقع إعلانات من شبكات إعلانية خارجية، وقد تستخدم هذه الشبكات تقنياتها الخاصة لجمع بيانات غير شخصية لتحسين الإعلانات المعروضة. كما تُستخدم خدمة الذكاء الاصطناعي (OpenRouter) لتوليد محتوى وصفي للتطبيقات دون إرسال أي بيانات شخصية للمستخدمين إليها.\n\nملفات APK ومحتوى التطبيقات: روابط التحميل المتوفرة على الموقع يقدّمها أو يرفعها ناشرو المحتوى، ونوصي دوماً بفحص أي ملف قبل تثبيته. الموقع غير مسؤول عن محتوى التطبيقات الخارجية بعد تحميلها.\n\nحقوقك: يمكنك طلب تعديل أو حذف بياناتك أو حسابك بالتواصل معنا عبر صفحة الدعم الفني.\n\nالتعديلات: قد نقوم بتحديث سياسة الخصوصية من وقت لآخر، وسيتم نشر أي تعديل على هذه الصفحة.",
        'terms' => "باستخدامك لموقع {$siteNameForSeed} فإنك توافق على الالتزام بشروط الاستخدام التالية. يرجى قراءتها بعناية قبل استخدام الموقع.\n\n1) طبيعة الخدمة: يقدّم الموقع روابط تحميل لتطبيقات وألعاب أندرويد ومنتجات رقمية، بعضها معدّل (مهكّر/مود) لأغراض تجريبية وتعليمية. استخدامك لهذه التطبيقات يقع على مسؤوليتك الخاصة بالكامل.\n\n2) لا ضمانات: يُقدَّم المحتوى \"كما هو\" دون أي ضمان صريح أو ضمني بشأن خلوّه من الأخطاء أو ملاءمته لغرض معيّن. لا نضمن استمرار عمل أي رابط تحميل أو توافقه مع جميع الأجهزة.\n\n3) حقوق الملكية الفكرية: جميع العلامات التجارية وأسماء التطبيقات المذكورة في الموقع مملوكة لأصحابها الأصليين، ولا يمثّل عرضها أي ارتباط أو تأييد رسمي من تلك الشركات للموقع.\n\n4) سلوك المستخدم: يُمنع استخدام الموقع لأي غرض غير قانوني، أو محاولة اختراقه، أو نشر محتوى مخالف عبر التعليقات أو الحسابات.\n\n5) الحسابات: أنت مسؤول عن سرية بيانات حسابك وكل نشاط يتم من خلاله. نحتفظ بحق تعليق أو حذف أي حساب يخالف هذه الشروط.\n\n6) الإعلانات والروابط الخارجية: قد يحتوي الموقع على إعلانات أو روابط لمواقع خارجية، ولسنا مسؤولين عن محتوى أو سياسات تلك المواقع.\n\n7) حدود المسؤولية: لا يتحمل {$siteNameForSeed} أي مسؤولية عن أضرار مباشرة أو غير مباشرة تنتج عن استخدام التطبيقات أو الملفات المحمّلة من الموقع.\n\n8) التعديلات: نحتفظ بحق تعديل هذه الشروط في أي وقت، ويُعتبر استمرارك باستخدام الموقع موافقة على التعديلات.\n\n9) التواصل: لأي استفسار قانوني يمكنك التواصل معنا عبر صفحة الدعم الفني.",
        'about' => "{$siteNameForSeed} هو متجر عربي شامل لتطبيقات وألعاب أندرويد، يقدّم نسخاً أصلية ومعدّلة (مهكّرة) لأشهر التطبيقات والألعاب مع شرح مفصّل لكل تطبيق: المميزات، الصلاحيات المطلوبة، لقطات الشاشة، وروابط تحميل مباشرة وسريعة دون تعقيد.\n\nماذا نقدّم؟\n• تحميل تطبيقات وألعاب أندرويد محدّثة باستمرار.\n• وصف تفصيلي ومولّد بالذكاء الاصطناعي لكل تطبيق لمساعدتك على فهم مميزاته بسرعة.\n• متجر منتجات رقمية مستقل (بطاقات شحن، خدمات رقمية).\n• نظام تقييم شفاف (إعجاب/عدم إعجاب) لكل تطبيق يعكس رأي المستخدمين الحقيقيين.\n• دعم فني سريع عبر تيليجرام.\n\nنحرص في {$siteNameForSeed} على تحديث المحتوى باستمرار وتقديم تجربة استخدام سريعة وسلسة بدون تعقيدات.",
        'contact' => 'لأي استفسار أو دعم فني يمكنك التواصل معنا عبر تيليجرام: ' . ($defaults['support_telegram'] ?? '@layos_he') . ' وسيتم الرد عليك في أقرب وقت ممكن. فريق الدعم متاح للإجابة عن استفساراتك المتعلقة بالتحميلات، الحسابات، أو المنتجات الرقمية على مدار الأسبوع.',
        'faq' => "س: كيف أحمّل تطبيقاً أو لعبة؟\nج: افتح صفحة التطبيق، اضغط «تحميل الآن»، انتظر العد التنازلي القصير ثم اضغط رابط التحميل المباشر.\n\nس: هل التطبيقات المعدّلة (المهكّرة) آمنة؟\nج: نحرص على فحص الروابط المعروضة، لكننا ننصح دوماً بفحص أي ملف بعد التحميل قبل تثبيته كإجراء احتياطي.\n\nس: التطبيق لا يعمل بعد التثبيت، ما الحل؟\nج: تأكد من توافق إصدار أندرويد لديك مع المتطلبات المذكورة بصفحة التطبيق، وتأكد من تفعيل «تثبيت من مصادر غير معروفة».\n\nس: كيف أشتري منتجاً من المتجر؟\nج: اختر المنتج واضغط طلب شراء، ثم أكمل عملية الدفع عبر أحد طرق الدفع المتاحة وأرفق إيصال التحويل، وسيقوم فريقنا بمراجعة الطلب وتفعيله.\n\nس: كم تستغرق معالجة الطلب؟\nج: عادة بين دقائق وحتى 24 ساعة بحسب نوع المنتج.\n\nس: هل يلزم تسجيل الدخول للتحميل؟\nج: لا، يمكنك تحميل أي تطبيق أو لعبة مباشرة بدون تسجيل دخول. التسجيل مطلوب فقط للشراء أو حفظ المفضّلة.\n\nس: نسيت كلمة المرور، ماذا أفعل؟\nج: استخدم رابط استعادة كلمة المرور من صفحة تسجيل الدخول.",
        'guide' => "تحميل تطبيقات وألعاب أندرويد بأمان أصبح أسهل عبر {$siteNameForSeed}. في هذا الدليل نشرح خطوة بخطوة كيفية تحميل وتثبيت أي تطبيق أو لعبة من الموقع دون أي تعقيد، بدون الحاجة لتسجيل الدخول.\n\n1) ابحث عن التطبيق أو اللعبة عبر شريط البحث أو من خلال تصنيفات التطبيقات والألعاب في الصفحة الرئيسية.\n2) افتح صفحة التطبيق لقراءة الوصف، الصلاحيات المطلوبة، حجم الملف، والإصدار المتوافق.\n3) اضغط زر «تحميل الآن» وانتظر ثواني قليلة حتى يظهر رابط التحميل المباشر.\n4) بعد انتهاء التحميل، فعّل خيار «تثبيت من مصادر غير معروفة» من إعدادات هاتفك إذا طُلب منك ذلك، ثم افتح الملف لتثبيته.\n5) ننصح دوماً بفحص أي ملف APK باستخدام برنامج حماية قبل التثبيت، خصوصاً النسخ المعدّلة (المهكّرة).\n\nلماذا {$siteNameForSeed}؟ لأننا نوفّر تحميلاً مباشراً وسريعاً بدون روابط مختصرة مزيفة، مع تحديث مستمر لقاعدة التطبيقات والألعاب وتفاصيل دقيقة لكل إصدار.",
        'top' => "إليك قائمة محدّثة بأفضل تطبيقات وألعاب أندرويد المتوفرة حالياً على {$siteNameForSeed}، شاملة النسخ الأصلية والمعدّلة (مود) الأكثر طلباً من زوار الموقع.\n\nأفضل فئات التطبيقات: تطبيقات التواصل الاجتماعي، تطبيقات تحرير الصور والفيديو، تطبيقات الإنتاجية والأدوات، وتطبيقات البث والمشاهدة.\n\nأفضل فئات الألعاب: ألعاب الأكشن والمغامرات، ألعاب الإستراتيجية، ألعاب الرياضة، وألعاب الذكاء والتسلية الخفيفة. كثير من هذه الألعاب متوفرة بنسخ معدّلة تتضمن مزايا إضافية مثل الأموال غير المحدودة أو فتح كل المراحل.\n\nيتم تحديث هذه القائمة باستمرار بناءً على أحدث الإضافات وأكثر التطبيقات تحميلاً، تابع صفحة التطبيقات والألعاب للحصول على كل الإضافات الجديدة فور نشرها.",
    ];
    foreach ($pagesSeed as $slug => $content) {
        $st = $pdo->prepare("INSERT INTO pages (slug, content) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM pages WHERE slug = ?)");
        $st->execute([$slug, $content, $slug]);
    }

    if ((string)$pdo->query("SELECT v FROM settings WHERE k='pages_content_v2_migrated'")->fetchColumn() === '') {
        foreach (['privacy', 'terms', 'about'] as $slug) {
            $pdo->prepare("UPDATE pages SET content = ? WHERE slug = ? AND (content LIKE '%...' OR content = '')")
                ->execute([$pagesSeed[$slug], $slug]);
        }
        $pdo->prepare(DB_DRIVER === 'sqlite'
            ? "INSERT INTO settings (k, v) VALUES ('pages_content_v2_migrated', '1') ON CONFLICT(k) DO UPDATE SET v='1'"
            : "INSERT INTO settings (k, v) VALUES ('pages_content_v2_migrated', '1') ON DUPLICATE KEY UPDATE v='1'")
            ->execute();
    }

    // ترحيل لمرة واحدة: تعديل اسم الموقع إلى zanxpk حتى للمواقع المثبّتة مسبقاً بالاسم القديم.
    if ((string)$pdo->query("SELECT v FROM settings WHERE k='site_rename_zanxpk_migrated'")->fetchColumn() === '') {
        $pdo->prepare(DB_DRIVER === 'sqlite'
            ? "INSERT INTO settings (k, v) VALUES ('site_name', 'zanxpk') ON CONFLICT(k) DO UPDATE SET v='zanxpk'"
            : "INSERT INTO settings (k, v) VALUES ('site_name', 'zanxpk') ON DUPLICATE KEY UPDATE v='zanxpk'")
            ->execute();
        $pdo->prepare(DB_DRIVER === 'sqlite'
            ? "INSERT INTO settings (k, v) VALUES ('site_rename_zanxpk_migrated', '1') ON CONFLICT(k) DO UPDATE SET v='1'"
            : "INSERT INTO settings (k, v) VALUES ('site_rename_zanxpk_migrated', '1') ON DUPLICATE KEY UPDATE v='1'")
            ->execute();
    }

    $walletCount = (int)$pdo->query("SELECT COUNT(*) c FROM wallets")->fetch()['c'];
    if ($walletCount === 0) {
        $pdo->prepare("INSERT INTO wallets (type, label, address) VALUES (?,?,?)")
            ->execute(['usdt', 'USDT (شحن المحفظة)', '5e87321b9ab229a23cdce035290b10cb']);
    }
    $shamCount = (int)$pdo->query("SELECT COUNT(*) c FROM wallets WHERE type='sham'")->fetch()['c'];
    if ($shamCount === 0) {
        $pdo->prepare("INSERT INTO wallets (type, label, address, active) VALUES (?,?,?,0)")
            ->execute(['sham', 'الشام كاش (شحن المحفظة)', 'ضع رقم محفظتك من لوحة الإدارة']);
    }
    // تصحيح بيانات قديمة: إن وُجدت أكثر من محفظة مفعّلة لنفس وسيلة الدفع (مثل ظهور الشام كاش مرتين)، نُبقي الأحدث فقط مفعّلة
    $dupTypes = $pdo->query("SELECT type FROM wallets WHERE active=1 GROUP BY type HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($dupTypes as $dupType) {
        $st = $pdo->prepare("SELECT id FROM wallets WHERE type=? AND active=1 ORDER BY id DESC");
        $st->execute([$dupType]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        array_shift($ids);
        if ($ids) {
            $pdo->prepare("UPDATE wallets SET active=0 WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")
                ->execute($ids);
        }
    }

    $bannerCount = (int)$pdo->query("SELECT COUNT(*) c FROM banners")->fetch()['c'];
    if ($bannerCount === 0) {
        $bannerDir = __DIR__ . '/uploads/banners';
        if (!is_dir($bannerDir)) mkdir($bannerDir, 0755, true);
        $bannerSeeds = [
            ['تسوّق الآن واربح نقاطاً', '#2563eb', '#06b6d4'],
            ['اسحب أرباحك فوراً', '#1e63d6', '#3fa9f5'],
            ['عروض حصرية كل يوم', '#1ea672', '#34d399'],
        ];
        foreach ($bannerSeeds as $i => [$text, $c1, $c2]) {
            $w = 1200; $h = 360;
            $im = imagecreatetruecolor($w, $h);
            [$r1, $g1, $b1] = sscanf($c1, "#%02x%02x%02x");
            [$r2, $g2, $b2] = sscanf($c2, "#%02x%02x%02x");
            for ($x = 0; $x < $w; $x++) {
                $ratio = $x / $w;
                $col = imagecolorallocate($im, (int)($r1 + ($r2 - $r1) * $ratio), (int)($g1 + ($g2 - $g1) * $ratio), (int)($b1 + ($b2 - $b1) * $ratio));
                imageline($im, $x, 0, $x, $h, $col);
            }
            $white = imagecolorallocate($im, 255, 255, 255);
            imagestring($im, 5, 40, (int)($h / 2) - 8, $text, $white);
            $filename = 'seed_banner_' . ($i + 1) . '.jpg';
            imagejpeg($im, $bannerDir . '/' . $filename, 85);
            imagedestroy($im);
            $pdo->prepare("INSERT INTO banners (image, link, sort_order) VALUES (?,?,?)")
                ->execute(['uploads/banners/' . $filename, null, $i]);
        }
    }

    // ترحيل مفتاح OpenRouter الفردي القديم إلى نظام المفاتيح المتعددة الجديد (مرة واحدة فقط)
    $oldOrKey = (string)$pdo->query("SELECT v FROM settings WHERE k='openrouter_api_key'")->fetchColumn();
    if ($oldOrKey !== '') {
        $orKeysCount = (int)$pdo->query("SELECT COUNT(*) c FROM openrouter_keys")->fetch()['c'];
        if ($orKeysCount === 0) {
            $pdo->prepare("INSERT INTO openrouter_keys (label, api_key) VALUES (?,?)")->execute(['مفتاح مستورد', $oldOrKey]);
        }
    }

    // ترحيل لمرة واحدة: حذف منتجات المتجر الافتراضية/الوهمية التي كانت تُضاف تلقائياً عند أول تشغيل
    // (كانت تُسبب صعوبة تمييز منتجات الأدمن الحقيقية وسط بيانات تجريبية). لا تُضاف أي منتجات افتراضية بعد الآن.
    if ((string)$pdo->query("SELECT v FROM settings WHERE k='fake_products_removed_migrated'")->fetchColumn() === '') {
        $fakeNames = [
        'شحن ببجي موبايل 60 UC',
        'شحن ببجي موبايل 325 UC',
        'شحن ببجي موبايل 660 UC',
        'شحن فري فاير 100 جوهرة',
        'شحن فري فاير 520 جوهرة',
        'شحن فورتنايت 1000 V-Bucks',
        'شحن كول أوف ديوتي موبايل 80 CP',
        'شحن ليج أوف ليجندز RP',
        'شحن جواهر كلاش أوف كلانس',
        'شحن نقاط فيفا موبايل',
        'شحن روبلوكس 400 Robux',
        'شحن ماين كرافت كوينز',
        'شحن جواكر شدات',
        'شحن سوبر سيل جواهر',
        'شحن جواهر متفرقة (عام)',
        'بطاقة آيتونز 10$',
        'بطاقة آيتونز 25$',
        'بطاقة جوجل بلاي 10$',
        'بطاقة جوجل بلاي 25$',
        'بطاقة ستيم 10$',
        'بطاقة ستيم 20$',
        'بطاقة أمازون 25$',
        'بطاقة بلايستيشن 10$',
        'بطاقة إكس بوكس 10$',
        'بطاقة نتفليكس هدية',
        'اشتراك نتفليكس شهر',
        'اشتراك نتفليكس 3 أشهر',
        'اشتراك شاهد VIP شهر',
        'اشتراك سبوتيفاي بريميوم شهر',
        'اشتراك يوتيوب بريميوم شهر',
        'اشتراك ديزني بلس شهر',
        'اشتراك كانفا برو شهر',
        'اشتراك ChatGPT Plus شهر',
        'اشتراك مايكروسوفت 365 شهر',
        'اشتراك آيكلود تخزين 50GB',
        'شحن تيك توك كوينز',
        'متابعين انستقرام 1000',
        'اشتراك تيليجرام بريميوم شهر',
        'اشتراك ديسكورد نيترو شهر',
        'اشتراك زووم برو شهر',
        'حماية كاسبرسكي سنة',
        'اشتراك VPN بريميوم شهر',
        'اشتراك أدوبي فوتوشوب شهر',
        'اشتراك WPS Office بريميوم',
        'اشتراك Grammarly بريميوم',
        'شحن رصيد سيرياتيل 5$',
        'شحن رصيد MTN سوريا 5$',
        'بطاقة Visa افتراضية 10$',
        'قسيمة شحن عام 5$',
        'قسيمة شحن عام 10$',
        'شحن ببجي موبايل 1800 UC',
        'شحن ببجي موبايل 3850 UC',
        'شحن فري فاير 1080 جوهرة',
        'نجوم تيليجرام Telegram Stars',
        'شحن جواهر جواكر 100 ألف',
        'اشتراك بلايستيشن بلس شهر',
        'اشتراك Xbox Game Pass Ultimate شهر',
        'اشتراك أمازون برايم شهر',
        'اشتراك سبوتيفاي عائلي شهر',
        'اشتراك يوتيوب بريميوم عائلي',
        'اشتراك آبل ميوزك شهر',
        'اشتراك ديسكورد نيترو سنة',
        'متابعين تيك توك 1000',
        'لايكات انستقرام 1000',
        'اشتراك X (تويتر) بريميوم شهر',
        'اشتراك LinkedIn بريميوم شهر',
        'اشتراك Duolingo Super شهر',
        'بطاقة Google Play 50$',
        'بطاقة ستيم 50$',
        'شحن رصيد سيرياتيل 10$',
        'بطاقة Valorant Points 10$',
        'بطاقة فري فاير الذهبية',
        'بطاقة Razer Gold 10$',
        'بطاقة Garena Shells',
        'بطاقة eBay 25$',
        'بطاقة Walmart 25$',
        'بطاقة Target 25$',
        'بطاقة Roblox 25$',
        'اشتراك Twitch Turbo شهر',
        'اشتراك Hulu شهر',
        'اشتراك HBO Max شهر',
        'اشتراك Audible شهر',
        'اشتراك NordVPN سنة',
        'شحن متابعين تويتر 1000',
        'شحن مشاهدات يوتيوب 1000',
        'اشتراك Notion Plus شهر',
        'اشتراك Canva Teams شهر',
        'شحن رصيد فودافون مصر',
        'شحن رصيد أورنج مصر',
        'شحن رصيد STC السعودية',
        'شحن رصيد موبايلي السعودية',
        'شحن رصيد زين السعودية',
        'شحن رصيد دو الإمارات',
        'شحن رصيد اتصالات الإمارات',
        'شحن رصيد Ooredoo قطر',
        'شحن رصيد Zain العراق',
        'بطاقة Visa افتراضية 25$',
        'بطاقة Visa افتراضية 50$',
        'قسيمة شحن عام 25$',
        'قسيمة شحن عام 50$'
        ];
        $delStmt = $pdo->prepare("DELETE FROM products WHERE name = ? AND source IS NULL");
        foreach ($fakeNames as $fn) $delStmt->execute([$fn]);
        $pdo->prepare(DB_DRIVER === 'sqlite'
            ? "INSERT INTO settings (k, v) VALUES ('fake_products_removed_migrated', '1') ON CONFLICT(k) DO UPDATE SET v='1'"
            : "INSERT INTO settings (k, v) VALUES ('fake_products_removed_migrated', '1') ON DUPLICATE KEY UPDATE v='1'")
            ->execute();
    }

    // ترحيل لمرة واحدة: تزويد قسم "بوتات وسكربتات" بقوالب جاهزة افتراضية (ملفاتها داخل مجلد bot_templates/)
    if ((string)$pdo->query("SELECT v FROM settings WHERE k='bot_templates_seeded'")->fetchColumn() === '') {
        $templates = [
            ['بوت تيليجرام لتحميل الفيديوهات', 'قالب بوت تيليجرام جاهز يستقبل رابط فيديو (يوتيوب/تيك توك/فيسبوك...) من المستخدم ويستخدم yt-dlp لجلب رابط تحميل مباشر وإرساله، قابل للتعديل والتركيب على أي سيرفر يدعم PHP وتنفيذ أوامر النظام.', 'telegram_bot', 'send', 'bot_templates/video_downloader_bot.php', '1.0'],
            ['بوت تيليجرام للرد التلقائي والقوائم', 'قالب بوت تيليجرام بسيط بقوائم أزرار وردود تلقائية جاهز كنقطة بداية لبناء أي بوت خدمة عملاء أو دعم.', 'telegram_bot', 'terminal', 'bot_templates/auto_reply_bot.php', '1.0'],
        ];
        $insTpl = $pdo->prepare("INSERT INTO bot_scripts (name, description, category, icon, file_path, version, is_template, status) VALUES (?,?,?,?,?,?,1,'active')");
        foreach ($templates as $t) $insTpl->execute($t);
        $pdo->prepare(DB_DRIVER === 'sqlite'
            ? "INSERT INTO settings (k, v) VALUES ('bot_templates_seeded', '1') ON CONFLICT(k) DO UPDATE SET v='1'"
            : "INSERT INTO settings (k, v) VALUES ('bot_templates_seeded', '1') ON DUPLICATE KEY UPDATE v='1'")
            ->execute();
    }
}
migrate();
if (setting('moneytag_sw_enabled', '0') === '1' && !is_file(__DIR__ . '/sw.js')) write_moneytag_sw_file();

/* ======================================================================
   2) HELPERS
   ====================================================================== */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function app_canonical_url(array $a): string { return rtrim(SITE_URL, '/') . '/index.php?page=app&id=' . (int)$a['id']; }

/* ---- Professional inline SVG icon set (no emojis) ---- */
function wallet_type_label(string $type): array
{
    $map = [
        'usdt' => ['USDT (TRC20)', 'coins'],
        'sham' => ['الشام كاش', 'wallet'],
        'binance' => ['Binance Pay', 'coins'],
        'crypto' => ['عملات مشفرة', 'coins'],
        'payeer' => ['Payeer', 'wallet'],
        'syriatel_cash' => ['سيرياتيل كاش', 'wallet'],
        'mtn_cash' => ['MTN كاش', 'wallet'],
        'bank_transfer' => ['حوالة بنكية', 'bank'],
        'western_union' => ['ويسترن يونيون', 'bank'],
    ];
    return $map[$type] ?? [$type, 'wallet'];
}

function icon(string $name, string $class = 'ic'): string
{
    if ($name === 'google') {
        return '<svg class="' . e($class) . '" viewBox="0 0 48 48" aria-hidden="true">'
            . '<path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.1 8 3l5.7-5.7C34.5 6.2 29.5 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.7-.4-3.9z"/>'
            . '<path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.6 16 19 13 24 13c3.1 0 5.9 1.1 8 3l5.7-5.7C34.5 6.2 29.5 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>'
            . '<path fill="#4CAF50" d="M24 44c5.4 0 10.3-2.1 14-5.5l-6.5-5.4C29.5 34.7 26.9 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.6 5.1C9.6 39.6 16.3 44 24 44z"/>'
            . '<path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.3-2.2 4.2-4.1 5.6l6.5 5.4C41.5 35.6 44 30.2 44 24c0-1.3-.1-2.7-.4-3.9z"/>'
            . '</svg>';
    }
    $paths = [
        'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M9.5 20v-6h5v6"/>',
        'coin' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5c0-1.2 1-2 2.5-2s2.5.8 2.5 2-1 1.6-2.5 2-2.5.8-2.5 2 1 2 2.5 2 2.5-.8 2.5-2"/>',
        'tasks' => '<rect x="4" y="3.5" width="16" height="17" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><circle cx="16.5" cy="14.5" r="1.2"/>',
        'orders' => '<path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="M4 7.5 12 12l8-4.5M12 12v9"/>',
        'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'doc' => '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
        'admin' => '<path d="M12 3 4 6v6c0 5 3.5 7.5 8 9 4.5-1.5 8-4 8-9V6z"/><path d="M9.5 12l1.8 1.8L15 10.2"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3.1-6 7-6s7 2.5 7 6"/>',
        'logout' => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M16 16l4-4-4-4M20 12H9"/>',
        'cart' => '<circle cx="9" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/><path d="M3 4h2l2.2 11h10.6L20 7H6.3"/>',
        'check' => '<path d="M5 12.5 9.5 17 19 7"/>',
        'refresh' => '<path d="M4 12a8 8 0 0 1 14-5.3L20 8"/><path d="M20 4v4h-4"/><path d="M20 12a8 8 0 0 1-14 5.3L4 16"/><path d="M4 20v-4h4"/>',
        'heart' => '<path d="M12 20.5s-7.5-4.6-10-9.3C0.4 8 2 4.5 5.5 4c2-0.3 3.8 0.6 5 2.2C11.7 4.6 13.5 3.7 15.5 4 19 4.5 20.6 8 19 11.2c-2.5 4.7-7 9.3-7 9.3z"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.3-4.3"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'edit' => '<path d="M4 17.5 14.5 7l3 3L7 20.5H4z"/><path d="M13 8 16.5 4.5l3 3L16 11"/>',
        'trash' => '<path d="M5 7h14M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m1 0-.8 12.2a2 2 0 0 1-2 1.8H10.8a2 2 0 0 1-2-1.8L8 7"/>',
        'toggle' => '<rect x="3" y="8" width="18" height="8" rx="4"/><circle cx="8" cy="12" r="2.6"/>',
        'image' => '<rect x="3.5" y="5" width="17" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M5 17l4.5-4.5 3 3L17.5 11l1.5 1.5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.3.9a7 7 0 0 0-2-1.2L14.2 3H9.8l-.4 2.6a7 7 0 0 0-2 1.2l-2.3-.9-2 3.4 2 1.5A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.3-.9c.6.5 1.3.9 2 1.2l.4 2.6h4.4l.4-2.6c.7-.3 1.4-.7 2-1.2l2.3.9 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'megaphone' => '<path d="M3 10v4l3 .6V18a1.5 1.5 0 0 0 3 0v-2.8l9 1.8V9L9 10.8 6 10z"/>',
        'chart' => '<path d="M4 19h16M7 19V11M12 19V6M17 19v-8"/>',
        'gift' => '<rect x="4" y="10" width="16" height="10" rx="1.5"/><path d="M4 10h16M12 10v10"/><path d="M12 10c-3 0-4-1.6-4-3a2.2 2.2 0 0 1 4-1.3c.3-.4.6-.7 1-.9M12 10c3 0 4-1.6 4-3a2.2 2.2 0 0 0-4-1.3c-.3-.4-.6-.7-1-.9"/>',
        'pages' => '<path d="M6 3h9l5 5v13H6z"/><path d="M15 3v5h5M9 12h6M9 16h6"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><circle cx="17" cy="9" r="2.4"/><path d="M15.5 14.2c2.6.4 4.5 2.2 4.5 4.8"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a13 13 0 0 1 0 18M12 3a13 13 0 0 0 0 18"/>',
        'upload' => '<path d="M12 16V4M7.5 8.5 12 4l4.5 4.5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'rocket' => '<path d="M12 3c3 1.5 5 4.5 5 8.5L12 15l-5-3.5C7 7.5 9 4.5 12 3z"/><path d="M9 14l-2.5 1.5L7 18l2.5-1M15 14l2.5 1.5L17 18l-2.5-1"/><circle cx="12" cy="9" r="1.3"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'bank' => '<path d="M3 10 12 4l9 6"/><path d="M4 10h16v2H4z"/><path d="M6 12v7M10 12v7M14 12v7M18 12v7"/><path d="M3 21h18"/>',
        'send' => '<path d="M4 12 20 4 13 20l-2-6-7-2z"/>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="1.5"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
        'history' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/><path d="M3.5 9 3 6l-2.7 1"/>',
        'shield' => '<path d="M12 3 5 6v6c0 5 3 7.5 7 9 4-1.5 7-4 7-9V6z"/><path d="M9.2 12l1.8 1.8L15 10.2"/>',
        'coins' => '<circle cx="9" cy="9" r="5.5"/><path d="M15 9.3c2.7.5 4.5 2 4.5 4.2 0 3-2.9 5-6.5 5-2.7 0-5-1.1-6.1-2.7"/>',
        'minus' => '<path d="M5 12h14"/>',
        'chevron-up' => '<path d="M5 15l7-7 7 7"/>',
        'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
        'star' => '<path d="M12 3.5 14.6 9l6 .9-4.3 4.2 1 6L12 17.3 6.7 20l1-6L3.4 9.9 9.4 9z"/>',
        'hat' => '<path d="M4 13.5c0-4.5 3.6-8 8-8s8 3.5 8 8"/><ellipse cx="12" cy="13.5" rx="9" ry="2.2"/><path d="M9 6.2C9.3 4.3 10.5 3 12 3s2.7 1.3 3 3.2"/>',
        'terminal' => '<rect x="3" y="4.5" width="18" height="15" rx="2"/><path d="M7 9.5 11 12l-4 2.5M13 15h4"/>',
        'android' => '<path d="M7 9.5h10v8a1.5 1.5 0 0 1-1.5 1.5h-7A1.5 1.5 0 0 1 7 17.5z"/><path d="M7 12H4.5M20 12h-2.5M9 5l-1.3-1.8M15 5l1.3-1.8"/><path d="M7 9.5a5 5 0 0 1 10 0"/><circle cx="9.7" cy="7" r=".4" fill="currentColor"/><circle cx="14.3" cy="7" r=".4" fill="currentColor"/><path d="M8 21v-2M16 21v-2"/>',
        'download' => '<path d="M12 4v11M7.5 11.5 12 16l4.5-4.5"/><path d="M4 19h16"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'play' => '<path d="M7 4.5v15l13-7.5z"/>',
        'thumb-up' => '<path d="M7 11v9H4a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1z"/><path d="M7 11l4-7a2 2 0 0 1 3.6 1.7L13.7 9H19a2 2 0 0 1 2 2.3l-1.2 7A2 2 0 0 1 17.8 20H10a3 3 0 0 1-3-3"/>',
        'thumb-down' => '<path d="M17 13V4h3a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1z"/><path d="M17 13l-4 7a2 2 0 0 1-3.6-1.7L10.3 15H5a2 2 0 0 1-2-2.3l1.2-7A2 2 0 0 1 6.2 4H14a3 3 0 0 1 3 3"/>',
        'bell' => '<path d="M6 16V10a6 6 0 1 1 12 0v6l1.5 2.5h-15z"/><path d="M9.5 19a2.5 2.5 0 0 0 5 0"/>',
        'telegram' => '<path d="M21 4.5 2.7 11.6c-.9.36-.9 1.55.05 1.85l4.5 1.45 1.7 5.5c.3.95 1.55 1.15 2.1.3l2.4-3.6 4.5 3.3c.85.6 2.05.15 2.25-.85l3-15.4c.2-1.05-.85-1.85-1.7-1.6z"/><path d="M7.25 14.9 17 7.5"/>',
    ];
    $body = $paths[$name] ?? $paths['check'];
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
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
    $sql = DB_DRIVER === 'sqlite'
        ? "INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = ?"
        : "INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = ?";
    $st = db()->prepare($sql);
    $st->execute([$k, $v, $v]);
}

// تُضبط من لوحة تحكم الموقع وتُخزَّن في قاعدة البيانات حتى يقرأهما بوت تيليجرام
// المستقل (telegram_bot.php) من سيرفر VPS آخر يشارك نفس القاعدة، مع الرجوع
// لقيم config.php كخيار احتياطي فقط.
function bot_token(): string { return setting('bot_token') ?: (defined('BOT_TOKEN') ? BOT_TOKEN : ''); }
function owner_id(): string { return setting('owner_id') ?: (defined('OWNER_ID') ? (string)OWNER_ID : ''); }

/** يرسل إشعار تيليجرام مباشر لمستخدم مرتبط بحسابه عبر تيليجرام (مثل تحديث حالة طلب). */
function tg_notify_user(string $chatId, string $text): void
{
    if (!$chatId) return;
    $token = bot_token();
    if (!$token) return;
    http_post_json("https://api.telegram.org/bot$token/sendMessage", [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ]);
}

function log_activity(int $adminId, string $adminName, string $field, string $filename, string $url): void
{
    db()->prepare("INSERT INTO activity_log (admin_id, admin_name, field, filename, url) VALUES (?,?,?,?,?)")
        ->execute([$adminId, $adminName, $field, $filename, $url]);
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
    if ($u && empty($u['referral_code'])) {
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        db()->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$code, $u['id']]);
        $u['referral_code'] = $code;
    }
    return $u;
}
function is_admin(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'admin';
}
function require_admin(): void
{
    if (!is_admin()) { http_response_code(403); die('ممنوع — هذه الصفحة للإدارة فقط.'); }
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

/* ---- نظام الحماية من تخمين كلمات المرور (Brute-force protection) ---- */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function is_ip_blocked(string $ip): bool
{
    $st = db()->prepare("SELECT id FROM blocked_ips WHERE ip = ?");
    $st->execute([$ip]);
    return (bool)$st->fetch();
}

function login_attempts_limit(): int { return (int)setting('login_max_attempts', 5); }
function login_lockout_minutes(): int { return (int)setting('login_lockout_minutes', 15); }

// يتحقق إن كانت هوية/IP معينة تجاوزت عدد المحاولات الفاشلة المسموح بها خلال فترة القفل
function is_login_locked(string $identity, string $ip): bool
{
    if (is_ip_blocked($ip)) return true;
    $limit = login_attempts_limit();
    if ($limit <= 0) return false;
    $since = date('Y-m-d H:i:s', time() - login_lockout_minutes() * 60);
    $st = db()->prepare("SELECT COUNT(*) c FROM login_attempts WHERE (identity = ? OR ip = ?) AND success = 0 AND created_at >= ?");
    $st->execute([$identity, $ip, $since]);
    return (int)$st->fetch()['c'] >= $limit;
}

function record_login_attempt(string $identity, string $ip, bool $success): void
{
    db()->prepare("INSERT INTO login_attempts (identity, ip, success) VALUES (?,?,?)")
        ->execute([$identity, $ip, $success ? 1 : 0]);
    if ($success) {
        db()->prepare("DELETE FROM login_attempts WHERE (identity = ? OR ip = ?) AND success = 0")
            ->execute([$identity, $ip]);
    }
}

/* ---- Telegram ----
   البوت أصبح عملية مستقلة تعمل على سيرفر VPS منفصل (telegram_bot.php)
   ويستخدم فقط نفس قاعدة البيانات. هذا الملف لا يتصل بـ Telegram API مباشرة إطلاقاً؛
   عند نشر منتج جديد يُكتب سجل في bot_broadcast_queue ليقرأه البوت ويبثه بنفسه. */

/* ---- Satofill API: مزامنة كتالوج المنتجات مع تطبيق نسبة هامش ربح ---- */
function satofill_fetch_catalog(): array
{
    if (!defined('SATOFILL_API_TOKEN') || !SATOFILL_API_TOKEN) {
        throw new RuntimeException('SATOFILL_API_TOKEN غير مُعرّف في config.php');
    }
    $base = rtrim(setting('satofill_api_base', 'https://satofill.com/api'), '/');
    $ch = curl_init($base . '/products');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . SATOFILL_API_TOKEN,
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 400) {
        throw new RuntimeException("فشل الاتصال بـ Satofill (HTTP $code)");
    }
    $data = json_decode($res, true);
    if (!is_array($data)) throw new RuntimeException('استجابة غير صالحة من Satofill');
    // الواجهة قد تُغلّف القائمة داخل data/products/items حسب توثيق المزود
    foreach (['data', 'products', 'items'] as $k) {
        if (isset($data[$k]) && is_array($data[$k])) { $data = $data[$k]; break; }
    }
    return $data;
}

// ضغط/تصغير الصور المرفوعة لتقليل حجمها وتسريع تحميلها (السبب الرئيسي لتجمّد الموقع أثناء عرض الصور).
// يُعاد ترميز الصورة بجودة مضغوطة وتُصغَّر أبعادها إذا تجاوزت الحد الأقصى، مع الحفاظ على نوع الملف.
/**
 * يكتب ملف sw.js (Service Worker) في جذر الموقع حسب محتوى وحالة التفعيل المضبوطة من لوحة الإدارة،
 * لأن service worker لا يعمل إلا إذا قُدّم فعلياً من مسار جذر الموقع (/sw.js) لا عبر index.php?...
 */
function write_moneytag_sw_file(): void
{
    $path = __DIR__ . '/sw.js';
    if (setting('moneytag_sw_enabled', '0') !== '1') {
        if (is_file($path)) @unlink($path);
        return;
    }
    $content = setting('moneytag_sw_content', '');
    if (trim($content) === '') $content = "self.addEventListener('install', () => self.skipWaiting());\nself.addEventListener('activate', () => self.clients.claim());";
    @file_put_contents($path, $content);
}

function compress_image_file(string $path, int $maxDim = 1280, int $quality = 75): void
{
    $info = @getimagesize($path);
    if (!$info) return;
    [$w, $h, $type] = $info;
    $needsResize = $w > $maxDim || $h > $maxDim;
    if ($type === IMAGETYPE_GIF && !$needsResize) return; // لا نعيد ترميز gif إلا عند الحاجة لتصغيره (حفاظاً على الحركة قدر الإمكان)
    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
        IMAGETYPE_GIF => @imagecreatefromgif($path),
        default => null,
    };
    if (!$src) return;
    if ($needsResize) {
        $ratio = min($maxDim / $w, $maxDim / $h);
        $nw = max(1, (int)round($w * $ratio));
        $nh = max(1, (int)round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }
    switch ($type) {
        case IMAGETYPE_JPEG: imagejpeg($src, $path, $quality); break;
        case IMAGETYPE_PNG: imagepng($src, $path, 6); break;
        case IMAGETYPE_WEBP: if (function_exists('imagewebp')) imagewebp($src, $path, $quality); break;
        case IMAGETYPE_GIF: imagegif($src, $path); break;
    }
    imagedestroy($src);
}

// تنزيل صورة المنتج من سيرفر المزوّد محلياً مرة واحدة عند المزامنة، حتى لا يضطر متصفح المستخدم
// لتحميلها من سيرفر بطيء/بعيد كل مرة (هذا هو السبب الرئيسي لبطء ظهور صور المنتجات).
function cache_remote_image(string $url): string
{
    if ($url === '' || !preg_match('#^https?://#i', $url)) return $url;
    $destDir = __DIR__ . '/uploads/cache';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $filename = hash('sha256', $url) . '.img';
    $destFile = $destDir . '/' . $filename;
    if (is_file($destFile)) return 'uploads/cache/' . $filename;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
    $bytes = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($bytes === false || $code >= 400 || strlen($bytes) > 5 * 1024 * 1024) return $url;
    $tmp = $destDir . '/tmp_' . $filename;
    file_put_contents($tmp, $bytes);
    $mime = mime_content_type($tmp);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) { unlink($tmp); return $url; }
    rename($tmp, $destFile);
    compress_image_file($destFile);
    return 'uploads/cache/' . $filename;
}

function satofill_sync_products(): int
{
    $items = satofill_fetch_catalog();
    $markup = (float)setting('satofill_markup_percent', 15);
    $pdo = db();
    $count = 0;
    foreach ($items as $item) {
        $extId = (string)($item['id'] ?? $item['product_id'] ?? '');
        if ($extId === '') continue;
        $name = trim((string)($item['name'] ?? $item['title'] ?? ''));
        if ($name === '') continue;
        $cost = (float)($item['price'] ?? $item['cost'] ?? 0);
        $price = round($cost * (1 + $markup / 100), 2);
        $image = cache_remote_image((string)($item['image'] ?? $item['thumbnail'] ?? $item['icon_url'] ?? ''));

        $st = $pdo->prepare("SELECT id FROM products WHERE source='satofill' AND external_id=?");
        $st->execute([$extId]);
        $existing = $st->fetch();
        if ($existing) {
            $pdo->prepare("UPDATE products SET name=?, price=?, image=? WHERE id=?")
                ->execute([$name, $price, $image, $existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO products (name, price, image, source, external_id, status) VALUES (?,?,?,?,?,'active')")
                ->execute([$name, $price, $image, 'satofill', $extId]);
        }
        $count++;
    }
    return $count;
}

/* ======================================================================
   3) GOOGLE OAUTH + TRADITIONAL AUTH
   ====================================================================== */
function google_redirect_uri(): string
{
    // مهم جداً: يجب أن يكون هذا الرابط ثابتاً ومطابقاً تماماً للمسجَّل في Google Cloud Console.
    // نبنيه من SITE_URL وليس من $_SERVER حتى لا يتغيّر حسب طريقة فتح الصفحة (yassota.com مقابل yassota.com/index.php)،
    // لأن أي اختلاف ولو بسيط يسبب خطأ redirect_uri_mismatch.
    if (defined('SITE_URL') && SITE_URL) {
        return rtrim(SITE_URL, '/') . '/index.php?action=google_callback';
    }
    // احتياطي للتشغيل المحلي فقط
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

function http_post(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'error' => $err, 'code' => $code];
}

function http_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'error' => $err, 'code' => $code];
}

function http_post_json(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'error' => $err, 'code' => $code];
}

function google_handle_callback(string $code): void
{
    if (!$code) { flash('فشل تسجيل الدخول بجوجل: لم يصل رمز التفويض من جوجل.', 'error'); redirect('?'); }

    $tokenHttp = http_post('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => google_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]);
    $tokenRes = json_decode((string)$tokenHttp['body'], true);

    if (empty($tokenRes['access_token'])) {
        $detail = $tokenHttp['error'] ?: ($tokenRes['error_description'] ?? $tokenRes['error'] ?? 'استجابة غير متوقعة من جوجل (HTTP ' . $tokenHttp['code'] . ')');
        error_log('Google OAuth token exchange failed: ' . $detail . ' | redirect_uri=' . google_redirect_uri());
        flash('فشل تسجيل الدخول بجوجل: ' . $detail, 'error');
        redirect('?');
    }

    $infoHttp = http_get('https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . urlencode($tokenRes['access_token']));
    $info = json_decode((string)$infoHttp['body'], true);
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
    }
    $_SESSION['uid'] = $uid;
    redirect($isNew ? '?' : ('?' . ($role === 'admin' ? 'page=admin' : '')));
}

function telegram_handle_login(): void
{
    if (!bot_token()) { flash('تسجيل الدخول بتيليجرام غير مفعّل حالياً.', 'error'); redirect('?page=login'); }
    $data = $_POST;
    $hash = $data['hash'] ?? '';
    unset($data['hash']);
    if (empty($data['id']) || !$hash) { flash('بيانات تيليجرام غير مكتملة.', 'error'); redirect('?page=login'); }

    $checkArr = [];
    foreach ($data as $k => $v) $checkArr[] = $k . '=' . $v;
    sort($checkArr);
    $checkString = implode("\n", $checkArr);
    $secretKey = hash('sha256', bot_token(), true);
    $computedHash = hash_hmac('sha256', $checkString, $secretKey);

    if (!hash_equals($computedHash, $hash)) { flash('تعذّر التحقق من حساب تيليجرام.', 'error'); redirect('?page=login'); }
    if (time() - (int)($data['auth_date'] ?? 0) > 86400) { flash('انتهت صلاحية جلسة تيليجرام، حاول مجدداً.', 'error'); redirect('?page=login'); }

    $tgId = (string)$data['id'];
    $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?: ('tg_' . $tgId);
    $avatar = $data['photo_url'] ?? '';

    $st = db()->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $st->execute([$tgId]);
    $u = $st->fetch();
    $isNew = !$u;

    if ($u) {
        db()->prepare("UPDATE users SET name=?, avatar=?, last_login=" . (DB_DRIVER === 'sqlite' ? "datetime('now')" : 'NOW()') . " WHERE id=?")
            ->execute([$name, $avatar, $u['id']]);
        $uid = $u['id'];
        $role = $u['role'];
    } else {
        $email = 'tg_' . $tgId . '@telegram.local';
        $username = gen_username($email);
        db()->prepare("INSERT INTO users (telegram_id, email, name, avatar, role, username) VALUES (?,?,?,?,?,?)")
            ->execute([$tgId, $email, $name, $avatar, 'user', $username]);
        $uid = db()->lastInsertId();
        $role = 'user';
    }
    $_SESSION['uid'] = $uid;
    redirect($isNew ? '?' : ('?' . ($role === 'admin' ? 'page=admin' : '')));
}

// تجزئة بسيطة لعنوان IP + متصفح المستخدم لمنع تكرار الإحالة من نفس الجهاز.
// ليست حماية مطلقة (يمكن تجاوزها بـ VPN/متصفح مختلف) لكنها تردع إعادة التسجيل السريعة.
function device_fingerprint(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

// كابتشا عالمية حقيقية عبر Cloudflare Turnstile (مفتاح/سر يُضبطان من لوحة الإدارة).
// إن لم يُضبط أي مفتاح يُعتبر التحقق غير مفعّل ولا يمنع تسجيل الدخول/الحساب.
function turnstile_verify(): bool
{
    $secret = setting('turnstile_secret_key');
    if (!$secret) return true;
    $token = $_POST['cf-turnstile-response'] ?? '';
    if (!$token) return false;
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res === false) return false;
    $data = json_decode($res, true);
    return !empty($data['success']);
}

function turnstile_widget(): string
{
    $siteKey = setting('turnstile_site_key');
    if (!$siteKey) return '';
    return '<div class="cf-turnstile" data-sitekey="' . e($siteKey) . '" data-theme="dark"></div>';
}

function handle_register(): void
{
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (is_ip_blocked(client_ip())) { flash('عذراً، لا يمكن التسجيل من هذا العنوان حالياً.', 'error'); redirect('?'); }
    if (!turnstile_verify()) { flash('فشل التحقق الأمني (الكابتشا)، يرجى المحاولة مجدداً.', 'error'); redirect('?'); }
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
    $refCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $fingerprint = device_fingerprint();
    $referredBy = null;
    if (!empty($_SESSION['ref_code'])) {
        $st = db()->prepare("SELECT id FROM users WHERE referral_code = ?");
        $st->execute([$_SESSION['ref_code']]);
        $ref = $st->fetch();
        if ($ref) $referredBy = (int)$ref['id'];
    }
    db()->prepare("INSERT INTO users (username, email, password_hash, name, role, referral_code, referred_by, signup_fingerprint) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $username, $role, $refCode, $referredBy, $fingerprint]);
    $uid = db()->lastInsertId();
    unset($_SESSION['ref_code']);
    $_SESSION['uid'] = $uid;
    redirect('?');
}

function handle_login(): void
{
    csrf_check();
    $identity = trim($_POST['identity'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $ip = client_ip();

    if (is_login_locked($identity, $ip)) {
        flash('تم حظر تسجيل الدخول مؤقتاً بسبب محاولات فاشلة كثيرة، حاول بعد ' . login_lockout_minutes() . ' دقيقة.', 'error');
        redirect('?');
    }
    if (!turnstile_verify()) { flash('فشل التحقق الأمني (الكابتشا)، يرجى المحاولة مجدداً.', 'error'); redirect('?'); }
    if (!$identity || !$password) { flash('يرجى تعبئة جميع الحقول.', 'error'); redirect('?'); }

    $st = db()->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
    $st->execute([$identity, $identity]);
    $u = $st->fetch();

    if (!$u || !$u['password_hash'] || !password_verify($password, $u['password_hash'])) {
        record_login_attempt($identity, $ip, false);
        flash('بيانات الدخول غير صحيحة.', 'error'); redirect('?');
    }
    if ($u['is_banned']) { flash('هذا الحساب محظور.', 'error'); redirect('?'); }

    record_login_attempt($identity, $ip, true);
    $role = ($u['email'] === ADMIN_EMAIL) ? 'admin' : $u['role'];
    db()->prepare("UPDATE users SET role=?, last_login=" . (DB_DRIVER === 'sqlite' ? "datetime('now')" : 'NOW()') . " WHERE id=?")
        ->execute([$role, $u['id']]);
    $_SESSION['uid'] = $u['id'];
    redirect('?' . ($role === 'admin' ? 'page=admin' : ''));
}

/* ======================================================================
   4) ROUTING
   ====================================================================== */
if (is_ip_blocked(client_ip())) { http_response_code(403); die('عذراً، تم حظر هذا العنوان من الوصول للموقع.'); }

$action = $_GET['action'] ?? '';
$page = $_GET['page'] ?? 'home';
$appsKindNav = $_GET['kind'] ?? '';

if (!empty($_GET['ref']) && preg_match('/^[A-Z0-9]{4,20}$/', $_GET['ref'])) {
    $_SESSION['ref_code'] = $_GET['ref'];
}

// ملاحظة: بوت تيليجرام (القوائم/الأرباح/المحفظة) يعمل كعملية مستقلة بالكامل عبر telegram_bot.php
// على سيرفر منفصل، ويتواصل مع هذا الموقع فقط من خلال قاعدة البيانات المشتركة (لا استدعاء API مباشر هنا).

if ($action === 'google_callback') {
    google_handle_callback($_GET['code'] ?? '');
    exit;
}

if ($action === 'telegram_login') { telegram_handle_login(); exit; }

if ($action === 'register') { handle_register(); exit; }
if ($action === 'login') { handle_login(); exit; }

if ($action === 'logout') { logout(); redirect('?'); }

if ($page === 'admin' && !is_admin()) { http_response_code(403); die('ممنوع — هذه الصفحة للإدارة فقط. سجّل الدخول ببريد الأدمن.'); }

if ($action === 'accept_policy') {
    setcookie('policy_accepted', setting('policy_version', '1'), time() + 60 * 60 * 24 * 365, '/');
    echo 'ok'; exit;
}

if ($action === 'robots') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nAllow: /\nSitemap: " . rtrim(SITE_URL, '/') . "/index.php?action=sitemap\n";
    exit;
}

if ($action === 'ads_txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo setting('ads_txt_content', '');
    exit;
}

if ($action === 'sitemap') {
    header('Content-Type: application/xml; charset=utf-8');
    $base = rtrim(SITE_URL, '/') . '/index.php';
    $urls = [
        ['loc' => $base, 'priority' => '1.0'],
        ['loc' => $base . '?page=apps', 'priority' => '0.9'],
        ['loc' => $base . '?page=store', 'priority' => '0.7'],
        ['loc' => $base . '?page=privacy', 'priority' => '0.3'],
        ['loc' => $base . '?page=terms', 'priority' => '0.3'],
        ['loc' => $base . '?page=about', 'priority' => '0.5'],
        ['loc' => $base . '?page=faq', 'priority' => '0.5'],
        ['loc' => $base . '?page=guide', 'priority' => '0.8'],
        ['loc' => $base . '?page=top', 'priority' => '0.8'],
    ];
    $apps = db()->query("SELECT id, created_at FROM apps WHERE status='published'")->fetchAll();
    foreach ($apps as $a) {
        $urls[] = ['loc' => $base . '?page=app&id=' . (int)$a['id'], 'priority' => '0.9', 'lastmod' => substr((string)$a['created_at'], 0, 10)];
    }
    $products = db()->query("SELECT id, created_at FROM products WHERE status='active'")->fetchAll();
    foreach ($products as $p) {
        $urls[] = ['loc' => $base . '?page=product&id=' . (int)$p['id'], 'priority' => '0.7', 'lastmod' => substr((string)$p['created_at'], 0, 10)];
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        echo '<url><loc>' . e($u['loc']) . '</loc>';
        if (!empty($u['lastmod'])) echo '<lastmod>' . e($u['lastmod']) . '</lastmod>';
        echo '<priority>' . $u['priority'] . '</priority></url>' . "\n";
    }
    echo '</urlset>';
    exit;
}

if ($action === 'app_vote') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    $appId = (int)($_POST['id'] ?? 0);
    $dir = $_POST['dir'] ?? '';
    if (!$appId || !in_array($dir, ['up', 'down'], true)) { echo json_encode(['ok' => false, 'msg' => 'طلب غير صالح.']); exit; }
    $voted = $_SESSION['voted_apps'] ?? [];
    if (isset($voted[$appId])) { echo json_encode(['ok' => false, 'msg' => 'لقد قيّمت هذا التطبيق مسبقاً.']); exit; }
    $col = $dir === 'up' ? 'likes_count' : 'dislikes_count';
    db()->prepare("UPDATE apps SET $col = $col + 1 WHERE id=?")->execute([$appId]);
    $voted[$appId] = $dir;
    $_SESSION['voted_apps'] = $voted;
    $row = db()->prepare("SELECT likes_count, dislikes_count FROM apps WHERE id=?");
    $row->execute([$appId]);
    $r = $row->fetch();
    echo json_encode(['ok' => true, 'likes' => (int)$r['likes_count'], 'dislikes' => (int)$r['dislikes_count']]);
    exit;
}

/* ---- JSON API actions (AJAX) ---- */
if ($action && str_starts_with($action, 'api_')) {
    header('Content-Type: application/json; charset=utf-8');
    $u = current_user();

    if (!$u && !in_array($action, ['api_ping', 'api_report_app'])) {
        echo json_encode(['ok' => false, 'msg' => 'يجب تسجيل الدخول أولاً.']); exit;
    }

    switch ($action) {
        case 'api_report_app':
            $appId = (int)($_POST['app_id'] ?? 0);
            if ($appId <= 0) { echo json_encode(['ok' => false]); exit; }
            $st = db()->prepare("SELECT id FROM apps WHERE id=?"); $st->execute([$appId]);
            if (!$st->fetch()) { echo json_encode(['ok' => false]); exit; }
            db()->prepare("INSERT INTO app_reports (app_id, user_id, message) VALUES (?,?,?)")
                ->execute([$appId, $u ? $u['id'] : null, 'رابط التحميل لا يعمل']);
            echo json_encode(['ok' => true]); exit;

        case 'api_update_profile':
            csrf_check();
            $newName = trim($_POST['name'] ?? '');
            $newUsername = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['username'] ?? ''));
            $newBio = mb_substr(trim($_POST['bio'] ?? ''), 0, 255);
            if ($newName === '' || $newUsername === '') { echo json_encode(['ok' => false, 'msg' => 'الاسم واسم المستخدم مطلوبان.']); exit; }
            $st = db()->prepare("SELECT id FROM users WHERE username=? AND id<>?");
            $st->execute([$newUsername, $u['id']]);
            if ($st->fetch()) { echo json_encode(['ok' => false, 'msg' => 'اسم المستخدم هذا مستخدم من قبل.']); exit; }
            db()->prepare("UPDATE users SET name=?, username=?, bio=? WHERE id=?")->execute([$newName, $newUsername, $newBio, $u['id']]);
            echo json_encode(['ok' => true, 'msg' => 'تم حفظ التعديلات.']); exit;

        case 'api_submit_suggestion':
            csrf_check();
            $title = trim($_POST['title'] ?? '');
            $details = mb_substr(trim($_POST['details'] ?? ''), 0, 500);
            if ($title === '') { echo json_encode(['ok' => false, 'msg' => 'أدخل اسم المنتج المقترح.']); exit; }
            db()->prepare("INSERT INTO product_suggestions (user_id, title, details) VALUES (?,?,?)")->execute([$u['id'], $title, $details]);
            echo json_encode(['ok' => true, 'msg' => 'تم إرسال اقتراحك، شكراً لك!']); exit;

        case 'api_toggle_wishlist':
            csrf_check();
            $pid = (int)($_POST['product_id'] ?? 0);
            $st = db()->prepare("SELECT id FROM wishlist WHERE user_id=? AND product_id=?");
            $st->execute([$u['id'], $pid]);
            $row = $st->fetch();
            if ($row) {
                db()->prepare("DELETE FROM wishlist WHERE id=?")->execute([$row['id']]);
                echo json_encode(['ok' => true, 'added' => false]);
            } else {
                db()->prepare("INSERT INTO wishlist (user_id, product_id) VALUES (?,?)")->execute([$u['id'], $pid]);
                echo json_encode(['ok' => true, 'added' => true]);
            }
            exit;

        case 'api_submit_review':
            csrf_check();
            $pid = (int)($_POST['product_id'] ?? 0);
            $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
            $comment = trim($_POST['comment'] ?? '');
            $st = db()->prepare("SELECT 1 FROM orders WHERE user_id=? AND product_id=? AND status='approved'");
            $st->execute([$u['id'], $pid]);
            if (!$st->fetch()) { echo json_encode(['ok' => false, 'msg' => 'يمكنك تقييم المنتجات التي اشتريتها فقط.']); exit; }
            $st = db()->prepare("SELECT id FROM reviews WHERE user_id=? AND product_id=?");
            $st->execute([$u['id'], $pid]);
            if ($st->fetch()) { echo json_encode(['ok' => false, 'msg' => 'لقد قيّمت هذا المنتج مسبقاً.']); exit; }
            db()->prepare("INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?,?,?,?)")->execute([$pid, $u['id'], $rating, $comment]);
            echo json_encode(['ok' => true, 'msg' => 'شكراً لتقييمك!']); exit;

        case 'api_validate_coupon':
            $code = trim($_POST['code'] ?? '');
            $pid = (int)($_POST['product_id'] ?? 0);
            $st = db()->prepare("SELECT * FROM products WHERE id=? AND status='active'");
            $st->execute([$pid]);
            $p = $st->fetch();
            if (!$p) { echo json_encode(['ok' => false, 'msg' => 'المنتج غير متوفر.']); exit; }
            $st = db()->prepare("SELECT * FROM coupons WHERE code=? AND active=1");
            $st->execute([$code]);
            $c = $st->fetch();
            if (!$c) { echo json_encode(['ok' => false, 'msg' => 'كود الخصم غير صالح.']); exit; }
            if ($c['max_uses'] > 0 && $c['used_count'] >= $c['max_uses']) { echo json_encode(['ok' => false, 'msg' => 'تم استهلاك هذا الكود بالكامل.']); exit; }
            if ($c['expires_at'] && $c['expires_at'] < date('Y-m-d')) { echo json_encode(['ok' => false, 'msg' => 'انتهت صلاحية هذا الكود.']); exit; }
            $newPrice = round($p['price'] * (1 - $c['discount_percent'] / 100), 2);
            echo json_encode(['ok' => true, 'new_price' => $newPrice, 'discount_percent' => (float)$c['discount_percent']]); exit;

        case 'api_buy_product':
            csrf_check();
            $pid = (int)($_POST['product_id'] ?? 0);
            $accountId = trim($_POST['account_id'] ?? '');
            $receipt = trim($_POST['receipt_image'] ?? '');
            $txNote = trim($_POST['tx_note'] ?? '');
            $couponCode = trim($_POST['coupon_code'] ?? '');
            $st = db()->prepare("SELECT * FROM products WHERE id=? AND status='active'");
            $st->execute([$pid]);
            $p = $st->fetch();
            if (!$p) { echo json_encode(['ok' => false, 'msg' => 'المنتج غير متوفر.']); exit; }
            if ($accountId === '') { echo json_encode(['ok' => false, 'msg' => 'يجب إدخال الآيدي الخاص بك.']); exit; }
            if ($receipt === '') { echo json_encode(['ok' => false, 'msg' => 'صورة الإيصال إجبارية لإتمام الطلب.']); exit; }

            $finalPrice = (float)$p['price'];
            $coupon = null;
            if ($couponCode !== '') {
                $st = db()->prepare("SELECT * FROM coupons WHERE code=? AND active=1");
                $st->execute([$couponCode]);
                $coupon = $st->fetch();
                if (!$coupon) { echo json_encode(['ok' => false, 'msg' => 'كود الخصم غير صالح.']); exit; }
                if ($coupon['max_uses'] > 0 && $coupon['used_count'] >= $coupon['max_uses']) { echo json_encode(['ok' => false, 'msg' => 'تم استهلاك هذا الكود بالكامل.']); exit; }
                if ($coupon['expires_at'] && $coupon['expires_at'] < date('Y-m-d')) { echo json_encode(['ok' => false, 'msg' => 'انتهت صلاحية هذا الكود.']); exit; }
                $finalPrice = round($finalPrice * (1 - $coupon['discount_percent'] / 100), 2);
            }

            db()->prepare("INSERT INTO orders (user_id, product_id, price, account_id, receipt_image, tx_note, coupon_code) VALUES (?,?,?,?,?,?,?)")
                ->execute([$u['id'], $pid, $finalPrice, $accountId, $receipt, $txNote, $couponCode ?: null]);
            if ($coupon) db()->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?")->execute([$coupon['id']]);
            echo json_encode(['ok' => true, 'msg' => 'تم إرسال طلب الشراء، بانتظار موافقة الإدارة.']);
            exit;

        case 'api_upload_receipt':
            csrf_check();
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['ok' => false, 'msg' => 'فشل رفع صورة الإيصال.']); exit;
            }
            $f = $_FILES['file'];
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $mime = mime_content_type($f['tmp_name']);
            if (!isset($allowed[$mime]) || $f['size'] > 5 * 1024 * 1024) {
                echo json_encode(['ok' => false, 'msg' => 'الملف يجب أن يكون صورة (jpg/png/webp/gif) أصغر من 5MB.']); exit;
            }
            $destDir = __DIR__ . '/uploads/receipts';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $filename = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
            move_uploaded_file($f['tmp_name'], $destDir . '/' . $filename);
            compress_image_file($destDir . '/' . $filename);
            echo json_encode(['ok' => true, 'url' => 'uploads/receipts/' . $filename]);
            exit;

        case 'api_upload_avatar':
            csrf_check();
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['ok' => false, 'msg' => 'فشل رفع الصورة.']); exit;
            }
            $f = $_FILES['file'];
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $mime = mime_content_type($f['tmp_name']);
            if (!isset($allowed[$mime]) || $f['size'] > 5 * 1024 * 1024) {
                echo json_encode(['ok' => false, 'msg' => 'الملف يجب أن يكون صورة (jpg/png/webp/gif) أصغر من 5MB.']); exit;
            }
            $destDir = __DIR__ . '/uploads/avatars';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $filename = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
            move_uploaded_file($f['tmp_name'], $destDir . '/' . $filename);
            compress_image_file($destDir . '/' . $filename, 600, 80);
            $url = 'uploads/avatars/' . $filename;
            db()->prepare("UPDATE users SET avatar=? WHERE id=?")->execute([$url, $u['id']]);
            echo json_encode(['ok' => true, 'url' => $url, 'msg' => 'تم تحديث صورة الملف الشخصي.']);
            exit;

        default:
            echo json_encode(['ok' => false, 'msg' => 'غير معروف.']);
            exit;
    }
}

/* ---- Admin file upload (logo/banner/product images) ---- */
if ($action === 'admin_upload') {
    require_admin();
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $field = preg_replace('/[^a-z_]/', '', $_POST['field'] ?? 'misc');
    $dir = match (true) {
        str_contains($field, 'logo') => 'site',
        str_contains($field, 'banner') => 'banners',
        default => 'products',
    };
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => 'فشل رفع الملف.']); exit;
    }
    $f = $_FILES['file'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($f['tmp_name']);
    if (!isset($allowed[$mime]) || $f['size'] > 5 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'msg' => 'الملف يجب أن يكون صورة (jpg/png/webp/gif) أصغر من 5MB.']); exit;
    }
    $destDir = __DIR__ . '/uploads/' . $dir;
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $filename = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    move_uploaded_file($f['tmp_name'], $destDir . '/' . $filename);
    compress_image_file($destDir . '/' . $filename, $dir === 'site' ? 400 : 1600, 78);
    $url = 'uploads/' . $dir . '/' . $filename;
    $admin = current_user();
    log_activity((int)$admin['id'], $admin['name'] ?: $admin['username'], $field, $f['name'], $url);
    echo json_encode(['ok' => true, 'url' => $url]);
    exit;
}

/* ---- OpenRouter AI helpers (admin) ---- */
function openrouter_active_keys(): array
{
    return db()->query("SELECT * FROM openrouter_keys WHERE active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
}

function openrouter_mark_error(int $keyId, string $msg): void
{
    db()->prepare("UPDATE openrouter_keys SET last_error=? WHERE id=?")->execute([mb_substr($msg, 0, 255), $keyId]);
}

/**
 * يجري الطلب عبر كل المفاتيح النشطة بالتتابع (round-robin بحسب sort_order) حتى ينجح أحدها،
 * لتفادي توقف الخدمة بالكامل إذا انتهى حد استخدام مفتاح واحد أو تعطّل.
 */
function openrouter_request(array $payload): array
{
    $keys = openrouter_active_keys();
    if (!$keys) {
        // توافق مع الإصدار القديم: مفتاح واحد في الإعدادات
        $legacy = setting('openrouter_api_key');
        if (!$legacy) return ['ok' => false, 'msg' => 'لم يتم ضبط أي مفتاح OpenRouter من الإعدادات.'];
        $keys = [['id' => 0, 'api_key' => $legacy]];
    }
    $lastErr = 'تعذر الاتصال بـ OpenRouter.';
    foreach ($keys as $k) {
        $res = http_post_json('https://openrouter.ai/api/v1/chat/completions', $payload, [
            'Authorization: Bearer ' . $k['api_key'],
            'HTTP-Referer: ' . (defined('SITE_URL') ? SITE_URL : 'https://yassota.com'),
        ]);
        $data = json_decode((string)$res['body'], true);
        $hasContent = !empty($data['choices'][0]['message']['content']) || !empty($data['choices'][0]['message']['images']);
        if ($hasContent) return ['ok' => true, 'data' => $data];
        $lastErr = $res['error'] ?: ($data['error']['message'] ?? 'استجابة غير متوقعة من OpenRouter (HTTP ' . $res['code'] . ')');
        if (!empty($k['id'])) openrouter_mark_error((int)$k['id'], $lastErr);
        // أخطاء المصادقة/الحصة تستدعي تجربة المفتاح التالي، غير ذلك (مثل خطأ بالموديل) لا فائدة من التكرار
        $code = (int)($res['code'] ?? 0);
        if (!in_array($code, [401, 402, 403, 429, 0], true)) break;
    }
    return ['ok' => false, 'msg' => $lastErr];
}

/**
 * قائمة احتياطية ثابتة تُستخدم فقط إذا تعذّر جلب قائمة الموديلات المجانية الفعلية من OpenRouter
 * (مثلاً بسبب انقطاع الشبكة). أسماء موديلات OpenRouter المجانية تتغيّر بمرور الوقت (تقاعد/إعادة تسمية)،
 * لذلك الاعتماد الأساسي يكون على openrouter_free_models() التي تجلب القائمة الحقيقية الحالية مباشرة.
 */
const OPENROUTER_FREE_FALLBACK_MODELS = [
    'meta-llama/llama-3.3-70b-instruct:free',
    'qwen/qwen-2.5-72b-instruct:free',
    'mistralai/mistral-7b-instruct:free',
    'deepseek/deepseek-r1:free',
];

/**
 * يجلب قائمة الموديلات المجانية الحقيقية والمتاحة حالياً من واجهة OpenRouter العامة (بدون مفتاح)،
 * ويخزّنها مؤقتاً في الإعدادات لمدة 6 ساعات لتجنّب إرسال طلب لكل محادثة. هذا يحل مشكلة فشل موديلات
 * تم تقاعدها أو تغيير اسمها من طرف OpenRouter (مثل ظهور خطأ "No endpoints found" لموديل قديم).
 */
function openrouter_free_models(): array
{
    $cachedAt = (int)setting('openrouter_free_models_at', 0);
    $cached = setting('openrouter_free_models_list', '');
    if ($cached !== '' && (time() - $cachedAt) < 6 * 3600) {
        return array_values(array_filter(array_map('trim', explode(',', $cached))));
    }
    $list = [];
    $res = http_get('https://openrouter.ai/api/v1/models');
    $data = json_decode((string)$res['body'], true);
    if (is_array($data['data'] ?? null)) {
        foreach ($data['data'] as $m) {
            $id = $m['id'] ?? '';
            $pricing = $m['pricing'] ?? [];
            $isFree = str_ends_with($id, ':free') || ((float)($pricing['prompt'] ?? 1) === 0.0 && (float)($pricing['completion'] ?? 1) === 0.0);
            if ($id && $isFree) $list[] = $id;
        }
    }
    if ($list) {
        set_setting('openrouter_free_models_list', implode(',', $list));
        set_setting('openrouter_free_models_at', (string)time());
        return $list;
    }
    return OPENROUTER_FREE_FALLBACK_MODELS;
}

function openrouter_chat(string $prompt, bool $jsonMode = false): array
{
    $primary = setting('openrouter_model', 'meta-llama/llama-3.3-70b-instruct:free');
    $models = array_values(array_unique(array_merge([$primary], openrouter_free_models(), OPENROUTER_FREE_FALLBACK_MODELS)));
    $lastErr = 'تعذر الاتصال بـ OpenRouter.';
    $triedNoEndpoint = [];
    foreach ($models as $model) {
        $payload = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        if ($jsonMode) $payload['response_format'] = ['type' => 'json_object'];
        $r = openrouter_request($payload);
        if ($r['ok']) {
            $data = $r['data'];
            if (!empty($data['choices'][0]['message']['content'])) {
                return ['ok' => true, 'text' => $data['choices'][0]['message']['content']];
            }
            $lastErr = $data['error']['message'] ?? 'استجابة غير متوقعة من OpenRouter.';
            continue;
        }
        if (stripos($r['msg'], 'no endpoints') !== false || stripos($r['msg'], 'not found') !== false) {
            $triedNoEndpoint[] = $model;
            continue; // موديل متقاعد/غير موجود، جرّب التالي فوراً بدون اعتباره الخطأ النهائي
        }
        $lastErr = $r['msg'];
    }
    if ($triedNoEndpoint && $lastErr === 'تعذر الاتصال بـ OpenRouter.') {
        $lastErr = 'كل الموديلات المجانية المتاحة حالياً غير صالحة أو منتهية لدى OpenRouter حالياً (تمت تجربة: ' . implode(', ', $triedNoEndpoint) . '). يرجى مراجعة مفتاح OpenRouter API أو تجربة موديل آخر من القائمة.';
    }
    return ['ok' => false, 'msg' => $lastErr];
}

function openrouter_image(string $prompt, string $destSubdir = 'products'): array
{
    $model = setting('openrouter_image_model');
    if (!$model) return ['ok' => false, 'msg' => 'لم يتم ضبط موديل توليد الصور من الإعدادات.'];
    $r = openrouter_request([
        'model' => $model,
        'modalities' => ['image', 'text'],
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ]);
    if (!$r['ok']) return $r;
    $data = $r['data'];
    $dataUri = $data['choices'][0]['message']['images'][0]['image_url']['url'] ?? null;
    if (!$dataUri || !preg_match('#^data:image/(\w+);base64,(.+)$#', $dataUri, $m)) {
        return ['ok' => false, 'msg' => $data['error']['message'] ?? 'لم يُرجع الموديل صورة (تأكد أن الموديل يدعم توليد الصور).'];
    }
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $bytes = base64_decode($m[2]);
    if (!$bytes) return ['ok' => false, 'msg' => 'فشل فك تشفير الصورة الناتجة.'];
    $destDir = __DIR__ . '/uploads/' . $destSubdir;
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    file_put_contents($destDir . '/' . $filename, $bytes);
    compress_image_file($destDir . '/' . $filename, 1280, 78);
    return ['ok' => true, 'url' => 'uploads/' . $destSubdir . '/' . $filename];
}

if ($action === 'admin_test_openrouter') {
    require_admin();
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $r = openrouter_chat('قل "تم الاتصال بنجاح" فقط بدون أي إضافات.');
    if (!$r['ok']) { echo json_encode(['ok' => false, 'msg' => $r['msg']]); exit; }
    echo json_encode(['ok' => true, 'msg' => 'الاتصال يعمل بنجاح: ' . trim($r['text'])]);
    exit;
}

if ($action === 'admin_clear_cache') {
    require_admin();
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $cleared = [];
    if (function_exists('opcache_reset') && opcache_reset()) $cleared[] = 'OPcache (شيفرة PHP المخزّنة)';
    clearstatcache();
    $cacheDir = __DIR__ . '/uploads/cache';
    $removed = 0;
    if (is_dir($cacheDir)) {
        foreach (glob($cacheDir . '/*') as $f) { if (is_file($f) && @unlink($f)) $removed++; }
    }
    if ($removed) $cleared[] = "$removed صورة مؤقتة من ذاكرة التخزين المؤقت";
    $newVersion = (string)(((int)setting('asset_version', '1')) + 1);
    db()->prepare(DB_DRIVER === 'sqlite'
        ? "INSERT INTO settings (k, v) VALUES ('asset_version', ?) ON CONFLICT(k) DO UPDATE SET v=?"
        : "INSERT INTO settings (k, v) VALUES ('asset_version', ?) ON DUPLICATE KEY UPDATE v=?")
        ->execute([$newVersion, $newVersion]);
    $cleared[] = 'رقم إصدار الموقع (لإجبار المتصفحات على تحميل نسخة جديدة)';
    echo json_encode(['ok' => true, 'msg' => 'تم تفريغ الكاش بنجاح: ' . implode('، ', $cleared) . '.']);
    exit;
}

if ($action === 'admin_ai_generate') {
    require_admin();
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $name = trim($_POST['name'] ?? '');
    $price = trim($_POST['price'] ?? '');
    if (!$name) { echo json_encode(['ok' => false, 'msg' => 'أدخل اسم المنتج أولاً.']); exit; }
    $prompt = "اكتب لمنتج إلكتروني اسمه \"$name\" بسعر $price دولار، وصفاً تسويقياً جذاباً بالعربية (3-4 جمل)، ووصف SEO قصير (جملة واحدة، أقل من 150 حرفاً). "
        . "أعد الإجابة بصيغة JSON فقط بدون أي نص إضافي بهذا الشكل: {\"description\":\"...\",\"meta_description\":\"...\"}";
    $r = openrouter_chat($prompt);
    if (!$r['ok']) { echo json_encode(['ok' => false, 'msg' => $r['msg']]); exit; }
    $text = trim($r['text']);
    $text = preg_replace('/^```json|```$/m', '', $text);
    $json = json_decode(trim($text), true);
    if (!$json || empty($json['description'])) {
        $out = ['ok' => true, 'description' => trim($r['text']), 'meta_description' => ''];
    } else {
        $out = ['ok' => true, 'description' => $json['description'], 'meta_description' => $json['meta_description'] ?? ''];
    }
    if (($_POST['gen_image'] ?? '') === '1') {
        $imgPrompt = "صورة منتج رقمي إعلانية بجودة عالية لمنتج اسمه \"$name\"، تصميم نظيف وجذاب مناسب لمتجر إلكتروني، بدون نص مكتوب على الصورة.";
        $imgRes = openrouter_image($imgPrompt);
        if ($imgRes['ok']) {
            $out['image'] = $imgRes['url'];
        } else {
            $out['image_error'] = $imgRes['msg'];
        }
    }
    echo json_encode($out);
    exit;
}

if ($action === 'admin_ai_generate_app') {
    require_admin();
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $name = trim($_POST['name'] ?? '');
    $kind = ($_POST['kind'] ?? 'app') === 'game' ? 'لعبة' : 'تطبيق';
    if (!$name) { echo json_encode(['ok' => false, 'msg' => 'أدخل اسم التطبيق أولاً.']); exit; }
    $prompt = "أنت خبير ASO/SEO لمتاجر تطبيقات أندرويد. لـ$kind اسمه \"$name\"، أنشئ محتوى نشر كامل بالعربية مناسب لمتجر تطبيقات عالمي يشبه APKPure، واجعل عنوان SEO جذاباً يحتوي كلمات بحث يستخدمها المستخدمون فعلياً (مثل تحميل، آخر إصدار، مهكرة إن كان مناسباً). "
        . "أعد الإجابة بصيغة JSON فقط بدون أي نص إضافي وبدون Markdown، بالمفاتيح التالية فقط: "
        . '{"short_description":"جملة واحدة جذابة أقل من 80 حرفاً","description":"وصف تفصيلي 4-6 جمل يشرح المزايا","changelog":"سطرين عن آخر تحديث افتراضي مناسب","category":"تصنيف التطبيق المناسب (مثل: ألعاب أكشن، أدوات، تواصل اجتماعي)","permissions":"3-5 صلاحيات شائعة مفصولة بفاصلة","seo_title":"عنوان SEO جذاب أقل من 65 حرفاً يحتوي اسم التطبيق وكلمة تحميل","seo_description":"وصف SEO أقل من 155 حرفاً","seo_keywords":"5-8 كلمات مفتاحية مفصولة بفاصلة"}';
    $r = openrouter_chat($prompt, true);
    if (!$r['ok']) { echo json_encode(['ok' => false, 'msg' => $r['msg']]); exit; }
    $text = trim($r['text']);
    $text = preg_replace('/^```json|```$/m', '', $text);
    $json = json_decode(trim($text), true);
    if (!$json) { echo json_encode(['ok' => false, 'msg' => 'تعذر فهم استجابة الذكاء الاصطناعي، حاول مجدداً.']); exit; }
    echo json_encode(array_merge(['ok' => true], $json));
    exit;
}

if ($action === 'admin_ai_generate_app_icon') {
    require_admin();
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $name = trim($_POST['name'] ?? '');
    $kind = ($_POST['kind'] ?? 'app') === 'game' ? 'لعبة' : 'تطبيق';
    if (!$name) { echo json_encode(['ok' => false, 'msg' => 'أدخل اسم التطبيق أولاً.']); exit; }
    $imgPrompt = "أيقونة تطبيق أندرويد ($kind) اسمه \"$name\"، تصميم مسطح عصري بزوايا دائرية، ألوان جذابة متناسقة، بدون أي نص مكتوب على الأيقونة، خلفية مربعة كاملة، أسلوب متجر تطبيقات محترف.";
    $imgRes = openrouter_image($imgPrompt, 'apps');
    if (!$imgRes['ok']) { echo json_encode(['ok' => false, 'msg' => $imgRes['msg']]); exit; }
    echo json_encode(['ok' => true, 'url' => $imgRes['url']]);
    exit;
}

/* ---- Admin POST actions ---- */
if ($action && str_starts_with($action, 'admin_')) {
    require_admin();
    csrf_check();

    switch ($action) {
        case 'admin_save_product':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $old_price = $_POST['old_price'] !== '' ? (float)$_POST['old_price'] : null;
            $cat = (int)($_POST['category_id'] ?? 0) ?: null;
            $desc = trim($_POST['description'] ?? '');
            $metaDesc = trim($_POST['meta_description'] ?? '');
            $tag = trim($_POST['tag'] ?? '');
            $image = trim($_POST['image'] ?? '');
            $picon = trim($_POST['icon'] ?? '');
            if ($id) {
                db()->prepare("UPDATE products SET name=?, price=?, old_price=?, category_id=?, description=?, meta_description=?, tag=?, image=?, icon=? WHERE id=?")
                    ->execute([$name, $price, $old_price, $cat, $desc, $metaDesc, $tag, $image, $picon, $id]);
            } else {
                db()->prepare("INSERT INTO products (name, price, old_price, category_id, description, meta_description, tag, image, icon) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$name, $price, $old_price, $cat, $desc, $metaDesc, $tag, $image, $picon]);
                $id = db()->lastInsertId();
                db()->prepare("INSERT INTO bot_broadcast_queue (product_id) VALUES (?)")->execute([$id]);
            }
            flash('تم حفظ المنتج بنجاح.');
            redirect('?page=admin&tab=products');

        case 'admin_delete_product':
            db()->prepare("DELETE FROM products WHERE id=?")->execute([(int)$_POST['id']]);
            flash('تم حذف المنتج.');
            redirect('?page=admin&tab=products');

        case 'admin_save_category':
            $name = trim($_POST['name'] ?? '');
            $cid = (int)($_POST['id'] ?? 0);
            $cimage = trim($_POST['image'] ?? '');
            $ccolor = trim($_POST['color'] ?? '');
            if ($name && $cid) {
                db()->prepare("UPDATE categories SET name=?, image=?, color=? WHERE id=?")->execute([$name, $cimage ?: null, $ccolor ?: null, $cid]);
            } elseif ($name) {
                db()->prepare("INSERT INTO categories (name, image, color) VALUES (?,?,?)")->execute([$name, $cimage ?: null, $ccolor ?: null]);
            }
            redirect('?page=admin&tab=products');

        case 'admin_delete_category':
            db()->prepare("DELETE FROM categories WHERE id=?")->execute([(int)$_POST['id']]);
            flash('تم حذف القسم.');
            redirect('?page=admin&tab=products');

        case 'admin_save_app':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if (!$name) { flash('اسم التطبيق مطلوب.', 'error'); redirect('?page=admin&tab=apps'); }
            $fields = [
                'kind' => in_array($_POST['kind'] ?? 'app', ['app', 'game'], true) ? $_POST['kind'] : 'app',
                'package_name' => trim($_POST['package_name'] ?? ''),
                'version' => trim($_POST['version'] ?? ''),
                'size_label' => trim($_POST['size_label'] ?? ''),
                'min_android' => trim($_POST['min_android'] ?? ''),
                'category' => trim($_POST['category'] ?? ''),
                'developer_name' => trim($_POST['developer_name'] ?? ''),
                'developer_website' => trim($_POST['developer_website'] ?? ''),
                'privacy_policy_url' => trim($_POST['privacy_policy_url'] ?? ''),
                'icon' => trim($_POST['icon'] ?? ''),
                'banner_image' => trim($_POST['banner_image'] ?? ''),
                'screenshots' => trim($_POST['screenshots'] ?? ''),
                'video_url' => trim($_POST['video_url'] ?? ''),
                'short_description' => trim($_POST['short_description'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'changelog' => trim($_POST['changelog'] ?? ''),
                'permissions' => trim($_POST['permissions'] ?? ''),
                'download_url' => trim($_POST['download_url'] ?? ''),
                'seo_title' => trim($_POST['seo_title'] ?? ''),
                'seo_description' => trim($_POST['seo_description'] ?? ''),
                'seo_keywords' => trim($_POST['seo_keywords'] ?? ''),
                'status' => in_array($_POST['status'] ?? 'published', ['published', 'pending', 'hidden'], true) ? $_POST['status'] : 'published',
            ];
            $slugBase = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-') ?: 'app';
            $admin = current_user();
            if ($id) {
                $fields['slug'] = $slugBase . '-' . $id;
                $sql = "UPDATE apps SET name=?, " . implode('=?, ', array_keys($fields)) . "=?, slug=? WHERE id=?";
                db()->prepare($sql)->execute([$name, ...array_values($fields), $fields['slug'], $id]);
            } else {
                $cols = array_keys($fields);
                $sql = "INSERT INTO apps (name, publisher_id, " . implode(',', $cols) . ") VALUES (?,?," . implode(',', array_fill(0, count($cols), '?')) . ")";
                db()->prepare($sql)->execute([$name, (int)$admin['id'], ...array_values($fields)]);
                $id = (int)db()->lastInsertId();
                db()->prepare("UPDATE apps SET slug=? WHERE id=?")->execute([$slugBase . '-' . $id, $id]);
            }
            flash('تم حفظ التطبيق بنجاح.');
            redirect('?page=admin&tab=apps');

        case 'admin_delete_app':
            db()->prepare("DELETE FROM apps WHERE id=?")->execute([(int)$_POST['id']]);
            flash('تم حذف التطبيق.');
            redirect('?page=admin&tab=apps');

        case 'admin_satofill_sync':
            try {
                $n = satofill_sync_products();
                flash("تمت مزامنة $n منتج من Satofill بنجاح.");
            } catch (Throwable $e) {
                flash('فشلت المزامنة مع Satofill: ' . $e->getMessage());
            }
            redirect('?page=admin&tab=products');

        case 'admin_order_decision':
            $oid = (int)$_POST['id']; $dec = $_POST['decision'] === 'approve' ? 'approved' : 'rejected';
            db()->prepare("UPDATE orders SET status=?, admin_note=? WHERE id=?")->execute([$dec, $_POST['note'] ?? '', $oid]);
            $orderInfo = db()->prepare("SELECT u.telegram_id, p.name pname FROM orders o JOIN users u ON u.id=o.user_id JOIN products p ON p.id=o.product_id WHERE o.id=?");
            $orderInfo->execute([$oid]);
            if ($oi = $orderInfo->fetch()) {
                if (!empty($oi['telegram_id'])) {
                    $msg = $dec === 'approved'
                        ? "✅ تم قبول طلبك على منتج «" . e($oi['pname']) . "» بنجاح."
                        : "❌ تم رفض طلبك على منتج «" . e($oi['pname']) . "»" . (!empty($_POST['note']) ? ("\nسبب: " . e($_POST['note'])) : '') . ".";
                    tg_notify_user($oi['telegram_id'], $msg);
                }
            }
            flash('تم تحديث حالة الطلب.');
            redirect('?page=admin&tab=orders');

        case 'admin_save_wallet':
            // ضمان عدم تفعيل أكثر من محفظة واحدة لنفس وسيلة الدفع (مثل الشام كاش) في نفس الوقت
            db()->prepare("UPDATE wallets SET active=0 WHERE type=?")->execute([$_POST['type']]);
            db()->prepare("INSERT INTO wallets (type, label, address) VALUES (?,?,?)")
                ->execute([$_POST['type'], $_POST['label'], $_POST['address']]);
            flash('تمت إضافة المحفظة.');
            redirect('?page=admin&tab=wallets');

        case 'admin_toggle_wallet':
            $wid = (int)$_POST['id'];
            $st = db()->prepare("SELECT type, active FROM wallets WHERE id=?"); $st->execute([$wid]);
            $row = $st->fetch();
            if ($row && !$row['active']) {
                db()->prepare("UPDATE wallets SET active=0 WHERE type=?")->execute([$row['type']]);
            }
            db()->prepare("UPDATE wallets SET active = 1 - active WHERE id=?")->execute([$wid]);
            redirect('?page=admin&tab=wallets');

        case 'admin_delete_wallet':
            db()->prepare("DELETE FROM wallets WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=wallets');

        case 'admin_save_openrouter_key':
            $apiKey = trim((string)($_POST['api_key'] ?? ''));
            if ($apiKey !== '') {
                $maxOrd = (int)db()->query("SELECT COALESCE(MAX(sort_order),0) m FROM openrouter_keys")->fetch()['m'];
                db()->prepare("INSERT INTO openrouter_keys (label, api_key, sort_order) VALUES (?,?,?)")
                    ->execute([trim((string)($_POST['label'] ?? '')) ?: null, $apiKey, $maxOrd + 1]);
                flash('تمت إضافة المفتاح.');
            }
            redirect('?page=admin&tab=settings');

        case 'admin_toggle_openrouter_key':
            db()->prepare("UPDATE openrouter_keys SET active = 1 - active WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=settings');

        case 'admin_delete_openrouter_key':
            db()->prepare("DELETE FROM openrouter_keys WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=settings');

        case 'admin_save_home_sections':
            set_setting('home_sections_order', preg_replace('/[^a-z_,]/', '', $_POST['order'] ?? ''));
            set_setting('home_sections_hidden', preg_replace('/[^a-z_,]/', '', $_POST['hidden'] ?? ''));
            flash('تم حفظ تخطيط الصفحة الرئيسية.');
            redirect('?page=admin&tab=homepage');

        case 'admin_save_banner':
            db()->prepare("INSERT INTO banners (image, link) VALUES (?,?)")->execute([$_POST['image'], $_POST['link']]);
            redirect('?page=admin&tab=banners');

        case 'admin_delete_banner':
            db()->prepare("DELETE FROM banners WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=banners');

        case 'admin_save_ticker':
            db()->prepare("INSERT INTO tickers (text, link) VALUES (?,?)")->execute([trim($_POST['text'] ?? ''), trim($_POST['link'] ?? '') ?: null]);
            redirect('?page=admin&tab=banners');

        case 'admin_toggle_ticker':
            db()->prepare("UPDATE tickers SET active = 1 - active WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=banners');

        case 'admin_delete_ticker':
            db()->prepare("DELETE FROM tickers WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=banners');

        case 'admin_suggestion_decision':
            db()->prepare("UPDATE product_suggestions SET status=? WHERE id=?")->execute([$_POST['status'] === 'dismissed' ? 'dismissed' : 'reviewed', (int)$_POST['id']]);
            redirect('?page=admin&tab=suggestions');

        case 'admin_report_decision':
            db()->prepare("UPDATE app_reports SET status='resolved' WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=reports');

        case 'admin_block_ip':
            $blockIp = trim($_POST['ip'] ?? '');
            if ($blockIp !== '' && filter_var($blockIp, FILTER_VALIDATE_IP)) {
                $sqlBlock = DB_DRIVER === 'sqlite'
                    ? "INSERT INTO blocked_ips (ip, reason) VALUES (?,?) ON CONFLICT(ip) DO UPDATE SET reason=excluded.reason"
                    : "INSERT INTO blocked_ips (ip, reason) VALUES (?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)";
                db()->prepare($sqlBlock)->execute([$blockIp, trim($_POST['reason'] ?? '')]);
                flash('تم حظر العنوان.');
            }
            redirect('?page=admin&tab=security');

        case 'admin_unblock_ip':
            db()->prepare("DELETE FROM blocked_ips WHERE id=?")->execute([(int)$_POST['id']]);
            flash('تم إلغاء الحظر.');
            redirect('?page=admin&tab=security');

        case 'admin_bot_save':
            $botId = (int)($_POST['id'] ?? 0);
            $botName = trim($_POST['name'] ?? '');
            $botDesc = trim($_POST['description'] ?? '');
            $botCategory = in_array($_POST['category'] ?? '', ['telegram_bot', 'script', 'tool'], true) ? $_POST['category'] : 'script';
            $botVersion = trim($_POST['version'] ?? '1.0');
            if ($botName === '') { flash('الاسم مطلوب.', 'error'); redirect('?page=admin&tab=bots'); }

            $filePath = null;
            if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $f = $_FILES['file'];
                $allowedExt = ['zip', 'php', 'py', 'js', 'txt', 'sh'];
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true) || $f['size'] > 20 * 1024 * 1024) {
                    flash('الملف يجب أن يكون من نوع (zip/php/py/js/txt/sh) وأصغر من 20MB.', 'error');
                    redirect('?page=admin&tab=bots');
                }
                $destDir = __DIR__ . '/uploads/bots';
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                $filename = bin2hex(random_bytes(8)) . '.' . $ext;
                move_uploaded_file($f['tmp_name'], $destDir . '/' . $filename);
                $filePath = 'uploads/bots/' . $filename;
            }

            if ($botId > 0) {
                $st = db()->prepare("SELECT * FROM bot_scripts WHERE id=?"); $st->execute([$botId]); $existing = $st->fetch();
                if (!$existing) { flash('العنصر غير موجود.', 'error'); redirect('?page=admin&tab=bots'); }
                if ($filePath === null) $filePath = $existing['file_path'];
                db()->prepare("UPDATE bot_scripts SET name=?, description=?, category=?, version=?, file_path=? WHERE id=?")
                    ->execute([$botName, $botDesc, $botCategory, $botVersion, $filePath, $botId]);
            } else {
                db()->prepare("INSERT INTO bot_scripts (name, description, category, icon, file_path, version, is_template, status) VALUES (?,?,?,?,?,?,0,'active')")
                    ->execute([$botName, $botDesc, $botCategory, 'terminal', $filePath, $botVersion]);
            }
            flash('تم الحفظ.');
            redirect('?page=admin&tab=bots');

        case 'admin_bot_toggle':
            $st = db()->prepare("SELECT status FROM bot_scripts WHERE id=?"); $st->execute([(int)$_POST['id']]); $row = $st->fetch();
            if ($row) {
                $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
                db()->prepare("UPDATE bot_scripts SET status=? WHERE id=?")->execute([$newStatus, (int)$_POST['id']]);
            }
            redirect('?page=admin&tab=bots');

        case 'admin_bot_delete':
            $st = db()->prepare("SELECT * FROM bot_scripts WHERE id=?"); $st->execute([(int)$_POST['id']]); $row = $st->fetch();
            if ($row && !$row['is_template'] && $row['file_path'] && is_file(__DIR__ . '/' . $row['file_path'])) {
                @unlink(__DIR__ . '/' . $row['file_path']);
            }
            if ($row && !$row['is_template']) {
                db()->prepare("DELETE FROM bot_scripts WHERE id=?")->execute([(int)$_POST['id']]);
                flash('تم الحذف.');
            }
            redirect('?page=admin&tab=bots');

        case 'admin_bot_download':
            $st = db()->prepare("SELECT * FROM bot_scripts WHERE id=?"); $st->execute([(int)($_GET['id'] ?? 0)]); $row = $st->fetch();
            if (!$row || !$row['file_path'] || !is_file(__DIR__ . '/' . $row['file_path'])) { die('الملف غير متوفر.'); }
            db()->prepare("UPDATE bot_scripts SET downloads = downloads + 1 WHERE id=?")->execute([$row['id']]);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($row['file_path']) . '"');
            header('Content-Length: ' . filesize(__DIR__ . '/' . $row['file_path']));
            readfile(__DIR__ . '/' . $row['file_path']);
            exit;

        case 'admin_save_settings':
            $redirectTab = preg_replace('/[^a-z_]/', '', $_POST['redirect_tab'] ?? 'settings') ?: 'settings';
            foreach ($_POST as $k => $v) {
                if ($k === 'action' || $k === 'csrf' || $k === 'redirect_tab') continue;
                if ($k === 'bot_token' && $v === '') continue; // حقل التوكن لا يُفرَّغ إذا تُرك خالياً
                set_setting($k, $v);
            }
            if (array_key_exists('moneytag_sw_enabled', $_POST) || array_key_exists('moneytag_sw_content', $_POST)) {
                write_moneytag_sw_file();
            }
            flash('تم حفظ الإعدادات.');
            redirect('?page=admin&tab=' . $redirectTab);

        case 'admin_save_page':
            $mTitle = trim($_POST['meta_title'] ?? '');
            $mDesc = trim($_POST['meta_description'] ?? '');
            db()->prepare(DB_DRIVER === 'sqlite'
                ? "INSERT INTO pages (slug, content, meta_title, meta_description) VALUES (?,?,?,?) ON CONFLICT(slug) DO UPDATE SET content=excluded.content, meta_title=excluded.meta_title, meta_description=excluded.meta_description"
                : "INSERT INTO pages (slug, content, meta_title, meta_description) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE content=VALUES(content), meta_title=VALUES(meta_title), meta_description=VALUES(meta_description)")
                ->execute([$_POST['slug'], $_POST['content'], $mTitle, $mDesc]);
            flash('تم حفظ الصفحة.');
            redirect('?page=admin&tab=pages');

        case 'admin_user_action':
            $uid = (int)$_POST['id'];
            if ($_POST['op'] === 'ban') db()->prepare("UPDATE users SET is_banned=1 WHERE id=?")->execute([$uid]);
            if ($_POST['op'] === 'unban') db()->prepare("UPDATE users SET is_banned=0 WHERE id=?")->execute([$uid]);
            redirect('?page=admin&tab=users');

        case 'admin_export_csv':
            $exportSpecs = [
                'apps' => ['apps', ['id', 'name', 'kind', 'version', 'category', 'downloads', 'views', 'rating_avg', 'status', 'created_at']],
                'products' => ['products', ['id', 'name', 'price', 'old_price', 'status', 'created_at']],
                'orders' => ['orders', ['id', 'user_id', 'product_id', 'price', 'status', 'created_at']],
                'users' => ['users', ['id', 'name', 'username', 'email', 'role', 'is_banned', 'created_at']],
            ];
            $exportType = $_GET['type'] ?? '';
            if (!isset($exportSpecs[$exportType])) { die('نوع تصدير غير معروف.'); }
            [$exportTable, $exportCols] = $exportSpecs[$exportType];
            $rows = db()->query("SELECT " . implode(',', $exportCols) . " FROM $exportTable ORDER BY id DESC")->fetchAll();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $exportType . '_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF");
            fputcsv($out, $exportCols);
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
            exit;

        default:
            die('إجراء غير معروف.');
    }
}

/* ======================================================================
   5) DATA FOR VIEWS
   ====================================================================== */
$user = current_user();
if ($page === 'login' && $user) { redirect('?'); }
$siteName = setting('site_name');
$logo = setting('logo_url');
$activeWallets = db()->query("SELECT * FROM wallets WHERE active=1")->fetchAll();

/* ---- SEO: per-page title / description / canonical / structured data ---- */
$seoProduct = null;
if ($page === 'product') {
    $st = db()->prepare("SELECT * FROM products WHERE id=? AND status='active'");
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $seoProduct = $st->fetch();
    if (!$seoProduct) { http_response_code(404); }
}
$seoApp = null;
if (in_array($page, ['app', 'app_download'], true)) {
    $st = db()->prepare("SELECT * FROM apps WHERE id=? AND status='published'");
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $seoApp = $st->fetch();
    if (!$seoApp) { http_response_code(404); }
}
$pageLabels = ['home' => 'الرئيسية', 'favorites' => 'المفضّلة', 'orders' => 'طلباتي', 'privacy' => 'سياسة الخصوصية', 'terms' => 'شروط الاستخدام', 'admin' => 'لوحة الإدارة', 'apps' => 'تطبيقات وألعاب', 'store' => 'المتجر', 'thankyou' => 'شكراً لزيارتك', 'about' => 'من نحن', 'faq' => 'الأسئلة الشائعة', 'contact' => 'تواصل معنا', 'guide' => 'دليل تحميل التطبيقات والألعاب أندرويد بأمان', 'top' => 'أفضل تطبيقات وألعاب أندرويد مهكرة ومجانية'];
if ($seoApp) {
    $seoTitle = ($seoApp['seo_title'] ?: $seoApp['name']) . ' — تحميل ' . ($seoApp['kind'] === 'game' ? 'لعبة' : 'تطبيق') . ' مجاناً — ' . e($siteName);
    $seoDesc = $seoApp['seo_description'] ?: ($seoApp['short_description'] ?: mb_substr((string)$seoApp['description'], 0, 155));
    $seoImage = $seoApp['icon'] ?: $logo;
    $seoCanonical = app_canonical_url($seoApp);
} elseif ($seoProduct) {
    $seoTitle = $seoProduct['name'] . ' — ' . e($siteName);
    $seoDesc = $seoProduct['meta_description'] ?: mb_substr((string)$seoProduct['description'], 0, 155);
    $seoImage = $seoProduct['image'] ?: $logo;
    $seoCanonical = rtrim(SITE_URL, '/') . '/index.php?page=product&id=' . (int)$seoProduct['id'];
} elseif (in_array($page, ['privacy', 'terms', 'about', 'faq', 'contact', 'guide', 'top'], true)) {
    $pg = db()->prepare("SELECT * FROM pages WHERE slug=?"); $pg->execute([$page]); $pgRow = $pg->fetch();
    $seoTitle = ($pgRow['meta_title'] ?? '') ?: ($pageLabels[$page] . ' — ' . $siteName);
    $seoDesc = ($pgRow['meta_description'] ?? '') ?: mb_substr((string)($pgRow['content'] ?? ''), 0, 160) ?: setting('site_description');
    $seoImage = $logo;
    $seoCanonical = rtrim(SITE_URL, '/') . '/index.php?page=' . $page;
} elseif ($page === 'home') {
    $seoTitle = $siteName . ' — ' . setting('banner_subtitle');
    $seoDesc = setting('site_description');
    $seoImage = $logo;
    $seoCanonical = rtrim(SITE_URL, '/') . '/index.php';
} else {
    $seoTitle = ($pageLabels[$page] ?? $siteName) . ' — ' . $siteName;
    $seoDesc = setting('site_description');
    $seoImage = $logo;
    $seoCanonical = rtrim(SITE_URL, '/') . '/index.php?page=' . e($page);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title><?= e($seoTitle) ?></title>
<meta name="description" content="<?= e($seoDesc) ?>">
<meta name="keywords" content="<?= e(setting('site_keywords')) ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<?php if ($page !== 'admin' && !$user): ?><meta name="robots" content="index, follow"><?php else: ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<?php if (setting('google_site_verification')): ?><meta name="google-site-verification" content="<?= e(setting('google_site_verification')) ?>"><?php endif; ?>
<?php if (setting('adsense_client_id')): ?><meta name="google-adsense-account" content="<?= e(setting('adsense_client_id')) ?>"><script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=<?= e(setting('adsense_client_id')) ?>" crossorigin="anonymous"></script><?php endif; ?>
<meta property="og:type" content="<?= $seoApp ? 'website' : ($seoProduct ? 'product' : 'website') ?>">
<meta property="og:title" content="<?= e($seoTitle) ?>">
<meta property="og:description" content="<?= e($seoDesc) ?>">
<?php if ($seoImage): ?><meta property="og:image" content="<?= e($seoImage) ?>"><?php endif; ?>
<?php if ($logo): ?><link rel="icon" href="<?= e($logo) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($seoCanonical) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap">
<?php if ($seoApp): ?>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"SoftwareApplication","name":"<?= e($seoApp['name']) ?>","description":"<?= e($seoDesc) ?>","image":"<?= e($seoImage) ?>","applicationCategory":"<?= $seoApp['kind'] === 'game' ? 'GameApplication' : 'MobileApplication' ?>","operatingSystem":"ANDROID"<?= $seoApp['version'] ? ',"softwareVersion":"' . e($seoApp['version']) . '"' : '' ?><?= $seoApp['rating_avg'] ? ',"aggregateRating":{"@type":"AggregateRating","ratingValue":"' . e($seoApp['rating_avg']) . '","ratingCount":"' . max(1, (int)$seoApp['downloads']) . '"}' : '' ?>,"offers":{"@type":"Offer","price":"0","priceCurrency":"USD"}}
</script>
<?php elseif ($seoProduct): ?>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Product","name":"<?= e($seoProduct['name']) ?>","description":"<?= e($seoDesc) ?>","image":"<?= e($seoImage) ?>","offers":{"@type":"Offer","price":"<?= e($seoProduct['price']) ?>","priceCurrency":"USD","availability":"https://schema.org/InStock"}}
</script>
<?php else: ?>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"WebSite","name":"<?= e($siteName) ?>","url":"<?= e(SITE_URL) ?>"<?= $logo ? ',"image":"' . e($logo) . '"' : '' ?>}
</script>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Organization","name":"<?= e($siteName) ?>","url":"<?= e(SITE_URL) ?>"<?= $logo ? ',"logo":"' . e($logo) . '"' : '' ?>}
</script>
<?php endif; ?>
<style>
:root{--bg:#0b1120;--bg2:#121b30;--card:#16213b;--card2:#1c2a48;--accent:#2563eb;--accent-d:#1d4ed8;--accent2:#06b6d4;--accent2-d:#0891b2;--gold:#ffc233;--text:#eef2ff;--muted:#8b9bbd;--danger:#ff5c5c;--radius:18px;--shadow:0 10px 30px rgba(0,0,0,.45);--glow:0 0 0 1px rgba(37,99,235,.3),0 8px 30px rgba(37,99,235,.2);--ease:cubic-bezier(.22,1,.36,1)}
*{box-sizing:border-box;margin:0;padding:0}
*::selection{background:var(--accent);color:#fff}
html{scroll-behavior:smooth}
body{font-family:'Cairo','Segoe UI',Tahoma,Arial,sans-serif;color:var(--text);min-height:100vh;background:var(--bg);background-image:radial-gradient(1200px 600px at 100% -10%,rgba(230,41,75,.10),transparent 60%),radial-gradient(1000px 600px at -10% 10%,rgba(255,77,77,.08),transparent 55%);background-attachment:fixed;animation:bodyFadeIn .5s var(--ease) both}
@keyframes bodyFadeIn{from{opacity:0}to{opacity:1}}
.ic{transition:transform .25s var(--ease)}
a:hover>.ic,button:hover>.ic,.btn:hover .ic{transform:scale(1.12)}
a{color:inherit;text-decoration:none}
::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:#2a3350;border-radius:10px;border:2px solid var(--bg)}
::-webkit-scrollbar-thumb:hover{background:var(--accent)}
#preloader{position:fixed;inset:0;background:radial-gradient(900px 500px at 50% 0%,rgba(230,41,75,.14),transparent 60%),var(--bg);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;transition:opacity .4s}
.pl-ring{position:relative;width:108px;height:108px;display:flex;align-items:center;justify-content:center}
.pl-ring svg{width:108px;height:108px;transform:rotate(-90deg)}
.pl-ring circle{fill:none;stroke-width:5}
.pl-ring .pl-track{stroke:#28304a}
.pl-ring .pl-bar{stroke:var(--accent);stroke-linecap:round;stroke-dasharray:301;stroke-dashoffset:301;transition:stroke-dashoffset .15s linear}
.pl-ring img,.pl-ring .pl-fallback{position:absolute;width:62px;height:62px;border-radius:50%;object-fit:cover}
.pl-pct{position:absolute;bottom:-30px;font-size:13px;font-weight:700;color:var(--accent2)}
#preloader .pl-text{color:var(--muted);font-size:13px;margin-top:14px}
#preloader img{width:64px;height:64px;border-radius:50%}
.spinner{width:46px;height:46px;border:4px solid #2a3350;border-top-color:var(--accent);border-radius:50%;animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.topbar{position:sticky;top:0;z-index:50;display:flex;align-items:center;gap:12px;padding:12px 18px;background:rgba(13,17,30,.72);backdrop-filter:blur(16px) saturate(150%);-webkit-backdrop-filter:blur(16px) saturate(150%);border-bottom:1px solid rgba(230,41,75,.15);box-shadow:0 4px 24px rgba(0,0,0,.25)}
.burger{cursor:pointer;font-size:22px;background:#18223a;border:1px solid #2a3350;border-radius:12px;color:var(--text);width:42px;height:42px;display:flex;align-items:center;justify-content:center;transition:.2s var(--ease)}
.burger:hover{background:var(--accent);transform:translateY(-1px);border-color:var(--accent)}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:18px;letter-spacing:.3px}
.brand img{width:34px;height:34px;flex-shrink:0;object-fit:cover;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.3)}
.brand .ic{filter:drop-shadow(0 2px 6px rgba(255,77,77,.5))}
.topbar .grow{flex:1}
.btn{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:12px;border:none;cursor:pointer;font-weight:700;font-size:14px;font-family:inherit;transition:transform .18s var(--ease),box-shadow .25s var(--ease),filter .2s,background .2s;overflow:hidden;-webkit-tap-highlight-color:transparent}
.btn::after{content:"";position:absolute;top:0;left:-120%;width:60%;height:100%;background:linear-gradient(120deg,transparent,rgba(255,255,255,.28),transparent);transform:skewX(-20deg);transition:left .6s var(--ease)}
.btn:hover::after{left:140%}
.btn:active{transform:scale(.96)}
.btn-primary{background:linear-gradient(135deg,var(--accent),#38bdf8);color:#fff;box-shadow:0 6px 18px rgba(230,41,75,.35)}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(230,41,75,.5)}
.btn-ghost{background:#2e1920;color:var(--text)}
.btn-ghost:hover{background:#391e26;transform:translateY(-1px)}
.btn-success{background:linear-gradient(135deg,#22c55e,#16a34a);color:#06251c;box-shadow:0 6px 18px rgba(34,197,94,.3)}
.btn-success:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(34,197,94,.45)}
.btn-danger{background:linear-gradient(135deg,var(--danger),#38bdf8);color:#250505}
.btn-danger:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(255,92,92,.4)}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none}
.btn:disabled::after{display:none}
.ripple{position:absolute;border-radius:50%;background:rgba(255,255,255,.45);transform:scale(0);animation:rippleAnim .6s var(--ease);pointer-events:none}
@keyframes rippleAnim{to{transform:scale(2.5);opacity:0}}
.user-chip{display:flex;align-items:center;gap:8px;background:#28304a;padding:6px 10px;border-radius:30px}
.user-chip img{width:26px;height:26px;flex-shrink:0;object-fit:cover;border-radius:50%}
.sidebar{position:fixed;top:0;right:-300px;width:280px;height:100%;background:var(--bg2);z-index:60;transition:right .3s;overflow-y:auto;box-shadow:-10px 0 30px rgba(0,0,0,.3)}
.sidebar.open{right:0}
.sidebar .sb-head{padding:18px;border-bottom:1px solid #3a1f26;display:flex;justify-content:space-between;align-items:center}
.sidebar nav a{position:relative;display:flex;align-items:center;gap:12px;padding:15px 18px;color:var(--text);border-bottom:1px solid #2b151b;font-size:15px;font-weight:600;transition:background .2s,padding .2s var(--ease)}
.sidebar nav a::before{content:"";position:absolute;right:0;top:0;bottom:0;width:4px;background:linear-gradient(var(--accent),var(--accent2));transform:scaleY(0);transition:transform .25s var(--ease)}
.sidebar nav a:hover{background:#1c2a48;padding-right:24px}
.sidebar nav a:hover::before{transform:scaleY(1)}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:55;display:none}
.overlay.show{display:block}
.banner{margin:18px 18px 0;border-radius:24px 24px 0 0;background:linear-gradient(135deg,#5a0e1a,#1d4ed8 55%,#06b6d4);padding:46px 28px;position:relative;overflow:hidden;box-shadow:0 14px 40px rgba(163,24,44,.35);animation:fadeUp .7s var(--ease) both;background-size:cover;background-position:center}
.banner.has-bg::before,.banner.has-bg::after{display:none}
.banner.has-bg{background-blend-mode:overlay}
.banner.has-bg .banner-overlay{position:absolute;inset:0;background:linear-gradient(135deg,rgba(13,30,60,.72),rgba(13,30,60,.45))}
.banner::before{content:"";position:absolute;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.25),transparent 70%);top:-120px;right:-60px;animation:float 8s ease-in-out infinite}
.banner::after{content:"";position:absolute;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(255,77,77,.35),transparent 70%);bottom:-110px;left:-40px;animation:float 10s ease-in-out infinite reverse}
.banner h1{font-size:30px;margin-bottom:10px;position:relative;z-index:1;text-shadow:0 2px 12px rgba(0,0,0,.25)}
.banner p{color:#f0f0ff;opacity:.95;position:relative;z-index:1;font-size:15px}
@keyframes float{0%,100%{transform:translate(0,0) scale(1)}50%{transform:translate(-14px,16px) scale(1.08)}}

.ticker-bar{margin:0 18px 18px;background:#1d0d12;border:1px solid rgba(255,255,255,.12);border-radius:0 0 24px 24px;border-top:none;display:flex;align-items:center;gap:10px;padding:10px 16px;overflow:hidden;position:relative}
.ticker-bar .ticker-badge{flex-shrink:0;display:flex;align-items:center;gap:6px;background:var(--accent2);color:#06251c;font-weight:700;font-size:12px;border-radius:20px;padding:5px 12px;z-index:2}
.ticker-track{flex:1;overflow:hidden;position:relative;mask-image:linear-gradient(90deg,transparent,#000 6%,#000 94%,transparent)}
.ticker-track-inner{display:flex;gap:48px;white-space:nowrap;animation:tickerScroll 28s linear infinite}
.ticker-track-inner:hover{animation-play-state:paused}
.ticker-item{display:inline-flex;align-items:center;gap:8px;font-size:13px;color:var(--text);font-weight:600}
.ticker-item .ic{color:var(--accent2)}
@keyframes tickerScroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}
.live-ticker .ticker-badge{background:var(--accent)}
.section-title{margin:28px 18px 12px;font-size:20px;font-weight:800;display:flex;align-items:center;gap:10px;position:relative}
.section-title::before{content:"";width:5px;height:22px;border-radius:6px;background:linear-gradient(var(--accent),var(--accent2))}
.section-title .ic{color:var(--accent2)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:12px;padding:0 18px 30px}
.card{background:linear-gradient(180deg,var(--card),#1c2a48);border-radius:var(--radius);padding:12px;position:relative;border:1px solid #28304a;transition:transform .28s var(--ease),box-shadow .28s var(--ease),border-color .28s;overflow:hidden}
.card::before{content:"";position:absolute;inset:0;border-radius:inherit;padding:1px;background:linear-gradient(135deg,rgba(230,41,75,.6),transparent 40%,rgba(255,77,77,.5));-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;opacity:0;transition:opacity .3s}
.card:hover{transform:translateY(-6px);box-shadow:0 16px 40px rgba(0,0,0,.45)}
.card:hover::before{opacity:1}
.card .tag{position:absolute;top:12px;left:12px;background:linear-gradient(135deg,var(--accent2),#38bdf8);color:#06251c;font-size:11px;padding:4px 10px;border-radius:20px;font-weight:800;z-index:2;box-shadow:0 4px 12px rgba(255,77,77,.4)}
.card .icon{font-size:28px;margin-bottom:6px}
.card img.pimg{width:100%;height:96px;object-fit:cover;border-radius:10px;margin-bottom:8px;transition:transform .4s var(--ease)}
.card:hover img.pimg{transform:scale(1.07)}
.card h3{font-size:14px;margin-bottom:5px;min-height:34px;transition:color .2s}
.card:hover h3{color:var(--accent2)}
.card .price{font-size:18px;font-weight:800;color:var(--accent2)}
.card .old{color:var(--muted);text-decoration:line-through;font-size:13px;margin-right:6px}
.card .desc{font-size:12px;color:var(--muted);margin:6px 0;max-height:36px;overflow:hidden}
.card .buy{width:100%;margin-top:10px}
.empty{padding:60px 20px;text-align:center;color:var(--muted);font-size:15px}
.empty .ic{color:var(--accent);opacity:.7;margin-bottom:6px}
.bottom-nav{position:fixed;bottom:0;left:0;right:0;display:flex;background:rgba(13,17,30,.82);backdrop-filter:blur(16px) saturate(150%);-webkit-backdrop-filter:blur(16px) saturate(150%);border-top:1px solid rgba(230,41,75,.15);z-index:40;box-shadow:0 -4px 24px rgba(0,0,0,.3)}
.bottom-nav a{position:relative;flex:1;text-align:center;padding:11px 4px 9px;font-size:11px;font-weight:600;color:var(--muted);transition:color .25s var(--ease)}
.bottom-nav a .ic{transition:transform .25s var(--ease)}
.bottom-nav a:active .ic{transform:scale(.85)}
.bottom-nav a.active{color:var(--accent2)}
.bottom-nav a.active .ic{transform:translateY(-2px) scale(1.12);filter:drop-shadow(0 4px 8px rgba(255,77,77,.5))}
.bottom-nav a.active::before{content:"";position:absolute;top:0;left:50%;transform:translateX(-50%);width:30px;height:3px;border-radius:0 0 6px 6px;background:linear-gradient(90deg,var(--accent),var(--accent2))}
.bottom-nav .bi{font-size:18px;display:block;margin-bottom:2px}
.container{max-width:1000px;margin:0 auto;padding-bottom:80px}
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;display:flex;align-items:center;justify-content:center;padding:16px}
.modal-bg{animation:fadeIn .25s ease}
.modal{background:linear-gradient(180deg,var(--card),#141d33);border-radius:22px;padding:24px;max-width:430px;width:100%;max-height:85vh;overflow:auto;border:1px solid #2a3350;box-shadow:0 24px 60px rgba(0,0,0,.5);animation:modalPop .35s var(--ease)}
@keyframes modalPop{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal h2{margin-bottom:14px;display:flex;align-items:center;gap:8px}
.modal input,.modal textarea,.modal select{width:100%;padding:12px;border-radius:12px;border:1px solid #2a3350;background:#101a2e;color:var(--text);margin-bottom:10px;font-family:inherit;font-size:14px;transition:border-color .2s,box-shadow .2s}
.modal input:focus,.modal textarea:focus,.modal select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(230,41,75,.2)}
.toast{position:fixed;bottom:96px;left:50%;transform:translateX(-50%) translateY(20px);background:linear-gradient(135deg,#28304a,#28324e);padding:13px 22px;border-radius:30px;z-index:300;display:none;font-size:14px;font-weight:600;box-shadow:0 12px 30px rgba(0,0,0,.45);border:1px solid #4a2530}
.toast.show{display:block;animation:toastIn .4s var(--ease) forwards}
@keyframes toastIn{to{transform:translateX(-50%) translateY(0)}}
.policy-modal{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:500;display:flex;align-items:center;justify-content:center;padding:16px}
.policy-box{background:var(--card);border-radius:var(--radius);padding:24px;max-width:480px}
.flash{margin:14px 18px;padding:12px 16px;border-radius:10px;background:#1d3b2e;border:1px solid var(--accent2)}
.flash.error{background:#243152;border-color:var(--danger)}
table{width:100%;border-collapse:collapse;font-size:13px}
table th,table td{padding:8px;border-bottom:1px solid #28304a;text-align:right}
.admin-tabs{display:flex;flex-wrap:wrap;gap:8px;padding:14px 18px}
.admin-tabs a{padding:8px 14px;border-radius:10px;background:#28304a;font-size:13px}
.admin-tabs a.active{background:var(--accent)}
.admin-box{background:var(--card);margin:0 18px 20px;border-radius:var(--radius);padding:18px;overflow-x:auto}
.formrow{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:12px}
.badge{padding:2px 8px;border-radius:8px;font-size:11px}
.badge.pending{background:#5a4a1c}
.badge.approved{background:#1d3b2e}
.badge.rejected{background:#243152}
footer{text-align:center;color:var(--muted);padding:30px 10px;font-size:12px}
.ic{width:18px;height:18px;display:inline-block;vertical-align:middle;flex-shrink:0}
.ic-sm{width:14px;height:14px}
.ic-lg{width:26px;height:26px}
.ic-xl{width:38px;height:38px}
.btn .ic{margin-inline-end:6px;margin-bottom:2px}
.burger .ic{width:24px;height:24px}
.sidebar nav a .ic{color:var(--accent2)}
.bottom-nav a .ic{display:block;margin:0 auto 3px}
.bottom-nav a.active .ic{color:var(--accent2)}
.admin-tabs a{display:inline-flex;align-items:center;gap:6px}
.card .icon-wrap{width:42px;height:42px;flex-shrink:0;border-radius:11px;background:#101a2e;display:flex;align-items:center;justify-content:center;margin-bottom:8px;color:var(--accent2)}
.card .icon-wrap.emoji-icon{font-size:20px;line-height:1}
.wish-btn{position:absolute;top:10px;left:10px;z-index:2;width:32px;height:32px;border-radius:50%;background:rgba(0,0,0,.4);border:1px solid #2a3350;display:flex;align-items:center;justify-content:center;color:#fff;cursor:pointer;transition:.2s}
.wish-btn .ic{fill:none;stroke:currentColor}
.wish-btn.active{color:var(--accent2)}
.wish-btn.active .ic{fill:var(--accent2)}
.search-bar{display:flex;gap:8px;margin:0 18px 16px}
.search-bar input{flex:1;padding:12px 14px;border-radius:12px;border:1px solid #2a3350;background:#101a2e;color:var(--text);font-size:14px}
.search-bar button{width:44px;border-radius:12px;border:1px solid #2a3350;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer}
.star-rating{display:flex;gap:2px;color:var(--accent2)}
.star-rating .ic{width:16px;height:16px}
.profile-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.profile-stat{display:flex;flex-direction:column;align-items:center;gap:6px;background:#101a2e;border:1px solid #28304a;border-radius:14px;padding:14px 8px;text-align:center}
.profile-stat strong{font-size:18px}
.profile-stat span{color:var(--muted);font-size:12px}
.profile-info-row{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #28304a;font-size:13px}
.profile-info-row:last-child{border-bottom:none}
.profile-info-row span{color:var(--muted)}
.achv-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
.achv-badge{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;background:#101a2e;border:1px solid #28304a;color:var(--muted);font-size:13px;opacity:.5}
.achv-badge.on{opacity:1;color:var(--text);border-color:var(--accent);background:rgba(230,41,75,.1)}
.achv-badge.on .ic{color:var(--accent2)}
.balance-pill-sm{display:flex;align-items:center;gap:5px;background:#101a2e;border:1px solid #28304a;border-radius:20px;padding:6px 12px;font-size:13px;font-weight:700;color:var(--accent2)}
@media (max-width:480px){.profile-grid{grid-template-columns:repeat(2,1fr)}.achv-grid{grid-template-columns:1fr}}
.icon-wrap .ic{flex-shrink:0}
.card img.pimg{display:block;flex-shrink:0}
.brand .ic{color:var(--accent2)}
.stat-card{background:#101a2e;border-radius:12px;padding:14px;display:flex;align-items:center;gap:12px}
.stat-card .ic{color:var(--accent2);background:#1c2840;border-radius:10px;padding:8px;width:36px;height:36px}
.stat-card .num{font-size:20px;font-weight:800}
.stat-card .lbl{color:var(--muted);font-size:12px}
.upload-row{display:flex;gap:8px;align-items:center;margin-bottom:10px}
.upload-row input[type=text],.upload-row input:not([type]),.upload-row textarea{flex:1;margin-bottom:0}
.upload-row textarea{align-self:stretch}
.upload-row label.btn{margin:0;white-space:nowrap;cursor:pointer}
.btn-ai{background:linear-gradient(135deg,#7c3aed,#06b6d4);color:#fff;width:100%;justify-content:center;margin-bottom:10px}
.btn-ai-icon{flex-shrink:0;width:38px;height:38px;display:flex;align-items:center;justify-content:center;border-radius:10px;border:1px solid #28304a;background:linear-gradient(135deg,#7c3aed,#06b6d4);color:#fff;cursor:pointer}
.btn-ai-icon:hover{filter:brightness(1.1)}
.upload-row .preview{width:44px;height:44px;border-radius:8px;object-fit:cover;background:#101a2e}
.icon-badge{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px}
.icon-badge.ok{background:#1d3b2e;color:var(--accent2)}
.icon-badge.no{background:#243152;color:var(--danger)}

.wallet-balance-card{background:linear-gradient(135deg,#1c2840,#3a1c30);border:1px solid #3f1f2c;border-radius:18px;padding:20px;margin:18px;position:relative;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.25)}
.wallet-balance-card::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 100% 0%,rgba(230,41,75,.25),transparent 60%)}
.wbc-top{display:flex;justify-content:space-between;align-items:center;font-size:13px;color:var(--muted);position:relative;z-index:1}
.wbc-label{display:flex;align-items:center;gap:6px;color:#fff;font-weight:700}
.wbc-amount{display:flex;align-items:center;gap:10px;margin:14px 0 2px;position:relative;z-index:1}
.wbc-amount .ic-xl{color:var(--accent2)}
.wbc-amount span{font-size:34px;font-weight:800}
.wbc-amount small{color:var(--muted);font-size:14px}
.wbc-usd{color:var(--muted);font-size:14px;position:relative;z-index:1}
.wbc-usd strong{color:#fff;font-size:16px}
.wbc-progress{margin-top:14px;position:relative;z-index:1}
.wbc-progress-bar{height:8px;border-radius:6px;background:#101a2e;overflow:hidden}
.wbc-progress-fill{height:100%;border-radius:6px;background:linear-gradient(90deg,var(--accent),var(--accent2));transition:width .6s ease}
.wbc-progress-txt{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);margin-top:6px}

.topup-method{background:#101a2e;border-radius:10px;padding:12px;margin-bottom:8px;transition:transform .15s ease}
.topup-method:hover{transform:translateY(-2px)}
.topup-method-head strong{display:flex;align-items:center;gap:6px;font-size:14px}
.topup-method-addr{display:flex;align-items:center;gap:8px;margin-top:6px}
.topup-method-addr code{flex:1;font-family:monospace;word-break:break-all;color:var(--muted);font-size:12px}
.cat-chips{display:flex;gap:10px;overflow-x:auto;padding:0 18px 14px;scrollbar-width:none}
.cat-chips::-webkit-scrollbar{display:none}
.cat-chip{display:flex;align-items:center;gap:6px;flex-shrink:0;background:#18223a;border:1px solid #28304a;border-radius:30px;padding:8px 16px;font-size:13px;font-weight:600;color:var(--text);transition:transform .2s var(--ease),border-color .2s}
.cat-chip:hover{transform:translateY(-2px);border-color:var(--accent2)}
.cat-chip.active{background:linear-gradient(135deg,var(--accent),var(--accent2));border-color:transparent;color:#fff}
.cat-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;padding:0 18px 16px}
.cat-tile{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;aspect-ratio:1/1;border-radius:16px;overflow:hidden;background:linear-gradient(135deg,#1d4ed8,#06b6d4);box-shadow:0 6px 16px rgba(0,0,0,.25);transition:transform .2s var(--ease)}
.cat-tile:hover{transform:translateY(-4px) scale(1.03)}
.cat-tile img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.cat-tile-icon{flex:1;display:flex;align-items:center;justify-content:center;color:#fff;opacity:.9}
.cat-tile-label{position:relative;z-index:1;width:100%;text-align:center;background:rgba(0,0,0,.55);color:#fff;font-weight:800;font-size:13px;padding:8px 4px}
.cat-tile-mini{width:60px;height:34px;border-radius:6px;overflow:hidden;position:relative}
.cat-tile-mini img{width:100%;height:100%;object-fit:cover}
.banner-carousel{position:relative;margin:0 18px 14px;border-radius:14px;overflow:hidden}
.banner-carousel-track{display:flex;transition:transform .5s var(--ease)}
.banner-carousel-slide{flex:0 0 100%;display:block}
.banner-carousel-slide img{width:100%;height:var(--banner-h,160px);object-fit:cover;display:block}
.banner-carousel-dots{position:absolute;bottom:10px;left:0;right:0;display:flex;justify-content:center;gap:6px}
.bc-dot{width:7px;height:7px;border-radius:50%;background:rgba(255,255,255,.45);cursor:pointer;transition:.2s}
.bc-dot.active{background:#fff;width:18px;border-radius:4px}
.soon-card{opacity:.85}
.balance-pill{display:flex;align-items:center;gap:8px;background:linear-gradient(135deg,#1c2840,#2e161d);border:1px solid #2a3350;border-radius:30px;padding:10px 16px;margin-bottom:16px;font-size:14px;color:var(--muted)}
.balance-pill strong{color:var(--accent2)}
.buy-modal label{display:block;font-size:13px;color:var(--muted);margin-bottom:10px}
.buy-extra{background:#101a2e;border:1px solid #2a3350;border-radius:10px;margin-bottom:10px;overflow:hidden}
.buy-extra summary{cursor:pointer;padding:10px 12px;font-size:13px;color:var(--muted);display:flex;align-items:center;gap:6px;list-style:none}
.buy-extra summary::-webkit-details-marker{display:none}
.buy-extra[open] summary{border-bottom:1px solid #2a3350}
.buy-extra label,.buy-extra .topup-method{margin:10px 12px}
.buy-extra .topup-method:last-of-type{margin-bottom:6px}
.buy-extra a{margin:0 12px 10px}
.upload-box{position:relative;border:2px dashed #2a3350;border-radius:var(--radius,12px);background:#101a2e;padding:18px 14px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;display:flex;flex-direction:column;align-items:center;gap:6px;margin-top:6px}
.upload-box:hover,.upload-box.dragover{border-color:var(--accent2);background:#141d33}
.upload-box input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer}
.upload-box .ic{color:var(--accent2)}
.upload-box-text{font-size:13px;color:var(--muted)}
.upload-box-text strong{color:var(--text)}
.upload-box-hint{font-size:11px;color:var(--muted);opacity:.7}
.upload-box.has-file{border-color:var(--accent2);border-style:solid}
.upload-box-preview{display:flex;align-items:center;gap:10px;width:100%}
.upload-box-preview img{width:56px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0}
.upload-box-preview-info{flex:1;text-align:right;overflow:hidden}
.upload-box-preview-info span{display:block;font-size:12px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.upload-box-remove{background:#1c2840;border:none;color:var(--muted);border-radius:8px;padding:6px;cursor:pointer;display:flex;flex-shrink:0;position:relative;z-index:2}
.upload-box-remove:hover{color:var(--danger);background:#3b1d22}
.btn-copy{background:#1c2840;border:none;color:var(--muted);border-radius:8px;padding:6px;cursor:pointer;display:flex;transition:color .2s,background .2s}
.btn-copy:hover{color:#fff;background:#3a1c29}
.btn-copy.copied{color:var(--accent2);background:#1d3b2e}

.login-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px;background:radial-gradient(1100px 600px at 50% -10%,rgba(230,41,75,.16),transparent 60%),var(--bg)}
.login-wrap{width:100%;max-width:440px}
.login-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
.login-back{display:flex;align-items:center;gap:8px;background:#18223a;border:1px solid #28304a;border-radius:30px;padding:9px 16px;font-size:13px;font-weight:600;color:var(--text);transition:.2s var(--ease)}
.login-back:hover{border-color:var(--accent)}
.login-logo{display:flex;align-items:center;gap:8px;font-weight:800;font-size:18px}
.login-logo img{width:34px;height:34px;border-radius:50%;object-fit:cover}
.login-card{background:linear-gradient(180deg,var(--card),#141d33);border:1px solid #2a3350;border-radius:24px;padding:28px 24px;box-shadow:0 24px 60px rgba(0,0,0,.5)}
.login-card h2{font-size:24px;margin-bottom:6px;color:var(--accent2)}
.login-card .sub{color:var(--muted);font-size:13px;margin-bottom:20px}
.login-tabs{display:flex;gap:10px;margin-bottom:20px}
.login-tabs button{flex:1;padding:11px;border-radius:12px;border:1px solid #2a3350;background:transparent;color:var(--muted);font-weight:700;font-size:14px;cursor:pointer;transition:.2s var(--ease)}
.login-tabs button.active{background:#fff;color:#16213b;border-color:#fff}
.login-card label{display:block;font-size:13px;color:var(--text);font-weight:600;margin-bottom:8px}
.login-card input{width:100%;padding:13px 14px;border-radius:12px;border:1px solid #2a3350;background:#101a2e;color:var(--text);margin-bottom:16px;font-size:14px}
.login-remember{display:flex;align-items:center;justify-content:flex-end;gap:8px;font-size:13px;color:var(--muted);margin-bottom:18px}
.login-submit{width:100%;padding:14px;border-radius:12px;border:none;background:#fff;color:#16213b;font-weight:800;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:.2s var(--ease)}
.login-submit:hover{transform:translateY(-2px)}
.login-forgot{display:block;text-align:center;color:var(--muted);font-size:13px;margin-top:16px}
.login-divider{display:flex;align-items:center;gap:10px;margin:22px 0;color:var(--muted);font-size:13px}
.login-divider::before,.login-divider::after{content:'';flex:1;height:1px;background:#2a3350}
.social-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.social-btn{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:18px 6px;border-radius:14px;border:1px solid #2a3350;background:#141d33;color:var(--text);font-size:18px;transition:.2s var(--ease)}
.social-btn:not(.soon):hover{border-color:var(--accent2);transform:translateY(-2px)}
.social-btn.soon{opacity:.55;cursor:not-allowed;pointer-events:none}
.social-btn .soon-tag{font-size:10px;color:var(--muted);font-weight:600}

.tx-list{display:flex;flex-direction:column;gap:2px}
.tx-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #28304a}
.tx-row:last-child{border-bottom:none}
.tx-icon{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;flex-shrink:0}
.tx-icon.pos{background:#1d3b2e;color:var(--accent2)}
.tx-icon.neg{background:#243152;color:var(--danger)}
.tx-info{display:flex;flex-direction:column;flex:1;min-width:0}
.tx-info strong{font-size:13px}
.tx-info span{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tx-amount{font-weight:800;font-size:14px}
.tx-amount.pos{color:var(--accent2)}
.tx-amount.neg{color:var(--danger)}

.breadcrumb a{color:var(--accent2)}
.product-detail{display:flex;flex-direction:column;gap:16px;margin:0 18px 24px;background:var(--card);border-radius:var(--radius);padding:18px;max-width:calc(100% - 36px)}
@media(min-width:640px){.product-detail{flex-direction:row;align-items:flex-start}}
.pd-img{width:100%;max-width:320px;border-radius:14px;object-fit:cover;color:var(--accent2);background:#101a2e;min-height:200px}
.pd-info{flex:1}
.pd-info h1{font-size:22px;margin-bottom:8px}
.pd-price{margin-bottom:12px}
.pd-desc{color:var(--muted);line-height:1.8;margin-bottom:16px}
.apps-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}
.app-card{display:flex;flex-direction:column}
.app-card .pimg.app-icon-img{width:64px;height:64px;border-radius:16px;margin:0 auto 8px;object-fit:cover}
.app-kind-tag{background:linear-gradient(135deg,var(--accent),var(--accent2))}
.app-stats{display:flex;gap:10px;flex-wrap:wrap;color:var(--muted);font-size:12px;margin:6px 0}
.app-stats span{display:flex;align-items:center;gap:4px}
.app-detail{margin:0 18px 24px;background:var(--card);border-radius:var(--radius);padding:18px}
.app-detail-head{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap}
.app-detail-icon{width:88px;height:88px;border-radius:20px;object-fit:cover;flex-shrink:0}
.app-detail-info{flex:1;min-width:200px}
.app-detail-info h1{font-size:22px;margin-bottom:6px}
.app-detail-meta{display:flex;gap:14px;color:var(--muted);font-size:13px;margin-bottom:8px;flex-wrap:wrap}
.app-detail-stats{margin:8px 0 14px}
.app-download-btn{display:inline-flex;width:100%;justify-content:center;margin-top:6px}
.app-screens{display:flex;gap:10px;overflow-x:auto;margin:18px 0;scrollbar-width:none}
.app-screens img{height:280px;border-radius:14px;flex-shrink:0}
.app-short-desc{color:var(--muted);margin:14px 0;line-height:1.7}
.app-desc{color:var(--text);line-height:1.8;margin-bottom:10px;white-space:pre-line}
.app-permissions{margin:0 0 10px 0;padding-right:20px;color:var(--muted);line-height:1.8}
.app-info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:10px}
.app-info-grid div{background:#18223a;border-radius:10px;padding:10px 12px;display:flex;flex-direction:column;gap:4px}
.app-info-grid span{color:var(--muted);font-size:12px}
.app-info-grid strong{font-size:13px;word-break:break-all}
.app-stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:18px 0}
.app-stat-box{background:#18223a;border:1px solid #232f4d;border-radius:14px;padding:12px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:4px}
.app-stat-box .ic-sm{color:var(--accent)}
.app-stat-box span{color:var(--muted);font-size:12px}
.app-stat-box strong{font-size:14px}
.app-popularity-bar{position:relative;background:#18223a;border-radius:30px;height:34px;overflow:hidden;margin-bottom:14px}
.app-popularity-fill{position:absolute;inset:0 auto 0 0;background:linear-gradient(90deg,#16a34a,#22c55e);border-radius:30px}
.app-popularity-bar span{position:relative;z-index:1;display:flex;height:100%;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff}
.app-vote-row{display:flex;gap:10px;margin-bottom:14px}
.app-vote-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border-radius:14px;border:1px solid #232f4d;background:#18223a;color:var(--text);font-weight:700;cursor:pointer}
.app-vote-btn.down{color:#f87171}
.app-vote-btn.up{color:#4ade80}
.app-vote-btn:disabled{opacity:.6;cursor:default}
.app-action-buttons{display:flex;flex-direction:column;gap:10px;margin-bottom:10px}
.app-action-buttons .btn{width:100%;justify-content:center}
.app-btn-download{background:linear-gradient(135deg,#06b6d4,#0ea5e9);color:#fff}
.app-btn-telegram{background:#2563eb;color:#fff}
.app-btn-notify{background:#16a34a;color:#fff}
.download-page{display:flex;justify-content:center;padding:30px 18px 50px}
.download-card{width:100%;max-width:440px;background:var(--card);border-radius:var(--radius);padding:30px 24px;text-align:center;box-shadow:var(--shadow);animation:fadeUp .55s var(--ease) both}
.download-app-icon{transition:transform .3s var(--ease)}
.download-card:hover .download-app-icon{transform:scale(1.05) rotate(-2deg)}
.download-app-icon{width:90px;height:90px;border-radius:22px;object-fit:cover;margin:0 auto 14px}
.download-card h1{font-size:20px;margin-bottom:6px}
.download-sub{color:var(--muted);font-size:13px;margin-bottom:18px}
.download-ad-slot{margin:14px auto;min-height:90px;max-width:100%;overflow:hidden;border-radius:10px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.03);contain:layout}
.download-ad-slot img,.download-ad-slot iframe,.download-ad-slot ins,.download-ad-slot video,.download-ad-slot embed,.download-ad-slot object{max-width:100%!important;height:auto}
.download-ad-slot iframe{width:100%}
.download-countdown{margin:18px 0}
.dl-progress{height:8px;border-radius:8px;background:#18223a;overflow:hidden;margin-bottom:10px}
.dl-progress-fill{height:100%;width:0;background:linear-gradient(90deg,var(--accent),var(--accent2));transition:width 1s linear}
.download-meta{display:flex;justify-content:center;gap:14px;flex-wrap:wrap;color:var(--muted);font-size:12px;margin:16px 0}
.download-meta span{display:flex;align-items:center;gap:4px}
.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:13px;margin-top:10px}
.dl-fallback{margin-top:18px;padding:14px;border-radius:14px;background:rgba(255,194,51,.08);border:1px solid rgba(255,194,51,.35);animation:fadeUp .5s var(--ease) both,pulseBorder 2.4s ease-in-out infinite}
.dl-fallback p{font-size:13px;color:var(--text);margin-bottom:10px}
@keyframes pulseBorder{0%,100%{border-color:rgba(255,194,51,.35)}50%{border-color:rgba(255,194,51,.8)}}

/* ===== Global polish, animations & micro-interactions ===== */
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.reveal{opacity:0;transform:translateY(26px);transition:opacity .6s var(--ease),transform .6s var(--ease)}
.reveal.in{opacity:1;transform:none}
.grid .card{animation:fadeUp .5s var(--ease) both}
.grid .card:nth-child(2){animation-delay:.05s}
.grid .card:nth-child(3){animation-delay:.1s}
.grid .card:nth-child(4){animation-delay:.15s}
.grid .card:nth-child(5){animation-delay:.2s}
.grid .card:nth-child(6){animation-delay:.25s}

.user-chip{transition:transform .2s var(--ease),background .2s}
.user-chip:hover{transform:translateY(-1px);background:#28324e}

.admin-box{background:linear-gradient(180deg,var(--card),#1c2a48);margin:0 18px 20px;border-radius:var(--radius);padding:20px;overflow-x:auto;border:1px solid #28304a;box-shadow:var(--shadow);animation:fadeUp .5s var(--ease) both}
.admin-box h2,.admin-box h3{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.admin-box h3 .ic,.admin-box h2 .ic{color:var(--accent2)}
.admin-box input,.admin-box textarea,.admin-box select{width:100%;padding:11px;border-radius:11px;border:1px solid #2a3350;background:#101a2e;color:var(--text);font-family:inherit;font-size:14px;transition:border-color .2s,box-shadow .2s}
.admin-box input:focus,.admin-box textarea:focus,.admin-box select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(230,41,75,.2)}
.admin-box label{display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--muted)}

.admin-tabs a{transition:transform .18s var(--ease),background .2s,box-shadow .2s}
.admin-tabs a:hover{transform:translateY(-2px);background:#28324e}
.admin-tabs a.active{background:linear-gradient(135deg,var(--accent),#38bdf8);box-shadow:0 6px 16px rgba(230,41,75,.4);color:#fff}

.stat-card{transition:transform .22s var(--ease),box-shadow .22s;border:1px solid #28304a}
.stat-card:hover{transform:translateY(-4px);box-shadow:0 12px 28px rgba(0,0,0,.4);border-color:var(--accent)}

.flash{animation:fadeUp .4s var(--ease) both;display:flex;align-items:center;gap:8px;box-shadow:var(--shadow)}
.badge{font-weight:700;text-transform:capitalize}

input,textarea,select{font-family:inherit}

/* scroll to top */
#scrollTop{position:fixed;bottom:92px;right:18px;width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,var(--accent),#38bdf8);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:45;opacity:0;visibility:hidden;transform:translateY(16px) scale(.8);transition:.3s var(--ease);box-shadow:0 8px 22px rgba(230,41,75,.45)}
#scrollTop.show{opacity:1;visibility:visible;transform:none}
#scrollTop:hover{transform:translateY(-2px) scale(1.05)}

/* skeleton shimmer */
.skeleton{background:linear-gradient(90deg,#18223a 25%,#28304a 37%,#18223a 63%);background-size:400% 100%;animation:shimmer 1.4s infinite}
@keyframes shimmer{from{background-position:100% 0}to{background-position:-100% 0}}

@media (prefers-reduced-motion:reduce){*{animation-duration:.001ms!important;transition-duration:.001ms!important}}
@media (max-width:640px){
  .admin-box{margin:0 8px 14px;padding:14px;border-radius:14px}
  .admin-tabs{padding:10px 8px;gap:6px}
  .admin-tabs a{padding:7px 10px;font-size:12px}
  .formrow{grid-template-columns:1fr 1fr;gap:8px}
  .formrow input,.formrow select,.formrow textarea{font-size:13px}
  table{font-size:11.5px}
  table th,table td{padding:6px 4px;white-space:nowrap}
  .stat-card{padding:12px}
  .stat-card .num{font-size:18px}
  .section-title{padding:14px 10px 6px;font-size:16px}
  .btn{padding:9px 14px;font-size:13px}
  .user-chip span{display:none}
  .balance-pill-sm{font-size:12px;padding:5px 9px}
}
@media (max-width:380px){.formrow{grid-template-columns:1fr}}
.lb-list{display:flex;flex-direction:column;gap:8px;margin:0 18px 20px}
.lb-row{display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid #28304a;border-radius:14px;padding:12px 14px;animation:fadeUp .5s var(--ease) both}
.lb-row.lb-rank-1{background:linear-gradient(90deg,rgba(255,194,51,.18),var(--card));border-color:#ffc233}
.lb-row.lb-rank-2{background:linear-gradient(90deg,rgba(192,192,192,.14),var(--card));border-color:#bdbdbd}
.lb-row.lb-rank-3{background:linear-gradient(90deg,rgba(205,127,50,.14),var(--card));border-color:#cd7f32}
.lb-pos{font-size:20px;width:34px;text-align:center;font-weight:800}
.lb-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;flex-shrink:0}
.lb-avatar-ph{display:flex;align-items:center;justify-content:center;background:#28304a}
.lb-name{flex:1;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lb-points{display:flex;align-items:center;gap:5px;color:var(--accent2);font-weight:800;flex-shrink:0}
.coin-pkgs{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px}
.coin-pkg{display:flex;flex-direction:column;align-items:center;gap:3px;background:#101a2e;border:1px solid #28304a;border-radius:12px;padding:10px 4px;cursor:pointer;color:var(--text);transition:.2s var(--ease)}
.coin-pkg:hover{border-color:var(--accent);transform:translateY(-2px)}
.coin-pkg strong{font-size:14px}
.coin-pkg span{font-size:10px;color:var(--muted)}
@media (max-width:480px){.coin-pkgs{grid-template-columns:repeat(2,1fr)}}
.btn-icon-only{padding:9px;aspect-ratio:1/1}
.btn-withdraw-cta{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;margin-top:10px;padding:16px;font-size:16px;font-weight:800;color:#06251c;background:linear-gradient(135deg,#4dd6a3,#2ecc8f);border-radius:14px;box-shadow:0 8px 22px rgba(46,204,143,.4);letter-spacing:.3px}
.btn-withdraw-cta:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(46,204,143,.55)}
.btn-withdraw-cta:disabled{opacity:.5;cursor:not-allowed;transform:none}
.verified-badge{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#1d9bf0;color:#fff;vertical-align:middle}
.verified-badge svg{width:11px;height:11px}
.profile-frame{position:relative;width:96px;height:96px;margin:0 auto;border-radius:50%;display:flex;align-items:center;justify-content:center}
.profile-frame img,.profile-frame .ph{width:84px;height:84px;border-radius:50%;object-fit:cover;background:#28304a;display:flex;align-items:center;justify-content:center}
.profile-frame::before{content:'';position:absolute;inset:0;border-radius:50%;border:3px solid var(--accent);animation:frameSpin 6s linear infinite}
.profile-rank-bronze .profile-frame::before{border-color:#c97a3d}
.profile-rank-silver .profile-frame::before{border-color:#c9d2da;box-shadow:0 0 12px rgba(201,210,218,.5)}
.profile-rank-gold .profile-frame::before{border-color:#e6b800;box-shadow:0 0 14px rgba(230,184,0,.6)}
.profile-rank-diamond .profile-frame::before{border-color:#36e0e0;box-shadow:0 0 16px rgba(54,224,224,.7)}
.avatar-edit-btn{position:absolute;bottom:-2px;left:50%;transform:translateX(-50%);width:28px;height:28px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg);cursor:pointer;z-index:2}
.avatar-edit-btn svg{width:13px;height:13px}
@keyframes frameSpin{from{transform:rotate(0deg) scale(1)}50%{transform:rotate(180deg) scale(1.04)}to{transform:rotate(360deg) scale(1)}}
.spin-wheel-wrap{display:flex;flex-direction:column;align-items:center;gap:20px;padding:20px 0}
.spin-wheel{width:240px;height:240px;border-radius:50%;position:relative;background:conic-gradient(#2563eb 0deg 45deg,#ff8a3d 45deg 90deg,#ffd23d 90deg 135deg,#4dd6a3 135deg 180deg,#3da5ff 180deg 225deg,#a36dff 225deg 270deg,#38bdf8 270deg 315deg,#6dffb0 315deg 360deg);transition:transform 2.4s cubic-bezier(.18,.9,.2,1);box-shadow:0 0 0 6px #101a2e,0 0 30px rgba(0,0,0,.5)}
.spin-wheel-pointer{position:absolute;top:-14px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:14px solid transparent;border-right:14px solid transparent;border-top:22px solid var(--accent);z-index:2}
.card img.pimg{height:<?= (int)setting('product_image_height', 130) ?>px}
.cat-tiles{grid-template-columns:repeat(auto-fill,minmax(<?= (int)setting('cat_tile_size', 140) ?>px,1fr))}
.banner-carousel-slide img{height:<?= (int)setting('banner_height', 160) ?>px}
<?php
$themeAccent = setting('theme_accent_color', '#2563eb');
$themeAccent2 = setting('theme_accent2_color', '#06b6d4');
if (preg_match('/^#[0-9a-fA-F]{3,6}$/', $themeAccent) && preg_match('/^#[0-9a-fA-F]{3,6}$/', $themeAccent2)):
?>
:root{--accent:<?= e($themeAccent) ?>;--accent2:<?= e($themeAccent2) ?>}
<?php endif; ?>
</style>
<?php if (setting('auto_translate_enabled', '1') === '1' && $page !== 'admin'): ?>
<style>.goog-te-banner-frame,.skiptranslate>iframe{display:none!important}body{top:0!important}#google_translate_element{display:none}</style>
<script>
(function(){
  try {
    if (document.cookie.indexOf('googtrans=') === -1) {
      var lang = ((navigator.language || navigator.userLanguage || 'ar').split('-')[0] || 'ar').toLowerCase();
      if (lang && lang !== 'ar') document.cookie = 'googtrans=/ar/' + lang + ';path=/';
    }
  } catch (e) {}
})();
function googleTranslateElementInit(){
  new google.translate.TranslateElement({ pageLanguage: 'ar', autoDisplay: false }, 'google_translate_element');
}
</script>
<?php endif; ?>
</head>
<body>
<?php if (setting('auto_translate_enabled', '1') === '1' && $page !== 'admin'): ?>
<div id="google_translate_element"></div>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>
<?php endif; ?>
<div id="preloader">
  <div class="pl-ring">
    <svg viewBox="0 0 100 100"><circle class="pl-track" cx="50" cy="50" r="48"></circle><circle class="pl-bar" id="plBar" cx="50" cy="50" r="48"></circle></svg>
    <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e($siteName) ?>"><?php else: ?><div class="pl-fallback"><?= icon('rocket', 'ic ic-lg') ?></div><?php endif; ?>
    <div class="pl-pct" id="plPct">0%</div>
  </div>
  <div class="pl-text">لحظات ونبدأ...</div>
</div>

<?php if ($page === 'login' && !$user): ?>
<div class="login-page">
  <div class="login-wrap">
    <div class="login-top">
      <a href="?" class="login-back"><?= icon('chevron-right', 'ic-sm') ?>العودة للرئيسية</a>
      <div class="login-logo"><?php if ($logo): ?><img src="<?= e($logo) ?>"><?php else: ?><?= icon('hat', 'ic') ?><?php endif; ?> <?= e($siteName) ?></div>
    </div>
    <div class="login-card">
      <div class="login-tabs">
        <button type="button" id="loginTabBtn" class="active" onclick="switchAuthTab('login')">تسجيل الدخول</button>
        <button type="button" id="registerTabBtn" onclick="switchAuthTab('register')">حساب جديد</button>
      </div>

      <form id="loginForm" method="post" action="?action=login">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <h2>تسجيل الدخول</h2>
        <div class="sub">أدخل بياناتك للوصول إلى حسابك</div>
        <label>البريد الإلكتروني أو اسم المستخدم</label>
        <input type="text" name="identity" placeholder="البريد، اسم المستخدم" required>
        <label>كلمة المرور</label>
        <input type="password" name="password" placeholder="أدخل كلمة المرور" required>
        <div class="login-remember"><label style="margin:0">تذكرني</label><input type="checkbox" style="width:auto;margin:0" name="remember"></div>
        <?= turnstile_widget() ?>
        <button type="submit" class="login-submit"><?= icon('logout', 'ic-sm') ?>تسجيل الدخول</button>
      </form>

      <form id="registerForm" method="post" action="?action=register" style="display:none">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <h2>حساب جديد</h2>
        <div class="sub">أنشئ حساباً جديداً مجاناً وابدأ الآن</div>
        <label>اسم المستخدم</label>
        <input type="text" name="username" placeholder="إنجليزي بدون مسافات" required>
        <label>البريد الإلكتروني</label>
        <input type="email" name="email" placeholder="البريد الإلكتروني" required>
        <label>كلمة المرور</label>
        <input type="password" name="password" placeholder="6 أحرف فأكثر" required>
        <?= turnstile_widget() ?>
        <button type="submit" class="login-submit"><?= icon('gift', 'ic-sm') ?>إنشاء حساب</button>
      </form>
      <?php if (setting('turnstile_site_key')): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>

      <a class="login-forgot" href="?page=contact">نسيت كلمة المرور؟ تواصل معنا</a>

      <div class="login-divider">أو</div>
      <div class="social-grid">
        <?php if (GOOGLE_CLIENT_ID): ?>
          <a href="<?= e(google_login_url()) ?>" class="social-btn"><?= icon('google', 'ic') ?></a>
        <?php else: ?>
          <div class="social-btn soon"><?= icon('google', 'ic') ?><span class="soon-tag">قريباً</span></div>
        <?php endif; ?>
        <?php if (setting('telegram_bot_username')): ?>
          <div class="social-btn" id="tgLoginBtn" style="cursor:pointer" onclick="document.getElementById('tgWidgetReal').querySelector('iframe')?.click()"><?= icon('send', 'ic') ?>
            <div id="tgWidgetReal" style="position:absolute;inset:0;opacity:0;overflow:hidden"></div>
          </div>
        <?php else: ?>
          <div class="social-btn soon"><?= icon('send', 'ic') ?><span class="soon-tag">قريباً</span></div>
        <?php endif; ?>
        <div class="social-btn soon"><?= icon('user', 'ic') ?><span class="soon-tag">قريباً</span></div>
      </div>
      <?php if (setting('telegram_bot_username')): ?>
      <script>
      window.onTelegramAuth = function(user){
        const f = document.createElement('form');
        f.method = 'POST'; f.action = '?action=telegram_login';
        Object.keys(user).forEach(k => {
          const i = document.createElement('input');
          i.type = 'hidden'; i.name = k; i.value = user[k];
          f.appendChild(i);
        });
        document.body.appendChild(f);
        f.submit();
      };
      (function(){
        const s = document.createElement('script');
        s.async = true;
        s.src = 'https://telegram.org/js/telegram-widget.js?22';
        s.setAttribute('data-telegram-login', '<?= e(setting('telegram_bot_username')) ?>');
        s.setAttribute('data-size', 'large');
        s.setAttribute('data-onauth', 'onTelegramAuth(user)');
        s.setAttribute('data-request-access', 'write');
        document.getElementById('tgWidgetReal').appendChild(s);
      })();
      </script>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php else: ?>

<div class="topbar">
  <button class="burger" onclick="toggleSidebar()"><?= icon('menu', 'ic') ?></button>
  <a href="?" class="brand"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e($siteName) ?>"><?php else: ?><?= icon('rocket', 'ic ic-lg') ?><?php endif; ?> <?= e($siteName) ?></a>
  <div class="grow"></div>
  <?php if ($user): ?>
    <a href="?page=favorites" class="btn btn-ghost btn-icon-only" title="المفضّلة"><?= icon('heart', 'ic ic-sm') ?></a>
    <a href="?page=profile" class="user-chip">
      <?php if ($user['avatar']): ?><img src="<?= e($user['avatar']) ?>"><?php else: ?><?= icon('user', 'ic ic-sm') ?><?php endif; ?>
      <span><?= e($user['name']) ?></span>
    </a>
    <a href="?action=logout" class="btn btn-ghost btn-icon-only" title="تسجيل الخروج"><?= icon('logout', 'ic ic-sm') ?></a>
  <?php else: ?>
    <a href="?page=login" class="btn btn-primary"><?= icon('user', 'ic ic-sm') ?>تسجيل الدخول</a>
  <?php endif; ?>
</div>

<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>
<div class="sidebar" id="sidebar">
  <div class="sb-head"><strong><?= icon('hat', 'ic-sm') ?> <?= e($siteName) ?></strong><button class="burger" onclick="toggleSidebar()"><?= icon('close', 'ic') ?></button></div>
  <nav>
    <a href="?"><?= icon('home') ?> الرئيسية</a>
    <a href="?page=apps&kind=app"><?= icon('android') ?> التطبيقات</a>
    <a href="?page=apps&kind=game"><?= icon('rocket') ?> الألعاب</a>
    <a href="?page=store"><?= icon('cart') ?> المتجر (منتجات للبيع)</a>
    <?php if ($user): ?><a href="?page=profile"><?= icon('user') ?> ملفي الشخصي</a><?php endif; ?>
    <a href="?page=favorites"><?= icon('heart') ?> المفضّلة</a>
    <a href="?page=orders"><?= icon('orders') ?> طلباتي</a>
    <a href="?page=suggest"><?= icon('megaphone') ?> اقترح منتجاً</a>
    <a href="?page=about"><?= icon('shield') ?> من نحن</a>
    <a href="?page=guide"><?= icon('doc') ?> دليل التحميل</a>
    <a href="?page=top"><?= icon('star') ?> أفضل التطبيقات</a>
    <a href="?page=faq"><?= icon('doc') ?> الأسئلة الشائعة</a>
    <a href="?page=contact"><?= icon('send') ?> تواصل معنا</a>
    <a href="?page=privacy"><?= icon('lock') ?> سياسة الخصوصية</a>
    <a href="?page=terms"><?= icon('doc') ?> شروط الاستخدام</a>
    <?php if (is_admin()): ?><a href="?page=admin"><?= icon('hat') ?> لوحة الإدارة</a><?php endif; ?>
  </nav>
</div>

<div class="modal-bg" id="buyModal" style="display:none">
  <div class="modal buy-modal">
    <h2><?= icon('cart', 'ic') ?>تأكيد طلب الشراء</h2>
    <div class="balance-pill"><?= icon('cart', 'ic-sm') ?>السعر: <strong id="buyFinalPrice">0$</strong></div>

    <label>الآيدي <span style="color:var(--danger)">*</span>
      <input type="text" id="buyAccountId" placeholder="أدخل الآيدي / الحساب الخاص بالطلب" required>
    </label>

    <label>صورة الإيصال <span style="color:var(--danger)">*</span>
      <div class="upload-box" id="buyReceiptBox">
        <div class="upload-box-empty">
          <?= icon('upload', 'ic') ?>
          <div class="upload-box-text"><strong>اضغط لاختيار صورة</strong> أو اسحبها هنا</div>
          <div class="upload-box-hint">JPG, PNG, WEBP — حتى 5MB</div>
        </div>
        <div class="upload-box-preview" style="display:none">
          <img id="buyReceiptPreview">
          <div class="upload-box-preview-info"><span id="buyReceiptName"></span></div>
          <button type="button" class="upload-box-remove" onclick="clearReceiptFile(event)"><?= icon('x', 'ic-sm') ?></button>
        </div>
        <input type="file" id="buyReceiptFile" accept="image/*" required>
      </div>
    </label>

    <?php if ($activeWallets): ?>
    <details class="buy-extra">
      <summary><?= icon('bank', 'ic-sm') ?>طرق الشحن المتاحة</summary>
      <div class="topup-hint">
        <?php foreach ($activeWallets as $w): ?>
          <div class="topup-method">
            <div class="topup-method-head">
              <?php [$wTypeLbl, $wTypeIcon] = wallet_type_label($w['type']); ?>
              <strong><?= icon($wTypeIcon, 'ic-sm') ?><?= e($w['label']) ?> (<?= e($wTypeLbl) ?>)</strong>
            </div>
            <div class="topup-method-addr">
              <code><?= e($w['address']) ?></code>
              <button type="button" class="btn-copy" onclick="copyAddr(this)" data-addr="<?= e($w['address']) ?>"><?= icon('copy', 'ic-sm') ?></button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endif; ?>

    <details class="buy-extra">
      <summary><?= icon('coins', 'ic-sm') ?>رقم عملية / كود خصم (اختياري)</summary>
      <label>رقم العملية
        <input type="text" id="buyTxNote" placeholder="رقم العملية / المرجع إن وجد">
      </label>
      <label>كود الخصم
        <div class="upload-row">
          <input type="text" id="buyCouponCode" placeholder="أدخل كود الخصم إن وجد">
          <button type="button" class="btn btn-ghost" onclick="applyCoupon()">تطبيق</button>
        </div>
        <div id="buyCouponMsg" style="font-size:13px;margin-top:4px"></div>
      </label>
    </details>

    <div style="display:flex;gap:10px;margin-top:14px">
      <button type="button" class="btn btn-primary" style="flex:1" id="buySubmitBtn" onclick="submitBuyRequest()"><?= icon('check', 'ic-sm') ?>تأكيد الطلب</button>
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="closeBuyModal()">إلغاء</button>
    </div>
  </div>
</div>

<?php $f = flash(); if ($f): ?>
  <div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endif; ?>

<div class="container">
<?php
/* ======================================================================
   6) PAGE VIEWS
   ====================================================================== */
function category_icon_name(string $name): string
{
    $n = mb_strtolower($name);
    if (str_contains($n, 'لعب') || str_contains($n, 'game')) return 'rocket';
    if (str_contains($n, 'اشتراك') || str_contains($n, 'sub')) return 'star';
    if (str_contains($n, 'تطبيق') || str_contains($n, 'app')) return 'globe';
    return 'cart';
}
function render_product_card(array $p): void
{
    global $wishlistSet;
    $isFav = !empty($wishlistSet[$p['id']]);
    ?>
    <div class="card">
      <?php if ($p['tag']): ?><span class="tag"><?= e($p['tag']) ?></span><?php endif; ?>
      <button type="button" class="wish-btn<?= $isFav ? ' active' : '' ?>" onclick="toggleWishlist(<?= (int)$p['id'] ?>, this)"><?= icon('heart', 'ic-sm') ?></button>
      <a href="?page=product&id=<?= (int)$p['id'] ?>">
        <?php if ($p['image']): ?>
          <img class="pimg" decoding="async" src="<?= e($p['image']) ?>" alt="<?= e($p['name']) ?>">
        <?php elseif (!empty($p['icon'])): ?>
          <div class="icon-wrap emoji-icon"><?= e($p['icon']) ?></div>
        <?php else: ?>
          <div class="icon-wrap"><?= icon('cart', 'ic ic-xl') ?></div>
        <?php endif; ?>
        <h3><?= e($p['name']) ?></h3>
      </a>
      <?php if ($p['description']): ?><div class="desc"><?= e(mb_substr($p['description'], 0, 60)) ?></div><?php endif; ?>
      <div>
        <span class="price"><?= e($p['price']) ?>$</span>
        <?php if ($p['old_price']): ?><span class="old"><?= e($p['old_price']) ?>$</span><?php endif; ?>
      </div>
      <button class="btn btn-primary buy" onclick="buyProduct(<?= (int)$p['id'] ?>, <?= (float)$p['price'] ?>)"><?= icon('cart', 'ic ic-sm') ?><?= e(setting('buy_button_text', 'طلب شراء')) ?></button>
    </div>
    <?php
}
function render_app_card(array $a): void
{
    $kindLabel = $a['kind'] === 'game' ? 'لعبة' : 'تطبيق';
    ?>
    <div class="card app-card">
      <span class="tag app-kind-tag"><?= e($kindLabel) ?></span>
      <a href="?page=app&id=<?= (int)$a['id'] ?>">
        <?php if ($a['icon']): ?>
          <img class="pimg app-icon-img" decoding="async" src="<?= e($a['icon']) ?>" alt="<?= e($a['name']) ?>">
        <?php else: ?>
          <div class="icon-wrap"><?= icon($a['kind'] === 'game' ? 'rocket' : 'android', 'ic ic-xl') ?></div>
        <?php endif; ?>
        <h3><?= e($a['name']) ?></h3>
      </a>
      <?php if ($a['short_description']): ?><div class="desc"><?= e(mb_substr($a['short_description'], 0, 60)) ?></div><?php endif; ?>
      <div class="app-stats">
        <?php if ($a['rating_avg']): ?><span><?= icon('star', 'ic-sm') ?><?= e(number_format((float)$a['rating_avg'], 1)) ?></span><?php endif; ?>
        <span><?= icon('eye', 'ic-sm') ?><?= number_format((int)$a['views']) ?></span>
        <span><?= icon('download', 'ic-sm') ?><?= number_format((int)$a['downloads']) ?></span>
      </div>
      <a class="btn btn-primary buy" href="?page=app&id=<?= (int)$a['id'] ?>"><?= icon('download', 'ic ic-sm') ?>تحميل</a>
    </div>
    <?php
}
switch ($page) {

case 'login':
    redirect('?');
    break;

case 'home':
    $banners = db()->query("SELECT * FROM banners WHERE active=1 ORDER BY sort_order")->fetchAll();
    $tickers = db()->query("SELECT * FROM tickers WHERE active=1 ORDER BY sort_order, id")->fetchAll();
    $searchQ = trim($_GET['q'] ?? '');
    if ($searchQ !== '') {
        $st = db()->prepare("SELECT * FROM products WHERE status='active' AND name LIKE ? ORDER BY id DESC");
        $st->execute(['%' . $searchQ . '%']);
        $products = $st->fetchAll();
    } else {
        $products = db()->query("SELECT * FROM products WHERE status='active' ORDER BY id DESC")->fetchAll();
    }
    $categories = db()->query("SELECT * FROM categories ORDER BY sort_order, id")->fetchAll();
    $wishlistSet = [];
    if ($user) {
        $st = db()->prepare("SELECT product_id FROM wishlist WHERE user_id=?");
        $st->execute([$user['id']]);
        foreach ($st->fetchAll() as $w) $wishlistSet[$w['product_id']] = true;
    }
    $byCat = []; $uncategorized = [];
    foreach ($products as $p) {
        if ($p['category_id']) $byCat[$p['category_id']][] = $p;
        else $uncategorized[] = $p;
    }
    ?>
    <?php
    $tileCats = array_filter($categories, fn($c) => !empty($c['image']));
    $homeSections = [
        'hero' => function () {
            $bannerBg = setting('banner_bg_image'); ?>
            <div class="banner<?= $bannerBg ? ' has-bg' : '' ?>"<?= $bannerBg ? ' style="background-image:url(\'' . e($bannerBg) . '\')"' : '' ?>>
              <?php if ($bannerBg): ?><div class="banner-overlay"></div><?php endif; ?>
              <h1 style="position:relative;z-index:1"><?= e(setting('banner_title')) ?></h1>
              <p style="position:relative;z-index:1"><?= e(setting('banner_subtitle')) ?></p>
            </div>
            <?php
        },
        'search' => function () use ($searchQ) { ?>
            <form method="get" action="?" class="search-bar">
              <input type="hidden" name="page" value="home">
              <input type="text" name="q" value="<?= e($searchQ) ?>" placeholder="ابحث عن منتج...">
              <button type="submit"><?= icon('search', 'ic-sm') ?></button>
            </form>
            <?php
        },
        'cat_tiles' => function () use ($tileCats) {
            if (!$tileCats) return; ?>
            <div class="cat-tiles">
              <?php foreach ($tileCats as $c): ?>
                <a href="#cat-<?= (int)$c['id'] ?>" class="cat-tile" style="background:<?= e($c['color'] ?: '#18223a') ?>">
                  <img src="<?= e($c['image']) ?>" alt="<?= e($c['name']) ?>" decoding="async">
                  <span class="cat-tile-label"><?= e($c['name']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
            <?php
        },
        'cat_chips' => function () use ($categories) {
            if (!$categories) return; ?>
            <div class="cat-chips">
              <?php foreach ($categories as $c): ?>
                <a href="#cat-<?= (int)$c['id'] ?>" class="cat-chip"><?= icon(category_icon_name($c['name']), 'ic-sm') ?><?= e($c['name']) ?></a>
              <?php endforeach; ?>
            </div>
            <?php
        },
        'carousel' => function () use ($banners) {
            if (setting('banner_carousel_enabled', '1') !== '1') return;
            if (!$banners) return; ?>
            <div class="banner-carousel" id="bannerCarousel" data-interval="<?= (int)setting('banner_interval', 4000) ?>">
              <div class="banner-carousel-track">
                <?php foreach ($banners as $i => $b): ?>
                  <a class="banner-carousel-slide" href="<?= e($b['link'] ?: '#') ?>"><img src="<?= e($b['image']) ?>" fetchpriority="<?= $i === 0 ? 'high' : 'auto' ?>" decoding="async" alt=""></a>
                <?php endforeach; ?>
              </div>
              <?php if (count($banners) > 1): ?>
              <div class="banner-carousel-dots">
                <?php foreach ($banners as $i => $b): ?><span class="bc-dot<?= $i === 0 ? ' active' : '' ?>" onclick="bannerGoTo(<?= $i ?>)"></span><?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
            <?php
        },
        'ticker' => function () use ($tickers) {
            if (setting('news_ticker_enabled', '1') !== '1') return;
            if (!$tickers) return; ?>
            <div class="ticker-bar">
              <span class="ticker-badge"><?= icon('megaphone', 'ic-sm') ?>جديد</span>
              <div class="ticker-track">
                <div class="ticker-track-inner">
                  <?php foreach (array_merge($tickers, $tickers) as $t): ?>
                    <?php if ($t['link']): ?><a class="ticker-item" href="<?= e($t['link']) ?>"><?= icon('star', 'ic-sm') ?><?= e($t['text']) ?></a>
                    <?php else: ?><span class="ticker-item"><?= icon('star', 'ic-sm') ?><?= e($t['text']) ?></span><?php endif; ?>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php
        },
        'live_ticker' => function () {
            if (setting('live_ticker_enabled', '1') !== '1') return;
            $recent = db()->query("SELECT name, kind FROM apps WHERE status='published' ORDER BY id DESC LIMIT 15")->fetchAll();
            if (!$recent) return; ?>
            <div class="ticker-bar live-ticker">
              <span class="ticker-badge"><?= icon('rocket', 'ic-sm') ?>مباشر</span>
              <div class="ticker-track">
                <div class="ticker-track-inner">
                  <?php foreach (array_merge($recent, $recent) as $r): ?>
                    <span class="ticker-item"><?= icon($r['kind'] === 'game' ? 'rocket' : 'android', 'ic-sm') ?><?= e($r['name']) ?> <strong><?= $r['kind'] === 'game' ? 'لعبة جديدة' : 'تطبيق جديد' ?></strong></span>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <?php
        },
        'products' => function () use ($products, $categories, $byCat, $uncategorized) {
            if (!$products) { ?>
              <div class="empty"><?= icon('rocket', 'ic ic-lg') ?><br><?= e(setting('empty_products_text', 'لا توجد منتجات حالياً، تابعنا قريباً')) ?></div>
              <?php return;
            } ?>
            <?php foreach ($categories as $c): if (empty($byCat[$c['id']])) continue; ?>
              <div class="section-title" id="cat-<?= (int)$c['id'] ?>"><?= icon(category_icon_name($c['name']), 'ic') ?><?= e($c['name']) ?></div>
              <div class="grid">
                <?php foreach ($byCat[$c['id']] as $p) render_product_card($p); ?>
              </div>
            <?php endforeach; ?>
            <?php if ($uncategorized): ?>
              <div class="section-title"><?= icon('cart', 'ic') ?>أحدث المنتجات</div>
              <div class="grid">
                <?php foreach ($uncategorized as $p) render_product_card($p); ?>
              </div>
            <?php endif; ?>
            <?php
        },
        'latest_apps' => function () {
            $apps = db()->query("SELECT * FROM apps WHERE status='published' ORDER BY id DESC LIMIT 7")->fetchAll();
            if (!$apps) { ?>
              <div class="empty"><?= icon('android', 'ic ic-lg') ?><br>لا توجد تطبيقات أو ألعاب منشورة حالياً، تابعنا قريباً</div>
              <?php return;
            } ?>
            <div class="section-title"><?= icon('android', 'ic') ?>أحدث التطبيقات والألعاب</div>
            <div class="grid apps-grid">
              <?php foreach ($apps as $a): render_app_card($a); endforeach; ?>
            </div>
            <div style="text-align:center;margin:18px 0">
              <a href="?page=apps" class="btn btn-ghost"><?= icon('android', 'ic-sm') ?>عرض كل التطبيقات والألعاب</a>
            </div>
            <?php
        },
        'trending_apps' => function () {
            if (setting('trending_apps_enabled', '1') !== '1') return;
            $apps = db()->query("SELECT * FROM apps WHERE status='published' ORDER BY downloads DESC, views DESC LIMIT 7")->fetchAll();
            if (!$apps) return; ?>
            <div class="section-title"><?= icon('rocket', 'ic') ?>الأكثر تحميلاً</div>
            <div class="grid apps-grid">
              <?php foreach ($apps as $a): render_app_card($a); endforeach; ?>
            </div>
            <?php
        },
        'soon' => function () { ?>
            <div class="section-title"><?= icon('rocket', 'ic') ?>تطبيقات وألعاب — قريباً</div>
            <div class="grid">
              <?php foreach ([['تطبيقات معدّلة (مود)', 'globe'], ['ألعاب بقوائم غش', 'rocket'], ['اشتراكات مميزة', 'star']] as [$label, $ic]): ?>
                <div class="card soon-card">
                  <span class="tag" style="background:linear-gradient(135deg,#555,#333)">قريباً</span>
                  <div class="icon-wrap"><?= icon($ic, 'ic ic-xl') ?></div>
                  <h3><?= e($label) ?></h3>
                  <div class="desc">هذا القسم قيد التحضير وسيتوفر قريباً، تابعنا للحصول على آخر التحديثات.</div>
                  <button class="btn btn-ghost" disabled style="opacity:.6;cursor:not-allowed"><?= icon('clock', 'ic-sm') ?>قريباً</button>
                </div>
              <?php endforeach; ?>
            </div>
            <?php
        },
    ];
    $homeOrder = array_filter(explode(',', setting('home_sections_order', '')));
    $homeHidden = array_filter(explode(',', setting('home_sections_hidden', '')));
    foreach ($homeOrder as $sKey) {
        if (in_array($sKey, $homeHidden, true)) continue;
        if (isset($homeSections[$sKey])) $homeSections[$sKey]();
    }
    ?>
    <?php
    break;

case 'product':
    if (!$seoProduct) {
        echo '<div class="empty" style="margin-top:30px">' . icon('x', 'ic ic-lg') . '<br>المنتج غير موجود أو غير متاح.<br><a href="?" class="btn btn-primary" style="margin-top:14px;display:inline-block">عودة للرئيسية</a></div>';
        break;
    }
    $p = $seoProduct;
    ?>
    <div class="breadcrumb" style="padding:14px 18px;font-size:13px;color:var(--muted)">
      <a href="?">الرئيسية</a> / <span><?= e($p['name']) ?></span>
    </div>
    <div class="product-detail">
      <?php if ($p['image']): ?>
        <img class="pd-img" src="<?= e($p['image']) ?>" alt="<?= e($p['name']) ?>">
      <?php elseif (!empty($p['icon'])): ?>
        <div class="pd-img icon-wrap" style="display:flex;align-items:center;justify-content:center;font-size:64px"><?= e($p['icon']) ?></div>
      <?php else: ?>
        <div class="pd-img icon-wrap" style="display:flex;align-items:center;justify-content:center"><?= icon('cart', 'ic ic-xl') ?></div>
      <?php endif; ?>
      <div class="pd-info">
        <?php if ($p['tag']): ?><span class="tag" style="position:static;display:inline-block;margin-bottom:8px"><?= e($p['tag']) ?></span><?php endif; ?>
        <h1><?= e($p['name']) ?></h1>
        <div class="pd-price"><span class="price"><?= e($p['price']) ?>$</span><?php if ($p['old_price']): ?><span class="old"><?= e($p['old_price']) ?>$</span><?php endif; ?></div>
        <?php if ($p['description']): ?><p class="pd-desc"><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
        <button class="btn btn-primary buy" style="width:100%" onclick="buyProduct(<?= (int)$p['id'] ?>)"><?= icon('cart', 'ic-sm') ?>طلب شراء</button>
      </div>
    </div>
    <?php
    $pReviews = db()->prepare("SELECT r.*, u.name uname FROM reviews r JOIN users u ON u.id=r.user_id WHERE r.product_id=? ORDER BY r.id DESC");
    $pReviews->execute([(int)$p['id']]);
    $pReviews = $pReviews->fetchAll();
    $pAvgRating = $pReviews ? round(array_sum(array_column($pReviews, 'rating')) / count($pReviews), 1) : 0;
    ?>
    <div class="section-title"><?= icon('star', 'ic') ?>تقييمات المنتج<?php if ($pReviews): ?> (<?= $pAvgRating ?>/5 — <?= count($pReviews) ?> تقييم)<?php endif; ?></div>
    <?php if ($user): ?>
    <div class="admin-box" style="margin-bottom:14px">
      <form id="reviewForm" class="formrow" onsubmit="return submitReview(event, <?= (int)$p['id'] ?>)">
        <select name="rating" required>
          <option value="">التقييم</option>
          <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> ⭐</option><?php endfor; ?>
        </select>
        <input name="comment" placeholder="رأيك بالمنتج (اختياري)" maxlength="500">
        <button class="btn btn-primary"><?= icon('check', 'ic-sm') ?>إرسال التقييم</button>
      </form>
      <div id="reviewMsg" style="font-size:13px;margin-top:6px"></div>
    </div>
    <?php endif; ?>
    <?php if (!$pReviews): ?>
      <div class="empty"><?= icon('star', 'ic ic-lg') ?><br>لا توجد تقييمات بعد.</div>
    <?php else: foreach ($pReviews as $rv): ?>
      <div class="admin-box" style="margin-bottom:8px;padding:12px 14px">
        <div style="display:flex;justify-content:space-between;font-size:13px"><strong><?= e($rv['uname']) ?></strong><span><?= str_repeat('⭐', (int)$rv['rating']) ?></span></div>
        <?php if ($rv['comment']): ?><p style="margin:6px 0 0;font-size:13px;color:var(--muted)"><?= nl2br(e($rv['comment'])) ?></p><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
    <script>
    function submitReview(ev, productId) {
      ev.preventDefault();
      const d = new FormData(ev.target);
      d.append('product_id', productId);
      post('api_submit_review', d).then(res => {
        document.getElementById('reviewMsg').textContent = res.msg || '';
        if (res.ok) setTimeout(() => location.reload(), 900);
      });
      return false;
    }
    </script>
    <?php
    break;

case 'store':
    $storeQ = trim($_GET['q'] ?? '');
    if ($storeQ !== '') {
        $st = db()->prepare("SELECT * FROM products WHERE status='active' AND name LIKE ? ORDER BY id DESC");
        $st->execute(['%' . $storeQ . '%']);
        $storeProducts = $st->fetchAll();
    } else {
        $storeProducts = db()->query("SELECT * FROM products WHERE status='active' ORDER BY id DESC")->fetchAll();
    }
    $storeCategories = db()->query("SELECT * FROM categories ORDER BY sort_order, id")->fetchAll();
    $wishlistSet = [];
    if ($user) {
        $st = db()->prepare("SELECT product_id FROM wishlist WHERE user_id=?");
        $st->execute([$user['id']]);
        foreach ($st->fetchAll() as $w) $wishlistSet[$w['product_id']] = true;
    }
    $storeByCat = []; $storeUncategorized = [];
    foreach ($storeProducts as $p) {
        if ($p['category_id']) $storeByCat[$p['category_id']][] = $p;
        else $storeUncategorized[] = $p;
    }
    ?>
    <div class="section-title"><?= icon('cart', 'ic') ?>المتجر — منتجات للبيع</div>
    <form method="get" action="?" class="search-bar">
      <input type="hidden" name="page" value="store">
      <input type="text" name="q" value="<?= e($storeQ) ?>" placeholder="ابحث عن منتج...">
      <button type="submit"><?= icon('search', 'ic-sm') ?></button>
    </form>
    <?php if (!$storeProducts): ?>
      <div class="empty"><?= icon('rocket', 'ic ic-lg') ?><br><?= e(setting('empty_products_text', 'لا توجد منتجات حالياً، تابعنا قريباً')) ?></div>
    <?php else: ?>
      <?php foreach ($storeCategories as $c): if (empty($storeByCat[$c['id']])) continue; ?>
        <div class="section-title" id="cat-<?= (int)$c['id'] ?>"><?= icon(category_icon_name($c['name']), 'ic') ?><?= e($c['name']) ?></div>
        <div class="grid">
          <?php foreach ($storeByCat[$c['id']] as $p) render_product_card($p); ?>
        </div>
      <?php endforeach; ?>
      <?php if ($storeUncategorized): ?>
        <div class="section-title"><?= icon('cart', 'ic') ?>أحدث المنتجات</div>
        <div class="grid">
          <?php foreach ($storeUncategorized as $p) render_product_card($p); ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <?php
    break;

case 'apps':
    $appsQ = trim($_GET['q'] ?? '');
    $appsKind = $_GET['kind'] ?? '';
    $appsSort = $_GET['sort'] ?? 'newest';
    $sqlApps = "SELECT * FROM apps WHERE status='published'";
    $argsApps = [];
    if ($appsQ !== '') { $sqlApps .= " AND name LIKE ?"; $argsApps[] = '%' . $appsQ . '%'; }
    if (in_array($appsKind, ['app', 'game'], true)) { $sqlApps .= " AND kind=?"; $argsApps[] = $appsKind; }
    $sqlApps .= match ($appsSort) {
        'downloads' => " ORDER BY downloads DESC",
        'rating' => " ORDER BY rating_avg DESC",
        'name' => " ORDER BY name ASC",
        default => " ORDER BY id DESC",
    };
    $st = db()->prepare($sqlApps);
    $st->execute($argsApps);
    $appsList = $st->fetchAll();
    ?>
    <div class="section-title"><?= icon('android', 'ic') ?>تطبيقات وألعاب</div>
    <form method="get" action="?" class="search-bar">
      <input type="hidden" name="page" value="apps">
      <?php if ($appsKind !== ''): ?><input type="hidden" name="kind" value="<?= e($appsKind) ?>"><?php endif; ?>
      <?php if ($appsSort !== 'newest'): ?><input type="hidden" name="sort" value="<?= e($appsSort) ?>"><?php endif; ?>
      <input type="text" name="q" value="<?= e($appsQ) ?>" placeholder="ابحث عن تطبيق أو لعبة...">
      <button type="submit"><?= icon('search', 'ic-sm') ?></button>
    </form>
    <div class="cat-chips">
      <a href="?page=apps&sort=<?= e($appsSort) ?><?= $appsQ !== '' ? '&q=' . urlencode($appsQ) : '' ?>" class="cat-chip<?= $appsKind === '' ? ' active' : '' ?>"><?= icon('android', 'ic-sm') ?>الكل</a>
      <a href="?page=apps&kind=app&sort=<?= e($appsSort) ?><?= $appsQ !== '' ? '&q=' . urlencode($appsQ) : '' ?>" class="cat-chip<?= $appsKind === 'app' ? ' active' : '' ?>"><?= icon('android', 'ic-sm') ?>تطبيقات</a>
      <a href="?page=apps&kind=game&sort=<?= e($appsSort) ?><?= $appsQ !== '' ? '&q=' . urlencode($appsQ) : '' ?>" class="cat-chip<?= $appsKind === 'game' ? ' active' : '' ?>"><?= icon('rocket', 'ic-sm') ?>ألعاب</a>
    </div>
    <div class="cat-chips" style="margin-top:6px">
      <?php foreach (['newest' => 'الأحدث', 'downloads' => 'الأكثر تحميلاً', 'rating' => 'الأعلى تقييماً', 'name' => 'الاسم (أ-ي)'] as $sk => $sl): ?>
        <a href="?page=apps&sort=<?= $sk ?><?= $appsKind !== '' ? '&kind=' . e($appsKind) : '' ?><?= $appsQ !== '' ? '&q=' . urlencode($appsQ) : '' ?>" class="cat-chip<?= $appsSort === $sk ? ' active' : '' ?>"><?= icon('chart', 'ic-sm') ?><?= $sl ?></a>
      <?php endforeach; ?>
    </div>
    <?php if (!$appsList): ?>
      <div class="empty"><?= icon('android', 'ic ic-lg') ?><br>لا توجد نتائج.</div>
    <?php else: ?>
      <div class="grid apps-grid">
        <?php foreach ($appsList as $a) render_app_card($a); ?>
      </div>
    <?php endif; ?>
    <?php
    break;

case 'app':
    if (!$seoApp) {
        echo '<div class="empty" style="margin-top:30px">' . icon('x', 'ic ic-lg') . '<br>التطبيق غير موجود أو غير متاح.<br><a href="?page=apps" class="btn btn-primary" style="margin-top:14px;display:inline-block">عودة لقائمة التطبيقات</a></div>';
        break;
    }
    $a = $seoApp;
    db()->prepare("UPDATE apps SET views = views + 1 WHERE id=?")->execute([$a['id']]);
    $screenshots = array_filter(explode(',', (string)$a['screenshots']));
    $permissions = array_filter(explode(',', (string)$a['permissions']));
    $likes = (int)$a['likes_count']; $dislikes = (int)$a['dislikes_count'];
    $totalVotes = $likes + $dislikes;
    $popularity = $totalVotes > 0 ? round($likes / $totalVotes * 100) : 99;
    $hasVoted = isset($_SESSION['voted_apps'][$a['id']]);
    ?>
    <div class="breadcrumb" style="padding:14px 18px;font-size:13px;color:var(--muted)">
      <a href="?">الرئيسية</a> / <a href="?page=apps"><?= $a['kind'] === 'game' ? 'ألعاب' : 'تطبيقات' ?></a> / <span><?= e($a['name']) ?></span>
    </div>
    <div class="app-detail">
      <div class="app-detail-head">
        <?php if ($a['icon']): ?>
          <img class="app-detail-icon" src="<?= e($a['icon']) ?>" alt="<?= e($a['name']) ?>">
        <?php else: ?>
          <div class="app-detail-icon icon-wrap"><?= icon($a['kind'] === 'game' ? 'rocket' : 'android', 'ic ic-xl') ?></div>
        <?php endif; ?>
        <div class="app-detail-info">
          <h1><?= e($a['name']) ?></h1>
          <div class="app-detail-meta">
            <?php if ($a['developer_name']): ?><span><?= icon('users', 'ic-sm') ?><?= e($a['developer_name']) ?></span><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="app-stat-grid">
        <?php if ($a['version']): ?><div class="app-stat-box"><?= icon('rocket', 'ic-sm') ?><span>النسخة</span><strong><?= e($a['version']) ?></strong></div><?php endif; ?>
        <div class="app-stat-box"><?= icon('history', 'ic-sm') ?><span>تحديث</span><strong><?= e(date('d/m/Y', strtotime((string)$a['created_at']))) ?></strong></div>
        <?php if ($a['min_android']): ?><div class="app-stat-box"><?= icon('android', 'ic-sm') ?><span>المتطلبات</span><strong>أندرويد <?= e($a['min_android']) ?></strong></div><?php endif; ?>
        <?php if ($a['size_label']): ?><div class="app-stat-box"><?= icon('download', 'ic-sm') ?><span>الحجم</span><strong><?= e($a['size_label']) ?></strong></div><?php endif; ?>
        <?php if ($a['category']): ?><div class="app-stat-box"><?= icon('chart', 'ic-sm') ?><span>التصنيف</span><strong><?= e($a['category']) ?></strong></div><?php endif; ?>
        <div class="app-stat-box"><?= icon('eye', 'ic-sm') ?><span>المشاهدات</span><strong><?= number_format((int)$a['views'] + 1) ?></strong></div>
      </div>

      <div class="app-popularity-bar"><div class="app-popularity-fill" style="width:<?= $popularity ?>%"></div><span><?= $popularity ?>% الشعبية</span></div>

      <div class="app-vote-row">
        <button type="button" class="app-vote-btn down" id="appVoteDown" onclick="appVote(<?= (int)$a['id'] ?>,'down')" <?= $hasVoted ? 'disabled' : '' ?>><?= icon('thumb-down', 'ic-sm') ?><span id="appDislikeCount"><?= number_format($dislikes) ?></span></button>
        <button type="button" class="app-vote-btn up" id="appVoteUp" onclick="appVote(<?= (int)$a['id'] ?>,'up')" <?= $hasVoted ? 'disabled' : '' ?>><?= icon('thumb-up', 'ic-sm') ?><span id="appLikeCount"><?= number_format($likes) ?></span></button>
      </div>

      <div class="app-detail-stats" style="margin-bottom:14px">
        <?php if ($a['rating_avg']): ?><span><?= icon('star', 'ic-sm') ?><?= e(number_format((float)$a['rating_avg'], 1)) ?></span><?php endif; ?>
        <span><?= icon('download', 'ic-sm') ?><?= number_format((int)$a['downloads']) ?> تحميل</span>
      </div>

      <div class="app-action-buttons">
        <a class="btn app-btn-download" href="?page=app_download&id=<?= (int)$a['id'] ?>"><?= icon('download', 'ic-sm') ?>تحميل الآن</a>
        <?php if (setting('telegram_channel_url')): ?>
          <a class="btn app-btn-telegram" href="<?= e(setting('telegram_channel_url')) ?>" target="_blank" rel="nofollow noopener"><?= icon('telegram', 'ic-sm') ?>اشترك في قناة تيليجرام</a>
        <?php endif; ?>
        <?php if (setting('app_notify_enabled', '1') === '1'): ?>
          <button type="button" class="btn app-btn-notify" onclick="appNotifySubscribe(this)"><?= icon('bell', 'ic-sm') ?>اشترك في التحديثات</button>
        <?php endif; ?>
      </div>

      <?php if ($screenshots): ?>
      <div class="app-screens">
        <?php foreach ($screenshots as $s): ?><img src="<?= e($s) ?>" decoding="async" alt="لقطة شاشة"><?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($a['short_description']): ?><p class="app-short-desc"><?= e($a['short_description']) ?></p><?php endif; ?>
      <?php if ($a['description']): ?>
        <div class="section-title"><?= icon('check', 'ic') ?>الوصف</div>
        <p class="app-desc"><?= nl2br(e($a['description'])) ?></p>
      <?php endif; ?>
      <?php if ($a['changelog']): ?>
        <div class="section-title"><?= icon('history', 'ic') ?>سجل التحديثات</div>
        <p class="app-desc"><?= nl2br(e($a['changelog'])) ?></p>
      <?php endif; ?>
      <?php if ($permissions): ?>
        <div class="section-title"><?= icon('check', 'ic') ?>الصلاحيات المطلوبة</div>
        <ul class="app-permissions">
          <?php foreach ($permissions as $perm): ?><li><?= e(trim($perm)) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <div class="section-title"><?= icon('check', 'ic') ?>معلومات إضافية</div>
      <div class="app-info-grid">
        <?php if ($a['package_name']): ?><div><span>اسم الحزمة</span><strong><?= e($a['package_name']) ?></strong></div><?php endif; ?>
        <?php if ($a['min_android']): ?><div><span>أقل إصدار أندرويد</span><strong><?= e($a['min_android']) ?></strong></div><?php endif; ?>
        <?php if ($a['category']): ?><div><span>التصنيف</span><strong><?= e($a['category']) ?></strong></div><?php endif; ?>
        <?php if ($a['developer_website']): ?><div><span>موقع المطوّر</span><strong><a href="<?= e($a['developer_website']) ?>" target="_blank" rel="nofollow noopener"><?= e($a['developer_website']) ?></a></strong></div><?php endif; ?>
        <?php if ($a['privacy_policy_url']): ?><div><span>سياسة الخصوصية</span><strong><a href="<?= e($a['privacy_policy_url']) ?>" target="_blank" rel="nofollow noopener">عرض</a></strong></div><?php endif; ?>
      </div>

      <div class="app-share-row" style="display:flex;gap:10px;margin:16px 0">
        <button type="button" class="btn btn-ghost" onclick="shareAppLink(this)" data-url="<?= e(app_canonical_url($a)) ?>"><?= icon('send', 'ic-sm') ?>مشاركة</button>
      </div>

      <?php
      $relatedSt = db()->prepare("SELECT * FROM apps WHERE status='published' AND id<>? AND (category=? OR kind=?) ORDER BY downloads DESC LIMIT 6");
      $relatedSt->execute([$a['id'], $a['category'] ?: '__none__', $a['kind']]);
      $relatedApps = $relatedSt->fetchAll();
      if ($relatedApps):
      ?>
      <div class="section-title"><?= icon('rocket', 'ic') ?>تطبيقات مشابهة</div>
      <div class="grid apps-grid">
        <?php foreach ($relatedApps as $ra) render_app_card($ra); ?>
      </div>
      <?php endif; ?>
    </div>
    <script>
    function shareAppLink(btn) {
      var url = btn.getAttribute('data-url');
      if (navigator.share) { navigator.share({ url: url }).catch(function(){}); return; }
      if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function(){ btn.textContent = 'تم نسخ الرابط'; }); }
    }
    </script>
    <?php
    break;

case 'app_download':
    if (!$seoApp) {
        echo '<div class="empty" style="margin-top:30px">' . icon('x', 'ic ic-lg') . '<br>التطبيق غير موجود أو غير متاح.<br><a href="?page=apps" class="btn btn-primary" style="margin-top:14px;display:inline-block">عودة لقائمة التطبيقات</a></div>';
        break;
    }
    $a = $seoApp;
    db()->prepare("UPDATE apps SET downloads = downloads + 1 WHERE id=?")->execute([$a['id']]);
    if ($user) db()->prepare("INSERT INTO user_downloads (user_id, app_id) VALUES (?,?)")->execute([$user['id'], $a['id']]);
    $waitSeconds = max(0, (int)setting('app_download_wait_seconds', 5));
    $moneytagScript = setting('moneytag_script');
    ?>
    <div class="download-page">
      <div class="download-card">
        <?php if ($a['icon']): ?>
          <img class="download-app-icon" src="<?= e($a['icon']) ?>" alt="<?= e($a['name']) ?>">
        <?php else: ?>
          <div class="download-app-icon icon-wrap"><?= icon($a['kind'] === 'game' ? 'rocket' : 'android', 'ic ic-xl') ?></div>
        <?php endif; ?>
        <h1><?= e($a['name']) ?></h1>
        <p class="download-sub">يتم تحضير رابط التحميل الخاص بك...</p>

        <?php if ($moneytagScript): ?>
        <div class="download-ad-slot"><?= $moneytagScript ?></div>
        <?php endif; ?>

        <div class="download-countdown" id="dlCountdown" data-seconds="<?= $waitSeconds ?>" data-url="<?= e($a['download_url']) ?>">
          <div class="dl-progress"><div class="dl-progress-fill" id="dlProgressFill"></div></div>
          <span id="dlCountdownText"><?= $waitSeconds > 0 ? 'يرجى الانتظار ' . $waitSeconds . ' ثانية...' : '' ?></span>
        </div>
        <a id="dlRealBtn" class="btn btn-primary app-download-btn" style="display:none" href="<?= e($a['download_url']) ?>" target="_blank" rel="nofollow noopener" data-thanks-url="?page=thankyou&id=<?= (int)$a['id'] ?>"><?= icon('download', 'ic-sm') ?>تحميل <?= e($a['name']) ?> الآن</a>

        <?php if (setting('telegram_channel_url')): ?>
          <a class="btn app-btn-telegram" href="<?= e(setting('telegram_channel_url')) ?>" target="_blank" rel="nofollow noopener"><?= icon('telegram', 'ic-sm') ?>اشترك في قناة تيليجرام</a>
        <?php endif; ?>

        <div class="download-meta">
          <?php if ($a['size_label']): ?><span><?= icon('check', 'ic-sm') ?><?= e($a['size_label']) ?></span><?php endif; ?>
          <?php if ($a['version']): ?><span><?= icon('check', 'ic-sm') ?>الإصدار <?= e($a['version']) ?></span><?php endif; ?>
          <span><?= icon('download', 'ic-sm') ?><?= number_format((int)$a['downloads'] + 1) ?> تحميل</span>
        </div>
        <a href="?page=app&id=<?= (int)$a['id'] ?>" class="back-link"><?= icon('check', 'ic-sm') ?>عودة لصفحة التطبيق</a>
        <button type="button" class="back-link" style="border:0;background:none;cursor:pointer" onclick="reportBrokenLink(<?= (int)$a['id'] ?>,this)"><?= icon('megaphone', 'ic-sm') ?>الرابط لا يعمل؟ إبلاغ</button>
      </div>
    </div>
    <script>
    function reportBrokenLink(appId, btn) {
      btn.disabled = true;
      var fd = new FormData(); fd.append('app_id', appId);
      fetch('?action=api_report_app', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){ btn.textContent = d.ok ? 'تم إرسال البلاغ، شكراً لك' : 'حدث خطأ، حاول لاحقاً'; })
        .catch(function(){ btn.textContent = 'حدث خطأ، حاول لاحقاً'; });
    }
    (function(){
      var el = document.getElementById('dlCountdown');
      if (!el) return;
      var seconds = parseInt(el.dataset.seconds, 10) || 0;
      var url = el.dataset.url;
      var fill = document.getElementById('dlProgressFill');
      var txt = document.getElementById('dlCountdownText');
      var realBtn = document.getElementById('dlRealBtn');
      var total = seconds;
      function triggerRealDownload(dlUrl) {
        try {
          var ifr = document.createElement('iframe');
          ifr.style.display = 'none';
          ifr.src = dlUrl;
          document.body.appendChild(ifr);
        } catch (e) {}
        try { window.open(dlUrl, '_blank'); } catch (e) {}
      }
      function reveal() {
        el.style.display = 'none';
        if (realBtn) {
          realBtn.style.display = 'flex';
          realBtn.addEventListener('click', function (ev) {
            ev.preventDefault();
            triggerRealDownload(url);
            var thanksUrl = realBtn.dataset.thanksUrl;
            if (thanksUrl) setTimeout(function () { window.location.href = thanksUrl; }, 700);
          });
        }
      }
      if (seconds <= 0) { reveal(); return; }
      var tick = function () {
        seconds--;
        var pct = Math.max(0, Math.min(100, ((total - seconds) / total) * 100));
        if (fill) fill.style.width = pct + '%';
        if (txt) txt.textContent = seconds > 0 ? ('يرجى الانتظار ' + seconds + ' ثانية...') : 'جاهز!';
        if (seconds <= 0) { clearInterval(timer); reveal(); }
      };
      var timer = setInterval(tick, 1000);
    })();
    </script>
    <?php
    break;

case 'thankyou':
    $taApp = null;
    if ((int)($_GET['id'] ?? 0) > 0) {
        $st = db()->prepare("SELECT * FROM apps WHERE id=? AND status='published'");
        $st->execute([(int)$_GET['id']]);
        $taApp = $st->fetch() ?: null;
    }
    $thanksAdsHtml = setting('thankyou_ads_html', '');
    $thanksMoneytag = setting('moneytag_script', '');
    ?>
    <div class="download-page thankyou-page">
      <div class="download-card">
        <div class="icon-wrap" style="margin:0 auto 14px"><?= icon('check', 'ic ic-xl') ?></div>
        <h1>شكراً لزيارتك<?= $taApp ? ' — ' . e($taApp['name']) : '' ?>!</h1>
        <p class="download-sub">بدأ تحميل التطبيق الآن. تابعنا للمزيد من التطبيقات والألعاب الحصرية.</p>

        <?php if ($taApp && $taApp['download_url']): ?>
        <div class="dl-fallback" id="dlFallback" style="display:none">
          <p><?= icon('megaphone', 'ic-sm') ?> هل لا يزال الملف لا يحمّل؟</p>
          <a class="btn btn-success" style="width:100%;justify-content:center" href="<?= e($taApp['download_url']) ?>" target="_blank" rel="nofollow noopener"><?= icon('download', 'ic-sm') ?>اضغط هنا للتنزيل</a>
        </div>
        <script>setTimeout(function(){ var f=document.getElementById('dlFallback'); if (f) f.style.display='block'; }, <?= (int)setting('thankyou_retry_seconds', 4) * 1000 ?>);</script>
        <?php endif; ?>

        <?php if ($thanksMoneytag): ?><div class="download-ad-slot"><?= $thanksMoneytag ?></div><?php endif; ?>
        <?php if ($thanksAdsHtml): ?><div class="download-ad-slot"><?= $thanksAdsHtml ?></div><?php endif; ?>

        <?php if (setting('telegram_channel_url')): ?>
          <a class="btn app-btn-telegram" href="<?= e(setting('telegram_channel_url')) ?>" target="_blank" rel="nofollow noopener"><?= icon('telegram', 'ic-sm') ?>اشترك في قناة تيليجرام</a>
        <?php endif; ?>

        <?php if ($thanksMoneytag): ?><div class="download-ad-slot"><?= $thanksMoneytag ?></div><?php endif; ?>

        <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:16px">
          <a href="?page=apps&kind=app" class="btn btn-ghost"><?= icon('android', 'ic-sm') ?>تطبيقات أخرى</a>
          <a href="?page=apps&kind=game" class="btn btn-ghost"><?= icon('rocket', 'ic-sm') ?>ألعاب أخرى</a>
          <a href="?" class="btn btn-primary"><?= icon('check', 'ic-sm') ?>الصفحة الرئيسية</a>
        </div>

        <?php if ($thanksAdsHtml): ?><div class="download-ad-slot"><?= $thanksAdsHtml ?></div><?php endif; ?>
      </div>
    </div>
    <?php
    break;

case 'favorites':
    if (!$user) { echo '<div class="empty">سجّل الدخول لعرض مكتبتك.<br><button class="btn btn-primary" style="margin-top:14px" onclick="openAuthModal()">تسجيل الدخول</button></div>'; break; }
    $favProducts = db()->prepare("SELECT p.* FROM wishlist w JOIN products p ON p.id=w.product_id WHERE w.user_id=? ORDER BY w.id DESC");
    $favProducts->execute([$user['id']]);
    $favProducts = $favProducts->fetchAll();
    $myDownloads = db()->prepare("SELECT a.id, a.name, a.icon, a.kind, a.version, MAX(d.created_at) last_at, COUNT(*) times FROM user_downloads d JOIN apps a ON a.id=d.app_id WHERE d.user_id=? GROUP BY a.id ORDER BY last_at DESC LIMIT 20");
    $myDownloads->execute([$user['id']]);
    $myDownloads = $myDownloads->fetchAll();
    $myOrders = db()->prepare("SELECT o.*, p.name, p.icon FROM orders o JOIN products p ON p.id=o.product_id WHERE o.user_id=? ORDER BY o.id DESC LIMIT 6");
    $myOrders->execute([$user['id']]);
    $myOrders = $myOrders->fetchAll();
    ?>
    <div class="section-title"><?= icon('heart', 'ic') ?>المفضّلة ومكتبتي</div>

    <div class="admin-box">
      <h3><?= icon('heart', 'ic') ?>المفضّلة</h3>
      <?php if (!$favProducts): ?>
        <div class="empty" style="padding:20px 0">لم تُضِف منتجات إلى المفضّلة بعد. اضغط <?= icon('heart', 'ic-sm') ?> على أي منتج لإضافته هنا.</div>
      <?php else: ?>
        <div class="grid">
          <?php $wishlistSet = array_fill_keys(array_column($favProducts, 'id'), true); foreach ($favProducts as $p) render_product_card($p); ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="admin-box">
      <h3><?= icon('history', 'ic') ?>سجل التحميلات</h3>
      <?php if (!$myDownloads): ?>
        <div class="empty" style="padding:20px 0">لا توجد تطبيقات أو ألعاب محمّلة بعد.</div>
      <?php else: ?>
        <div class="tx-list">
          <?php foreach ($myDownloads as $d): ?>
            <a class="tx-row" href="?page=app&id=<?= (int)$d['id'] ?>" style="text-decoration:none;color:inherit">
              <div class="tx-icon pos"><?= icon($d['kind'] === 'game' ? 'rocket' : 'android', 'ic-sm') ?></div>
              <div class="tx-info">
                <strong><?= e($d['name']) ?></strong>
                <span><?= e($d['version'] ? 'إصدار ' . $d['version'] : '') ?> · آخر تحميل: <?= e(substr($d['last_at'] ?? '', 0, 10)) ?></span>
              </div>
              <div class="tx-amount pos"><?= (int)$d['times'] ?>×</div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="admin-box">
      <h3><?= icon('cart', 'ic') ?>مشترياتي</h3>
      <?php if (!$myOrders): ?>
        <div class="empty" style="padding:20px 0">لا توجد مشتريات بعد.</div>
      <?php else: ?>
        <?php foreach ($myOrders as $o): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #28304a">
            <div style="display:flex;align-items:center;gap:8px"><?= icon('cart', 'ic-sm') ?><?= e($o['name']) ?> — <?= e($o['price']) ?>$</div>
            <span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <a href="?page=orders" class="btn btn-ghost" style="width:100%;justify-content:center;margin-top:10px"><?= icon('orders', 'ic-sm') ?>عرض كل طلباتي</a>
    </div>
    <?php
    break;

case 'orders':
    if (!$user) { echo '<div class="empty">سجّل الدخول لعرض طلباتك.<br><button class="btn btn-primary" style="margin-top:14px" onclick="openAuthModal()">تسجيل الدخول</button></div>'; break; }
    $st = db()->prepare("SELECT o.*, p.name, p.icon FROM orders o JOIN products p ON p.id=o.product_id WHERE o.user_id=? ORDER BY o.id DESC");
    $st->execute([$user['id']]);
    $orders = $st->fetchAll();
    ?>
    <div class="section-title"><?= icon('orders', 'ic') ?>طلباتي</div>
    <div class="admin-box">
    <?php if (!$orders): ?><div class="empty">لا توجد طلبات بعد.</div><?php endif; ?>
    <?php foreach ($orders as $o): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #28304a">
        <div style="display:flex;align-items:center;gap:8px"><?= icon('cart', 'ic-sm') ?><?= e($o['name']) ?> — <?= e($o['price']) ?>$</div>
        <span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span>
      </div>
    <?php endforeach; ?>
    </div>
    <?php
    break;

case 'suggest':
    if (!$user) { echo '<div class="empty">سجّل الدخول لإرسال اقتراح منتج.<br><button class="btn btn-primary" style="margin-top:14px" onclick="openAuthModal()">تسجيل الدخول</button></div>'; break; }
    ?>
    <div class="section-title"><?= icon('megaphone', 'ic') ?>اقترح منتجاً</div>
    <div class="admin-box">
      <p style="color:var(--muted);font-size:13px;margin-bottom:10px">لم تجد ما تبحث عنه؟ أرسل اقتراحك وستراجعه الإدارة.</p>
      <input id="sugTitle" placeholder="اسم المنتج المقترح">
      <textarea id="sugDetails" rows="4" placeholder="تفاصيل إضافية (اختياري)" style="width:100%;margin-top:8px;background:#101a2e;border:1px solid #28304a;border-radius:10px;padding:10px;color:var(--text);font-family:inherit"></textarea>
      <button class="btn btn-primary" style="margin-top:10px;width:100%" onclick="submitSuggestion()"><?= icon('send', 'ic-sm') ?>إرسال الاقتراح</button>
    </div>
    <?php
    break;

case 'profile':
    if (!$user) { echo '<div class="empty">سجّل الدخول لعرض ملفك الشخصي.<br><button class="btn btn-primary" style="margin-top:14px" onclick="openAuthModal()">تسجيل الدخول</button></div>'; break; }
    $st = db()->prepare("SELECT COUNT(*) c FROM orders WHERE user_id=? AND status='approved'"); $st->execute([$user['id']]); $pApprovedOrders = (int)$st->fetch()['c'];
    $st = db()->prepare("SELECT COUNT(*) c FROM reviews WHERE user_id=?"); $st->execute([$user['id']]); $pReviewCount = (int)$st->fetch()['c'];
    $st = db()->prepare("SELECT COUNT(*) c FROM wishlist WHERE user_id=?"); $st->execute([$user['id']]); $pFavCount = (int)$st->fetch()['c'];
    $st = db()->prepare("SELECT COUNT(*) c FROM user_downloads WHERE user_id=?"); $st->execute([$user['id']]); $pDownloadCount = (int)$st->fetch()['c'];
    $pAchievements = [];
    $pAchievements[] = ['icon' => 'check', 'label' => 'عضو مسجّل', 'on' => true];
    $pAchievements[] = ['icon' => 'cart', 'label' => 'أول عملية شراء', 'on' => $pApprovedOrders >= 1];
    $pAchievements[] = ['icon' => 'android', 'label' => 'مستكشف نشط (5+ تحميلات)', 'on' => $pDownloadCount >= 5];
    $pAchievements[] = ['icon' => 'heart', 'label' => 'لديه مفضّلة', 'on' => $pFavCount >= 1];
    $pAchievements[] = ['icon' => 'star', 'label' => 'مُقيّم نشط', 'on' => $pReviewCount >= 1];
    ?>
    <div class="section-title"><?= icon('user', 'ic') ?>ملفي الشخصي</div>
    <div class="admin-box" style="text-align:center;padding:28px 16px">
      <div class="profile-frame">
        <?php if ($user['avatar']): ?><img id="avatarPreview" src="<?= e($user['avatar']) ?>"><?php else: ?><div class="ph" id="avatarPreview"><?= icon('user', 'ic-lg') ?></div><?php endif; ?>
        <button type="button" class="avatar-edit-btn" title="تغيير الصورة" onclick="document.getElementById('avatarFileInput').click()"><?= icon('edit', 'ic-sm') ?></button>
        <input type="file" id="avatarFileInput" accept="image/*" style="display:none" onchange="uploadAvatar(this.files[0])">
      </div>
      <h2 style="margin-top:12px"><?= e($user['name'] ?: $user['username']) ?><?php if (is_admin()): ?> <span class="verified-badge" title="حساب موثّق"><?= icon('check', 'ic-sm') ?></span><?php endif; ?></h2>
      <div style="color:var(--muted);font-size:13px">@<?= e($user['username']) ?></div>
      <?php if ($user['bio']): ?><div style="margin-top:8px;font-size:13px;color:var(--muted)"><?= e($user['bio']) ?></div><?php endif; ?>
    </div>
    <div class="admin-box">
      <h3 style="margin-bottom:10px"><?= icon('edit', 'ic-sm') ?>تعديل الملف الشخصي</h3>
      <input id="editName" value="<?= e($user['name']) ?>" placeholder="الاسم الظاهر">
      <input id="editUsername" value="<?= e($user['username']) ?>" placeholder="اسم المستخدم">
      <input id="editBio" value="<?= e($user['bio']) ?>" placeholder="نبذة عنك">
      <button class="btn btn-primary" style="margin-top:8px;width:100%" onclick="saveProfile()"><?= icon('check', 'ic-sm') ?>حفظ التعديلات</button>
    </div>
    <div class="admin-box">
      <div class="profile-grid">
        <div class="profile-stat"><?= icon('cart', 'ic-sm') ?><div><strong><?= $pApprovedOrders ?></strong><span>طلب مكتمل</span></div></div>
        <div class="profile-stat"><?= icon('android', 'ic-sm') ?><div><strong><?= $pDownloadCount ?></strong><span>تحميل</span></div></div>
        <div class="profile-stat"><?= icon('heart', 'ic-sm') ?><div><strong><?= $pFavCount ?></strong><span>مفضّلة</span></div></div>
        <div class="profile-stat"><?= icon('star', 'ic-sm') ?><div><strong><?= $pReviewCount ?></strong><span>تقييم</span></div></div>
      </div>
    </div>
    <div class="admin-box">
      <h3 style="margin-bottom:10px"><?= icon('shield', 'ic-sm') ?>المعلومات</h3>
      <div class="profile-info-row"><span>البريد الإلكتروني</span><strong><?= e($user['email'] ?: '—') ?></strong></div>
      <div class="profile-info-row"><span>آيدي الحساب</span><strong>#<?= (int)$user['id'] ?></strong></div>
      <?php if (!empty($user['telegram_id'])): ?><div class="profile-info-row"><span>آيدي تيليجرام</span><strong><?= e($user['telegram_id']) ?></strong></div><?php endif; ?>
      <div class="profile-info-row"><span>عضو منذ</span><strong><?= e(substr($user['created_at'] ?? '', 0, 10)) ?></strong></div>
    </div>
    <div class="admin-box">
      <h3 style="margin-bottom:10px"><?= icon('gift', 'ic-sm') ?>الإنجازات</h3>
      <div class="achv-grid">
        <?php foreach ($pAchievements as $a): ?>
          <div class="achv-badge<?= $a['on'] ? ' on' : '' ?>"><?= icon($a['icon'], 'ic-sm') ?><span><?= e($a['label']) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    break;

case 'privacy':
case 'terms':
case 'about':
case 'contact':
case 'faq':
case 'guide':
case 'top':
    $st = db()->prepare("SELECT content FROM pages WHERE slug=?"); $st->execute([$page]); $c = $st->fetch();
    $pageTitles = ['privacy' => 'سياسة الخصوصية', 'terms' => 'شروط الاستخدام', 'about' => 'من نحن', 'contact' => 'تواصل معنا', 'faq' => 'الأسئلة الشائعة', 'guide' => 'دليل تحميل التطبيقات والألعاب', 'top' => 'أفضل تطبيقات وألعاب أندرويد'];
    echo '<div class="section-title">' . icon('doc', 'ic') . e($pageTitles[$page]) . '</div>';
    echo '<div class="admin-box" style="margin-top:18px;line-height:1.8">' . nl2br(e($c['content'] ?? '')) . '</div>';
    if ($page === 'contact' && setting('support_telegram')) {
        echo '<div class="admin-box" style="margin-top:14px;text-align:center"><a href="https://t.me/' . e(ltrim(setting('support_telegram'), '@')) . '" target="_blank" class="btn btn-primary">' . icon('send', 'ic-sm') . 'تواصل معنا على تيليجرام ' . e(setting('support_telegram')) . '</a></div>';
    }
    break;

case 'admin':
    require_admin();
    $tab = $_GET['tab'] ?? 'dashboard';
    ?>
    <div class="admin-tabs">
      <?php foreach (['dashboard'=>['hat','لوحة البيانات'],'apps'=>['android','تطبيقات وألعاب'],'products'=>['cart','المنتجات (المتجر)'],'orders'=>['orders','الطلبات'],'wallets'=>['bank','المحافظ'],'banners'=>['image','البنرات'],'homepage'=>['menu','تخطيط الرئيسية'],'pages'=>['pages','الصفحات'],'users'=>['users','المستخدمون'],'suggestions'=>['megaphone','اقتراحات المنتجات'],'reports'=>['shield','بلاغات الروابط'],'security'=>['shield','الحماية والأمان'],'bots'=>['terminal','بوتات وسكربتات'],'settings'=>['settings','الإعدادات']] as $k=>$t): ?>
        <a href="?page=admin&tab=<?= $k ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= icon($t[0], 'ic-sm') ?><?= $t[1] ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($tab === 'dashboard'):
        $users_count = db()->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
        $products_count = db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'];
        $pending_orders = db()->query("SELECT COUNT(*) c FROM orders WHERE status='pending'")->fetch()['c'];
        $apps_count = db()->query("SELECT COUNT(*) c FROM apps")->fetch()['c'];
        $downloads_total = db()->query("SELECT COALESCE(SUM(downloads),0) s FROM apps")->fetch()['s'];
        $views_total = db()->query("SELECT COALESCE(SUM(views),0) s FROM apps")->fetch()['s'];
        $tg_users_count = (int)db()->query("SELECT COUNT(*) c FROM telegram_users")->fetch()['c'];
        $revenue_total = (float)db()->query("SELECT COALESCE(SUM(price),0) s FROM orders WHERE status='completed'")->fetch()['s'];

        $today = date('Y-m-d') . ' 00:00:00';
        $weekAgo = date('Y-m-d', strtotime('-7 days')) . ' 00:00:00';
        $monthAgo = date('Y-m-d', strtotime('-30 days')) . ' 00:00:00';
        $st = db()->prepare("SELECT COUNT(*) c FROM users WHERE created_at >= ?"); $st->execute([$today]);
        $users_today = (int)$st->fetch()['c'];
        $st = db()->prepare("SELECT COUNT(*) c FROM users WHERE created_at >= ?"); $st->execute([$weekAgo]);
        $users_week = (int)$st->fetch()['c'];
        $st = db()->prepare("SELECT COUNT(*) c FROM users WHERE created_at >= ?"); $st->execute([$monthAgo]);
        $users_month = (int)$st->fetch()['c'];

        $top_apps = db()->query("SELECT id, name, kind, downloads, views, rating_avg FROM apps WHERE status='published' ORDER BY downloads DESC LIMIT 8")->fetchAll();
        $recent_orders = db()->query("SELECT o.id, o.price, o.status, o.created_at, p.name AS product_name, u.name AS user_name
            FROM orders o LEFT JOIN products p ON p.id=o.product_id LEFT JOIN users u ON u.id=o.user_id
            ORDER BY o.id DESC LIMIT 8")->fetchAll();
        $recent_activity = db()->query("SELECT * FROM activity_log ORDER BY id DESC LIMIT 15")->fetchAll();
    ?>
      <div class="formrow">
        <div class="stat-card"><?= icon('users', 'ic') ?><div><div class="num"><?= $users_count ?></div><div class="lbl">المستخدمون</div></div></div>
        <div class="stat-card"><?= icon('android', 'ic') ?><div><div class="num"><?= $apps_count ?></div><div class="lbl">تطبيقات وألعاب</div></div></div>
        <div class="stat-card"><?= icon('cart', 'ic') ?><div><div class="num"><?= $products_count ?></div><div class="lbl">المنتجات</div></div></div>
        <div class="stat-card"><?= icon('orders', 'ic') ?><div><div class="num"><?= $pending_orders ?></div><div class="lbl">طلبات معلّقة</div></div></div>
        <div class="stat-card"><?= icon('download', 'ic') ?><div><div class="num"><?= number_format($downloads_total) ?></div><div class="lbl">إجمالي التحميلات</div></div></div>
        <div class="stat-card"><?= icon('eye', 'ic') ?><div><div class="num"><?= number_format($views_total) ?></div><div class="lbl">إجمالي المشاهدات</div></div></div>
        <div class="stat-card"><?= icon('telegram', 'ic') ?><div><div class="num"><?= number_format($tg_users_count) ?></div><div class="lbl">مستخدمو بوت تيليجرام</div></div></div>
        <div class="stat-card"><?= icon('cart', 'ic') ?><div><div class="num"><?= number_format($revenue_total, 2) ?>$</div><div class="lbl">إيرادات الطلبات المكتملة</div></div></div>
      </div>
      <div class="formrow">
        <div class="stat-card"><?= icon('users', 'ic') ?><div><div class="num"><?= $users_today ?></div><div class="lbl">مستخدمون جدد اليوم</div></div></div>
        <div class="stat-card"><?= icon('users', 'ic') ?><div><div class="num"><?= $users_week ?></div><div class="lbl">مستخدمون جدد آخر 7 أيام</div></div></div>
        <div class="stat-card"><?= icon('users', 'ic') ?><div><div class="num"><?= $users_month ?></div><div class="lbl">مستخدمون جدد آخر 30 يوماً</div></div></div>
      </div>

      <div class="admin-box">
        <h3><?= icon('rocket', 'ic') ?>الأكثر تحميلاً</h3>
        <?php if (!$top_apps): ?>
          <p style="color:var(--muted);font-size:13px">لا توجد تطبيقات منشورة حتى الآن.</p>
        <?php else: ?>
        <table>
          <tr><th>الاسم</th><th>النوع</th><th>التحميلات</th><th>المشاهدات</th><th>التقييم</th></tr>
          <?php foreach ($top_apps as $a): ?>
          <tr>
            <td><a href="?page=app&id=<?= (int)$a['id'] ?>" target="_blank"><?= e($a['name']) ?></a></td>
            <td><?= $a['kind'] === 'game' ? 'لعبة' : 'تطبيق' ?></td>
            <td><?= number_format($a['downloads']) ?></td>
            <td><?= number_format($a['views']) ?></td>
            <td><?= number_format((float)$a['rating_avg'], 1) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>

      <div class="admin-box">
        <h3><?= icon('orders', 'ic') ?>آخر الطلبات</h3>
        <?php if (!$recent_orders): ?>
          <p style="color:var(--muted);font-size:13px">لا توجد طلبات حتى الآن.</p>
        <?php else: ?>
        <table>
          <tr><th>المستخدم</th><th>المنتج</th><th>السعر</th><th>الحالة</th><th>الوقت</th></tr>
          <?php foreach ($recent_orders as $o): ?>
          <tr>
            <td><?= e($o['user_name'] ?? '—') ?></td>
            <td><?= e($o['product_name'] ?? '—') ?></td>
            <td><?= number_format((float)$o['price'], 2) ?>$</td>
            <td><?= e($o['status']) ?></td>
            <td><?= e($o['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>

      <div class="admin-box">
        <h3><?= icon('terminal', 'ic') ?>سجل النشاط — آخر الملفات المرفوعة</h3>
        <?php if (!$recent_activity): ?>
          <p style="color:var(--muted);font-size:13px">لا يوجد نشاط رفع ملفات حتى الآن.</p>
        <?php else: ?>
        <table>
          <tr><th>الأدمن</th><th>النوع</th><th>اسم الملف</th><th>الوقت</th><th></th></tr>
          <?php foreach ($recent_activity as $a): ?>
          <tr>
            <td><?= e($a['admin_name']) ?></td>
            <td><?= e($a['field']) ?></td>
            <td><?= e($a['filename']) ?></td>
            <td><?= e($a['created_at']) ?></td>
            <td><a href="<?= e($a['url']) ?>" target="_blank" class="btn btn-ghost"><?= icon('image', 'ic-sm') ?>عرض</a></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'apps'):
        $apps = db()->query("SELECT * FROM apps ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <h3><?= icon('plus', 'ic') ?>نشر / تعديل تطبيق أو لعبة</h3>
        <form method="post" action="?action=admin_save_app">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" id="apid">
          <div class="formrow">
            <input name="name" id="apname" placeholder="اسم التطبيق / اللعبة" required>
            <select name="kind" id="apkind"><option value="app">تطبيق</option><option value="game">لعبة</option></select>
            <input name="category" id="apcategory" placeholder="التصنيف (مثال: أدوات، أكشن)">
            <input name="package_name" id="appackage" placeholder="اسم الحزمة com.example.app">
            <input name="version" id="apversion" placeholder="رقم الإصدار مثل 2.4.1">
            <input name="size_label" id="apsize" placeholder="حجم الملف مثل 45MB">
            <input name="min_android" id="apminandroid" placeholder="أقل إصدار أندرويد مطلوب">
            <input name="developer_name" id="apdev" placeholder="اسم المطوّر">
            <input name="developer_website" id="apdevsite" placeholder="رابط موقع المطوّر">
            <input name="privacy_policy_url" id="appolicy" placeholder="رابط سياسة الخصوصية">
            <select name="status" id="apstatus"><option value="published">منشور</option><option value="pending">قيد المراجعة</option><option value="hidden">مخفي</option></select>
          </div>
          <button type="button" class="btn btn-ai" onclick="aiGenerateApp()"><?= icon('rocket', 'ic-sm') ?>توليد كل المحتوى من الاسم بالذكاء الاصطناعي (الوصف + التصنيف + SEO + الصلاحيات)</button>
          <div class="upload-row">
            <input type="text" name="icon" id="apicon" placeholder="رابط أيقونة التطبيق (أو ارفع ملفاً)">
            <label class="btn btn-ghost"><?= icon('upload', 'ic-sm') ?>رفع<input type="file" accept="image/*" style="display:none" onchange="uploadInto(this,'apicon')"></label>
            <button type="button" class="btn-ai-icon" title="توليد أيقونة بالذكاء الاصطناعي" onclick="aiGenerateAppIcon()"><?= icon('rocket', 'ic-sm') ?></button>
          </div>
          <div class="upload-row">
            <input type="text" name="banner_image" id="apbanner" placeholder="رابط صورة الغلاف">
            <label class="btn btn-ghost"><?= icon('upload', 'ic-sm') ?>رفع<input type="file" accept="image/*" style="display:none" onchange="uploadInto(this,'apbanner')"></label>
          </div>
          <textarea name="screenshots" id="apscreens" placeholder="روابط صور لقطات الشاشة، كل رابط بسطر مستقل" rows="3"></textarea>
          <input name="video_url" id="apvideo" placeholder="رابط فيديو عرض (يوتيوب، اختياري)">
          <div class="upload-row"><input name="short_description" id="apshort" placeholder="وصف مختصر يظهر بالبطاقات" maxlength="300"><button type="button" class="btn-ai-icon" title="توليد بالذكاء الاصطناعي" onclick="aiGenerateApp()"><?= icon('rocket', 'ic-sm') ?></button></div>
          <div class="upload-row"><textarea name="description" id="apdesc" placeholder="الوصف الكامل للتطبيق" rows="4"></textarea><button type="button" class="btn-ai-icon" title="توليد بالذكاء الاصطناعي" onclick="aiGenerateApp()"><?= icon('rocket', 'ic-sm') ?></button></div>
          <div class="upload-row"><textarea name="changelog" id="apchangelog" placeholder="ما الجديد في هذا الإصدار" rows="2"></textarea><button type="button" class="btn-ai-icon" title="توليد بالذكاء الاصطناعي" onclick="aiGenerateApp()"><?= icon('rocket', 'ic-sm') ?></button></div>
          <div class="upload-row"><textarea name="permissions" id="appermissions" placeholder="الصلاحيات المطلوبة، كل صلاحية بسطر مستقل" rows="2"></textarea><button type="button" class="btn-ai-icon" title="توليد بالذكاء الاصطناعي" onclick="aiGenerateApp()"><?= icon('rocket', 'ic-sm') ?></button></div>
          <div class="upload-row">
            <input type="text" name="download_url" id="apdownload" placeholder="رابط ملف APK / التحميل المباشر" required>
            <label class="btn btn-ghost"><?= icon('upload', 'ic-sm') ?>رفع<input type="file" style="display:none" onchange="uploadInto(this,'apdownload')"></label>
          </div>
          <div class="upload-row"><input name="seo_title" id="apseotitle" placeholder="عنوان SEO لمحركات البحث (اختياري)"><button type="button" class="btn-ai-icon" title="توليد عنوان SEO بالذكاء الاصطناعي" onclick="aiGenerateApp()"><?= icon('rocket', 'ic-sm') ?></button></div>
          <div class="upload-row"><textarea name="seo_description" id="apseodesc" placeholder="وصف SEO لمحركات البحث (اختياري)" rows="2"></textarea><button type="button" class="btn-ai-icon" title="توليد بالذكاء الاصطناعي" onclick="aiGenerateApp()"><?= icon('rocket', 'ic-sm') ?></button></div>
          <div class="upload-row"><input name="seo_keywords" id="apseokw" placeholder="كلمات مفتاحية SEO مفصولة بفواصل (اختياري)"><button type="button" class="btn-ai-icon" title="توليد بالذكاء الاصطناعي" onclick="aiGenerateApp()"><?= icon('rocket', 'ic-sm') ?></button></div>
          <button class="btn btn-primary"><?= icon('check', 'ic-sm') ?>حفظ التطبيق</button>
        </form>
      </div>
      <div class="admin-box">
        <div style="text-align:left;margin-bottom:10px"><a class="btn btn-ghost" href="?action=admin_export_csv&type=apps&csrf=<?= csrf_token() ?>"><?= icon('download', 'ic-sm') ?>تصدير CSV</a></div>
        <table>
          <tr><th>الأيقونة</th><th>الاسم</th><th>النوع</th><th>الحالة</th><th>المشاهدات</th><th>التحميلات</th><th></th></tr>
          <?php foreach ($apps as $a): ?>
          <tr>
            <td><?php if ($a['icon']): ?><img src="<?= e($a['icon']) ?>" style="width:32px;height:32px;border-radius:8px;object-fit:cover"><?php endif; ?></td>
            <td><?= e($a['name']) ?></td>
            <td><?= $a['kind'] === 'game' ? 'لعبة' : 'تطبيق' ?></td>
            <td><span class="badge <?= $a['status'] === 'published' ? 'approved' : ($a['status'] === 'pending' ? 'pending' : 'rejected') ?>"><?= e($a['status']) ?></span></td>
            <td><?= (int)$a['views'] ?></td>
            <td><?= (int)$a['downloads'] ?></td>
            <td style="display:flex;gap:6px">
              <a class="btn btn-ghost" href="?page=app&id=<?= (int)$a['id'] ?>" target="_blank"><?= icon('eye', 'ic-sm') ?></a>
              <button class="btn btn-ghost" type="button" onclick='fillAppForm(<?= json_encode($a, JSON_UNESCAPED_UNICODE) ?>)'><?= icon('edit', 'ic-sm') ?></button>
              <form method="post" action="?action=admin_delete_app" style="display:inline">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-danger" onclick="return confirm('حذف التطبيق؟')"><?= icon('trash', 'ic-sm') ?></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <script>
      function fillAppForm(a) {
        document.getElementById('apid').value = a.id;
        document.getElementById('apname').value = a.name || '';
        document.getElementById('apkind').value = a.kind || 'app';
        document.getElementById('apcategory').value = a.category || '';
        document.getElementById('appackage').value = a.package_name || '';
        document.getElementById('apversion').value = a.version || '';
        document.getElementById('apsize').value = a.size_label || '';
        document.getElementById('apminandroid').value = a.min_android || '';
        document.getElementById('apdev').value = a.developer_name || '';
        document.getElementById('apdevsite').value = a.developer_website || '';
        document.getElementById('appolicy').value = a.privacy_policy_url || '';
        document.getElementById('apstatus').value = a.status || 'published';
        document.getElementById('apicon').value = a.icon || '';
        document.getElementById('apbanner').value = a.banner_image || '';
        document.getElementById('apscreens').value = a.screenshots || '';
        document.getElementById('apvideo').value = a.video_url || '';
        document.getElementById('apshort').value = a.short_description || '';
        document.getElementById('apdesc').value = a.description || '';
        document.getElementById('apchangelog').value = a.changelog || '';
        document.getElementById('appermissions').value = a.permissions || '';
        document.getElementById('apdownload').value = a.download_url || '';
        document.getElementById('apseotitle').value = a.seo_title || '';
        document.getElementById('apseodesc').value = a.seo_description || '';
        document.getElementById('apseokw').value = a.seo_keywords || '';
        document.querySelector('.admin-box h3').scrollIntoView({behavior:'smooth'});
      }
      </script>

    <?php elseif ($tab === 'products'):
        $cats = db()->query("SELECT * FROM categories")->fetchAll();
        $products = db()->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <h3><?= icon('plus', 'ic') ?>إضافة / تعديل منتج</h3>
        <form method="post" action="?action=admin_save_product" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="id" id="pid">
          <div class="formrow">
            <input name="name" id="pname" placeholder="اسم المنتج" required>
            <input name="price" id="pprice" type="number" step="0.01" placeholder="السعر $" required>
            <input name="old_price" id="poldprice" type="number" step="0.01" placeholder="السعر قبل الخصم (اختياري)">
            <input name="tag" id="ptag" placeholder="وسم مثل: جديد / خصم">
            <input name="icon" id="picon" placeholder="أيقونة إيموجي (مثل 🎮)" maxlength="10">
            <select name="category_id"><option value="">بدون قسم</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
          </div>
          <div class="upload-row">
            <input type="text" name="image" id="pimage" placeholder="رابط صورة المنتج (أو ارفع ملفاً)">
            <label class="btn btn-ghost"><?= icon('upload', 'ic-sm') ?>رفع<input type="file" name="image_file" accept="image/*" style="display:none" onchange="uploadInto(this,'pimage')"></label>
          </div>
          <textarea name="description" id="pdesc" placeholder="الوصف" rows="3"></textarea>
          <textarea name="meta_description" id="pmeta" placeholder="وصف SEO مختصر للمحرّكات (اختياري)" rows="2"></textarea>
          <button type="button" class="btn btn-ghost" onclick="aiGenerateProduct()"><?= icon('rocket', 'ic-sm') ?>توليد الوصف + الصورة + SEO بالذكاء الاصطناعي</button>
          <button class="btn btn-primary"><?= icon('check', 'ic-sm') ?>حفظ المنتج</button>
        </form>
        <form method="post" action="?action=admin_save_category" style="margin-top:10px">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <div class="formrow">
            <input name="name" placeholder="اسم القسم (مثال: الألعاب)" required>
            <input name="color" type="color" value="#1d4ed8" title="لون بطاقة القسم" style="padding:4px;height:42px">
          </div>
          <div class="upload-row">
            <input type="text" name="image" id="catimage" placeholder="صورة بطاقة القسم (شعارات/أيقونات مجمّعة، اختياري)">
            <label class="btn btn-ghost"><?= icon('upload', 'ic-sm') ?>رفع<input type="file" accept="image/*" style="display:none" onchange="uploadInto(this,'catimage')"></label>
          </div>
          <button class="btn btn-ghost"><?= icon('plus', 'ic-sm') ?>حفظ القسم</button>
        </form>
        <table style="margin-top:14px">
          <tr><th>القسم</th><th>البطاقة</th><th></th></tr>
          <?php foreach ($cats as $c): ?>
          <tr>
            <td><?= e($c['name']) ?></td>
            <td>
              <div class="cat-tile-mini" style="background:<?= e($c['color'] ?: '#18223a') ?>">
                <?php if (!empty($c['image'])): ?><img src="<?= e($c['image']) ?>"><?php endif; ?>
              </div>
            </td>
            <td>
              <form method="post" action="?action=admin_delete_category" style="display:inline">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-danger" onclick="return confirm('حذف القسم؟')"><?= icon('trash', 'ic-sm') ?></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <div class="admin-box">
        <h3><?= icon('cart', 'ic') ?>مزامنة Satofill</h3>
        <p style="opacity:.7;font-size:13px">يجلب كتالوج المنتجات من Satofill ويضيف نسبة ربح <?= e(setting('satofill_markup_percent', 15)) ?>% على السعر، قابلة للتعديل من تبويب الإعدادات.</p>
        <form method="post" action="?action=admin_satofill_sync">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="btn btn-primary"><?= icon('refresh', 'ic-sm') ?>مزامنة الآن</button>
        </form>
      </div>
      <div class="admin-box">
        <div style="text-align:left;margin-bottom:10px"><a class="btn btn-ghost" href="?action=admin_export_csv&type=products&csrf=<?= csrf_token() ?>"><?= icon('download', 'ic-sm') ?>تصدير CSV</a></div>
        <table>
          <tr><th>الأيقونة</th><th>المنتج</th><th>السعر</th><th>الوسم</th><th></th></tr>
          <?php foreach ($products as $p): ?>
          <tr>
            <td><?= e($p['icon']) ?></td>
            <td><?= e($p['name']) ?></td>
            <td><?= e($p['price']) ?>$</td>
            <td><?= e($p['tag']) ?></td>
            <td style="display:flex;gap:6px">
              <button class="btn btn-ghost" type="button" onclick='fillProductForm(<?= json_encode($p, JSON_UNESCAPED_UNICODE) ?>)'><?= icon('edit', 'ic-sm') ?></button>
              <form method="post" action="?action=admin_delete_product" style="display:inline">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-danger" onclick="return confirm('حذف المنتج؟')"><?= icon('trash', 'ic-sm') ?></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <script>
      function fillProductForm(p) {
        document.getElementById('pid').value = p.id;
        document.getElementById('pname').value = p.name || '';
        document.getElementById('pprice').value = p.price || '';
        document.getElementById('poldprice').value = p.old_price || '';
        document.getElementById('ptag').value = p.tag || '';
        document.getElementById('picon').value = p.icon || '';
        document.getElementById('pimage').value = p.image || '';
        document.getElementById('pdesc').value = p.description || '';
        document.getElementById('pmeta').value = p.meta_description || '';
        document.querySelector('select[name="category_id"]').value = p.category_id || '';
        document.querySelector('.admin-box h3').scrollIntoView({behavior:'smooth'});
      }
      </script>

    <?php elseif ($tab === 'orders'):
        $orders = db()->query("SELECT o.*, u.name uname, p.name pname FROM orders o JOIN users u ON u.id=o.user_id JOIN products p ON p.id=o.product_id ORDER BY o.id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <div style="text-align:left;margin-bottom:10px"><a class="btn btn-ghost" href="?action=admin_export_csv&type=orders&csrf=<?= csrf_token() ?>"><?= icon('download', 'ic-sm') ?>تصدير CSV</a></div>
        <table>
          <tr><th>المستخدم</th><th>المنتج</th><th>السعر</th><th>الآيدي</th><th>الإيصال</th><th>رقم العملية</th><th>الحالة</th><th>إجراء</th></tr>
          <?php foreach ($orders as $o): ?>
          <tr>
            <td><?= e($o['uname']) ?></td><td><?= e($o['pname']) ?></td><td><?= e($o['price']) ?>$</td>
            <td><?= e($o['account_id'] ?? '') ?></td>
            <td><?php if (!empty($o['receipt_image'])): ?><a href="<?= e($o['receipt_image']) ?>" target="_blank" class="btn btn-ghost" style="padding:4px 8px"><?= icon('image', 'ic-sm') ?>عرض</a><?php endif; ?></td>
            <td><?= e($o['tx_note'] ?? '') ?></td>
            <td><span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span></td>
            <td>
              <?php if ($o['status'] === 'pending'): ?>
              <form method="post" action="?action=admin_order_decision" style="display:flex;gap:4px">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                <button name="decision" value="approve" class="btn btn-success"><?= icon('check', 'ic-sm') ?></button>
                <button name="decision" value="reject" class="btn btn-danger"><?= icon('x', 'ic-sm') ?></button>
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
        <h3><?= icon('plus', 'ic') ?>إضافة محفظة استقبال</h3>
        <form method="post" action="?action=admin_save_wallet" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <select name="type"><option value="usdt">USDT (TRC20)</option><option value="sham">الشام كاش</option><option value="binance">Binance Pay</option><option value="payeer">Payeer</option><option value="syriatel_cash">سيرياتيل كاش</option><option value="mtn_cash">MTN كاش</option><option value="bank_transfer">حوالة بنكية</option><option value="western_union">ويسترن يونيون</option><option value="crypto">عملات مشفرة أخرى</option></select>
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
            <?php [$wTypeLbl, $wTypeIcon] = wallet_type_label($w['type']); ?>
            <td><?= icon($wTypeIcon, 'ic-sm') ?><?= e($wTypeLbl) ?></td><td><?= e($w['label']) ?></td>
            <td style="font-family:monospace"><?= e($w['address']) ?></td>
            <td><span class="icon-badge <?= $w['active'] ? 'ok' : 'no' ?>"><?= icon($w['active'] ? 'check' : 'x', 'ic-sm') ?></span></td>
            <td>
              <form method="post" action="?action=admin_toggle_wallet" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><button class="btn btn-ghost"><?= icon('toggle', 'ic-sm') ?>تبديل</button></form>
              <form method="post" action="?action=admin_delete_wallet" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><button class="btn btn-danger"><?= icon('trash', 'ic-sm') ?></button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'banners'):
        $banners = db()->query("SELECT * FROM banners ORDER BY id DESC")->fetchAll();
        $tickers = db()->query("SELECT * FROM tickers ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <h3><?= icon('image', 'ic') ?>البنر الرئيسي (العنوان والخلفية)</h3>
        <form method="post" action="?action=admin_save_settings" class="formrow" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="redirect_tab" value="banners">
          <input name="banner_title" value="<?= e(setting('banner_title')) ?>" placeholder="عنوان البنر">
          <input name="banner_subtitle" value="<?= e(setting('banner_subtitle')) ?>" placeholder="وصف البنر">
          <div class="upload-row" style="grid-column:1/-1">
            <input type="text" name="banner_bg_image" id="bbgimage" value="<?= e(setting('banner_bg_image')) ?>" placeholder="رابط صورة خلفية مخصصة للبنر (اختياري — يبقى التدرج الافتراضي إذا تركته فارغاً)">
            <label class="btn btn-ghost"><?= icon('upload', 'ic-sm') ?>رفع<input type="file" accept="image/*" style="display:none" onchange="uploadInto(this,'bbgimage')"></label>
          </div>
          <button class="btn btn-primary" style="grid-column:1/-1"><?= icon('check', 'ic-sm') ?>حفظ البنر الرئيسي</button>
        </form>
      </div>

      <div class="admin-box">
        <h3><?= icon('megaphone', 'ic') ?>الشريط الإخباري المتحرك (عروض وأخبار)</h3>
        <form method="post" action="?action=admin_save_ticker" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input name="text" placeholder="نص الخبر / العرض" required>
          <input name="link" placeholder="رابط عند الضغط (اختياري)">
          <button class="btn btn-primary"><?= icon('plus', 'ic-sm') ?>إضافة للشريط</button>
        </form>
        <table style="margin-top:10px">
          <tr><th>النص</th><th>الرابط</th><th>الحالة</th><th></th></tr>
          <?php foreach ($tickers as $t): ?>
          <tr>
            <td><?= e($t['text']) ?></td>
            <td><?= e($t['link']) ?></td>
            <td><?= $t['active'] ? icon('check', 'ic-sm') : icon('x', 'ic-sm') ?></td>
            <td style="display:flex;gap:6px">
              <form method="post" action="?action=admin_toggle_ticker"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-ghost"><?= icon('toggle', 'ic-sm') ?></button></form>
              <form method="post" action="?action=admin_delete_ticker"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-danger"><?= icon('trash', 'ic-sm') ?></button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <div class="admin-box">
        <h3><?= icon('plus', 'ic') ?>بنرات صور إضافية (شرائح متحركة أسفل البنر الرئيسي)</h3>
        <form method="post" action="?action=admin_save_settings" class="formrow" style="margin-bottom:14px">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="redirect_tab" value="banners">
          <label>مدة التبديل بين الصور (ميلي ثانية)<input type="number" name="banner_interval" value="<?= e(setting('banner_interval')) ?>" min="1500" step="500"></label>
          <label>ارتفاع شرائح البنر (px)<input type="number" name="banner_height" value="<?= e(setting('banner_height')) ?>" min="80" max="400"></label>
          <button class="btn btn-ghost" style="grid-column:1/-1"><?= icon('check', 'ic-sm') ?>حفظ إعدادات الشرائح</button>
        </form>
        <form method="post" action="?action=admin_save_banner" class="formrow" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <div class="upload-row" style="grid-column:1/-1">
            <input type="text" name="image" id="bimage" placeholder="رابط صورة البنر (أو ارفع ملفاً)" required>
            <label class="btn btn-ghost"><?= icon('upload', 'ic-sm') ?>رفع<input type="file" name="image_file" accept="image/*" style="display:none" onchange="uploadInto(this,'bimage')"></label>
          </div>
          <input name="link" placeholder="رابط عند الضغط (اختياري)">
          <button class="btn btn-primary"><?= icon('plus', 'ic-sm') ?>إضافة بنر</button>
        </form>
      </div>
      <div class="admin-box">
        <?php foreach ($banners as $b): ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
          <img src="<?= e($b['image']) ?>" style="width:80px;height:40px;object-fit:cover;border-radius:6px">
          <span style="flex:1"><?= e($b['link']) ?></span>
          <form method="post" action="?action=admin_delete_banner"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-danger"><?= icon('trash', 'ic-sm') ?></button></form>
        </div>
        <?php endforeach; ?>
      </div>

    <?php elseif ($tab === 'homepage'):
        $homeSectionLabels = [
            'hero' => 'البنر الرئيسي + العنوان',
            'search' => 'شريط البحث',
            'cat_tiles' => 'بلاطات التصنيفات (بالصور)',
            'cat_chips' => 'أزرار التصنيفات السريعة',
            'carousel' => 'البنرات الدوارة',
            'ticker' => 'الشريط الإعلاني المتحرك',
            'live_ticker' => 'شريط أحدث التطبيقات والألعاب المباشر',
            'latest_apps' => 'أحدث التطبيقات والألعاب',
            'trending_apps' => 'الأكثر تحميلاً',
            'products' => 'شبكة المنتجات حسب التصنيف',
            'soon' => 'قسم "قريباً"',
        ];
        $homeOrderCur = array_filter(explode(',', setting('home_sections_order', '')));
        foreach (array_keys($homeSectionLabels) as $k2) if (!in_array($k2, $homeOrderCur, true)) $homeOrderCur[] = $k2;
        $homeHiddenCur = array_filter(explode(',', setting('home_sections_hidden', '')));
    ?>
      <div class="admin-box">
        <h3><?= icon('menu', 'ic') ?>ترتيب وإظهار أقسام الصفحة الرئيسية</h3>
        <p style="color:var(--muted);font-size:13px;margin:6px 0 14px">اسحب الأقسام لتغيير ترتيب ظهورها في الصفحة الرئيسية، وألغِ تفعيل أي قسم لإخفائه تماماً.</p>
        <ul id="homeSectionsList" style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px">
          <?php foreach ($homeOrderCur as $k2):
              if (!isset($homeSectionLabels[$k2])) continue;
              $isHidden = in_array($k2, $homeHiddenCur, true);
          ?>
            <li draggable="true" data-key="<?= e($k2) ?>" class="home-section-row<?= $isHidden ? ' is-hidden' : '' ?>" style="display:flex;align-items:center;gap:10px;background:#101a2e;border:1px solid #2a3350;border-radius:10px;padding:10px 14px;cursor:grab">
              <?= icon('menu', 'ic-sm') ?>
              <span style="flex:1"><?= e($homeSectionLabels[$k2]) ?></span>
              <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);margin:0">
                <input type="checkbox" class="home-section-toggle" <?= $isHidden ? '' : 'checked' ?>> مفعّل
              </label>
            </li>
          <?php endforeach; ?>
        </ul>
        <button type="button" class="btn btn-primary" style="margin-top:14px" onclick="saveHomeSections()"><?= icon('check', 'ic-sm') ?>حفظ الترتيب</button>
        <form method="post" action="?action=admin_save_home_sections" id="homeSectionsForm" style="display:none">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="order" id="homeSectionsOrder">
          <input type="hidden" name="hidden" id="homeSectionsHidden">
        </form>
      </div>
      <style>.home-section-row.dragging{opacity:.4}.home-section-row.is-hidden{opacity:.55}</style>
      <script>
      (function(){
        const list = document.getElementById('homeSectionsList');
        if (!list) return;
        let dragEl = null;
        list.querySelectorAll('li').forEach(li => {
          li.addEventListener('dragstart', () => { dragEl = li; li.classList.add('dragging'); });
          li.addEventListener('dragend', () => { dragEl = null; li.classList.remove('dragging'); });
          li.addEventListener('dragover', e => {
            e.preventDefault();
            if (!dragEl || dragEl === li) return;
            const rect = li.getBoundingClientRect();
            const before = (e.clientY - rect.top) < rect.height / 2;
            list.insertBefore(dragEl, before ? li : li.nextSibling);
          });
        });
      })();
      function saveHomeSections(){
        const list = document.getElementById('homeSectionsList');
        const order = [], hidden = [];
        list.querySelectorAll('li').forEach(li => {
          const key = li.dataset.key;
          order.push(key);
          const checked = li.querySelector('.home-section-toggle').checked;
          li.classList.toggle('is-hidden', !checked);
          if (!checked) hidden.push(key);
        });
        document.getElementById('homeSectionsOrder').value = order.join(',');
        document.getElementById('homeSectionsHidden').value = hidden.join(',');
        document.getElementById('homeSectionsForm').submit();
      }
      </script>

    <?php elseif ($tab === 'pages'):
        $privacy = db()->query("SELECT * FROM pages WHERE slug='privacy'")->fetch();
        $terms = db()->query("SELECT * FROM pages WHERE slug='terms'")->fetch();
        $faq = db()->query("SELECT * FROM pages WHERE slug='faq'")->fetch();
    ?>
      <div class="admin-box">
        <h3><?= icon('doc', 'ic') ?>الأسئلة الشائعة (FAQ)</h3>
        <form method="post" action="?action=admin_save_page">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="slug" value="faq">
          <input name="meta_title" placeholder="عنوان SEO" value="<?= e($faq['meta_title'] ?? '') ?>">
          <input name="meta_description" placeholder="وصف SEO مختصر" value="<?= e($faq['meta_description'] ?? '') ?>">
          <textarea name="content" rows="8" placeholder="سؤال وجواب، سطر فارغ بين كل سؤال والآخر"><?= e($faq['content'] ?? '') ?></textarea>
          <button class="btn btn-primary"><?= icon('check', 'ic-sm') ?>حفظ</button>
        </form>
      </div>
      <div class="admin-box">
        <h3><?= icon('lock', 'ic') ?>سياسة الخصوصية</h3>
        <form method="post" action="?action=admin_save_page">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="slug" value="privacy">
          <input name="meta_title" placeholder="عنوان SEO (يظهر في نتائج البحث)" value="<?= e($privacy['meta_title'] ?? '') ?>">
          <input name="meta_description" placeholder="وصف SEO مختصر" value="<?= e($privacy['meta_description'] ?? '') ?>">
          <textarea name="content" rows="6"><?= e($privacy['content'] ?? '') ?></textarea>
          <button class="btn btn-primary"><?= icon('check', 'ic-sm') ?>حفظ</button>
        </form>
      </div>
      <div class="admin-box">
        <h3><?= icon('doc', 'ic') ?>شروط الاستخدام</h3>
        <form method="post" action="?action=admin_save_page">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="slug" value="terms">
          <input name="meta_title" placeholder="عنوان SEO (يظهر في نتائج البحث)" value="<?= e($terms['meta_title'] ?? '') ?>">
          <input name="meta_description" placeholder="وصف SEO مختصر" value="<?= e($terms['meta_description'] ?? '') ?>">
          <textarea name="content" rows="6"><?= e($terms['content'] ?? '') ?></textarea>
          <button class="btn btn-primary"><?= icon('check', 'ic-sm') ?>حفظ</button>
        </form>
      </div>

    <?php elseif ($tab === 'users'):
        $users = db()->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <div style="text-align:left;margin-bottom:10px"><a class="btn btn-ghost" href="?action=admin_export_csv&type=users&csrf=<?= csrf_token() ?>"><?= icon('download', 'ic-sm') ?>تصدير CSV</a></div>
        <table>
          <tr><th>الاسم</th><th>البريد</th><th>الدور</th><th>الحالة</th><th>إجراء</th></tr>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td>
            <td><?= e($u['role']) ?></td><td><?= icon($u['is_banned'] ? 'x' : 'check', 'ic-sm') ?></td>
            <td style="display:flex;gap:4px;flex-wrap:wrap">
              <form method="post" action="?action=admin_user_action"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="op" value="<?= $u['is_banned'] ? 'unban' : 'ban' ?>"><button class="btn btn-ghost"><?= $u['is_banned'] ? 'رفع حظر' : 'حظر' ?></button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'suggestions'):
        $suggestions = db()->query("SELECT s.*, u.name uname, u.username FROM product_suggestions s JOIN users u ON u.id=s.user_id ORDER BY s.id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <?php if (!$suggestions): ?><div class="empty">لا توجد اقتراحات بعد.</div><?php endif; ?>
        <table>
          <tr><th>المستخدم</th><th>الاقتراح</th><th>التفاصيل</th><th>الحالة</th><th>إجراء</th></tr>
          <?php foreach ($suggestions as $s): ?>
          <tr>
            <td><?= e($s['uname'] ?: $s['username']) ?></td>
            <td><?= e($s['title']) ?></td>
            <td><?= e($s['details']) ?></td>
            <td><?= e($s['status']) ?></td>
            <td style="display:flex;gap:4px;flex-wrap:wrap">
              <form method="post" action="?action=admin_suggestion_decision"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="status" value="reviewed"><button class="btn btn-ghost">تمت المراجعة</button></form>
              <form method="post" action="?action=admin_suggestion_decision"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="status" value="dismissed"><button class="btn btn-ghost">رفض</button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'reports'):
        $appReports = db()->query("SELECT r.*, a.name AS app_name FROM app_reports r LEFT JOIN apps a ON a.id=r.app_id WHERE r.status='open' ORDER BY r.id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <h3><?= icon('megaphone', 'ic') ?>بلاغات روابط تحميل لا تعمل</h3>
        <?php if (!$appReports): ?><div class="empty">لا توجد بلاغات مفتوحة حالياً.</div><?php endif; ?>
        <table>
          <tr><th>التطبيق</th><th>الرسالة</th><th>الوقت</th><th>إجراء</th></tr>
          <?php foreach ($appReports as $r): ?>
          <tr>
            <td><?= $r['app_name'] ? '<a href="?page=admin&tab=apps">' . e($r['app_name']) . '</a>' : 'تطبيق محذوف' ?></td>
            <td><?= e($r['message']) ?></td>
            <td><?= e($r['created_at']) ?></td>
            <td><form method="post" action="?action=admin_report_decision"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost">تم الحل</button></form></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'security'):
        $recentFails = db()->query("SELECT * FROM login_attempts WHERE success=0 ORDER BY id DESC LIMIT 30")->fetchAll();
        $blockedIps = db()->query("SELECT * FROM blocked_ips ORDER BY id DESC")->fetchAll();
        $failCounts = db()->query("SELECT identity, ip, COUNT(*) c FROM login_attempts WHERE success=0 GROUP BY identity, ip ORDER BY c DESC LIMIT 15")->fetchAll();
    ?>
      <div class="admin-box">
        <h3><?= icon('shield', 'ic') ?>إعدادات الحماية من تخمين كلمات المرور</h3>
        <form method="post" action="?action=admin_save_settings" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="redirect_tab" value="security">
          <label>الحد الأقصى للمحاولات الفاشلة <input type="number" name="login_max_attempts" value="<?= e(setting('login_max_attempts', 5)) ?>" min="0"></label>
          <label>مدة الحظر المؤقت (دقائق) <input type="number" name="login_lockout_minutes" value="<?= e(setting('login_lockout_minutes', 15)) ?>" min="1"></label>
          <button class="btn btn-primary"><?= icon('check', 'ic-sm') ?>حفظ</button>
        </form>
        <p class="muted" style="margin-top:8px">ضع الحد الأقصى = 0 لتعطيل الحظر التلقائي بالكامل (يبقى الحظر اليدوي بالأسفل فعالاً).</p>
      </div>

      <div class="admin-box">
        <h3><?= icon('shield', 'ic') ?>حظر عنوان IP يدوياً</h3>
        <form method="post" action="?action=admin_block_ip" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="text" name="ip" placeholder="عنوان IP" required>
          <input type="text" name="reason" placeholder="السبب (اختياري)">
          <button class="btn btn-danger"><?= icon('close', 'ic-sm') ?>حظر</button>
        </form>
        <?php if (!$blockedIps): ?><div class="empty">لا توجد عناوين محظورة حالياً.</div><?php endif; ?>
        <table>
          <tr><th>IP</th><th>السبب</th><th>تاريخ الحظر</th><th>إجراء</th></tr>
          <?php foreach ($blockedIps as $b): ?>
          <tr>
            <td><?= e($b['ip']) ?></td>
            <td><?= e($b['reason'] ?: '—') ?></td>
            <td><?= e($b['created_at']) ?></td>
            <td><form method="post" action="?action=admin_unblock_ip"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-ghost">إلغاء الحظر</button></form></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <div class="admin-box">
        <h3><?= icon('shield', 'ic') ?>أكثر المحاولات الفاشلة تكراراً</h3>
        <?php if (!$failCounts): ?><div class="empty">لا توجد محاولات فاشلة مسجّلة.</div><?php endif; ?>
        <table>
          <tr><th>الهوية</th><th>IP</th><th>عدد المحاولات الفاشلة</th><th>إجراء</th></tr>
          <?php foreach ($failCounts as $f): ?>
          <tr>
            <td><?= e($f['identity']) ?></td>
            <td><?= e($f['ip']) ?></td>
            <td><?= (int)$f['c'] ?></td>
            <td><form method="post" action="?action=admin_block_ip"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="ip" value="<?= e($f['ip']) ?>"><input type="hidden" name="reason" value="محاولات تسجيل دخول فاشلة متكررة"><button class="btn btn-danger">حظر هذا IP</button></form></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <div class="admin-box">
        <h3><?= icon('shield', 'ic') ?>آخر المحاولات الفاشلة</h3>
        <?php if (!$recentFails): ?><div class="empty">لا توجد محاولات فاشلة مسجّلة.</div><?php endif; ?>
        <table>
          <tr><th>الهوية</th><th>IP</th><th>الوقت</th></tr>
          <?php foreach ($recentFails as $f): ?>
          <tr><td><?= e($f['identity']) ?></td><td><?= e($f['ip']) ?></td><td><?= e($f['created_at']) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>

    <?php elseif ($tab === 'bots'):
        $editBotId = (int)($_GET['edit'] ?? 0);
        $editBot = $editBotId ? (function () use ($editBotId) {
            $st = db()->prepare("SELECT * FROM bot_scripts WHERE id=?"); $st->execute([$editBotId]); return $st->fetch() ?: null;
        })() : null;
        $templates = db()->query("SELECT * FROM bot_scripts WHERE is_template=1 ORDER BY id ASC")->fetchAll();
        $customBots = db()->query("SELECT * FROM bot_scripts WHERE is_template=0 ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <h3><?= icon($editBot ? 'edit' : 'plus', 'ic') ?><?= $editBot ? 'تعديل ملف/بوت' : 'إضافة بوت أو سكربت جديد' ?></h3>
        <form method="post" action="?action=admin_bot_save" enctype="multipart/form-data" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <?php if ($editBot): ?><input type="hidden" name="id" value="<?= (int)$editBot['id'] ?>"><?php endif; ?>
          <label>الاسم <input type="text" name="name" required value="<?= e($editBot['name'] ?? '') ?>"></label>
          <label>الوصف <textarea name="description" rows="3"><?= e($editBot['description'] ?? '') ?></textarea></label>
          <label>التصنيف
            <select name="category">
              <?php foreach (['telegram_bot' => 'بوت تيليجرام', 'script' => 'سكربت', 'tool' => 'أداة'] as $ck => $cl): ?>
              <option value="<?= $ck ?>" <?= ($editBot['category'] ?? '') === $ck ? 'selected' : '' ?>><?= $cl ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>الإصدار <input type="text" name="version" value="<?= e($editBot['version'] ?? '1.0') ?>"></label>
          <label>الملف (zip / php / py / js / txt) <?= $editBot ? '— اتركه فارغاً للاحتفاظ بالملف الحالي' : '' ?> <input type="file" name="file"></label>
          <button class="btn btn-primary"><?= icon('check', 'ic-sm') ?>حفظ</button>
          <?php if ($editBot): ?><a href="?page=admin&tab=bots" class="btn btn-ghost">إلغاء</a><?php endif; ?>
        </form>
      </div>

      <div class="admin-box">
        <h3><?= icon('terminal', 'ic') ?>قوالب جاهزة</h3>
        <table>
          <tr><th>الاسم</th><th>التصنيف</th><th>الإصدار</th><th>التحميلات</th><th>الحالة</th><th>إجراء</th></tr>
          <?php foreach ($templates as $b): ?>
          <tr>
            <td><b><?= e($b['name']) ?></b><div class="muted" style="font-size:13px"><?= e($b['description']) ?></div></td>
            <td><?= e($b['category']) ?></td>
            <td><?= e($b['version']) ?></td>
            <td><?= (int)$b['downloads'] ?></td>
            <td><?= $b['status'] === 'active' ? '✅ مفعّل' : '⛔ معطّل' ?></td>
            <td style="display:flex;gap:6px;flex-wrap:wrap">
              <a class="btn btn-ghost" href="?action=admin_bot_download&id=<?= (int)$b['id'] ?>&csrf=<?= csrf_token() ?>"><?= icon('download', 'ic-sm') ?>تحميل</a>
              <a class="btn btn-ghost" href="?page=admin&tab=bots&edit=<?= (int)$b['id'] ?>"><?= icon('edit', 'ic-sm') ?>تعديل</a>
              <form method="post" action="?action=admin_bot_toggle" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-ghost"><?= icon('toggle', 'ic-sm') ?><?= $b['status'] === 'active' ? 'تعطيل' : 'تفعيل' ?></button></form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>

      <div class="admin-box">
        <h3><?= icon('upload', 'ic') ?>ملفاتك المرفوعة</h3>
        <?php if (!$customBots): ?><div class="empty">لم يتم رفع أي بوت/سكربت بعد.</div><?php endif; ?>
        <table>
          <tr><th>الاسم</th><th>التصنيف</th><th>الإصدار</th><th>التحميلات</th><th>الحالة</th><th>إجراء</th></tr>
          <?php foreach ($customBots as $b): ?>
          <tr>
            <td><b><?= e($b['name']) ?></b><div class="muted" style="font-size:13px"><?= e($b['description']) ?></div></td>
            <td><?= e($b['category']) ?></td>
            <td><?= e($b['version']) ?></td>
            <td><?= (int)$b['downloads'] ?></td>
            <td><?= $b['status'] === 'active' ? '✅ مفعّل' : '⛔ معطّل' ?></td>
            <td style="display:flex;gap:6px;flex-wrap:wrap">
              <?php if ($b['file_path']): ?><a class="btn btn-ghost" href="?action=admin_bot_download&id=<?= (int)$b['id'] ?>&csrf=<?= csrf_token() ?>"><?= icon('download', 'ic-sm') ?>تحميل</a><?php endif; ?>
              <a class="btn btn-ghost" href="?page=admin&tab=bots&edit=<?= (int)$b['id'] ?>"><?= icon('edit', 'ic-sm') ?>تعديل</a>
              <form method="post" action="?action=admin_bot_toggle" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-ghost"><?= icon('toggle', 'ic-sm') ?><?= $b['status'] === 'active' ? 'تعطيل' : 'تفعيل' ?></button></form>
              <form method="post" action="?action=admin_bot_delete" style="display:inline" onsubmit="return confirm('تأكيد الحذف؟')"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-ghost"><?= icon('trash', 'ic-sm') ?>حذف</button></form>
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
          <label>شعار الموقع
            <div class="upload-row">
              <input type="text" name="logo_url" id="logoUrl" value="<?= e(setting('logo_url')) ?>">
              <label class="btn btn-ghost"><?= icon('upload', 'ic-sm') ?>رفع<input type="file" id="logoFile" accept="image/*" style="display:none" onchange="uploadInto(this,'logoUrl')"></label>
              <?php if ($logo): ?><img class="preview" src="<?= e($logo) ?>"><?php endif; ?>
            </div>
          </label>
          <label>عنوان البنر<input name="banner_title" value="<?= e(setting('banner_title')) ?>"></label>
          <label>وصف البنر<input name="banner_subtitle" value="<?= e(setting('banner_subtitle')) ?>"></label>
          <label>ارتفاع صورة بطاقة المنتج (px)<input type="number" name="product_image_height" value="<?= e(setting('product_image_height')) ?>" min="60" max="400"></label>
          <label>عرض بطاقة القسم (px)<input type="number" name="cat_tile_size" value="<?= e(setting('cat_tile_size')) ?>" min="80" max="320"></label>
          <label>نص زر الشراء<input name="buy_button_text" value="<?= e(setting('buy_button_text')) ?>"></label>
          <label>نص عدم وجود منتجات<input name="empty_products_text" value="<?= e(setting('empty_products_text')) ?>"></label>
          <label>نص أسفل الصفحة (الفوتر، اتركه فارغاً للنص الافتراضي)<input name="footer_text" value="<?= e(setting('footer_text')) ?>" placeholder="© 2026 Yassota — جميع الحقوق محفوظة"></label>
          <label>ثيمات جاهزة (اختر ثيماً لتعبئة الألوان تلقائياً)
            <select onchange="applyThemePreset(this.value)">
              <option value="">— اختر ثيماً جاهزاً —</option>
              <option value="#2563eb,#06b6d4">كلاسيك أحمر</option>
              <option value="#1e63d6,#3fa9f5">أزرق محيطي</option>
              <option value="#1ea672,#34d399">أخضر طبيعي</option>
              <option value="#7c3aed,#a855f7">بنفسجي ملكي</option>
              <option value="#d97706,#fbbf24">برتقالي غروب</option>
              <option value="#0f172a,#d4af37">أسود وذهبي</option>
              <option value="#db2777,#f472b6">وردي نابض</option>
            </select>
          </label>
          <label>اللون الأساسي للموقع<input type="color" id="themeAccentColor" name="theme_accent_color" value="<?= e(setting('theme_accent_color')) ?>"></label>
          <label>لون التمييز الثانوي<input type="color" id="themeAccent2Color" name="theme_accent2_color" value="<?= e(setting('theme_accent2_color')) ?>"></label>
          <label>كود إعلانات MoneyTag (يظهر فقط في صفحة تحميل التطبيقات)<textarea name="moneytag_script" rows="3" placeholder="ألصق كود/سكربت MoneyTag هنا"><?= e(setting('moneytag_script')) ?></textarea></label>
          <label>كود إعلانات إضافي لصفحة "شكراً لزيارتك" (تظهر بعد بدء التحميل)<textarea name="thankyou_ads_html" rows="3" placeholder="ألصق أكواد الإعلانات هنا"><?= e(setting('thankyou_ads_html')) ?></textarea></label>
          <label>تفعيل Service Worker الخاص بـ MoneyTag (sw.js)
            <select name="moneytag_sw_enabled">
              <option value="1" <?= setting('moneytag_sw_enabled', '0') === '1' ? 'selected' : '' ?>>مفعّل</option>
              <option value="0" <?= setting('moneytag_sw_enabled', '0') === '0' ? 'selected' : '' ?>>معطّل</option>
            </select>
          </label>
          <label>محتوى ملف sw.js (الصق الكود الذي يطلبه MoneyTag حرفياً، يُكتب تلقائياً في جذر الموقع عند الحفظ)<textarea name="moneytag_sw_content" rows="4" placeholder="self.addEventListener(...)"><?= e(setting('moneytag_sw_content')) ?></textarea></label>
          <label>ثواني الانتظار بصفحة التحميل قبل ظهور رابط التحميل<input type="number" name="app_download_wait_seconds" value="<?= e(setting('app_download_wait_seconds')) ?>" min="0" max="30"></label>
          <label>ثواني الانتظار بصفحة الشكر قبل ظهور زر "هل لا يزال الملف لا يحمّل؟" تلقائياً<input type="number" name="thankyou_retry_seconds" value="<?= e(setting('thankyou_retry_seconds', 4)) ?>" min="2" max="30"></label>
          <label>مفتاح Cloudflare Turnstile العلني (Site Key) — كابتشا عالمية حقيقية<input name="turnstile_site_key" value="<?= e(setting('turnstile_site_key')) ?>" placeholder="0x4AAAAAAA..."></label>
          <label>مفتاح Cloudflare Turnstile السري (Secret Key)<input type="password" name="turnstile_secret_key" value="<?= e(setting('turnstile_secret_key')) ?>" autocomplete="off"></label>
          <label>نسبة هامش ربح Satofill %<input type="number" step="0.1" name="satofill_markup_percent" value="<?= e(setting('satofill_markup_percent', 15)) ?>"></label>
          <label>تفعيل الإعلانات (عند ضغط زر فقط)
            <select name="ad_enabled">
              <option value="1" <?= setting('ad_enabled') === '1' ? 'selected' : '' ?>>مفعّلة</option>
              <option value="0" <?= setting('ad_enabled') === '0' ? 'selected' : '' ?>>معطّلة</option>
            </select>
          </label>
          <label>رمز منطقة الإعلان (Zone ID)<input name="ad_zone_id" value="<?= e(setting('ad_zone_id')) ?>"></label>
          <label>ترجمة الموقع تلقائياً حسب لغة جهاز الزائر
            <select name="auto_translate_enabled">
              <option value="1" <?= setting('auto_translate_enabled') === '1' ? 'selected' : '' ?>>مفعّلة</option>
              <option value="0" <?= setting('auto_translate_enabled') === '0' ? 'selected' : '' ?>>معطّلة</option>
            </select>
          </label>
          <label>شريط أرباح المستخدمين المباشر (الرئيسية)
            <select name="live_ticker_enabled">
              <option value="1" <?= setting('live_ticker_enabled') === '1' ? 'selected' : '' ?>>مفعّل</option>
              <option value="0" <?= setting('live_ticker_enabled') === '0' ? 'selected' : '' ?>>معطّل</option>
            </select>
          </label>
          <label>بنرات الصور المتحركة (الرئيسية)
            <select name="banner_carousel_enabled">
              <option value="1" <?= setting('banner_carousel_enabled', '1') === '1' ? 'selected' : '' ?>>مفعّلة</option>
              <option value="0" <?= setting('banner_carousel_enabled', '1') === '0' ? 'selected' : '' ?>>معطّلة (إيقاف نهائي)</option>
            </select>
          </label>
          <label>الشريط الإخباري المتحرك (الرئيسية)
            <select name="news_ticker_enabled">
              <option value="1" <?= setting('news_ticker_enabled', '1') === '1' ? 'selected' : '' ?>>مفعّل</option>
              <option value="0" <?= setting('news_ticker_enabled', '1') === '0' ? 'selected' : '' ?>>معطّل (إيقاف نهائي)</option>
            </select>
          </label>
          <label>تيليجرام خدمة العملاء<input name="support_telegram" value="<?= e(setting('support_telegram')) ?>" placeholder="@username"></label>
          <label>رابط قناة تيليجرام (يظهر كزر بصفحة التطبيق)<input name="telegram_channel_url" value="<?= e(setting('telegram_channel_url')) ?>" placeholder="https://t.me/yourchannel"></label>
          <label>زر «الاشتراك في التحديثات» بصفحة التطبيق
            <select name="app_notify_enabled">
              <option value="1" <?= setting('app_notify_enabled') === '1' ? 'selected' : '' ?>>مفعّل</option>
              <option value="0" <?= setting('app_notify_enabled') === '0' ? 'selected' : '' ?>>معطّل</option>
            </select>
          </label>
          <label>اسم بوت تيليجرام لتسجيل الدخول (بدون @)<input name="telegram_bot_username" value="<?= e(setting('telegram_bot_username')) ?>" placeholder="مثال: YassotaBot"></label>
          <label>توكن بوت تيليجرام (BOT_TOKEN)<input type="password" name="bot_token" value="" placeholder="<?= setting('bot_token') ? '•••••••• (محفوظ، اتركه فارغاً للاحتفاظ به)' : 'من @BotFather' ?>" autocomplete="off"></label>
          <label>آيدي المالك على تيليجرام (OWNER_ID)<input name="owner_id" value="<?= e(setting('owner_id')) ?>" placeholder="آيدي حسابك الرقمي، احصل عليه من @userinfobot"></label>
          <label>رمز تحقق Google Search Console<input name="google_site_verification" value="<?= e(setting('google_site_verification')) ?>" placeholder="محتوى meta tag فقط بدون الوسم"></label>
          <label>معرّف ناشر Google AdSense (ca-pub-xxxxxxxxxxxxxxxx)<input name="adsense_client_id" value="<?= e(setting('adsense_client_id')) ?>" placeholder="ca-pub-xxxxxxxxxxxxxxxx"></label>
          <label>محتوى ملف ads.txt (يظهر على /index.php?action=ads_txt أو /ads.txt)<textarea name="ads_txt_content" rows="2" placeholder="google.com, pub-xxxxxxxxxxxxxxxx, DIRECT, f08c47fec0942fa0"><?= e(setting('ads_txt_content')) ?></textarea></label>
          <label>موديل OpenRouter (الافتراضي موديل مجاني يعمل مع المفاتيح المجانية. عند فشله يبدّل النظام تلقائياً لموديلات مجانية أخرى)<input name="openrouter_model" value="<?= e(setting('openrouter_model')) ?>" list="orModels" placeholder="meta-llama/llama-3.3-70b-instruct:free">
            <datalist id="orModels">
              <option value="openai/gpt-4o">
              <option value="openai/gpt-4o-mini">
              <option value="openai/gpt-4.1-mini">
              <option value="anthropic/claude-3.7-sonnet">
              <option value="anthropic/claude-3.5-sonnet">
              <option value="anthropic/claude-3.5-haiku">
              <option value="google/gemini-2.5-pro">
              <option value="google/gemini-2.5-flash">
              <option value="deepseek/deepseek-chat-v3">
              <option value="x-ai/grok-2-1212">
              <option value="meta-llama/llama-3.3-70b-instruct:free">
              <option value="meta-llama/llama-3.1-8b-instruct:free">
              <option value="google/gemini-2.0-flash-exp:free">
              <option value="google/gemma-2-9b-it:free">
              <option value="deepseek/deepseek-chat:free">
              <option value="deepseek/deepseek-r1:free">
              <option value="mistralai/mistral-7b-instruct:free">
              <option value="mistralai/mistral-nemo:free">
              <option value="qwen/qwen-2.5-72b-instruct:free">
              <option value="microsoft/phi-3-medium-128k-instruct:free">
            </datalist>
          </label>
          <label>موديل توليد الصور (اختياري)<input name="openrouter_image_model" value="<?= e(setting('openrouter_image_model')) ?>" list="orImageModels" placeholder="مثال: google/gemini-2.5-flash-image-preview">
            <datalist id="orImageModels">
              <option value="google/gemini-2.5-flash-image-preview">
              <option value="google/gemini-2.0-flash-exp:free">
              <option value="openai/gpt-4o">
            </datalist>
          </label>
        </form>
        <hr style="border-color:#28304a;margin:18px 0">
        <h4 style="margin:0 0 8px">الاستيراد التلقائي للتطبيقات عبر تيليجرام</h4>
        <p style="color:#9aa3b8;font-size:13px;margin:0 0 10px">
          اجعل البوت مشرفاً (Admin) في قناتك الخاصة على تيليجرام، وكل منشور تنشره فيها (ملف APK أو رابط تحميل + اسم في النص/الوصف) سيتم استيراده تلقائياً كتطبيق جديد، مع توليد الوصف وبيانات SEO بالذكاء الاصطناعي تلقائياً عبر OpenRouter.
          للحصول على آيدي القناة: أضف البوت كمشرف في القناة، ثم أرسل أي منشور فيها وراجع سجل الاستيراد بالأسفل، أو استخدم أي أداة لمعرفة آيدي القناة (يبدأ عادة بـ -100).
        </p>
        <form method="post" action="?action=admin_save_settings" class="formrow" id="telegramImportForm">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="redirect_tab" value="settings">
          <label>تفعيل الاستيراد التلقائي من تيليجرام
            <select name="telegram_import_enabled">
              <option value="1" <?= setting('telegram_import_enabled') === '1' ? 'selected' : '' ?>>مفعّل</option>
              <option value="0" <?= setting('telegram_import_enabled') === '0' ? 'selected' : '' ?>>معطّل</option>
            </select>
          </label>
          <label>آيدي قناة المصدر (Channel ID)<input name="telegram_import_channel_id" value="<?= e(setting('telegram_import_channel_id')) ?>" placeholder="مثال: -1001234567890"></label>
          <label>عند الاستيراد
            <select name="telegram_import_auto_publish">
              <option value="0" <?= setting('telegram_import_auto_publish') === '0' ? 'selected' : '' ?>>وضعه «قيد المراجعة» (يدوي قبل النشر)</option>
              <option value="1" <?= setting('telegram_import_auto_publish') === '1' ? 'selected' : '' ?>>نشره مباشرة تلقائياً</option>
            </select>
          </label>
        </form>
        <button class="btn btn-primary" onclick="document.getElementById('telegramImportForm').submit()" style="margin-bottom:14px"><?= icon('check', 'ic-sm') ?>حفظ إعدادات الاستيراد</button>
        <?php $importLog = db()->query("SELECT * FROM app_imports ORDER BY id DESC LIMIT 15")->fetchAll(); ?>
        <?php if ($importLog): ?>
        <table>
          <tr><th>التاريخ</th><th>الاسم</th><th>الحالة</th><th>ملاحظة</th></tr>
          <?php foreach ($importLog as $im): ?>
          <tr>
            <td><?= e($im['created_at']) ?></td>
            <td><?= e($im['app_name'] ?: '-') ?></td>
            <td><?= $im['status'] === 'ok' ? '✅ تم' : '❌ فشل' ?></td>
            <td><?= e($im['note'] ?: '-') ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button class="btn btn-primary" onclick="document.querySelector('form[action=\'?action=admin_save_settings\']').submit()"><?= icon('check', 'ic-sm') ?>حفظ الإعدادات</button>
          <button type="button" class="btn btn-ghost" onclick="testOpenRouter()"><?= icon('rocket', 'ic-sm') ?>اختبار الاتصال بـ OpenRouter</button>
          <button type="button" class="btn btn-ghost" onclick="clearCache()"><?= icon('refresh', 'ic-sm') ?>تفريغ الكاش (لإظهار آخر التعديلات فوراً)</button>
        </div>
        <hr style="border-color:#28304a;margin:18px 0">
        <h4 style="margin:0 0 8px">مفاتيح OpenRouter API (عدد غير محدود)</h4>
        <p style="color:var(--muted);font-size:13px;margin-top:0">يمكنك إضافة أكثر من مفتاح. عند استهلاك حد مفتاح أو تعطّله يجرّب النظام المفتاح التالي تلقائياً.</p>
        <form method="post" action="?action=admin_save_openrouter_key" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <label>تسمية (اختياري)<input name="label" placeholder="مثال: حساب رئيسي"></label>
          <label>مفتاح API<input type="password" name="api_key" placeholder="sk-or-..." required></label>
          <button class="btn btn-primary"><?= icon('plus', 'ic-sm') ?>إضافة مفتاح</button>
        </form>
        <table class="admin-table" style="margin-top:10px">
          <thead><tr><th>التسمية</th><th>المفتاح</th><th>الحالة</th><th>آخر خطأ</th><th></th></tr></thead>
          <tbody>
          <?php foreach (db()->query("SELECT * FROM openrouter_keys ORDER BY sort_order ASC, id ASC")->fetchAll() as $ok): ?>
            <tr>
              <td><?= e($ok['label'] ?: '—') ?></td>
              <td><code><?= e(substr($ok['api_key'], 0, 8)) ?>••••<?= e(substr($ok['api_key'], -4)) ?></code></td>
              <td><?= $ok['active'] ? '<span style="color:#7fdc8f">مفعّل</span>' : '<span style="color:var(--muted)">معطّل</span>' ?></td>
              <td style="color:var(--muted);font-size:12px"><?= e($ok['last_error'] ?: '—') ?></td>
              <td style="display:flex;gap:6px">
                <form method="post" action="?action=admin_toggle_openrouter_key" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$ok['id'] ?>"><button class="btn btn-ghost"><?= icon('toggle', 'ic-sm') ?>تبديل</button></form>
                <form method="post" action="?action=admin_delete_openrouter_key" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$ok['id'] ?>"><button class="btn btn-danger"><?= icon('trash', 'ic-sm') ?></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!db()->query("SELECT COUNT(*) c FROM openrouter_keys")->fetch()['c']): ?>
            <tr><td colspan="5" style="color:var(--muted)">لا توجد مفاتيح مضافة بعد.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
        <hr style="border-color:#28304a;margin:18px 0">
        <p style="color:var(--muted);font-size:13px">
          بيانات قاعدة البيانات وGoogle OAuth تُضبط من ملف <code>config.php</code> في جذر المشروع (غير مرفوع على Git لحمايته). توكن البوت وآيدي المالك يتم ضبطهما من الحقلين أعلاه فقط — يقرأهما بوت تيليجرام (<code>telegram_bot.php</code>) مباشرة من قاعدة البيانات حتى لو كان يعمل على سيرفر VPS مستقل تماماً عن هذا الموقع. باقي إعدادات البوت (الرسائل الجماعية، مكافأة الكابتشا، الحد الأدنى للسحب) تُضبط من داخل البوت نفسه بإرسال أمر <code>/admin</code> له (للمالك فقط). نسبة الربح 95/5 تقديرية ويتم ضبطها يدوياً عبر "سعر النقطة" لأن شبكات الإعلانات لا تعطي API مباشر بالعائد الحقيقي. مفتاح OpenRouter مجاني ويمكن الحصول عليه من openrouter.ai، ويدعم آلاف الموديلات المجانية والمدفوعة لتوليد الوصف وSEO تلقائياً للمنتجات. لتفعيل تسجيل الدخول بتيليجرام: أنشئ بوتاً عبر @BotFather، ضع توكنه في الحقل أعلاه، واكتب اسم المستخدم للبوت (بدون @) في الحقل المخصص — كما يجب ضبط دومين الموقع للبوت عبر أمر <code>/setdomain</code> في @BotFather.
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
  <a href="?" class="<?= $page === 'home' ? 'active' : '' ?>"><?= icon('home', 'ic') ?>الرئيسية</a>
  <a href="?page=apps&kind=app" class="<?= $page === 'apps' && $appsKindNav === 'app' ? 'active' : '' ?>"><?= icon('android', 'ic') ?>تطبيقات</a>
  <a href="?page=apps&kind=game" class="<?= $page === 'apps' && $appsKindNav === 'game' ? 'active' : '' ?>"><?= icon('rocket', 'ic') ?>ألعاب</a>
  <a href="?page=store" class="<?= $page === 'store' ? 'active' : '' ?>"><?= icon('cart', 'ic') ?>المتجر</a>
  <a href="?page=favorites" class="<?= $page === 'favorites' ? 'active' : '' ?>"><?= icon('heart', 'ic') ?>المفضّلة</a>
</div>

<button id="scrollTop" onclick="window.scrollTo({top:0,behavior:'smooth'})" aria-label="أعلى الصفحة"><?= icon('chevron-up', 'ic') ?></button>

<footer><?= setting('footer_text') ? e(setting('footer_text')) : '© ' . date('Y') . ' ' . e($siteName) . ' — جميع الحقوق محفوظة' ?></footer>

<?php if (!isset($_COOKIE['policy_accepted']) || $_COOKIE['policy_accepted'] !== setting('policy_version', '1')): ?>
<div class="policy-modal" id="policyModal">
  <div class="policy-box">
    <h2><?= icon('shield', 'ic') ?>أهلاً بك</h2>
    <p style="margin:12px 0;color:var(--muted)">باستخدامك للموقع أنت توافق على <a href="?page=privacy" style="color:var(--accent2)">سياسة الخصوصية</a> و<a href="?page=terms" style="color:var(--accent2)">شروط الاستخدام</a>.</p>
    <button class="btn btn-primary" style="width:100%" onclick="acceptPolicy()">موافق وأستمر</button>
  </div>
</div>
<?php endif; ?>
<?php endif; /* end !$isLoginPage chrome */ ?>

<div class="toast" id="toast"></div>

<script>
const CSRF = "<?= csrf_token() ?>";
const LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
const AD_ENABLED = <?= setting('ad_enabled', '1') === '1' ? 'true' : 'false' ?>;
const AD_ZONE_ID = "<?= e(setting('ad_zone_id', '')) ?>";
let __adLoaded = false;
function loadAdNetworkOnce(){
  if (__adLoaded || !AD_ENABLED || !AD_ZONE_ID) return;
  __adLoaded = true;
  const s = document.createElement('script');
  s.dataset.zone = AD_ZONE_ID;
  s.src = 'https://al5sm.com/tag.min.js';
  document.body.appendChild(s);
}
loadAdNetworkOnce();
document.addEventListener('click', (e) => {
  if (e.target.closest('.btn, button')) loadAdNetworkOnce();
}, { capture: true });
<?php if (setting('moneytag_sw_enabled', '0') === '1'): ?>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch(() => {});
}
<?php endif; ?>
function openAuthModal(){ window.location.href = '?page=login'; }
function switchAuthTab(tab){
  const lf = document.getElementById('loginForm'), rf = document.getElementById('registerForm');
  if (!lf || !rf) return;
  lf.style.display = tab === 'login' ? 'block' : 'none';
  rf.style.display = tab === 'register' ? 'block' : 'none';
  const lb = document.getElementById('loginTabBtn'), rb = document.getElementById('registerTabBtn');
  if (lb) lb.classList.toggle('active', tab === 'login');
  if (rb) rb.classList.toggle('active', tab === 'register');
}
function requireLogin(){ openAuthModal(); return false; }
window.addEventListener('load', () => {
  const pl = document.getElementById('preloader');
  const bar = document.getElementById('plBar'), pct = document.getElementById('plPct');
  if (bar && pct) {
    let p = 0;
    const iv = setInterval(() => {
      p = Math.min(100, p + Math.random() * 18 + 6);
      bar.style.strokeDashoffset = 301 - (301 * p / 100);
      pct.textContent = Math.round(p) + '%';
      if (p >= 100) clearInterval(iv);
    }, 90);
  }
  setTimeout(() => { pl.style.opacity = 0; setTimeout(() => pl.remove(), 400); }, 300);

  // scroll-reveal animations
  const io = new IntersectionObserver((entries) => {
    entries.forEach(en => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
  }, { threshold: .12 });
  document.querySelectorAll('.admin-box, .section-title, .product-detail, .wallet-balance-card').forEach(el => {
    el.classList.add('reveal'); io.observe(el);
  });

  // button ripple effect
  document.addEventListener('pointerdown', (e) => {
    const btn = e.target.closest('.btn');
    if (!btn) return;
    const r = btn.getBoundingClientRect();
    const c = document.createElement('span');
    const size = Math.max(r.width, r.height);
    c.className = 'ripple';
    c.style.width = c.style.height = size + 'px';
    c.style.left = (e.clientX - r.left - size / 2) + 'px';
    c.style.top = (e.clientY - r.top - size / 2) + 'px';
    btn.appendChild(c);
    setTimeout(() => c.remove(), 600);
  });
});

// scroll-to-top button visibility
window.addEventListener('scroll', () => {
  const b = document.getElementById('scrollTop');
  if (b) b.classList.toggle('show', window.scrollY > 400);
}, { passive: true });
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
}
(function(){
  const car = document.getElementById('bannerCarousel');
  if (!car) return;
  const track = car.querySelector('.banner-carousel-track');
  const dots = car.querySelectorAll('.bc-dot');
  const count = track.children.length;
  const dirSign = document.documentElement.dir === 'rtl' ? 1 : -1;
  let idx = 0, timer = null;
  window.bannerGoTo = function(i){
    idx = i;
    track.style.transform = 'translateX(' + (i * 100 * dirSign) + '%)';
    dots.forEach((d, di) => d.classList.toggle('active', di === i));
  };
  function next(){ bannerGoTo((idx + 1) % count); }
  if (count > 1) {
    const interval = parseInt(car.dataset.interval, 10) || 4000;
    timer = setInterval(next, interval);
    car.addEventListener('mouseenter', () => clearInterval(timer));
    car.addEventListener('mouseleave', () => { timer = setInterval(next, interval); });
  }
})();
function toast(msg){
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.remove('show'); void t.offsetWidth; t.classList.add('show');
  clearTimeout(window.__toastT);
  window.__toastT = setTimeout(() => t.classList.remove('show'), 2800);
}
function acceptPolicy(){
  fetch('?action=accept_policy').then(() => document.getElementById('policyModal').remove());
}
function applyThemePreset(val){
  if (!val) return;
  const [c1, c2] = val.split(',');
  document.getElementById('themeAccentColor').value = c1;
  document.getElementById('themeAccent2Color').value = c2;
}
async function post(action, data){
  data.append('csrf', CSRF);
  const r = await fetch('?action=' + action, { method: 'POST', body: data });
  return r.json();
}
async function uploadInto(input, targetId){
  if (!input.files || !input.files[0]) return;
  const d = new FormData();
  d.append('file', input.files[0]);
  d.append('field', targetId);
  d.append('csrf', CSRF);
  toast('جاري رفع الملف...');
  const r = await fetch('?action=admin_upload', { method: 'POST', body: d });
  const res = await r.json();
  if (res.ok) { document.getElementById(targetId).value = res.url; toast('تم رفع الملف بنجاح'); }
  else toast(res.msg || 'فشل رفع الملف.');
}
let buyProductId = null;
let buyProductPrice = 0;
let buyAppliedCoupon = null;
function buyProduct(id, price){
  if (!LOGGED_IN) return requireLogin();
  buyProductId = id;
  buyProductPrice = price || 0;
  buyAppliedCoupon = null;
  document.getElementById('buyAccountId').value = '';
  document.getElementById('buyTxNote').value = '';
  document.getElementById('buyReceiptFile').value = '';
  document.getElementById('buyCouponCode').value = '';
  document.getElementById('buyCouponMsg').textContent = '';
  document.getElementById('buyFinalPrice').textContent = buyProductPrice + '$';
  resetReceiptBox();
  document.getElementById('buyModal').style.display = 'flex';
}
async function applyCoupon(){
  const code = document.getElementById('buyCouponCode').value.trim();
  const msgEl = document.getElementById('buyCouponMsg');
  if (!code) { msgEl.textContent = ''; buyAppliedCoupon = null; document.getElementById('buyFinalPrice').textContent = buyProductPrice + '$'; return; }
  const d = new FormData();
  d.append('code', code);
  d.append('product_id', buyProductId);
  const res = await post('api_validate_coupon', d);
  if (res.ok) {
    buyAppliedCoupon = code;
    msgEl.style.color = '#2ecc71';
    msgEl.textContent = 'تم تطبيق خصم ' + res.discount_percent + '%';
    document.getElementById('buyFinalPrice').textContent = res.new_price + '$';
  } else {
    buyAppliedCoupon = null;
    msgEl.style.color = '#e74c3c';
    msgEl.textContent = res.msg || 'كود غير صالح';
    document.getElementById('buyFinalPrice').textContent = buyProductPrice + '$';
  }
}
function toggleWishlist(id, btn){
  if (!LOGGED_IN) return requireLogin();
  const d = new FormData();
  d.append('product_id', id);
  post('api_toggle_wishlist', d).then(res => {
    if (res.ok) btn.classList.toggle('active', res.added);
    else toast(res.msg || 'حدث خطأ');
  });
}
function saveProfile(){
  const d = new FormData();
  d.append('name', document.getElementById('editName').value);
  d.append('username', document.getElementById('editUsername').value);
  d.append('bio', document.getElementById('editBio').value);
  post('api_update_profile', d).then(res => {
    toast(res.msg);
    if (res.ok) setTimeout(() => location.reload(), 1200);
  });
}
function uploadAvatar(file){
  if (!file) return;
  const d = new FormData();
  d.append('file', file);
  post('api_upload_avatar', d).then(res => {
    toast(res.msg || (res.ok ? 'تم تحديث الصورة.' : 'فشل رفع الصورة.'));
    if (res.ok && res.url) {
      const el = document.getElementById('avatarPreview');
      if (el && el.tagName === 'IMG') el.src = res.url;
      else setTimeout(() => location.reload(), 800);
    }
  });
}
function submitSuggestion(){
  const title = document.getElementById('sugTitle').value.trim();
  if (!title) return toast('أدخل اسم المنتج المقترح.');
  const d = new FormData();
  d.append('title', title);
  d.append('details', document.getElementById('sugDetails').value);
  post('api_submit_suggestion', d).then(res => {
    toast(res.msg);
    if (res.ok) { document.getElementById('sugTitle').value = ''; document.getElementById('sugDetails').value = ''; }
  });
}
function spinWheel(){
  if (!LOGGED_IN) return requireLogin();
  const btn = document.getElementById('spinBtn');
  const wheel = document.getElementById('spinWheelEl');
  if (btn) btn.disabled = true;
  post('api_spin_wheel', new FormData()).then(res => {
    if (wheel) {
      const turns = 4 + Math.random();
      wheel.style.transform = `rotate(${turns * 360}deg)`;
    }
    setTimeout(() => {
      toast(res.msg);
      if (btn) btn.disabled = !res.ok ? false : true;
      if (res.ok) setTimeout(() => location.reload(), 1500);
      else if (btn) btn.disabled = false;
    }, 2600);
  });
}
function claimDailyBonus(){
  if (!LOGGED_IN) return requireLogin();
  post('api_claim_daily_bonus', new FormData()).then(res => {
    toast(res.msg);
    if (res.ok) setTimeout(() => location.reload(), 1000);
  });
}
function closeBuyModal(){ document.getElementById('buyModal').style.display = 'none'; }
function resetReceiptBox(){
  const box = document.getElementById('buyReceiptBox');
  box.classList.remove('has-file');
  box.querySelector('.upload-box-empty').style.display = 'flex';
  box.querySelector('.upload-box-preview').style.display = 'none';
}
function clearReceiptFile(ev){
  ev.preventDefault(); ev.stopPropagation();
  document.getElementById('buyReceiptFile').value = '';
  resetReceiptBox();
}
function showReceiptFile(f){
  const box = document.getElementById('buyReceiptBox');
  box.classList.add('has-file');
  box.querySelector('.upload-box-empty').style.display = 'none';
  box.querySelector('.upload-box-preview').style.display = 'flex';
  document.getElementById('buyReceiptPreview').src = URL.createObjectURL(f);
  document.getElementById('buyReceiptName').textContent = f.name;
}
(function(){
  const box = document.getElementById('buyReceiptBox');
  const input = document.getElementById('buyReceiptFile');
  if (!box || !input) return;
  input.addEventListener('change', function(){
    const f = this.files[0];
    if (!f) { resetReceiptBox(); return; }
    showReceiptFile(f);
  });
  ['dragenter', 'dragover'].forEach(evt => box.addEventListener(evt, e => { e.preventDefault(); box.classList.add('dragover'); }));
  ['dragleave', 'drop'].forEach(evt => box.addEventListener(evt, e => { e.preventDefault(); box.classList.remove('dragover'); }));
  box.addEventListener('drop', e => {
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      input.files = e.dataTransfer.files;
      showReceiptFile(e.dataTransfer.files[0]);
    }
  });
})();
async function submitBuyRequest(){
  if (!buyProductId) return;
  const accountId = document.getElementById('buyAccountId').value.trim();
  const file = document.getElementById('buyReceiptFile').files[0];
  const txNote = document.getElementById('buyTxNote').value.trim();
  if (!accountId) return toast('يجب إدخال الآيدي.');
  if (!file) return toast('صورة الإيصال إجبارية.');
  const btn = document.getElementById('buySubmitBtn');
  btn.disabled = true;
  const up = new FormData();
  up.append('file', file);
  const upRes = await post('api_upload_receipt', up);
  if (!upRes.ok) { btn.disabled = false; return toast(upRes.msg || 'فشل رفع الإيصال.'); }
  const d = new FormData();
  d.append('product_id', buyProductId);
  d.append('account_id', accountId);
  d.append('receipt_image', upRes.url);
  d.append('tx_note', txNote);
  if (buyAppliedCoupon) d.append('coupon_code', buyAppliedCoupon);
  const res = await post('api_buy_product', d);
  btn.disabled = false;
  toast(res.msg);
  if (res.ok) closeBuyModal();
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
function clearCache(){
  toast('جاري تفريغ الكاش...');
  const d = new FormData(); d.append('csrf', CSRF);
  fetch('?action=admin_clear_cache', { method: 'POST', body: d }).then(r => r.json()).then(res => toast(res.msg));
}
function testOpenRouter(){
  toast('جاري الاختبار...');
  const d = new FormData(); d.append('csrf', CSRF);
  fetch('?action=admin_test_openrouter', { method: 'POST', body: d }).then(r => r.json()).then(res => toast(res.msg));
}
function aiGenerateProduct(){
  const name = document.getElementById('pname').value.trim();
  const price = document.getElementById('pprice').value.trim();
  if (!name) return toast('أدخل اسم المنتج أولاً.');
  toast('جاري التوليد بالذكاء الاصطناعي...');
  const d = new FormData(); d.append('csrf', CSRF); d.append('name', name); d.append('price', price); d.append('gen_image', '1');
  fetch('?action=admin_ai_generate', { method: 'POST', body: d }).then(r => r.json()).then(res => {
    if (!res.ok) return toast(res.msg);
    document.getElementById('pdesc').value = res.description || '';
    if (res.meta_description) document.getElementById('pmeta').value = res.meta_description;
    if (res.image) document.getElementById('pimage').value = res.image;
    toast(res.image ? 'تم توليد الوصف والصورة وSEO بنجاح' : 'تم توليد الوصف وSEO' + (res.image_error ? ' (تعذر توليد الصورة: ' + res.image_error + ')' : ''));
  });
}
function aiGenerateApp(){
  const name = document.getElementById('apname').value.trim();
  const kind = document.getElementById('apkind').value;
  if (!name) return toast('أدخل اسم التطبيق أولاً.');
  toast('جاري التوليد بالذكاء الاصطناعي...');
  const d = new FormData(); d.append('csrf', CSRF); d.append('name', name); d.append('kind', kind);
  fetch('?action=admin_ai_generate_app', { method: 'POST', body: d }).then(r => r.json()).then(res => {
    if (!res.ok) return toast(res.msg);
    if (res.short_description) document.getElementById('apshort').value = res.short_description;
    if (res.description) document.getElementById('apdesc').value = res.description;
    if (res.changelog) document.getElementById('apchangelog').value = res.changelog;
    if (res.permissions) document.getElementById('appermissions').value = res.permissions;
    if (res.category) document.getElementById('apcategory').value = res.category;
    if (res.seo_title) document.getElementById('apseotitle').value = res.seo_title;
    if (res.seo_description) document.getElementById('apseodesc').value = res.seo_description;
    if (res.seo_keywords) document.getElementById('apseokw').value = res.seo_keywords;
    toast('تم توليد المحتوى بنجاح بالذكاء الاصطناعي');
  });
}
function aiGenerateAppIcon(){
  const name = document.getElementById('apname').value.trim();
  const kind = document.getElementById('apkind').value;
  if (!name) return toast('أدخل اسم التطبيق أولاً.');
  toast('جاري توليد الأيقونة...');
  const d = new FormData(); d.append('csrf', CSRF); d.append('name', name); d.append('kind', kind);
  fetch('?action=admin_ai_generate_app_icon', { method: 'POST', body: d }).then(r => r.json()).then(res => {
    if (!res.ok) return toast(res.msg);
    document.getElementById('apicon').value = res.url;
    toast('تم توليد الأيقونة بنجاح');
  });
}
function appVote(id, dir){
  const d = new FormData(); d.append('csrf', CSRF); d.append('id', id); d.append('dir', dir);
  fetch('?action=app_vote', { method: 'POST', body: d }).then(r => r.json()).then(res => {
    if (!res.ok) return toast(res.msg);
    document.getElementById('appLikeCount').textContent = res.likes.toLocaleString();
    document.getElementById('appDislikeCount').textContent = res.dislikes.toLocaleString();
    document.getElementById('appVoteUp').disabled = true;
    document.getElementById('appVoteDown').disabled = true;
    toast('شكراً لتقييمك!');
  });
}
function appNotifySubscribe(btn){
  localStorage.setItem('app_notify_' + window.location.search, '1');
  btn.disabled = true;
  btn.innerHTML = btn.innerHTML.replace('اشترك في التحديثات', 'تم تفعيل التنبيهات');
  toast('تم تفعيل تنبيهات التحديثات لهذا التطبيق');
}
function copyAddr(btn){
  const addr = btn.getAttribute('data-addr');
  navigator.clipboard.writeText(addr).then(() => {
    btn.classList.add('copied');
    toast('تم نسخ العنوان');
    setTimeout(() => btn.classList.remove('copied'), 1500);
  });
}
</script>
</body>
</html>
