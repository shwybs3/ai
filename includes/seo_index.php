<?php
/**
 * IndexNow + Google Search Console — real, logged submissions on every
 * publish/update. Ported from the same system already live on
 * syria-home.yassota.com (a sibling site), adapted to this app: there are
 * no per-article/product URLs here (products are listed inline on one
 * page), so "publish" means the small set of public, non-login-gated URLs
 * changed — currently just the homepage, privacy and terms pages.
 *
 * IndexNow does NOT reach Google — only Bing/Yandex/Seznam/Naver speak that
 * protocol. Google's own real, currently-supported nudge is
 * sitemaps.submit (the old /ping?sitemap= endpoint was retired in 2023).
 */

const SH_INDEXNOW_ENDPOINTS = [
    'indexnow' => 'https://api.indexnow.org/indexnow',
    'bing'     => 'https://www.bing.com/indexnow',
    'yandex'   => 'https://yandex.com/indexnow',
];

const GSC_ADMIN_SCOPES = [
    'https://www.googleapis.com/auth/webmasters',
    'https://www.googleapis.com/auth/indexing',
];

function seo_docroot(): string { return dirname(__DIR__); }

function indexnow_key(): string {
    return trim((string)setting('indexnow_key', 'd12a9522b79d420992b2f46d4ab34062'));
}

/** Publishes the key at BOTH /{key}.txt and the bare, extension-less /{key} —
 *  some validators check the bare path instead of the .txt one. */
function indexnow_keyfile(): string {
    $key = indexnow_key();
    if ($key === '') return '';
    $dir = seo_docroot();
    foreach ([$key . '.txt', $key] as $name) {
        $path = $dir . '/' . $name;
        if (!file_exists($path) || trim((string)@file_get_contents($path)) !== $key) {
            @file_put_contents($path, $key);
        }
    }
    return rtrim(SITE_URL, '/') . '/' . $key . '.txt';
}

function index_log(string $url, string $engine, string $status, int $httpCode = 0, string $message = ''): void {
    try {
        db()->prepare("INSERT INTO index_log (url, engine, status, http_code, message) VALUES (?,?,?,?,?)")
            ->execute([mb_substr($url, 0, 600), $engine, $status, $httpCode, mb_substr($message, 0, 400)]);
    } catch (Throwable $e) { /* logging is best-effort */ }
}

function indexnow_ping_get(string $url): array {
    $key = indexnow_key();
    if ($key === '') return [];
    $keyLocation = indexnow_keyfile();

    $results = [];
    foreach (SH_INDEXNOW_ENDPOINTS as $name => $endpoint) {
        $qs = http_build_query(['url' => $url, 'key' => $key, 'keyLocation' => $keyLocation]);
        $ch = curl_init($endpoint . '?' . $qs);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = ($code >= 200 && $code < 300);
        $results[$name] = ['code' => $code, 'ok' => $ok];
        index_log($url, 'indexnow:' . $name, $ok ? 'ok' : 'failed', $code, '');
    }
    return $results;
}

function indexnow_submit(array $urls): array {
    $urls = array_values(array_filter(array_unique($urls)));
    if (!$urls) return [];
    $key = indexnow_key();
    if ($key === '') return [];
    $keyLocation = indexnow_keyfile();

    $payload = json_encode([
        'host' => parse_url(SITE_URL, PHP_URL_HOST),
        'key' => $key, 'keyLocation' => $keyLocation, 'urlList' => array_slice($urls, 0, 10000),
    ], JSON_UNESCAPED_SLASHES);

    $results = [];
    foreach (SH_INDEXNOW_ENDPOINTS as $name => $endpoint) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ok = ($code >= 200 && $code < 300);
        $results[$name] = ['code' => $code, 'ok' => $ok];
        $label = count($urls) === 1 ? $urls[0] : count($urls) . ' URLs (batch)';
        index_log($label, 'indexnow:' . $name, $ok ? 'ok' : 'failed', $code, $ok ? '' : ($err ?: mb_substr((string)$body, 0, 200)));
    }
    return $results;
}

/** The only real, public, non-login-gated URLs this app has today. */
function seo_public_urls(): array {
    $base = rtrim(SITE_URL, '/');
    return [$base . '/', $base . '/?page=privacy', $base . '/?page=terms'];
}

function seo_sitemap_url(): string { return rtrim(SITE_URL, '/') . '/sitemap.xml'; }

/** Writes a real, physical sitemap.xml at the docroot (this app has no URL
 *  rewriting, so a plain static file is what actually serves at /sitemap.xml). */
function seo_write_sitemap_file(): void {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (seo_public_urls() as $u) {
        $xml .= '<url><loc>' . htmlspecialchars($u) . '</loc><lastmod>' . date('c') . '</lastmod></url>' . "\n";
    }
    $xml .= '</urlset>';
    @file_put_contents(seo_docroot() . '/sitemap.xml', $xml);
}

/* ---- Google admin connection (Search Console + Indexing API) ----
 * Separate from the site's user-login Google OAuth (different scopes,
 * different redirect URI) — reuses the same GOOGLE_CLIENT_ID/SECRET. */

function gadmin_redirect_uri(): string { return rtrim(SITE_URL, '/') . '/index.php?action=admin_google_callback'; }

