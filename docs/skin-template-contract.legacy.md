# （历史版）风格模板契约

> ⚠️ **本文件是历史版（已废弃）** —— 契约重建时的手写稿，归档仅供查阅（例如看当时的取舍与历史记录）。
> 现行手册：`docs/skin-contract.md`（由 `docs/skin-contract.json` 生成）；工程/审计见 `docs/skin-audit.json`。
> **本文件不参与** `node tools/contract-check.mjs` 校验，内容可能已与渲染器不一致 —— 以现行手册为准。

---
# 风格模板契约（写模板时看这一份）

> 定稿 2026-09（依据 `worker/src` 现网代码逐函数实测）。
> 关系：`docs/skin-placeholder-split.md`（**分工原则**：谁产出什么）/ `docs/skin-extraction-v3.md`（经典抓取链路）/
> `docs/static-hosting-extraction.md`（职责边界）。本文是**面向模板作者（人或 AI）的参考手册**。

---

## 0. 30 秒版

1. 共 **6 类模板**：`pageHome` / `pageCategory` / `pageTag` / `pageArchive` / `single` / `item`。
2. 渲染 = **纯字符串替换**（`split/join`），不是模板引擎。占位符写几次都会换；**没写的毫无成本**；没有循环、没有表达式、没有 `else`。
3. 条件块必须用**白名单里的 flag**。写错名字 ⇒ 两个标记被出口兜底清掉、**中间内容永远显示**，只在日志里留一行 —— **静默失败**。
4. "重复"只能由渲染器产生：文章列表（`{{CONTENT}}`）、侧边栏项、评论树。**模板自己不能循环**。
5. 模板内容从 **`tpl:active`**（KV）取 ⇒ 手写风格**应用即生效，不需要 deploy**；预览 `/preview/<slug>/` 直接读 `tpl.json`。

权威实现：`worker/src/templates.js`（列表页 + 条目）、`worker/src/admin.js`（单页）、`worker/src/comments.js`（评论）。

> ⚠️ `worker/src/templates.js` 是**生成产物**（源 = `views/template-editor.php` 的 `cfse_helpers_js()`；
> 生成端 **1 份**列表页 JS 展开成产物 **4 份**函数）。改渲染器必须两处同改、计数对上（1↔4），**不要手改产物**。

---

## 1. 六类模板

| kind | 谁用它 | 模板从哪取 | 最低要求 | 备注 |
|---|---|---|---|---|
| `pageHome` | `/` 首页 **+ `/?s=` 搜索页** | `tpl:active.pageHome` | `{{CONTENT}}` | 搜索页复用本模板 |
| `pageCategory` | `/category/<slug>/` | `tpl:active.pageCategory` | `{{CONTENT}}` | |
| `pageTag` | `/tag/<slug>/` | `tpl:active.pageTag` | `{{CONTENT}}` | |
| `pageArchive` | `/2024/`、`/2024/09/` | `tpl:active.pageArchive` | `{{CONTENT}}` | 唯一会填 `{{YEAR}}`/`{{MONTH}}` 的页面 |
| `single` | 文章单页（**静态快照写 R2**） | `tpl:active.single` | `{{POST_CONTENT}}` | 改模板后需**重建单页**才生效 |
| `item` | 4 个列表页里的**每条** | `tpl:active.item` | `{{POST_TITLE}}` + `{{POST_URL}}` | 被 4 个列表页 + 搜索页共用 ⇒ 只写"通用条目" |

**四个页面壳的替换清单逐字相同**（渲染器里是 4 份复制代码），差别只在传入的变量（`{{TITLE}}` / `{{YEAR}}` / `{{MONTH}}`）。

---

## 2. 占位符全表

### 2.1 站点级（值来自 `site:ctx`）

