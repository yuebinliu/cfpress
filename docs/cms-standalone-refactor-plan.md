# CMS 独立化重构方案（2026-10-25 讨论稿）

> 状态：**待拍板，未写一行代码**。
> 前提确认：大魔王已拍板 —— cf-docs 与 cfse-style 暂不处理，本次只做「CMS 独立 + 砍掉内置文档库」。

---

## 〇、三个已确认的前提

1. **cf-docs 不再投入** —— 独立虽已完成但效果不理想（体感偏重、CPU 消耗高于 CMS 本体）。本次不做任何迁移动作。
2. **cfse-style 暂不处理** —— 保持现状独立存在。
3. **本次范围** —— 只做两件事：① 把 Worker CMS 独立成产品；② 砍掉内置文档库功能。
   **不动** WP 插件的同步逻辑主体，**不动** 风格系统，**不动** 静态托管插件。

---

## 一、路径选择：新开平级目录（推荐）

### 1.1 两条路的真实差别

差别只有一个 —— **「真源归谁」**。

| | 路径 A · 原地砍（在 `cf` 仓删） | 路径 B · 新建平级 `cf-cms/` |
|---|---|---|
| 仓库身份 | `cf` 仍是「WP 插件 + Worker + 工具」大杂烩 | `cf-cms` 是纯 CMS 产品 |
| `routes.js` | 仍是生成物 | **变成真源**（删 `routes.template.js`） |
| `sync_routes` 生成器 | 仍住在 WP 插件里 | 迁进 `cf-cms/scripts/`（或直接运行时化） |
| WP 插件角色 | 仍是真源的上游 | 降级为 API 消费方 |
| 生成链能否断 | **不能** | 能 |
| 可独立发布/版本化 | 不能 | 能 |

**关键判断**：你要的「CMS 作为独立产品自主演进」，路径 A 做不到 —— 因为 `routes.js` 一旦是生成物，WP 插件点一次部署就覆盖你的改动。**路径 A 只是把文件挪了个位置，耦合原封不动。**

### 1.2 推荐的分步法（可全程回滚）

老仓 `cf` **一行不动**，新仓另起：

```
第 1 步  建 C:\Users\dog\Documents\cf-cms\
         ├─ 净拷贝 worker/src（19 文件，剔除 documents.js）
         ├─ 净拷贝 worker/static、migrations、scripts
         ├─ 拷贝 schema.sql（剔除 documents 表）
         └─ 建自己的 git 仓库 + README + wrangler.toml.example
         ⇒ 此时不部署、不改行为，纯搬运

第 2 步  在 cf-cms 内砍掉文档库（删 documents.js、8 个 API 端点、
         admin UI、docRoutes 配置、R2 成品清理脚本）
         ⇒ 本地 node --check + 静态引用扫描确认无残留

第 3 步  新建一个 Worker（cf-cms-test），指向同一个 D1/R2，
         做等价性验证：首页 / 分类 / 标签 / 归档 / 单页 / feed / 后台 API
         逐项与线上比对（字节数 + {{ 残留数 + 关键 DOM 断言）

第 4 步  验证通过 → 切正式 Worker；老 Worker 保留一周作为回滚

第 5 步  才动 WP 插件：sync_routes 迁走 / 高级层改为读 CMS 发行包
```

**风险可控的原因**：第 3 步用独立 Worker + 同一份 D1/R2 数据，线上零影响；任何一步不对就回到老仓。

---

## 二、砍文档库：清单与唯一的真坑

### 2.1 代码清单（实测行号，2026-10-25）

