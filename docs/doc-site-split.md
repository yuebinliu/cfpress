# 文档站独立化（Doc Site Split）设计 v2 —— 独立的文档 CMS

> ⚠️ **本文件已随代码迁入新仓**：权威版本是 `../cf-docs/docs/design.md`，
> 实施状态见 `../cf-docs/AGENTS.md`。此处仅保留给博客侧清理清单（§8）当索引，**改动请只改新仓那份**。

> 状态：**设计定稿，待实施**（本轮不写实现代码）。
> 关系：**取代** `docs/document-library.md` 的部署形态与配置存储部分（原 D2/D3/D19/§2.2 作废）；纯函数层（内链改写、侧边栏树、frontmatter 标题摘要提取、壳占位符哨兵填充）**整文件复用**。
> v1 → v2 的关键变化：你建好了**独立 D1**（`docs`，uuid `0f839d15-e104-421d-9d8f-659d3df3a97f`，0 张表）与**独立 R2**（桶 `docs`），并且**不迁数据** ⇒ 上一轮为「共享 D1」而做的妥协全部撤销。
> 日期：2026-09-28

---

## 0. 已拍板的决策

| # | 决策 | 内容 |
|---|---|---|
| S1 | **独立 Worker** | 独立 wrangler 项目 / `wrangler.toml` / 密钥 / 版本 / 回滚，与博客 Worker 完全无关 |
| S2 | **独立 R2 桶 `docs`** ✅ 已建 | 只放**文件**：原稿、成品、附件 |
| S3 | **独立 D1 `docs`** ✅ 已建 | 只放**元数据**：索引、路由、壳、设置。表名直接叫 `documents`（新库无历史包袱，不需要 `doc_pages` 这种避让名） |
| S4 | **不配 KV** | 配置直读 D1（读配额 500 万行/天，且渲染本就只在 miss 时查库）⇒ 少一个资源，也少掉「KV 最终一致 ~60s 才能验证」这个反复坑你的等待 |
| S5 | **三前缀，可达性按前缀一刀切** | `src/` 原稿**永不直达**；`pages/` 成品**唯一可直达**；`files/` 附件可直达。不按扩展名判断可达性（见 §2.1 的理由） |
| S6 | **md + html 双格式，判定靠 D1 字段** | 有 `content_format` ⇒ 免 R2 探测；**不做** URL 后缀区分（`.md` 对外暴露＝原稿可达） |
| S7 | **路由与壳都存 D1（v1 存 R2 的建议作废）** | 理由见 §3 |
| S8 | **`slug` 取「甲」语义** | `slug` = 相对 `source_prefix` 的路径且**不含扩展名**；`UNIQUE(source_prefix, slug)` |
| S9 | **模板体系独立，不复用博客风格** | 只有 `doc` / `index` / `404` 三种壳；不碰博客的 6 类模板、sidebar/comments 骨架、`tpl.json`、`styles` 表、`tpl:active` |
| S10 | **壳内联 CSS** | 新站零外部资源依赖（不引用 `/wp-content/themes/…`，那是旧缺陷成因之一） |
| S11 | **URL 稳定 + 事件失效** | 不把壳哈希写进 key；靠 §5 失效矩阵保证新鲜 |
| S12 | **不迁移数据** | 旧 `documents` 表与旧 R2 里的文档**全部弃用** ⇒ 主站清理不必留回退期，可直接删（§8） |
| S13 | **独立仓库、与 `cf` 平级** | 独立发布 = 独立 git（对齐 `cf-static-hosting` 的先例） |
| S14 | **后台单密码认证** | 不搬 `users/roles/app_passwords`；文档 CMS 是单人工具 |

**待你定（4 项，见 §9）**：域名、worker 项目名、后台口令、我是否可在工作区外建目录。

---

## 1. 独立度（v2：真独立）