| 占位符 | 页面壳 | single | item | 说明 |
|---|:-:|:-:|:-:|---|
| `{{SITE_URL}}` | ✅ | ✅ | ✅ | 站点根，**不带**尾斜杠 |
| `{{SITE_NAME}}` | ✅ | ✅ | ✅ | |
| `{{SITE_DESC}}` | ✅ | ✅ | ✅ | 站点副标题 |
| `{{SITE_FOOTER}}` | ✅ | ✅ | — | `site:ctx.footerHtml` |
| `{{SITE_LOGO}}` | ✅ | ✅ | — | **整块** `<div class="wp-block-site-logo">…</div>`，不是裸 URL |
| `{{SEARCH_ACTION}}` | ✅ | ✅ | — | 搜索表单的 `action` 值（= 站点根）；`<form>`/`<input name="s">` 自己写 |
| `{{SOCIAL_LINKS}}` | ✅ | ✅ | — | 一串 `<li class="wp-block-social-link">…</li>`；**外层 `<ul>` 留模板里** |
| `{{MENU}}` | ✅ | ✅ | — | 渲染成 `<ul class="menu">…</ul>`（CMS 独立维护，不与 WP 后台菜单联动） |

### 2.2 页面壳专属（4 类通用）

| 占位符 | 说明 |
|---|---|
| `{{CONTENT}}` | **列表的全部条目**（= `item` 模板渲染 N 次后拼接）。不写它 ⇒ 页面没有任何文章 |
| `{{PAGINATION_PREV}}` / `{{PAGINATION_NEXT}}` | 上一页 / 下一页链接。**HTML 由渲染器生成**（`<a class="wp-block-query-pagination-previous">← Older posts</a>`），模板只能决定"放哪" |
| `{{PAGE_NUMBER}}` / `{{TOTAL_POSTS}}` | 当前页码 / 文章总数 |
| `{{TITLE}}` | 分类页 / 标签页 / 归档页 = 该分类(标签)名或年月；**首页 / 搜索页 = 站点名** |
| `{{YEAR}}` / `{{MONTH}}` | 仅 `pageArchive` 有值；其它页面写它就是未替换 ⇒ 被清空 |
| `{{SIDEBAR_CATEGORIES}}` / `{{SIDEBAR_ARCHIVES}}` / `{{SIDEBAR_TAGS}}` | 动态侧边栏三块（见 §4.1） |

### 2.3 `item` 专属

| 占位符 | 说明 |
|---|---|
| `{{POST_TITLE}}` / `{{POST_URL}}` / `{{POST_DATE}}` | 标题 / 永久链接 / 日期（`YYYY-MM-DD`） |
| `{{POST_EXCERPT}}` | 摘要（**item 没有 `{{POST_CONTENT}}`**） |
| `{{POST_CATEGORIES}}` / `{{POST_TAGS}}` | 已拼好的 `<a href="…">名称</a>`，多个以 `, ` 分隔 |
| `{{POST_AUTHOR}}` / `{{POST_AUTHOR_URL}}` | 作者名 / 作者归档地址（空则 `{{POST_AUTHOR_URL}}` 为空串） |
| `{{POST_THUMBNAIL}}` | 特色图 URL，**回退正文首图** |
| `{{COMMENT_COUNT}}` / `{{COMMENT_COUNT_TEXT}}` | 评论数 / 文案（`No Comments` / `1 Comment` / `N Comments`） |

### 2.4 `single` 专属（含 §2.1 全部 + 以下）

| 占位符 | 说明 |
|---|---|
| `{{POST_TITLE}}` / `{{POST_DATE}}` / `{{POST_EXCERPT}}` | 标题 / 日期 / 摘要 |
| `{{POST_CONTENT}}` | **正文**（`content_format='md'` 时由 Worker 侧 marked 渲染） |
| `{{POST_URL}}` | 本篇永久链接 |
| `{{POST_CATEGORIES}}` / `{{POST_TAGS}}` / `{{POST_AUTHOR}}` / `{{POST_AUTHOR_URL}}` / `{{POST_THUMBNAIL}}` | 同 item 语义 |
| `{{CFSE_POST_ID}}` | 本篇 `wp_id`（半动态评论标记用，见 §4.2） |
| `{{COMMENTS}}` | 评论区内容（由 `site.comments.mode` 决定：第三方 embed 片段 / 骨架再生 / 空） |
| `{{PREV_URL}}` `{{PREV_TITLE}}` `{{NEXT_URL}}` `{{NEXT_TITLE}}` | 上一篇（更早） / 下一篇（更新） |

> `single` **没有** `{{TITLE}}`、`{{CONTENT}}`、`{{PAGINATION_*}}`、`{{COMMENT_COUNT*}}`。
> **`single` 模板不写 `{{CFSE_POST_ID}}` 也不会坏** —— 只是评论区失去半动态注入能力。

