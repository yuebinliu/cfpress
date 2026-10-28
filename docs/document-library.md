# 文档库（Document Library）设计 —— 存量 MD/HTML 文件作为内容来源

> 状态：**设计稿 v1.1**（2026-09；v1 经第三方评审，采纳 9 处意见修订：问题 1/2/3/4/5 + 小点 1/2/3/4。未写代码）
> 场景：本地有一批说明文档/教程（md 或 html），上传到站点作为**独立于博客文章**的内容来源，
> 访问时套一个干净的「教程壳」直接可读；后续想迁移进整站（posts）也容易。
> 定位：CMS（Worker 侧）为主，WP 只是配套插件、后续再调整；本文只设计 Worker 侧。

---

## 0. 已拍板的决策（会话结论固化）

| # | 决策 | 说明 |
|---|---|---|
| D1 | **独立表**，不并入 posts | 新建 `documents` 表专门处理原稿上传：元数据 + 路径 + 格式 |
| D2 | 原稿存 **R2**（`abc` 目录），元数据存 **D1** | 内容/元数据分离；渲染时从 R2 取原稿 |
| D3 | 独立路由 + 独立壳 | 教程页干干净净（无分类/评论/标签），可自定义壳 |
| D4 | **abc → xyz 映射**，存 site:ctx | `abc`=原稿目录（渲染路径来源），`xyz`=可访问前缀（R2 存储路径来源）；site:ctx 只存映射，壳本体存 D1 styles |
| D5 | **xyz 全局唯一（1:1）** | 一个 xyz 只对应一个 abc；成品 key 永不冲突 |
| D6 | 风格挂**目录级** | 每套文档库独立壳（D1 styles 的 styleId 指向） |
| D7 | 访问走 **xyz**，R2 先命中，miss 反查 abc 取原稿 | URL 和存储都走 xyz，abc 只管原稿 |
| D8 | 成品形态 = **`<路径>.html`（扩展名替换）** | 原稿 `abc/readme.md` → 成品 `xyz/readme.html`；不是 `{slug}/index.html` 目录形态 |
| D9 | **KV 读只在 miss 之后** | 与文章路径一致：R2 命中 0 KV 读；site:ctx 只在动态渲染时读 |
| D10 | **保留前缀硬校验** | xyz 不能是已占用前缀，并屏蔽未来可能用的（见 §5） |
| D11 | HTML 原稿防覆盖 = **源头掐灭 + 提醒** | 不做强制处理：后台提示「有 html 原稿时建议 xyz≠abc」；md 原稿同目录安全 |
| D12 | **内链改写始终做**（无开关） | 同库内链 `.md→.html` + abc→xyz 重映射，输出绝对路径 |
| D13 | **导航开关**：`doc_nav: none/sidebar/prevnext`，默认 **sidebar** | 判定是用户的事（不做自动扫描）；用户倾向侧边栏目录树（教程是树形结构，上下篇顺序会漂移） |
| D14 | **目录页开关**与 abc/xyz/风格/导航放**一条** | 原稿自带导航索引的就不做目录页；没有的勾上 |
| D15 | 上传原稿**自动失效对应成品** | 照搬 `invalidate_md_single_page` 模式；另提供「重建整个文档库」批量任务 |
| D16 | 导航在 **worker 侧**实现 | 查 D1 documents + D1 styles + 渲染；本地只负责上传 |
| D17 | 侧边栏树**烘焙进成品页** | 访问零成本（CDN 命中）；重建时一次查库 O(N)，批量用 shared 预取避免 O(N²) |
| D18 | 后期可迁移进整站 | documents → posts 的导入脚本（10 行级），作为可选工具 |
| D19 | **D1 settings 是权威，site:ctx 是缓存** | docRoutes 权威存 D1 settings（与 site/cache 同模式），site:ctx 是渲染读的快照（评审问题 1） |
| D20 | **documents 不冗余存 xyz** | xyz 只在 docRoutes；documents 只存 `source_prefix`(abc)+`path`。改 xyz 零 D1 代价（评审问题 2） |
| D21 | **abc 原则上稳定，改 abc = 挪原稿 + 批量 UPDATE** | 与「改 xyz 不动数据」互补：xyz 可随意改，abc 尽量别动（评审问题 3） |
| D22 | **内链改写 = 纯路径前缀判断，零 D1 查询** | 不验证目标是否存在（死链本来就 404），纯字符串操作（评审问题 4） |
| D23 | **目录页落 `xyz/index.html` 成品** | 与成品同区，可被静态分支直出（评审问题 5） |

