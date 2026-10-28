# CMS 侧代码审查 + 风格指南 / cf-docs 剥离评估

日期：2026-10-19
范围：`worker/src/*`（8,278 行 / 18 文件）、`worker/static/admin`、`docs/*`、`C:/Users/dog/Documents/cf-docs`
性质：**只读审查，未改动任何代码**

> **勘误（同日 02:0x）**：初版把内置文档库判为「休眠死代码」，**该判断错误**。经查线上 D1，`documents` 表有 29 条数据、`docRoutes` 仍有 1 条生效（`abc`），`https://yuebin.uk/abc/` 与 `/abc/README.html` 均 200 且渲染正常。详见 §0 与 §二（已重写）。

---

## 〇、线上与本地代码是否一致

**结论：一致。线上运行的正是当前工作区（含未提交改动）。**

| 核对项 | 证据 | 结果 |
|---|---|---|
| 部署版本 | `fc8a853d-cb53-4fb7-8c5f-99a63db91e13`，创建于 `2026-10-19T01:02:04+08:00` | — |
| 源码时间戳 | `src/*.js` 最新为 `admin.js` @ `01:01:44`，**早于部署 20 秒**；其余文件均早于 10-16 | ✅ 部署后无源码改动 |
| 未提交改动是否上线 | `/styles/` 页面仍原样输出 `{{POST_URL}}` / `{{SIDEBAR_CATEGORIES}}`（哨兵修复生效） | ✅ |
| 静态资源 | `wp-content/themes/index.json` 本地/线上 md5 **完全相同**；`cfse-demo/style.css` md5 **完全相同**；`cfse-demo/tpl.json` 19,756 B 与本地一致 | ✅ |
| 工作区 vs HEAD | `git status`：`admin.js`、`template-engine.js`、`AGENTS.md`、`themes/index.json` 未提交；`cfse-demo/`、`docs/*` 未跟踪 | ⚠️ **HEAD（9b14021）落后于线上** |

### 附带发现：`routes.js` 是生成物

`cf-static-export-pro.php:1666` 会在 WP 侧部署时，用 `worker/src/routes.template.js` **重新生成** `worker/src/routes.js`
（替换 `{{ROUTE_PATTERN}}` / `{{STORAGE_PATTERN}}` / `{{SITE_URL}}` / `{{CFSE_PER_PAGE}}` 四处）。

- 当前两者 `diff` 只有上述 4 处占位符差异，**内容完全同步**；
- 但 `routes.js` 第 9 行注释仍是 `https://doc.yuebin.uk` —— 说明它上次生成于旧域名时代，之后没再跑过插件部署；
- **风险**：任何对 `routes.js` 的手工修改，都会被下一次 WP 插件部署静默覆盖。要么改模板，要么把这条生成链断掉。

---

## 一、风格制作指南在哪

| 项 | 位置 | 说明 |
|---|---|---|
| 作者指南（人读） | `tmp/style-authoring.md` | 370 行，《为本站 CMS 制作风格：作者使用说明》 |
| 契约（机器读） | `docs/skin-contract.json` | 72 个 token（58 占位符 + 14 条件块） |
| 契约（人读） | `docs/skin-contract.md` | 同上，含语义说明 |
| 校验器 | `tools/skin-validate.mjs` | `node tools/skin-validate.mjs <tpl.json>` |
| 旧版契约 | `docs/skin-template-contract.legacy.md` | 历史留档 |

### 指南内容结构（10 节）

数据模型 → 最小风格包 → 渲染模型 → 占位符速查 → 14 个条件块 → sidebar/comments 骨架 →
（文档壳）→ 缓存 → 上传/预览/应用/回滚 API 表 → 生效时机表 → 校验命令 → 常见坑 → 10 步工作流。

### 三处已过时，必须改

1. **第 27 行**：写「风格清单 = `site:ctx.styleIndex`」——**已不存在**。
   现为 `getStyleIndex()`（`site.js:180`）= assets `index.json` + D1 `k='style'.user`，60s isolate 内存缓存，**不进 KV**。
2. **第 243–250 行整节「文档壳 docShell」**：属待剥离的文档库功能，指南里却当作风格包的一部分讲。
   剥离后这一节要整段删除（`admin.js:1486` 把 `docShell` 写进 `wp-content/themes/<id>/tpl.json`，剥离时别把风格包写坏）。
3. **第 267 / 309 / 312 / 345 行**：把「改风格要等 ~60s KV 最终一致」当成主因。
   实际现在是「`site:ctx` 删除传播（≤60s）+ `tpl.json` 15s 内存缓存 + 风格索引 60s 内存缓存」三件事叠加，表述要拆开写，否则读者会误以为风格清单也走 KV。

### 建议

指南本身质量不错（有坑、有工作流、有校验命令），**不建议重写，建议迁移 + 打补丁**：
`tmp/style-authoring.md` → `docs/style-authoring.md`，改上面 3 处，删 docShell 节，
然后原样作为演示站 `guide` 分类的一篇文章发布（Markdown 直接可用，`content_format: 'md'`）。