---

## 3. 条件块（白名单 = 全部）

语法 `{{#IF_FLAG}}…{{/IF_FLAG}}`，条件为假时**整块删除**（含容器，避免留空壳）。

| flag | 条件 | 适用 |
|---|---|---|
| `LOGO` / `SOCIAL` / `MENU` | 对应站点值非空 | 页面壳 + single |
| `CATEGORIES` / `TAGS` | 有分类 / 有标签 | item + single |
| `AUTHOR` / `THUMBNAIL` | 有作者 / 有图 | item + single |
| `COMMENTS` | 评论区非空 | single |
| `PREV` / `NEXT` | 有上/下一篇 | single |
| `hasPage` | 当前页 > 1 | 页面壳（⚠️ **唯一小写**，写 `{{#IF_HASPAGE}}` 无效） |
| `COMMENT_AUTHOR_URL` / `COMMENT_CHILDREN` | 评论有作者链接 / 有子评论 | 评论骨架（§4.2） |

**规则**

- 标记必须**成对**；渲染器每次取**第一个**闭合标记 ⇒ **同名 IF 不可嵌套**。
- flag 只认上表。**写不存在的 flag ⇒ 两个标记被 `cfseStripLeftovers()` 清掉、中间内容永远显示**（不报错，只在 console 留 `[cfse] unreplaced placeholders`）。这是本契约最容易踩的坑。
- 未替换的**普通占位符**（如模板里写了个不存在的 `{{POST_SLUG}}`）同样被清成空串 + 记日志。

---

## 4. 骨架段（不在 6 类模板里）

两者都存在 `tpl:active` 的独立键上；**缺失时渲染器回退硬编码**（所以可以只写 `{{SIDEBAR_CATEGORIES}}` 而完全不给骨架）。

### 4.1 `sidebar`（侧边栏项级骨架，`{categories, archives, tags}`）

| 占位符 | categories | archives | tags |
|---|:-:|:-:|:-:|
| `{{ITEM_URL}}` | ✅ | ✅ | ✅ |
| `{{ITEM_NAME}}` | ✅ | ✅ | ✅ |
| `{{ITEM_COUNT}}` | ✅ | ✅ | ✅ |
| `{{ITEM_SIZE}}` | 空串 | 空串 | 字号（8~22pt，WP 算法） |
| `{{ITEM_POSITION}}` | ✅ | ✅ | ✅ |

骨架是**单个 `<li>`**，渲染器按数据循环填充 ⇒ 骨架里不要写循环标记。

### 4.2 `comments`（评论骨架，`{list, entry, children, form}`）

| 段 | 可用占位符 |
|---|---|
| `list`（外壳） | `{{COMMENTS}}`（顶层评论树）、`{{COMMENT_COUNT}}`、`{{POST_TITLE}}`、`{{POST_URL}}` |
| `entry` / `children` | `{{COMMENT_ID}}` `{{COMMENT_AUTHOR}}` `{{COMMENT_AUTHOR_URL}}` `{{COMMENT_AUTHOR_PLAIN}}` `{{COMMENT_AVATAR}}` `{{COMMENT_AVATAR_2X}}` `{{COMMENT_DATE}}` `{{COMMENT_DATETIME}}` `{{COMMENT_CONTENT}}` `{{COMMENT_DEPTH}}` `{{COMMENT_ODD_EVEN}}` `{{COMMENT_ALT}}` `{{COMMENT_THREAD_CLASS}}` `{{COMMENT_CHILDREN}}`（子树，递归） |
| `form`（主题的 `#respond`） | `{{COMMENT_POST_ID}}` |

评论渲染注意：

- 回复链接用 `href="#respond"` + `data-cfse-parent="{{COMMENT_ID}}"`，渲染端/注入脚本会接管（点它聚焦表单并带上 parent）。
- 更深一层由 `{{COMMENT_CHILDREN}}` 递归填充；渲染器**只替换本层那一处**（同名占位符在子树里必须走递归，不可全局替换）。
- `list` / `entry` 缺失 ⇒ 该风格评论区整块隐藏（仍会给出 form，若有 form）。

### 4.3 半动态评论注入标记（`single` 模板里的关键用法）

想在**不重建单页**的前提下让新评论/审核结果即时可见，必须把评论区包进这对标记：

