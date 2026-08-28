<?php
/**
 * tools.php — public tools hub + individual tool runner for yassota.com
 * ====================================================================
 * - /tools.php               → directory of all 200+ tools (searchable, filterable)
 * - /tools.php?cat=text      → filtered to one category
 * - /tools.php?t=word-counter→ a single tool page (own <h1>, description, SEO)
 *
 * The tool catalogue lives in assets/js/tools.js (the single source of
 * truth). PHP mirrors just enough metadata (id, name, desc, cat, icon)
 * for server-rendered SEO cards + per-tool pages, so each tool has a
 * crawlable URL with real descriptive text — fixing "tools don't appear
 * in search results".
 */

$rootConfig = __DIR__ . '/config.php';
if (is_file($rootConfig)) require_once $rootConfig;
require_once __DIR__ . '/partials.php';
if (is_file(__DIR__ . '/tools_catalog.php')) require __DIR__ . '/tools_catalog.php';

$CATS = [
    'text'     => ['name' => 'أدوات النصوص',       'en' => 'Text',            'icon' => '📝', 'cls' => 'cat-text'],
    'math'     => ['name' => 'الرياضيات والأرقام',  'en' => 'Math & Numbers',  'icon' => '🔢', 'cls' => 'cat-math'],
    'color'    => ['name' => 'الألوان والتصميم',    'en' => 'Color & Design',  'icon' => '🎨', 'cls' => 'cat-color'],
    'dev'      => ['name' => 'أدوات المطورين',      'en' => 'Developer',       'icon' => '⌨️', 'cls' => 'cat-dev'],
    'seo'      => ['name' => 'السيو والويب',        'en' => 'SEO & Web',       'icon' => '🔍', 'cls' => 'cat-seo'],
    'security' => ['name' => 'الأمان والخصوصية',    'en' => 'Security',        'icon' => '🔐', 'cls' => 'cat-security'],
    'convert'  => ['name' => 'المحوّلات',           'en' => 'Converters',      'icon' => '🔄', 'cls' => 'cat-convert'],
    'image'    => ['name' => 'الصور والوسائط',      'en' => 'Image & Media',   'icon' => '🖼️', 'cls' => 'cat-image'],
    'product'  => ['name' => 'الإنتاجية',           'en' => 'Productivity',    'icon' => '⚡', 'cls' => 'cat-product'],
    'social'   => ['name' => 'التواصل والتسويق',    'en' => 'Social & Marketing','icon' => '📣', 'cls' => 'cat-social'],
];

/**
 * TOOLS metadata (mirrors tools.js). Kept compact — [id, cat, icon, name, desc].
 * If tools_catalog.php defines $TOOLS_META it is used; otherwise this fallback runs.
 */
if (!isset($TOOLS_META)) {
    $TOOLS_META = yassota_tools_meta();
}

$reqTool = isset($_GET['t']) ? preg_replace('/[^a-z0-9\-]/', '', $_GET['t']) : '';
$reqCat  = isset($_GET['cat']) ? preg_replace('/[^a-z]/', '', $_GET['cat']) : '';

/* ------------------------------------------------------------------ */
/* SINGLE TOOL PAGE                                                    */
/* ------------------------------------------------------------------ */
if ($reqTool) {
    $tool = null;
    foreach ($TOOLS_META as $t) if ($t['id'] === $reqTool) { $tool = $t; break; }
    if (!$tool) { http_response_code(404); }
    $cat = $tool ? ($CATS[$tool['cat']] ?? null) : null;
    $base = yassota_base_url();

    $jsonld = $tool ? json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => $tool['name'],
        'applicationCategory' => 'WebApplication',
        'operatingSystem' => 'Any',
        'description' => $tool['desc'],
        'url' => $base . '/tools.php?t=' . $tool['id'],
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

    yassota_header([
        'title'       => ($tool ? $tool['name'] . ' — أداة مجانية' : 'الأداة غير موجودة') . ' | Yassota',
        'description' => $tool ? $tool['desc'] : 'الأداة المطلوبة غير موجودة.',
        'active'      => 'tools',
        'jsonld'      => $jsonld,
    ]);

    if (!$tool) {
        echo '<div class="container"><div class="empty-state"><h1>الأداة غير موجودة</h1><p><a class="tag" href="/tools.php">تصفّح كل الأدوات</a></p></div></div>';
        yassota_footer(); exit;
    }
    ?>
    <div class="container">
      <div class="breadcrumb"><a href="/">الرئيسية</a> › <a href="/tools.php">الأدوات</a> › <a href="/tools.php?cat=<?= e($tool['cat']) ?>"><?= e($cat['name'] ?? '') ?></a> › <?= e($tool['name']) ?></div>
      <section class="page-hero" style="padding-top:20px">
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
          <div class="tcard-icon <?= e($cat['cls'] ?? 'cat-text') ?>" style="width:60px;height:60px;font-size:26px;border-radius:16px"><?= $tool['icon'] ?></div>
          <div>
            <h1 style="margin:0"><?= e($tool['name']) ?></h1>
            <p class="lead" style="margin:4px 0 0"><?= e($tool['desc']) ?></p>
          </div>
        </div>
        <div style="margin-top:12px"><span class="pill pill-free">مجاني ١٠٠٪</span> <span class="tag">يعمل في المتصفح — بياناتك لا تغادر جهازك</span></div>
      </section>

      <div id="toolMount" data-tool="<?= e($tool['id']) ?>"></div>

      <div class="ad-zone" id="adInArticle"></div>

      <section style="margin-top:34px">
        <h2 style="font-size:20px">عن أداة <?= e($tool['name']) ?></h2>
        <p style="color:var(--muted);max-width:760px;line-height:1.9"><?= e($tool['desc']) ?> تعمل هذه الأداة بالكامل داخل متصفحك دون رفع أي بيانات إلى خوادمنا، مما يجعلها سريعة وآمنة ومناسبة للاستخدام اليومي. جميع أدوات Yassota مجانية تمامًا وبدون حدود استخدام.</p>
      </section>

      <section class="section-head"><h2>أدوات ذات صلة</h2><a class="more" href="/tools.php?cat=<?= e($tool['cat']) ?>">المزيد ←</a></section>
      <div class="grid grid-tools">
        <?php
        $rel = array_values(array_filter($TOOLS_META, fn($t) => $t['cat'] === $tool['cat'] && $t['id'] !== $tool['id']));
        foreach (array_slice($rel, 0, 4) as $t) yassota_tool_card($t, $CATS);
        ?>
      </div>
    </div>

    <script src="/assets/js/tools.js"></script>
    <script>
    (function () {
      var mount = document.getElementById('toolMount');
      var id = mount.getAttribute('data-tool');
      var tool = window.YassotaTools.byId(id);
      if (tool) window.YassotaTools.render(tool, mount);
      else mount.innerHTML = '<div class="flash-error">تعذّر تحميل الأداة.</div>';
    })();
    </script>
    <?php
    yassota_footer();
    exit;
}

