<?php
/**
 * partials.php — shared public header/footer for yassota.com
 * ==========================================================
 * Visual identity mirrors syria-home.yassota.com (light theme,
 * indigo→cyan brand, Inter/Cairo). Every new public page should
 * call yassota_header() / yassota_footer() so the look stays
 * consistent in one place.
 *
 * Depends on helpers already defined in index.php: e(), setting().
 * Falls back gracefully if they are missing (e.g. standalone pages).
 */

if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('setting')) {
    function setting($k, $d = '') { return $d; }
}

/** Absolute base URL for the site (no trailing slash). */
function yassota_base_url(): string
{
    if (defined('SITE_URL') && SITE_URL) return rtrim(SITE_URL, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/** The public navigation items. active: matches a page key. */
function yassota_nav(): array
{
    return [
        ['key' => 'home',     'label' => 'الرئيسية',  'href' => '/'],
        ['key' => 'tools',    'label' => 'أدوات',     'href' => '/tools.php'],
        ['key' => 'articles', 'label' => 'مقالات',    'href' => '/articles.php'],
        ['key' => 'store',    'label' => 'المتجر',    'href' => '/?page=store'],
        ['key' => 'about',    'label' => 'من نحن',    'href' => '/?page=about'],
        ['key' => 'contact',  'label' => 'تواصل',     'href' => '/?page=contact'],
    ];
}

/**
 * Render the full <head> + header.
 * @param array $opts  title, description, canonical, active, dir ('rtl'|'ltr'), image, jsonld
 */
function yassota_header(array $opts = []): void
{
    $site   = setting('site_name') ?: 'Yassota';
    $dir    = $opts['dir']   ?? 'rtl';
    $lang   = $dir === 'rtl' ? 'ar' : 'en';
    $title  = $opts['title'] ?? $site;
    $desc   = $opts['description'] ?? setting('site_description');
    $canon  = $opts['canonical'] ?? (yassota_base_url() . ($_SERVER['REQUEST_URI'] ?? '/'));
    $active = $opts['active'] ?? '';
    $img    = $opts['image'] ?? setting('logo_url');
    $base   = yassota_base_url();
    ?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" dir="<?= e($dir) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<?php if ($desc): ?><meta name="description" content="<?= e($desc) ?>"><?php endif; ?>
<link rel="canonical" href="<?= e($canon) ?>">
<meta name="theme-color" content="#6366f1">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($canon) ?>">
<?php if ($img): ?><meta property="og:image" content="<?= e($img) ?>"><meta name="twitter:card" content="summary_large_image"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
<?php if (!empty($opts['jsonld'])): ?>
<script type="application/ld+json"><?= $opts['jsonld'] ?></script>
<?php endif; ?>
<?php
    // AdSense head snippet (only when a publisher id is configured)
    $ads = setting('adsense_publisher_id');
    if ($ads):
    ?>
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=<?= e($ads) ?>" crossorigin="anonymous"></script>
<?php endif; ?>
</head>
<body>
<div class="nav-backdrop" id="navBackdrop"></div>
<header class="site-header">
  <div class="bar">
    <a class="logo" href="/">
      <span class="mark">Y</span><span><?= e($site) ?></span>
    </a>
    <nav class="main-nav" id="mainNav">
      <?php foreach (yassota_nav() as $n): ?>
        <a href="<?= e($n['href']) ?>"<?= $active === $n['key'] ? ' class="active"' : '' ?>><?= e($n['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <form class="header-search" action="/tools.php" method="get" role="search">
      <span aria-hidden="true">🔍</span>
      <input type="text" name="q" placeholder="ابحث عن أداة…" aria-label="بحث">
    </form>
    <a class="header-cta" href="/tools.php"><span>٢٠٠+ أداة مجانية</span> 🚀</a>
    <button class="hamburger" id="navToggle" aria-label="القائمة">☰</button>
  </div>
</header>
<main>
<?php
}

/** Render the footer + close body. */
function yassota_footer(): void
{
    $site = setting('site_name') ?: 'Yassota';
    $year = date('Y');
    // Optional cross-network band (other Yassota sites)
    ?>
</main>

<section class="newsletter-strip">
  <div class="container">
    <h2>📬 اشترك في نشرتنا</h2>
    <p>وصلك أحدث الأدوات والمقالات مباشرة — بدون إزعاج، وتلغي الاشتراك متى شئت.</p>
    <form class="newsletter-form" id="newsletterForm">
      <input type="email" name="email" placeholder="بريدك الإلكتروني" required>
      <button type="submit">اشترك</button>
    </form>
  </div>
</section>

<footer class="site-footer">
  <div class="container">
    <div class="cols">
      <div>
        <div class="brand"><span class="mark">Y</span> <?= e($site) ?></div>
        <p style="color:#94a3b8;font-size:13px;max-width:320px">منصة عربية تجمع أكثر من ٢٠٠ أداة ويب مجانية، مقالات تقنية، ومتجر منتجات رقمية — كل ذلك في مكان واحد.</p>
        <div class="social-row">
          <a href="#" aria-label="Facebook">f</a>
          <a href="#" aria-label="X">𝕏</a>
          <a href="#" aria-label="Instagram">◎</a>
          <a href="#" aria-label="YouTube">▶</a>
        </div>
      </div>
      <div>
        <h4>الأدوات</h4>
        <a href="/tools.php?cat=text">أدوات النصوص</a>
        <a href="/tools.php?cat=dev">أدوات المطورين</a>
        <a href="/tools.php?cat=seo">أدوات السيو</a>
        <a href="/tools.php?cat=image">أدوات الصور</a>
        <a href="/tools.php">كل الأدوات</a>
      </div>
      <div>
        <h4>الموقع</h4>
        <a href="/articles.php">المقالات</a>
        <a href="/?page=store">المتجر</a>
        <a href="/?page=about">من نحن</a>
        <a href="/?page=contact">تواصل معنا</a>
      </div>
      <div>
        <h4>قانوني</h4>
        <a href="/?page=privacy">سياسة الخصوصية</a>
        <a href="/?page=terms">شروط الاستخدام</a>
        <a href="/?page=cookies">سياسة الكوكيز</a>
      </div>
    </div>
    <div class="bottom">
      <span>© <?= $year ?> <?= e($site) ?>. جميع الحقوق محفوظة.</span>
      <span>صُنع بحب للمستخدم العربي</span>
    </div>
  </div>
</footer>

<div class="cookie-banner" id="cookieBanner">
  <p>نستخدم ملفات تعريف الارتباط لتحسين تجربتك. بالمتابعة أنت توافق على <a href="/?page=cookies">سياسة الكوكيز</a>.</p>
  <div class="cookie-actions"><button id="cookieAccept">موافق</button></div>
</div>

<script src="/assets/js/main.js"></script>
</body>
</html>
<?php
}