```html
<!--CFSE-COMMENTS-START id={{CFSE_POST_ID}}-->{{#IF_COMMENTS}}{{COMMENTS}}{{/IF_COMMENTS}}<!--CFSE-COMMENTS-END-->
```

- 有标记 ⇒ 每个单页请求按需从 D1 现场替换该区间（`Cache-Control: max-age=60`）。
- 无标记 ⇒ 评论区只是静态快照，**要重建单页才更新**。
- 评论关闭（`comment_status=closed`）或站点评论 off ⇒ 该区间被替换为**空**。

---

## 5. 做不到的事（与直觉相反，先看这段）

| 想要 | 能吗 | 说明 |
|---|:-:|---|
| 写循环（多条、多列、页码列表） | ❌ | 没有循环语法。"多条"只有 `{{CONTENT}}`（文章）与侧边栏/评论三处骨架 |
| 分页页码 `1 2 3 …` | ❌ | 只有 `{{PAGINATION_PREV}}` / `{{PAGINATION_NEXT}}`，且**链接 HTML 由渲染器生成**，文案/类名不能自定义 |
| `{{#IF_X}}…{{#ELSE}}` | ❌ | 无 else；需要"另一支"就再写一个条件块（用取反的 flag —— 目前没有取反 flag） |
| 条件里做判断（`{{#IF_COUNT>3}}`） | ❌ | 条件只有上表 13 个 flag |
| 同名 IF 嵌套 | ❌ | 渲染器只找第一个闭合标记 ⇒ 会错 |
| 引用任意字段（`{{POST_MODIFIED}}`） | ❌ | 只认上表；请求时替换成空串 + 日志 |
| 单页/列表混用同一模板 | ⚠️ | 可行的只有"共用的站点级占位符"；`item` 与 `single` 字段集不同 |

---

## 6. 骨架示意（结构说明，不是可直接粘贴的成品）

**`pageHome`（最小）**

```html
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>{{SITE_NAME}}</title>
<link rel="stylesheet" href="{{SITE_URL}}/style/my-theme.css"></head>
<body>
  <header>
    <a href="{{SITE_URL}}/">{{SITE_NAME}}</a>
    {{#IF_MENU}}<nav>{{MENU}}</nav>{{/IF_MENU}}
    <form action="{{SEARCH_ACTION}}" method="get"><input name="s" type="search"></form>
  </header>
  <main>
    <h1>{{TITLE}}</h1>
    {{CONTENT}}
    <nav class="pager">{{PAGINATION_PREV}}{{PAGINATION_NEXT}}</nav>
    {{#IF_hasPage}}<p>第 {{PAGE_NUMBER}} 页 / 共 {{TOTAL_POSTS}} 篇</p>{{/IF_hasPage}}
  </main>
  <aside>
    <ul>{{SIDEBAR_CATEGORIES}}</ul>
    <ul>{{SIDEBAR_ARCHIVES}}</ul>
    <ul class='wp-tag-cloud'>{{SIDEBAR_TAGS}}</ul>
  </aside>
  <footer>{{SITE_FOOTER}}</footer>
</body></html>
```

**`item`（最小）**

```html
<article class="post">
  <h2><a href="{{POST_URL}}">{{POST_TITLE}}</a></h2>
  <div class="meta">{{POST_DATE}}{{#IF_AUTHOR}} · <a href="{{POST_AUTHOR_URL}}">{{POST_AUTHOR}}</a>{{/IF_AUTHOR}}</div>
  {{#IF_THUMBNAIL}}<img src="{{POST_THUMBNAIL}}" alt="">{{/IF_THUMBNAIL}}
  <p>{{POST_EXCERPT}}</p>
  <div>{{#IF_CATEGORIES}}分类：{{POST_CATEGORIES}}{{/IF_CATEGORIES}}{{#IF_TAGS}} 标签：{{POST_TAGS}}{{/IF_TAGS}}</div>
</article>
```

**`single`（评论部分）**