---

## 二、cf-docs 剥离评估

### 先分清：这里有两套「文档库」

| | A. CMS 内置文档库 | B. 独立 Worker `cf-docs` |
|---|---|---|
| 代码 | `worker/src/documents.js`（466 行）+ `routes.js` 文档分支 + `admin.js` 6 个 `/api/documents*` | `C:/Users/dog/Documents/cf-docs`，17 文件 **5,851 行** |
| 存储 | 博客 D1 的 `documents` 表 + 博客 R2 的 `abc/` `xyz/` 前缀 | 独立 D1 `docs`（`0f839d15-…`）+ 独立 R2 `docs` |
| 入口 | `site.docRoutes`（[{abc,xyz,styleId,docNav,docIndex}]） | `docs.yuebin.uk` |
| 现状 | **在线可用**：29 条文档 / 4 个前缀；`abc` 路由生效，`/abc/` 与 `/abc/README.html` 均 200 | 在线，`200 / X-Doc: render / X-Docs-Time: 624ms` |

### A 的真实状态（更正）

`documents` 表 29 条，全部 `published`、全部 `md`：

| 前缀 | 篇数 | 内容 | 路由 | 可访问 |
|---|---|---|---|---|
| `abc` | 1 | `README.md`（标题「Vultr 专栏」） | ✅ `abc → /abc/`，docIndex=true | ✅ `/abc/`、`/abc/README.html` |
| `host` | 18 | Vultr 系列教程（`vultr/*.md`） | ❌ 已从 docRoutes 移除 | ⚠️ 仍可访问（R2 孤儿） |
| `hosting` | 6 | 阿里云系列（`aliyun/*.md`） | ❌ 已移除 | ⚠️ 仍可访问（R2 孤儿） |
| `docs` | 4 | Xboard / 部署 / **`help/style-authoring.md`** | ❌ 已移除 | ⚠️ 仍可访问（R2 孤儿） |

**所以「内置文档库」功能是在线的、可用的**，我之前说「休眠死代码」是错的。错在两点：

1. 我把「演示站不介绍文档库」误读成「文档库已停用」。你原话是「cf-docs 功能其实不太完善，可以先**不做介绍**」——是内容规划，不是功能下线。
2. 我推断「`docRoutes` 已清空」却没有实际查库。事实是 `docRoutes` 里仍保留 `{abc:'abc', xyz:'abc', docIndex:true}`，说明当初的 settings PUT 并没有带 `docRoutes`（或带了但没生效）。

### 新发现：删路由不删成品 —— ✅ 已修复（版本 `e9b098ff`）

`docRoutes` 移除后，**R2 里的成品页不会自动删除**，静态分支照样直出（修复前的实测）：

```
/host/                                 200  3,755 B  <title>yuebin's blog · 文档目录</title>
/host/vultr/vultr-bbr.html             200 10,485 B
/hosting/aliyun/what-is-aliyun.html    200  8,927 B
/docs/                                 200  1,558 B
/docs/help/style-authoring.html        200 25,356 B  ← 风格指南，仍在线上
```

**修法**（`admin.js`）：

- 新增 `purgeDocArtifacts(env, xyz)`：按 `xyz/` 前缀列举 R2 并批量删除（`delete(string[])` 一次最多 1000 个，分页上限 2 万个），**只删成品，不动原稿与 DB 行**；
- `/api/settings` PUT 在写入后对比 `prev.docRoutes` 与新的 `docRoutes`：**xyz 消失或 abc 变更** ⇒ 清理旧前缀；**同一 xyz 但 styleId/docNav/docIndex 变了** ⇒ 只让目录页成品失效。响应里返回 `purgedArtifacts`；
- `/api/documents/delete` 删完文档后，若该库 `docIndex=true`，顺带删掉 `xyz/index.html`（否则目录页还挂着已删条目），响应返回 `indexPurged`。

> ⚠️ **存量孤儿不会自动消失**：这次修的是「以后删路由会清」，已经存在的 `/host/`、`/hosting/`、`/docs/` 成品仍在。
> 要清掉它们，需要在后台把这三个路由加回来再删掉（触发一次 diff），或我单独跑一次清理。

### 本地批量上传脚本：`tools/import-documents.mjs`

结论：**它和后台走的是同一个端点，不是另一条路径**。

```
tools/import-documents.mjs
  → 遍历目录 → 读 frontmatter → base64
  → 一次性 POST /api/documents/import { abc, files: [...全部...], rebuild: true }
  → 服务端 for 循环逐个 R2 put + D1 upsert，然后在同一个请求里整库重建
```

限制都来自服务端，脚本没有绕开：

