# CF Static Export Pro — 路线图 / 待办（ROADMAP）

> 本文件只记「**还没做的事**」和「**未拍板的方案**」。已完成、已部署的架构与进度以 `AGENTS.md` 为准（那是权威交接文档）；演进叙事见 `EVOLUTION.md`。
> 最后更新：2026-09-13

---

## A. 第三方编辑器对接（Headless 写入口）—— 仅设计，**未拍板、未编码**

**目标**：不进 WP 后台，用本地编辑器（Obsidian 插件 / Ulysses / 手机 App / 脚本）直接读写这个站 —— 价值是「解耦写作入口」。

**当时的推荐组合（2026-09-11 讨论结论，用户未最终确认）**

| 层 | 方案 | 说明 |
|---|---|---|
| 接口协议 | **WordPress REST 写子集 + Application Passwords 认证** | `GET/POST /wp/v2/posts`、`PUT\|PATCH /wp/v2/posts/<id>`、`GET /wp/v2/categories\|tags`、`POST /wp/v2/media`（图 → R2）。第三方编辑器认的就是这套 |
| 内容形态 | md 原稿直存（非标准扩展字段 `content_format:"md"`） | 渲染出口走 Worker 端 `marked`（现有能力，无需新代码） |
| 防覆盖 | **待拍板**（见下） | |
| 配套 | 限流 + 审计日志 + 幂等（重复提交不重复发文）；写后触发重建（列表 / 单页 / RSS） | |

**被否掉的方案**：XML-RPC（`metaWeblog` / `wp.*`）—— 生态萎缩、攻击重灾区、Worker 侧无成熟库。

**防覆盖：两个候选（用户尚未拍板）**
- **候选 1（最省事）**：用 `content_format='md'` 当「线上托管」标记 —— 导出 SQL 改 `ON CONFLICT(wp_id) DO UPDATE … WHERE posts.content_format != 'md'`，并跳过 R2 上传。**前提**：把 Worker 端「新建文章」的默认格式固定为 md（或禁用富文本新建），让「格式」真正等价于「归属」。
- **候选 2（语义最正）**：D1 `posts` 加一列 **`origin`（`'wp'`/`'worker'`）** 或 **`locked`（0/1）**；WP 导出前先读该列，`worker`/`locked` 则**整篇跳过**（不写 D1 + 不上传 R2）。与内容格式解耦，将来扩展双向同步也有地基。

**为什么「格式列」不够（讨论结论）**：`content_format` 回答「内容是什么格式」，防覆盖要回答「权威副本在谁那儿」。
- **漏**：Worker 端用**富文本**新建/编辑的文章是 `content_format='html'`（`admin.js:376`），用 md 当条件保护不到 → WP 一导出就覆盖，且是**静默丢数据**。
- **误伤**：想在 WP 后台改一篇 md 文章时，条件会把它永久挡住（改了不生效）。
- **范围**：WP 的 `save_post` 是**自动**导出（`cf-static-export-pro.php:117` → `do_export_post()`：生成 HTML → 上传 R2 → 写 D1，三件一起做），所以被覆盖的是 **D1 那一行 + R2 单页两样**。

**现状事实（2026-09-13 复核）**
- 覆盖风险**仍然存在**：导出 SQL 是 `INSERT OR REPLACE` 且硬编码 `content_format='html'`（`cf-static-export-pro.php:1110-1111`）。
- Worker 侧 md 副线路**已可用**（`admin.js` 按 `content_format` 存/渲染，`mdToHtml` 走 marked）。
- 「切页签污染 md 原稿」的编辑器 bug 已修（commit `a762a0b`：页签改为「可编辑 + 只读预览」，md 原稿是唯一真源）。

**下一步（等用户点头）**：定候选 1 / 2 → 出落地清单（D1 加列、端点契约、Token 方案、导出端跳过逻辑、薄客户端最小形态）。

---

## B. 单页 permalink 交给本项目自己驱动 —— ✅ **方案 A 已实现（2026-09-13）**，等你定目标结构后即可迁移

**目标**：permalink 结构由 KV（站点基础设置）说了算，不再依赖 WP 的「同步路由规则」把手写 pattern 灌进来。**列表页不受影响** —— 首页/分类/标签/归档的 URL 空间与文章 permalink 无关（由 `category_base`/`tag_base` 决定，默认不变）；受影响的只有「单页地址」和「列表页里指向单页的链接」。

**现行基础（已具备，这是能做的关键）**
- `site.storagePattern` 已经是**数据驱动**的：渲染链接的 `templates.js: cfsePostUrl()` 与算 R2 key 的 `routes.js:217` / `admin.js:275` 用的是**同一个** pattern —— 「URL ≡ R2 key」本来就是现行设定，所以换 permalink **只需换 KV 里那个字符串**，渲染代码不用改。
- pattern 记号支持情况（`cfsePostUrl()` 已经实现了 `{year}{month}{day}{id}{slug}` 的替换）：