function gadmin_authorize_url(): string {
    if (!GOOGLE_CLIENT_ID) return '#';
    $params = [
        'client_id' => GOOGLE_CLIENT_ID, 'redirect_uri' => gadmin_redirect_uri(),
        'response_type' => 'code', 'access_type' => 'offline', 'prompt' => 'consent',
        'scope' => implode(' ', GSC_ADMIN_SCOPES), 'state' => csrf_token(),
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function gadmin_post_form(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $json = json_decode((string)$body, true);
    return is_array($json) ? $json : ['error' => 'invalid_response', 'raw' => $body];
}

function gadmin_store_tokens(array $resp): void {
    $expiresAt = date('Y-m-d H:i:s', time() + (int)($resp['expires_in'] ?? 3600));
    $sql = DB_DRIVER === 'sqlite'
        ? "INSERT INTO google_admin_tokens (service, access_token, refresh_token, expires_at, scope) VALUES ('search_console',?,?,?,?)
           ON CONFLICT(service) DO UPDATE SET access_token=excluded.access_token,
           refresh_token=COALESCE(NULLIF(excluded.refresh_token,''), google_admin_tokens.refresh_token),
           expires_at=excluded.expires_at, scope=excluded.scope"
        : "INSERT INTO google_admin_tokens (service, access_token, refresh_token, expires_at, scope) VALUES ('search_console',?,?,?,?)
           ON DUPLICATE KEY UPDATE access_token=VALUES(access_token),
           refresh_token=COALESCE(NULLIF(VALUES(refresh_token),''), refresh_token),
           expires_at=VALUES(expires_at), scope=VALUES(scope)";
    db()->prepare($sql)->execute([$resp['access_token'], $resp['refresh_token'] ?? '', $expiresAt, $resp['scope'] ?? implode(' ', GSC_ADMIN_SCOPES)]);
}

function gadmin_exchange_code(string $code): array {
    $resp = gadmin_post_form('https://oauth2.googleapis.com/token', [
        'code' => $code, 'client_id' => GOOGLE_CLIENT_ID, 'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => gadmin_redirect_uri(), 'grant_type' => 'authorization_code',
    ]);
    if (!empty($resp['access_token'])) gadmin_store_tokens($resp);
    return $resp;
}

function gadmin_is_connected(): bool {
    $row = db()->query("SELECT * FROM google_admin_tokens WHERE service='search_console'")->fetch();
    return $row && !empty($row['refresh_token']);
}

function gadmin_disconnect(): void { db()->exec("DELETE FROM google_admin_tokens WHERE service='search_console'"); }

function gadmin_access_token(): ?string {
    $row = db()->query("SELECT * FROM google_admin_tokens WHERE service='search_console'")->fetch();
    if (!$row) return null;
    if (strtotime($row['expires_at']) - time() > 60) return $row['access_token'];
    if (empty($row['refresh_token'])) return null;
    $resp = gadmin_post_form('https://oauth2.googleapis.com/token', [
        'client_id' => GOOGLE_CLIENT_ID, 'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $row['refresh_token'], 'grant_type' => 'refresh_token',
    ]);
    if (empty($resp['access_token'])) return null;
    $resp['refresh_token'] = $row['refresh_token'];
    gadmin_store_tokens($resp);
    return $resp['access_token'];
}

function gadmin_request(string $method, string $url, ?array $payload = null): array {
    $token = gadmin_access_token();
    if (!$token) return ['error' => 'not_connected', 'code' => 0];
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    } elseif ($method === 'PUT') {
        $opts[CURLOPT_POSTFIELDS] = '';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$body, true);
    return ['code' => $code, 'ok' => $code >= 200 && $code < 300, 'data' => is_array($json) ? $json : null, 'raw' => $body];
}

/** Real, currently-supported Google endpoint — PUT to sitemaps.submit.
 *  The old /ping?sitemap= endpoint was retired in 2023 and is not used. */
function gsc_sitemap_submit(): array {
    $siteUrl = trim((string)setting('gsc_site_url', ''));
    if ($siteUrl === '') return ['ok' => false, 'code' => 0, 'error' => 'Set the Search Console site URL in Settings first.'];
    $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/sitemaps/' . rawurlencode(seo_sitemap_url());
    $res = gadmin_request('PUT', $url);
    index_log(seo_sitemap_url(), 'google:sitemaps_submit', $res['ok'] ? 'ok' : 'failed', $res['code'], $res['ok'] ? '' : mb_substr((string)($res['raw'] ?? ''), 0, 200));
    return $res;
}

function gsc_inspect_url(string $url): array {
    $siteUrl = trim((string)setting('gsc_site_url', ''));
    if ($siteUrl === '') return ['error' => 'Set the Search Console site URL in Settings first.'];
    return gadmin_request('POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
        'inspectionUrl' => $url, 'siteUrl' => $siteUrl,
    ])['data'] ?? ['error' => 'invalid_response'];
}

/** Officially scoped by Google to JobPosting/BroadcastEvent pages — a 200
 *  here means "accepted", not "will be indexed". Off by default. */
function google_index_submit(string $url): array {
    $res = gadmin_request('POST', 'https://indexing.googleapis.com/v3/urlNotifications:publish', [
        'url' => $url, 'type' => 'URL_UPDATED',
    ]);
    index_log($url, 'google:indexing', $res['ok'] ? 'ok' : 'failed', $res['code'], $res['ok'] ? '' : mb_substr((string)($res['raw'] ?? ''), 0, 200));
    return $res;
}

/** Call this after any admin save that changes public content (product,
 *  page, banner). Fires IndexNow for the real URL, refreshes+resubmits the
 *  sitemap to Search Console, and — only if explicitly enabled — the
 *  Google Indexing API too. Never throws; safe on every save. */
function indexnow_ping(string $url): void {
    if ((int)setting('auto_index_on_publish', 1) !== 1) return;
    try { indexnow_ping_get($url); } catch (Throwable $e) {}
    try {
        seo_write_sitemap_file();
        if (gadmin_is_connected()) gsc_sitemap_submit();
    } catch (Throwable $e) {}
    if ((int)setting('google_indexing_enabled', 0) === 1 && gadmin_is_connected()) {
        try { google_index_submit($url); } catch (Throwable $e) {}
    }
}