| # | 位置 | 动作 | 行数 |
|---|---|---|---|
| 1 | `worker/src/documents.js` | **整文件删除** | 565 |
| 2 | `worker/src/routes.template.js` | 删 `:22` import、`:458-521` 文档分支、`:277` preserved 里的 `docRoutes` | 64 |
| 3 | `worker/src/routes.js` | 同上（若是生成物，改模板后重新生成） | 64 |
| 4 | `worker/src/admin.js` | 删 `:17` import、`:211-212` + `:1289-1291` DDL、8 个端点（`:1294/1311/1359/1446/1476/1511/1523/1538`）、`:374-393` `purgeDocArtifacts`、`:1104`、`:1171-1194`、`:1200-1224`、`:1567-1583` | ~300 |
| 5 | `worker/src/site.js` | 删 `:125` `docRoutes` 透传 | 1 |
| 6 | `worker/static/admin/app.js` | 删 `renderDocuments()`（`:2254-2596`）、`NAVS` 映射 `:2598`、两处视图数组 `:2608` `:2622` | 343 |
| 7 | `worker/static/admin/index.html` | 删 `:69` nav、`:101` section | 2 |
| 8 | `schema.sql` | 删 `:134-146` documents 表 DDL | 13 |
| 9 | `tools/import-documents.mjs` | 删或移入 cf-docs | 127 |
| — | **合计** | | **约 900 行** |

**好消息**：耦合只在**路由层 + 后台层**，数据层只有 1 张独立表 + 1 个 JSON 字段（`settings k='site'.docRoutes`）。核心业务（posts / taxonomies / comments / templates / media）**零改动**。

### 2.2 唯一的真坑：R2 孤儿成品

> **只删代码不清 R2 = 功能下线、内容照旧在线。**

`routes.js:398` 的静态分支正则含 `html?` ⇒ 任何 `.html` 结尾的 R2 对象都会被直接直出。

实测线上现存孤儿（删代码后仍会 200）：
- `/host/vultr/*.html`、`/hosting/aliyun/*.html`（**18 + 6 篇**）
- `/docs/help/style-authoring.html`（4 篇）
- `/abc/README.html`（原稿 `.html` 也会被直出）

⇒ 第 2 步必须**同时**清 R2：`abc/` `host/` `hosting/` `docs/` 四个前缀下的全部 `.html` 成品 + 原稿。
（`.md` 原稿天然不可达，正则不含 `.md`，可留可删。）

### 2.3 三个必须一起做的收尾

| # | 事项 | 为什么 |
|---|---|---|
| 1 | D1 `DROP TABLE documents` + `DROP INDEX idx_documents_prefix` | 29 行数据，按 S12 决策不迁移 |
| 2 | 清 `settings k='site'` 的 `docRoutes` 字段 + 清 KV `site:ctx` | **WP 的 `/sync/site-info` 用 `Object.assign(prev, patch)` 合并写入 ⇒ 这个幽灵字段永远清不掉**，必须主动清 |
| 3 | 扫 `posts.content` 里的 `/docs/…` 链接改绝对 URL | 唯一的内容层耦合（`docs.yuebin.uk` 换成了别的域名） |

---

## 三、生成链怎么断：三个占位符的实测

`routes.template.js` 里 4 个占位符，**功能上只有 2 个**：

| 占位符 | 用在哪 | 现状 | 能否运行时化 |
|---|---|---|---|
| `{{SITE_URL}}` | **只在 `:557` 的注释里** | 纯装饰 | ✅ 直接删掉占位符 |
| `{{CFSE_PER_PAGE}}` | `:26` `const CFSE_PER_PAGE = ...` | 只是**兜底默认值** —— 真实值运行时取 `site.cache.per_page`（`:28-31` `perPageOf(site)`） | ✅ 直接删掉占位符，兜底写死 10 |
| `{{ROUTE_PATTERN}}` | `:828` `path.match(...)` | **真编译期常量** | ⚠️ 见 §3.3 |
| `{{STORAGE_PATTERN}}` | `:832` `basePath` | **真编译期常量** | ✅ **已经能运行时化** |

### 3.1 关键发现：CMS 早就有运行时能力，只是路由层没用上

`admin.js:395-413` 已有完整的运行时转换：

```js
const PERMALINK_MAP = { postname:'slug', year:'year', monthnum:'month', ... };
function permalinkToPattern(structure) { ... }   // '/%postname%/' → '{slug}/index.html'
function patternToPermalink(pattern) { ... }     // 反向
```

`setStoragePattern()`（`admin.js:429`）把 `storagePattern` **写进 D1 `settings`**，并有 `clearSiteCtxMemo()` + 清 `site:ctx` 的失效链。
`admin.js:1992` 还有**完整的 permalink 迁移逻辑**（把已发布单页的 R2 对象按新结构 copy）。

