# 风格画廊：批量抓取 wp.org 主题生成风格包

> 2026-09-21 建。脚本：`tools/run-batch-fetch.mjs`（本地编排）+ `tools/batch-fetch-themes.sh`（服务器执行）
> 配套：`tools/fse-skin-extract.php`（单个主题提取器）、`docs/skin-placeholder-split.md`（分工与占位符契约）

## 一、为什么下载放本地、提取放服务器

| 环节 | 位置 | 原因 |
|---|---|---|
| 取列表 / 下载 zip | **本地** | 服务器（192.168.2.2）**访问不了 wp.org**（API 空响应）；本地可以 |
| 解压 / 提取风格包 | **服务器** | 提取器要 WP 环境（`wp-load.php` + `render_block()`），且会切主题 |

## 二、一次完整运行

```bash
# 本地（会依次：下载 → pscp 上传 → 服务器 nohup 启动提取）
cd C:/Users/dog/Documents/cf
node tools/run-batch-fetch.mjs
```

**关键约定**：

- 只取**块主题**（wp.org `tag=full-site-editing`）。经典主题要走"渲染 + 探针值锚"，成本完全不同，**不在此脚本范围**。
- **下载的主题保留**在服务器 `wp-content/themes/<slug>/`（用户要本地看风格再筛选）。
- 产出落在**服务器本地目录**：`wp-content/plugins/cf/styles/<slug>/`
  （扁平：`tpl.json` + 该主题被引用的资源文件，与 `styles/` 既有约定一致）。
- **不自动 deploy**，也**不自动进 `worker/static/`** —— 由用户看过风格后再挑。

## 二之二、列表类块（categories / tag-cloud / archives）—— 走"骨架"机制（2026-09-22）

这三个块是**全站数据**（不是本条文章的值），模板里**只保留核心渲染的外壳**（`<ul>` / `<p>`），
内层交给 Worker 的 `cfseSidebarCategoryItems/ArchiveItems/TagItems` 用 **D1 数据 + 风格骨架**逐条再生。

- **提取器**：`fse_sidebar_skeleton()` —— 对块做核心渲染 → 取条目区内容 → **复用经典链路 v2 的两个函数**
  （`cfse_skin_extract_units()` 自动认"重复 ≥2 次"的单元 + `cfse_skin_units_skeleton()` 多单元对照推骨架）
  → 存进 `tpl.json` 的 **`sidebar` 段**（键 `categories` / `tags` / `archives`）。
  `require_once views/skin-builder.php`（**同一份实现**，不复制、不漂移）。
- **骨架只取"一级条目"**（`<li>` 或 `<a>`），**不含 `<ul>`** —— 与经典链路模板"自包含 `<ul>`"的约定一致。
- **送达链**（这段曾经断掉，2026-09-22 修）：
  `tpl.json.sidebar` → 前端 `app.js` apply/save **带 `sidebar`** → `style/apply`(admin.js) 写 **`tpl:active.sidebar`**
  → `getActiveSidebarTpl()` → `cfseSidebar*` 按骨架输出。
  - 之前断在：`style/save` 只存 6 类；前端 apply 只传 `tpl` ⇒ `tpl:active.sidebar` **恒为空** ⇒ 一路走默认 `<li>`。
  - `categories` / `archives` 的默认 `<li>` 恰好能塞进模板的 `<ul>`（"瞎猫碰死耗子"）；
    **`tag-cloud` 对不上** —— 核心是 `<p>` + 一串 `<a>`，所以它是唯一真正依赖骨架的块。
- **预览**：`tplPreviewHtml()` 现在带**真实侧边栏数据 + 该风格的骨架**（此前是 `sidebar: null` ⇒ 预览里这些位置是空白）。
- ⚠️ `{{SIDEBAR_*}}` 在**同一模板里出现多次**时，`rep()` 是全局替换 ⇒ 每处填相同内容（骨架只登记一次）。

## 三、日志与查看方式（下个会话先看这里）