/* ------------------------------------------------------------------ */
/* DIRECTORY PAGE                                                      */
/* ------------------------------------------------------------------ */
$total = count($TOOLS_META);
yassota_header([
    'title'       => 'أكثر من ' . $total . ' أداة ويب مجانية | Yassota Tools',
    'description' => 'مجموعة ضخمة من أدوات الويب المجانية: نصوص، حاسبات، ألوان، أدوات مطورين، سيو، أمان، محوّلات وحدات، وصور — كلها تعمل في متصفحك بدون تسجيل.',
    'active'      => 'tools',
]);
?>
<div class="container">
  <section class="page-hero">
    <span class="eyebrow">🧰 صندوق الأدوات</span>
    <h1><?= $total ?> أداة ويب مجانية في مكان واحد</h1>
    <p class="lead">نصوص، رياضيات، ألوان، تطوير، سيو، أمان، محوّلات، صور، إنتاجية وتسويق — كل الأدوات تعمل مباشرة في متصفحك، بدون تسجيل وبدون رفع بياناتك.</p>
    <div class="hero-search">
      <input type="text" id="toolSearch" placeholder="ابحث عن أداة… (مثال: كلمات، لون، JSON)" value="<?= e($_GET['q'] ?? '') ?>">
      <button type="button" onclick="document.getElementById('toolSearch').dispatchEvent(new Event('input'))">بحث</button>
    </div>
  </section>

  <div class="chip-row">
    <span class="chip active" data-cat-chip="all">الكل</span>
    <?php foreach ($CATS as $k => $c): ?>
      <span class="chip<?= $reqCat === $k ? ' active' : '' ?>" data-cat-chip="<?= e($k) ?>"><?= $c['icon'] ?> <?= e($c['name']) ?></span>
    <?php endforeach; ?>
  </div>

  <div class="ad-zone" id="adTop"></div>

  <?php foreach ($CATS as $ck => $c):
      $items = array_values(array_filter($TOOLS_META, fn($t) => $t['cat'] === $ck));
      if (!$items) continue; ?>
    <section class="section-head" data-cat-section="<?= e($ck) ?>">
      <h2><?= $c['icon'] ?> <?= e($c['name']) ?> <span style="font-size:14px;color:var(--muted);font-weight:600">(<?= count($items) ?>)</span></h2>
    </section>
    <div class="grid grid-tools" data-cat-grid="<?= e($ck) ?>">
      <?php foreach ($items as $t) yassota_tool_card($t, $CATS); ?>
    </div>
  <?php endforeach; ?>
</div>

<?php
yassota_footer();

/* ------------------------------------------------------------------ */
/* HELPERS                                                             */
/* ------------------------------------------------------------------ */
function yassota_tool_card(array $t, array $CATS): void
{
    $c = $CATS[$t['cat']] ?? ['cls' => 'cat-text'];
    ?>
    <div class="tcard-wrap">
      <a class="tcard" href="/tools.php?t=<?= e($t['id']) ?>" data-tool-name="<?= e($t['name']) ?>" data-tool-cat="<?= e($t['cat']) ?>">
        <div class="tcard-top">
          <div class="tcard-icon <?= e($c['cls']) ?>"><?= $t['icon'] ?></div>
          <div class="tcard-heading"><h3><?= e($t['name']) ?></h3><span class="pill pill-free">مجاني</span></div>
        </div>
        <p class="tcard-desc"><?= e($t['desc']) ?></p>
        <div class="tcard-foot"><span class="tcard-cta">افتح الأداة ←</span></div>
      </a>
    </div>
    <?php
}
