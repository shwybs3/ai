<?php
/**
 * نسخ هذا الملف إلى config.php وتعديل القيم الحقيقية.
 * config.php غير مرفوع على Git لحماية بياناتك.
 */

// ===== قاعدة البيانات (MySQL على cPanel) =====
define('DB_DRIVER', 'mysql');           // mysql أو sqlite
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// ===== الأدمن =====
define('ADMIN_EMAIL', 'sadoo1234999@gmail.com'); // البريد الوحيد المسموح له بالوصول للوحة الإدارة

// ===== Google OAuth =====
define('GOOGLE_CLIENT_ID', '');
define('GOOGLE_CLIENT_SECRET', '');
define('GOOGLE_REDIRECT_URI', ''); // مثال: https://yourdomain.com/index.php?action=google_callback

// ===== بوت تيليجرام =====
define('BOT_TOKEN', '');
define('OWNER_ID', '');

// ===== MoneyTag =====
define('MONEYTAG_SCRIPT', ''); // كود/سكربت الإعلانات الخاص بك

// ===== عام =====
define('SITE_URL', ''); // رابط الموقع بدون / في النهاية