| 维度 | 状态 |
|---|---|
| Worker 代码与发布 / Version / 回滚 | ✅ 独立 |
| 路由命名空间 | ✅ 独立 —— `RESERVED_PREFIXES` 那 20+ 项避让清单**整块消失**，`abc`/`xyz` 双前缀映射的必要性也大幅下降（`src` 与 `pages` 是**桶内前缀**，不再争 URL 命名空间） |
| R2 | ✅ 独立桶 |
| D1 | ✅ 独立库（v1 这里是「共享实例 + 新表」，现在彻底解耦） |
| 壳与配置 | ✅ 自己的 D1 表，不再寄住博客 `settings k='site'` 那一行 |
| KV | ✅ 不用（博客 KV 也无需共享） |
| 故障域 | ✅ 博客 Worker 挂了不影响文档站 |
| **仍共享：Cloudflare 账户配额** | ⚠️ 唯一剩余共享项，且**无法**用架构消除 —— 10 万请求/天、D1 5M 行读 + 10 万写/天、R2 10GB + 操作数**全部按账户累计**。多一个 Worker 不多一分钱额度；`CPU 10ms/请求`、`Subrequest 50/请求` 这类**单请求**限制才是各自独立的 |
| 内容引用 | ⚠️ 与架构无关：博客正文里若有指向 `/docs/…` 的链接，切换后必须改成新站绝对 URL（扫一遍 `posts.content` 即可） |
| marked 依赖 | ⚠️ **新站自己装 marked**，不要跨项目 import 博客的 `md.js`（774 字节也别抄路径），否则刚拆开的边界又焊回去 |

---

## 2. 存储布局

### 2.1 R2 桶 `docs`（只放文件）

```
src/<source_prefix>/<slug>.md          原稿（markdown）   ← 路由层显式拒绝，永不可达
src/<source_prefix>/<slug>.html        原稿（html）       ← 同上
pages/<out_prefix>/<slug>.html         成品              ← 唯一可直达
pages/<out_prefix>/index.html          目录页（route.has_index=1 时）
files/<source_prefix>/<相对路径>.png   附件（图片/字体/pdf…）← 可直达
```

**为什么是三前缀一刀切，而不是「`src/` 里只拦 `.md/.html`、图片放行」**

旧项目有个至今未决的缺口（`document-library.md` 的遗留段）：内链把 `./img/a.png` 改写成 `/xyz/img/a.png`，但**图片只存在于原稿区** ⇒ **成品里的图片 404**。靠「放行原稿前缀里的图片扩展名」能治，但那是要按扩展名做可达性判断——正是这次 `html` 原稿被直出/被覆盖事故的同型逻辑（`abc===xyz` 时原稿与成品撞同一个 key，静态分支先命中，壳永久不生效）。

所以：**可达性只看前缀，不看扩展名**。`src/` 无条件 404，图片一律落 `files/`。代价是上传时要分流（附件进 `files/`、正文进 `src/`），换来的是「不可能把原稿漏出去」这条可以一眼验证。

内链改写规则随之确定（两类，无第三种）：

| 原稿里的链接 | 改写成 |
|---|---|
| `.md` / `.html` / 无扩展名的站内相对链接 | `/pages/<out_prefix>/<base>.html` |
| 图片、附件、其它非文档扩展名 | `/files/<source_prefix>/<相对路径>` |
| `http(s)://`、`//`、`mailto:`、`#锚点`、`data:` | **不动** |

★ 这条比旧设计更简单：旧 `rewriteDocLinks()` 里「先判断是否落在 `abc` 前缀下」的分支（曾出过一个「把 `abc` 前缀再拼进判断、导致内链根本没改写」的线上 bug）在新规则下大幅缩减——`src/` 下不再有可直达对象，图片引用也不必与文档引用共用一套前缀判断。

### 2.2 D1 库 `docs`（只放元数据）

