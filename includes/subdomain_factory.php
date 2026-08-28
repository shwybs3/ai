<?php
/**
 * subdomain_factory.php — mass subdomain generator for yassota.com.*
 * ==================================================================
 * Builds a network of SEO subdomains on ONE wildcard docroot:
 *   *.yassota.com  →  /home/USER/public_html   (this codebase)
 * A wildcard subdomain means you create it ONCE; after that every
 * host like best-color-tools.yassota.com is served by subsite.php,
 * which looks the host up in the `subsites` table and renders its
 * AI-generated, SEO-optimised content with the Syria-Home theme.
 *
 * Pieces:
 *   - sf_cpanel_call()        real cPanel UAPI over HTTPS (token auth)
 *   - sf_ensure_wildcard()    create the *.domain wildcard subdomain once
 *   - sf_create_subdomain()   create a concrete subdomain (rarely needed
 *                             once the wildcard exists — kept for hosts
 *                             that must be explicit)
 *   - sf_run_autossl()        trigger AutoSSL so HTTPS is issued
 *   - sf_unique_name()        generate a non-duplicate subdomain label
 *   - sf_openrouter()         call OpenRouter free models for content
 *   - sf_generate_subsite()   produce {title, description, html} for a niche
 *   - sf_provision_batch()    orchestrate N subdomains (dry-run by default)
 *
 * SAFETY — cPanel calls are LIVE and irreversible. Everything defaults
 * to DRY-RUN; a real run requires $dryRun=false AND cPanel credentials
 * in settings. Credentials are read from the settings table and are
 * NEVER printed, logged, or written under the webroot.
 *
 * Required settings (set in Admin → مصنع الدومينات):
 *   cpanel_host      e.g. https://server.host.com:2083
 *   cpanel_user      cPanel account username
 *   cpanel_token     cPanel API TOKEN (create in cPanel → Manage API Tokens)
 *   factory_root_domain   e.g. yassota.com
 *   factory_docroot  absolute docroot of the wildcard (this app)
 *   openrouter_key   (or the OPENROUTER_KEY constant) — free models work
 */

if (defined('YASSOTA_SUBFACTORY')) return;
define('YASSOTA_SUBFACTORY', 1);

