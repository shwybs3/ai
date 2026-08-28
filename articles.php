<?php
/**
 * articles.php — public articles/blog for yassota.com
 * =====================================================
 * - /articles.php               → list of published articles (paginated)
 * - /articles.php?cat=seo       → filtered by category
 * - /articles.php?slug=...      → a single article page (own <h1>, SEO,
 *                                  JSON-LD Article schema, related articles)
 *
 * Content lives in the `articles` table (includes/articles.php). Admin
 * create/edit/delete/publish is a tab in index.php's admin panel.
 */

$rootConfig = __DIR__ . '/config.php';
if (is_file($rootConfig)) require_once $rootConfig;
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/articles.php';
require_once __DIR__ . '/partials.php';

articles_migrate();

$slug = trim($_GET['slug'] ?? '');
$cat  = trim($_GET['cat'] ?? '');

/* ------------------------------------------------------------------ */
/* SINGLE ARTICLE                                                       */
/* ------------------------------------------------------------------ */
if ($slug) {
    $a = article_by_slug($slug);
    if (!$a || $a['status'] !== 'published') { http_response_code(404); }

    yassota_header([
        'title'       => $a ? ($a['title'] . ' | ' . (setting('site_name') ?: 'Yassota')) : 'المقال غير موجود',
        'description' => $a ? ($a['excerpt'] ?: mb_substr(strip_tags($a['content']), 0, 155)) : '',
        'active'      => 'articles',
        'image'       => $a['cover'] ?? '',
        'jsonld'      => $a ? json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $a['title'],
            'description' => $a['excerpt'] ?: mb_substr(strip_tags($a['content']), 0, 155),
            'image' => $a['cover'] ?: null,
            'datePublished' => date('c', strtotime($a['created_at'])),
            'dateModified' => date('c', strtotime($a['updated_at'] ?: $a['created_at'])),
            'inLanguage' => $a['lang'] ?: 'ar',
            'author' => ['@type' => 'Organization', 'name' => setting('site_name') ?: 'Yassota'],
            'publisher' => ['@type' => 'Organization', 'name' => setting('site_name') ?: 'Yassota'],
            'mainEntityOfPage' => yassota_base_url() . '/articles.php?slug=' . $a['slug'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
    ]);

    if (!$a) {
        echo '<div class="container"><div class="empty-state"><h1>المقال غير موجود</h1><p><a class="tag" href="/articles.php">تصفّح كل المقالات</a></p></div></div>';
        yassota_footer(); exit;
    }

    article_bump_views((int)$a['id']);
    $related = article_related($a, 4);
    $readMin = max(1, (int)round(mb_strlen(strip_tags($a['content'])) / 900));
    ?>
    <div class="container">
      <div class="breadcrumb"><a href="/">الرئيسية</a> › <a href="/articles.php">المقالات</a><?= $a['category'] ? ' › <a href="/articles.php?cat=' . e($a['category']) . '">' . e($a['category']) . '</a>' : '' ?> › <?= e($a['title']) ?></div>
      <article class="page-hero" style="padding-bottom:0">
        <?php if ($a['category']): ?><span class="eyebrow"><?= e($a['category']) ?></span><?php endif; ?>
        <h1><?= e($a['title']) ?></h1>
        <div class="article-meta">
          <span>📅 <?= e(date('Y-m-d', strtotime($a['created_at']))) ?></span>
          <span class="dot"></span><span>⏱️ <?= $readMin ?> دقيقة قراءة</span>
          <span class="dot"></span><span>👁️ <?= (int)$a['views'] ?> مشاهدة</span>
        </div>
        <?php if ($a['cover']): ?><img class="article-cover" src="<?= e($a['cover']) ?>" alt="<?= e($a['title']) ?>" loading="eager"><?php endif; ?>
      </article>

      <div class="ad-zone" id="adTop"></div>

      <div class="article-body"><?= $a['content'] /* trusted: authored/seeded content, not user input */ ?></div>

      <div class="ad-zone" id="adBottom"></div>

      <?php if ($related): ?>
      <section class="section-head"><h2>مقالات ذات صلة</h2><a class="more" href="/articles.php?cat=<?= e($a['category']) ?>">المزيد ←</a></section>
      <div class="grid-articles">
        <?php foreach ($related as $r) yassota_article_card($r); ?>
      </div>
      <?php endif; ?>
    </div>
    <?php
    yassota_footer();
    exit;
}

/* ------------------------------------------------------------------ */
/* LIST                                                                 */
/* ------------------------------------------------------------------ */
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 12;
$items = articles_public($cat ?: null, $perPage, ($page - 1) * $perPage);
$total = articles_count_published();
$categories = articles_categories();

yassota_header([
    'title'       => ($cat ? $cat . ' — ' : '') . 'مقالات وأدلة تقنية | ' . (setting('site_name') ?: 'Yassota'),
    'description' => 'مقالات وأدلة عملية وأصلية حول أدوات الويب، السيو، الإنتاجية، الأمان الرقمي، وريادة الأعمال على الإنترنت.',
    'active'      => 'articles',
]);
?>
<div class="container">
  <section class="page-hero">
    <span class="eyebrow">📰 المدوّنة</span>
    <h1><?= $cat ? e($cat) : 'مقالات وأدلة تقنية عملية' ?></h1>
    <p class="lead">محتوى أصلي ومتعمّق يساعدك على الاستفادة القصوى من أدوات الويب والإنترنت — بدون حشو، وبخطوات قابلة للتطبيق.</p>
  </section>

  <?php if ($categories): ?>
  <div class="chip-row">
    <a class="chip<?= !$cat ? ' active' : '' ?>" href="/articles.php">الكل (<?= (int)$total ?>)</a>
    <?php foreach ($categories as $c): ?>
      <a class="chip<?= $cat === $c['category'] ? ' active' : '' ?>" href="/articles.php?cat=<?= e($c['category']) ?>"><?= e($c['category']) ?> (<?= (int)$c['n'] ?>)</a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="ad-zone" id="adTop"></div>

  <?php if ($items): ?>
  <div class="grid-articles" style="margin-top:20px">
    <?php foreach ($items as $a) yassota_article_card($a); ?>
  </div>
  <?php else: ?>
  <div class="empty-state"><h2>لا توجد مقالات بعد</h2><p>عد قريبًا — نضيف محتوى جديدًا باستمرار.</p></div>
  <?php endif; ?>

  <?php $pages = (int)ceil($total / $perPage); if ($pages > 1): ?>
  <div style="display:flex;gap:8px;justify-content:center;margin:34px 0">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <a class="chip<?= $i === $page ? ' active' : '' ?>" href="/articles.php?p=<?= $i ?><?= $cat ? '&cat=' . e($cat) : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>
<?php
yassota_footer();

function yassota_article_card(array $a): void
{
    ?>
    <a class="acard" href="/articles.php?slug=<?= e($a['slug']) ?>">
      <?php if (!empty($a['cover'])): ?>
        <img class="acard-cover" src="<?= e($a['cover']) ?>" alt="<?= e($a['title']) ?>" loading="lazy">
      <?php else: ?>
        <div class="acard-cover"></div>
      <?php endif; ?>
      <div class="acard-body">
        <?php if (!empty($a['category'])): ?><span class="acard-cat"><?= e($a['category']) ?></span><?php endif; ?>
        <h3><?= e($a['title']) ?></h3>
        <p class="acard-excerpt"><?= e($a['excerpt'] ?: mb_substr(strip_tags($a['content']), 0, 140)) ?></p>
      </div>
    </a>
    <?php
}
