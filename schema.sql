-- ============================================
-- CF Static Export Pro — D1 数据库 Schema（参考快照）
-- 版本: 3.1.0（2026-09 文档库）
--
-- ⚠️ 正常情况下**不需要手工执行本文件**：
--    · Worker 端：调用 /api/setup/status 或 /api/setup 时会 ensureCoreSchema() 自动建表
--      （worker/src/admin.js）；styles / app_passwords 也会在首次使用时自动建。
--    · WP 插件端：导出前 ensure_d1_schema() 自动建表（cf-static-export-pro.php）。
--
-- 本文件是「三处保持一致」的参考快照，便于手工建库、排障与阅读：
--    ① worker/src/admin.js 的 ensureCoreSchema()
--    ② cf-static-export-pro.php 的 ensure_d1_schema()
--    ③ 本文件
--   改表结构时**三处同步**，否则两端行为会不一致。
-- ============================================

-- 1) 文章
CREATE TABLE IF NOT EXISTS posts (
    wp_id           INTEGER PRIMARY KEY,          -- WP 的 post ID；Worker 端诞生的文章为时间戳量级（≥ 1e11）
    slug            TEXT UNIQUE NOT NULL,         -- 与 WP sanitize_title 同形态（非 ASCII → 小写 %xx）
    title           TEXT NOT NULL,
    content         TEXT,                         -- md 原稿或 HTML（由 content_format 决定）
    content_format  TEXT DEFAULT 'html',          -- 'html' | 'md'（md 的渲染出口在读取端）
    excerpt         TEXT,
    date            DATETIME NOT NULL,
    modified        DATETIME,
    status          TEXT DEFAULT 'published',     -- 'published' | 'draft'
    comment_status  TEXT DEFAULT 'open',          -- 'open' | 'closed'（文章级评论开关）
    categories      TEXT,                         -- JSON 数组 [{name, slug}]
    tags            TEXT,                         -- JSON 数组 [{name, slug}]
    featured_image  TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 2) 站点设置（k/v：'site' = 站点基础设置，'cache' = 缓存策略 + 批量任务配置）
CREATE TABLE IF NOT EXISTS settings (
    k TEXT PRIMARY KEY,
    v TEXT
);

-- 3) 后台用户（Worker 后台登录；应用密码也挂在用户上）
CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_login      TEXT UNIQUE NOT NULL,
    user_pass       TEXT NOT NULL,                -- pbkdf2_sha256$<iter>$<salt>$<hash>
    user_nicename   TEXT,
    user_email      TEXT,
    user_url        TEXT,
    user_registered DATETIME DEFAULT CURRENT_TIMESTAMP,
    display_name    TEXT,
    role            TEXT DEFAULT 'admin',         -- admin | editor | author | subscriber
    avatar_url      TEXT,
    user_status     INTEGER DEFAULT 1,            -- 1 启用 / 0 禁用
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    settings        TEXT
);

-- 4) 分类法（v2：复合主键 (type, slug)，同名 category/tag slug 可共存）
CREATE TABLE IF NOT EXISTS taxonomies (
    type        TEXT NOT NULL,                    -- 'category' | 'tag'
    slug        TEXT NOT NULL,
    name        TEXT,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (type, slug)
);

-- 5) 文章 ↔ 分类法关联
CREATE TABLE IF NOT EXISTS post_taxonomy (
    post_id        INTEGER NOT NULL,
    taxonomy_type  TEXT NOT NULL,
    taxonomy_slug  TEXT NOT NULL,
    PRIMARY KEY (post_id, taxonomy_type, taxonomy_slug)
);

-- 6) （已废弃）模板风格版本表
-- ⚠️ style-refactor（docs/style-refactor.md）：风格不再存 D1 —— 改为
--   wp-content/themes/<id>/tpl.json（内置 assets / 用户 R2），清单在 site:ctx.styleIndex，
--   生效指针在 settings k='style'。此表保留仅作**迁移历史**；迁移完成后可 DROP。
-- CREATE TABLE IF NOT EXISTS styles (
--     id          TEXT NOT NULL,
--     version     INTEGER NOT NULL,
--     name        TEXT,
--     tpl_json    TEXT,
--     updated_at  TEXT,
--     PRIMARY KEY (id, version)
-- );