| 限制 | 值 | 后果 |
|---|---|---|
| 单请求体 | 25 MB（`admin.js:1300`） | 大目录直接 413 |
| 单次文件数 | 500（`files.slice(0, 500)`） | 超出部分**静默丢弃**，不报错 |
| 处理方式 | 串行 for 循环 | 每篇 2 次子请求（R2 put + D1），500 篇 = 1000 次 |
| 子请求上限 | 免费档 **50** / 付费档 1000 每请求 | 25 篇就摸到免费档上限 |
| 重建 | 同一个请求里再串行渲染全部 | 119 篇 ≈ 再 119 次子请求 |

所以「线上只适合小批量」这个判断是对的，但**本地脚本并没有解决它** —— 它只是省掉了浏览器的内存与 `webkitdirectory` 限制。

真正该做的是让脚本**分批**（每批 15–20 篇，不带 `rebuild`，最后单独调 `/api/documents/rebuild`）。改动很小，但按你的要求先暂缓。

> 顺带回答你上一个问题：你记得的那份风格指南，线上原稿就在文档库里 —— `docs/help/style-authoring.md`，渲染页 `/docs/help/style-authoring.html`（25 KB，标题《为本站 CMS 制作风格：作者使用说明》）。`tmp/style-authoring.md` 是同一份的本地副本（370 行，带 frontmatter）。所以「指南还在不在」的答案不是「在 tmp 里」，而是**线上有一份活的**。

### 保留 A（内置文档库）的实际代价 —— 实测

| 维度 | 实测值 | 评价 |
|---|---|---|
| R2 存储 | 29 篇成品合计 **285.9 KB**（平均 9.9 KB）+ 29 份 md 原稿 ≈ **450 KB** | 免费额度 10 GB 的 0.004%，忽略 |
| D1 存储 | `documents` 29 行，表本身几 KB（整库 1.36 MB） | 忽略 |
| 构建产物 | 整包 366.11 KiB / **gzip 84.46 KiB**；文档库代码约 43.7 KB 源码 ≈ gzip **8–10 KB** | 约占免费档 1 MB 上限的 **1%**，忽略 |
| 每请求 KV 读 | **+0** | `injectHeadInto()`（`routes.js:963`）本来就会为每个单页调 `getSiteContext`；文档分支那次只是提前，memo 命中，不增加 IO |
| 每请求 CPU | 一次 `docRoutes` 数组遍历 + 一个正则预判 | 微秒级，忽略 |
| **文档目录页** | ~~每次访问都 1 次 D1 查询 + 1 次 R2 写入~~ → **已修复** | ✅ 见下 |

**目录页为什么每次都重写 R2（已修复）**：`routes.js:474` 的注释说「优先静态分支已命中（index.html）；miss 则渲染」，
但静态分支（`routes.js:398`）的匹配条件是**路径必须带扩展名**，`/abc/` 永远匹配不上 ⇒
`abc/index.html` 虽然被写进 R2，却从来没有被读过，每次请求都重新 `renderDocIndex()` + 重新 `put()`。

修复前实测（同机、同边缘，各 3 次）：

```
/abc/              1.958s  1.994s  2.005s     ← 每次重渲染 + 重写 R2
/abc/README.html   1.219s  1.181s  1.234s     ← R2 静态直出
```

**修复**（`routes.js` + `routes.template.js` 同步改，否则下次插件部署会被覆盖）：
目录页分支先按 `xyz/index.html` 读一次 R2 成品，命中即直出（带 head 注入），miss 才渲染回写。

修复后实测：

```
/abc/   1.224s  1.321s  1.284s    X-CFSE-Doc: index-static
```

耗时从 ~2.0s 降到 ~1.25s（与单篇静态页持平），**每次访问省掉 1 次 D1 查询 + 1 次 R2 写入**。

**结论：保留它的资源代价基本为零**（不到 1% 的构建体积、0 额外 KV 读、450 KB 存储）。
真正的代价是**维护成本**：后端约 800 行（`documents.js` 466 + 管理端点 ~290 + 路由分支 ~45）、
后台前端 31 处引用，以及上面列的 3 个功能缺口。这是「注意力成本」，不是「资源成本」。

### B 的实测占用

| 项 | 数值 |
|---|---|
| 源码 | 17 文件 / 5,851 行 |
| 内置主题 | `themes/` 349 KB |
| D1 `docs` | 483 KB；**119** documents / **6** routes / **25** shells |
| R2 `docs` | **804** 对象 / **43.3 MB**（对比博客 `wordpress`：820 对象 / 89.5 MB） |
| 24h 访问 | **1,434 次读、0 次写** |
| 内容构成 | `test` 61 / `cloudflare` 55 / `manual` 2 / `book` 1 —— **基本全是测试稿** |
| 单次渲染成本 | 首页 `X-Docs-Time: 624ms`，且**重复请求仍返回 `X-Doc: render`** ⇒ 每次都在渲染，没吃到静态成品 |

最后一条是关键：**它不是"静态托管 + 偶尔渲染"，而是"每请求渲染"**。624ms 的内部耗时 × 全部流量，就是"相当耗费资源"的直接来源。

### 三条路径

