# 风格抓取精化 v3（P0/P1/P2）

> 状态：**已实现并验证**（2026-09-19，CFSE 新链路）。
> 相关：`views/skin-builder.php`（新链路核心）、`views/template-editor.php`（生成端）、`worker/src/{templates,routes,tplstore,admin,site}.js`。

---

## 1. 背景与目标

CFSE 的「风格抓取」有两条链路：

| | 新链路（**主路径**） | 旧链路（回退） |
|---|---|---|
| 函数 | `cfse_fetch_skin_from_wordpress()` | `cfse_fetch_skin_from_wordpress_legacy()` |
| HTML 来源 | **内部渲染**：`tools/skin-render.php`，每类型一个 CLI 进程（规避 WP load-once）+ 探针 token 驱动映射 | HTTP 抓原站首页/单页 + 主题类名正则清洗 |
| 产物 | `cfse_custom_templates` + `styles/<slug>/tpl.json` + 重新生成 `templates.js` | 同上（旧形态） |

本次目标（P0/P1/P2）：让抓取结果**更贴近 WP 前台真实输出**，尤其是侧边栏组件与评论/作者区。

---

## 2. 已实现

### 2.1 侧边栏三块：widget id 定位 + 完整 `<li>` 骨架（P0+P1）

- **定位**：`cfse_skin_sidebar_by_widget()` 用 **widget DOM id**（`id="categories-N"` / `archives-N` / `tag_cloud-N`）在渲染出的页面里精确定位 widget 块；旧的 class 正则（`wp-block-categories-list` / `widget_archive` / `wp-tag-cloud`…）保留作兜底。
- **骨架提取**：从块里取**第一个 `<li>` 的完整 HTML**，把数据位换成占位符：
  - `href="…"` → `{{ITEM_URL}}`、标签文本 → `{{ITEM_NAME}}`、括号计数 → `{{ITEM_COUNT}}`
  - tag 云另有 `{{ITEM_SIZE}}`（字号，Worker 端按算法算）
- **落地**：骨架写进 `tpl.json` 的 **`sidebar` 段**：
  ```json
  "sidebar": {
    "categories": "<li class=\"cat-item\"><a href=\"{{ITEM_URL}}\">{{ITEM_NAME}}</a></li>",
    "archives":   "<li><a href='{{ITEM_URL}}'>{{ITEM_NAME}}</a></li>",
    "tags":       "<li><a href=\"{{ITEM_URL}}\" class=\"tag-cloud-link tag-link-position-{{ITEM_POSITION}}\" style=\"font-size: {{ITEM_SIZE}}pt;\" aria-label=\"{{ITEM_NAME}} ({{ITEM_COUNT}} items)\">{{ITEM_NAME}}</a></li>"
  }
  ```
  - 骨架里 **term id 类名（`cat-item-N` / `tag-link-N`）已去掉**（无对应静态数据，避免所有条目带同一个 id）；
    `{{ITEM_POSITION}}`（序号）/ `{{ITEM_SIZE}}`（字号）由 Worker 端按序/按算法填。
- **Worker 再生**：`cfseSidebarCategoryItems/ArchiveItems/TagItems(categories, site, sidebarTpl)` 读 `sidebarTpl`，逐条替换占位符；**旧模板无 `sidebar` 段 → 回退原硬编码 `<li>`**。
- **传递链**：`style/apply` 把 `tpl.sidebar` 写进 KV `tpl:active.sidebar`（`admin.js`）→ `routes.js` 5 处 `renderXxx` 传 `sidebarTpl: await getActiveSidebarTpl(env)`（`tplstore.js` 新增）。

### 2.2 评论（结构保留 + 元数据 ID 占位化）

- `cfse_skin_single_comments()`：保留 `#comments` 结构，包 `{{#IF_COMMENTS}}…{{/IF_COMMENTS}}`；**`#respond`（评论表单）整块删除**（动态功能，静态站无用）。
- **元数据 ID 占位化**（WP 输出到 HTML 的结构性 DOM 钩子，随文章/评论 ID 变化）：
  - `li-comment-N` → `li-comment-{{COMMENT_ID}}`、`comment-N` → `comment-{{COMMENT_ID}}`
  - `replytocom=N` / `data-commentid` / `data-postid` / `data-belowelement` / `data-respondelement` / `data-replyto` → 去掉
- **评论数据**：暂**不填充**（`cfseIfBlock(h,'COMMENTS',false)` → 整块隐藏），等评论系统上线再补。

### 2.3 作者（替代方案：站点默认作者，**不改 D1 schema**）