```sql
-- ① 索引（列名沿用旧语义但按甲收紧；全新库不需要避让名）
CREATE TABLE IF NOT EXISTS documents (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  source_prefix  TEXT NOT NULL,
  slug           TEXT NOT NULL,                      -- 相对 source_prefix，不含扩展名
  title          TEXT NOT NULL DEFAULT '',
  excerpt        TEXT NOT NULL DEFAULT '',
  content_format TEXT NOT NULL DEFAULT 'md' CHECK (content_format IN ('md','html')),
  status         TEXT NOT NULL DEFAULT 'published'  CHECK (status IN ('published','draft')),
  shell_id       TEXT,                               -- 单篇覆盖壳；NULL=按集合/默认解析
  sort           INTEGER NOT NULL DEFAULT 0,
  date           TEXT NOT NULL DEFAULT '',           -- frontmatter/mtime 常缺，不设 NOT NULL
  modified       TEXT NOT NULL DEFAULT '',
  updated_at     TEXT NOT NULL DEFAULT '',
  UNIQUE (source_prefix, slug)
);
CREATE INDEX IF NOT EXISTS idx_doc_prefix_status ON documents(source_prefix, status);

-- ② 目录级路由（你要的「源目录 → 输出目录 → 该级壳」）
CREATE TABLE IF NOT EXISTS doc_routes (
  source_prefix TEXT PRIMARY KEY,                    -- 与 documents.source_prefix 对应
  out_prefix    TEXT NOT NULL UNIQUE,                -- URL 前缀，全局唯一
  shell_doc     TEXT,                                -- 文档页壳（NULL → doc_settings.default_doc_shell）
  shell_index   TEXT,                                -- 目录页壳
  nav           TEXT NOT NULL DEFAULT 'sidebar' CHECK (nav IN ('none','sidebar','toc','prevnext')),
  has_index     INTEGER NOT NULL DEFAULT 1,          -- 是否生成 /out_prefix/ 目录页
  title         TEXT NOT NULL DEFAULT '',
  sort          INTEGER NOT NULL DEFAULT 0
);

-- ③ 壳（三种 kind，见 §3）
CREATE TABLE IF NOT EXISTS doc_shells (
  id         TEXT PRIMARY KEY,
  name       TEXT NOT NULL,
  kind       TEXT NOT NULL CHECK (kind IN ('doc','index','404')),
  html       TEXT NOT NULL,
  updated_at TEXT NOT NULL DEFAULT ''
);

-- ④ 设置（站点名/页脚/默认壳/导航）
CREATE TABLE IF NOT EXISTS doc_settings (
  k TEXT PRIMARY KEY,          -- 'site' | 'nav'
  v TEXT NOT NULL              -- JSON
);
```

三个刻意的设计点：
- **`CHECK` 而非应用层校验**：`content_format` 直接决定渲染分流，写脏一个值就会让某篇永远渲染失败（`md.js` 是 `breaks:true`，html 原稿过 marked 会把源码换行变 `<br>` 打乱排版 ⇒ 必须严格分流）。
- **`shell_id` 三层解析**：单篇 `documents.shell_id` → 集合 `doc_routes.shell_doc` → `doc_settings.default_doc_shell`。比旧版「只有 route 级一个 styleId」多一层，后台显示当前实际生效的是哪一层（旧项目吃过「改了壳但库指向别的壳」的亏，显示生效来源是唯一防线）。
- **`out_prefix UNIQUE`**：URL 前缀冲突必须被数据库拒绝（旧版靠应用层查重要求，且校验函数还牵扯到一堆博客保留字 —— 新站只需要拦 `pages`/`src`/`files`/`admin`/`api`/`_`）。

---

## 3. 模板：一套完全独立的小体系（回答「模板呢？」）

**结论：不复用博客风格体系。** 博客那 6 类模板（`single/item/pageHome/pageCategory/pageTag/pageArchive`）+ `sidebar` 骨架 + `comments` 骨架 + 72 项占位符契约，全部是为「博客列表页 + 文章页」服务的；文档站只需要**一个文档页壳 + 一个目录页壳 + 一个 404 壳**。把博客那套接过来，等于把刚拆掉的耦合从后门请回来。

### 3.1 三种壳

| kind | 用途 | 渲染入口 |
|---|---|---|
| `doc` | 文档页（正文 + 侧栏/TOC + 上下篇） | miss 时按需渲染 + 后台整集重建 |
| `index` | 目录页 `/out_prefix/`、`/out_prefix/sub/` | 同上 |
| `404` | 全站 404 页（带导航与搜索入口） | 路由层查不到文档时渲染，`status=404` |

### 3.2 为什么壳存 D1 而不是 R2（推翻 v1 的 §S7）

v1 我推荐 `_shells/` + `_routes.json` 存 R2，**前提是「D1 与博客共享 ⇒ 别往别人库里塞东西」**。你建了独立 D1，前提直接消失，而存 D1 白拿三样：

