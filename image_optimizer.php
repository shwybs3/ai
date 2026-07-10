<?php
/**
 * image_optimizer.php — نظام معالجة الصور الموحّد لمنصة Yassota Store
 * Dev By SAAD - HAMADO · Tele @layos_he
 *
 * يوفر:
 *  - تحويل كل صورة مرفوعة إلى AVIF (إن كان مدعوماً على السيرفر) أو WebP كخيار افتراضي آمن.
 *  - ضغط ذكي حسب نوع الصورة (جودة مختلفة للصور الفوتوغرافية عن صور الشعارات/الرسومات).
 *  - توليد 4 أحجام تلقائياً: thumb / small / medium / large لكل صورة مرفوعة.
 *  - حذف الصور القديمة تلقائياً عند استبدالها (بدل تركها تتراكم على الاستضافة).
 *  - إعادة كتابة الروابط لتخرج من CDN تلقائياً إن تم تفعيل CDN_BASE_URL في config.php (بدون أي تعديل إضافي).
 *  - حماية من الملفات الضارة: يعاد ترميز كل صورة من الصفر (pixel-by-pixel) عبر GD، ما يزيل أي كود
 *    مخفي (PHP/HTML/JS) قد يكون مطموراً داخل الملف حتى لو كان امتداده يبدو صورة سليمة.
 */

const IO_ALLOWED_MIMES = [
    'image/jpeg' => IMAGETYPE_JPEG,
    'image/png'  => IMAGETYPE_PNG,
    'image/webp' => IMAGETYPE_WEBP,
    'image/gif'  => IMAGETYPE_GIF,
];

const IO_DEFAULT_SIZES = [
    'thumb'  => 150,
    'small'  => 400,
    'medium' => 800,
    'large'  => 1600,
];

/**
 * يحدد أفضل صيغة إخراج متاحة على السيرفر: AVIF إن كانت مكتبة GD تدعمها، وإلا WebP.
 */
function io_best_format(): string
{
    static $fmt = null;
    if ($fmt !== null) return $fmt;
    return $fmt = (function_exists('imageavif') && function_exists('imagecreatefromavif')) ? 'avif' : 'webp';
}

/**
 * إعادة كتابة رابط نسبي (مثل uploads/products/xxx.webp) ليخرج عبر CDN تلقائياً
 * إذا تم تعريف CDN_BASE_URL في config.php (مثال: define('CDN_BASE_URL','https://cdn.yassota.com');).
 * وإلا يعاد الرابط كما هو (نسبي لجذر الموقع) دون أي تغيير في السلوك الحالي.
 */
function cdn_url(string $relPath): string
{
    if ($relPath === '' || preg_match('#^https?://#i', $relPath)) return $relPath;
    if (defined('CDN_BASE_URL') && CDN_BASE_URL !== '') {
        return rtrim(CDN_BASE_URL, '/') . '/' . ltrim($relPath, '/');
    }
    return $relPath;
}

/**
 * تحقق حقيقي من كون الملف صورة سليمة (وليس مجرد اسم/امتداد ملف صورة).
 * يرفض أي نوع غير مسموح به (بما فيها SVG لأنها قد تحتوي جافاسكربت قابل للتنفيذ = XSS).
 */
function io_validate_image(array $file, int $maxBytes = 8 * 1024 * 1024): array
{
    if (empty($file) || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => 'فشل رفع الملف.'];
    }
    if ($file['size'] > $maxBytes) {
        return ['ok' => false, 'msg' => 'حجم الملف كبير جداً (الحد الأقصى ' . round($maxBytes / 1024 / 1024, 1) . 'MB).'];
    }
    // فحص حقيقي لمحتوى الملف (وليس فقط الامتداد أو الاسم المُرسل من المتصفح)
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !isset($info['mime']) || !isset(IO_ALLOWED_MIMES[$info['mime']])) {
        return ['ok' => false, 'msg' => 'الملف ليس صورة صالحة أو صيغته غير مدعومة (jpg/png/webp/gif فقط).'];
    }
    // فحص إضافي عبر finfo كطبقة حماية ثانية ضد الملفات المموّهة
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($realMime !== $info['mime']) {
            return ['ok' => false, 'msg' => 'تعذّر التحقق من نوع الملف الحقيقي.'];
        }
    }
    // حماية إضافية: رفض أي ملف يحتوي توقيع كود قابل للتنفيذ في بداياته (دفاع إضافي احترازي،
    // الحماية الحقيقية والأساسية هي إعادة ترميز الصورة بالكامل عبر GD أدناه).
    $head = @file_get_contents($file['tmp_name'], false, null, 0, 512) ?: '';
    if (stripos($head, '<?php') !== false || stripos($head, '<%') !== false || stripos($head, '<script') !== false) {
        return ['ok' => false, 'msg' => 'تم رفض الملف لأسباب أمنية.'];
    }
    return ['ok' => true, 'type' => $info[2], 'mime' => $info['mime'], 'w' => $info[0], 'h' => $info[1]];
}

function io_load_gd($tmpPath, int $type)
{
    switch ($type) {
        case IMAGETYPE_JPEG: return @imagecreatefromjpeg($tmpPath);
        case IMAGETYPE_PNG:  return @imagecreatefrompng($tmpPath);
        case IMAGETYPE_WEBP: return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false;
        case IMAGETYPE_GIF:  return @imagecreatefromgif($tmpPath);
        default: return false;
    }
}

/**
 * تصغير الصورة بحيث تتناسب داخل maxDim x maxDim (بدون تكبير الصور الأصغر من الحجم المطلوب).
 */