| 路径 | 动作 | 代价 | 适合 |
|---|---|---|---|
| **① 下线，保留数据**（推荐） | `wrangler delete` 删 Worker；DNS 去掉 `docs` 记录；D1/R2 **不删**，留作冷备 | 5 分钟，零风险；R2 43 MB 继续计费（可忽略） | 你现在这个状态：不确定还要不要 |
| ② 导出为静态 | 用 `cf-docs/tools/docs.mjs` 把 119 篇导出成静态目录，塞进主站 R2 某个前缀或另建 Pages 项目；然后按 ① 删 Worker | 半天；但 119 篇里 116 篇是测试稿，导出了也没人看 | 里面有真实文档要留 |
| ③ 彻底删除 | 删 Worker + 删 D1 `docs` + 清空 R2 `docs` + 删仓库目录 | 半小时；不可逆 | 确认永远不要了 |

**推荐 ①**，理由：
- 24h 只有 1,434 次读、0 次写 ⇒ 它没有真实使用者；
- 116/119 是 `test`/`cloudflare` 测试稿 ⇒ 没有需要抢救的内容资产；
- 保留 D1/R2 的成本约等于 0，删 Worker 就切断了唯一的资源消耗点（渲染 CPU）；
- 哪天想重启，`wrangler deploy` 就能回来。

### 架构判断的更正：分离本身更干净

初版说过「cf-docs 那才是真正增加复杂度的做法」——**这个判断是错的**，已更正。

把 5,851 行拆开看，绝大多数不是「分离的代价」，而是「内置版根本没有的功能」：

| 模块 | 行数 | 内容 | 内置版有吗 |
|---|---|---|---|
| `api.js` | 1,439 | 同步/配置 API + 批量删除（含 1000/批 + 退化并发）+ 鉴权 | 部分（~290） |
| `admin.js` | 992 | **独立后台 UI** | ❌ 寄生在 CMS 后台 |
| `render.js` | 692 | 渲染 + SEO + JSON-LD + 邻域注入 | 部分（~200） |
| `db.js` | 657 | 独立 schema / 迁移 / 查询 | 部分 |
| `router.js` | 527 | 路由 + 成品读写 | 部分 |
| `indexlist.js` | 249 | 目录索引文件 | ❌ |
| `pdf.js` | 238 | **PDF 支持** | ❌ |
| `toc.js` | 207 | **目录 / 锚点** | ❌ |
| `keys.js` | 206 | 路径 / key 处理 | 有 `r2key.js` 43 行 |
| `links.js` | 193 | 内链改写 | 有（~60） |
| `shell.js` | 124 | 壳占位符填充 | 有（~40） |
| `themes.gen.js` | 114 | 主题生成 | ❌ |
| `themes.js` | 74 | 主题解析 | 部分 |
| `navorder.js` | 42 | 导航排序 | ❌ |
| `seeds.js` | 38 | 种子数据 | ❌ |
| `md.js` | 20 | marked 包装 | 有（24 行） |

真正「重复的基础设施」只有 **auth（~50 行，还是更简单的共享密钥模型）+ md（20 行）+ 少量 keys/shell 逻辑 ≈ 100 行**。
剩下 5,700 行是功能差异，不是分离税。

**分离的真实代价是运维面，不是代码**：2 个 Worker / 2 个 D1 / 2 个 R2 / 2 个域名 / 2 套密钥 / 2 套备份。
这个代价与代码量无关，是「每改一个功能要部署两次」的那种。

**而且 cf-docs 的工具链明显更强**：`tools/docs.mjs` **778 行**，按 6 MB / 120 个文件分批、支持 `dry-run` / `prune` / `pull` / `help`；
而 CMS 的 `tools/import-documents.mjs` 只有 **127 行**，一次性把全部文件塞进一个请求 —— **「只能小批量」说的是后者**。

### 真正的结论：选 A，但别两套并行

| | cf-docs | CMS 内置 |
|---|---|---|
| 内容 | 119 篇，其中 116 篇是测试稿 | 29 篇真实教程（Vultr / 阿里云） |
| 24h 使用 | 1,434 读 / 0 写 | `/abc/` 有真实访问 |
| 工具链 | 778 行 CLI，分批 | 127 行脚本，单请求 |
| 功能 | PDF / TOC / 目录索引 / 独立后台 | 最小可用 |
| 定位 | 专用文档站（独立域名/主题/内容模型） | 博客里顺带放几篇 |

**如果目标是「专用文档站」，A 就是对的** —— 独立域名、独立壳、独立内容模型（collection / sort / PDF）、
独立 CLI、独立发布节奏，这五样内置版都给不了，硬塞只会把 CMS 变成第二个产品。

之前倾向 B 是拿「使用率低」当成了「架构不好」，这是两回事。

**所以决策不是 A vs B，而是「只留一个」**：
用现成的 `docs.mjs` CLI 把 CMS 那 29 篇迁到 cf-docs，然后摘掉内置文档库（-800 行），博客只留文章。