/* ------------------------------------------------------------------ */
/* Schema                                                             */
/* ------------------------------------------------------------------ */
function sf_migrate(): void
{
    if (!function_exists('db')) return;
    db()->exec("CREATE TABLE IF NOT EXISTS subsites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        host VARCHAR(190) NOT NULL UNIQUE,
        label VARCHAR(120) NOT NULL,
        niche VARCHAR(190) NOT NULL,
        title VARCHAR(255) NULL,
        description VARCHAR(500) NULL,
        keywords VARCHAR(500) NULL,
        html MEDIUMTEXT NULL,
        lang VARCHAR(8) NOT NULL DEFAULT 'ar',
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        ssl TINYINT NOT NULL DEFAULT 0,
        dns_ready TINYINT NOT NULL DEFAULT 0,
        views INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    db()->exec("CREATE TABLE IF NOT EXISTS factory_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        host VARCHAR(190) NULL,
        step VARCHAR(60) NOT NULL,
        ok TINYINT NOT NULL DEFAULT 0,
        detail VARCHAR(1000) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

function sf_log(string $step, bool $ok, string $detail = '', ?string $host = null): void
{
    if (!function_exists('db')) return;
    // redact anything token-shaped before storing
    $detail = preg_replace('/\b[A-Z0-9]{16,}\b/', '‹redacted›', $detail);
    try { db()->prepare('INSERT INTO factory_log (host, step, ok, detail) VALUES (?,?,?,?)')
        ->execute([$host, $step, $ok ? 1 : 0, mb_substr($detail, 0, 1000)]); } catch (Throwable $e) {}
}

function sf_setting(string $k, string $d = ''): string
{
    return function_exists('setting') ? (string)setting($k, $d) : $d;
}

/* ------------------------------------------------------------------ */
/* cPanel UAPI — real, token-authenticated                            */
/* ------------------------------------------------------------------ */
/**
 * Call a cPanel UAPI module/function.
 * @return array{ok:bool, data:mixed, error:string}
 */
function sf_cpanel_call(string $module, string $func, array $params = []): array
{
    $host  = rtrim(sf_setting('cpanel_host'), '/');   // https://server:2083
    $user  = sf_setting('cpanel_user');
    $token = sf_setting('cpanel_token');
    if (!$host || !$user || !$token) {
        return ['ok' => false, 'data' => null, 'error' => 'cPanel credentials not configured'];
    }
    $url = "$host/execute/$module/$func" . ($params ? '?' . http_build_query($params) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $user . ':' . $token],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'data' => null, 'error' => 'cURL: ' . $err];
    $json = json_decode($body, true);
    if (!is_array($json)) return ['ok' => false, 'data' => null, 'error' => "Bad response (HTTP $code)"];
    // UAPI: {status:1|0, errors:[], data:...}
    $ok = !empty($json['status']) || (isset($json['result']['status']) && $json['result']['status']);
    $errors = $json['errors'] ?? ($json['result']['errors'] ?? null);
    return ['ok' => (bool)$ok, 'data' => $json['data'] ?? ($json['result']['data'] ?? null),
            'error' => $errors ? implode('; ', (array)$errors) : ''];
}

/**
 * Create the wildcard subdomain once: *.rootdomain → docroot.
 * cPanel accepts '*' as the subdomain label to make a wildcard.
 */
function sf_ensure_wildcard(bool $dryRun = true): array
{
    $root    = sf_setting('factory_root_domain');
    $docroot = sf_setting('factory_docroot');
    if (!$root) return ['ok' => false, 'error' => 'factory_root_domain not set'];
    if ($dryRun) { sf_log('wildcard', true, "DRY-RUN would create *.$root → $docroot"); return ['ok' => true, 'dry' => true, 'error' => '']; }
    $res = sf_cpanel_call('SubDomain', 'addsubdomain', [
        'domain' => '*', 'rootdomain' => $root,
        'dir' => $docroot ?: ('public_html'),
    ]);
    sf_log('wildcard', $res['ok'], $res['ok'] ? "created *.$root" : $res['error']);
    return $res;
}

/** Create a concrete subdomain (usually unnecessary with a wildcard). */
function sf_create_subdomain(string $label, bool $dryRun = true): array
{
    $root    = sf_setting('factory_root_domain');
    $docroot = sf_setting('factory_docroot') ?: 'public_html';
    if (!$root) return ['ok' => false, 'error' => 'factory_root_domain not set'];
    if ($dryRun) { sf_log('subdomain', true, "DRY-RUN would create $label.$root", "$label.$root"); return ['ok' => true, 'dry' => true]; }
    $res = sf_cpanel_call('SubDomain', 'addsubdomain', ['domain' => $label, 'rootdomain' => $root, 'dir' => $docroot]);
    sf_log('subdomain', $res['ok'], $res['ok'] ? 'created' : $res['error'], "$label.$root");
    return $res;
}

/** Trigger AutoSSL so the wildcard/host gets a real HTTPS certificate. */
function sf_run_autossl(bool $dryRun = true): array
{
    if ($dryRun) { sf_log('autossl', true, 'DRY-RUN would start AutoSSL'); return ['ok' => true, 'dry' => true]; }
    // UAPI SSL::start_autossl_check_for_one_user runs AutoSSL for this account.
    $res = sf_cpanel_call('SSL', 'start_autossl_check_for_one_user', []);
    if (!$res['ok']) { // fallback name used on some builds
        $res = sf_cpanel_call('SSL', 'start_autossl', []);
    }
    sf_log('autossl', $res['ok'], $res['ok'] ? 'AutoSSL started' : $res['error']);
    return $res;
}

/** List installed SSL certificates (for the domains control panel). */
function sf_list_ssl(): array
{
    return sf_cpanel_call('SSL', 'installed_hosts', []);
}

/** List all subdomains from cPanel (for the domains control panel). */
function sf_list_subdomains(): array
{
    return sf_cpanel_call('SubDomain', 'listsubdomains', []);
}

/* ------------------------------------------------------------------ */
/* Unique subdomain naming                                            */
/* ------------------------------------------------------------------ */
function sf_word_banks(): array
{
    return [
        'adj'   => ['best', 'top', 'free', 'smart', 'quick', 'easy', 'pro', 'daily', 'super', 'ultimate', 'instant', 'simple', 'modern', 'expert', 'handy', 'prime', 'swift', 'clever', 'bright', 'mega'],
        'noun'  => ['tools', 'guide', 'hub', 'zone', 'lab', 'kit', 'center', 'point', 'world', 'club', 'space', 'box', 'spot', 'base', 'desk', 'wiki', 'academy', 'corner', 'studio', 'gallery'],
        'niche' => ['color', 'text', 'seo', 'code', 'math', 'design', 'image', 'crypto', 'money', 'health', 'travel', 'recipe', 'fitness', 'study', 'career', 'startup', 'marketing', 'writing', 'photo', 'music', 'gaming', 'pets', 'garden', 'fashion', 'tech', 'finance', 'business', 'education', 'science', 'sports'],
    ];
}

/** Deterministic-ish unique label; checks the DB to avoid duplicates. */
function sf_unique_name(?string $seedNiche = null): string
{
    $b = sf_word_banks();
    for ($try = 0; $try < 40; $try++) {
        $niche = $seedNiche ?: $b['niche'][array_rand($b['niche'])];
        $parts = [];
        $mode = $try % 3;
        if ($mode === 0)      $parts = [$b['adj'][array_rand($b['adj'])], $niche, $b['noun'][array_rand($b['noun'])]];
        elseif ($mode === 1)  $parts = [$niche, $b['noun'][array_rand($b['noun'])]];
        else                  $parts = [$b['adj'][array_rand($b['adj'])], $niche];
        $label = implode('-', $parts);
        if ($try > 20) $label .= '-' . substr(md5(uniqid('', true)), 0, 4); // guarantee uniqueness late
        $label = strtolower(preg_replace('/[^a-z0-9\-]/i', '', $label));
        if (!sf_host_exists($label)) return $label;
    }
    return 'site-' . substr(md5(uniqid('', true)), 0, 8);
}

function sf_host_exists(string $label): bool
{
    if (!function_exists('db')) return false;
    $root = sf_setting('factory_root_domain');
    $host = $label . '.' . $root;
    try { return (bool)db()->prepare('SELECT 1 FROM subsites WHERE host=?')->execute([$host]) && db()->query('SELECT COUNT(*) c FROM subsites WHERE host=' . db()->quote($host))->fetch()['c'] > 0; }
    catch (Throwable $e) { return false; }
}

/* ------------------------------------------------------------------ */
/* OpenRouter (free models) content generation                        */
/* ------------------------------------------------------------------ */
function sf_openrouter_key(): string
{
    $k = sf_setting('openrouter_key');
    if (!$k && defined('OPENROUTER_KEY')) $k = OPENROUTER_KEY;
    return (string)$k;
}

/**
 * Call OpenRouter chat completions. Defaults to a free model.
 * @return array{ok:bool, text:string, error:string}
 */
function sf_openrouter(array $messages, string $model = ''): array
{
    $key = sf_openrouter_key();
    if (!$key) return ['ok' => false, 'text' => '', 'error' => 'OpenRouter key not set'];
    $model = $model ?: (sf_setting('openrouter_model') ?: 'meta-llama/llama-3.1-8b-instruct:free');
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
            'HTTP-Referer: ' . (defined('SITE_URL') ? SITE_URL : 'https://yassota.com'),
            'X-Title: Yassota Subdomain Factory',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.8,
            'max_tokens' => 1500,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'text' => '', 'error' => 'cURL: ' . $err];
    $json = json_decode($body, true);
    $text = $json['choices'][0]['message']['content'] ?? '';
    if ($text === '') return ['ok' => false, 'text' => '', 'error' => $json['error']['message'] ?? 'empty response'];
    return ['ok' => true, 'text' => $text, 'error' => ''];
}

/**
 * Generate a full subsite for a niche. Returns title/description/keywords/html.
 * Falls back to a solid template if AI is unavailable, so a run never fails.
 */
function sf_generate_subsite(string $niche, string $label, string $lang = 'ar'): array
{
    $langName = $lang === 'ar' ? 'Arabic' : 'English';
    $sys = "You are an SEO content writer. Return STRICT JSON only, no markdown fences. "
         . "Keys: title (<=60 chars), description (<=155 chars), keywords (comma list, 6 items), "
         . "html (600-900 words of real, useful, well-structured content in $langName using <h2>, <h3>, <p>, <ul><li>). "
         . "Topic: a helpful resource site about \"$niche\". Do not invent fake statistics or fake brands.";
    $res = sf_openrouter([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => "Create the JSON for a $langName resource page about: $niche"],
    ]);

    if ($res['ok']) {
        $raw = trim($res['text']);
        $raw = preg_replace('/^```(json)?|```$/m', '', $raw);
        $data = json_decode($raw, true);
        if (is_array($data) && !empty($data['html'])) {
            return [
                'title'       => mb_substr($data['title'] ?? ucfirst($niche), 0, 120),
                'description' => mb_substr($data['description'] ?? '', 0, 300),
                'keywords'    => is_array($data['keywords'] ?? null) ? implode(', ', $data['keywords']) : ($data['keywords'] ?? $niche),
                'html'        => $data['html'],
                'source'      => 'ai',
            ];
        }
    }
    // fallback template (still real, indexable content)
    return sf_fallback_subsite($niche, $label, $lang);
}