**也就是说：CMS 后台已经支持运行时改 URL 结构了**（D1 里存着 `storagePattern`，`site.js:168` 会读出来）。
只有 `routes.js` 这一层还在用编译期常量 —— 这是**历史遗留，不是能力缺失**。

### 3.2 所以断生成链的方案（两选一）

| 方案 | 做法 | 代价 | 评价 |
|---|---|---|---|
| **① 运行时化**（推荐） | `{{ROUTE_PATTERN}}` / `{{STORAGE_PATTERN}}` 改成从 `site.storagePattern` 推导：正则由 pattern 反推，`basePath` 直接取 `site.storagePattern` | 需要写一个 `patternToRouteRegex()`（约 20 行）；`getSiteContext` 在单页分支多一次读（已有 30s memo + KV） | **真解**。CMS 完全自主，不再需要 WP 生成任何东西 |
| ② 生成器迁仓 | 把 `sync_routes()` 挪到 `cf-cms/scripts/build-routes.mjs`，用 Node 脚本生成 | 生成逻辑仍需 WP 侧配置喂进去（permalink 结构、每页数） | 半吊子 —— 真源还是横跨两边 |

**建议 ①**。理由：`storagePattern` 已经在 D1 里，运行时推导是正路；方案 ② 只是把耦合从 PHP 搬到 Node，没解决「CMS 自主」这个根本问题。

> 顺带修一个现存 bug：`site.js:200` 的 `footerHtml` **无条件覆盖**，导致自定义页脚永远失效（`admin.js:1140` 的注释声称"合并 prev"，与实现不符）。

### 3.3 补充核实（第三轮，2026-10-25 16:40）

**大魔王的判断基本正确，但需一处纠正。**

**✅ 正确的一半：WP 侧确实"日常不做更新"**

实测 `sync_routes()` 的调用点只有 3 处（主仓 `cf-static-export-pro.php`）：

| 调用点 | 触发时机 | 频率 |
|---|---|---|
| `:1659` `handle_sync_routes()` | 后台手动点「同步路由规则」按钮 | 极少 |
| `:1763` `handle_deploy_worker()` | 点「部署 Worker」 | 偶尔 |
| — | **`save_post` 链路不触发** | 0 |

`save_post` 钩子只挂 `export_post`（`:125`）和两个 meta 保存（`:134` `:135`），**没有任何一条会调 `sync_routes`**。
⇒ **日常发文章不会重新生成 routes.js**。只有改 permalink 结构后手动点一次、或点部署时才会重生成。

**⚠️ 需纠正的一半：「WP 侧不更新」≠「WP 侧不参与」**

`routes.js` 里编译进去的 `{{STORAGE_PATTERN}}`，其**数据源仍然是 WP 的 permalink 设置**：

```
WP permalink 设置
  └─► sync_routes() 生成时读取
        └─► 编译进 routes.js 的 const
              └─► 运行时用（永远不变，除非重新部署）
```

**而 WP 每次「同步站点信息到 D1」都会推 `storagePattern` 到 D1**（`class-worker-cms.php:258`，`$fields` 里含 `'storagePattern' => $storage`，来源 `cfse_storage_pattern()`）。
⇒ **同一个语义值（URL 结构），此刻存在于两个地方：D1 里是活的（可改），routes.js 里是死的（编译期）。**
⇒ 这不是"WP 不更新"，是**双源且其中一个是快照**。运行时化要做的事，就是**让路由层只认 D1 那个活源**。

**⇒ 结论：WP 侧「同步路由」按钮和部署时自动生成，都可以删。**
生成链一断，`routes.template.js` 一起删，`routes.js` 变成唯一真源。

### 3.4 「KV 兜底 D1」这层要不要加？—— 不用加

大魔王建议「直接进 KV，兜底进 D1 的 site setting」。实测下来**这层是多余的**：

- `site:ctx`（KV）本来就是 `settings` 的**缓存快照**，已经跑着「30s 内存 memo → KV → D1」三级读取（`site.js:149 getSiteContext`）。
- `storagePattern` **已经在 `site:ctx` 里**（`site.js` 读 `k='site'` 整行，字段全带）。
- ⇒ 路由层只要 `const site = await getSiteContext(env)` 就能同时拿到 `storagePattern` **和** 现成的缓存。
- 再单独开一个 KV 键存正则 = **多一个写入点、多一个失效点、多一处漂移风险**，而只省下一次查表。