| 记号 | 数据来源（`posts` 表列） | 现状 |
|---|---|---|
| `{slug}` | `slug` | ✅ 可用 |
| `{year}` / `{month}` / `{day}` | `date` | ✅ 可用（现成派生） |
| `{id}` | `wp_id` | ✅ 可用 |
| `{category}` | `categories`（JSON `[{name,slug}]`） | ⚠️ **数据在，`cfsePostUrl()` 里 `category:''` 未接**（补一行） |
| `{author}` | 无此列 | ❌ 需加列 + WP 导出带上（建议不做） |

**方案 A（推荐）**：改 permalink 时，把已发布单页在 R2 里的 key **逐篇重算并搬迁**（`copy 新 key` → 切 KV 里的 pattern → `删旧 key`）。之后 URL 严格等于 key —— **请求路径完全不变、无新增解析逻辑、无额外查询**（单页始终是 1 次 R2 读）。
**方案 B（备选）**：不动 R2，在 Worker 里做「URL → 解析字段 → 按旧结构拼 key」的**纯函数映射**。请求性能与 A 基本等同（都是纯计算，微秒级），代价是**常驻的解析逻辑 + 历史多形态 key 兼容**，长期维护面更宽。

**关键事实与坑**
- **R2 是对象存储，没有目录、没有 `mv`**：`post/post1/index.html` 整体只是一个 key 字符串；「移动」= 逐对象 `put(newKey, body)` + `delete(oldKey)` → 289 篇 ≈ **578 次 R2 操作**（免费额度 A 类 100 万次/月，压力可忽略，但不是瞬时完成）。
- Worker 免费档 10ms 限的是 **CPU**；R2 调用的 `await` **不计 CPU**。真正要**分批**的原因是**单请求总时长与可恢复性**，不是 CPU。
- 迁移必须三件套：① **分批 + 幂等**（`POST /api/permalink/migrate`，每批 N 篇、返回进度、可续做）；② 顺序 **先 copy 全量 → 切 pattern → 再删旧 key**（中途无 404 窗口，顶多短时间两份共存）；③ **旧 URL：不做重定向（用户 2026-09-13 决定）** —— 站点新建、SEO 包袱轻，让旧 URL 自然 404、搜索引擎自行替换记录。省掉的复杂度：重定向表、路由里「R2 未命中 → 查 KV」分支、以及**泛化重写规则的误伤风险**（曾讨论过：泛化规则要自己排雷排除 `/category/`、`/tag/`、`/api/`… 漏一个就误伤，还可能让新文章自己重定向自己）。**唯一实际影响**：文章正文/菜单里硬写的站内旧链接会失效 —— 重定向表本来也救不了这类"文本里的旧 URL"，所以代价很小。
- **语义风险**：改 slug / 改发布日期 → 该篇 key 变；用 `{category}` → 改分类 slug 会让**一整批**文章 key 变。所以迁移工具是必需品（顺带可加「R2 孤儿 key 清理」）。
- 落成后 `routes.template.js` + 设置页「🔄 同步路由规则」按钮可**退役**（permalink 不再需要 WP 灌入）—— 与「选项 B：废掉模板机制」是同一个决定。

**✅ 已实现（2026-09-13）** —— commit 见 git log，deploy `1ee77135-8c4a-4192-835d-a5cff52edb1c`：后端 `permalinkToPattern()/patternToPermalink()`（WP 风格 `%xxx%` ↔ `storagePattern {xxx}`，会报告不支持记号如 `%author%`）、`POST /api/permalink/preview`（转换 + 3 篇新旧 URL / R2 key 样例）、`POST /api/permalink/migrate`（**复用批量执行器**：逐篇把 R2 对象按新结构 copy，**旧对象保留**，全部完成后才切 `storagePattern` + 清缓存；进度键 `task:permalink:progress` 可续做）、`POST /api/permalink/cleanup`（删迁移时记录的旧 key）；`postStoragePath()`/`cfsePostUrl()` 均接通 `{category}`（取首个分类 slug）；后台设置页新增「URL 结构（permalink）」卡（当前/新结构 + 预览 + 迁移进度 + 清理旧文件）。**顺序设计成"先 copy 全量 → 再切 pattern"⇒ 中途失败不影响线上旧 URL，天然可回滚。**实测：预览转换正确（`%year%/%monthnum%` → `/2010/03/…`、`{category}` → `/hosting/…`）；小批量真实 copy 一批成功且 pattern 未切换。**待你定：是否迁移、迁到哪个结构**（迁移是写操作，跑之前建议先点预览确认样例）。