### 但「cf-docs 更重」不是错觉 —— 实测（2026-10-19 02:5x 复核，已更正）

> **勘误**：本节早先写「`/hosting/` 每请求 1–2 次 D1 读」。**这是错的**。
> 成品命中路径**零 SQL** —— 侧栏内容来自 R2 的 `src/<集合>/<目录>/index_list.json`，
> 在内存里切片得到，不碰 D1。`neighborDocs`（那条 O(k) 的 D1 查询）只在**索引缺失时**兜底。

`X-Docs-Time`（Worker 内部耗时，非网络）实测，各 12–15 次取分布：

| 路径 | 走哪条分支 | 内部耗时（实测区间） | 每请求 SQL | 每请求 R2 读 |
|---|---|---|---|---|
| `/pages/x`（404，最早返回） | ③ 硬拒 | **0 ms（9/12）**，其余 405–460 ms | 0（`getSite` 已 memo） | 0 |
| `/`（单集合） | ④ 302 跳转 | **0 ms（10/12）** | 0（memo） | 0 |
| `/hosting/`（索引 memo **前**） | ③ 成品 + 邻域注入 | **435–781 ms，下限 ~435 ms** | **0** | 2（成品 20.6 KB + 索引 2.7 KB） |
| `/hosting/`（索引 memo **后**） | ③ 成品 + 邻域注入 | **205–291 ms（13/15）**，冷 isolate 615–692 ms | **0** | **1** |
| `/hosting/cloudcone.html` | ③ 成品 + 邻域注入 | 465–747 ms | **0** | 2 |
| `/cloudflare/`、`/docs/`、`/computer/` | ④ 目录页**每次重渲染** | **860–1339 ms** | 1 + 列表查询 | 壳/主题 |

> 代理已关闭后复测，数值与之前一致（`X-Docs-Time` 是 Worker 内部耗时，本来就不含本机网络）。
> 另更正一处：索引**不是 176 B** —— 那是 `src/hosting/` 下的**废弃残留**（集合从 `hosting` 改名为
> `out_prefix=hosting / source_prefix=test` 之前留下的空索引，`docs:[]`）。真正生效的是
> `src/test/index_list.json`，**2,671 B**。集合内容也确实集中在 `test/*`：已发布 119 篇中
> `test` 系占 61 篇（`vultr` 18、根 9、`bandwagonhost` 7、`digitalocean` 7、`aliyun` 6、`linode` 6…），
> `cloudflare` 系约 6 篇，`manual` 2 篇，`book` 1 篇 —— 所以 `/cloudflare/`、`/docs/` 这些
> 「空集合根」看起来慢，是**没有 index 文档导致每次都重渲染**，不是数据量大。

三个独立结论：

1. **配置零缓存的底噪已经消除**。加了 isolate 级 memo（`CFG_MEMO_TTL_MS=1800000`，30 分钟）后，
   `/pages/x` 和 `/` 出现大量 **0 ms**，说明 `getSite` / `listRoutes` 不再回源 D1。
   写入路径（新建路由、路由表整体替换、站点设置保存）都会 `clearConfigMemo()` 后**预热一次**，
   所以「改完立刻生效」没有被牺牲。

2. **`/hosting/` 剩下的耗时不是 SQL，是两次串行 R2 读**。顺序无法并行：
   先读成品，才能从 `<!--CFSE-NB-START sp=… d=…-->` 标记里拿到索引 key，再读索引。
   → **已实施**：`readDirIndex` 加 isolate 级 memo（`IDX_MEMO_TTL_MS=43200000`，12 小时），
   写路径 `putDirIndex` / `deleteDirIndex` 就地失效并预热。实测下限 **435 → ~210 ms**，
   R2 读 2 次 → 1 次。**内存代价见下条**。

3. **真正的性能洼地是「没有 index 文档的集合根」**。实测 R2：
   ```
   pages/hosting/index.html     存在   ← 有 src/test/index.md
   pages/cloudflare/index.html  缺失
   pages/docs/index.html        缺失
   pages/computer/index.html    缺失
   ```
   `router.js:148-151` 的自动目录页**故意不写回**（`fresh.dynamic = true`，因为要反映最新列表 + `?page=N`），
   于是这些 URL **每次访问都跑一遍完整渲染**（860–1339 ms），比 `/hosting/` 还慢一倍。
   要么给第 1 页做成品（`?page=1` 与无参同义），要么用 Cache API 缓存 60 s。

**另外：`Cache-Control: public, max-age=1800` 目前没有起到「减少回源」的作用。**
可观测证据：所有响应都没有 `cf-cache-status` / `Age`，且同一 URL 连续 12 次请求的
`X-Docs-Time` 各不相同（418/443/500/539/626/660…）⇒ **Worker 每次都真跑了**。
Cloudflare 默认不缓存 HTML（只按扩展名缓存静态资源），Worker 产出的 HTML 要进边缘缓存，
必须显式配 Cache Rule（Cache Everything）或在 Worker 里用 Cache API。
所以 `PAGES_TTL` 现在只对**浏览器**有效，对回源次数无效。这是比微调注入更大的杠杆。