**推荐：直接从 `getSiteContext()` 拿 `site.storagePattern`，运行时推导正则，不新增任何存储。**

推导链（两跳，都有现成零件）：
```
site.storagePattern = '{year}/{slug}/index.html'
  └─ patternToPermalink()  （admin.js:415 已有）
       → '/%year%/%postname%/'
            └─ 新增一张 20 行标签对照表（照搬 PHP 侧 permalink_to_route_pattern 的映射）
                 → /^\/(\d{4})\/([^\/]+)\/$/
```
**唯一的新代码就是那张对照表**，其余全是复用。

### 3.5 保留高级功能（部署能力）是否必须保留 routes.js 本地可写 + 推送？

**必须保留「部署」这个能力，但不必保留「本地可写 + 推送 routes.js」这个机制。** 两者要拆开看：

| 能力 | 现在怎么做 | 独立后怎么做 | 结论 |
|---|---|---|---|
| **部署 Worker 代码** | WP 点按钮 → `npx wrangler deploy`（`cfse-sync.php:1240`），部署的是插件内嵌的 `worker/` 副本 | CMS 独立仓自己 `wrangler deploy`；WP 侧高级层改为**下载 CMS 发行包**再部署 | 能力保留，**副本机制取消** |
| **推送 routes.js** | 部署前 `sync_routes()` 重新生成 | **删除** —— 运行时化后没有编译期常量了 | **删除** |
| **同步路由规则按钮** | 后台一个按钮 | **删除** —— 改 URL 结构在 CMS 后台改 D1 即可（`admin.js:1966` 已有 `storagePattern` 写入 + `admin.js:1992` 已有 R2 对象迁移） | **删除** |
| **改 permalink 结构后生效** | 必须点「同步路由」+ 重新部署 Worker | CMS 后台改 → `setStoragePattern()` 写 D1 → 清 `site:ctx` → **立即生效，无需部署** | **反而更强** |

**所以答案是：**
1. **不用保留 routes.js 本地可写**。运行时化后 `routes.js` 里那两行常量直接删，文件变成纯手写真源。
2. **不用保留推送机制**。WP 侧「同步路由」按钮整个删掉。
3. **部署能力要保留，但要换实现**：插件不再内嵌 `worker/` 源码副本，改成「下载 CMS 发行包（zip/tar）→ 解压 → `wrangler deploy`」。这样部署能力还在，源码只有一份。
4. **顺带一提**：`cfse-sync.php:1215` 的 `$required` 列表里**没有 `routes.template.js`** —— 说明插件作者自己也知道模板是可选的；但 `src/routes.js` 在列表里，**这正是必须保留的产物**。改完后这个列表可以简化。

### 3.6 副作用：一个必须注意的时序问题

运行时化后，**改 URL 结构会立即改变路由匹配**，但 R2 里旧结构的成品还在。

- 好消息：`admin.js:1992` 的 permalink 迁移逻辑**已经存在**（copy 旧对象到新 key，旧对象保留）。
- ⚠️ 但它现在是**手动触发**的（`/api/permalink/migrate` 有进度接口）。运行时化后这一步变成**运维刚需**。
- ⇒ 产品化时要把「改结构 → 迁移 R2 对象」做成**带进度提示的一键操作**，否则用户改完结构发现老文章 404。

---

## 四、砍完之后的"纯 CMS"边界

### 4.1 保留

文章 / 分类 / 标签 / 评论 / 媒体 / 模板引擎 / 风格系统 / 多语言（`lang.js` 137 行，刚做完）/ 认证 / 边缘缓存 / WPv2 兼容层。

### 4.2 删掉

`documents.js` 全部、8 个 `/api/documents*` 端点、文档库后台 UI、R2 文档成品、文档壳（`docShell` 字段）。

### 4.3 顺带清掉的"缝合痕迹"

砍文档库时一并处理这些 —— 它们都是同一个病因（同一概念有多份实现）：