| 日志 | 位置 | 内容 |
|---|---|---|
| 本地编排 | `tmp/batch-fetch-local.log` | 下载/上传进度（本地） |
| **服务器主日志** | `/mnt/optane/webstack/www/cfse-batch-fetch.log` | 每个主题：解压 → 提取 → `tpl=…B 资源=N uncovered=…`，末尾有 `OK=x SKIP=y FAIL=z` |
| nohup 原始输出 | 服务器 `/tmp/batch-fetch.out` | 同上（重定向） |

查看：

```bash
plink -ssh -batch -pw <pw> root@192.168.2.2 "tail -40 /mnt/optane/webstack/www/cfse-batch-fetch.log"
# 看已生成哪些风格包
plink ... "ls /mnt/optane/webstack/www/wp-content/plugins/cf/styles/"
```

`SKIP` = 该 zip 不是块主题；`FAIL` = 提取失败（日志里 `-- <slug> FAIL` 后跟最后 5 行原因）。

## 四、风险与边界

- **切主题**：提取器用「先切主题 → exec 子进程 → 父进程恢复」两段式，属于安全实现；但**务必只在本地开发服务器跑，生产别跑**。
- 第三方主题可能 fatal / 输出警告 ⇒ 脚本逐个独立进程处理，失败只影响该主题。
- 每个风格包 ~200KB–2MB；30 个约几十 MB ⇒ **不进 git**（`styles/` 已被忽略或只挑精选）。
- 后续再跑：已下载的 zip 会跳过；已存在的主题目录不重复解压。

## 四之二、预览与上线（R2 路线，2026-09-21 建）

风格包**不直接进 static**：先整包（`tpl.json` + 资源）上传 R2，浏览器预览后再挑精选进 static。

| 步骤 | 命令 | 说明 |
|---|---|---|
| 1. 包 → R2 | 服务器上 `node /tmp/upload-styles-r2.mjs --slugs=a,b --all` | key = `wp-content/themes/<slug>/<包内相对路径>`，与 tpl.json 里的引用**同形** ⇒ 预览时 assets 未命中自动落 R2 直出 |
| 2. 预览 | **浏览器直接开** `https://doc.yuebin.uk/preview/`（画廊）→ `/preview/<slug>/`（全屏 6 页签） | 只读路由：直接读 `tpl.json` 渲染，**不动 `tpl:active`、不改线上** |
| 3. 挑中后进 static | 复制 `styles/<slug>/*` → `worker/static/wp-content/themes/<slug>/` → `npm run deploy` | static 命中优先于 R2（免费直出）；不用改任何代码 |

- **上传用 `/upload`（X-Auth-Key）**，`--concurrency=6`；X-Auth-Key 通道限速 **600 请求/分/IP**，脚本每 380 个文件自动歇 61s。
- **空文件**（0 字节，如 `spectra-one/assets/js/script.js`）会被 `/upload` 拒（`Missing content`）⇒ 脚本直接跳过（无影响）。
- 脚本：`tools/upload-styles-r2.mjs`（本地开发用副本在服务器 `/tmp/`）。

### 线上预览路由（2026-09-21 新增）

`worker/src/preview.js`（由 `routes.js` / `routes.template.js` 转发；**非登录区、只读**）：

| 路由 | 作用 |
|---|---|
| `GET /preview/` | 画廊索引：KV `tpl:index`（带 name / current）+ **R2 里 `wp-content/themes/*/` 列举** 合并 ⇒ 即使 `npm run deploy` 覆盖了 KV，画廊也不缺项 |
| `GET /preview/<slug>/` | 全屏外壳：6 个页签 + iframe |
| `GET /preview/<slug>/?kind=<kind>` | 单类渲染（干净 HTML，供 iframe / 新窗口） |

