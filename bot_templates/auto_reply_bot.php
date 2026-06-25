<?php
/**
 * قالب جاهز: بوت تيليجرام بقائمة أزرار وردود تلقائية (نقطة بداية لأي بوت خدمة/دعم).
 *
 * التركيب:
 * 1. عدّل BOT_TOKEN بتوكن بوتك من @BotFather.
 * 2. فعّل الـ Webhook:
 *    https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://yourdomain.com/auto_reply_bot.php
 * 3. عدّل $menu والردود بالأسفل حسب حاجتك.
 */

define('BOT_TOKEN', 'ضع_توكن_البوت_هنا');

function tg_send(string $chatId, string $text, ?array $keyboard = null): void
{
    $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard !== null) $params['reply_markup'] = json_encode($keyboard);
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function main_menu(): array
{
    return ['keyboard' => [
        [['text' => '📦 خدماتنا'], ['text' => '💬 تواصل معنا']],
        [['text' => '❓ الأسئلة الشائعة']],
    ], 'resize_keyboard' => true];
}

// عدّل هذه الردود بما يناسب نشاطك
$replies = [
    '📦 خدماتنا' => '📦 نقدّم لكم مجموعة من الخدمات، تواصل معنا لمزيد من التفاصيل.',
    '💬 تواصل معنا' => '💬 يمكنك التواصل معنا مباشرة على: @your_support_username',
    '❓ الأسئلة الشائعة' => "❓ الأسئلة الشائعة:\n\n1- كيف أطلب الخدمة؟\nاضغط على «خدماتنا» من القائمة.\n\n2- ما وسائل الدفع المتاحة؟\nنوضحها عند التواصل المباشر.",
];

$update = json_decode(file_get_contents('php://input'), true);
if (!$update || empty($update['message'])) { echo 'ok'; exit; }

$msg = $update['message'];
$chatId = (string)$msg['chat']['id'];
$text = trim($msg['text'] ?? '');

if ($text === '/start') {
    tg_send($chatId, '👋 أهلاً بك! اختر من القائمة بالأسفل:', main_menu());
    exit;
}

if (isset($replies[$text])) {
    tg_send($chatId, $replies[$text], main_menu());
    exit;
}

tg_send($chatId, '🤖 لم أفهم طلبك، استخدم الأزرار بالأسفل من فضلك.', main_menu());
echo 'ok';
