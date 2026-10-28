# 风格抓取 ↔ Worker 分工与占位符契约

> 定稿 2026-09-21。相关：`tools/fse-skin-extract.php`、`views/skin-builder.php`、`views/template-editor.php`、
> `worker/src/{site,admin,templates,comments}.js`、`cf-static-export-pro.php`。
> 前置阅读：`docs/skin-extraction-v3.md`（经典链路细节）、`docs/comments-module.md`。

---

## 1. 三层模型（务必分清）

| 层 | 存什么 | 谁写 | 何时变 |
|---|---|---|---|
| **`tpl.json`**（磁盘文件 / 部署资产） | **纯模板**，全是占位符 | WP 抓取程序 | 重新抓取 + `deploy` |
| **`site:ctx`**（KV） | **站点级的值**：logo / social / defaultAuthor / footerHtml / comments | WP `sync_site_info()` 或 Worker 后台 | 后台改配置时 |
| **`tpl:active`**（KV） | **当前生效的模板**；`style/apply` 可以在这一步把常量"编译"进去 | Worker `style/apply` | 应用风格 / 重新注入 |
| 渲染时 | 只要"每次都变"的：文章数据 / 分页 / 评论 / 侧边栏 | Worker | 每请求 |

`tpl:active` **现在存的是带占位符的模板原文**（证据：`admin.js` 的 `renderSingleHtml` 拿到它之后还要逐条
`rep('{{SITE_URL}}', …)`、`cfseIfBlock(h,'TAGS',…)`；若已是最终值这些动作就没有意义）。
"常量只算一次"的对象是 **`site:ctx`**，不是 `tpl:active`。

## 2. 分工原则

- **WP 侧 = 抓包 + 尽量还原。** 它是唯一能拿到"渲染结果"的人 ⇒ 负责 head、样式引用、主题资源、结构占位符化。
- **Worker 侧 = 占位符解析。** 它是唯一能拿到"站点运行数据"的人 ⇒ 负责所有值（每请求算，或算一次入 `site:ctx`）。

**判据：这个值在"抓取那一刻"是否已经确定？**

| 已确定、与运行数据无关 | 抓取时不确定 / 后台要能改 |
|---|---|
| head 结构、样式链接、模板骨架、分页结构 | logo、菜单项、社交账号、搜索地址、作者 |

⇒ 前者由 WP 侧产出；后者一律留占位符，交给 Worker。

### 2.1 菜单：归 Worker 侧（✅ 2026-09-21 已实现）

**不要由抓取程序固化成静态 HTML** —— 那会让菜单变成"抓取那一刻的死快照"。菜单必须
**在 Worker CMS 里自定义**（选分类 / 标签 / 文章 / 自定义链接），数据 **CMS 独立维护**，
不与 WP 后台菜单联动。

数据与用法：

- 提取器：`wp:navigation` / `wp:navigation-link` → `{{#IF_MENU}}{{MENU}}{{/IF_MENU}}`
- 存储：`site:ctx.menu` = 菜单树 `[{title, type, target, children:[…]}]`
  - `type` = `custom` | `category` | `tag` | `post`
  - `target`：`custom` 时是 URL（相对/绝对均可，原样用）；其余是 **slug**（Worker 自动拼
    `{站点}/category/<slug>/` 这类地址）
- 渲染：`cfseMenuHtml()`（`admin.js` 单页 + `templates.js`/生成端列表页，**两处同改**），
  输出 `<ul class="menu"><li class="menu-item[ menu-item-has-children]"><a href="…">…</a>[<ul class="sub-menu">…</ul>]</li></ul>`
- 后台：设置页「🧭 导航菜单」卡（行式编辑：类型 / 显示文字 / 链接或 slug / 层级 / ↑↓ / ✕）
- API：`PUT /api/settings` body `{site:{name, description, menu:[…]}}`；写入经 `sanitizeMenu()`
  （限 4 层 / 每层 60 项 / 字段长度）。**改完即生效**（`{{MENU}}` 是渲染时替换，不需要重建单页）。

### 2.2 head：必须归 WP 侧

块主题的 `templates/*.html` **本身不含 head**（head 由 `wp_head()` 运行时生成）。所以"还原"必须在 WP 侧补：
提取器在 WP 环境里临时切主题 → `wp_get_global_stylesheet()`（theme.json 派生的 global styles）+ `get_stylesheet_uri()`
+ 核心 block-library CSS，拼出 head。**不整页渲染**，但也不能推给 Worker。

### 2.3 必须有兜底：残留占位符检测