function io_resize_contain($src, int $maxDim)
{
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= $maxDim && $h <= $maxDim) return $src; // لا داعي للتصغير
    $ratio = min($maxDim / $w, $maxDim / $h);
    $nw = max(1, (int)round($w * $ratio));
    $nh = max(1, (int)round($h * $ratio));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/**
 * ضغط ذكي: جودة أعلى للصور ذات الألوان القليلة (شعارات/رسومات من نوع PNG)
 * وجودة أخف قليلاً للصور الفوتوغرافية لتقليل الحجم دون فقدان واضح في الجودة.
 */
function io_quality_for(int $originalType, string $label): int
{
    $isGraphic = $originalType === IMAGETYPE_PNG;
    if ($label === 'thumb') return $isGraphic ? 80 : 68;
    if ($label === 'small') return $isGraphic ? 85 : 74;
    if ($label === 'large') return $isGraphic ? 90 : 80;
    return $isGraphic ? 88 : 76; // medium
}

function io_encode($img, string $path, string $format, int $quality): bool
{
    if ($format === 'avif' && function_exists('imageavif')) {
        return @imageavif($img, $path, $quality);
    }
    if (function_exists('imagewebp')) {
        return @imagewebp($img, $path, $quality);
    }
    // احتياط أخير لو كانت مكتبة GD على السيرفر لا تدعم WebP إطلاقاً (نادر جداً)
    return @imagejpeg($img, $path, $quality);
}

/**
 * يحذف بأمان الصور القديمة المرتبطة بحقل ما (نصوص روابط نسبية داخل uploads/ فقط،
 * لمنع أي محاولة حذف ملفات خارج مجلد الرفع عبر مسارات ملتوية).
 */
function io_delete_old(array $relPaths): void
{
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    if (!$uploadsRoot) return;
    foreach ($relPaths as $rel) {
        if (!$rel || preg_match('#^https?://#i', $rel)) continue; // روابط خارجية لا تُحذف
        $rel = ltrim($rel, '/');
        if (!str_starts_with($rel, 'uploads/')) continue;
        $full = realpath(__DIR__ . '/' . $rel);
        if ($full && str_starts_with($full, $uploadsRoot) && is_file($full)) {
            @unlink($full);
        }
        // نحذف أيضاً الأحجام الشقيقة المولّدة معها (نفس الاسم الأساسي بلاحقات مختلفة)
        $base = preg_replace('/_(thumb|small|medium|large)\.[a-z0-9]+$/i', '', $full ?: '');
        if ($base && $base !== $full) {
            foreach (glob($base . '_*.{webp,avif,jpg,jpeg,png,gif}', GLOB_BRACE) ?: [] as $sibling) {
                if (str_starts_with(realpath($sibling) ?: '', $uploadsRoot)) @unlink($sibling);
            }
        }
    }
}

/**
 * الدالة الرئيسية: تعالج ملفاً مرفوعاً واحداً وتنتج 4 أحجام محسّنة (thumb/small/medium/large)
 * بصيغة AVIF أو WebP، مع حذف الصور القديمة تلقائياً وإرجاع روابط جاهزة (مع دعم CDN).
 *
 * @param array  $file       عنصر من $_FILES
 * @param string $subdir     مجلد فرعي داخل uploads/ (مثل: products, banners, avatars, receipts, site)
 * @param array  $oldRelPaths روابط نسبية قديمة يجب حذفها بعد نجاح الرفع الجديد (اختياري)
 * @param array  $sizes      أحجام مخصّصة، افتراضياً IO_DEFAULT_SIZES (4 أحجام)
 */
function io_process_image_upload(array $file, string $subdir, array $oldRelPaths = [], array $sizes = null): array
{
    $sizes = $sizes ?? IO_DEFAULT_SIZES;
    $check = io_validate_image($file);
    if (!$check['ok']) return $check;

    $src = io_load_gd($file['tmp_name'], $check['type']);
    if (!$src) return ['ok' => false, 'msg' => 'تعذّر معالجة الصورة (ملف تالف).'];

    $format = io_best_format();
    $ext = $format === 'avif' ? 'avif' : 'webp';

    $destDir = __DIR__ . '/uploads/' . trim($subdir, '/');
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    $base = bin2hex(random_bytes(10));
    $urls = [];
    $ok = true;

    foreach ($sizes as $label => $maxDim) {
        $resized = io_resize_contain($src, (int)$maxDim);
        $quality = io_quality_for($check['type'], $label);
        $filename = $base . '_' . $label . '.' . $ext;
        $path = $destDir . '/' . $filename;
        $saved = io_encode($resized, $path, $format, $quality);
        if ($resized !== $src) imagedestroy($resized);
        if (!$saved) { $ok = false; continue; }
        $rel = 'uploads/' . trim($subdir, '/') . '/' . $filename;
        $urls[$label] = cdn_url($rel);
    }
    imagedestroy($src);

    if (!$ok || empty($urls)) {
        return ['ok' => false, 'msg' => 'فشل حفظ الصورة بعد المعالجة (تأكد من دعم WebP/AVIF على السيرفر).'];
    }

    // نظّف الصور القديمة بعد التأكد من نجاح رفع الصور الجديدة
    if (!empty($oldRelPaths)) io_delete_old($oldRelPaths);

    $primary = $urls['medium'] ?? $urls['large'] ?? reset($urls);
    return ['ok' => true, 'format' => $format, 'urls' => $urls, 'url' => $primary];
}

/**
 * معالجة عدة ملفات دفعة واحدة (للسحب والإفلات / الرفع المتعدد).
 * @param array $files  مصفوفة عناصر $_FILES بصيغة متعددة (name[], tmp_name[], ...)
 */
function io_process_multi_upload(array $files, string $subdir, array $sizes = null): array
{
    $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
    $results = [];
    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $single = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i],
        ];
        $results[] = io_process_image_upload($single, $subdir, [], $sizes);
    }
    return $results;
}