1. **§7 那三项跨库一致性检查不再需要** —— 路由、索引、壳同库，可以 `JOIN` 校验、可以在一个 batch 里改完（R2 与 D1 之间永远做不到原子）。
2. **R2 无 CAS 的坑消失** —— 路由/壳的增删改是行操作，不用「整文件读-改-写 + 并发覆盖」这套规避动作。
3. **备份边界更干净** —— 一句话能说清：**R2 只有文件，D1 只有元数据**；恢复 = 导 D1 + 拷 R2，不需要在两个存储之间对配置。

### 3.3 占位符（新站自己的小契约）

`doc` 壳：
```
{{TITLE}} {{CONTENT}} {{SIDEBAR}} {{TOC}} {{PREV}} {{NEXT}} {{UPDATED_AT}} {{COLLECTION}}
{{SITE_NAME}} {{SITE_URL}} {{SITE_DESC}} {{NAV}} {{FOOTER}}
{{#IF_LOGO}} {{#IF_SIDEBAR}} {{#IF_TOC}} {{#IF_PREV}} {{#IF_NEXT}} {{#IF_NAV}} {{#IF_SOCIAL}}
```
`index` 壳：`{{COLLECTION}} {{PAGE_LIST}} {{SITE_NAME}} {{SITE_URL}} {{SITE_DESC}} {{NAV}} {{FOOTER}}`
`404` 壳：`{{SITE_NAME}} {{SITE_URL}} {{NAV}} {{FOOTER}}`

两条从博客那边**继承过来实现、不继承概念**的机制（它们是真踩过坑才有的）：
- **哨兵法填占位符**：先把壳里已知占位符换成 `\0` 包裹的哨兵 → 清掉壳内**未识别**的 `{{XXX}}` → 再把哨兵换成真实值。顺序颠倒会把正文里「讲解占位符本身」的字面量一起吃空（`documents.js:394-404` 注释记录过这个修法）。
- **未知占位符兜底清空并记日志**：绝不把 `{{XXX}}` 漏到线上页面（主站对应 `cfseStripLeftovers()`）。

### 3.4 seed 与版本策略

- 首次 `ensureSchema()` 往 `doc_shells` 写 **3 套 doc + 1 套 index + 1 套 404**（左栏粘性 / 顶栏 + 右侧 TOC / 极简单栏 / docs-wiki 风），CSS 全部内联在壳里 ⇒ **开箱就能访问，不必先配模板**。
- 后台可编辑、可另存为新壳；**不做版本历史**（旧项目的 `style/versions` + `revert` + `tpl.json.bak` 那套维护成本 > 收益，文档站壳只有一个 HTML 文件，想回退直接复制粘贴）。

---

## 4. 路由与渲染（三态）

```
GET /<path>
 ① 命中静态资源（css/js/img/字体/…）→ R2 直出，长缓存 + immutable（这些是不可变文件）
 ② path 落在 src/ 或 以 _ 或 /api /admin 未鉴权 → 404（前缀一刀切，不看扩展名）
 ③ pages/<path>.html 或 pages/<path>/index.html 命中
     → 直出，Cache-Control: public, max-age=60，**绝不 immutable**
     → 0 D1 读、0 渲染（这是绝大多数请求）
 ④ miss → 由 URL 反推 out_prefix + slug
     → D1 一次查：SELECT … FROM documents JOIN doc_routes … WHERE out_prefix=? AND slug=? AND status='published'
       （一次查询同时拿到：存在性 / 权限 / content_format / title / 该用哪个壳）
     → 查不到 → 用 404 壳渲染并返回 status=404
       ★ 不要用 R2 探测兜底：否则每个 404 也要两次往返（爬虫乱试是主要形态）
     → 查到 → R2 读 src/<source_prefix>/<slug>.<md|html>
       → content_format='md'   → marked 解析
         content_format='html' → 原样使用，**不过 marked**
       → 内链改写（§2.1 表）
       → 侧边栏树 / TOC / 上下篇（同库一次取清单，shared 预取）
       → 壳三层解析（§2.2）→ 哨兵填充 → 写回 pages/… → 返回（X-Doc: render）
```

**两条必须写死的规则**