**分清「CPU」和「墙钟」**：上面这些毫秒绝大部分是**等 I/O**，不是 CPU 计算。
Worker 的 CPU 限额只算计算时间 ⇒ 不构成配额/成本问题，纯粹是**延迟**问题。
（这也回答了「cf-docs 的 CPU 占用是不是更高」——不是 CPU 高，是**回源次数多**：
每次请求都在真跑，而 CMS 有 KV + memo 兜着。）

#### 索引 memo 的内存代价（精确口径，2026-10-19 03:1x 复核）

「12 小时 memo」等价于**把目录索引放进 isolate 内存**，和配置 memo 是同一套模式。会不会爆？不会：

| 口径 | 数值 |
|---|---|
| 已发布文档 / 有索引的目录 | **119 篇 / 64 个目录** |
| 全库 `slug+title+excerpt` 字节数（D1 实测） | **22,155 B**，其中 `excerpt` 占 **16,652 B（75%）** |
| 加上 JSON 键名（约 60 B/篇）+ 每目录头（约 80 B） | 全部索引原始 JSON **约 35–40 KB** |
| 解析成 JS 对象后 | 约 **100 KB**（2–3 倍） |
| 占 128 MB isolate 上限 | **约 0.08%** |
| 代码里的双上限 | `IDX_MEMO_MAX_ITEMS = 256` 条 / `IDX_MEMO_MAX_BYTES = 8 MB`，超了 FIFO 淘汰 |

> 计数口径：`bytes` 取的是 `txt.length`（**字符数**，不是 UTF-8 字节）。中文在 V8 里是双字节串，
> 所以 8 MB 闸门约等于 16 MB 真实字符串内存 —— 依然是 128 MB 的 12%，结论不变。

> 早先这里写「约 130–160 KB」是**按目录均值 × 目录数**估的，把大量只有 100 多字节的空目录也按 2.5 KB 算了，
> 偏高约 4 倍。按 D1 字段字节数逐篇累加才是准的。

**关键点：memo 的条目数 = 被访问过的「目录数」，不是文档数，更不是访问量。**
所以规模增长时它是**台阶式**涨（每多一个目录多一条），不是线性涨。

增长模型（按 186 B/篇 内容 + 60 B/篇 键名 ≈ 250 B/篇，与实测 339 B/篇取保守上限）：

| 文档总量 | 原始 JSON | 解析后内存 | 结论 |
|---|---|---|---|
| 119（当前） | ~40 KB | ~0.1 MB | 0.08% |
| 1,000 | ~340 KB | ~0.9 MB | 0.7% |
| 5,000 | ~1.7 MB | ~4.5 MB | 3.5%，仍在上限内 |
| 10,000 | ~3.4 MB | ~9 MB | **首次触到 8 MB 闸门 ⇒ 开始 FIFO 淘汰** |

「爆掉」在结构上不可能：超限是**淘汰**，不是 OOM；最坏退化回「不缓存」，也就是现在的表现。
真正的 OOM 要占满 128 MB，那需要 ~15 万篇文档且每个目录都被访问过。

**但临界点不在内存，在性价比**：单目录索引大到几百 KB 时，为了取侧栏那 21 条而缓存整份就不划算了。
那时正确的做法不是加大 memo，而是**把 `excerpt` 从侧栏路径里拿掉**（它占 75% 体积，而侧栏只用
`slug/title/rel`）——拆成「完整索引给目录页 + 瘦索引给侧栏」，体积直接降到 1/4。

**关于 A（内置文档库）的建议：不要删，补齐 3 个缺口即可**

既然它在线可用、且 `host`/`hosting` 里躺着一批成体系的真实教程，删掉是净损失。要补的是：

1. **`docNav: 'prevnext'` 声明了但没实现** —— `admin.js:1154` 允许选 `none|sidebar|prevnext`，但 `documents.js:352` 只有 `if (docNav === 'sidebar')`，选 `prevnext` 等于选 `none`（没有导航，也不报错）。要么实现上一篇/下一篇，要么从下拉里去掉。
2. **删路由/删文档不清理成品**（见上「新发现」）—— 这是当前最该修的，否则旧页面永久在线。
3. **批量导入是串行循环**（`admin.js:1314`，最多 500 篇 + 可选整库重建）—— 119 篇量级就会撞 CPU 上限，应改成 `runBatchChunk`（现成的并发池）或拆批。

顺带：`docShell` 被写进风格包的 `tpl.json`（`admin.js:1486`），这个耦合建议解掉，文档壳单独存一张表或单独目录。

---

## 三、CMS 侧代码审查

### P0 · 安全