按此分工，WP 侧会越来越敢输出占位符 ⇒ 一旦 Worker 还没实现某个，线上就会把 `{{XXX}}` 原样挂出来。
**渲染出口统一扫一遍并清空 + 记日志**（`cfseStripLeftovers()`）：只匹配 `{{[#/]?[A-Z][A-Z0-9_]*}}`，
所以正文里的 `{{小写}}` 不受影响。

## 3. 占位符 ≡ 后台配置项（"可以不用，但不能没有"）

补全一个占位符 = 后台多一个自定义项。补全清单**按能力裁剪**，不按"这次产物出现了几个"。

| 占位符 | 语义 | 后台配置项 | 数据源 | 状态 |
|---|---|---|---|---|
| `{{SITE_LOGO}}` | 站点 logo | 上传 / 填 URL | `site:ctx.logo` | ✅ 2026-09-21 |
| `{{SEARCH_ACTION}}` | 搜索提交地址 | 固定 `站点根/`（可留可配） | 渲染时由 `site.url` 拼 | ✅ 2026-09-21 |
| `{{SOCIAL_LINKS}}` | 社交账号 | 各平台 URL | `site:ctx.social` | ✅ 2026-09-21（WP 侧读 option `cf_social_links`，每行 `service\|url`；后台 UI 待补） |
| `{{MENU}}` | 主导航 | **菜单项编辑器**（分类/标签/文章/自定义） | `site:ctx.menu` | ✅ 2026-09-21（后台「🧭 导航菜单」卡） |
| `{{SITE_DESC}}` / `{{SITE_FOOTER}}` | 站点描述 / 页脚 | 现有 | `site:ctx` | ✅ 既有 |
| `{{POST_AUTHOR}}` / `{{POST_AUTHOR_URL}}` | 作者 | 站点默认作者 | `site:ctx.defaultAuthor` | ✅ 既有 |
| `{{POST_*}}` / `{{CONTENT}}` / `{{TITLE}}` / `{{PAGINATION_*}}` / `{{COMMENTS}}` / `{{SIDEBAR_*}}` | 文章与结构数据 | 不需配置 | D1 / 渲染 | ✅ 既有 |

`{{#IF_*}}` 条件块：**空值必须整块隐藏**（否则留空容器）。Worker 端 `cfseIfBlock` 只认模板里**已有**的标记，
所以条件块必须由**提取器生成**（FSE 提取器 2026-09-21 已补：`IF_LOGO` / `IF_THUMBNAIL` / `IF_AUTHOR` /
`IF_CATEGORIES` / `IF_TAGS` / `IF_COMMENTS`）。

## 4. FSE 路线（块主题）

### 4.1 定位

**不是替代经典链路，而是"经典链路第 1 步的另一种实现"**：落点（`worker/static/wp-content/themes/<slug>/`）、
索引（`npm run deploy` 里的 `scripts/sync-themes-index.mjs` 扫描 → 写 KV `tpl:index`）、应用（`style/apply`）
**三段全部复用现成机制**。所以它不需要接进插件 UI——产物放对位置即自动成为"内置风格包"。

**独有能力**：不切主题、不渲染，直接读 `templates/` + `parts/` + `patterns/` ⇒ 可以在一次会话里
批量产出任意**已安装**块主题的风格包。经典链路做不到（必须切主题 + 渲染 + 探针）。

### 4.2 现状与缺口（截至 2026-09-21）

已修：

- `--site=` 此前是 `/* 预留 */` 空壳 ⇒ CLI 下 `home_url()` 退化 ⇒ 菜单 localhost 死链。**已修**（含 `HTTPS=on`）。
- 输出前 host 归一化（本站 URL → `{{SITE_URL}}`，含 percent-encoded 形态）。
- `social-links` 不再"保留原文"（自闭合 `social-link` 只会留下块注释 ⇒ 页面空白）⇒ 保留 `<ul>` 外壳 + `{{SOCIAL_LINKS}}`。
- 条件块补齐（见 §3）。

已解决（2026-09-21）：

- **head / 样式外壳**（§4.4）——用核心 canvas + `wp_head()`，两段式渲染。
- **主题资源随包拷贝**——`--assets=<风格包目录>`，按产物实际引用逐个拷。
- 条件块、`--site` 死链、`social-links` 空白（§4.2 上文）。

仍缺：

- **产物体积**：500KB × 5 段（块主题按块加载 CSS，各段重复度高）。
  **2026-09-21 决定不抽** —— 用户规划：后续精选几个主题放 `static`，其余走 R2，体积不是瓶颈。