1. **`await` 不计 CPU，渲染计**。免费档是**每请求 CPU ≤ 10ms 硬限**（不是总量计费），而 `await` D1/R2 只是挂起、不吃 CPU。所以「多一次 D1 查询换掉一次 R2 探测」是**净赚** —— 这正是你原设计里「方式 A」胜出的真正原因（比「路由开销比渲染轻」更硬的依据）。
2. **渲染是撞 CPU 上限的唯一风险点**。大文档 + 大壳 + 全库目录树在同一次请求里做有超限风险 ⇒ 主路径靠**后台整集重建**（批量、可断点续做），按需渲染只作**兜底自愈**；目录树必须一次查库 + 请求内共享，不能每篇各算（旧版为此专门做过 `shared` 预取，避免 O(N²)）。

---

## 5. 失效矩阵（这张表比路由图重要）

「命中即直读成品」的前提是成品永远新鲜。本项目历史上所有「改了没变化」的事故都在这一层（`tpl.json` 曾带 `immutable` 导致换风格不生效、「保存 ≠ 应用」、CDN 挡住验证）。新站必须显式定义事件 → 失效范围：

| 事件 | 必须失效 | 要求 |
|---|---|---|
| 上传/更新单篇 | 该篇 `pages/…/<slug>.html` | 删单 key |
| 删除单篇 | 该篇成品 | 删 key，`src/` 与 `files/` 可选一并删（后台选项） |
| **改名（slug 变）** | **旧**成品 + 旧 D1 行 | 最容易漏的一条；旧 key 不删则老 URL 仍可直达 |
| 集合换壳（`doc_routes.shell_doc`） | 该 `out_prefix` 下全部成品 + `index.html` | 必须给一键「重渲染该集」；只改路由不重渲染＝用户看到旧外观 |
| **壳改名** | — | ⚠️ 新风险：`shell_doc` 存的是 id，改 id 会让引用变 NULL 静默回退默认壳 ⇒ 后台**禁止直接改 id**，改名与改 id 分开 |
| 编辑壳内容 | 引用该壳的**所有**集合成品 | 保存时显示「本壳被 N 个集合引用」并可一键全部重渲染 |
| 改 `doc_settings.site`（站点名/页脚/默认壳） | 全站成品 | 提示「需全站重建」并提供入口 |
| 改 `out_prefix` | 旧前缀整片成品 + 新前缀重建 | 旧文件不删则老 URL 仍活（可接受），但要显式告知 |
| 换附件（`files/`） | 仅该附件（文件名带 hash 时无需处理） | 建议附件 key 带内容 hash 前缀 ⇒ 天然幂等 + 长缓存安全（旧项目媒体去重就是这么做的） |

**全局兜底**：所有 `pages/**` 响应一律 `max-age=60`，绝不 `immutable`（CDN 最多滞留 60 秒）；`files/**` 与静态资源才允许长缓存。

---

## 6. 后台（极简）

- 单页面 `/admin/`（原生 JS 无框架），四块：**文件** · **目录/路由** · **壳** · **设置**。
- 认证＝单密码（env secret）→ 签发 HMAC token（沿用 `auth.js` 的 HS256 思路，但**不建 users 表**）；写接口按 IP 内存滑窗限速（isolate 级，0 KV）。

```
GET  /api/bootstrap      token 状态 + 站点概要
POST /api/login          单密码 → token
GET  /api/routes         ·  POST /api/routes/save         （行写，非整文件）
GET  /api/pages?prefix=&q=&page=
POST /api/pages/upsert   原稿写 src/ + 附件写 files/ + 失效成品
POST /api/pages/import   批量（≤500 篇 / ≤25MB，沿用主站已验证上限）
POST /api/pages/rename   ★ 同时删旧成品
POST /api/pages/delete   ·  POST /api/pages/rebuild（整集，带进度）
GET  /api/shells         ·  GET /api/shell?id=  ·  POST /api/shell/save?applyTo=<source_prefix>
POST /api/shell/preview  用该集合真实文档渲染一份预览（不用假数据）
GET  /api/settings       ·  POST /api/settings/save
```

约 15 个端点，全部单表/单桶操作，无跨库事务。

---

## 7. 一致性检查（v1 的三项已消解，剩两项）

因为路由/索引/壳同库（S7），v1 里那三个跨库检查不再需要：

- ~~有文档无路由（跨库漂移）~~ → `LEFT JOIN doc_routes` 一句 SQL 即可，且不可能出现「一半新一半旧」；
- ~~有路由无文档~~ → 同上，降级为 UI 提示；
- ~~R2 `_routes.json` 读到写并发覆盖~~ → 不存在了。