| # | 位置 | 问题 |
|---|---|---|
| 1 | `admin.js:850` + `routes.js:133` | **一个密钥两种用途**：`AUTH_KEY_SECRET` 既是 JWT 的 HMAC 密钥，又是 WP 插件上传/清缓存用的 `X-Auth-Key`。插件把 key 存在 WP 数据库里、每次请求都带在 header 上 ⇒ 一旦泄漏（插件备份、日志、抓包），攻击者可以直接**伪造 admin JWT**，不需要知道任何密码。应拆成两个 secret。 |
| 2 | `admin.js:850` | `env.AUTH_KEY_SECRET \|\| 'cfse-dev-secret'`：变量缺失时静默使用硬编码默认值 ⇒ 知道源码即可伪造任意 token。生产环境应直接 500。 |
| 3 | `admin.js:826` / `admin.js:2912` / `md.js:8` | **md 正文完全不过滤**：`marked.parse()` 无消毒、无 `sanitize`，md 里的原始 HTML 原样上屏。而 html 格式却过 `scrubHtml()`。这个不对称不成立——要么信任 md 作者，要么都过滤。现在的结果是：任何 `author` 角色账号（含应用密码）都能在公开页写入 `<script>`，构成**存储型 XSS**。 |
| 4 | `admin.js:23-32` | `scrubHtml()` 是正则黑名单，**可绕过**：`<svg/onload=alert(1)>`（`on\w+` 前要求 `\s+`，`/` 不匹配）、`<img src=x onerror=…>` 的变体拼接、`<scr<script>ipt>` 等。只能当"减噪"，不能当安全边界。 |
| 5 | `admin.js:957-964` | **`author` 与 `editor` 权限等价**：门禁只区分 `admin` / 非 admin，`/api/posts/{id}` 的 PUT/DELETE 没有任何归属校验（`posts` 表也没有 author 列）。任何 author 能删改全站文章。要么加 `author` 列做归属，要么把 `author` 降级为只读。 |
| 6 | `auth.js:113` | `verifyJwt` 只校验 `exp`，**不校验 `iat` / `nbf`**，也不校验用户是否仍存在/被禁用（`/api/refresh` 有校验，但 access token 15 分钟内改密码/禁用账号仍然有效）。 |

### P1 · 正确性（断言与实现对不上）

| # | 位置 | 问题 |
|---|---|---|
| 7 | `site.js:163` | `footerHtml` **每次重建 `site:ctx` 都被无条件覆盖**。而 `admin.js:1105` 的注释明确说"合并 prev，避免把 footerHtml 丢掉"——D1 里确实保留了，但渲染时永远用不上。两者必须二选一：要么允许自定义 footer（`ctx.footerHtml = parsed.footerHtml \|\| 默认`），要么删掉这段 merge。 |
| 8 | `admin.js:1109` | `/api/settings` PUT 里 `url: prev.url \|\| site.url \|\| ''` —— **站点 URL 在后台改不了**，只能靠 `/api/setup` 或 WP 侧 `/sync/site-info`。后台 UI 也确实没有这个输入框。这是个隐藏限制，应在设置页明确标注或补上输入框（改 URL 要连带迁移 R2 key，所以"不提供"也许是对的，但要有说明）。 |
| 9 | `cache.js:57` | `edgeSet` 强制 `Math.max(60, ttl)` —— 配 `ttl_list=10` 实际得 60s。注释说"Cache-Control 必须 > 0"，但 1 就够了，60 这个下限是凭空的。 |
| 10 | `cache.js:38,85` | `edgePurge(prefix)` 只在 **4 个固定键**里做 `includes` 匹配。`/api/cache/purge` 传 `home:page:` 时，只有 `home:page:1` 被清，`home:page:2..N` 清不掉；`category:*` / `tag:*` / `search:*` / `author:*` 完全触达不到（注释已承认，但 API 没告诉调用方"你清了个寂寞"）。 |
| 11 | `routes.js:467` | 文档库分支的"廉价预判" `^\/[^/]+\/$` **命中所有 `{slug}/` 形态的单页**，而它在单页分支（813）之前 ⇒ 每个单页请求多一次 `getSiteContext()`。有 30s isolate 内存缓存兜住，冷启动仍是 KV 读。**注**：该分支本身是有效功能（`abc` 路由在用），不能删；要优化就把预判改成"先查 `docRoutes` 的 xyz 首段集合"，而不是对所有单层路径都走一遍。 |
| 12 | `admin.js:1691` | `/api/posts/batch` 用 `SELECT * FROM posts WHERE wp_id IN (…)` **取全行**（含全文 `content`）。285 篇 × 全文 → 单请求内存 + CPU 峰值，这正是之前批量转草稿 500 的直接原因（后来靠前端分 80 个一批绕过）。应改成只取 `wp_id, slug, date, categories, status`。 |

### P2 · 性能

