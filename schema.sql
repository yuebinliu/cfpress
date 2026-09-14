-- ============================================
-- CF Static Export Pro — D1 数据库 Schema（参考快照）
-- 版本: 3.0.0（2026-09-14）
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

-- 6) 模板风格版本（每次保存 = 一个新版本，可回退）
CREATE TABLE IF NOT EXISTS styles (
    id          TEXT NOT NULL,
    version     INTEGER NOT NULL,
    name        TEXT,
    tpl_json    TEXT,
    updated_at  TEXT,
    PRIMARY KEY (id, version)
);

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

-- 索引
CREATE INDEX IF NOT EXISTS idx_posts_date   ON posts(date DESC);
CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status);
CREATE INDEX IF NOT EXISTS idx_posts_slug   ON posts(slug);
CREATE INDEX IF NOT EXISTS idx_pt_post      ON post_taxonomy(post_id);
CREATE INDEX IF NOT EXISTS idx_pt_slug      ON post_taxonomy(taxonomy_slug);
CREATE INDEX IF NOT EXISTS idx_apppw_user   ON app_passwords(user_id);

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
