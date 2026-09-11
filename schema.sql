-- ============================================
-- CF Static Export Pro - D1 数据库 Schema
-- 版本: 2.5.0
-- ============================================

-- ============================================
-- 第一步：建表（顺序不能乱,必须一个表一个表的建）
-- ============================================

-- 1. 创建 posts 表（如果还没有）
CREATE TABLE IF NOT EXISTS posts (
    wp_id           INTEGER PRIMARY KEY,
    slug            TEXT UNIQUE NOT NULL,
    title           TEXT NOT NULL,
    content         TEXT,
    excerpt         TEXT,
    date            DATETIME NOT NULL,
    modified        DATETIME,
    status          TEXT DEFAULT 'published',
    categories      TEXT,  -- JSON 数组 [{name, slug}]（结构化；旧版为纯 names 数组，读取端已兼容）
    tags            TEXT,  -- JSON 数组 [{name, slug}]
    featured_image  TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 2. 创建 taxonomies 表（v2：复合主键 (type, slug)，同名 category/tag slug 可共存）
CREATE TABLE IF NOT EXISTS taxonomies (
    type        TEXT NOT NULL,
    slug        TEXT NOT NULL,
    name        TEXT,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (type, slug)
);

-- 3. 创建 post_taxonomy 关联表（v2：带 taxonomy_type，关联到 taxonomies 复合主键）
CREATE TABLE IF NOT EXISTS post_taxonomy (
    post_id        INTEGER NOT NULL,
    taxonomy_type  TEXT NOT NULL,
    taxonomy_slug  TEXT NOT NULL,
    PRIMARY KEY (post_id, taxonomy_type, taxonomy_slug)
);

-- ============================================
-- 第二步：创建索引（表存在后才能执行）
-- ============================================

CREATE INDEX IF NOT EXISTS idx_posts_date ON posts(date DESC);
CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status);
CREATE INDEX IF NOT EXISTS idx_posts_slug ON posts(slug);
CREATE INDEX IF NOT EXISTS idx_pt_post ON post_taxonomy(post_id);
CREATE INDEX IF NOT EXISTS idx_pt_slug ON post_taxonomy(taxonomy_slug);

-- ============================================
-- 迁移 v1 → v2（仅当表仍是旧结构时执行；请在 Cloudflare D1 控制台手工执行）
--   旧 taxonomies: slug TEXT PRIMARY KEY / post_taxonomy 无 taxonomy_type
--   新表数据需重新全量导出后重建（见插件「全量导出（重建元数据）」按钮），
--   因此以下迁移会丢弃旧 taxonomy 数据，交由插件导出时重建。
-- ============================================
-- DROP TABLE IF EXISTS taxonomies;
-- DROP TABLE IF EXISTS post_taxonomy;
-- （重建上面 v2 建表语句即可）

-- ============================================
-- 验证查询（可选）
-- ============================================

-- 查看所有表
-- .tables

-- 查看 posts 表结构
-- PRAGMA table_info(posts);

-- 查看 taxonomies 表结构
-- PRAGMA table_info(taxonomies);

-- 查看 post_taxonomy 表结构
-- PRAGMA table_info(post_taxonomy);

-- 查看所有索引
-- SELECT name FROM sqlite_master WHERE type='index';

-- ============================================
-- 清理数据（谨慎使用）
-- ============================================

-- 清空所有数据（保留表结构）
-- DELETE FROM posts;
-- DELETE FROM taxonomies;
-- DELETE FROM post_taxonomy;

-- 删除所有表（重建）
-- DROP TABLE IF EXISTS posts;
-- DROP TABLE IF EXISTS taxonomies;
-- DROP TABLE IF EXISTS post_taxonomy;