---

## 1. 核心架构（三层分离，与现有 CFSE 同构）

```
┌─────────────┬──────────────────────────┬──────────────────────┐
│ 层          │ 存什么                   │ 存哪                 │
├─────────────┼──────────────────────────┼──────────────────────┤
│ 内容（原稿）│ md / html 原文件          │ R2（abc/<路径>）      │
│ 元数据      │ 路径 / 格式 / 标题 / 状态 │ D1 documents 表      │
│ 壳（风格）  │ 教程页 HTML 模板          │ D1 styles 表（styleId）│
│ 成品        │ 渲染好的单页              │ R2（xyz/<路径>.html） │
└─────────────┴──────────────────────────┴──────────────────────┘
```

- Worker 组装：**R2 取原稿 → marked/html 转正文 → 内链改写 → 套目录级壳 → 写回成品 R2**。
- 与现有博客（posts）链路**完全隔离**：不动 posts 表、不动单页正则、不动评论/分类/标签体系。
- 与现有体系**复用**：marked（md 渲染）、D1 styles（壳存储）、批量任务执行器（重建）、
  `site:ctx`（配置承载）、静态资源分支（成品直出）。

---

## 2. 数据模型

### 2.1 `documents` 表（新增，D1）

```
documents
  id            INTEGER PRIMARY KEY AUTOINCREMENT
  source_prefix TEXT    -- abc：原稿目录（渲染路径来源）
  path          TEXT    -- 相对路径（abc 内唯一），如 guide/sub/readme.md
  format        TEXT    -- 'md' | 'html'（由扩展名判定）
  title         TEXT    -- 标题（md 读 frontmatter title / html 读 <title> / 缺省用文件名）
  excerpt       TEXT    -- 摘要（可选；md 读 frontmatter description，缺省正文截断）
  status        TEXT    -- 'published' | 'draft'
  date          TEXT    -- 发布日期（frontmatter 或 mtime）
  updated_at    TEXT
  UNIQUE(source_prefix, path)
```

- **内容不进 D1**（渲染时从 R2 取原稿）；documents 只是索引 + 元数据。
- `UNIQUE(source_prefix, path)` 天然解决「不同文件夹重名」（`docs/readme.md` 与 `guide/readme.md` 是两行）。
- **不冗余存 `route_prefix`（xyz）**（D20）：xyz 只在 docRoutes 里；documents 只存 `source_prefix`(abc)+`path`。
  侧边栏树/重建按 `source_prefix` 查库；拼 URL 时从 docRoutes 拿 xyz。改 xyz **零 D1 代价**。

### 2.2 `docRoutes` 配置（site:ctx 新增字段）

`site:ctx.docRoutes = [ { abc, xyz, styleId, docNav, docIndex } ]`

| 字段 | 含义 | 约束 |
|---|---|---|
| `abc` | 原稿目录 | 必填；只读用途（改访问路径不动它） |
| `xyz` | 可访问前缀 = 成品 R2 前缀 | 必填；**全局唯一**；保留前缀校验（§5） |
| `styleId` | 壳在 D1 styles 表的 id | 必填；壳本体存 D1，site:ctx 只存指向 |
| `docNav` | `none/sidebar/prevnext` | 默认 `sidebar` |
| `docIndex` | 是否生成目录页 `/xyz/` | 默认按用户勾选 |

- **权威存 D1 settings**（`k='docRoutes'` 或并入 `k='site'`，与 site/cache 同模式）；`site:ctx` 只是渲染读的**快照**（D19）。
  改 docRoutes → 写 D1 + 清 `site:ctx` → 前台下次读时重建（与现有「改站点名清 site:ctx」完全同链路）。
- site.js 是**白名单式构造**（AGENTS.md 已知坑）：加 `docRoutes` 必须同步改 site.js 透传。
- KV 最终一致性 ~60s 的坑沿用现有认知（改完等 60s 再验证）。

### 2.3 壳本体存 D1 styles（复用现表）

- ~~用现有 `styles` 表（id/version/name/tpl_json/updated_at，主键 (id,version)），
  styleId = styles.id，壳 HTML 存 tpl_json（一个单页壳模板，含 `{{CONTENT}}` / `{{DOC_TITLE}}` / `{{DOC_SIDEBAR}}` 等占位符）。~~
