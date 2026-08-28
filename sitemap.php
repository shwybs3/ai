<?php
/**
 * sitemap.php — unified sitemap for the whole Yassota network
 * ===========================================================
 * ONE sitemap endpoint covers:
 *   - the main site (home, tools hub, every tool page, articles, store)
 *   - every generated subdomain in the `subsites` table
 *
 * Modes:
 *   /sitemap.php            → sitemap index (points to the sections below)
 *   /sitemap.php?type=main  → main site + all tool URLs
 *   /sitemap.php?type=subs  → all live subdomains (one <url> each)
 *
 * Served as valid XML so Google/Bing can fetch it, and it's the file you
 * submit to Search Console / ping via IndexNow.
 */

$rootConfig = __DIR__ . '/config.php';
if (is_file($rootConfig)) require_once $rootConfig;
if (is_file(__DIR__ . '/includes/bootstrap.php')) require_once __DIR__ . '/includes/bootstrap.php';
if (is_file(__DIR__ . '/tools_catalog.php')) require_once __DIR__ . '/tools_catalog.php';

header('Content-Type: application/xml; charset=utf-8');

function sm_base(): string
{
    if (defined('SITE_URL') && SITE_URL) return rtrim(SITE_URL, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'yassota.com');
}
function sm_x($s) { return htmlspecialchars((string)$s, ENT_XML1, 'UTF-8'); }

$base = sm_base();
$type = $_GET['type'] ?? '';
$today = date('Y-m-d');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

/* ---------- INDEX ---------- */
if ($type === '') {
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo "  <sitemap><loc>{$base}/sitemap.php?type=main</loc><lastmod>{$today}</lastmod></sitemap>\n";
    echo "  <sitemap><loc>{$base}/sitemap.php?type=subs</loc><lastmod>{$today}</lastmod></sitemap>\n";
    echo '</sitemapindex>';
    exit;
}

echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

/* ---------- MAIN + TOOLS ---------- */
if ($type === 'main') {
    $urls = [
        ['/', '1.0', 'daily'],
        ['/tools.php', '0.9', 'daily'],
        ['/articles.php', '0.8', 'daily'],
        ['/?page=store', '0.8', 'weekly'],
        ['/?page=about', '0.4', 'monthly'],
        ['/?page=contact', '0.4', 'monthly'],
    ];
    foreach ($urls as [$u, $p, $f]) {
        echo "  <url><loc>" . sm_x($base . $u) . "</loc><changefreq>{$f}</changefreq><priority>{$p}</priority></url>\n";
    }
    // every tool page (real, crawlable URLs)
    if (function_exists('yassota_tools_meta')) {
        foreach (yassota_tools_meta() as $t) {
            echo "  <url><loc>" . sm_x($base . '/tools.php?t=' . $t['id']) . "</loc><changefreq>weekly</changefreq><priority>0.7</priority></url>\n";
        }
    }
    // every published article
    if (function_exists('db')) {
        try {
            $arts = db()->query("SELECT slug, updated_at FROM articles WHERE status='published' ORDER BY id DESC LIMIT 20000");
            foreach ($arts as $a) {
                $mod = date('Y-m-d', strtotime($a['updated_at'] ?: 'now'));
                echo "  <url><loc>" . sm_x($base . '/articles.php?slug=' . $a['slug']) . "</loc><lastmod>{$mod}</lastmod><changefreq>monthly</changefreq><priority>0.7</priority></url>\n";
            }
        } catch (Throwable $e) { /* table may not exist yet */ }
    }
    // category pages
    foreach (['text','math','color','dev','seo','security','convert','image','product','social'] as $c) {
        echo "  <url><loc>" . sm_x($base . '/tools.php?cat=' . $c) . "</loc><changefreq>weekly</changefreq><priority>0.6</priority></url>\n";
    }
}

/* ---------- SUBDOMAINS ---------- */
if ($type === 'subs' && function_exists('db')) {
    try {
        $rows = db()->query("SELECT host, created_at FROM subsites WHERE status='live' ORDER BY id DESC LIMIT 50000");
        foreach ($rows as $r) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $loc = $scheme . '://' . $r['host'] . '/';
            $mod = date('Y-m-d', strtotime($r['created_at'] ?: 'now'));
            echo "  <url><loc>" . sm_x($loc) . "</loc><lastmod>{$mod}</lastmod><changefreq>weekly</changefreq><priority>0.6</priority></url>\n";
        }
    } catch (Throwable $e) { /* table may not exist yet */ }
}

echo '</urlset>';