function sf_fallback_subsite(string $niche, string $label, string $lang): array
{
    $n = htmlspecialchars($niche);
    if ($lang === 'ar') {
        $html = "<h2>دليلك الشامل حول {$n}</h2>"
              . "<p>مرحبًا بك في مورد متخصص يجمع أفضل النصائح والأدوات والمعلومات حول <strong>{$n}</strong>. "
              . "هدفنا تقديم محتوى عملي وواضح يساعدك على البدء بسرعة واتخاذ قرارات أفضل.</p>"
              . "<h3>لماذا يهمك هذا الموضوع؟</h3>"
              . "<p>يبحث الكثيرون يوميًا عن معلومات موثوقة حول {$n}، لكنهم يجدون محتوى مبعثرًا. هنا جمعنا الأساسيات في مكان واحد.</p>"
              . "<ul><li>مفاهيم أساسية مبسّطة</li><li>نصائح قابلة للتطبيق فورًا</li><li>أخطاء شائعة يجب تجنّبها</li><li>مصادر وأدوات مفيدة</li></ul>"
              . "<h3>كيف تبدأ؟</h3>"
              . "<p>ابدأ بالخطوات الصغيرة، واعتمد على مصادر موثوقة، وطبّق ما تتعلمه مباشرة. مع الوقت ستبني خبرة حقيقية في {$n}.</p>"
              . "<h3>أدوات مجانية تساعدك</h3>"
              . "<p>يمكنك استخدام مجموعة أدوات Yassota المجانية لتسريع عملك وتنظيم أفكارك.</p>";
        $title = "$niche — دليل ونصائح ومصادر مجانية";
        $desc  = "كل ما تحتاج معرفته عن $niche: مفاهيم، نصائح عملية، أخطاء شائعة، وأدوات مجانية في مكان واحد.";
    } else {
        $html = "<h2>Your complete guide to {$n}</h2>"
              . "<p>Welcome to a focused resource covering the best tips, tools and information about <strong>{$n}</strong>.</p>"
              . "<h3>Why it matters</h3><p>People search for reliable info about {$n} every day. We gathered the essentials in one place.</p>"
              . "<ul><li>Simple core concepts</li><li>Actionable tips</li><li>Common mistakes</li><li>Useful free tools</li></ul>"
              . "<h3>How to start</h3><p>Start small, rely on trusted sources, and apply what you learn about {$n} right away.</p>";
        $title = "$niche — Free guide, tips & resources";
        $desc  = "Everything you need to know about $niche: concepts, practical tips, common mistakes and free tools.";
    }
    return ['title' => $title, 'description' => $desc, 'keywords' => "$niche, guide, tips, free tools, $label", 'html' => $html, 'source' => 'template'];
}

