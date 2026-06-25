<?php
/**
 * قالب جاهز: بوت تيليجرام لتحميل الفيديوهات (يوتيوب/تيك توك/فيسبوك/تويتر وغيرها)
 * يعتمد على أداة yt-dlp المثبتة على السيرفر (مفتوحة المصدر، تُستخدم للتحميل الشخصي).
 *
 * التركيب:
 * 1. تأكد من تثبيت yt-dlp على السيرفر: pip install -U yt-dlp  (أو apt install yt-dlp)
 * 2. عدّل BOT_TOKEN بالأسفل بتوكن بوتك من @BotFather.
 * 3. فعّل الـ Webhook:
 *    https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/video_downloader_bot.php
 * 4. ارفع الملف على سيرفرك خارج مجلد uploads/ (هذا الملف قالب مرجعي فقط، عدّله بحرية).
 *
 * ملاحظة: التحميل من بعض المنصات قد يخضع لشروط استخدامها، استخدم البوت لأغراض شخصية/مشروعة فقط.
 */

define('BOT_TOKEN', 'ضع_توكن_البوت_هنا');
define('YTDLP_PATH', 'yt-dlp'); // عدّلها لمسار كامل إن لزم، مثل /usr/local/bin/yt-dlp
define('TMP_DIR', __DIR__ . '/tmp_downloads');

if (!is_dir(TMP_DIR)) mkdir(TMP_DIR, 0755, true);

function tg_api(string $method, array $params = [], ?string $filePath = null, string $fileField = '')
{
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/$method";
    if ($filePath) {
        $params[$fileField] = new CURLFile($filePath);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120]);
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
    }
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function send_text(string $chatId, string $text): void
{
    tg_api('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
}

function is_valid_url(string $s): bool
{
    return (bool)filter_var($s, FILTER_VALIDATE_URL);
}

$update = json_decode(file_get_contents('php://input'), true);
if (!$update || empty($update['message'])) { echo 'ok'; exit; }

$msg = $update['message'];
$chatId = (string)$msg['chat']['id'];
$text = trim($msg['text'] ?? '');

if ($text === '/start') {
    send_text($chatId, "👋 أهلاً بك!\nأرسل رابط فيديو من يوتيوب/تيك توك/فيسبوك/تويتر وسأرسل لك الفيديو مباشرة.");
    exit;
}

if (!is_valid_url($text)) {
    send_text($chatId, '⚠️ أرسل رابط فيديو صالح فقط.');
    exit;
}

send_text($chatId, '⏳ جاري تحميل الفيديو، يرجى الانتظار...');

// اسم ملف فريد لكل طلب لتجنّب التعارض بين المستخدمين
$outTemplate = TMP_DIR . '/' . bin2hex(random_bytes(8)) . '.%(ext)s';

// تحميل بصيغة فيديو متوسطة الحجم (أقل من 50MB حد تيليجرام للبوتات العادية)
$cmd = YTDLP_PATH . ' -f "best[filesize<50M]/best" -o ' . escapeshellarg($outTemplate) . ' ' . escapeshellarg($text) . ' 2>&1';
exec($cmd, $outputLines, $exitCode);

$downloaded = glob(TMP_DIR . '/*');
usort($downloaded, fn($a, $b) => filemtime($b) - filemtime($a));
$file = $downloaded[0] ?? null;

if ($exitCode !== 0 || !$file || !is_file($file)) {
    send_text($chatId, '❌ تعذّر تحميل هذا الفيديو، تأكد من الرابط أو جرّب رابطاً آخر.');
    exit;
}

if (filesize($file) > 49 * 1024 * 1024) {
    send_text($chatId, '⚠️ حجم الفيديو أكبر من الحد المسموح به من تيليجرام للبوتات (50MB).');
} else {
    tg_api('sendVideo', ['chat_id' => $chatId, 'caption' => '✅ تم التحميل بنجاح'], $file, 'video');
}

@unlink($file);
echo 'ok';