| 项 | 位置 | 说明 |
|---|---|---|
| `docNav: 'prevnext'` 声明未实现 | `admin.js:1189` / `documents.js:400` | 选 prevnext 等于选 none 且不报错 → 随文档库一起删 |
| `cfseStripLeftovers` / `cfseIfBlock` 各两份 | `admin.js:577/594` vs `template-engine.js:179/84` | 静默故障源，保留 `template-engine` 那份 |
| 转义函数 5 份 | `admin.js:443` / `comments.js:15` / `documents.js:458` / `site.js:63` / `template-engine.js:39` | 随 documents.js 删掉 1 份，剩 4 份 |
| 密钥校验 8 处重复 | `routes.js` | 抽成一处（顺带修 `timingSafeEqualStr` 未复用） |
| `preview.js` 循环依赖 | `preview.js:14` import `admin.js` | 独立成产品后要解 |

---

## 五、安全基线（对外发布前必须清，与剥离开并行做）

| # | 问题 | 位置 | 修法方向 |
|---|---|---|---|
| 1 | `AUTH_KEY_SECRET` 一钥两用（签 JWT + 校验四写通道） | `admin.js:884` + `routes.js` 8 处 | 拆成两个 secret：`JWT_SECRET` 与 `SYNC_KEY` |
| 2 | 硬编码回退 `'cfse-dev-secret'` | `admin.js:884` | 生产环境缺失直接 500，不给默认 |
| 3 | `/d1-batch` 任意 SQL | `routes.js:355-386` | 表名白名单 + 语句类型限制；批次上限 200 → 50（对齐免费档子请求上限，修数据不一致） |
| 4 | md 正文零消毒（存储型 XSS） | `md.js:8`，4 个出口 | md 出口也过消毒；`scrubHtml` 正则黑名单改白名单（`<svg/onload>` 可绕过） |
| 5 | author/editor 权限等价，`posts` 表无 `author_id` 列 | `admin.js:1698/1728`、`schema.sql` | 加 `author_id` 列 + 归属校验 |

---

## 六、大魔王决策（2026-10-25 17:00 已拍板）

| # | 问题 | 决策 |
|---|---|---|
| 1 | 多租户还是自托管单站 | **自托管单站**。多租户逻辑过于复杂，本项目本就是从复杂简化来的。→ **schema 不加 tenant_id，查询不需防跨租户泄漏** |
| 2 | 新仓命名 | **`cf-cms`**（与 `cf-docs` 平级） |
| 3 | 文档数据与 R2 成品处置 | **见 §6.1 专项说明**（已查实，非"全删"） |
| 4 | 34 个未提交改动 | **按建议：先提交**，让重构 diff 干净 |

### 6.1 关于第 3 点：「29 篇文档和 R2 成品为什么会动到？」

大魔王问得对 —— 我原先的表述（"清 R2 四个前缀"）**过于粗暴，掩盖了真实边界**。已用 Cloudflare API 实测 R2 桶 `wordpress`（846 个对象）+ D1 `documents` 表，精确拆解如下。

**D1 `documents` 表实际 46 行**（不是 29 —— 29 是旧数字）：

| source_prefix | 行数 | 状态 |
|---|---|---|
| `host` | 18 | published / md |
| `cf-docs` | 17 | published / md |
| `hosting` | 6 | published / md |
| `docs` | 4 | published / md |
| `abc` | 1 | published / md |

**R2 桶 846 个对象的精确拆分**：

| 分类 | 数量 | 体积 | 处置 |
|---|---|---|---|
| `wp-content/**`（主题资源 + 风格包） | **736** | 14 MB | **绝不能动** —— CMS 核心资产 |
| 文章单页成品 `*/index.html`（非文档前缀） | **16** | — | **绝不能动** —— CMS 核心资产 |
| 文档库前缀下的 **html 成品** | **47** | 527 KB | **这才是"要动"的** —— 见下 |
| 文档库前缀下的 **md 原稿** | **46** | — | 可留可删（静态正则不含 `.md`，**天然不可达**） |
| `be-local-check/probe.html` | 1 | — | 探针残留，顺手删 |

**为什么只有那 47 个 html 成品"必须动"**：