-- 7) 应用密码（第三方编辑器认证；哈希为 sha256$<salt>$<hash>，高熵随机串无需慢哈希）
CREATE TABLE IF NOT EXISTS app_passwords (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL,
    name          TEXT NOT NULL DEFAULT '',
    pass_hash     TEXT NOT NULL,
    last_used_at  TEXT DEFAULT '',
    created_at    TEXT NOT NULL,
    revoked       INTEGER NOT NULL DEFAULT 0
);

-- 8) 评论（B1：WP → D1 单向同步；Worker 端用它渲染静态单页的评论区）
--    comment_id    = D1 主键（WP 端评论 = WP comment_ID；Worker 端诞生的 = 时间戳形态，B2 再用）
--    wp_comment_id = 回拉后在 WP 的 comment_ID（B2 的映射；B1 阶段与 comment_id 相同）
--    origin        = 'wp' | 'worker'（B2 判断"哪些还没回拉"）
CREATE TABLE IF NOT EXISTS comments (
    comment_id    INTEGER PRIMARY KEY,
    wp_comment_id INTEGER DEFAULT 0,
    post_id       INTEGER NOT NULL,
    parent_id     INTEGER NOT NULL DEFAULT 0,
    author        TEXT DEFAULT '',
    author_email  TEXT DEFAULT '',
    author_url    TEXT DEFAULT '',
    avatar        TEXT DEFAULT '',
    date_text     TEXT DEFAULT '',
    date          TEXT DEFAULT '',
    content       TEXT DEFAULT '',
    approved      INTEGER NOT NULL DEFAULT 0,
    type          TEXT DEFAULT 'comment',
    origin        TEXT DEFAULT 'wp',
    updated_at    TEXT DEFAULT ''
);

-- 索引
CREATE INDEX IF NOT EXISTS idx_posts_date   ON posts(date DESC);
CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status);
CREATE INDEX IF NOT EXISTS idx_posts_slug   ON posts(slug);
CREATE INDEX IF NOT EXISTS idx_pt_post      ON post_taxonomy(post_id);
CREATE INDEX IF NOT EXISTS idx_pt_slug      ON post_taxonomy(taxonomy_slug);
CREATE INDEX IF NOT EXISTS idx_apppw_user   ON app_passwords(user_id);
CREATE INDEX IF NOT EXISTS idx_comments_post   ON comments(post_id);
CREATE INDEX IF NOT EXISTS idx_comments_parent ON comments(parent_id);

-- 9) 文档库（独立于 posts 的内容来源：存量 MD/HTML 文件）
--    source_prefix = abc（原稿目录）；path = abc 内相对路径（唯一）；成品 R2 = xyz/<path去扩展名>.html
--    xyz 不冗余存（见 docs/document-library.md D20，改 xyz 零 D1 代价）
CREATE TABLE IF NOT EXISTS documents (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    source_prefix TEXT NOT NULL,
    path          TEXT NOT NULL,
    format        TEXT DEFAULT 'md',            -- 'md' | 'html'
    title         TEXT DEFAULT '',
    excerpt       TEXT DEFAULT '',
    status        TEXT DEFAULT 'published',     -- 'published' | 'draft'
    date          TEXT DEFAULT '',
    updated_at    TEXT DEFAULT '',
    UNIQUE(source_prefix, path)
);
CREATE INDEX IF NOT EXISTS idx_documents_prefix ON documents(source_prefix);

-- ============================================
-- 排障用查询
-- ============================================
-- SELECT name FROM sqlite_master WHERE type='table';
-- PRAGMA table_info(posts);
-- SELECT COUNT(*) FROM posts WHERE status = 'published';
-- SELECT k FROM settings;

-- ============================================
-- 清理（谨慎；D1 控制台手工执行）
-- ============================================
-- DELETE FROM posts;            -- 只清数据，保留结构
-- DROP TABLE IF EXISTS posts;   -- 连结构一起删（下次 ensureCoreSchema 会重建）
