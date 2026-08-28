<?php
/**
 * includes/articles.php — data layer for the Yassota articles/blog system.
 * ==========================================================================
 * Schema + CRUD helpers only (no HTML). Public rendering lives in
 * articles.php (list + single page, Syria-Home themed); admin CRUD is
 * wired into index.php's admin panel, reusing db()/setting()/csrf_*()
 * from includes/bootstrap.php.
 *
 * Required before use: includes/bootstrap.php (for db()).
 */

if (defined('YASSOTA_ARTICLES')) return;
define('YASSOTA_ARTICLES', 1);

function articles_migrate(): void
{
    if (!function_exists('db')) return;
    db()->exec("CREATE TABLE IF NOT EXISTS articles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug VARCHAR(190) NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL,
        excerpt VARCHAR(500) NULL,
        content MEDIUMTEXT NOT NULL,
        cover VARCHAR(500) NULL,
        category VARCHAR(80) NULL,
        keywords VARCHAR(400) NULL,
        lang VARCHAR(8) NOT NULL DEFAULT 'ar',
        status VARCHAR(20) NOT NULL DEFAULT 'published',
        views INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

/** Unique, URL-safe slug from a title (Arabic-friendly). */
function article_slugify(string $title): string
{
    $s = trim($title);
    $s = preg_replace('/[\s]+/u', '-', $s);
    $s = preg_replace('/[^\p{L}\p{N}\-]+/u', '', $s);
    $s = trim($s, '-');
    return $s !== '' ? mb_substr($s, 0, 160) : 'article-' . substr(md5($title . microtime()), 0, 8);
}

/** @return array<int,array> published articles, newest first, optionally by category. */
function articles_public(?string $category = null, int $limit = 100, int $offset = 0): array
{
    if (!function_exists('db')) return [];
    if ($category) {
        $st = db()->prepare('SELECT * FROM articles WHERE status=\'published\' AND category=? ORDER BY id DESC LIMIT ? OFFSET ?');
        $st->bindValue(1, $category);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->bindValue(3, $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
    $st = db()->prepare('SELECT * FROM articles WHERE status=\'published\' ORDER BY id DESC LIMIT ? OFFSET ?');
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->bindValue(2, $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function articles_count_published(): int
{
    if (!function_exists('db')) return 0;
    try { return (int)db()->query("SELECT COUNT(*) c FROM articles WHERE status='published'")->fetch()['c']; }
    catch (Throwable $e) { return 0; }
}

function articles_categories(): array
{
    if (!function_exists('db')) return [];
    try {
        return db()->query("SELECT category, COUNT(*) n FROM articles WHERE status='published' AND category IS NOT NULL AND category<>'' GROUP BY category ORDER BY n DESC")->fetchAll();
    } catch (Throwable $e) { return []; }
}

function article_by_slug(string $slug): ?array
{
    if (!function_exists('db')) return null;
    $st = db()->prepare('SELECT * FROM articles WHERE slug=? LIMIT 1');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function article_related(array $article, int $limit = 4): array
{
    if (!function_exists('db')) return [];
    $randFn = (defined('DB_DRIVER') && DB_DRIVER === 'sqlite') ? 'RANDOM()' : 'RAND()';
    $st = db()->prepare("SELECT * FROM articles WHERE status='published' AND category=? AND id<>? ORDER BY $randFn LIMIT ?");
    $st->bindValue(1, $article['category']);
    $st->bindValue(2, $article['id'], PDO::PARAM_INT);
    $st->bindValue(3, $limit, PDO::PARAM_INT);
    try { $st->execute(); return $st->fetchAll(); } catch (Throwable $e) { return []; }
}

function article_bump_views(int $id): void
{
    if (!function_exists('db')) return;
    try { db()->prepare('UPDATE articles SET views=views+1 WHERE id=?')->execute([$id]); } catch (Throwable $e) {}
}

/** Insert or update an article by slug (idempotent seed-friendly upsert). */
function article_upsert(array $a): int
{
    $slug = $a['slug'] ?: article_slugify($a['title']);
    $existing = article_by_slug($slug);
    $fields = [
        'slug' => $slug, 'title' => $a['title'], 'excerpt' => $a['excerpt'] ?? '',
        'content' => $a['content'], 'cover' => $a['cover'] ?? '', 'category' => $a['category'] ?? '',
        'keywords' => $a['keywords'] ?? '', 'lang' => $a['lang'] ?? 'ar', 'status' => $a['status'] ?? 'published',
    ];
    if ($existing) {
        db()->prepare('UPDATE articles SET title=?, excerpt=?, content=?, cover=?, category=?, keywords=?, lang=?, status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$fields['title'], $fields['excerpt'], $fields['content'], $fields['cover'], $fields['category'], $fields['keywords'], $fields['lang'], $fields['status'], $existing['id']]);
        return (int)$existing['id'];
    }
    db()->prepare('INSERT INTO articles (slug,title,excerpt,content,cover,category,keywords,lang,status) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$fields['slug'], $fields['title'], $fields['excerpt'], $fields['content'], $fields['cover'], $fields['category'], $fields['keywords'], $fields['lang'], $fields['status']]);
    return (int)db()->lastInsertId();
}

function article_delete(int $id): void
{
    if (!function_exists('db')) return;
    db()->prepare('DELETE FROM articles WHERE id=?')->execute([$id]);
}