- `{{MENU}}` 已完成（见 §2.1）；`fse_menu_html()` 保留但已不调用。

**已决定不处理（2026-09-21 用户拍板）：**

- `term-description`（14/35 个主题出现）= 分类/标签的**描述文本**（WP 后台分类编辑里的"描述"字段），
  显示在分类页标题下方。**只有站长主动填了才有内容**，多数站点不填 ⇒ 缺的只是分类页一段说明文字，
  不影响列表 / 导航 / 样式。**无实质影响 ⇒ 不改**。
  （真要补：D1 `taxonomies` 加 `description` 列 + 三处 schema 同步 + 导出写入 + Worker 渲染。）
- 个别主题的第三方块（`accordion`、`shortcode`）与需要执行 PHP 的块：**无法静态化**，原样保留。
- **不追求"每个主题零未覆盖"** —— 个别主题有残留是正常的，原样保留（浏览器当注释忽略）即可。

### 4.4 head 怎么来的（2026-09-21 定案）

块主题的 `templates/*.html` **本身不含 head** —— head 是 `wp_head()` 运行时生成的。所以：

**用 WP 核心的 canvas 外壳，一行 head 都不自己拼。**

- 外层框架 = `wp-includes/template-canvas.php`（WP 5.8+，块主题专用）
- head 内容 = `wp_head()` 的标准输出；已知它**必须先渲染块**，因为块渲染会"登记"自己需要的样式
  （文件里的原注释：*Get the template HTML. This needs to run before `<head>` so that blocks can add
  scripts and styles in `wp_head()`*）⇒ 先 `get_the_block_template_html()`（返回值丢弃），再取
  `wp_head()` / `wp_body_open()` / `wp_footer()`，拼成 `open`/`close` 两段外壳（`fse_shell_parts()`）。

**⚠️ 两个必须知道的坑（都踩过）：**

1. **主题必须在进程「启动前」就是目标主题。** `switch_theme()` 只改 option —— WP 只在
   `wp-settings.php` 里加载一次主题的 `functions.php` ⇒ 同请求内切主题会得到
   「上个主题的 enqueue + 新主题的路径」的**混合 head**（实测 `id='twentyten-block-style-css'`
   但 href 指向 `neve-fse/blocks.css`，而该文件并不存在）。
   **修法**：提取器发现主题不匹配时先 `switch_theme`，再 `exec` 另起一个进程真正渲染
   （与"切插件副本必须分两次请求"同型），父进程随后恢复原主题。
2. **`WP_Styles::$done` / `WP_Scripts::$done` 是全局的。** 核心 `wp_print_styles` 打印一次后
   就把 handle 记进 `$done` ⇒ 第二次 `wp_head()` 什么都不输出（实测第一个模板 9 个 `<style>`、
   后面的 0 个）。取 head 前清空 `done` 即可。

**资源随包**：`--assets=<风格包目录>`。`fse_copy_assets()` 从产物里提取**实际引用**的
`/wp-content/themes/<slug>/...`（含 `?ver=` 去参、`rawurldecode`），逐个从主题目录拷进包 ——
不整目录搬（避免把 `.php` / `vendor` 塞进去）。

**一条命令出完整包**：
```sh
php tools/fse-skin-extract.php --theme=neve-fse --site=https://<站点> \
    --assets=<worker/static/wp-content/themes/neve-fse> \
    --out=<同上>/tpl.json
```

### 4.5 全量主题兼容性测试（2026-09-21）

服务器 `wp-content/themes/` 下**全部主题**各跑一次提取器：

| 结果 | 数量 |
|---|---|
| 块主题（成功产出完整包） | **35** |
| 非块主题（`isFSE=n` 正确跳过） | **36** |
| 失败 | **0** |
| 其中**零未覆盖块** | **16** |

19 个主题有残留未覆盖块，逐项判断：

| 块 | 主题数 | 实质影响 | 处置 |
|---|---|---|---|
| `term-description` | 14 | 几乎无（分类描述，多数站点不填） | **不改**（见 §4.2） |
| `html` | 4 | **无** —— 自定义 HTML 块本来就该原样保留 | 不是缺口 |
| `read-more` | 3 | 小（"阅读更多"链接） | 可选 |
| `categories` / `tag-cloud` | 2 / 1 | 中（侧边栏式列表不显示） | 可用现有 `{{SIDEBAR_*}}` 补 |
| `post-comments` / `post-author-biography` / `post-time-to-read` | 各 1 | 小 | 可选 |
| `accordion` / `shortcode` | 1 / 1 | **无法静态化** | 原样保留 |