**新增两项**（换到 D1 后才会出现的）：
- **集合引用的壳 id 不存在 / kind 不匹配**（`doc_routes.shell_doc` 指向一个 `kind='index'` 的壳）→ 后台必须红字提示，不能静默回退默认壳（这正是旧项目「改了壳、重建没变化」的同型静默失败）；
- **同一 URL 被两个集合前缀嵌套**（`/a/` 与 `/a/b/`）→ 路由匹配按最长前缀优先，保存时校验并提示。

---

## 8. 主站侧清理（因为不迁数据，无需回退期）

| 位置 | 动作 |
|---|---|
| `worker/src/routes.js` + `routes.template.js`（**两处同改**） | 删文档分支（当前 `:438-488`）、`looksDoc` 预判、`import … from './documents'` |
| `worker/src/admin.js` | 删 **8 个** `/api/documents*` 端点（`:1186/:1203/:1251/:1338/:1360/:1395/:1407/:1422`）、两处 documents DDL（`:211`、`:1181`）、`import … from './documents'`、`PUT /api/settings` 里的 `docRoutes` 校验分支（`:1091-1113`） |
| `worker/src/site.js` | 删 `docRoutes` 透传（`:139-141`）—— 顺带消掉「白名单式构造 ctx，加字段要同步改透传，否则静默丢」这个反复踩的坑 |
| `worker/src/admin.js` `/sync/site-info` | `preserved` 列表里的 `'docRoutes'` 改 `'—'`（保留字段清单要跟着现实，别留幽灵项） |
| `worker/static/admin/index.html` + `app.js` | 删 `#documents` nav/section、`renderDocuments()`（`:2220-2570` 约 350 行）、`NAVS`/别名表里的 `documents` |
| `worker/src/documents.js` | **整文件移到新站**（纯函数可原样复用：`validateDocPath`/`rewriteDocLinks`/`sidebarTreeHtml`/`extractDocTitle`/`extractDocExcerpt`/`outKey`；只有 `renderDocument` 的取壳一段要改成 D1） |
| `docs/document-library.md` | 顶部标注「部署形态与配置存储已由 doc-site-split.md 取代」，保留 §4.3 内链改写、§2.1 表设计等作为实现参考 |
| `tools/import-documents.mjs` | 移入新站仓库（改 base + 端点），或删除由新站后台批量导入替代 |
| 博客正文里的 `/docs/…` 链接 | 扫 `posts.content` 改成新站绝对 URL（§1 唯一内容层耦合） |

旧 D1 的 `documents` 表（4 行）与旧桶里的 `docs/*` 对象**保留不动**（不占额度、不影响新站），确认新站稳定后再清。

---

## 9. 待你定（4 项，定了我就进 P1）

1. **域名**：子域还是独立域？（反代/子路径会把命名空间耦合引回来，不建议）
2. **Worker 项目名**：建议 `cf-docs`（wrangler `name`，影响 `*.yuebinliu.workers.dev` 前缀）
3. **后台口令**：`ADMIN_PASSWORD` 用 `wrangler secret put` 注入 —— 我不替你生成也不写进仓库；你决定是谁来设（你在终端跑一次，或我用 `--force`/管道，但会经过命令行记录，建议你自己跑）
4. **我能否在工作区外建目录**：新仓库要和 `cf` 平级（如 `C:\Users\dog\Documents\cf-docs`），当前 workspace 是 `cf` ⇒ 建外部目录需要额外写权限授权。两个选择：① 授权我创建；② 你手动 `mkdir` + `git init` 后把该目录切成 workspace，我在那边开工

## 10. 实施顺序（不迁数据 ⇒ 比 v1 短）

| 阶段 | 内容 |
|---|---|
| P1 骨架 | 仓库 + `wrangler.toml`（D1 `docs` / R2 `docs` / 无 KV）+ `ensureSchema()` + seed 壳 + 三态路由 + 渲染套壳 + 写回 ⇒ **能独立 deploy 并访问一篇手工放进 R2 的文档** |
| P2 后台 | 单密码认证 + 四块 + §6 端点 |
| P3 主站清理 | §8 全表（新站跑通后立即做，不留回退期） |
| P4 收尾 | AGENTS.md + README + 域名绑定验证 |