- **2026-10-20 修正：壳实际存 R2，不存 D1 styles 表。**

  现行站点的「风格」早已从 D1 `styles` 表迁到 `R2 wp-content/themes/<id>/tpl.json`（+ ASSETS 内置 `index.json`），
  `styles` 表在线上**根本不存在**。而后台 `/api/documents/shell/save` 一直写的是 R2：
  `tpl.json` 里加 `docShell` 字段（保留该风格原有的 6 类文章模板）。两边读写路径不一致的后果是
  `renderDocument()` 查 D1 抛错 → 被 `catch` 吞掉 → `shell = null` → **自定义文档壳永远不生效**，
  恒回退内置 `DEFAULT_DOC_SHELL`（表现为「改了壳没反应」）。

  现实现 `readDocShell(env, id)` 三级回退：**ASSETS → R2 → D1 styles（兼容老站点）**，
  与 `admin.js readStyleTpl()` 同源。壳只认 `docShell` 字段，**不会**回退到文章 `single` 壳
  （占位符体系不同，会把正文吃空）。

  另：`renderDocIndex()`（目录页 `/xyz/`）原先硬编码一套 `max-width:760px` 的内联样式，
  与单页完全两种外观；现改为**与单篇文档共用同一个 docShell**，
  正文换成按一级目录分组的卡片，`DOC_TITLE` 固定为「文档目录」。

- 白拿：版本化 / 回退 / 删除 / 列表 —— 全部现成（走的是风格体系那一套，不再依赖 styles 表）。

### 2.4 文档壳占位符（7 个）

| 占位符 | 值 |
|---|---|
| `{{DOC_TITLE}}` | 当前文档标题（目录页固定「文档目录」） |
| `{{CONTENT}}` | 正文 HTML（目录页为分组卡片） |
| `{{DOC_SIDEBAR}}` | 侧栏目录树（`docNav=sidebar` 时） |
| `{{SITE_NAME}}` / `{{SITE_URL}}` / `{{SITE_DESC}}` | 站点信息 |
| `{{MENU}}` | 主导航（2026-10-20 新增，复用 `cfseMenuHtml`） |

⚠️ 壳里写任何**不在此表**的 `{{XXX}}` 都会被兜底清空。新增占位符时必须同步改 `documents.js` 的
`DOC_KEYS` 数组与 `repVal()`，漏改会被清理逻辑吃掉。

---

## 3. 路径体系

### 3.1 四类 key / URL

| 东西 | 形态 | 例子 |
|---|---|---|
| 原稿 R2 key | `abc/<路径>.<ext>` | `docs/guide/sub/readme.md` |
| 成品 R2 key | `xyz/<路径去扩展名>.html` | `guides/guide/sub/readme.html` |
| 成品 URL | `https://站点/xyz/<路径去扩展名>.html` | `https://站点/guides/guide/sub/readme.html` |
| 目录页 URL | `https://站点/xyz/`（成品 key = `xyz/index.html`，D23） | 按 docIndex 开关 |

### 3.2 映射规则

- `abc` 是原稿目录（用户本地文件夹），**改访问路径不动它**。
- `xyz` 决定 URL 和成品存储；**1:1 映射**（一个 xyz 只对应一个 abc）。
- **xyz 可随意改**（改后清 site:ctx + 重建成品即可，documents 数据零改动，D20）。
- **abc 原则上稳定**（D21）：改 abc = 挪原稿（R2 原稿 key 全变）+ 批量 UPDATE documents.source_prefix。
  后台「改 abc」可提供，但要明确提示这是「迁移原稿」，不是普通配置修改。
- 上传时只需录入：元数据（title/date/…）+ 格式；两套路径自动推导：
  - 原稿 key = `abc/<path>`
  - 成品 key = `xyz/<path 去扩展名>.html`

### 3.3 重名处理

- 不同 abc 下的同名文件（`docs/readme.md`、`guide/readme.md`）→ documents 行不同、成品 key 不同，天然不冲突。
- **同库内** `guide/sub.md` 与 `guide/sub.html` 并存 → 成品 key 都指向 `xyz/guide/sub.html` → **冲突**。
  导入时校验：**同一 abc 内，去扩展名后同名（.md 与 .html）不允许并存**（源头掐灭）。

### 3.4 HTML 原稿与同目录

- **md 原稿同目录（abc=xyz）完全安全**：原稿 `abc/readme.md`，成品 `abc/readme.html`，key 不同。
- **html 原稿同目录（abc=xyz）会冲突/覆盖**：原稿 `abc/readme.html` 与成品 `abc/readme.html` 是同一个 key。
  → 后台**提醒**「有 html 原稿时建议 xyz≠abc」（D11，源头掐灭，不做强制处理）。