**这次全量抓出并修掉的 3 个真缺口**（只看 neve-fse 永远发现不了）：

1. `pattern` 展开**没走块转换** —— 官方主题（twentytwentyfour/five、blockwist…）的列表不写 `wp:query`，
   而是 `wp:pattern` 引一个 pattern，query + post-template 都在里面 ⇒ item 段为空（列表页没条目）。
2. `pattern` 只在 `patterns/<slug>.php` **平面路径**查找 —— mroya 放在 `patterns/template/` 子目录 ⇒ 取不到。
   改用核心 **`WP_Block_Patterns_Registry::get_registered($slug)`**（覆盖主题文件/子目录/PHP 注册/插件），
   文件系统递归扫描只作兜底；取不到记入 `missing_patterns` 并打印（不静默）。
3. `pattern` 支持**嵌套**（mroya：archive-posts-1 → query-posts-grid-1 → 才是 query）⇒ 改**循环展开**（上限 5 轮）。

**结论：还原度的口径**

- **结构 / 外壳 ≈ 100%**：文档外壳与 head 直接来自核心 `wp_head()`/`wp_footer()`（原样），主题资源按引用精确拷贝。
- **块结构**：16/35 零未覆盖；其余缺口多为无害（`html`）或不可为（`shortcode`）。
- **不是还原度问题、而是分工**：动态数据一律占位符化，由 Worker 侧填（§1）。
- 已知语义偏差（与"还原"相关，需单独记）：**作者**用 `site:ctx.defaultAuthor`（多作者不精确）、
  **缩略图**用 `post.thumbnail` 或正文首图（与 WP featured image 判定可能不同）、**菜单**抓取时固化。

### 4.3 常见坑

- **块的外层结构也必须补**（同 `wp-container-*` 一类问题）：核心渲染时才加的外层包装，
  读文件拿不到 —— 实测三处：① `wp:template-part` 会按 **`tagName` 属性**包一层
  `<header class="wp-block-template-part">…</header>`；② `site-title` 会输出
  `<h1 class="wp-block-site-title"><a href rel="home" aria-current="page">名</a></h1>`；
  ③ `post-title`/`post-date`/`post-content`… 也有各自的包装。
  **判据：凡是"整块替换成占位符"，都要先问一句"核心渲染时会不会加外层结构"** ——
  加的话就得走「**核心 `render_block()` 拿真实结构 + 把值换成占位符**」，不能手工拼 class
  （会和 `attrs` 不一致）。
  ⚠️ 用 `render_block()` 需要文章上下文 ⇒ `fse_setup_context()` 必须在**文件解析之前**调用。
- **容器 class 必须补**（`wp-container-*`）：块渲染时核心的 layout support
  （`wp_render_layout_support_flag()`，即挂在 `render_block` 上的那个 filter）会给容器块补
  `wp-container-<hash>` 类并生成 CSS。我们读**文件**拿骨架 ⇒ body 里没有这些类，而 head 里的
  `core-block-supports-inline-css` 依赖它们 ⇒ **flex/grid 的 gap / justify-content / align 与
  `> *` 的 margin 重置全部失效**（实测：真实渲染 body 有 5 个、我们的产物 0 个）。
  **修法**：容器块分支复用核心那个 filter 回调补类；实测 DOM 类与 head CSS **7/7 hash 匹配**。
- **`$open`/`$close` 是块注释，不是标签**（踩过**三次** —— `social-links`、layout support、
  以及最早的一次）：`fse_block_repl()` 里
  `$open = substr($html, $b['start'], $b['innerStart'] - $b['start'])` —— 起点是 **`<!-- wp:xxx -->` 注释**，
  所以 `$open` 只含注释。`social-links` 直接写 `$open . '{{SOCIAL_LINKS}}' . $close` 就把
  `<ul class="wp-block-social-links">` **外壳整个丢了**（渲染出来没有 `<ul>`）。
  凡是"保留外壳 + 内部占位化"的块，都要从 `$inner` 里取回真正的开标签。

- **改渲染函数必须两处同改**：`worker/src/templates.js` 是**生成产物**（源 = `worker/templates.base.js` 壳 +
  `views/template-editor.php` 的 `cfse_helpers_js()` 注入）。生成端 **1 份**列表页 JS 会被展开成产物里的
  **4 个**渲染函数（首页/分类/标签/归档）——改的时候计数要对上（1 ↔ 4）。
- **`worker/static/wp-content/themes/` 才是内置风格的权威清单**：`sync-themes-index.mjs` 只扫本地目录。
  服务器上多出来的包（如 `blogier` / `twentyseventeen`）文件在、但**不在 `tpl:index` 里**，后台看不到。