**执行顺序（用户 2026-09-13 决定）**：**先做 C 节第 8 条的「Worker 端批量重建的并发 + 断点」**，再实施 permalink 迁移 —— 因为两者的调度骨架**完全一样**（分批取文章 → 并发处理 → 进度可续做），差别只在"每篇做什么"（渲染 vs 按新结构 copy/delete）。建议抽成一个通用执行器（如 `POST /api/batch/run?job=rebuild|migrate&cursor=…&limit=…` → `{done, cursor, processed, errors}`），permalink 迁移直接复用。

> 关于「并发」在 Worker 上的含义：免费档 CPU 限 10ms，但 `await` 网络等待**不计 CPU** → 可以单请求内 `Promise.all` 并行 N 篇，也可以**前端同时发 K 个请求**各跑一段。后者更稳、更适合断点续做。



---

## C. 其它待办

1. **四个 `X-Auth-Key` 写接口无限速**：`/upload`、`/delete`、`/purge-cache`、`/d1-batch` 共用单一 `X-Auth-Key`，无频率限制 / IP 白名单（用户有其它站点「一天大量发布」的场景）。Worker 后台自身的写操作已有内存滑窗限速。
2. **「生成」与「上传」拆开**（用户构想）：目前 `save_post` 自动跑「生成 HTML → 上传 R2 → 写 D1」三件；希望拆成可分别触发，从而支持「WP 只写 D1、生成交给 Worker」的形态，也为将来独立的「WP 静态化插件（内置部署通道）」留接口。
3. **导出剩余耗时**：D1 写入已批量化、全量已并发（`tools/bulk-export-worker.php` + 设置页并发数），剩余是 WP 端渲染 + R2 上传的固定开销。
4. **HTML 稳定化**：同一篇两次渲染的 HTML 差约 15B（疑为动态 id / 时间戳 / `?ver=` 类片段），导致「上传去重」（`cf-static-export-pro.php:963` 上传带 `md5`，Worker 命中回 `skipped`）对 HTML 不命中 → 每次导出都重复上传。
   ⚠️ **文档自相矛盾，待实测定论**：`AGENTS.md:98` 写的是「已核查：独立进程渲染确定，无需改造」，与本条（差 15B）冲突。定论方法：同一篇文章渲染两遍，`diff` 出那 15B 到底来自哪里。
5. **模板细节**：站点名在模板里是硬编码文本（未用 `{{SITE_NAME}}`）；含 `<svg>` 的容器不会被整体包进 `{{#IF_*}}` 块（不影响正确性）。
6. **媒体库大批量删除偏慢**（R2 单删约 1s，分批 20 即可）。
7. **单页重建**：模板改动只影响「新建 / 重建」的单页；需要时在 Worker 后台跑「重建全部单页」。
8. ✅ **已完成（2026-09-13）** —— commit `ae161b3`，deploy Version `a7ba7ac4-8f7b-4b93-b745-8c57ce5f8624`。实现：`cache` 配置加 `task_batch`(1-100, 默认 20) / `task_concurrency`(1-16, 默认 4)；`admin.js` 抽出**通用批量执行器**（`runBatchChunk()` 并发池 + KV 进度键 `task:<job>:progress`，跑完自动清），`rebuild-single` 改为 `GET` 查进度 / `POST` 跑一批（`?reset=1` 从头）；后台设置页加「批量任务」卡，重建按钮支持**从断点继续**。实测：配 3/2 验证配置与 cursor 递增（3→6→9）；恢复 20/4 后 **15 批跑完 292 篇、failed=0**、完成后进度自动清除。**permalink 迁移可直接复用同款执行器（换 job 即可）。** 原描述：`POST /api/posts/rebuild-single?limit=15&offset=N` 由前端**串行**循环调用（`static/admin/app.js:1171` / `:1456`）—— 没有并发；`offset` 可手动指定算是**隐式**断点，但**进度不持久化**（刷新页面即丢）。WP 端已有并发（`export_posts_concurrent`，4 进程），Worker 端没有。
9. ~~**两处 `R2.list` 没做分页**~~ → **用户决定忽略**（风格包数量远达不到 1000，`admin.js:1093` 与 `routes.js:93` 维持单次 list 即可）。备查：R2 单次最多返回 1000 个 key，超过会静默漏列。
10. ✅ **已完成（2026-09-13）** —— commit `9c5f160`，deploy Version `e93ce481-4087-4bab-a480-df8968c37e7a`；线上实测：`/sitemap.xml` → **404**、`/robots.txt` → 200（内容为静态常量）、首页与真实单页 `/testfor-md/` → 200、`/feed/` → 200；KV 里遗留的 `seo:sitemap.xml` 已删除。原决策内容：
    - **`/sitemap.xml` → 路由层直接 404**（`routes.js:192` 那条分支）。**必须截在路由层**：现在是「按需生成 + KV 缓存 3600s」（`routes.js:209` 全表查 `posts`、`:223` 全表查分类标签、`:229` 拼单文件 XML），所以只要爬虫来访问就会触发全量生成 —— 1 万篇时单请求要在免费档 10ms CPU 内读完 1 万行并拼 ~1.5MB 字符串，**大概率直接超限失败**，不只是慢。
    - **`/robots.txt` → 改为纯静态常量**（不查库、不缓存、不再声明 sitemap），建议内容：`User-agent: *` + `Allow: /` + `Disallow: /admin/` + `Disallow: /api/`（这才是 robots 的真正价值：挡爬虫抓后台与 API）。
    - **`purgeLists()` 删掉那两次 `seo:*` delete**（`admin.js:255-256`）：sitemap 已砍、robots 是常量 → 不再需要清；默认配置（`purge_on_publish=false`）下，**发布链路的 KV 操作随之归零**。
    - 依据：sitemap 对「已被收录 + 有 RSS + 内链完整」的站点边际价值很小，且成本随文章数线性上升。

