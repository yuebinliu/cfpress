# CF Static Export Pro — 路线图 / 待办（ROADMAP）

> 本文件只记「**还没做的事**」和「**未拍板的方案**」。已完成、已部署的架构与进度以 `AGENTS.md` 为准（那是权威交接文档）。
> 最后更新：2026-09-13

---

## A. 第三方编辑器对接（Headless 写入口）—— 仅设计，**未拍板、未编码**

**目标**：不进 WP 后台，用本地编辑器（Obsidian 插件 / Ulysses / 手机 App / 脚本）直接读写这个站 —— 价值是「解耦写作入口」。

**当时的推荐组合（2026-09-11 讨论结论，用户未最终确认）**

| 层 | 方案 | 说明 |
|---|---|---|
| 接口协议 | **WordPress REST 写子集 + Application Passwords 认证** | `GET/POST /wp/v2/posts`、`PUT\|PATCH /wp/v2/posts/<id>`、`GET /wp/v2/categories\|tags`、`POST /wp/v2/media`（图 → R2）。第三方编辑器认的就是这套 |
| 内容形态 | md 原稿直存（非标准扩展字段 `content_format:"md"`） | 渲染出口走 Worker 端 `marked`（现有能力，无需新代码） |
| 防覆盖 | **方案 A（推荐）**：D1 `posts` 加 `origin`（`'wp'`/`'worker'`）+ `worker_updated_at`；WP 导出前判 `origin='worker'` 则**跳过导出 + 跳过 R2 上传**（记日志）；Worker 侧 md 写入置 `origin='worker'` | 备选 **方案 B**：导出 SQL 从 `INSERT OR REPLACE` 改 `ON CONFLICT(wp_id) DO UPDATE … WHERE posts.content_format != 'md'`（省一步，但会挡住「在 WP 端改 md 文」） |
| 配套 | 限流 + 审计日志 + 幂等（重复提交不重复发文）；写后触发重建（列表 / 单页 / RSS） | |

**被否掉的方案**：XML-RPC（`metaWeblog` / `wp.*`）—— 生态萎缩、攻击重灾区、Worker 侧无成熟库。

**现状事实（2026-09-13 复核）**
- 冲突风险**仍然存在**：`cf-static-export-pro.php:1110-1111` 的导出 SQL 是 `INSERT OR REPLACE` 且硬编码 `content_format='html'` → WP 每导出一次就会把第三方编辑器写的 md 覆盖回 html。
- Worker 侧 md 副线路**已可用**（`admin.js` 按 `content_format` 存/渲染，`mdToHtml` 走 marked）。
- 「切页签污染 md 原稿」的编辑器 bug 已修（commit `a762a0b`：页签改为「可编辑 + 只读预览」，md 原稿是唯一真源）。

**下一步（等用户点头）**：出一份落地清单 —— D1 加列、端点契约、Token 方案、导出端跳过逻辑、薄客户端最小形态。

---

## B. 其它待办

1. **四个 `X-Auth-Key` 写接口无限速**：`/upload`、`/delete`、`/purge-cache`、`/d1-batch` 共用单一 `X-Auth-Key`，无频率限制 / IP 白名单（用户有其它站点「一天大量发布」的场景）。Worker 后台自身的写操作已有内存滑窗限速。
2. **旧键 `tpl:sindex-builtin` 清理**：其功能已由 `tpl:index` 接管（部署时 `scripts/sync-themes-index.mjs` 扫描 `static/wp-content/themes/*/tpl.json` 注入），现在只剩「兼容双写 + 兼容读取」——涉及 `worker/src/routes.js:114`、`worker/src/admin.js:133-143 / 932 / 978 / 1029`、`views/template-editor.php:1816`。确认无外部依赖后可移除。
3. **导出剩余耗时**：D1 写入已批量化、全量已并发（`tools/bulk-export-worker.php` + 设置页并发数），剩余是 WP 端渲染 + R2 上传的固定开销。
4. **HTML 稳定化**：同一篇两次渲染的 HTML 差约 15B（动态 id / 时间戳类片段），导致「上传去重」对 HTML 不命中。
5. **模板细节**：站点名在模板里是硬编码文本（未用 `{{SITE_NAME}}`）；含 `<svg>` 的容器不会被整体包进 `{{#IF_*}}` 块（不影响正确性）。
6. **媒体库大批量删除偏慢**（R2 单删约 1s，分批 20 即可）。
7. **单页重建**：模板改动只影响「新建 / 重建」的单页；需要时在 Worker 后台跑「重建全部单页」。