| # | 位置 | 问题 |
|---|---|---|
| 13 | `schema.sql` | `idx_posts_date ON posts(date DESC)` **只有 date**。所有列表查询都是 `WHERE status='published' ORDER BY date DESC` ⇒ 用不上索引（要么扫 `idx_posts_status` 再排序，要么全表扫再排序）。应建 `(status, date DESC)` 复合索引，`idx_posts_status` 可省。 |
| 14 | `database.js` `searchPosts` / `admin.js:1530` | 搜索走 `title/content/excerpt LIKE '%…%'` —— 前置通配符 ⇒ **必然全表扫**。当前 300 篇量级无感，上千篇后会撞 CPU。真要救就上 D1 的 FTS5 虚表，否则明确标注"文章数 >1000 时搜索会慢"。 |
| 15 | `cache.js:44-69` | `edgeSet` 对 HTML 字符串做 `JSON.stringify`，`edgeGet` 再 `JSON.parse`。纯字符串多一层序列化，量小可忽略，但既然只存字符串，不如直接 `new Response(html)`。 |

### P3 · 一致性 / 死代码

| # | 位置 | 问题 |
|---|---|---|
| 16 | `admin.js:549` 与 `template-engine.js:179` | `cfseStripLeftovers` **两份实现**（正则一致，行为一致）。`cfseIfBlock` 也有两份（`admin.js:566` / `comments.js:23`）。改一处漏一处，是典型的静默故障源。应收敛到 `template-engine.js` 单点导出。 |
| 17 | `admin.js:1486` | `docShell` 被写进**风格包**的 `tpl.json`。文档壳和文章风格是两个独立概念，混在一个文件里 ⇒ 改文档壳会写风格包（还带 `.bak` 备份），换风格又可能把文档壳带走。建议把 `docShell` 拆到独立存储（`wp-content/docshells/<id>.html` 或单独表）。 |
| 18 | `preview.js:14,75` | 顶部 `import { tplPreviewHtml } from './admin'` 是静态导入，`galleryIndex` 里又 `await import('./site')` 动态导入"规避循环依赖"。循环依赖其实没解开（`admin → site → ...`），只是把一处挪到运行时。建议把 `tplPreviewHtml` 也移到 `template-engine.js`。 |
| 19 | `admin.js:1414` / `documents.js:358` | `renderDocument` 的 `doc` 参数用 `doc.path` / `doc.format` / `doc.title`，**与博客 `documents` 表结构是吻合的**（`source_prefix/path/format/title/...`），这块之前怀疑的"接口不匹配"**不成立**，是误报，特此更正。真正不匹配的是 cf-docs 的 `source_md/slug/content_format`，那是另一套。 |

### 做得对的地方（保持）

- **assets-first 静态直出**：`tpl.json` 先 `ASSETS.fetch` 再落 R2，部署即生效、不花 R2 读。
- **正文哨兵**（`admin.js:579`）：先占位、所有替换跑完再注入正文——这是 `/styles/` 占位符被吃掉那次的正确修法，注释也写清了原因。
- **R2 key 穿越防护**（`r2key.js`）：段级拒 `.`/`..`/NUL，`r2KeyFromPath` / `r2UrlPath` 编解码分离得很干净。
- **上传魔数嗅探**：不信任扩展名和 `Content-Type`，只认 PNG/JPEG/GIF/WEBP 头 12 字节。
- **应用密码哈希**：高熵随机串用 SHA-256+盐（不硬上 PBKDF2 100k），理由（免费档 CPU）写在注释里，是清醒的取舍。
- **批量任务进度落 D1**（`task:<job>:progress`）：断点续做，且把 KV 写额度彻底移出热路径。
- **评论树渲染**（`comments.js:162`）：注释里"子树同名占位符不能全局替换，否则无限复制导致 500"——踩过的坑写进了代码。

---

## 建议修复顺序

> **已完成（2026-10-19 02:2x，版本 `e9b098ff`）**
> - 文档目录页不再每次重渲染 + 重写 R2（`routes.js` / `routes.template.js`）
> - 删路由 / 删文档时自动清理 R2 成品（`admin.js`：`purgeDocArtifacts` + docRoutes diff + 目录页失效）

1. **拆密钥**（P0-1/2）：`AUTH_KEY_SECRET` 拆成 `JWT_SECRET` + `SYNC_KEY`，去掉硬编码回退 —— 一条改动，收益最大。
2. **md 消毒**（P0-3/4）：`mdToHtml` 出口接一层白名单（DOMPurify 或自写标签白名单），或明确"md 作者必须可信"并收窄角色。
3. **`footerHtml` / `url` 二选一**（P1-7/8）：确认到底要不要自定义页脚，把死代码删掉或修好。
4. **批量取数瘦身 + 复合索引**（P1-12 / P2-13）：两行 SQL，直接解决之前 500 的根因。
5. **本地导入脚本分批**（§二）：`import-documents.mjs` 每批 15–20 篇、不带 `rebuild`，最后单独重建。
6. **清理存量孤儿**：`host` / `hosting` / `docs` 三个前缀的成品仍在（需手动触发或单独跑一次清理）。
7. **收敛重复实现**（P3-16）：`cfseStripLeftovers` / `cfseIfBlock` 各留一份。