`routes.js:398` 静态分支的正则是 `\.(css|js|png|...|html?|xml|txt|json|...)$`。
⇒ **凡是 key 以 `.html` 结尾的 R2 对象，删掉文档库代码后仍会被边缘直接直出** —— 功能下线了，页面还在。
⇒ 而 `.md` 原稿不匹配该正则（`html?` 只匹配 html/htm），**删不删都访问不到**，属于纯粹的死重量。

**所以精确的处置清单是**：

| # | 对象 | 数量 | 动作 | 理由 |
|---|---|---|---|---|
| 1 | 文档库 5 前缀下的 **html 成品** | **47** | **必须删**（或改静态正则排除它们） | 删代码后仍会 200 —— 功能下线内容照旧 |
| 2 | 文档库 5 前缀下的 **md 原稿** | 46 | 可留（不可达）或一并删（省 300+ KB） | 死重量，不影响功能 |
| 3 | D1 `documents` 表 | 46 行 | `DROP TABLE` | 表的存在本身不影响运行（代码已删，无人查它）；留着是垃圾 |
| 4 | `settings k='site'.docRoutes` | 1 个 JSON 字段 | **必须主动清** | ⚠️ WP `/sync/site-info` 用 `Object.assign(prev, patch)` 合并写入 ⇒ **这个幽灵字段你永远清不掉**，必须显式清 |

**替代方案（更保守，可选）**：不删 R2，改为**在静态分支加一个排除前缀**。代价是永久留 527 KB 死重量 + 一条额外规则。
⇒ **建议直接删 47 个 html 成品**（先备份到本地，见下），干净利落。

### 6.2 备份方案（回应"先备份还是直接删"）

删除前先把要删的对象**下载到本地**：`C:\Users\dog\Documents\cf-docs-backup\2026-10-25-orphan-r2\`
- 47 个 html 成品（527 KB）
- 46 个 md 原稿
- D1 `documents` 表导出为 SQL

⇒ 成本几十 MB 以内，但**删除动作完全可逆**。这比"留着当备份"好 —— 留着当备份 = 线上继续 200 = 没下线。

---

## 七、下一步执行清单（决策已齐）

### 第 0 步：清理工作区（先做，否则 diff 混浊）
- `cf` 仓：14 个改动 → 提交（含 `worker/wrangler.toml` 的 staged delete 需确认：文件在工作区仍在、`.gitignore` 已含该路径 ⇒ **确认这是有意不入库**）
- `cf-docs` 仓：20 个改动 → 提交（本次不动它，但别留脏状态）

### 第 1 步：建仓 `C:\Users\dog\Documents\cf-cms\`
- 净拷贝 `worker/src`（19 文件 → 剔 `documents.js`）
- 净拷贝 `worker/static`（**排除** `node_modules`、`.wrangler`、`dist`）
- 拷贝 `worker/migrations`、`worker/scripts`、`worker/package.json`、`worker/wrangler.toml.example`
- 拷贝 `schema.sql`（剔 documents 表）
- 新建 git 仓 + README + `.gitignore`（含 `worker/wrangler.toml`）
- ⚠️ **不做任何行为改动** —— 纯搬运

### 第 2 步：砍文档库（约 900 行）
按 §2.1 清单删代码；`node --check` 全过 + 静态扫描无残留引用。

### 第 3 步：清数据
按 §6.1 清单：先备份 → 删 47 个 html 成品 → DROP 表 → 清 `docRoutes` → 清 KV `site:ctx`。

### 第 4 步：等价性验证
新 Worker 指同一 D1/R2 → 逐项比对首页 / 分类 / 标签 / 归档 / 单页 / feed / 后台 API。

### 第 5 步：切流量

### 第 6 步（后续）：断生成链 + 安全基线
按 §3.3–3.6 与 §5。

---

## 八、本方案里我认为最该先动的一件事

**先在 `cf-cms` 建仓 + 净拷贝 + 砍文档库，但不动 WP 插件。**

原因：这一步是纯搬运 + 纯删除，风险接近零，但能立刻验证「独立之后还跑不跑得起来」。
验证通过再谈断生成链（运行时化）和安全基线。**不要一上来就动生成链** —— 那是唯一有回归风险的部分，得在独立的仓里改、在独立的 Worker 里验。