---

## 4. 访问与渲染流程

### 4.1 路由顺序（插入点：静态资源分支之后）

现有 routes.js 顺序（关键）：`/api/*`、`/wp-json/*` → 静态资源（`\.(html?|css|js|...)`，**miss 不 return，继续往下**）→ `/feed` → `/category/` → `/tag/` → 单页正则 → 404。

文档成品 URL 带 `.html`，**天然被静态分支先拦**：

```
请求 /xyz/guide/readme.html
  ├─ ① 静态资源分支：R2 试 xyz/guide/readme.html
  │     ├─ 命中 → 直出成品（已套壳，Cache-Control: max-age=86400）   ← 0 KV / 0 D1
  │     └─ miss  → 继续往下（不 return）
  ├─ ② 文档路由（新增，静态分支之后）：
  │     ├─ 匹配 /^\/[^/]+\/.+\.html$/（<xyz>/<多级路径>.html）
  │     ├─ 读 site:ctx → docRoutes 找 xyz          ← 这里才读 KV（D9）
  │     ├─ 命中映射 → 反查 abc，构造原稿 key → 渲染 → 写回成品 → 返回
  │     └─ 无映射 → 404
  └─ ③ 其它分支照旧
```

- 请求 `/xyz/`（目录页，docIndex=true 时）：文档路由单独处理 → 查 documents 渲染列表（走缓存）。
- 请求 `/xyz/<路径>.md`（原稿）：静态正则**无 .md** → 不被拦 → 文档路由不匹配（.md 不在模式）→ 404。
  **md 原稿天然私有**；html 原稿（.html 在静态正则里）若 abc 下没有成品 key 会直出原文件 —— 提醒用户（§3.4）。

### 4.2 渲染流程（miss 时，worker 侧，D16）

```
1. 读 site:ctx.docRoutes → 匹配 xyz → {abc, styleId, docNav}
2. 原稿 key = abc/<path> → R2.get()
3. format='md' → marked 转 HTML；'html' → 原样（可选 HTMLRewriter 清理冗余标签）
4. 内链改写（§4.3，始终做，D12）
5. docNav='sidebar' → 查 documents 生成侧边栏树（§4.4）
   docNav='prevnext' → 查前后篇（按 date 或路径序）
   docNav='none'     → 不生成
   ※ xyz 直接用第 1 步已读的 docRoutes 里的值（D20：documents 不存 xyz，拼 URL 全靠这里）
6. 取壳：D1 styles 按 styleId 取最新版 tpl_json
7. 填占位符：{{CONTENT}} {{DOC_TITLE}} {{DOC_SIDEBAR}} {{SITE_URL}} {{SITE_NAME}} ...（沿用 cfseReplace/cfseIfBlock/cfseStripLeftovers）
8. 写回 R2 成品 key（xyz/<path 去扩展名>.html）
9. 返回
```

### 4.3 内链改写（始终做）

原稿里指向**同库文件**的相对链接，渲染时改写（否则点过去是 404 或原稿）：

```
原稿 abc/sub/a.md 里的内链：
  ./b.md            → 解析为 abc/sub/b.md（落在 abc 前缀内）→ /xyz/sub/b.html
  ../guide/c.md     → 解析为 abc/guide/c.md                 → /xyz/guide/c.html
  readme.md#intro   → 锚点保留                               → /xyz/readme.html#intro
  外链 https://… / mailto: → 不动
  站内绝对 /abc/x.md → 若命中映射                            → /xyz/x.html
```

- **判定「同库」= 纯路径前缀判断，零 D1 查询**（D22）：解析相对路径 → 得到 `abc/...`；
  判断是否以**该库 abc 前缀开头** → 是则改写，否则不动。**不查 documents 表验证目标是否存在**
  （指向不存在的文件本来就是死链，改写与否都 404，无所谓）。
- **输出绝对路径**（`/xyz/...`），不做相对路径重算 —— abc→xyz 映射可能改变目录层级关系，绝对路径最稳。
- 自带导航（原稿写死的目录链接）也走同一套改写，天然指向成品页。
- 图片/附件相对路径（`./img/a.png`）也走同一套「落 abc 前缀 → 改写」判断（小点 4，见 §11）。

### 4.4 侧边栏目录树（docNav=sidebar）