- wrangler 打包遵循 `.gitignore`；主题目录已被取消忽略（否则 tpl.json 更新上不了线）。

## 5. 本批实现（2026-09-21，提交 `95edd3c` / `e418703`）

- `site.js`：`getSiteContext()` 透传 `parsed.logo` / `parsed.social`（白名单式构造，必须显式加）。
- `admin.js`：`renderSingleHtml` 支持 `{{SITE_LOGO}}`（**回吐完整 `wp:site-logo` 块结构**——提取器替换掉的是整块，
  只给裸 URL 会在页面上露出一个 URL 文本）/ `{{SEARCH_ACTION}}`（= `site.url + '/'`，Worker 搜索入口是 `/?s=`）/
  `{{SOCIAL_LINKS}}`（`<li>` 列表，`<ul>` 外壳留在模板里）+ `IF_LOGO` / `IF_SOCIAL`。
- `templates.js`（产物）与 `cfse_helpers_js()`（生成端）两处同改：4 个列表页渲染函数各加上述替换。
- 渲染出口新增 `cfseStripLeftovers()`（§2.3）。
- `cf-static-export-pro.php`：`sync_site_info()` 写入 `logo`（`custom_logo`，URL 与 `cf_site_url` 对齐，避免
  CLI/内网抓取时的 localhost 前缀）与 `social`（option `cf_social_links`）。
- 提取器修复见 §4.2。

**验证**：`php -l` 两文件 No syntax errors；`node --check` 三文件通过；线上 `style/preview` 渲染 neve-fse
六页 `left=0`（无残留占位符）、`{{SEARCH_ACTION}}` → `https://doc.yuebin.uk/`；线上首页/分类页 HTTP 200 + `{{` 计数 0。
部署 Version `47a2d5af-5787-416c-afa7-fadf01a90e95` / 后续 `e418703` 对应版本。

## 6. 待办 / 已收敛

**已完成（2026-09-21）**：

- ✅ 站点级可配占位符：`{{SITE_LOGO}}` / `{{SEARCH_ACTION}}` / `{{SOCIAL_LINKS}}`（Worker 渲染 + 后台 UI）
- ✅ 菜单 `{{MENU}}`：渲染链 + 后台「🧭 导航菜单」编辑（§2.1）
- ✅ head：核心 canvas + `wp_head()`（§4.4）
- ✅ 主题资源随包：`--assets`（§4.4）

**已决定不做 / 不做**：

- ❌ 产物体积抽公共样式（用户规划：精选主题进 static、其余走 R2）
- ❌ `term-description`（无实质影响，见 §4.2）
- ❌ `tpl:active` 的"常量烘焙 + 自动重新注入"（§3）—— 现有 `site:ctx` 已是"算一次入 KV"，
  且 `{{MENU}}`/`{{SOCIAL_LINKS}}` 走**渲染时替换**，改配置后即时生效，无需重新注入

**仍可做（未排期）**：

1. 把 FSE 提取器**接进插件后台**（目前是 CLI；产物落对位置即自动成为内置风格包，见 §4.1）
2. `categories` / `tag-cloud` 块映射到 `{{SIDEBAR_*}}`（3 个主题受益）
3. WP 设置页的 `cf_social_links` 输入框（Worker 后台已有 UI，WP 侧可选）

## 7. 运维记录

### 7.1 切主题的探针必须防超时（踩过一次，很严重）

探针用 `register_shutdown_function` 恢复主题 —— 但如果请求被 curl `--max-time` **杀掉**
（SIGKILL 不触发 shutdown），主题就**留在目标主题上**。实测把生产站点切到了 `neve-fse`
（发现后立即用探针切回 `twentyten`）。

对策：① 探针别一次渲染太多块（`render_block('core/post-content')` 会跑 `the_content` 滤镜，很慢）；
② 宁可拆成多次短请求；③ **跑完必须复查** `stylesheet`。
提取器本身用「先切主题 → `exec` 子进程渲染 → 父进程恢复」两段式，不受影响。

### 7.2 废包清理（2026-09-21）

服务器 `worker/static/wp-content/themes/` 下清除三个废包残留（暂存 `/tmp/cfse-trash-20260921/`）：
`index.json`（已废弃机制：内置清单改为部署时扫描）、`blogier-2`（与 `blogier` 逐字节相同）、
`twentyten-2`（旧版本，`twentyten` 更新）。清理后目录 9 项，`tpl:index` 扫描到 7 个（本地目录为准）。