- **为什么用 iframe**：6 类模板各自的 `<style>` 会互相污染，必须隔离（后台那个小预览是单类，所以没这问题）。
- `tpl.json` 读取顺序 = `static assets → R2`；模板里的资源（css/字体）由浏览器直读同路径，同样 assets→R2。
- **意义**：想看某套风格**不必先「应用」到线上**，也**不需要**把它登记进 KV —— 直接开 URL 即可。
- 渲染复用 `admin.js` 的 `tplPreviewHtml()`（已 export）⇒ 与后台预览、线上渲染**同一套函数**，预览即所得。
- `tools/preview-styles.mjs`（本地落盘预览）保留，但**已非主流程**，仅供离线排查。

### ⚠️ assets 会遮蔽 R2（2026-09-21 踩坑）

同一路径 `/wp-content/themes/<slug>/…` **可能同时在 static（assets）与 R2** 存在，而请求链是
**assets 优先**。所以：**若该主题已经在 `worker/static/` 里**（例如手动放进去的），
那么"重抓 → 传 R2"**不会**让它生效 —— 预览与线上仍读 static 的**旧版**。

**⇒ 重抓后必须同时更新 static**（该主题在 static 里时）：

```sh
styles/<slug>/*  →  worker/static/wp-content/themes/<slug>/  →  npm run deploy
```

**实测**：`saaslauncher` 曾在 static 里留着旧版（`item` 段的 post-title 仍是硬编码），
预览 `/preview/saaslauncher/?kind=pageHome` 的**10 条列表项全部显示同一篇的标题与链接**，
而 R2 里早已是修复版。`tpl.json` 直读 1809097（旧）vs 1808664（修复版），md5 一分即知。

**排查口径（重要）**：Worker 内部读 `tpl.json` 的顺序是 `assets → R2`，
所以**「curl 线上 `/wp-content/themes/<slug>/tpl.json`」不能区分 assets 与 R2**；
要分离得用 `npx wrangler r2 object get wordpress/wp-content/themes/<slug>/tpl.json --file x.json`。

**另一个坑**：验证时别用 PowerShell 的正则去匹配**中文**（`Get-Content -Raw` 的编码会让
`wordpress模板生成` 匹配出 0，造成"已修复"的假象）。用 **node** 读文件统计。

### 已知瑕疵（2026-09-21 抓取批次）

| 现象 | 影响包 | 原因 |
|---|---|---|
| `term-description` 未覆盖 | ollie / online-courses-fse / spectra-one / therapy-coach / twentytwentyfive / yith-wonder | 有意不做（用户决定） |
| `avatar` 未覆盖 | denovr-lite / saaslauncher / savara-lite / unibiz | 待做（`defaultAuthor` 替代） |
| `categories` / `tag-cloud` | denovr-lite / saaslauncher / variations | 可映射到 `{{SIDEBAR_*}}`（未做） |
| 结构几乎为空（tpl 28KB） | **ona-architecture** | 它是 **child theme**（`Template: ona`），pattern 全在父主题 ⇒ 父主题未装 ⇒ `missing_patterns` 8 个 |
| 个别字体 404 | neve-fse（open-sans）/ online-courses-fse（inter）/ yith-wonder（oswald-v48-*） | 主题自身引用的文件名与包内文件不符（`_wght` vs `_wdth,wght`）⇒ 浏览器回退字体 |

### 提取器修复（2026-09-21，`tools/fse-skin-extract.php`）

**A. 块级占位化失效（最重要，56/57 个块主题中招）**

- **根因**：`fse_block_inner_token()` 的正则 `(</[a-z]+>\s*)$` **只认纯字母标签名**，而 `post-title` 的默认外层是
  `<h1>`~`<h6>`（**带数字**）⇒ 永不匹配 ⇒ 落到最后的 `return $html;`，把核心渲染出的**真实标题 / 真实 permalink**
  原样留在产物里。`post-excerpt` 另走 `fse_block_value_token()`（要求"真实值 == 渲染值"），而核心摘要是
  `wp_trim_words` 处理过的 ⇒ 值不相等 ⇒ 同样漏（两类合计几乎全部主题）。
