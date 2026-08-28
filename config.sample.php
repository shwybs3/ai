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

// ===== Google AdMob (للتطبيق APK) =====
define('ADMOB_APP_ID', '');        // ca-app-pub-xxxx~xxxx
define('ADMOB_REWARDED_ID', '');   // وحدة الإعلان المكافأ (شاهد 30 ثانية)
define('ADMOB_INTERSTitial_ID', ''); // إعلان بيني (كابتشا 5 ثوانٍ)

// ===== OpenRouter AI =====
// المفتاح يمكن ضبطه أيضاً من لوحة الإدارة، وهذه قيمة افتراضية اختيارية
define('OPENROUTER_KEY', '');

// ===== عام =====
define('SITE_URL', ''); // رابط الموقع بدون / في النهاية

/* ======================================================================
 * الإعدادات التالية تُضبط من لوحة الإدارة (جدول settings) وليست ثوابت،
 * لكنها موثّقة هنا لتعرف ما يحتاجه كل نظام. لا تضع مفاتيح سرّية في هذا
 * الملف إن كان مرفوعًا على Git.
 *
 * — مصنع الدومينات (Admin → 🏭 مصنع الدومينات) —
 *   factory_root_domain   الدومين الجذر، مثال: yassota.com
 *   factory_docroot       مسار ملفات الدومين الشامل، مثال: public_html
 *   openrouter_model      نموذج التوليد، الافتراضي:
 *                         meta-llama/llama-3.1-8b-instruct:free  (مجاني)
 *   cpanel_host           https://server-host:2083
 *   cpanel_user           اسم مستخدم cPanel
 *   cpanel_token          توكِن cPanel API (من Manage API Tokens) — سرّي
 *
 * — النشرة البريدية (newsletter.php) —
 *   brevo_api_key         مفتاح Brevo v3 لإضافة المشتركين — سرّي
 *   brevo_list_id         رقم قائمة Brevo لإضافة العناوين إليها
 *
 * — إعلانات AdSense —
 *   adsense_publisher_id  ca-pub-xxxxxxxxxxxxxxxx (يفعّل سكربت الإعلانات)
 * ====================================================================== */