```html
<h1>{{POST_TITLE}}</h1>
<div class="meta">{{POST_DATE}}{{#IF_AUTHOR}} · {{POST_AUTHOR}}{{/IF_AUTHOR}}</div>
<article>{{POST_CONTENT}}</article>
<nav>{{#IF_PREV}}<a href="{{PREV_URL}}">← {{PREV_TITLE}}</a>{{/IF_PREV}}{{#IF_NEXT}}<a href="{{NEXT_URL}}">{{NEXT_TITLE}} →</a>{{/IF_NEXT}}</nav>
<div id="comments"><!--CFSE-COMMENTS-START id={{CFSE_POST_ID}}-->{{#IF_COMMENTS}}{{COMMENTS}}{{/IF_COMMENTS}}<!--CFSE-COMMENTS-END--></div>
```

---

## 7. 预览能验证什么 / 不能验证什么

`GET /preview/<slug>/`（6 页签，只读，直接读 `tpl.json`，不动线上）。

| 能验证 | 不能验证（预览里必然为空/看不到） |
|---|---|
| 站点级占位符、菜单、logo、社交 | `{{PREV_*}}` / `{{NEXT_*}}`（预览不传上下篇 ⇒ `{{#IF_PREV}}` 永远为假） |
| 列表条目、摘要、日期、分类标签 | `{{COMMENTS}}`（预览不传评论） |
| 侧边栏三块（**用真实数据**） | 多页分页（预览 `totalPages=1` ⇒ `{{PAGINATION_NEXT}}` 为空） |
| 单页正文 / 元信息 / 标题 | "无分类 / 无标签 / 无作者 / 无图"的空值路径（样例数据全都有值） |
| `{{TITLE}}`（分类/标签/归档样例） | 搜索页（`{{TITLE}}` 会变成站点名） |

⇒ **空值路径要自己构造**（例如临时用一篇无标签的文章看 item 是否留残句）。

---

## 8. 资源从哪来

| 资源 | 放哪 | 生效方式 |
|---|---|---|
| 自己的 CSS / JS / 图片 | 风格包内相对路径（上传到 R2 `style/<slug>/…`），或放 `worker/static/style/<slug>/…` | R2：上传即可访问；static：`wrangler deploy`（更省，走 assets 直出） |
| 站点内的资源（uploads 等） | 一律用 `{{SITE_URL}}` 前缀 | — |
| 代码高亮 | **不用管** | `single` 渲染器检测到正文含 `<pre|code>` 时自动注入 `/hl/github.min.css` + `/hl/highlight.min.js`（仅单页，列表页没有） |
| `/wp-content/themes/<slug>/…` | ⚠️ 只有**抓取包**自带这些文件 | 手写风格引用它 = 依赖某个主题包存在（static 是部署快照，运行时改不了） |

---

## 9. 改动 → 生效路径（记住这张表，别"看着没变"）

| 改了什么 | 需要做什么 |
|---|---|
| 模板内容（页面壳 / item） | 后台「应用风格」写 `tpl:active` ⇒ **列表页即时生效**（列表页每请求渲染） |
| 模板内容（`single`） | 应用风格 **+ 重建全部单页**（单页是写进 R2 的静态快照） |
| 站点配置（站名 / 菜单 / logo / 社交） | 改完即生效（渲染时替换）；**但已生成的单页仍是旧值** ⇒ 需重建 |
| 风格资源（CSS/JS/图） | R2：上传即生效；static：`wrangler deploy` |
| 只加一个纯静态页面 | 无（本系统不生成独立静态页） |
| 抓取风格包更新 | 抓取 → 拉回本地 → `wrangler deploy` → 应用风格 → 重建单页 |

---

## 10. 已知不一致 / 待统一（记录，未处理）

1. `{{#IF_hasPage}}` 是**唯一小写** flag（其余全大写）—— 容易写错，且错了是静默的。
2. `{{SITE_NAME}}` 在 `single` 里转义（`escHtml`），在页面壳 / item 里**不转义**（站名含 `&`/`<` 时行为不同）。
3. `item` 与 `single` 的字段集不对称：`single` 有 `{{POST_CONTENT}}`/`{{COMMENTS}}`/`{{PREV_*}}`，`item` 有 `{{COMMENT_COUNT*}}`/`{{POST_EXCERPT}}` 但无正文。
4. 页面壳**没有** `{{#IF_CATEGORIES}}` 一类的"页面级条件"（那是 item 的）。
5. 搜索页复用 `pageHome`，`{{TITLE}}` 落到站点名（页面壳里没有"搜索词"占位符）。