- **表现**：列表条目**每一条都显示同一篇文章的标题/链接**（= `fse_setup_context()` 取的那篇）。
- **修法（A + B + 老路径回退）**：
  1. 正则改 `</[a-z][a-z0-9]*>`（认 h1~h6）；
  2. `isLink` 形态（`<h3><a href="permalink">标题</a></h3>`）**保留 `<a>`**，href → `{{POST_URL}}`、文本 → `{{POST_TITLE}}`；
  3. 结构不符合假设时**回退老路径**（整块换成占位符 —— 丢结构，但绝不残留真实值）。
- `post-excerpt` / `read-more` 改成**按结构**占位化（不靠"值相等"）：前者 `__excerpt` 段 → `{{POST_EXCERPT}}`、
  more 链接 href → `{{POST_URL}}`；后者只换 href 与无障碍标题，**保留主题文案**。

**B. C 层兜底（对齐经典链路的 `cfse_skin_sanitize_template()`）**

- 新增 `fse_strip_context_values()`：在 host 归一化**之前**，把上下文文章的真实值（标题 / permalink / 摘要的多种形态）
  再扫一遍替换成占位符 ⇒ **任何块映射失效都能兜住**（经典链路有"探针 + 值锚 + sanitize"三层，这是 FSE 路线缺的第三层）。
- 实测（故意把 post-title 分支改成不占位化）：`context_values_stripped: 3`、产物里真实标题 **0 次**、`{{POST_TITLE}}` 2 次 ✓

**C. 其它**

1. **pattern ↔ part 多轮交替展开**（旧版单向顺序 ⇒ pattern 里引出的 `{{PART:header}}` 残留，8 个主题中招）
2. **item 段补展开**（`item` 在展开循环之后才补写 ⇒ 从没走过替换链）
3. 取不到的 pattern 清空 + 兜底清理未解析的 `{{PART:…}}/{{PATTERN:…}}`（**同时记入 `missing_patterns`，不静默**）
4. **资源拷贝正则补 `,` `[` `]`**（`Geologica-…CRSV,SHRP,slnt,wght.ttf`、`source-sans-pro[wght].woff2` 这类文件名会被截断）；
   ⚠️ **字符类里的 `-` 必须留在末尾** —— 去掉它会把 `assets/fonts/playfair-display/…` 截成 `playfair`（实测 `copied` 从 23 掉到 6）

### ⚠️ 验证口径（2026-09-21，这里踩过坑）

「数 `{{}}` 残留」**只对经典路线成立**（经典按值锚替换，残留可数）。FSE 路线的问题是**没生成占位符**，
而这种错误在残留计数里恰好是 **0**（看着"干净"）⇒ 必须换成**结构对照**：

- **元素数 vs 占位符数**：`class="…wp-block-post-title…"` 元素个数 应 ≤ 该块占位符个数
- **真实内容残留**：拿上下文文章的真实标题/摘要去产物里搜，**必须 0 次**

### 跑提取器的规矩

- **不要用管道接输出**（`php … | grep …`）—— 管道/SIGPIPE 会让**父进程**在 `switch_theme($prev_theme)` 之前被中断，
  **主题就留在被切过去的那个上面**（实测把站点留在了 saaslauncher，已用探针切回 twentyten）。
  用重定向（`> log 2>&1`）；`batch-fetch-themes.sh` 用的就是 `>> 日志`，所以批量一直没出事。

## 五、相关脚本

| 脚本 | 用途 |
|---|---|
| `tools/sync-wp-includes.sh` | 把 WP 核心资源（块样式全量 + 常用 JS）镜像到 `worker/static/wp-includes/`（路径原样、assets 直出） |
| `tools/fse-skin-extract.php` | 单个块主题 → 风格包（`--theme/--site/--assets/--out`） |
| `tools/upload-styles-r2.mjs` | 风格包 → R2（`--slugs=a,b` / `--all`；`/upload` + X-Auth-Key） |
| `tools/preview-styles.mjs` | 循环预览：线上 tpl.json → Worker style/preview → `tmp/preview/` 6 页 + 索引页 |