- 查询：`SELECT path, title FROM documents WHERE source_prefix=abc AND status='published' ORDER BY path`
- 按路径层级分组生成 `<ul>` 树；每项链接 = `/xyz/<path 去扩展名>.html`（xyz 从 docRoutes 拿，D20）；当前页高亮。
- **烘焙进成品页**（壳的 `{{DOC_SIDEBAR}}`）→ 访问零成本（D17）。
- 批量重建时**一次预取该库全部文件**（shared 模式，与 `writeSinglePage` 的 shared 预取同构），避免 O(N²)。

---

## 5. 校验规则（后台保存 / 导入时）

1. **xyz 全局唯一**：不能与已有 docRoutes 的 xyz 重复；不能与另一库的 abc 指向同一 R2 前缀（防成品 key 冲突）。
2. **保留前缀清单**（硬校验，拒绝保存）：
   - 已占用（必须屏蔽）：`api` `admin` `feed` `category` `tag` `wp-json` `wp-content` `wp-includes`
     `preview` `sync` `comments` `upload` `delete` `d1-batch` `style-list` `purge-cache` `page`
     `robots.txt` `favicon.ico` `favicon.png` `sitemap.xml`
   - 未来预留（仅屏蔽确会扩展的资源前缀）：`assets` `media` `static`
   - **不屏蔽 `doc`/`docs`/`guide`/`manual` 等**——这是文档库的核心场景词，屏蔽它们自相矛盾
     （小点 1，已采纳）。
3. **同库内去扩展名同名冲突**：同一 abc 下 `x.md` 与 `x.html` 不允许并存（成品 key 冲突）。
4. **原稿路径最后一段不能是 `index`**（`abc/index.md` / `abc/sub/index.html` 拒绝）：否则成品 key = `xyz/index.html` 与目录页撞（D23）。目录页占用了 `index`，原稿路径必须避开。
5. **HTML 原稿提醒**：库内有 html 原稿且 xyz=abc 时，后台提示「建议 xyz≠abc」（不强制）。

---

## 6. 上传 / 失效 / 重建

### 6.1 导入通道（批量 = 本地脚本 + 整库重建；单篇 = 后台上传）

**两条通道，明确分工**：

- **批量导入（初始/增量大批）**：本地 Node 脚本（遍历快、不占 Worker 配额）。脚本**只负责写原稿 + 元数据**，不逐篇渲染；
  全部写完后调**整库重建**（§6.3）一次生成全部成品。Worker 端点（`POST /api/documents/import`）留作后续可选项。
- **单篇上传/更新（后台）**：后台上传走 Worker 端点（§7.2），逐篇写原稿 + 元数据 + **单篇失效**对应成品（§6.2）。

```
本地脚本遍历目录：
  1. 每个文件：读 frontmatter（title/date/description/categories?）或默认（文件名=title，mtime=date）
  2. 格式判定：.md → 'md'；.html → 'html'
  3. R2.put('abc/<path>', 原稿)
  4. D1 INSERT documents（source_prefix=abc, path, format, title, ...）   ← 无 route_prefix（D20）
  5. 全部写完后 → 触发该库**整库重建**（§6.3）
```

- 上传时校验 §5 全部规则（含新增的「路径末段不能是 index」）。
- **不逐篇渲染**：批量场景下逐篇渲染会重复查库/重复取壳，浪费；整库重建一次预取（§4.4 shared 模式）更省。

### 6.2 单篇失效（后台上传/更新单篇时）

- 更新单篇原稿后，删除对应成品 key（`xyz/<path 去扩展名>.html`）→ 下次访问 miss → 用新原稿重渲染（D15）。
- 照搬 `invalidate_md_single_page` 模式（现有 md 文章更新后清 R2 单页的逻辑）。
- **批量导入不用单篇失效**——走 6.1 的整库重建，一步到位。

### 6.3 重建整个文档库（批量任务）

- 复用现有批量任务执行器（`task:<job>:progress` 断点续做）。
- 每库一次任务：预取该库文件列表 → 逐篇渲染写回成品 → 进度持久化。
- **批量导入（6.1）与「整库重建」按钮（7.2）都调它**。

---

## 7. 后台 UI（新增「文档库」管理页）

### 7.1 库配置行（一条 = 一行，D14）

```
[abc 输入] --- [xyz 输入] --- [风格下拉] --- [导航开关] --- [目录页开关] --- [保存/删除]
```