/* ------------------------------------------------------------------ */
/* Orchestration                                                      */
/* ------------------------------------------------------------------ */
/**
 * Provision a batch of subdomains. Content is always generated + stored;
 * cPanel/DNS/SSL steps run only when $dryRun is false AND credentials exist.
 * Returns a per-host summary you can render in the admin.
 *
 * @param int    $count   how many to create this run (keep batches small)
 * @param bool   $dryRun  true = generate + store content, skip cPanel calls
 * @param string $lang    'ar' | 'en'
 * @param string $niche   optional fixed niche; blank = random per site
 */
function sf_provision_batch(int $count, bool $dryRun = true, string $lang = 'ar', string $niche = ''): array
{
    sf_migrate();
    $root = sf_setting('factory_root_domain');
    if (!$root) return ['ok' => false, 'error' => 'factory_root_domain not set', 'results' => []];

    // Ensure the wildcard exists first (once). Cheap when it already does.
    if (!$dryRun) sf_ensure_wildcard(false);

    $count = max(1, min(200, $count)); // cap a single run; loop from the UI for thousands
    $results = [];
    for ($i = 0; $i < $count; $i++) {
        $label = sf_unique_name($niche ?: null);
        $host  = $label . '.' . $root;
        $chosenNiche = $niche ?: str_replace('-', ' ', $label);
        $content = sf_generate_subsite($chosenNiche, $label, $lang);

        $dnsReady = false; $sslReady = false; $err = '';
        if (!$dryRun) {
            // With a wildcard, no per-host DNS call is needed; mark ready.
            $dnsReady = true;
            // AutoSSL runs at the account level; trigger occasionally, not per host.
            if ($i === 0) { $ssl = sf_run_autossl(false); $sslReady = $ssl['ok']; }
        }

        if (function_exists('db')) {
            try {
                db()->prepare('INSERT INTO subsites (host,label,niche,title,description,keywords,html,lang,status,ssl,dns_ready)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$host, $label, $chosenNiche, $content['title'], $content['description'],
                               $content['keywords'], $content['html'], $lang,
                               $dryRun ? 'draft' : 'live', $sslReady ? 1 : 0, $dnsReady ? 1 : 0]);
            } catch (Throwable $e) { $err = $e->getMessage(); }
        }
        sf_log('provision', $err === '', $err ?: ('via ' . $content['source']), $host);
        $results[] = ['host' => $host, 'niche' => $chosenNiche, 'title' => $content['title'],
                      'source' => $content['source'], 'dry' => $dryRun, 'error' => $err];
        if (!$dryRun) usleep(300000); // be gentle on OpenRouter free tier
    }
    return ['ok' => true, 'error' => '', 'results' => $results];
}

/** Total subsites + quick stats for the admin dashboard. */
function sf_stats(): array
{
    if (!function_exists('db')) return ['total' => 0, 'live' => 0, 'ssl' => 0];
    try {
        $row = db()->query("SELECT COUNT(*) total,
            SUM(CASE WHEN status='live' THEN 1 ELSE 0 END) live,
            SUM(CASE WHEN ssl=1 THEN 1 ELSE 0 END) ssl FROM subsites")->fetch();
        return ['total' => (int)$row['total'], 'live' => (int)$row['live'], 'ssl' => (int)$row['ssl']];
    } catch (Throwable $e) { return ['total' => 0, 'live' => 0, 'ssl' => 0]; }
}