- `{{POST_AUTHOR}}` / `{{POST_AUTHOR_URL}}`，包 `{{#IF_AUTHOR}}` 条件块（防空 → 不产生 `/author//` 坏链接）。
- **数据源**：`site:ctx` → `defaultAuthor {name, slug}`（WP 侧 `sync_site_info()` 写入，Worker 侧 `getSiteContext()` 消费）。
- **为什么不用 D1 列**：`posts` 表加 `author_name/author_slug` 需要 schema 迁移；而 `site:ctx` 是 JSON value（加字段**不改表结构**），对**单作者站点（绝大多数个人博客）完全精确**。
- **边界**：多作者站点会统一显示站点默认作者（不精确）；届时再评估 D1 列。

### 2.4 缩略图（替代方案：特色图或正文首图）

- `{{POST_THUMBNAIL}}`，包 `{{#IF_THUMBNAIL}}` 条件块。
- **数据源**：`post.thumbnail`（若有 D1 列）或 **`cfseFirstImage(post.content)`**（正文首个 `<img>`）——零数据层改动。
- **边界**：主题单页不输出特色图（如 twentyten）时，模板里就不会有 `{{POST_THUMBNAIL}}` —— 这是**忠实还原**（前台本来就不显示）。

---

## 3. 验证证据（2026-09-19，服务器 192.168.2.2）

- 生成链路：`cfse_fetch_skin_from_wordpress()` → `ok=y`，日志「模板已写入 + 风格包已生成」
- `tpl.json`：top keys = `name, tpl, sidebar`（**sidebar 段已写入**，三个骨架正确）
- single 模板：`{{#IF_AUTHOR}}=y`、`{{#IF_COMMENTS}}=y`、`{{POST_AUTHOR_URL}}=y`
- `templates.js`（生成产物）：含 `cfseFirstImage`、`'AUTHOR'` 条件块、`sidebarTpl`×13
- **D1 `settings` 的 `site` 行**（`wrangler d1 execute --remote` 实查）：
  ```json
  {…,"defaultAuthor":{"name":"admin","slug":"admin"}}
  ```
- **线上生效**（`wrangler deploy` + 写 `tpl:active.sidebar` 后，`https://doc.yuebin.uk/?t=<rand>` 绕 CDN）：
  - 分类 `<li class="cat-item">`（无写死 id）、归档 `<li><a href='…'>…</a></li>`（骨架单引号）
  - tags `tag-link-position-1..N` **递增**、`font-size` 按 count **各异**（8 / 15.64 / 22 / 12.58pt…）
  - 首页 HTTP 200、43262B

---

## 4. 待办 / 已知边界

1. **评论系统**：评论数据 + 评论条目骨架 —— 等 CMD 评论系统立项后做（届时需「2 篇探针矩阵」：A 无评论 / B 有评论，才能提取 `<ol class="commentlist">` 结构）。
2. ~~**tags 骨架细节**~~ ✅ **已修**（2026-09-19）：`tag-link-N` 去掉、`{{ITEM_POSITION}}` / `{{ITEM_SIZE}}` 占位化，Worker 端按序/按算法填。
3. **多作者站点**：作者会统一为站点默认作者（见 §2.3）。
4. **特色图**：主题不输出特色图时无缩略图占位符（见 §2.4）。
5. **legacy 回退**：主链路异常时回退旧抓取，产物**不含**上述新特性（骨架/评论/作者）—— 属兜底，可接受。

---

## 5. 相关代码位置速查

| 文件 | 内容 |
|---|---|
| `views/skin-builder.php` | 侧边栏骨架：**v2 `cfse_skin_sidebar_by_units()`（主路径，`b6b4ccb` 引入）+ `cfse_skin_extract_units()`（自动认重复单元）+ `cfse_skin_units_skeleton()`（多单元对照推骨架）**；v1 `cfse_skin_sidebar_by_widget()`（保留可回退）；三块入口 `cfse_skin_replace_sidebars()`；`cfse_skin_single_comments()` / `cfse_skin_single_author_thumb()`；`cfse_skin_map_templates()` 汇出 `sidebar` 段 |
| `views/template-editor.php` | `cfse_fetch_skin_from_wordpress()`（ctx 补作者/缩略图 + 探针特色图）；`cfse_helpers_js()`（生成端 `cfseRenderItem`/`cfseSidebar*`/`cfseFirstImage`）；`$tpl_payload['sidebar']` |
| `cf-static-export-pro.php` | `sync_site_info()` 写入 `defaultAuthor` |
| `worker/src/templates.js` | `cfseSidebar*`（骨架再生）、`cfseRenderItem`（作者/缩略图/条件块）、`cfseFirstImage` |
| `worker/src/tplstore.js` | `getActiveSidebarTpl()` |
| `worker/src/routes.js` | 5 处 `renderXxx` 传 `sidebarTpl`（`routes.template.js` 同步） |
| `worker/src/admin.js` | `style/apply` 写 `tpl:active.sidebar`；`renderSingleHtml` 新占位符 |
| `worker/src/site.js` | `getSiteContext()` 消费 `defaultAuthor` |