- **abc**：原稿目录（只读用途）
- **xyz**：可访问前缀（全局唯一，保留前缀校验）
- **风格下拉**：D1 styles 里的壳（目录级，D6）
- **导航开关**：`无 / 侧边栏目录树 / 上一篇·下一篇`（默认侧边栏；判定交给用户，D13）
- **目录页开关**：`/xyz/` 是否生成列表页（D14）
- 保存 → **写 D1 settings（docRoutes 权威）** + 清 site:ctx（D19；与改站点名同链路）
- **改 abc**：提供但标记为「迁移原稿」（挪 R2 原稿 + 批量 UPDATE documents.source_prefix，D21）

### 7.2 文档管理

- 列出该库所有文档（documents 表，分页）
- **单篇上传/更新**：走 Worker 端点（逐篇写原稿 + 元数据 + 单篇失效，§6.2）
- **整库重建按钮**：调 §6.3 批量任务（批量导入后也用它）
- 导入脚本入口（引导用本地脚本，§6.1）

---

## 8. 成本分析

| 场景 | KV | D1 | R2 |
|---|---|---|---|
| 成品命中（静态分支直出 / CDN 命中） | **0** | **0** | 1 读 |
| 成品 miss → 渲染 | 1 读（site:ctx） | 1~2 查（documents 行 + 侧边栏树） | 1 读（原稿）+ 1 写（回填成品） |
| 目录页 `/xyz/`（docIndex=true，命中） | 0 | 0（或 KV 缓存命中） | 1 读（xyz/index.html） |
| 目录页 `/xyz/`（miss） | 1 读（site:ctx） | 1 查（documents 全库列表） | 1 写（回填 xyz/index.html） |
| 上传一篇 | 0 | 1 写 | 1 写（原稿）+ 失效删 1 |
| 重建整库（N 篇） | 少量（进度） | 1 次全库查（预取）+ N 渲染 | N 写 |

- **KV 读只在 miss 之后**（D9）：文档路径与文章路径成本一致，命中 0 KV。
- 侧边栏树烘焙进成品 → 访问零成本（D17）。
- md 原稿不公开（静态正则无 .md）；html 原稿按 §3.4 提醒。
- 配额结论：与现有系统同量级，无新增风险点。

---

## 9. 与现有系统边界

| 现有关卡 | 文档库是否触碰 |
|---|---|
| posts 表 / 单页渲染 / 上下篇 | **不碰**（独立 documents 表） |
| 分类 / 标签 / 评论 / 搜索 | **不碰**（教程页无这些） |
| 列表页缓存 / 侧边栏 / RSS | **不碰**（文档不进博客列表） |
| site:ctx | **加字段**（docRoutes；同步改 site.js 透传） |
| D1 styles | **复用**（壳存储 + 版本化） |
| 静态资源分支 | **复用**（成品直出） |
| 批量任务执行器 | **复用**（重建文档库） |
| WP 侧 | 暂不动（后续需要再加） |

---

## 10. 实施阶段（建议顺序）

1. **阶段一：数据与配置** —— documents 表（DDL；**三处 schema 同步** = `worker/src/database.js` 的 ensureCoreSchema / WP 插件 `ensure_d1_schema` / `worker/schema.sql`，按 AGENTS.md 既有习惯三处同改）、docRoutes 进 site:ctx（site.js 透传 + PUT /api/settings 支持）、后台「文档库」管理页（库配置行 + 校验规则 §5）。
2. **阶段二：渲染主链路** —— 文档路由（静态分支后）、原稿取回 + marked/html、内链改写、壳渲染（D1 styles）、成品写回、目录页（docIndex）。
3. **阶段三：上传与失效** —— 本地导入脚本（批量遍历 + 校验 + R2 原稿 + D1 元数据）、上传自动失效成品、重建整库批量任务。
4. **阶段四（可选）** —— Worker 批量导入端点、documents → posts 迁移工具（D18）、html 原稿 HTMLRewriter 清理。

---

## 11. 开放问题（实现时再定，不阻塞设计）

- 目录页 `/xyz/` 的缓存策略（KV TTL 或纯 CDN）—— 与列表页同构，先按 CDN 处理（成本已列 §8）。
- `prevnext` 的排序基准（date vs 路径序）—— 用户倾向 sidebar，prevnext 实现时再定。
- **图片/附件方向（小点 4，先定原则）**：**图片与 md 同区上传 R2**（`abc/<path>` 原样放），渲染时走「落 abc 前缀 → 改写」判断（§4.3），
  把 `./img/a.png` 改写为 `/xyz/img/a.png`（成品同区取）。不做「搬到媒体库 /wp-content/uploads/」——那是博客的链路，文档库保持独立。
  实现阶段若发现图片改写成本高，可退回「文档库纯文本，图片外部托管」。
