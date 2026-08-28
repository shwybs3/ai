<?php
/**
 * error_logger.php — نظام تسجيل الأخطاء لموقع yassota.com
 * =========================================================
 * يلتقط كل خطأ (Warning / Notice / Fatal / Exception) ويكتبه بصيغة
 * واضحة جدًا يسهل فهمها وحلّها فورًا: نوع الخطأ + الرسالة + الملف + السطر
 * + مقتطف الكود المحيط + مسار الطلب + وقت الحدوث.
 *
 * - يكتب في جدول error_log بقاعدة البيانات (إن توفّرت) + ملف احتياطي
 *   خارج الـ webroot متى أمكن.
 * - في وضع التطوير (?debug=KEY أو DEBUG=1) يعرض الخطأ على الشاشة بشكل مقروء.
 * - لا يطبع أبدًا مفاتيح API أو التوكِنات: يُخفيها من أي رسالة قبل الحفظ.
 *
 * الاستخدام: require هذا الملف مبكرًا جدًا في index.php ثم استدعِ
 *            error_logger_boot();  (بعد تحميل config.php).
 */

if (!defined('YASSOTA_ERRLOG')) {
    define('YASSOTA_ERRLOG', 1);

    /** أنماط الأسرار التي يجب حجبها من أي رسالة قبل تخزينها. */
    function errlog_redact(string $s): string
    {
        // احجب أي قيمة تشبه مفتاح/توكِن طويل، وقيَم الثوابت الحساسة إن ظهرت.
        $s = preg_replace('/\b(sk-[A-Za-z0-9_\-]{8,}|AIza[A-Za-z0-9_\-]{20,}|ghp_[A-Za-z0-9]{20,})\b/', '‹redacted-token›', $s);
        foreach (['OPENROUTER_KEY', 'DB_PASS', 'GEMINI_KEY', 'CPANEL_TOKEN', 'CPANEL_PASS', 'NOWPAYMENTS_KEY', 'agent_anthropic_api_key'] as $c) {
            if (defined($c) && constant($c) !== '' && is_string(constant($c))) {
                $s = str_replace((string)constant($c), "‹redacted:$c›", $s);
            }
        }
        return $s;
    }

    /** مستوى الخطأ كنص مقروء. */
    function errlog_level_name(int $type): string
    {
        $map = [
            E_ERROR => 'FATAL', E_WARNING => 'WARNING', E_PARSE => 'PARSE',
            E_NOTICE => 'NOTICE', E_CORE_ERROR => 'CORE_ERROR', E_COMPILE_ERROR => 'COMPILE_ERROR',
            E_USER_ERROR => 'USER_ERROR', E_USER_WARNING => 'USER_WARNING', E_USER_NOTICE => 'USER_NOTICE',
            E_RECOVERABLE_ERROR => 'RECOVERABLE', E_DEPRECATED => 'DEPRECATED', E_USER_DEPRECATED => 'USER_DEPRECATED',
        ];
        return $map[$type] ?? ('ERR#' . $type);
    }

    /** مقتطف من الكود حول سطر الخطأ لتسهيل الفهم. */
    function errlog_snippet(string $file, int $line, int $ctx = 3): string
    {
        if (!$file || !is_readable($file)) return '';
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!$lines) return '';
        $from = max(0, $line - $ctx - 1);
        $to   = min(count($lines) - 1, $line + $ctx - 1);
        $out = [];
        for ($i = $from; $i <= $to; $i++) {
            $mark = ($i + 1 === $line) ? '>>' : '  ';
            $out[] = sprintf('%s %4d | %s', $mark, $i + 1, $lines[$i]);
        }
        return implode("\n", $out);
    }

    /** ملف السجل الاحتياطي (خارج webroot إن أمكن). */
    function errlog_file_path(): string
    {
        $dir = dirname(__DIR__);              // مجلد المشروع
        $parent = dirname($dir);
        $candidate = is_writable($parent) ? $parent . '/yassota_errors.log'
                                          : $dir . '/error.log';
        return $candidate;
    }

    /** كتابة سجل واحد بصيغة واضحة في القاعدة + الملف الاحتياطي. */
    function errlog_record(string $level, string $message, string $file, int $line, string $trace = ''): void
    {
        $message = errlog_redact($message);
        $trace   = errlog_redact($trace);
        $uri     = errlog_redact($_SERVER['REQUEST_URI'] ?? 'cli');
        $ts      = date('Y-m-d H:i:s');
        $snippet = errlog_snippet($file, $line);

        // 1) قاعدة البيانات (بصمت إن فشلت حتى لا يتحوّل مسجّل الأخطاء لمصدر خطأ)
        if (function_exists('db')) {
            try {
                $pdo = db();
                $pdo->prepare(
                    'INSERT INTO error_log (level, message, file, line, snippet, trace, uri, created_at)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([$level, mb_substr($message, 0, 2000), $file, $line,
                            mb_substr($snippet, 0, 2000), mb_substr($trace, 0, 4000), $uri, $ts]);
            } catch (Throwable $e) { /* تجاهل */ }
        }

        // 2) ملف احتياطي مقروء
        $block = "\n[$ts] $level: $message\n"
               . "    at $file:$line\n"
               . ($snippet ? "    ----- code -----\n" . preg_replace('/^/m', '    ', $snippet) . "\n" : '')
               . ($trace ? "    ----- trace -----\n" . preg_replace('/^/m', '    ', $trace) . "\n" : '');
        @file_put_contents(errlog_file_path(), $block, FILE_APPEND | LOCK_EX);
    }

    /** هل نعرض الأخطاء على الشاشة؟ (وضع التطوير فقط) */
    function errlog_debug_on(): bool
    {
        if (defined('YASSOTA_DEBUG') && YASSOTA_DEBUG) return true;
        $key = defined('YASSOTA_DEBUG_KEY') ? YASSOTA_DEBUG_KEY : '';
        return $key !== '' && (($_GET['debug'] ?? '') === $key);
    }

    /** عرض صفحة خطأ مقروءة للمطوّر. */
    function errlog_render(string $level, string $message, string $file, int $line, string $trace = ''): void
    {
        if (!errlog_debug_on()) return;
        $e = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
        $snippet = errlog_snippet($file, $line);
        echo '<div style="font-family:ui-monospace,Menlo,Consolas,monospace;direction:ltr;text-align:left;'
           . 'background:#0f172a;color:#e2e8f0;padding:18px 22px;border-radius:12px;margin:16px;'
           . 'border-left:5px solid #f43f5e;box-shadow:0 10px 30px rgba(0,0,0,.3);font-size:13px;line-height:1.6;overflow:auto">';
        echo '<div style="color:#f43f5e;font-weight:800;font-size:15px">⛔ ' . $e($level) . '</div>';
        echo '<div style="color:#fca5a5;margin:6px 0 10px">' . $e($message) . '</div>';
        echo '<div style="color:#94a3b8">↳ ' . $e($file) . ':<b style="color:#fbbf24">' . $e($line) . '</b></div>';
        if ($snippet) echo '<pre style="background:#020617;padding:12px;border-radius:8px;margin:10px 0;overflow:auto;color:#cbd5e1">' . $e($snippet) . '</pre>';
        if ($trace)  echo '<details style="margin-top:8px"><summary style="cursor:pointer;color:#94a3b8">Stack trace</summary><pre style="background:#020617;padding:12px;border-radius:8px;margin-top:8px;overflow:auto;color:#64748b">' . $e($trace) . '</pre></details>';
        echo '</div>';
    }

    /** تهيئة الالتقاط: يُستدعى مرة واحدة بعد config.php. */
    function error_logger_boot(): void
    {
        set_error_handler(function ($type, $msg, $file, $line) {
            // احترم @ (error suppression)
            if (!(error_reporting() & $type)) return false;
            $level = errlog_level_name($type);
            errlog_record($level, $msg, (string)$file, (int)$line);
            errlog_render($level, $msg, (string)$file, (int)$line);
            return true; // منعنا الطباعة الافتراضية
        });

        set_exception_handler(function ($ex) {
            /** @var Throwable $ex */
            errlog_record('EXCEPTION: ' . get_class($ex), $ex->getMessage(),
                          $ex->getFile(), $ex->getLine(), $ex->getTraceAsString());
            if (errlog_debug_on()) {
                errlog_render('EXCEPTION: ' . get_class($ex), $ex->getMessage(),
                              $ex->getFile(), $ex->getLine(), $ex->getTraceAsString());
            } else {
                http_response_code(500);
                echo '<div style="font-family:system-ui;max-width:520px;margin:60px auto;text-align:center;color:#334155">'
                   . '<h2 style="color:#0f172a">حدث خطأ غير متوقع</h2>'
                   . '<p>سُجّل الخطأ وسيُراجَع. جرّب تحديث الصفحة بعد قليل.</p></div>';
            }
        });

        register_shutdown_function(function () {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                errlog_record('FATAL', $e['message'], $e['file'], (int)$e['line']);
                errlog_render('FATAL', $e['message'], $e['file'], (int)$e['line']);
            }
        });
    }
}
