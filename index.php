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
    // إعادة فحص/إنشاء الجداول وضبط الإعدادات الافتراضية على كل طلب يضيف عشرات الاستعلامات الزائدة
    // ويُبطّئ الموقع بشكل كبير خصوصاً مع قاعدة بيانات بعيدة؛ نكتفي بتنفيذه فعلياً مرة كل دقيقتين كحد أقصى.
    $marker = __DIR__ . '/uploads/cache/.migrated';
    if (is_file($marker) && (time() - filemtime($marker)) < 120) return;
    @touch($marker);

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
    "CREATE TABLE IF NOT EXISTS wishlist (
        id $id,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
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
    "CREATE TABLE IF NOT EXISTS providers (
        id $id,
        name VARCHAR(120) NOT NULL,
        public_key VARCHAR(255) NULL,
        private_key VARCHAR(255) NULL,
        db_driver VARCHAR(20) NOT NULL DEFAULT 'mysql',
        db_host VARCHAR(255) NULL,
        db_port VARCHAR(10) NULL,
        db_name VARCHAR(120) NULL,
        db_user VARCHAR(120) NULL,
        db_pass VARCHAR(255) NULL,
        active TINYINT NOT NULL DEFAULT 1,
        last_test_result VARCHAR(255) NULL,
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
        'site_name' => 'Yassota Store',
        'site_description' => 'منصة Yassota للتسوق وكسب العملات الرقمية مجاناً',
        'site_keywords' => 'متجر,تسوق,أرباح,عملات,Yassota',
        'logo_url' => '',
        'banner_title' => 'مرحباً بك في Yassota',
        'banner_subtitle' => 'تسوّق، اربح نقاط، واسحبها أموالاً حقيقية',
        'banner_bg_image' => '',
        'footer_text' => '',
        'buy_button_text' => 'طلب شراء',
        'empty_products_text' => 'لا توجد منتجات حالياً، تابعنا قريباً',
        'theme_accent_color' => '#e6294b',
        'theme_accent2_color' => '#ff4d4d',
        'satofill_markup_percent' => '15',
        'satofill_api_base' => 'https://satofill.com/api',
        'referral_bonus_points' => '100',
        'daily_bonus_points' => '20',
        'points_rate' => '0.001',      // 1 نقطة = كم دولار
        'min_withdraw_usd' => '25',
        'auto_approve_withdraw' => '0',
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
        'entry_ad_enabled' => '1',
        'ad_zone_id' => '11185011',
        'support_telegram' => '@layos_he',
        'google_site_verification' => '',
        'adsense_enabled' => '0',
        'adsense_publisher_id' => '',
        'ads_txt_content' => '',
        'openrouter_api_key' => '',
        'openrouter_model' => 'meta-llama/llama-3.3-70b-instruct:free',
        'openrouter_image_model' => '',
        'product_image_height' => '130',
        'cat_tile_size' => '140',
        'telegram_bot_username' => '',
        'banner_interval' => '4000',
        'banner_height' => '160',
        'home_sections_order' => 'hero,search,carousel,cat_tiles,cat_chips,ticker,live_ticker,products,soon',
        'home_sections_hidden' => '',
        'satofill_public_key' => '',
        'satofill_private_key' => '',
    ];
    $stmt = $pdo->prepare("INSERT INTO settings (k, v) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM settings WHERE k = ?)");
    foreach ($defaults as $k => $v) $stmt->execute([$k, $v, $k]);
    // إصلاح ترتيب الرئيسية القديم: نقل قائمة الأقسام تحت البنرات بدلاً من فوقها، فقط إذا لم يخصص الأدمن ترتيباً مختلفاً عن الافتراضي القديم
    if (setting('home_sections_order') === 'hero,search,cat_tiles,cat_chips,carousel,ticker,live_ticker,products,soon') {
        set_setting('home_sections_order', 'hero,search,carousel,cat_tiles,cat_chips,ticker,live_ticker,products,soon');
    }

    $pagesSeed = [
        'privacy' => 'سياسة الخصوصية الخاصة بمنصة Yassota...',
        'terms' => 'شروط الاستخدام الخاصة بمنصة Yassota...',
        'about' => 'Yassota منصة عربية للتسوق وكسب النقاط مقابل إنجاز مهام بسيطة، نلتزم بالشفافية والجودة في جميع خدماتنا.',
        'contact' => 'لأي استفسار أو دعم فني يمكنك التواصل معنا عبر تيليجرام: ' . ($defaults['support_telegram'] ?? '@layos_he') . ' وسيتم الرد عليك في أقرب وقت ممكن.',
        'faq' => "س: كيف أشحن منتجاً؟\nج: اختر المنتج واضغط طلب شراء، ثم أكمل عملية الدفع وأرفق إيصال التحويل.\n\nس: كم تستغرق معالجة الطلب؟\nج: عادة بين دقائق وحتى 24 ساعة بحسب نوع المنتج.\n\nس: كيف أسحب أرباحي؟\nج: من صفحة المحفظة بعد الوصول للحد الأدنى للسحب، وعبر USDT أو الشام كاش.\n\nس: نسيت كلمة المرور، ماذا أفعل؟\nج: استخدم رابط استعادة كلمة المرور من صفحة تسجيل الدخول.",
    ];
    foreach ($pagesSeed as $slug => $content) {
        $st = $pdo->prepare("INSERT INTO pages (slug, content) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM pages WHERE slug = ?)");
        $st->execute([$slug, $content, $slug]);
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
    // حذف صفوف محافظ فاسدة بالكامل (بلا نوع وبلا عنوان ظاهري)
    $pdo->exec("DELETE FROM wallets WHERE (type IS NULL OR type='') AND (label IS NULL OR label='')");
    // أي محفظة بعنوان فارغ أو لا تزال تحمل نص العنصر النائب الافتراضي يجب أن تكون معطّلة دائماً، بغض النظر عن حالتها السابقة
    $pdo->prepare("UPDATE wallets SET active=0 WHERE active=1 AND (address IS NULL OR address='' OR address=?)")
        ->execute(['ضع رقم محفظتك من لوحة الإدارة']);
    // حذف التكرار التام (نفس النوع ونفس العنوان)، مع الاحتفاظ بالصف الأقدم فقط
    $dupPairs = $pdo->query("SELECT type, address FROM wallets WHERE address IS NOT NULL AND address<>'' GROUP BY type, address HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dupPairs as $pair) {
        $st = $pdo->prepare("SELECT id FROM wallets WHERE type=? AND address=? ORDER BY id ASC");
        $st->execute([$pair['type'], $pair['address']]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        array_shift($ids);
        if ($ids) {
            $pdo->prepare("DELETE FROM wallets WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")
                ->execute($ids);
        }
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
            ['تسوّق الآن واربح نقاطاً', '#e6294b', '#ff4d4d'],
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

    $productCount = (int)$pdo->query("SELECT COUNT(*) c FROM products")->fetch()['c'];
    if ($productCount === 0) {
        $seedCats = ['الألعاب', 'بطاقات الهدايا', 'الاشتراكات', 'التطبيقات والخدمات', 'عام'];
        $catIds = [];
        foreach ($seedCats as $i => $cname) {
            $pdo->prepare("INSERT INTO categories (name, sort_order) VALUES (?,?)")->execute([$cname, $i]);
            $catIds[$cname] = $pdo->lastInsertId();
        }
        $seedProducts = [
            // [name, icon, price, old_price, category]
            ['شحن ببجي موبايل 60 UC', '🎮', 1.5, null, 'الألعاب'],
            ['شحن ببجي موبايل 325 UC', '🎮', 7, 8, 'الألعاب'],
            ['شحن ببجي موبايل 660 UC', '🎮', 14, 16, 'الألعاب'],
            ['شحن فري فاير 100 جوهرة', '💎', 1.2, null, 'الألعاب'],
            ['شحن فري فاير 520 جوهرة', '💎', 6, 7, 'الألعاب'],
            ['شحن فورتنايت 1000 V-Bucks', '🎮', 9, null, 'الألعاب'],
            ['شحن كول أوف ديوتي موبايل 80 CP', '🔫', 1.5, null, 'الألعاب'],
            ['شحن ليج أوف ليجندز RP', '🎮', 5, null, 'الألعاب'],
            ['شحن جواهر كلاش أوف كلانس', '💎', 4, 5, 'الألعاب'],
            ['شحن نقاط فيفا موبايل', '⚽', 6, null, 'الألعاب'],
            ['شحن روبلوكس 400 Robux', '🧩', 5, 6, 'الألعاب'],
            ['شحن ماين كرافت كوينز', '⛏️', 3, null, 'الألعاب'],
            ['شحن جواكر شدات', '♠️', 2, null, 'الألعاب'],
            ['شحن سوبر سيل جواهر', '💎', 3, null, 'الألعاب'],
            ['شحن جواهر متفرقة (عام)', '🎮', 2, null, 'الألعاب'],
            ['بطاقة آيتونز 10$', '🍏', 11, null, 'بطاقات الهدايا'],
            ['بطاقة آيتونز 25$', '🍏', 27, 30, 'بطاقات الهدايا'],
            ['بطاقة جوجل بلاي 10$', '▶️', 11, null, 'بطاقات الهدايا'],
            ['بطاقة جوجل بلاي 25$', '▶️', 27, 30, 'بطاقات الهدايا'],
            ['بطاقة ستيم 10$', '🎮', 11, null, 'بطاقات الهدايا'],
            ['بطاقة ستيم 20$', '🎮', 22, 25, 'بطاقات الهدايا'],
            ['بطاقة أمازون 25$', '📦', 27, null, 'بطاقات الهدايا'],
            ['بطاقة بلايستيشن 10$', '🎮', 11, null, 'بطاقات الهدايا'],
            ['بطاقة إكس بوكس 10$', '🎮', 11, null, 'بطاقات الهدايا'],
            ['بطاقة نتفليكس هدية', '🎬', 15, null, 'بطاقات الهدايا'],
            ['اشتراك نتفليكس شهر', '🎬', 5, 6, 'الاشتراكات'],
            ['اشتراك نتفليكس 3 أشهر', '🎬', 13, 16, 'الاشتراكات'],
            ['اشتراك شاهد VIP شهر', '📺', 4, null, 'الاشتراكات'],
            ['اشتراك سبوتيفاي بريميوم شهر', '🎵', 3, null, 'الاشتراكات'],
            ['اشتراك يوتيوب بريميوم شهر', '▶️', 3, null, 'الاشتراكات'],
            ['اشتراك ديزني بلس شهر', '🏰', 5, null, 'الاشتراكات'],
            ['اشتراك كانفا برو شهر', '🎨', 4, null, 'الاشتراكات'],
            ['اشتراك ChatGPT Plus شهر', '🤖', 20, null, 'الاشتراكات'],
            ['اشتراك مايكروسوفت 365 شهر', '💼', 6, null, 'الاشتراكات'],
            ['اشتراك آيكلود تخزين 50GB', '☁️', 1, null, 'الاشتراكات'],
            ['شحن تيك توك كوينز', '🎵', 2, null, 'التطبيقات والخدمات'],
            ['متابعين انستقرام 1000', '📷', 4, 5, 'التطبيقات والخدمات'],
            ['اشتراك تيليجرام بريميوم شهر', '✈️', 4, null, 'التطبيقات والخدمات'],
            ['اشتراك ديسكورد نيترو شهر', '🎮', 5, null, 'التطبيقات والخدمات'],
            ['اشتراك زووم برو شهر', '📹', 7, null, 'التطبيقات والخدمات'],
            ['حماية كاسبرسكي سنة', '🛡️', 15, 18, 'التطبيقات والخدمات'],
            ['اشتراك VPN بريميوم شهر', '🔒', 3, null, 'التطبيقات والخدمات'],
            ['اشتراك أدوبي فوتوشوب شهر', '🖌️', 10, null, 'التطبيقات والخدمات'],
            ['اشتراك WPS Office بريميوم', '📄', 5, null, 'التطبيقات والخدمات'],
            ['اشتراك Grammarly بريميوم', '✍️', 10, 12, 'التطبيقات والخدمات'],
            ['شحن رصيد سيرياتيل 5$', '📱', 5.5, null, 'عام'],
            ['شحن رصيد MTN سوريا 5$', '📱', 5.5, null, 'عام'],
            ['بطاقة Visa افتراضية 10$', '💳', 11, null, 'عام'],
            ['قسيمة شحن عام 5$', '🎁', 5.5, null, 'عام'],
            ['قسيمة شحن عام 10$', '🎁', 11, 12, 'عام'],
        ];
        $ins = $pdo->prepare("INSERT INTO products (name, icon, price, old_price, category_id, status) VALUES (?,?,?,?,?,'active')");
        foreach ($seedProducts as [$pname, $picon, $price, $oldPrice, $pcat]) {
            $ins->execute([$pname, $picon, $price, $oldPrice, $catIds[$pcat] ?? null]);
        }
    }

    // منتجات مطلوبة بكثرة (تُضاف مرة واحدة فقط لكل منتج، ولا تُكرَّر حتى على موقع يعمل مسبقاً)
    $reqCatNames = ['الألعاب', 'بطاقات الهدايا', 'الاشتراكات', 'التطبيقات والخدمات', 'عام'];
    $reqCatIds = [];
    foreach ($reqCatNames as $cn) {
        $st = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
        $st->execute([$cn]);
        $row = $st->fetch();
        if ($row) { $reqCatIds[$cn] = $row['id']; continue; }
        $pdo->prepare("INSERT INTO categories (name, sort_order) VALUES (?, 99)")->execute([$cn]);
        $reqCatIds[$cn] = $pdo->lastInsertId();
    }
    $highDemandProducts = [
        ['شحن ببجي موبايل 1800 UC', '🎮', 38, 42, 'الألعاب'],
        ['شحن ببجي موبايل 3850 UC', '🎮', 76, 85, 'الألعاب'],
        ['شحن فري فاير 1080 جوهرة', '💎', 12, 14, 'الألعاب'],
        ['نجوم تيليجرام Telegram Stars', '✈️', 4, null, 'الألعاب'],
        ['شحن جواهر جواكر 100 ألف', '♠️', 6, null, 'الألعاب'],
        ['اشتراك بلايستيشن بلس شهر', '🎮', 12, 14, 'الاشتراكات'],
        ['اشتراك Xbox Game Pass Ultimate شهر', '🎮', 14, 16, 'الاشتراكات'],
        ['اشتراك أمازون برايم شهر', '📦', 7, null, 'الاشتراكات'],
        ['اشتراك سبوتيفاي عائلي شهر', '🎵', 8, 10, 'الاشتراكات'],
        ['اشتراك يوتيوب بريميوم عائلي', '▶️', 9, 11, 'الاشتراكات'],
        ['اشتراك آبل ميوزك شهر', '🎵', 4, null, 'الاشتراكات'],
        ['اشتراك ديسكورد نيترو سنة', '🎮', 45, 50, 'التطبيقات والخدمات'],
        ['متابعين تيك توك 1000', '🎵', 4, 5, 'التطبيقات والخدمات'],
        ['لايكات انستقرام 1000', '📷', 2, null, 'التطبيقات والخدمات'],
        ['اشتراك X (تويتر) بريميوم شهر', '🐦', 8, null, 'التطبيقات والخدمات'],
        ['اشتراك LinkedIn بريميوم شهر', '💼', 12, null, 'التطبيقات والخدمات'],
        ['اشتراك Duolingo Super شهر', '🦉', 5, null, 'التطبيقات والخدمات'],
        ['بطاقة Google Play 50$', '▶️', 53, 58, 'بطاقات الهدايا'],
        ['بطاقة ستيم 50$', '🎮', 53, 58, 'بطاقات الهدايا'],
        ['شحن رصيد سيرياتيل 10$', '📱', 10.5, null, 'عام'],
    ];
    $exists = $pdo->prepare("SELECT 1 FROM products WHERE name = ?");
    $insReq = $pdo->prepare("INSERT INTO products (name, icon, price, old_price, category_id, status) VALUES (?,?,?,?,?,'active')");
    foreach ($highDemandProducts as [$pname, $picon, $price, $oldPrice, $pcat]) {
        $exists->execute([$pname]);
        if ($exists->fetch()) continue;
        $insReq->execute([$pname, $picon, $price, $oldPrice, $reqCatIds[$pcat] ?? null]);
    }

    // تكملة كل قسم حتى يصل لحوالي 20 منتجاً (إضافة مرة واحدة فقط لكل منتج، حتى على موقع يعمل مسبقاً)
    $catalogFillProducts = [
        ['بطاقة Valorant Points 10$', '🎮', 11, null, 'بطاقات الهدايا'],
        ['بطاقة فري فاير الذهبية', '💎', 6, null, 'بطاقات الهدايا'],
        ['بطاقة Razer Gold 10$', '🎮', 11, null, 'بطاقات الهدايا'],
        ['بطاقة Garena Shells', '🛡️', 6, null, 'بطاقات الهدايا'],
        ['بطاقة eBay 25$', '🛒', 27, null, 'بطاقات الهدايا'],
        ['بطاقة Walmart 25$', '🛒', 27, null, 'بطاقات الهدايا'],
        ['بطاقة Target 25$', '🎯', 27, null, 'بطاقات الهدايا'],
        ['بطاقة Roblox 25$', '🧩', 27, 30, 'بطاقات الهدايا'],
        ['اشتراك Twitch Turbo شهر', '🎮', 9, null, 'الاشتراكات'],
        ['اشتراك Hulu شهر', '🎬', 6, null, 'الاشتراكات'],
        ['اشتراك HBO Max شهر', '🎬', 8, null, 'الاشتراكات'],
        ['اشتراك Audible شهر', '🎧', 8, null, 'الاشتراكات'],
        ['اشتراك NordVPN سنة', '🔒', 35, 40, 'الاشتراكات'],
        ['شحن متابعين تويتر 1000', '🐦', 4, 5, 'التطبيقات والخدمات'],
        ['شحن مشاهدات يوتيوب 1000', '▶️', 2, null, 'التطبيقات والخدمات'],
        ['اشتراك Notion Plus شهر', '📝', 5, null, 'التطبيقات والخدمات'],
        ['اشتراك Canva Teams شهر', '🎨', 8, null, 'التطبيقات والخدمات'],
        ['شحن رصيد فودافون مصر', '📱', 5.5, null, 'عام'],
        ['شحن رصيد أورنج مصر', '📱', 5.5, null, 'عام'],
        ['شحن رصيد STC السعودية', '📱', 5.5, null, 'عام'],
        ['شحن رصيد موبايلي السعودية', '📱', 5.5, null, 'عام'],
        ['شحن رصيد زين السعودية', '📱', 5.5, null, 'عام'],
        ['شحن رصيد دو الإمارات', '📱', 5.5, null, 'عام'],
        ['شحن رصيد اتصالات الإمارات', '📱', 5.5, null, 'عام'],
        ['شحن رصيد Ooredoo قطر', '📱', 5.5, null, 'عام'],
        ['شحن رصيد Zain العراق', '📱', 5.5, null, 'عام'],
        ['بطاقة Visa افتراضية 25$', '💳', 27, null, 'عام'],
        ['بطاقة Visa افتراضية 50$', '💳', 53, 58, 'عام'],
        ['قسيمة شحن عام 25$', '🎁', 27, null, 'عام'],
        ['قسيمة شحن عام 50$', '🎁', 53, 58, 'عام'],
    ];
    foreach ($catalogFillProducts as [$pname, $picon, $price, $oldPrice, $pcat]) {
        $exists->execute([$pname]);
        if ($exists->fetch()) continue;
        $insReq->execute([$pname, $picon, $price, $oldPrice, $reqCatIds[$pcat] ?? null]);
    }

    // إضافة دفعة بداية من المهام (٣٠ مهمة، الإدارة يمكنها تعديل/حذف/تعطيل أي منها أو إضافة المزيد لاحقاً)
    // فقط إذا كان جدول المهام فارغاً بالكامل (لا نكرر الإضافة كل مرة)
    if (!$pdo->query("SELECT COUNT(*) c FROM tasks")->fetch()['c']) {
        $insTask = $pdo->prepare("INSERT INTO tasks (title, url, seconds, reward) VALUES (?,?,?,?)");
        for ($day = 1; $day <= 7; $day++) {
            for ($n = 1; $n <= 4; $n++) {
                $insTask->execute([
                    "مهمة اليوم $day - رقم $n: شاهد واستعرض المحتوى",
                    '?',
                    20 + ($n * 5),
                    5 + $n,
                ]);
            }
        }
        // مهمتان إضافيتان (المتابعة على وسائل التواصل) لإكمال ٣٠ مهمة
        $insTask->execute(['تابعنا على تيليجرام', '?', 15, 10]);
        $insTask->execute(['شارك الموقع مع صديق', '?', 15, 10]);
    }
}
migrate();

/* ======================================================================
   2) HELPERS
   ====================================================================== */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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

/* ---- Telegram ----
   البوت أصبح عملية مستقلة تعمل على سيرفر VPS منفصل (telegram_bot.php)
   ويستخدم فقط نفس قاعدة البيانات. هذا الملف لا يتصل بـ Telegram API مباشرة إطلاقاً؛
   عند نشر منتج جديد يُكتب سجل في bot_broadcast_queue ليقرأه البوت ويبثه بنفسه. */

/* ---- Satofill API: مزامنة كتالوج المنتجات مع تطبيق نسبة هامش ربح ---- */
function satofill_fetch_catalog(): array
{
    // المفتاح الخاص (التوكن) يُضبط من لوحة الإدارة (تبويب المنتجات)؛ يبقى دعم القيمة القديمة من config.php للتوافق الخلفي
    $token = setting('satofill_private_key') ?: (defined('SATOFILL_API_TOKEN') ? SATOFILL_API_TOKEN : '');
    if (!$token) {
        throw new RuntimeException('لم يتم ضبط توكن Satofill (المفتاح الخاص) من لوحة الإدارة.');
    }
    $base = rtrim(setting('satofill_api_base', 'https://satofill.com/api'), '/');
    $ch = curl_init($base . '/products');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
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
function compress_image_file(string $path, int $maxDim = 1280, int $quality = 75): void
{
    // الصور الكبيرة (مثل أيقونات التطبيقات عالية الدقة) قد تستهلك ذاكرة GD أكثر من الحد الافتراضي
    // وتُجمّد الطلب؛ نرفع الحدود مؤقتاً هنا فقط ثم نعيدها كما كانت بعد الانتهاء.
    $prevMemLimit = @ini_get('memory_limit');
    @ini_set('memory_limit', '512M');
    @set_time_limit(60);
    try {
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
    } finally {
        if ($prevMemLimit !== false) @ini_set('memory_limit', $prevMemLimit);
    }
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
        award_welcome_bonus((int)$uid);
    }
    $_SESSION['uid'] = $uid;
    redirect($isNew ? '?page=welcome' : ('?' . ($role === 'admin' ? 'page=admin' : '')));
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
        award_welcome_bonus((int)$uid);
    }
    $_SESSION['uid'] = $uid;
    redirect($isNew ? '?page=welcome' : ('?' . ($role === 'admin' ? 'page=admin' : '')));
}

// تجزئة بسيطة لعنوان IP + متصفح المستخدم لمنع تكرار الإحالة من نفس الجهاز.
// ليست حماية مطلقة (يمكن تجاوزها بـ VPN/متصفح مختلف) لكنها تردع إعادة التسجيل السريعة.
function device_fingerprint(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
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
    award_welcome_bonus((int)$uid);
    if ($referredBy) {
        // الحماية من احتيال الإحالة: لا مكافأة إذا كان جهاز المستخدم الجديد نفس جهاز الداعي (حساب وهمي على نفس الجهاز)،
        // ولا مكافأة بعد بلوغ الداعي الحد الأقصى لعدد الإحالات المربحة.
        $st = db()->prepare("SELECT signup_fingerprint FROM users WHERE id = ?");
        $st->execute([$referredBy]);
        $referrerFingerprint = $st->fetch()['signup_fingerprint'] ?? null;
        $sameDevice = $referrerFingerprint && $referrerFingerprint === $fingerprint;

        $st = db()->prepare("SELECT COUNT(*) c FROM users WHERE referred_by = ? AND referral_bonus_given = 1");
        $st->execute([$referredBy]);
        $successfulReferrals = (int)$st->fetch()['c'];
        $maxReferrals = (int)setting('referral_max_count', 5);

        if (!$sameDevice && $successfulReferrals < $maxReferrals) {
            $bonus = (int)setting('referral_bonus_points', 100);
            add_points($referredBy, $bonus, 'referral', 'مكافأة دعوة صديق: ' . $username);
            $cut = (int)setting('referral_referred_cut_points', 50);
            if ($cut > 0) add_points((int)$uid, $cut, 'referral', 'مكافأة الانضمام عبر دعوة صديق');
            db()->prepare("UPDATE users SET referral_bonus_given = 1 WHERE id = ?")->execute([$uid]);
        }
    }
    unset($_SESSION['ref_code']);
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
    echo (string)setting('ads_txt_content', '');
    exit;
}

if ($action === 'sitemap') {
    header('Content-Type: application/xml; charset=utf-8');
    $base = rtrim(SITE_URL, '/') . '/index.php';
    $urls = [
        ['loc' => $base, 'priority' => '1.0'],
        ['loc' => $base . '?page=privacy', 'priority' => '0.3'],
        ['loc' => $base . '?page=terms', 'priority' => '0.3'],
    ];
    $products = db()->query("SELECT id, created_at FROM products WHERE status='active'")->fetchAll();
    foreach ($products as $p) {
        $urls[] = ['loc' => $base . '?page=product&id=' . (int)$p['id'], 'priority' => '0.8', 'lastmod' => substr((string)$p['created_at'], 0, 10)];
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

/* ---- JSON API actions (AJAX) ---- */
if ($action && str_starts_with($action, 'api_')) {
    header('Content-Type: application/json; charset=utf-8');
    $u = current_user();

    if (!$u && !in_array($action, ['api_ping'])) {
        echo json_encode(['ok' => false, 'msg' => 'يجب تسجيل الدخول أولاً.']); exit;
    }

    switch ($action) {
        case 'api_claim_daily_bonus':
            csrf_check();
            $today = date('Y-m-d');
            if ($u['last_bonus_date'] === $today) { echo json_encode(['ok' => false, 'msg' => 'لقد حصلت على مكافأتك اليوم.']); exit; }
            $amt = (int)setting('daily_bonus_points', 20);
            add_points((int)$u['id'], $amt, 'daily_bonus', 'مكافأة تسجيل دخول يومية');
            db()->prepare("UPDATE users SET last_bonus_date = ? WHERE id = ?")->execute([$today, $u['id']]);
            echo json_encode(['ok' => true, 'msg' => "تم إضافة +{$amt} عملة!"]); exit;

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
            $bonusMsg = '';
            if ($newBio !== '' && !$u['bonus_bio_claimed']) {
                $bonus = (int)setting('bio_bonus_points', 100);
                add_points((int)$u['id'], $bonus, 'bio_bonus', 'إضافة نبذة شخصية');
                db()->prepare("UPDATE users SET bonus_bio_claimed=1 WHERE id=?")->execute([$u['id']]);
                $bonusMsg .= " +{$bonus} عملة (النبذة)";
            }
            $st = db()->prepare("SELECT bonus_profile_claimed FROM users WHERE id=?"); $st->execute([$u['id']]); $claimed = (int)$st->fetch()['bonus_profile_claimed'];
            if (!$claimed && $newBio !== '' && $u['avatar']) {
                $bonus2 = (int)setting('profile_complete_bonus_points', 350);
                add_points((int)$u['id'], $bonus2, 'profile_complete_bonus', 'اكتمال الملف الشخصي');
                db()->prepare("UPDATE users SET bonus_profile_claimed=1 WHERE id=?")->execute([$u['id']]);
                $bonusMsg .= " +{$bonus2} عملة (اكتمال الملف)";
            }
            echo json_encode(['ok' => true, 'msg' => 'تم حفظ التعديلات.' . $bonusMsg]); exit;

        case 'api_submit_suggestion':
            csrf_check();
            $title = trim($_POST['title'] ?? '');
            $details = mb_substr(trim($_POST['details'] ?? ''), 0, 500);
            if ($title === '') { echo json_encode(['ok' => false, 'msg' => 'أدخل اسم المنتج المقترح.']); exit; }
            db()->prepare("INSERT INTO product_suggestions (user_id, title, details) VALUES (?,?,?)")->execute([$u['id'], $title, $details]);
            echo json_encode(['ok' => true, 'msg' => 'تم إرسال اقتراحك، شكراً لك!']); exit;

        case 'api_spin_wheel':
            csrf_check();
            $today = date('Y-m-d');
            $maxPerDay = (int)setting('spin_max_per_day', 1);
            if ($maxPerDay > 0 && $u['last_spin_date'] === $today) { echo json_encode(['ok' => false, 'msg' => 'لقد استخدمت دورتك اليوم، عُد غداً.']); exit; }
            $min = (int)setting('spin_reward_min', 5);
            $max = (int)setting('spin_reward_max', 200);
            $won = random_int($min, $max);
            add_points((int)$u['id'], $won, 'spin', 'عجلة الحظ');
            db()->prepare("UPDATE users SET last_spin_date=? WHERE id=?")->execute([$today, $u['id']]);
            echo json_encode(['ok' => true, 'won' => $won, 'msg' => "ربحت {$won} عملة!"]); exit;

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

            $usd_balance = points_to_usd($u['points']);
            if ($usd_balance < $finalPrice) {
                echo json_encode(['ok' => false, 'msg' => 'رصيدك غير كافٍ لإتمام الشراء، يمكنك شحن المحفظة من صفحة "محفظتي".']); exit;
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
            // لا تُمنح المكافأة فوراً؛ يجب الانتظار/مشاهدة الإعلان أولاً عبر api_claim_captcha_reward
            $_SESSION['captcha_verified_at'] = time();
            $adWillShow = setting('ad_enabled', '1') === '1' && setting('ad_zone_id', '') !== '';
            echo json_encode(['ok' => true, 'msg' => 'صحيح! انتظر قليلاً للحصول على المكافأة.', 'ad_will_show' => $adWillShow, 'wait_seconds' => 5]);
            exit;

        case 'api_claim_captcha_reward':
            csrf_check();
            $verifiedAt = $_SESSION['captcha_verified_at'] ?? null;
            if (!$verifiedAt || (time() - $verifiedAt) < 5 || (time() - $verifiedAt) > 120) {
                echo json_encode(['ok' => false, 'msg' => 'يجب إكمال الانتظار أولاً.']); exit;
            }
            unset($_SESSION['captcha_verified_at']);
            $day = date('Y-m-d');
            $st = db()->prepare("SELECT * FROM captcha_logs WHERE user_id=? AND day=?");
            $st->execute([$u['id'], $day]);
            $log = $st->fetch();
            $count = $log['count'] ?? 0;
            $max = (int)setting('captcha_max_per_day', 40);
            if ($count >= $max) { echo json_encode(['ok' => false, 'msg' => 'وصلت للحد اليومي من الكابتشا.']); exit; }
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
            $autoApprove = setting('auto_approve_withdraw', '0') === '1';
            $wstatus = $autoApprove ? 'approved' : 'pending';
            db()->prepare("INSERT INTO withdraw_requests (user_id, amount_points, amount_usd, wallet_type, wallet_address, status) VALUES (?,?,?,?,?,?)")
                ->execute([$u['id'], $u['points'], $usd, $u['wallet_type'], $u['wallet_address'], $wstatus]);
            db()->prepare("UPDATE users SET points = 0 WHERE id = ?")->execute([$u['id']]);
            echo json_encode(['ok' => true, 'msg' => $autoApprove ? 'تم تحويل المبلغ فوراً.' : 'تم إرسال طلب السحب، بانتظار مراجعة الإدارة.']);
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
    global $pdo;
    return $pdo->query("SELECT * FROM openrouter_keys WHERE active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
}

function openrouter_mark_error(int $keyId, string $msg): void
{
    global $pdo;
    $pdo->prepare("UPDATE openrouter_keys SET last_error=? WHERE id=?")->execute([mb_substr($msg, 0, 255), $keyId]);
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

function openrouter_chat(string $prompt): array
{
    $model = setting('openrouter_model', 'meta-llama/llama-3.3-70b-instruct:free');
    $r = openrouter_request([
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ]);
    if (!$r['ok']) return $r;
    $data = $r['data'];
    if (empty($data['choices'][0]['message']['content'])) {
        return ['ok' => false, 'msg' => $data['error']['message'] ?? 'استجابة غير متوقعة من OpenRouter.'];
    }
    return ['ok' => true, 'text' => $data['choices'][0]['message']['content']];
}

function openrouter_image(string $prompt): array
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
    $destDir = __DIR__ . '/uploads/products';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    file_put_contents($destDir . '/' . $filename, $bytes);
    compress_image_file($destDir . '/' . $filename, 1280, 78);
    return ['ok' => true, 'url' => 'uploads/products/' . $filename];
}

if ($action === 'admin_test_openrouter') {
    require_admin();
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $r = openrouter_chat('قل "تم الاتصال بنجاح" فقط بدون أي إضافات.');
    if (!$r['ok']) { echo json_encode(['ok' => false, 'msg' => $r['msg']], JSON_INVALID_UTF8_SUBSTITUTE); exit; }
    echo json_encode(['ok' => true, 'msg' => 'الاتصال يعمل بنجاح: ' . trim($r['text'])], JSON_INVALID_UTF8_SUBSTITUTE);
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
    if (!$r['ok']) { echo json_encode(['ok' => false, 'msg' => $r['msg']], JSON_INVALID_UTF8_SUBSTITUTE); exit; }
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
    echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
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

        case 'admin_save_provider':
            $pName = trim((string)($_POST['name'] ?? ''));
            if ($pName === '') { flash('اسم المزوّد مطلوب.'); redirect('?page=admin&tab=providers'); }
            $pid = (int)($_POST['id'] ?? 0);
            $publicKey = trim((string)($_POST['public_key'] ?? ''));
            $privateKey = (string)($_POST['private_key'] ?? '');
            $dbPass = (string)($_POST['db_pass'] ?? '');
            $fields = [
                'name' => $pName,
                'public_key' => $publicKey,
                'db_driver' => in_array($_POST['db_driver'] ?? '', ['mysql', 'pgsql', 'sqlite']) ? $_POST['db_driver'] : 'mysql',
                'db_host' => trim((string)($_POST['db_host'] ?? '')),
                'db_port' => trim((string)($_POST['db_port'] ?? '')),
                'db_name' => trim((string)($_POST['db_name'] ?? '')),
                'db_user' => trim((string)($_POST['db_user'] ?? '')),
            ];
            if ($pid > 0) {
                $sets = [];
                $vals = [];
                foreach ($fields as $k => $v) { $sets[] = "$k=?"; $vals[] = $v; }
                if ($privateKey !== '') { $sets[] = 'private_key=?'; $vals[] = $privateKey; }
                if ($dbPass !== '') { $sets[] = 'db_pass=?'; $vals[] = $dbPass; }
                $vals[] = $pid;
                db()->prepare("UPDATE providers SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
                flash('تم تحديث المزوّد.');
            } else {
                db()->prepare("INSERT INTO providers (name, public_key, private_key, db_driver, db_host, db_port, db_name, db_user, db_pass) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$fields['name'], $fields['public_key'], $privateKey, $fields['db_driver'], $fields['db_host'], $fields['db_port'], $fields['db_name'], $fields['db_user'], $dbPass]);
                flash('تمت إضافة المزوّد.');
            }
            redirect('?page=admin&tab=providers');

        case 'admin_toggle_provider':
            db()->prepare("UPDATE providers SET active = 1 - active WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=providers');

        case 'admin_delete_provider':
            db()->prepare("DELETE FROM providers WHERE id=?")->execute([(int)$_POST['id']]);
            redirect('?page=admin&tab=providers');

        case 'admin_test_provider_connection':
            $prov = db()->prepare("SELECT * FROM providers WHERE id=?");
            $prov->execute([(int)$_POST['id']]);
            $prov = $prov->fetch();
            if (!$prov) { flash('المزوّد غير موجود.'); redirect('?page=admin&tab=providers'); }
            $result = 'فشل: بيانات الاتصال ناقصة.';
            if ($prov['db_host'] && $prov['db_name'] && $prov['db_user']) {
                try {
                    if ($prov['db_driver'] === 'sqlite') {
                        new PDO('sqlite:' . $prov['db_name']);
                    } else {
                        $port = $prov['db_port'] ? ';port=' . $prov['db_port'] : '';
                        $dsn = $prov['db_driver'] . ':host=' . $prov['db_host'] . $port . ';dbname=' . $prov['db_name'];
                        new PDO($dsn, $prov['db_user'], (string)$prov['db_pass'], [PDO::ATTR_TIMEOUT => 5]);
                    }
                    $result = 'نجح الاتصال بنجاح ✓';
                } catch (Throwable $e) {
                    $result = 'فشل الاتصال: تحقق من بيانات الاعتماد والشبكة.';
                }
            }
            db()->prepare("UPDATE providers SET last_test_result=? WHERE id=?")->execute([$result, (int)$prov['id']]);
            flash($result);
            redirect('?page=admin&tab=providers');

        case 'admin_save_home_sections':
            set_setting('home_sections_order', preg_replace('/[^a-z_,]/', '', $_POST['order'] ?? ''));
            set_setting('home_sections_hidden', preg_replace('/[^a-z_,]/', '', $_POST['hidden'] ?? ''));
            flash('تم حفظ تخطيط الصفحة الرئيسية.');
            redirect('?page=admin&tab=homepage');

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

        case 'admin_save_settings':
            $redirectTab = preg_replace('/[^a-z_]/', '', $_POST['redirect_tab'] ?? 'settings') ?: 'settings';
            foreach ($_POST as $k => $v) {
                if ($k === 'action' || $k === 'csrf' || $k === 'redirect_tab') continue;
                if ($k === 'bot_token' && $v === '') continue; // حقل التوكن لا يُفرَّغ إذا تُرك خالياً
                if ($k === 'satofill_private_key' && $v === '') continue; // مفتاح Satofill الخاص لا يُفرَّغ إذا تُرك خالياً
                set_setting($k, $v);
            }
            flash('تم حفظ الإعدادات.');
            redirect('?page=admin&tab=' . $redirectTab);

        case 'admin_clear_cache':
            $cacheDir = __DIR__ . '/uploads/cache';
            $cleared = 0;
            if (is_dir($cacheDir)) {
                foreach (glob($cacheDir . '/*') as $f) {
                    if (is_file($f) && @unlink($f)) $cleared++;
                }
            }
            if (function_exists('opcache_reset')) @opcache_reset();
            clearstatcache();
            flash("تم تفريغ الذاكرة المؤقتة بنجاح ($cleared ملف محذوف).");
            redirect('?page=admin&tab=settings');

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
if ($page === 'login' && $user) { redirect('?'); }
$siteName = setting('site_name');
$logo = setting('logo_url');
$activeWallets = db()->query("SELECT * FROM wallets WHERE active=1")->fetchAll();
$userUsdBalance = $user ? points_to_usd($user['points']) : 0;

/* ---- SEO: per-page title / description / canonical / structured data ---- */
$seoProduct = null;
if ($page === 'product') {
    $st = db()->prepare("SELECT * FROM products WHERE id=? AND status='active'");
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $seoProduct = $st->fetch();
    if (!$seoProduct) { http_response_code(404); }
}
$pageLabels = ['home' => 'الرئيسية', 'earn' => 'اكسب عملات', 'tasks' => 'المهام اليومية', 'wallet' => 'محفظتي', 'orders' => 'طلباتي', 'privacy' => 'سياسة الخصوصية', 'terms' => 'شروط الاستخدام', 'welcome' => 'مرحباً بك', 'admin' => 'لوحة الإدارة'];
if ($seoProduct) {
    $seoTitle = $seoProduct['name'] . ' — ' . e($siteName);
    $seoDesc = $seoProduct['meta_description'] ?: mb_substr((string)$seoProduct['description'], 0, 155);
    $seoImage = $seoProduct['image'] ?: $logo;
    $seoCanonical = rtrim(SITE_URL, '/') . '/index.php?page=product&id=' . (int)$seoProduct['id'];
} elseif (in_array($page, ['privacy', 'terms'], true)) {
    $pg = db()->prepare("SELECT * FROM pages WHERE slug=?"); $pg->execute([$page]); $pgRow = $pg->fetch();
    $seoTitle = ($pgRow['meta_title'] ?? '') ?: ($pageLabels[$page] . ' — ' . $siteName);
    $seoDesc = ($pgRow['meta_description'] ?? '') ?: setting('site_description');
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
<?php if (setting('adsense_enabled', '0') === '1' && setting('adsense_publisher_id')): ?><script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=<?= e(setting('adsense_publisher_id')) ?>" crossorigin="anonymous"></script><?php endif; ?>
<meta property="og:type" content="<?= $seoProduct ? 'product' : 'website' ?>">
<meta property="og:title" content="<?= e($seoTitle) ?>">
<meta property="og:description" content="<?= e($seoDesc) ?>">
<?php if ($seoImage): ?><meta property="og:image" content="<?= e($seoImage) ?>"><?php endif; ?>
<?php if ($logo): ?><link rel="icon" href="<?= e($logo) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($seoCanonical) ?>">
<?php if ($seoProduct): ?>
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
:root{--bg:#000000;--bg2:#121212;--card:#161616;--card2:#1c1c1c;--accent:#fe2c55;--accent-d:#c41f42;--accent2:#25f4ee;--accent2-d:#0fc7c1;--gold:#ffc233;--text:#fefefe;--muted:#86878b;--danger:#fe2c55;--radius:14px;--shadow:0 10px 30px rgba(0,0,0,.6);--glow:0 0 0 1px rgba(254,44,85,.35),0 8px 30px rgba(254,44,85,.2);--ease:cubic-bezier(.22,1,.36,1)}
*{box-sizing:border-box;margin:0;padding:0}
*::selection{background:var(--accent);color:#fff}
html{scroll-behavior:smooth}
body{font-family:'Segoe UI',Tahoma,Arial,sans-serif;color:var(--text);min-height:100vh;background:var(--bg);background-image:radial-gradient(1200px 600px at 100% -10%,rgba(254,44,85,.10),transparent 60%),radial-gradient(1000px 600px at -10% 10%,rgba(37,244,238,.06),transparent 55%);background-attachment:fixed}
a{color:inherit;text-decoration:none}
::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:#2a2a2a;border-radius:10px;border:2px solid var(--bg)}
::-webkit-scrollbar-thumb:hover{background:var(--accent)}
#preloader{position:fixed;inset:0;background:radial-gradient(900px 500px at 50% 0%,rgba(230,41,75,.14),transparent 60%),var(--bg);z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;transition:opacity .4s}
.pl-ring{position:relative;width:108px;height:108px;display:flex;align-items:center;justify-content:center}
.pl-ring svg{width:108px;height:108px;transform:rotate(-90deg)}
.pl-ring circle{fill:none;stroke-width:5}
.pl-ring .pl-track{stroke:#262626}
.pl-ring .pl-bar{stroke:var(--accent);stroke-linecap:round;stroke-dasharray:301;stroke-dashoffset:301;transition:stroke-dashoffset .15s linear}
.pl-ring img,.pl-ring .pl-fallback{position:absolute;width:62px;height:62px;border-radius:50%;object-fit:cover}
.pl-pct{position:absolute;bottom:-30px;font-size:13px;font-weight:700;color:var(--accent2)}
#preloader .pl-text{color:var(--muted);font-size:13px;margin-top:14px}
#preloader img{width:64px;height:64px;border-radius:50%}
.spinner{width:46px;height:46px;border:4px solid #2a2a2a;border-top-color:var(--accent);border-radius:50%;animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.topbar{position:sticky;top:0;z-index:50;display:flex;align-items:center;gap:12px;padding:12px 18px;background:rgba(0,0,0,.85);backdrop-filter:blur(16px) saturate(150%);-webkit-backdrop-filter:blur(16px) saturate(150%);border-bottom:1px solid #1e1e1e;box-shadow:0 4px 24px rgba(0,0,0,.4)}
.burger{cursor:pointer;font-size:22px;background:#1e1e1e;border:1px solid #2a2a2a;border-radius:12px;color:var(--text);width:42px;height:42px;display:flex;align-items:center;justify-content:center;transition:.2s var(--ease)}
.burger:hover{background:var(--accent);transform:translateY(-1px);border-color:var(--accent)}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:18px;letter-spacing:.3px}
.brand img{width:34px;height:34px;flex-shrink:0;object-fit:cover;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.3)}
.brand .ic{filter:drop-shadow(0 2px 6px rgba(255,77,77,.5))}
.topbar .grow{flex:1}
.btn{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:12px;border:none;cursor:pointer;font-weight:700;font-size:14px;font-family:inherit;transition:transform .18s var(--ease),box-shadow .25s var(--ease),filter .2s,background .2s;overflow:hidden;-webkit-tap-highlight-color:transparent}
.btn::after{content:"";position:absolute;top:0;left:-120%;width:60%;height:100%;background:linear-gradient(120deg,transparent,rgba(255,255,255,.28),transparent);transform:skewX(-20deg);transition:left .6s var(--ease)}
.btn:hover::after{left:140%}
.btn:active{transform:scale(.96)}
.btn-primary{background:linear-gradient(135deg,var(--accent),#ff6b6b);color:#fff;box-shadow:0 6px 18px rgba(230,41,75,.35)}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(230,41,75,.5)}
.btn-ghost{background:#1c1c1c;color:var(--text)}
.btn-ghost:hover{background:#262626;transform:translateY(-1px)}
.btn-success{background:linear-gradient(135deg,#22c55e,#16a34a);color:#06251c;box-shadow:0 6px 18px rgba(34,197,94,.3)}
.btn-success:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(34,197,94,.45)}
.btn-danger{background:linear-gradient(135deg,var(--danger),#ff7b7b);color:#250505}
.btn-danger:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(255,92,92,.4)}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none}
.btn:disabled::after{display:none}
.ripple{position:absolute;border-radius:50%;background:rgba(255,255,255,.45);transform:scale(0);animation:rippleAnim .6s var(--ease);pointer-events:none}
@keyframes rippleAnim{to{transform:scale(2.5);opacity:0}}
.user-chip{display:flex;align-items:center;gap:8px;background:#262626;padding:6px 10px;border-radius:30px}
.user-chip img{width:26px;height:26px;flex-shrink:0;object-fit:cover;border-radius:50%}
.sidebar{position:fixed;top:0;right:-300px;width:280px;height:100%;background:var(--bg2);z-index:60;transition:right .3s;overflow-y:auto;box-shadow:-10px 0 30px rgba(0,0,0,.3)}
.sidebar.open{right:0}
.sidebar .sb-head{padding:18px;border-bottom:1px solid #3a1f26;display:flex;justify-content:space-between;align-items:center}
.sidebar nav a{position:relative;display:flex;align-items:center;gap:12px;padding:15px 18px;color:var(--text);border-bottom:1px solid #1e1e1e;font-size:15px;font-weight:600;transition:background .2s,padding .2s var(--ease)}
.sidebar nav a::before{content:"";position:absolute;right:0;top:0;bottom:0;width:4px;background:linear-gradient(var(--accent),var(--accent2));transform:scaleY(0);transition:transform .25s var(--ease)}
.sidebar nav a:hover{background:#1c1c1c;padding-right:24px}
.sidebar nav a:hover::before{transform:scaleY(1)}
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:55;display:none}
.overlay.show{display:block}
.banner{margin:18px 18px 0;border-radius:24px 24px 0 0;background:linear-gradient(135deg,#5a0e1a,#a3182c 55%,#ff4d4d);padding:46px 28px;position:relative;overflow:hidden;box-shadow:0 14px 40px rgba(163,24,44,.35);animation:fadeUp .7s var(--ease) both;background-size:cover;background-position:center}
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
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px;padding:0 18px 30px}
.card{background:linear-gradient(180deg,var(--card),#1c1c1c);border-radius:var(--radius);padding:16px;position:relative;border:1px solid #262626;transition:transform .28s var(--ease),box-shadow .28s var(--ease),border-color .28s;overflow:hidden}
.card::before{content:"";position:absolute;inset:0;border-radius:inherit;padding:1px;background:linear-gradient(135deg,rgba(230,41,75,.6),transparent 40%,rgba(255,77,77,.5));-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;opacity:0;transition:opacity .3s}
.card:hover{transform:translateY(-6px);box-shadow:0 16px 40px rgba(0,0,0,.45)}
.card:hover::before{opacity:1}
.card .tag{position:absolute;top:12px;left:12px;background:linear-gradient(135deg,var(--accent2),#ff7676);color:#06251c;font-size:11px;padding:4px 10px;border-radius:20px;font-weight:800;z-index:2;box-shadow:0 4px 12px rgba(255,77,77,.4)}
.card .icon{font-size:38px;margin-bottom:8px}
.card img.pimg{width:100%;height:130px;object-fit:cover;border-radius:12px;margin-bottom:10px;transition:transform .4s var(--ease)}
.card:hover img.pimg{transform:scale(1.07)}
.card h3{font-size:15px;margin-bottom:6px;min-height:38px;transition:color .2s}
.card:hover h3{color:var(--accent2)}
.card .price{font-size:18px;font-weight:800;color:var(--accent2)}
.card .old{color:var(--muted);text-decoration:line-through;font-size:13px;margin-right:6px}
.card .desc{font-size:12px;color:var(--muted);margin:6px 0;max-height:36px;overflow:hidden}
.card .buy{width:100%;margin-top:10px}
.empty{padding:60px 20px;text-align:center;color:var(--muted);font-size:15px}
.empty .ic{color:var(--accent);opacity:.7;margin-bottom:6px}
.bottom-nav{position:fixed;bottom:0;left:0;right:0;display:flex;align-items:center;background:rgba(0,0,0,.92);backdrop-filter:blur(16px) saturate(150%);-webkit-backdrop-filter:blur(16px) saturate(150%);border-top:1px solid #1e1e1e;z-index:40;box-shadow:0 -4px 24px rgba(0,0,0,.5)}
.bottom-nav a{position:relative;flex:1;text-align:center;padding:11px 4px 9px;font-size:11px;font-weight:600;color:var(--muted);transition:color .25s var(--ease)}
.bottom-nav a .ic{transition:transform .25s var(--ease)}
.bottom-nav a:active .ic{transform:scale(.85)}
.bottom-nav a.active{color:#fff}
.bottom-nav a.active .ic{color:var(--accent2);transform:translateY(-2px) scale(1.12);filter:drop-shadow(0 4px 8px rgba(37,244,238,.5))}
.bottom-nav a.active::before{content:"";position:absolute;top:0;left:50%;transform:translateX(-50%);width:30px;height:3px;border-radius:0 0 6px 6px;background:linear-gradient(90deg,var(--accent),var(--accent2))}
.bottom-nav .bi{font-size:18px;display:block;margin-bottom:2px}
.bottom-nav a.fab{flex:0 0 auto;padding:0;margin:0 10px}
.bottom-nav a.fab .fab-box{width:46px;height:30px;margin:0 auto 2px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--accent2),var(--accent));box-shadow:0 4px 14px rgba(254,44,85,.4);transition:transform .2s var(--ease)}
.bottom-nav a.fab:active .fab-box{transform:scale(.9)}
.bottom-nav a.fab .fab-box .ic{color:#000;width:22px;height:22px}
.bottom-nav a.fab.active{color:var(--text)}
.bottom-nav a.fab.active .ic{color:#000;transform:none;filter:none}
.bottom-nav a.fab.active::before{display:none}
.container{max-width:1000px;margin:0 auto;padding-bottom:80px}
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;display:flex;align-items:center;justify-content:center;padding:16px}
.modal-bg{animation:fadeIn .25s ease}
.modal{background:linear-gradient(180deg,var(--card),#161616);border-radius:22px;padding:24px;max-width:430px;width:100%;max-height:85vh;overflow:auto;border:1px solid #2a2a2a;box-shadow:0 24px 60px rgba(0,0,0,.5);animation:modalPop .35s var(--ease)}
@keyframes modalPop{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal h2{margin-bottom:14px;display:flex;align-items:center;gap:8px}
.modal input,.modal textarea,.modal select{width:100%;padding:12px;border-radius:12px;border:1px solid #2a2a2a;background:#161616;color:var(--text);margin-bottom:10px;font-family:inherit;font-size:14px;transition:border-color .2s,box-shadow .2s}
.modal input:focus,.modal textarea:focus,.modal select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(230,41,75,.2)}
.ad-gate-x{position:absolute;top:12px;left:12px;width:32px;height:32px;border-radius:50%;border:1px solid #2a2a2a;background:#161616;color:var(--text);cursor:pointer;font-size:14px}
.ad-gate-x:disabled{opacity:.35;cursor:not-allowed}
.ad-gate-timer{font-size:28px;font-weight:800;margin-top:6px}
.toast{position:fixed;bottom:96px;left:50%;transform:translateX(-50%) translateY(20px);background:linear-gradient(135deg,#262626,#3a1f29);padding:13px 22px;border-radius:30px;z-index:300;display:none;font-size:14px;font-weight:600;box-shadow:0 12px 30px rgba(0,0,0,.45);border:1px solid #4a2530}
.toast.show{display:block;animation:toastIn .4s var(--ease) forwards}
@keyframes toastIn{to{transform:translateX(-50%) translateY(0)}}
.policy-modal{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:500;display:flex;align-items:center;justify-content:center;padding:16px}
.policy-box{background:var(--card);border-radius:var(--radius);padding:24px;max-width:480px}
.flash{margin:14px 18px;padding:12px 16px;border-radius:10px;background:#1d3b2e;border:1px solid var(--accent2)}
.flash.error{background:#3b1d1d;border-color:var(--danger)}
table{width:100%;border-collapse:collapse;font-size:13px}
table th,table td{padding:8px;border-bottom:1px solid #262626;text-align:right}
.admin-tabs{display:flex;flex-wrap:wrap;gap:8px;padding:14px 18px}
.admin-tabs a{padding:8px 14px;border-radius:10px;background:#262626;font-size:13px}
.admin-tabs a.active{background:var(--accent)}
.admin-box{background:var(--card);margin:0 18px 20px;border-radius:var(--radius);padding:18px;overflow-x:auto}
.formrow{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:12px}
.badge{padding:2px 8px;border-radius:8px;font-size:11px}
.badge.pending{background:#5a4a1c}
.badge.approved{background:#1d3b2e}
.badge.rejected{background:#3b1d1d}
footer{text-align:center;color:var(--muted);padding:30px 10px;font-size:12px}
.ic{width:20px;height:20px;display:inline-block;vertical-align:middle;flex-shrink:0}
.ic-sm{width:16px;height:16px}
.ic-lg{width:30px;height:30px}
.ic-xl{width:46px;height:46px}
.btn .ic{margin-inline-end:6px;margin-bottom:2px}
.burger .ic{width:24px;height:24px}
.sidebar nav a .ic{color:var(--accent2)}
.bottom-nav a .ic{display:block;margin:0 auto 3px}
.bottom-nav a.active .ic{color:var(--accent2)}
.admin-tabs a{display:inline-flex;align-items:center;gap:6px}
.card .icon-wrap{width:56px;height:56px;flex-shrink:0;border-radius:14px;background:#161616;display:flex;align-items:center;justify-content:center;margin-bottom:10px;color:var(--accent2)}
.card .icon-wrap.emoji-icon{font-size:28px;line-height:1}
.wish-btn{position:absolute;top:10px;left:10px;z-index:2;width:32px;height:32px;border-radius:50%;background:rgba(0,0,0,.4);border:1px solid #2a2a2a;display:flex;align-items:center;justify-content:center;color:#fff;cursor:pointer;transition:.2s}
.wish-btn .ic{fill:none;stroke:currentColor}
.wish-btn.active{color:var(--accent2)}
.wish-btn.active .ic{fill:var(--accent2)}
.search-bar{display:flex;gap:8px;margin:0 18px 16px}
.search-bar input{flex:1;padding:12px 14px;border-radius:12px;border:1px solid #2a2a2a;background:#161616;color:var(--text);font-size:14px}
.search-bar button{width:44px;border-radius:12px;border:1px solid #2a2a2a;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer}
.star-rating{display:flex;gap:2px;color:var(--accent2)}
.star-rating .ic{width:16px;height:16px}
.profile-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.profile-stat{display:flex;flex-direction:column;align-items:center;gap:6px;background:#161616;border:1px solid #262626;border-radius:14px;padding:14px 8px;text-align:center}
.profile-stat strong{font-size:18px}
.profile-stat span{color:var(--muted);font-size:12px}
.profile-info-row{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid #262626;font-size:13px}
.profile-info-row:last-child{border-bottom:none}
.profile-info-row span{color:var(--muted)}
.achv-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
.achv-badge{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;background:#161616;border:1px solid #262626;color:var(--muted);font-size:13px;opacity:.5}
.achv-badge.on{opacity:1;color:var(--text);border-color:var(--accent);background:rgba(230,41,75,.1)}
.achv-badge.on .ic{color:var(--accent2)}
.balance-pill-sm{display:flex;align-items:center;gap:5px;background:#161616;border:1px solid #262626;border-radius:20px;padding:6px 12px;font-size:13px;font-weight:700;color:var(--accent2)}
@media (max-width:480px){.profile-grid{grid-template-columns:repeat(2,1fr)}.achv-grid{grid-template-columns:1fr}}
.icon-wrap .ic{flex-shrink:0}
.card img.pimg{display:block;flex-shrink:0}
.brand .ic{color:var(--accent2)}
.stat-card{background:#161616;border-radius:12px;padding:14px;display:flex;align-items:center;gap:12px}
.stat-card .ic{color:var(--accent2);background:#1c1c1c;border-radius:10px;padding:8px;width:36px;height:36px}
.stat-card .num{font-size:20px;font-weight:800}
.stat-card .lbl{color:var(--muted);font-size:12px}
.upload-row{display:flex;gap:8px;align-items:center;margin-bottom:10px}
.upload-row input[type=text]{flex:1;margin-bottom:0}
.upload-row label.btn{margin:0;white-space:nowrap;cursor:pointer}
.upload-row .preview{width:44px;height:44px;border-radius:8px;object-fit:cover;background:#161616}
.icon-badge{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px}
.icon-badge.ok{background:#1d3b2e;color:var(--accent2)}
.icon-badge.no{background:#3b1d1d;color:var(--danger)}

.wallet-balance-card{background:linear-gradient(135deg,#1c1c1c,#1c1c1c);border:1px solid #2a2a2a;border-radius:18px;padding:20px;margin:18px;position:relative;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.25)}
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
.wbc-progress-bar{height:8px;border-radius:6px;background:#161616;overflow:hidden}
.wbc-progress-fill{height:100%;border-radius:6px;background:linear-gradient(90deg,var(--accent),var(--accent2));transition:width .6s ease}
.wbc-progress-txt{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);margin-top:6px}

.topup-method{background:#161616;border-radius:10px;padding:12px;margin-bottom:8px;transition:transform .15s ease}
.topup-method:hover{transform:translateY(-2px)}
.topup-method-head strong{display:flex;align-items:center;gap:6px;font-size:14px}
.topup-method-addr{display:flex;align-items:center;gap:8px;margin-top:6px}
.topup-method-addr code{flex:1;font-family:monospace;word-break:break-all;color:var(--muted);font-size:12px}
.cat-chips{display:flex;gap:10px;overflow-x:auto;padding:0 18px 14px;scrollbar-width:none}
.cat-chips::-webkit-scrollbar{display:none}
.cat-chip{display:flex;align-items:center;gap:6px;flex-shrink:0;background:#1e1e1e;border:1px solid #262626;border-radius:30px;padding:8px 16px;font-size:13px;font-weight:600;color:var(--text);transition:transform .2s var(--ease),border-color .2s}
.cat-chip:hover{transform:translateY(-2px);border-color:var(--accent2)}
.cat-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;padding:0 18px 16px}
.cat-tile{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;aspect-ratio:1/1;border-radius:16px;overflow:hidden;background:linear-gradient(135deg,#a3182c,#ff4d4d);box-shadow:0 6px 16px rgba(0,0,0,.25);transition:transform .2s var(--ease)}
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
.balance-pill{display:flex;align-items:center;gap:8px;background:linear-gradient(135deg,#1c1c1c,#2e161d);border:1px solid #2a2a2a;border-radius:30px;padding:10px 16px;margin-bottom:16px;font-size:14px;color:var(--muted)}
.balance-pill strong{color:var(--accent2)}
.buy-modal label{display:block;font-size:13px;color:var(--muted);margin-bottom:10px}
.buy-extra{background:#161616;border:1px solid #2a2a2a;border-radius:10px;margin-bottom:10px;overflow:hidden}
.buy-extra summary{cursor:pointer;padding:10px 12px;font-size:13px;color:var(--muted);display:flex;align-items:center;gap:6px;list-style:none}
.buy-extra summary::-webkit-details-marker{display:none}
.buy-extra[open] summary{border-bottom:1px solid #2a2a2a}
.buy-extra label,.buy-extra .topup-method{margin:10px 12px}
.buy-extra .topup-method:last-of-type{margin-bottom:6px}
.buy-extra a{margin:0 12px 10px}
.upload-box{position:relative;border:2px dashed #2a2a2a;border-radius:var(--radius,12px);background:#161616;padding:18px 14px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;display:flex;flex-direction:column;align-items:center;gap:6px;margin-top:6px}
.upload-box:hover,.upload-box.dragover{border-color:var(--accent2);background:#161616}
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
.upload-box-remove{background:#1c1c1c;border:none;color:var(--muted);border-radius:8px;padding:6px;cursor:pointer;display:flex;flex-shrink:0;position:relative;z-index:2}
.upload-box-remove:hover{color:var(--danger);background:#3b1d22}
.btn-copy{background:#1c1c1c;border:none;color:var(--muted);border-radius:8px;padding:6px;cursor:pointer;display:flex;transition:color .2s,background .2s}
.btn-copy:hover{color:#fff;background:#3a1c29}
.btn-copy.copied{color:var(--accent2);background:#1d3b2e}

.login-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px;background:radial-gradient(1100px 600px at 50% -10%,rgba(230,41,75,.16),transparent 60%),var(--bg)}
.login-wrap{width:100%;max-width:440px}
.login-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
.login-back{display:flex;align-items:center;gap:8px;background:#1e1e1e;border:1px solid #262626;border-radius:30px;padding:9px 16px;font-size:13px;font-weight:600;color:var(--text);transition:.2s var(--ease)}
.login-back:hover{border-color:var(--accent)}
.login-logo{display:flex;align-items:center;gap:8px;font-weight:800;font-size:18px}
.login-logo img{width:34px;height:34px;border-radius:50%;object-fit:cover}
.login-card{background:linear-gradient(180deg,var(--card),#161616);border:1px solid #2a2a2a;border-radius:24px;padding:28px 24px;box-shadow:0 24px 60px rgba(0,0,0,.5)}
.login-card h2{font-size:24px;margin-bottom:6px;color:var(--accent2)}
.login-card .sub{color:var(--muted);font-size:13px;margin-bottom:20px}
.login-tabs{display:flex;gap:10px;margin-bottom:20px}
.login-tabs button{flex:1;padding:11px;border-radius:12px;border:1px solid #2a2a2a;background:transparent;color:var(--muted);font-weight:700;font-size:14px;cursor:pointer;transition:.2s var(--ease)}
.login-tabs button.active{background:#fff;color:#2a0a10;border-color:#fff}
.login-card label{display:block;font-size:13px;color:var(--text);font-weight:600;margin-bottom:8px}
.login-card input{width:100%;padding:13px 14px;border-radius:12px;border:1px solid #2a2a2a;background:#161616;color:var(--text);margin-bottom:16px;font-size:14px}
.login-remember{display:flex;align-items:center;justify-content:flex-end;gap:8px;font-size:13px;color:var(--muted);margin-bottom:18px}
.login-submit{width:100%;padding:14px;border-radius:12px;border:none;background:#fff;color:#2a0a10;font-weight:800;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:.2s var(--ease)}
.login-submit:hover{transform:translateY(-2px)}
.login-forgot{display:block;text-align:center;color:var(--muted);font-size:13px;margin-top:16px}
.login-divider{display:flex;align-items:center;gap:10px;margin:22px 0;color:var(--muted);font-size:13px}
.login-divider::before,.login-divider::after{content:'';flex:1;height:1px;background:#2a2a2a}
.social-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.social-btn{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:18px 6px;border-radius:14px;border:1px solid #2a2a2a;background:#161616;color:var(--text);font-size:18px;transition:.2s var(--ease)}
.social-btn:not(.soon):hover{border-color:var(--accent2);transform:translateY(-2px)}
.social-btn.soon{opacity:.55;cursor:not-allowed;pointer-events:none}
.social-btn .soon-tag{font-size:10px;color:var(--muted);font-weight:600}

.tx-list{display:flex;flex-direction:column;gap:2px}
.tx-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #262626}
.tx-row:last-child{border-bottom:none}
.tx-icon{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;flex-shrink:0}
.tx-icon.pos{background:#1d3b2e;color:var(--accent2)}
.tx-icon.neg{background:#3b1d1d;color:var(--danger)}
.tx-info{display:flex;flex-direction:column;flex:1;min-width:0}
.tx-info strong{font-size:13px}
.tx-info span{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tx-amount{font-weight:800;font-size:14px}
.tx-amount.pos{color:var(--accent2)}
.tx-amount.neg{color:var(--danger)}

.breadcrumb a{color:var(--accent2)}
.product-detail{display:flex;flex-direction:column;gap:16px;margin:0 18px 24px;background:var(--card);border-radius:var(--radius);padding:18px;max-width:calc(100% - 36px)}
@media(min-width:640px){.product-detail{flex-direction:row;align-items:flex-start}}
.pd-img{width:100%;max-width:320px;border-radius:14px;object-fit:cover;color:var(--accent2);background:#161616;min-height:200px}
.pd-info{flex:1}
.pd-info h1{font-size:22px;margin-bottom:8px}
.pd-price{margin-bottom:12px}
.pd-desc{color:var(--muted);line-height:1.8;margin-bottom:16px}

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
.user-chip:hover{transform:translateY(-1px);background:#3a1f29}

.admin-box{background:linear-gradient(180deg,var(--card),#1c1c1c);margin:0 18px 20px;border-radius:var(--radius);padding:20px;overflow-x:auto;border:1px solid #262626;box-shadow:var(--shadow);animation:fadeUp .5s var(--ease) both}
.admin-box h2,.admin-box h3{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.admin-box h3 .ic,.admin-box h2 .ic{color:var(--accent2)}
.admin-box input,.admin-box textarea,.admin-box select{width:100%;padding:11px;border-radius:11px;border:1px solid #2a2a2a;background:#161616;color:var(--text);font-family:inherit;font-size:14px;transition:border-color .2s,box-shadow .2s}
.admin-box input:focus,.admin-box textarea:focus,.admin-box select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(230,41,75,.2)}
.admin-box label{display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--muted)}

.admin-tabs a{transition:transform .18s var(--ease),background .2s,box-shadow .2s}
.admin-tabs a:hover{transform:translateY(-2px);background:#3a1f29}
.admin-tabs a.active{background:linear-gradient(135deg,var(--accent),#ff6b6b);box-shadow:0 6px 16px rgba(230,41,75,.4);color:#fff}

.stat-card{transition:transform .22s var(--ease),box-shadow .22s;border:1px solid #262626}
.stat-card:hover{transform:translateY(-4px);box-shadow:0 12px 28px rgba(0,0,0,.4);border-color:var(--accent)}

.flash{animation:fadeUp .4s var(--ease) both;display:flex;align-items:center;gap:8px;box-shadow:var(--shadow)}
.badge{font-weight:700;text-transform:capitalize}

input,textarea,select{font-family:inherit}

/* scroll to top */
#scrollTop{position:fixed;bottom:92px;right:18px;width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,var(--accent),#ff6b6b);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:45;opacity:0;visibility:hidden;transform:translateY(16px) scale(.8);transition:.3s var(--ease);box-shadow:0 8px 22px rgba(230,41,75,.45)}
#scrollTop.show{opacity:1;visibility:visible;transform:none}
#scrollTop:hover{transform:translateY(-2px) scale(1.05)}

/* skeleton shimmer */
.skeleton{background:linear-gradient(90deg,#1e1e1e 25%,#262626 37%,#1e1e1e 63%);background-size:400% 100%;animation:shimmer 1.4s infinite}
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
.lb-row{display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid #262626;border-radius:14px;padding:12px 14px;animation:fadeUp .5s var(--ease) both}
.lb-row.lb-rank-1{background:linear-gradient(90deg,rgba(255,194,51,.18),var(--card));border-color:#ffc233}
.lb-row.lb-rank-2{background:linear-gradient(90deg,rgba(192,192,192,.14),var(--card));border-color:#bdbdbd}
.lb-row.lb-rank-3{background:linear-gradient(90deg,rgba(205,127,50,.14),var(--card));border-color:#cd7f32}
.lb-pos{font-size:20px;width:34px;text-align:center;font-weight:800}
.lb-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;flex-shrink:0}
.lb-avatar-ph{display:flex;align-items:center;justify-content:center;background:#262626}
.lb-name{flex:1;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lb-points{display:flex;align-items:center;gap:5px;color:var(--accent2);font-weight:800;flex-shrink:0}
.coin-pkgs{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px}
.coin-pkg{display:flex;flex-direction:column;align-items:center;gap:3px;background:#161616;border:1px solid #262626;border-radius:12px;padding:10px 4px;cursor:pointer;color:var(--text);transition:.2s var(--ease)}
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
.profile-frame img,.profile-frame .ph{width:84px;height:84px;border-radius:50%;object-fit:cover;background:#262626;display:flex;align-items:center;justify-content:center}
.profile-frame::before{content:'';position:absolute;inset:0;border-radius:50%;border:3px solid var(--accent);animation:frameSpin 6s linear infinite}
.profile-rank-bronze .profile-frame::before{border-color:#c97a3d}
.profile-rank-silver .profile-frame::before{border-color:#c9d2da;box-shadow:0 0 12px rgba(201,210,218,.5)}
.profile-rank-gold .profile-frame::before{border-color:#e6b800;box-shadow:0 0 14px rgba(230,184,0,.6)}
.profile-rank-diamond .profile-frame::before{border-color:#36e0e0;box-shadow:0 0 16px rgba(54,224,224,.7)}
.avatar-edit-btn{position:absolute;bottom:-2px;left:50%;transform:translateX(-50%);width:28px;height:28px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg);cursor:pointer;z-index:2}
.avatar-edit-btn svg{width:13px;height:13px}
@keyframes frameSpin{from{transform:rotate(0deg) scale(1)}50%{transform:rotate(180deg) scale(1.04)}to{transform:rotate(360deg) scale(1)}}
.spin-wheel-wrap{display:flex;flex-direction:column;align-items:center;gap:20px;padding:20px 0}
.spin-wheel{width:240px;height:240px;border-radius:50%;position:relative;background:conic-gradient(#e6294b 0deg 45deg,#ff8a3d 45deg 90deg,#ffd23d 90deg 135deg,#4dd6a3 135deg 180deg,#3da5ff 180deg 225deg,#a36dff 225deg 270deg,#ff5fa2 270deg 315deg,#6dffb0 315deg 360deg);transition:transform 2.4s cubic-bezier(.18,.9,.2,1);box-shadow:0 0 0 6px #161616,0 0 30px rgba(0,0,0,.5)}
.spin-wheel-pointer{position:absolute;top:-14px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:14px solid transparent;border-right:14px solid transparent;border-top:22px solid var(--accent);z-index:2}
.card img.pimg{height:<?= (int)setting('product_image_height', 130) ?>px}
.cat-tiles{grid-template-columns:repeat(auto-fill,minmax(<?= (int)setting('cat_tile_size', 140) ?>px,1fr))}
.banner-carousel-slide img{height:<?= (int)setting('banner_height', 160) ?>px}
<?php
$themeAccent = setting('theme_accent_color', '#e6294b');
$themeAccent2 = setting('theme_accent2_color', '#ff4d4d');
if (preg_match('/^#[0-9a-fA-F]{3,6}$/', $themeAccent) && preg_match('/^#[0-9a-fA-F]{3,6}$/', $themeAccent2)):
?>
:root{--accent:<?= e($themeAccent) ?>;--accent2:<?= e($themeAccent2) ?>}
<?php endif; ?>
</style>
</head>
<body>
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
        <button type="submit" class="login-submit"><?= icon('gift', 'ic-sm') ?>إنشاء حساب</button>
      </form>

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
    <div class="balance-pill-sm"><?= icon('coins', 'ic-sm') ?><?= points_to_usd((int)$user['points']) ?>$</div>
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
    <?php if ($user): ?><a href="?page=profile"><?= icon('user') ?> ملفي الشخصي</a><?php endif; ?>
    <a href="?page=earn"><?= icon('coin') ?> اكسب عملات (كابتشا)</a>
    <a href="?page=tasks"><?= icon('tasks') ?> المهام اليومية</a>
    <a href="?page=wallet"><?= icon('wallet') ?> محفظتي</a>
    <a href="?page=leaderboard"><?= icon('star') ?> المتصدّرون</a>
    <a href="?page=spin"><?= icon('gift') ?> عجلة الحظ</a>
    <a href="?page=orders"><?= icon('orders') ?> طلباتي</a>
    <a href="?page=suggest"><?= icon('megaphone') ?> اقترح منتجاً</a>
    <a href="?page=about"><?= icon('shield') ?> من نحن</a>
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
    <div class="balance-pill"><?= icon('coins', 'ic-sm') ?>رصيدك المتاح: <strong id="buyBalance"><?= e($userUsdBalance) ?>$</strong> · السعر: <strong id="buyFinalPrice">0$</strong></div>

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
        <a href="?page=wallet" class="btn btn-ghost" style="width:100%;margin-top:6px;text-align:center;display:block"><?= icon('send', 'ic-sm') ?>إرسال طلب شحن من صفحة المحفظة</a>
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

<div class="modal-bg" id="adGateModal" style="display:none">
  <div class="modal ad-gate-modal" style="text-align:center;position:relative">
    <button type="button" id="adGateClose" class="ad-gate-x" disabled onclick="closeAdGate()">✕</button>
    <h2 style="justify-content:center"><?= icon('coin', 'ic') ?>انتظر قليلاً...</h2>
    <div id="adGateMsg" style="color:var(--muted);margin:10px 0">جارٍ تحميل الإعلان...</div>
    <div id="adGateSlot" style="min-height:60px;display:flex;align-items:center;justify-content:center"></div>
    <div class="ad-gate-timer" id="adGateTimer">5</div>
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
              <img class="pimg" loading="lazy" decoding="async" src="<?= e($p['image']) ?>" alt="<?= e($p['name']) ?>">
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
                <a href="#cat-<?= (int)$c['id'] ?>" class="cat-tile" style="background:<?= e($c['color'] ?: '#271419') ?>">
                  <img src="<?= e($c['image']) ?>" alt="<?= e($c['name']) ?>" loading="lazy" decoding="async">
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
            if (!$banners) return; ?>
            <div class="banner-carousel" id="bannerCarousel" data-interval="<?= (int)setting('banner_interval', 4000) ?>">
              <div class="banner-carousel-track">
                <?php foreach ($banners as $i => $b): ?>
                  <a class="banner-carousel-slide" href="<?= e($b['link'] ?: '#') ?>"><img src="<?= e($b['image']) ?>" <?= $i === 0 ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"' ?> decoding="async" alt=""></a>
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
            $winners = db()->query("SELECT u.name, u.username, e.amount FROM earn_logs e JOIN users u ON u.id = e.user_id WHERE e.amount > 0 ORDER BY e.id DESC LIMIT 15")->fetchAll();
            if (!$winners) return; ?>
            <div class="ticker-bar live-ticker">
              <span class="ticker-badge"><?= icon('coin', 'ic-sm') ?>مباشر</span>
              <div class="ticker-track">
                <div class="ticker-track-inner">
                  <?php foreach (array_merge($winners, $winners) as $w): ?>
                    <span class="ticker-item"><?= icon('coins', 'ic-sm') ?><?= e($w['name'] ?: $w['username']) ?> ربح <strong>+<?= (int)$w['amount'] ?></strong> عملة</span>
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
      <h2><?= icon('coin', 'ic') ?>اكسب عملات Yassota</h2>
      <p style="color:var(--muted);margin:10px 0">أدخل الرقم الظاهر بالأسفل بشكل صحيح لتحصل على <?= e(setting('captcha_reward')) ?> عملة. (<?= $done ?>/<?= $max ?> اليوم)</p>
      <div id="captchaBox" style="font-size:32px;font-weight:800;letter-spacing:8px;background:#1d1014;border-radius:12px;padding:18px;text-align:center;margin:14px 0">----</div>
      <input type="text" id="captchaAnswer" placeholder="أدخل الرقم هنا" style="width:100%;padding:12px;border-radius:10px;border:1px solid #3a1c23;background:#1d1014;color:#fff;text-align:center;font-size:18px">
      <button class="btn btn-success" style="width:100%;margin-top:12px" onclick="submitCaptcha()"><?= icon('check', 'ic ic-sm') ?>تحقق واحصل على العملات</button>
    </div>
    <?php
    break;

case 'tasks':
    if (!$user) { echo '<div class="empty">سجّل الدخول لعرض المهام.<br><button class="btn btn-primary" style="margin-top:14px" onclick="openAuthModal()">تسجيل الدخول</button></div>'; break; }
    $tasks = db()->query("SELECT * FROM tasks WHERE active=1")->fetchAll();
    $day = date('Y-m-d');
    ?>
    <div class="section-title"><?= icon('tasks', 'ic') ?>المهام اليومية</div>
    <div class="admin-box">
    <?php if (!$tasks): ?>
      <div class="empty">لا توجد مهام حالياً.</div>
    <?php endif; ?>
    <?php foreach ($tasks as $t):
        $st = db()->prepare("SELECT * FROM task_completions WHERE user_id=? AND task_id=? AND day=?");
        $st->execute([$user['id'], $t['id'], $day]);
        $done = (bool)$st->fetch();
    ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #341c22">
        <div>
          <strong><?= e($t['title']) ?></strong>
          <div style="color:var(--muted);font-size:12px;display:flex;align-items:center;gap:4px"><?= icon('clock', 'ic-sm') ?><?= (int)$t['seconds'] ?> ثانية · +<?= (int)$t['reward'] ?> عملة</div>
        </div>
        <?php if ($done): ?>
          <span class="badge approved"><?= icon('check', 'ic-sm') ?>مكتملة</span>
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
    $minw = (float)setting('min_withdraw_usd', 25);
    $progress = $minw > 0 ? min(100, round($usd / $minw * 100)) : 100;
    $canWithdraw = $usd >= $minw;
    $log = db()->prepare("SELECT * FROM earn_logs WHERE user_id=? ORDER BY id DESC LIMIT 12");
    $log->execute([$user['id']]);
    $logs = $log->fetchAll();
    $sourceLabel = ['welcome' => 'هدية الترحيب', 'captcha' => 'كابتشا', 'task' => 'مهمة', 'topup' => 'شحن رصيد', 'refund' => 'استرجاع', 'admin' => 'الإدارة', 'withdraw' => 'سحب', 'referral' => 'دعوة صديق', 'daily_bonus' => 'مكافأة يومية', 'bio_bonus' => 'مكافأة النبذة', 'profile_complete_bonus' => 'اكتمال الملف الشخصي', 'spin' => 'عجلة الحظ'];
    ?>
    <div class="wallet-balance-card">
      <div class="wbc-top">
        <span class="wbc-label"><?= icon('wallet', 'ic-sm') ?>محفظتي</span>
        <span class="wbc-rate">سعر العملة: <?= e(setting('points_rate', 0.001)) ?>$</span>
      </div>
      <div class="wbc-amount"><?= icon('coins', 'ic ic-xl') ?><span><?= number_format((int)$user['points']) ?></span><small>عملة</small></div>
      <div class="wbc-usd">≈ <strong><?= $usd ?>$</strong></div>
      <div class="wbc-progress">
        <div class="wbc-progress-bar"><div class="wbc-progress-fill" style="width:<?= $progress ?>%"></div></div>
        <span class="wbc-progress-txt"><?= $canWithdraw ? icon('check', 'ic-sm') . 'يمكنك السحب الآن' : 'الحد الأدنى للسحب: ' . e($minw) . '$ (' . $progress . '%)' ?></span>
      </div>
    </div>

    <?php
    $todayStr = date('Y-m-d');
    $gotDailyBonus = $user['last_bonus_date'] === $todayStr;
    $dailyBonusAmt = (int)setting('daily_bonus_points', 20);
    $refStCount = db()->prepare("SELECT COUNT(*) c FROM users WHERE referred_by = ?");
    $refStCount->execute([$user['id']]);
    $refCount = (int)$refStCount->fetch()['c'];
    $refPaidSt = db()->prepare("SELECT COUNT(*) c FROM users WHERE referred_by = ? AND referral_bonus_given = 1");
    $refPaidSt->execute([$user['id']]);
    $refPaidCount = (int)$refPaidSt->fetch()['c'];
    $refMax = (int)setting('referral_max_count', 5);
    $refBonusPts = (int)setting('referral_bonus_points', 100);
    ?>
    <div class="admin-box" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <div><strong><?= icon('coin', 'ic-sm') ?> المكافأة اليومية</strong><div style="font-size:13px;color:var(--muted);margin-top:4px"><?= $gotDailyBonus ? 'حصلت عليها اليوم، عُد غداً.' : "اضغط للحصول على +{$dailyBonusAmt} عملة." ?></div></div>
      <button class="btn btn-primary" id="dailyBonusBtn" <?= $gotDailyBonus ? 'disabled style="opacity:.5;cursor:not-allowed"' : 'onclick="claimDailyBonus()"' ?>><?= icon('coins', 'ic-sm') ?><?= $gotDailyBonus ? 'تم الاستلام' : 'استلام' ?></button>
    </div>

    <div class="admin-box">
      <h3><?= icon('send', 'ic') ?>دعوة الأصدقاء</h3>
      <p style="font-size:13px;color:var(--muted)">شارك رابطك واحصل على <?= $refBonusPts ?> عملة عن كل صديق يسجّل عبره (حتى <?= $refMax ?> أصدقاء كحد أقصى = <?= points_to_usd($refBonusPts * $refMax) ?>$ إجمالاً). إحالات مكتملة ومربحة: <strong><?= $refPaidCount ?>/<?= $refMax ?></strong> — إجمالي من سجّل عبر رابطك: <strong><?= $refCount ?></strong></p>
      <div class="upload-row">
        <input type="text" readonly value="<?= e(rtrim(SITE_URL, '/')) ?>/index.php?ref=<?= e($user['referral_code']) ?>" id="refLinkInput" onclick="this.select()">
        <button class="btn btn-ghost" type="button" onclick="navigator.clipboard.writeText(document.getElementById('refLinkInput').value);this.textContent='تم النسخ!'"><?= icon('check', 'ic-sm') ?>نسخ</button>
      </div>
    </div>

    <div class="admin-box withdraw-box">
      <h3><?= icon('bank', 'ic') ?>تحويل العملات إلى رصيد حقيقي</h3>
      <p style="color:var(--muted);font-size:13px;margin-bottom:10px">اختر طريقة الاستلام، ثم اطلب السحب. كل طلب يحتاج موافقة الإدارة وستجد حالته في "سجل العمليات" أدناه.</p>
      <select id="wType">
        <option value="sham" <?= $user['wallet_type'] === 'sham' ? 'selected' : '' ?>>الشام كاش (افتراضي)</option>
        <option value="usdt" <?= $user['wallet_type'] === 'usdt' ? 'selected' : '' ?>>USDT (TRC20)</option>
        <option value="binance" <?= $user['wallet_type'] === 'binance' ? 'selected' : '' ?>>Binance Pay</option>
        <option value="payeer" <?= $user['wallet_type'] === 'payeer' ? 'selected' : '' ?>>Payeer</option>
        <option value="syriatel_cash" <?= $user['wallet_type'] === 'syriatel_cash' ? 'selected' : '' ?>>سيرياتيل كاش</option>
        <option value="mtn_cash" <?= $user['wallet_type'] === 'mtn_cash' ? 'selected' : '' ?>>MTN كاش</option>
        <option value="bank_transfer" <?= $user['wallet_type'] === 'bank_transfer' ? 'selected' : '' ?>>حوالة بنكية</option>
        <option value="western_union" <?= $user['wallet_type'] === 'western_union' ? 'selected' : '' ?>>ويسترن يونيون</option>
        <option value="crypto" <?= $user['wallet_type'] === 'crypto' ? 'selected' : '' ?>>عملة مشفرة أخرى</option>
      </select>
      <input id="wAddr" value="<?= e($user['wallet_address']) ?>" placeholder="عنوان المحفظة / رقم الحساب">
      <button class="btn btn-ghost" style="margin-top:8px;width:100%" onclick="saveWallet()"><?= icon('check', 'ic-sm') ?>حفظ طريقة الاستلام</button>
      <button class="btn btn-withdraw-cta" onclick="requestWithdraw()" <?= $canWithdraw ? '' : 'disabled' ?>>
        <?= icon('send', 'ic ic-sm') ?>
        <span><?= $canWithdraw ? 'سحب الرصيد الآن — ' . $usd . '$' : 'الحد الأدنى للسحب ' . e($minw) . '$' ?></span>
      </button>
    </div>

    <div class="admin-box">
      <h3><?= icon('plus', 'ic') ?>شحن الرصيد</h3>
      <p style="color:var(--muted);font-size:13px;margin-bottom:10px">حوّل المبلغ إلى إحدى المحافظ التالية ثم أرسل طلب التحقق:</p>
      <?php foreach ($wallets as $w): ?>
        <div class="topup-method">
          <div class="topup-method-head">
            <?php [$wTypeLbl, $wTypeIcon] = wallet_type_label($w['type']); ?>
            <strong><?= icon($wTypeIcon, 'ic-sm') ?><?= e($wTypeLbl) ?> — <?= e($w['label']) ?></strong>
          </div>
          <div class="topup-method-addr">
            <code><?= e($w['address']) ?></code>
            <button type="button" class="btn-copy" onclick="copyAddr(this)" data-addr="<?= e($w['address']) ?>"><?= icon('copy', 'ic-sm') ?></button>
          </div>
        </div>
      <?php endforeach; ?>
      <select id="topupWallet"><?php foreach ($wallets as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['label']) ?></option><?php endforeach; ?></select>
      <div class="coin-pkgs">
        <?php foreach ([5, 10, 25, 50] as $amt): $coinsFor = $amt > 0 ? round($amt / (float)setting('points_rate', 0.001)) : 0; ?>
          <button type="button" class="coin-pkg" onclick="document.getElementById('topupAmount').value='<?= $amt ?>'"><?= icon('coins', 'ic-sm') ?><strong>$<?= $amt ?></strong><span><?= number_format($coinsFor) ?> عملة</span></button>
        <?php endforeach; ?>
      </div>
      <input id="topupAmount" type="number" placeholder="المبلغ بالدولار">
      <input id="topupNote" placeholder="ملاحظة / رقم العملية (اختياري)">
      <button class="btn btn-primary" onclick="requestTopup()"><?= icon('send', 'ic-sm') ?>إرسال طلب الشحن</button>
    </div>

    <div class="admin-box">
      <h3><?= icon('users', 'ic') ?>أعلى المتصدّرين</h3>
      <a href="?page=leaderboard" class="btn btn-ghost" style="width:100%;justify-content:center"><?= icon('star', 'ic-sm') ?>عرض قائمة المتصدّرين</a>
    </div>

    <div class="admin-box">
      <h3><?= icon('history', 'ic') ?>سجل العمليات</h3>
      <?php if (!$logs): ?>
        <div class="empty" style="padding:20px 0">لا توجد عمليات بعد.</div>
      <?php else: ?>
        <div class="tx-list">
          <?php foreach ($logs as $l): $pos = $l['amount'] >= 0; ?>
            <div class="tx-row">
              <div class="tx-icon <?= $pos ? 'pos' : 'neg' ?>"><?= icon($pos ? 'plus' : 'minus', 'ic-sm') ?></div>
              <div class="tx-info">
                <strong><?= e($sourceLabel[$l['source']] ?? $l['source']) ?></strong>
                <span><?= e($l['description']) ?></span>
              </div>
              <div class="tx-amount <?= $pos ? 'pos' : 'neg' ?>"><?= $pos ? '+' : '' ?><?= (int)$l['amount'] ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
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
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #341c22">
        <div style="display:flex;align-items:center;gap:8px"><?= icon('cart', 'ic-sm') ?><?= e($o['name']) ?> — <?= e($o['price']) ?>$</div>
        <span class="badge <?= e($o['status']) ?>"><?= e($o['status']) ?></span>
      </div>
    <?php endforeach; ?>
    </div>
    <?php
    break;

case 'spin':
    if (!$user) { echo '<div class="empty">سجّل الدخول لتجربة عجلة الحظ.<br><button class="btn btn-primary" style="margin-top:14px" onclick="openAuthModal()">تسجيل الدخول</button></div>'; break; }
    $spunToday = $user['last_spin_date'] === date('Y-m-d');
    ?>
    <div class="section-title"><?= icon('gift', 'ic') ?>عجلة الحظ</div>
    <div class="admin-box spin-wheel-wrap">
      <div style="position:relative">
        <div class="spin-wheel-pointer"></div>
        <div class="spin-wheel" id="spinWheelEl"></div>
      </div>
      <button class="btn btn-primary" id="spinBtn" style="font-size:16px;padding:14px 30px" onclick="spinWheel()" <?= $spunToday ? 'disabled' : '' ?>><?= icon('coins', 'ic-sm') ?><?= $spunToday ? 'عُد غداً للمحاولة مرة أخرى' : 'أدر العجلة الآن' ?></button>
      <p style="color:var(--muted);font-size:13px">اربح بين <?= (int)setting('spin_reward_min', 5) ?> و <?= (int)setting('spin_reward_max', 200) ?> عملة، مرة واحدة يومياً.</p>
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
      <textarea id="sugDetails" rows="4" placeholder="تفاصيل إضافية (اختياري)" style="width:100%;margin-top:8px;background:#1d0f14;border:1px solid #341c22;border-radius:10px;padding:10px;color:var(--text);font-family:inherit"></textarea>
      <button class="btn btn-primary" style="margin-top:10px;width:100%" onclick="submitSuggestion()"><?= icon('send', 'ic-sm') ?>إرسال الاقتراح</button>
    </div>
    <?php
    break;

case 'leaderboard':
    $top10 = db()->query("SELECT id, username, name, avatar, points FROM users WHERE is_banned=0 ORDER BY points DESC LIMIT 10")->fetchAll();
    $myRank = null;
    if ($user) {
        $st = db()->prepare("SELECT COUNT(*) c FROM users WHERE points > ? AND is_banned=0");
        $st->execute([(int)$user['points']]);
        $myRank = (int)$st->fetch()['c'] + 1;
    }
    $medals = ['🥇', '🥈', '🥉'];
    ?>
    <div class="section-title"><?= icon('star', 'ic') ?>أعلى المتصدّرين</div>
    <?php if ($user): ?>
    <div class="admin-box" style="text-align:center;background:linear-gradient(135deg,var(--accent),var(--accent2))">
      <div style="font-size:13px;opacity:.9">ترتيبك الحالي</div>
      <div style="font-size:28px;font-weight:800">#<?= $myRank ?></div>
    </div>
    <?php endif; ?>
    <div class="lb-list">
      <?php foreach ($top10 as $i => $row): $rank = $i + 1; ?>
        <div class="lb-row lb-rank-<?= $rank ?>" style="animation-delay:<?= $i * 60 ?>ms">
          <div class="lb-pos"><?= $medals[$i] ?? $rank ?></div>
          <?php if ($row['avatar']): ?><img class="lb-avatar" src="<?= e($row['avatar']) ?>"><?php else: ?><div class="lb-avatar lb-avatar-ph"><?= icon('user', 'ic-sm') ?></div><?php endif; ?>
          <div class="lb-name"><?= e($row['name'] ?: $row['username']) ?></div>
          <div class="lb-points"><?= icon('coins', 'ic-sm') ?><?= number_format((int)$row['points']) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$top10): ?><div class="empty">لا يوجد مستخدمون حتى الآن.</div><?php endif; ?>
    </div>
    <?php
    break;

case 'profile':
    if (!$user) { echo '<div class="empty">سجّل الدخول لعرض ملفك الشخصي.<br><button class="btn btn-primary" style="margin-top:14px" onclick="openAuthModal()">تسجيل الدخول</button></div>'; break; }
    $pPoints = (int)$user['points'];
    $pUsd = points_to_usd($pPoints);
    $st = db()->prepare("SELECT COUNT(*) c FROM orders WHERE user_id=? AND status='approved'"); $st->execute([$user['id']]); $pApprovedOrders = (int)$st->fetch()['c'];
    $st = db()->prepare("SELECT COUNT(*) c FROM users WHERE referred_by=?"); $st->execute([$user['id']]); $pReferrals = (int)$st->fetch()['c'];
    $st = db()->prepare("SELECT COUNT(*) c FROM earn_logs WHERE user_id=? AND source='captcha'"); $st->execute([$user['id']]); $pCaptchaCount = (int)$st->fetch()['c'];
    $st = db()->prepare("SELECT COUNT(*) c FROM earn_logs WHERE user_id=? AND source='task'"); $st->execute([$user['id']]); $pTaskCount = (int)$st->fetch()['c'];
    $st = db()->prepare("SELECT COUNT(*) c FROM reviews WHERE user_id=?"); $st->execute([$user['id']]); $pReviewCount = (int)$st->fetch()['c'];
    if ($pPoints >= 5000) { $pRank = 'ماسي'; $pRankIcon = '💎'; }
    elseif ($pPoints >= 2000) { $pRank = 'ذهبي'; $pRankIcon = '🥇'; }
    elseif ($pPoints >= 500) { $pRank = 'فضي'; $pRankIcon = '🥈'; }
    else { $pRank = 'برونزي'; $pRankIcon = '🥉'; }
    $pAchievements = [];
    $pAchievements[] = ['icon' => 'check', 'label' => 'عضو مسجّل', 'on' => true];
    $pAchievements[] = ['icon' => 'cart', 'label' => 'أول عملية شراء', 'on' => $pApprovedOrders >= 1];
    $pAchievements[] = ['icon' => 'send', 'label' => 'مُحيل نشط (3+ دعوات)', 'on' => $pReferrals >= 3];
    $pAchievements[] = ['icon' => 'coin', 'label' => 'محارب الكابتشا (50+)', 'on' => $pCaptchaCount >= 50];
    $pAchievements[] = ['icon' => 'tasks', 'label' => 'منجز مهام (10+)', 'on' => $pTaskCount >= 10];
    $pAchievements[] = ['icon' => 'star', 'label' => 'مُقيّم نشط', 'on' => $pReviewCount >= 1];
    ?>
    <div class="section-title"><?= icon('user', 'ic') ?>ملفي الشخصي</div>
    <div class="admin-box profile-rank-<?= e(mb_strtolower($pRank === 'ماسي' ? 'diamond' : ($pRank === 'ذهبي' ? 'gold' : ($pRank === 'فضي' ? 'silver' : 'bronze')))) ?>" style="text-align:center;padding:28px 16px">
      <div class="profile-frame">
        <?php if ($user['avatar']): ?><img id="avatarPreview" src="<?= e($user['avatar']) ?>"><?php else: ?><div class="ph" id="avatarPreview"><?= icon('user', 'ic-lg') ?></div><?php endif; ?>
        <button type="button" class="avatar-edit-btn" title="تغيير الصورة" onclick="document.getElementById('avatarFileInput').click()"><?= icon('edit', 'ic-sm') ?></button>
        <input type="file" id="avatarFileInput" accept="image/*" style="display:none" onchange="uploadAvatar(this.files[0])">
      </div>
      <h2 style="margin-top:12px"><?= e($user['name'] ?: $user['username']) ?><?php if (is_admin()): ?> <span class="verified-badge" title="حساب موثّق"><?= icon('check', 'ic-sm') ?></span><?php endif; ?></h2>
      <div style="color:var(--muted);font-size:13px">@<?= e($user['username']) ?></div>
      <?php if ($user['bio']): ?><div style="margin-top:8px;font-size:13px;color:var(--muted)"><?= e($user['bio']) ?></div><?php endif; ?>
      <div style="margin-top:8px;font-size:18px"><?= $pRankIcon ?> رتبة <strong><?= $pRank ?></strong></div>
    </div>
    <div class="admin-box">
      <h3 style="margin-bottom:10px"><?= icon('edit', 'ic-sm') ?>تعديل الملف الشخصي</h3>
      <input id="editName" value="<?= e($user['name']) ?>" placeholder="الاسم الظاهر">
      <input id="editUsername" value="<?= e($user['username']) ?>" placeholder="اسم المستخدم">
      <input id="editBio" value="<?= e($user['bio']) ?>" placeholder="نبذة عنك (احصل على <?= (int)setting('bio_bonus_points', 100) ?> عملة)">
      <button class="btn btn-primary" style="margin-top:8px;width:100%" onclick="saveProfile()"><?= icon('check', 'ic-sm') ?>حفظ التعديلات</button>
      <?php if (!$user['avatar'] || !$user['bio']): ?><p style="font-size:12px;color:var(--muted);margin-top:6px">أكمل الصورة والنبذة لتحصل على <?= (int)setting('profile_complete_bonus_points', 350) ?> عملة إضافية.</p><?php endif; ?>
    </div>
    <div class="admin-box" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <div><strong><?= icon('star', 'ic-sm') ?> عجلة الحظ</strong><div style="font-size:13px;color:var(--muted);margin-top:4px">جرّب حظك واربح عملات إضافية يومياً</div></div>
      <a href="?page=spin" class="btn btn-success"><?= icon('coins', 'ic-sm') ?>أدر العجلة</a>
    </div>
    <div class="admin-box">
      <div class="profile-grid">
        <div class="profile-stat"><?= icon('coins', 'ic-sm') ?><div><strong><?= $pPoints ?></strong><span>نقطة (<?= $pUsd ?>$)</span></div></div>
        <div class="profile-stat"><?= icon('cart', 'ic-sm') ?><div><strong><?= $pApprovedOrders ?></strong><span>طلب مكتمل</span></div></div>
        <div class="profile-stat"><?= icon('send', 'ic-sm') ?><div><strong><?= $pReferrals ?></strong><span>إحالة ناجحة</span></div></div>
        <div class="profile-stat"><?= icon('star', 'ic-sm') ?><div><strong><?= $pReviewCount ?></strong><span>تقييم</span></div></div>
      </div>
    </div>
    <div class="admin-box">
      <h3 style="margin-bottom:10px"><?= icon('shield', 'ic-sm') ?>المعلومات</h3>
      <div class="profile-info-row"><span>البريد الإلكتروني</span><strong><?= e($user['email'] ?: '—') ?></strong></div>
      <div class="profile-info-row"><span>آيدي الحساب</span><strong>#<?= (int)$user['id'] ?></strong></div>
      <?php if (!empty($user['telegram_id'])): ?><div class="profile-info-row"><span>آيدي تيليجرام</span><strong><?= e($user['telegram_id']) ?></strong></div><?php endif; ?>
      <div class="profile-info-row"><span>عضو منذ</span><strong><?= e(substr($user['created_at'] ?? '', 0, 10)) ?></strong></div>
      <div class="profile-info-row"><span>رابط الإحالة</span><strong style="word-break:break-all"><?= e(SITE_URL . '/?ref=' . $user['referral_code']) ?></strong></div>
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
    $st = db()->prepare("SELECT content FROM pages WHERE slug=?"); $st->execute([$page]); $c = $st->fetch();
    $pageTitles = ['privacy' => 'سياسة الخصوصية', 'terms' => 'شروط الاستخدام', 'about' => 'من نحن', 'contact' => 'تواصل معنا', 'faq' => 'الأسئلة الشائعة'];
    echo '<div class="section-title">' . icon('doc', 'ic') . e($pageTitles[$page]) . '</div>';
    echo '<div class="admin-box" style="margin-top:18px;line-height:1.8">' . nl2br(e($c['content'] ?? '')) . '</div>';
    if ($page === 'contact' && setting('support_telegram')) {
        echo '<div class="admin-box" style="margin-top:14px;text-align:center"><a href="https://t.me/' . e(ltrim(setting('support_telegram'), '@')) . '" target="_blank" class="btn btn-primary">' . icon('send', 'ic-sm') . 'تواصل معنا على تيليجرام ' . e(setting('support_telegram')) . '</a></div>';
    }
    break;

case 'welcome':
    if (!$user) { redirect('?'); }
    $bonus = (int)setting('welcome_bonus_points', 200);
    $dest = is_admin() ? '?page=admin' : '?';
    ?>
    <div class="admin-box" style="margin-top:40px;text-align:center;padding:40px 20px">
      <div style="margin-bottom:10px;color:var(--accent2)"><?= icon('rocket', 'ic ic-xl') ?></div>
      <h2>أهلاً بك، <?= e($user['name'] ?: $user['username']) ?>!</h2>
      <p style="color:var(--muted);margin:14px 0">تم إنشاء حسابك بنجاح، وحصلت على هدية ترحيبية: <strong style="color:var(--accent2)">+<?= $bonus ?> عملة Yassota</strong> <?= icon('gift', 'ic-sm') ?></p>
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
      <?php foreach (['dashboard'=>['hat','لوحة البيانات'],'products'=>['cart','المنتجات'],'orders'=>['orders','الطلبات'],'topups'=>['coins','طلبات الشحن'],'withdraws'=>['send','طلبات السحب'],'wallets'=>['bank','المحافظ'],'tasks'=>['tasks','المهام'],'banners'=>['image','البنرات'],'homepage'=>['menu','تخطيط الرئيسية'],'pages'=>['pages','الصفحات'],'users'=>['users','المستخدمون'],'suggestions'=>['megaphone','اقتراحات المنتجات'],'providers'=>['rocket','المزوّدون'],'settings'=>['settings','الإعدادات']] as $k=>$t): ?>
        <a href="?page=admin&tab=<?= $k ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= icon($t[0], 'ic-sm') ?><?= $t[1] ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($tab === 'dashboard'):
        $users_count = db()->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
        $products_count = db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'];
        $pending_orders = db()->query("SELECT COUNT(*) c FROM orders WHERE status='pending'")->fetch()['c'];
        $pending_topups = db()->query("SELECT COUNT(*) c FROM topup_requests WHERE status='pending'")->fetch()['c'];
        $pending_withdraws = db()->query("SELECT COUNT(*) c FROM withdraw_requests WHERE status='pending'")->fetch()['c'];
        $points_total = db()->query("SELECT COALESCE(SUM(points),0) s FROM users")->fetch()['s'];
        $recent_activity = db()->query("SELECT * FROM activity_log ORDER BY id DESC LIMIT 15")->fetchAll();
    ?>
      <div class="formrow">
        <div class="stat-card"><?= icon('users', 'ic') ?><div><div class="num"><?= $users_count ?></div><div class="lbl">المستخدمون</div></div></div>
        <div class="stat-card"><?= icon('cart', 'ic') ?><div><div class="num"><?= $products_count ?></div><div class="lbl">المنتجات</div></div></div>
        <div class="stat-card"><?= icon('orders', 'ic') ?><div><div class="num"><?= $pending_orders ?></div><div class="lbl">طلبات معلّقة</div></div></div>
        <div class="stat-card"><?= icon('coins', 'ic') ?><div><div class="num"><?= $pending_topups ?></div><div class="lbl">شحن معلّق</div></div></div>
        <div class="stat-card"><?= icon('send', 'ic') ?><div><div class="num"><?= $pending_withdraws ?></div><div class="lbl">سحب معلّق</div></div></div>
        <div class="stat-card"><?= icon('coin', 'ic') ?><div><div class="num"><?= number_format($points_total) ?></div><div class="lbl">عملات بالتداول</div></div></div>
      </div>
      <div class="admin-box">
        <p style="color:var(--muted);font-size:13px">نسبة الربح الحالية: <?= e(setting('profit_split_admin')) ?>% للإدارة / <?= e(setting('profit_split_user')) ?>% للمستخدم — عدّلها من تبويب الإعدادات بحسب عوائد MoneyTag الفعلية.</p>
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
            <input name="color" type="color" value="#a3182c" title="لون بطاقة القسم" style="padding:4px;height:42px">
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
              <div class="cat-tile-mini" style="background:<?= e($c['color'] ?: '#271419') ?>">
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
        <form method="post" action="?action=admin_save_settings" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="redirect_tab" value="products">
          <label>مفتاح Satofill العام (Public Key)<input name="satofill_public_key" value="<?= e(setting('satofill_public_key')) ?>" placeholder="public key"></label>
          <label>توكن Satofill الخاص (Private Token)<input type="password" name="satofill_private_key" value="" placeholder="<?= setting('satofill_private_key') ? '•••••••• (محفوظ، اتركه فارغاً للاحتفاظ به)' : 'private token' ?>" autocomplete="off"></label>
          <button class="btn btn-ghost"><?= icon('check', 'ic-sm') ?>حفظ مفاتيح Satofill</button>
        </form>
        <form method="post" action="?action=admin_satofill_sync">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="btn btn-primary"><?= icon('refresh', 'ic-sm') ?>مزامنة الآن</button>
        </form>
      </div>
      <div class="admin-box">
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
                <button name="decision" value="approve" class="btn btn-success"><?= icon('check', 'ic-sm') ?></button>
                <button name="decision" value="reject" class="btn btn-danger"><?= icon('x', 'ic-sm') ?></button>
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

    <?php elseif ($tab === 'tasks'):
        $tasks = db()->query("SELECT * FROM tasks ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <h3><?= icon('plus', 'ic') ?>إضافة مهمة (مثل: زيارة رابط لمدة معينة)</h3>
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
            <td><span class="icon-badge <?= $t['active'] ? 'ok' : 'no' ?>"><?= icon($t['active'] ? 'check' : 'x', 'ic-sm') ?></span></td>
            <td><form method="post" action="?action=admin_toggle_task" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-ghost"><?= icon('toggle', 'ic-sm') ?>تبديل</button></form></td>
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
            'live_ticker' => 'شريط أرباح المستخدمين المباشر',
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
            <li draggable="true" data-key="<?= e($k2) ?>" class="home-section-row<?= $isHidden ? ' is-hidden' : '' ?>" style="display:flex;align-items:center;gap:10px;background:#1d1014;border:1px solid #3a1c23;border-radius:10px;padding:10px 14px;cursor:grab">
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
        <table>
          <tr><th>الاسم</th><th>البريد</th><th>النقاط</th><th>الدور</th><th>الحالة</th><th>إجراء</th></tr>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td><td><?= (int)$u['points'] ?></td>
            <td><?= e($u['role']) ?></td><td><?= icon($u['is_banned'] ? 'x' : 'check', 'ic-sm') ?></td>
            <td style="display:flex;gap:4px;flex-wrap:wrap">
              <form method="post" action="?action=admin_user_action"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="op" value="<?= $u['is_banned'] ? 'unban' : 'ban' ?>"><button class="btn btn-ghost"><?= $u['is_banned'] ? 'رفع حظر' : 'حظر' ?></button></form>
              <form method="post" action="?action=admin_user_action" style="display:flex;gap:4px"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="op" value="addpoints"><input type="number" name="points" placeholder="عملات" style="width:80px;padding:4px"><button class="btn btn-primary">إضافة</button></form>
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

    <?php elseif ($tab === 'providers'):
        $providers = db()->query("SELECT * FROM providers ORDER BY id DESC")->fetchAll();
    ?>
      <div class="admin-box">
        <h4 style="margin:0 0 8px">إضافة مزوّد جديد</h4>
        <p style="color:var(--muted);font-size:13px;margin-top:0">كل مزوّد له مفتاح عام، مفتاح خاص، وبيانات اتصال بقاعدة بياناته الخارجية. الحقول الحساسة تبقى محفوظة ولا تُعرض كاملة بعد الحفظ.</p>
        <form method="post" action="?action=admin_save_provider" class="formrow">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <label>اسم المزوّد<input name="name" placeholder="مثال: مزوّد المنتجات الثاني" required></label>
          <label>المفتاح العام<input name="public_key" placeholder="public key"></label>
          <label>المفتاح الخاص<input type="password" name="private_key" placeholder="private key" autocomplete="off"></label>
          <label>نوع قاعدة البيانات
            <select name="db_driver">
              <option value="mysql">MySQL</option>
              <option value="pgsql">PostgreSQL</option>
              <option value="sqlite">SQLite</option>
            </select>
          </label>
          <label>عنوان السيرفر (Host)<input name="db_host" placeholder="db.example.com"></label>
          <label>المنفذ (Port، اختياري)<input name="db_port" placeholder="3306"></label>
          <label>اسم قاعدة البيانات<input name="db_name" placeholder="provider_db"></label>
          <label>مستخدم قاعدة البيانات<input name="db_user" placeholder="db_user"></label>
          <label>كلمة مرور قاعدة البيانات<input type="password" name="db_pass" autocomplete="off"></label>
          <button class="btn btn-primary"><?= icon('plus', 'ic-sm') ?>إضافة المزوّد</button>
        </form>
        <hr style="border-color:#341c22;margin:18px 0">
        <h4 style="margin:0 0 8px">المزوّدون الحاليون</h4>
        <table class="admin-table">
          <thead><tr><th>الاسم</th><th>المفتاح العام</th><th>قاعدة البيانات</th><th>الحالة</th><th>آخر اختبار اتصال</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($providers as $pv): ?>
            <tr>
              <td><?= e($pv['name']) ?></td>
              <td><code><?= $pv['public_key'] ? e(substr($pv['public_key'], 0, 6)) . '••••' : '—' ?></code></td>
              <td style="font-size:12px;color:var(--muted)"><?= e($pv['db_driver']) ?> · <?= e($pv['db_host'] ?: '—') ?> / <?= e($pv['db_name'] ?: '—') ?></td>
              <td><?= $pv['active'] ? '<span style="color:#22c55e">مفعّل</span>' : '<span style="color:var(--muted)">معطّل</span>' ?></td>
              <td style="font-size:12px;color:var(--muted)"><?= e($pv['last_test_result'] ?: '—') ?></td>
              <td style="display:flex;gap:6px;flex-wrap:wrap">
                <form method="post" action="?action=admin_test_provider_connection" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$pv['id'] ?>"><button class="btn btn-ghost"><?= icon('rocket', 'ic-sm') ?>اختبار الاتصال</button></form>
                <form method="post" action="?action=admin_toggle_provider" style="display:inline"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$pv['id'] ?>"><button class="btn btn-ghost"><?= icon('toggle', 'ic-sm') ?>تبديل</button></form>
                <form method="post" action="?action=admin_delete_provider" style="display:inline" onsubmit="return confirm('حذف هذا المزوّد؟')"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$pv['id'] ?>"><button class="btn btn-danger"><?= icon('trash', 'ic-sm') ?></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$providers): ?>
            <tr><td colspan="6" style="color:var(--muted)">لا يوجد مزوّدون مضافون بعد.</td></tr>
          <?php endif; ?>
          </tbody>
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
          <label>سعر النقطة بالدولار<input name="points_rate" value="<?= e(setting('points_rate')) ?>"></label>
          <label>الحد الأدنى للسحب $<input name="min_withdraw_usd" value="<?= e(setting('min_withdraw_usd')) ?>"></label>
          <label>السحب الفوري (بدون مراجعة الإدارة)
            <select name="auto_approve_withdraw">
              <option value="0" <?= setting('auto_approve_withdraw', '0') === '0' ? 'selected' : '' ?>>معطّل (مراجعة يدوية)</option>
              <option value="1" <?= setting('auto_approve_withdraw', '0') === '1' ? 'selected' : '' ?>>مفعّل (سحب فوري تلقائي)</option>
            </select>
          </label>
          <label>مكافأة الكابتشا<input name="captcha_reward" value="<?= e(setting('captcha_reward')) ?>"></label>
          <label>أقصى كابتشا باليوم<input name="captcha_max_per_day" value="<?= e(setting('captcha_max_per_day')) ?>"></label>
          <label>أقصى مهام باليوم<input name="task_max_per_day" value="<?= e(setting('task_max_per_day')) ?>"></label>
          <label>نسبة ربح الإدارة %<input name="profit_split_admin" value="<?= e(setting('profit_split_admin')) ?>"></label>
          <label>نسبة ربح المستخدم %<input name="profit_split_user" value="<?= e(setting('profit_split_user')) ?>"></label>
          <label>ارتفاع صورة بطاقة المنتج (px)<input type="number" name="product_image_height" value="<?= e(setting('product_image_height')) ?>" min="60" max="400"></label>
          <label>عرض بطاقة القسم (px)<input type="number" name="cat_tile_size" value="<?= e(setting('cat_tile_size')) ?>" min="80" max="320"></label>
          <label>نص زر الشراء<input name="buy_button_text" value="<?= e(setting('buy_button_text')) ?>"></label>
          <label>نص عدم وجود منتجات<input name="empty_products_text" value="<?= e(setting('empty_products_text')) ?>"></label>
          <label>نص أسفل الصفحة (الفوتر، اتركه فارغاً للنص الافتراضي)<input name="footer_text" value="<?= e(setting('footer_text')) ?>" placeholder="© 2026 Yassota — جميع الحقوق محفوظة"></label>
          <label>ثيمات جاهزة (اختر ثيماً لتعبئة الألوان تلقائياً)
            <select onchange="applyThemePreset(this.value)">
              <option value="">— اختر ثيماً جاهزاً —</option>
              <option value="#e6294b,#ff4d4d">كلاسيك أحمر</option>
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
          <label>نسبة هامش ربح Satofill %<input type="number" step="0.1" name="satofill_markup_percent" value="<?= e(setting('satofill_markup_percent', 15)) ?>"></label>
          <label>تفعيل الإعلانات (عند ضغط زر فقط)
            <select name="ad_enabled">
              <option value="1" <?= setting('ad_enabled') === '1' ? 'selected' : '' ?>>مفعّلة</option>
              <option value="0" <?= setting('ad_enabled') === '0' ? 'selected' : '' ?>>معطّلة</option>
            </select>
          </label>
          <label>رمز منطقة الإعلان (Zone ID)<input name="ad_zone_id" value="<?= e(setting('ad_zone_id')) ?>"></label>
          <label>إعلان فوري عند دخول الموقع (مرة كل جلسة، مع زر تخطي بعد ٥ ثواني)
            <select name="entry_ad_enabled">
              <option value="1" <?= setting('entry_ad_enabled', '1') === '1' ? 'selected' : '' ?>>مفعّل</option>
              <option value="0" <?= setting('entry_ad_enabled', '1') === '0' ? 'selected' : '' ?>>معطّل</option>
            </select>
          </label>
          <label>شريط أرباح المستخدمين المباشر (الرئيسية)
            <select name="live_ticker_enabled">
              <option value="1" <?= setting('live_ticker_enabled') === '1' ? 'selected' : '' ?>>مفعّل</option>
              <option value="0" <?= setting('live_ticker_enabled') === '0' ? 'selected' : '' ?>>معطّل</option>
            </select>
          </label>
          <label>تيليجرام خدمة العملاء<input name="support_telegram" value="<?= e(setting('support_telegram')) ?>" placeholder="@username"></label>
          <label>اسم بوت تيليجرام لتسجيل الدخول (بدون @)<input name="telegram_bot_username" value="<?= e(setting('telegram_bot_username')) ?>" placeholder="مثال: YassotaBot"></label>
          <label>توكن بوت تيليجرام (BOT_TOKEN)<input type="password" name="bot_token" value="" placeholder="<?= setting('bot_token') ? '•••••••• (محفوظ، اتركه فارغاً للاحتفاظ به)' : 'من @BotFather' ?>" autocomplete="off"></label>
          <label>آيدي المالك على تيليجرام (OWNER_ID)<input name="owner_id" value="<?= e(setting('owner_id')) ?>" placeholder="آيدي حسابك الرقمي، احصل عليه من @userinfobot"></label>
          <label>رمز تحقق Google Search Console<input name="google_site_verification" value="<?= e(setting('google_site_verification')) ?>" placeholder="محتوى meta tag فقط بدون الوسم"></label>
          <label>تفعيل Google AdSense
            <select name="adsense_enabled">
              <option value="0" <?= setting('adsense_enabled', '0') === '0' ? 'selected' : '' ?>>معطّل</option>
              <option value="1" <?= setting('adsense_enabled', '0') === '1' ? 'selected' : '' ?>>مفعّل</option>
            </select>
          </label>
          <label>معرّف ناشر AdSense<input name="adsense_publisher_id" value="<?= e(setting('adsense_publisher_id')) ?>" placeholder="ca-pub-XXXXXXXXXXXXXXXX"></label>
          <label>محتوى ملف ads.txt<textarea name="ads_txt_content" rows="2" placeholder="google.com, pub-XXXXXXXXXXXXXXXX, DIRECT, f08c47fec0942fa0"><?= e(setting('ads_txt_content')) ?></textarea></label>
          <label>موديل OpenRouter<input name="openrouter_model" value="<?= e(setting('openrouter_model')) ?>" list="orModels" placeholder="meta-llama/llama-3.3-70b-instruct:free">
            <datalist id="orModels">
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
              <option value="openai/gpt-4o-mini">
              <option value="openai/gpt-4o">
              <option value="openai/gpt-4.1-mini">
              <option value="anthropic/claude-3.5-haiku">
              <option value="anthropic/claude-3.5-sonnet">
              <option value="anthropic/claude-3.7-sonnet">
              <option value="google/gemini-2.5-flash">
              <option value="google/gemini-2.5-pro">
              <option value="deepseek/deepseek-chat-v3">
              <option value="x-ai/grok-2-1212">
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
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button class="btn btn-primary" onclick="document.querySelector('form[action=\'?action=admin_save_settings\']').submit()"><?= icon('check', 'ic-sm') ?>حفظ الإعدادات</button>
          <button type="button" class="btn btn-ghost" onclick="testOpenRouter()"><?= icon('rocket', 'ic-sm') ?>اختبار الاتصال بـ OpenRouter</button>
          <form method="post" action="?action=admin_clear_cache" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <button type="submit" class="btn btn-ghost"><?= icon('rocket', 'ic-sm') ?>تفريغ الذاكرة المؤقتة (Cache Clear)</button>
          </form>
        </div>
        <hr style="border-color:#341c22;margin:18px 0">
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
              <td><?= $ok['active'] ? '<span style="color:#22c55e">مفعّل</span>' : '<span style="color:var(--muted)">معطّل</span>' ?></td>
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
        <hr style="border-color:#341c22;margin:18px 0">
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
  <a href="?page=tasks" class="<?= $page === 'tasks' ? 'active' : '' ?>"><?= icon('tasks', 'ic') ?>مهام</a>
  <a href="?page=earn" class="fab <?= $page === 'earn' ? 'active' : '' ?>"><span class="fab-box"><?= icon('coin', 'ic') ?></span>اكسب</a>
  <a href="?page=wallet" class="<?= $page === 'wallet' ? 'active' : '' ?>"><?= icon('wallet', 'ic') ?>محفظتي</a>
  <a href="?page=orders" class="<?= $page === 'orders' ? 'active' : '' ?>"><?= icon('orders', 'ic') ?>طلباتي</a>
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

<?php
$showEntryAd = false;
if (!($page === 'login' && !$user) && setting('entry_ad_enabled', '1') === '1'
    && setting('ad_enabled', '1') === '1' && setting('ad_zone_id', '') !== ''
    && empty($_SESSION['entry_ad_shown'])) {
    $showEntryAd = true;
    $_SESSION['entry_ad_shown'] = true;
}
?>
<script>
const CSRF = "<?= csrf_token() ?>";
const LOGGED_IN = <?= $user ? 'true' : 'false' ?>;
const AD_ENABLED = <?= setting('ad_enabled', '1') === '1' ? 'true' : 'false' ?>;
const AD_ZONE_ID = "<?= e(setting('ad_zone_id', '')) ?>";
const SHOW_ENTRY_AD = <?= $showEntryAd ? 'true' : 'false' ?>;
let __adLoaded = false;
function loadAdNetworkOnce(){
  if (__adLoaded || !AD_ENABLED || !AD_ZONE_ID) return;
  __adLoaded = true;
  const s = document.createElement('script');
  s.dataset.zone = AD_ZONE_ID;
  s.src = 'https://al5sm.com/tag.min.js';
  document.body.appendChild(s);
}
// الإعلانات تُحمَّل فقط عند ضغط المستخدم على أي زر في الموقع، وليس عند تحميل الصفحة
document.addEventListener('click', (e) => {
  if (e.target.closest('.btn, button')) loadAdNetworkOnce();
}, { capture: true });
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
document.addEventListener('DOMContentLoaded', () => {
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
  try {
    const r = await fetch('?action=' + action, { method: 'POST', body: data });
    return await r.json();
  } catch (e) {
    return { ok: false, msg: 'انتهت صلاحية الجلسة أو تعذّر الاتصال، يرجى تحديث الصفحة والمحاولة مجدداً.' };
  }
}
async function uploadInto(input, targetId){
  if (!input.files || !input.files[0]) return;
  const d = new FormData();
  d.append('file', input.files[0]);
  d.append('field', targetId);
  d.append('csrf', CSRF);
  toast('جاري رفع الملف...');
  try {
    const r = await fetch('?action=admin_upload', { method: 'POST', body: d });
    const res = await r.json();
    if (res.ok) { document.getElementById(targetId).value = res.url; toast('تم رفع الملف بنجاح'); }
    else toast(res.msg || 'فشل رفع الملف.');
  } catch (e) {
    toast('انتهت صلاحية الجلسة أو تعذّر الاتصال، يرجى تحديث الصفحة والمحاولة مجدداً.');
  }
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
    msgEl.style.color = '#22c55e';
    msgEl.textContent = 'تم تطبيق خصم ' + res.discount_percent + '%';
    document.getElementById('buyFinalPrice').textContent = res.new_price + '$';
  } else {
    buyAppliedCoupon = null;
    msgEl.style.color = 'var(--danger)';
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
    document.getElementById('captchaAnswer').value = '';
    if (!res.ok) { toast(res.msg); return; }
    openAdGate(res.ad_will_show, res.wait_seconds || 5, () => {
      post('api_claim_captcha_reward', new FormData()).then(r2 => {
        toast(r2.msg);
        if (r2.ok) loadCaptcha();
      }).catch(() => toast('تعذر الاتصال بالخادم، حاول مجدداً.'));
    });
  }).catch(() => toast('تعذر الاتصال بالخادم، حاول مجدداً.'));
}

let __adGateTimer = null;
let __adGateOnDone = null;
function openAdGate(adWillShow, seconds, onDone){
  __adGateOnDone = onDone;
  const modal = document.getElementById('adGateModal');
  const msg = document.getElementById('adGateMsg');
  const xBtn = document.getElementById('adGateClose');
  const timerEl = document.getElementById('adGateTimer');
  const slot = document.getElementById('adGateSlot');
  msg.textContent = adWillShow ? 'جارٍ تحميل الإعلان...' : 'لا يوجد إعلان متاح حالياً، انتظر العداد للحصول على المكافأة.';
  slot.innerHTML = '';
  xBtn.disabled = true;
  modal.style.display = 'flex';
  if (adWillShow) loadAdNetworkOnce();
  let remain = seconds;
  timerEl.textContent = remain;
  if (__adGateTimer) clearInterval(__adGateTimer);
  __adGateTimer = setInterval(() => {
    remain--;
    timerEl.textContent = Math.max(remain, 0);
    if (remain <= 0) {
      clearInterval(__adGateTimer);
      xBtn.disabled = false;
      timerEl.textContent = '✓';
    }
  }, 1000);
}
function closeAdGate(){
  document.getElementById('adGateModal').style.display = 'none';
  if (__adGateTimer) clearInterval(__adGateTimer);
  const cb = __adGateOnDone;
  __adGateOnDone = null;
  if (cb) cb();
}
if (SHOW_ENTRY_AD) document.addEventListener('DOMContentLoaded', () => openAdGate(true, 5, () => {}));
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
function testOpenRouter(){
  toast('جاري الاختبار...');
  const d = new FormData(); d.append('csrf', CSRF);
  fetch('?action=admin_test_openrouter', { method: 'POST', body: d })
    .then(r => r.json())
    .then(res => toast(res.msg))
    .catch(() => toast('فشل الاختبار: انتهت صلاحية الجلسة أو تعذّر الاتصال، يرجى تحديث الصفحة.'));
}
function aiGenerateProduct(){
  const name = document.getElementById('pname').value.trim();
  const price = document.getElementById('pprice').value.trim();
  if (!name) return toast('أدخل اسم المنتج أولاً.');
  toast('جاري التوليد بالذكاء الاصطناعي...');
  const d = new FormData(); d.append('csrf', CSRF); d.append('name', name); d.append('price', price); d.append('gen_image', '1');
  fetch('?action=admin_ai_generate', { method: 'POST', body: d })
    .then(r => r.json())
    .then(res => {
      if (!res.ok) return toast(res.msg);
      document.getElementById('pdesc').value = res.description || '';
      if (res.meta_description) document.getElementById('pmeta').value = res.meta_description;
      if (res.image) document.getElementById('pimage').value = res.image;
      toast(res.image ? 'تم توليد الوصف والصورة وSEO بنجاح' : 'تم توليد الوصف وSEO' + (res.image_error ? ' (تعذر توليد الصورة: ' + res.image_error + ')' : ''));
    })
    .catch(() => toast('فشل التوليد: انتهت صلاحية الجلسة أو تعذّر الاتصال، يرجى تحديث الصفحة.'));
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