---

## D. 上规模的注意事项（**1 万篇级**）—— 实测结论，供未来设计参考

**先破一个直觉**：**R2 没有「目录太多会变慢」这回事**。R2 是**扁平对象存储**，`post/2024/09/a/index.html` 整体只是一个 key 字符串（`/` 只是普通字符），取对象是**按键查找** → **1 个对象和 1 亿个对象，单次 `GET` 的延迟一样**。唯一有「规模感」的是 `list`（**强制分页，单次上限 1000 个 key**），而正常请求路径**从不 list**。
→ 所以 permalink 结构（`{year}/{month}/{slug}`）的价值在 **URL 语义 / SEO / 目录可读性 / 未来扩展**，**不是性能**：「全塞根目录」与「分成多层前缀」在 R2 上性能完全相同。

**真正要盯的约束（代码/配额口径）**

| 约束 | 实测 | 结论 |
|---|---|---|
| **KV** | 发布一篇 = **2 次 KV `delete`**（`purgeLists()` 删 `seo:sitemap.xml` + `seo:robots.txt`，`admin.js:255-256`），**没有 `put`** | 配额是「写/删各 1000 次/天」→ 每天约 **500 篇**才触限；量级上**不是瓶颈** |
| **D1 分页** | 列表用 `LIMIT ? OFFSET ?`（`database.js:66/93/127`），路由支持任意页（`/page/N`） | **可以直接跳页，不需要一页页翻**；OFFSET 大时会扫过前面的行，但 1 万篇最多扫 ~1 万行 = **毫秒级，不构成瓶颈**（几十万篇才需要 keyset 分页） |
| **批量重建单页** | Worker 端 `rebuild-single` 分批 15/请求、前端**串行**循环；进度不持久化 | 1 万篇 ≈ 670 个请求；**缺并发 + 缺断点续做**（见 C 节第 8 条） |
| **sitemap** | **按需生成**（`routes.js:192`）：KV 未命中就**全表**查 posts + 分类标签，拼单文件 XML 后缓存 3600s | ⚠️ 1 万篇时单请求要读 1 万行并拼 ~1.5MB，**可能撞免费档 10ms CPU 上限直接失败** → **用户已决定砍掉**（见 C 节第 10 条） |
| **R2 `list`** | 媒体库/批处理有 `cursor` 循环 ✅；风格资源与 `/style-list` 没有 ⚠️ | 单次上限 1000 key，超了静默漏列（见 C 节第 9 条） |

**关于 sitemap —— 用户已决定砍掉（2026-09-13）**：对「已被搜索引擎收录 + 有 RSS + 内链完整」的站点，它的边际价值很小；而它的成本**随文章数线性上升**（每次生成 = 全表查询 + 全量拼接），1 万篇时会成为负担。做法见 C 节第 10 条（**关键是截在路由层**，否则爬虫访问仍会触发全量生成）。

---

## E. 近期已完成（从待办移出）

- **旧键 `tpl:sindex-builtin` 已清理**（2026-09-13，commit `30852fa`）：内置风格索引统一走 `tpl:index`，去掉了双写与兼容读取，线上 KV 旧键已删、deploy 验证无回归。
- **`routes.template.js` 与 `routes.js` 的漂移**：本次只对齐了动到的那一行；整体对齐或退役（路线见 B 节末）**仍未做**。
