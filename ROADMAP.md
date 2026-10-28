# CF Static Export Pro — 路线图 / 待办（ROADMAP）

> 本文件只记「**还没做的事**」和「**未拍板的方案**」。已完成、已部署的架构与进度以 `AGENTS.md` 为准（那是权威交接文档）；演进叙事见 `EVOLUTION.md`。
> 最后更新：2026-09-14

---

## A. 第三方编辑器对接（Headless 写入口）—— ✅ **已拍板：要做**（防覆盖用候选 1），按需排期

**目标**：不进 WP 后台，用本地编辑器（Obsidian 插件 / Ulysses / 手机 App / 脚本）直接读写这个站 —— 价值是「解耦写作入口」。

**当时的推荐组合（2026-09-11 讨论结论，用户未最终确认）**

| 层 | 方案 | 说明 |
|---|---|---|
| 接口协议 | **WordPress REST 写子集 + Application Passwords 认证** | `GET/POST /wp/v2/posts`、`PUT\|PATCH /wp/v2/posts/<id>`、`GET /wp/v2/categories\|tags`、`POST /wp/v2/media`（图 → R2）。第三方编辑器认的就是这套 |
| 内容形态 | md 原稿直存（非标准扩展字段 `content_format:"md"`） | 渲染出口走 Worker 端 `marked`（现有能力，无需新代码） |
| 防覆盖 | **待拍板**（见下） | |
| 配套 | 限流 + 审计日志 + 幂等（重复提交不重复发文）；写后触发重建（列表 / 单页 / RSS） | |

**被否掉的方案**：XML-RPC（`metaWeblog` / `wp.*`）—— 生态萎缩、攻击重灾区、Worker 侧无成熟库。

**防覆盖：✅ 已定 = 候选 1**（`content_format='md'` 当「线上托管」标记），理由见下。

**为什么候选 2 也不行（2026-09-13 讨论结论，用户提出的关键质疑）**
- WP 编辑器（**装了 md 插件也一样**）负责把 md **渲染成 HTML** 再存 `post_content`（插件缓存的原稿在插件自己的表里，不进 `post_content`）；我们导出取的是 `$post->post_content`，且 SQL 里 `content_format` **硬编码 `'html'`**（`cf-static-export-pro.php:1111`）。
  ⇒ **一篇 md 文章只要流经 WP 编辑一次，就永久降级为 html**（md 原稿与其格式标记一起丢），**候选 1 和候选 2 都救不了**。
  ⇒ 所以候选 2 的"解除锁定"出口**本质是单向的**（解除 = 放弃 md 格式），并不是"两全"。
  ⇒ 推论：**md 文档必须永不流经 WP** —— 这正是候选 1 的语义。
- "Worker 端编辑原本是 html 的文章会被覆盖" 这条曾被当作候选 1 的漏洞，**经用户反驳成立为次要场景**：html 文章的家在 WP（有修订/插件），在 Worker 端改它本就是次要路径。处理方式是**在编辑器上给忠告提示**（"本文档由 WP 托管，此处改动会在下次 WP 导出时被覆盖"），而不是上锁、也不必加列。

**实现要点（候选 1）**：导出 SQL 从 `INSERT OR REPLACE` 改成真 upsert（`ON CONFLICT(wp_id) DO UPDATE SET … WHERE posts.content_format != 'md'`）+ **md 文章跳过 R2 上传**；Worker 端"新建文章"默认 md（或提示）。

**候选 2（已排除）**：D1 加 `origin`/`locked` 列
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

**下一步**：出「最小写入口」清单 —— md 直存（已有）+ 候选 1 防覆盖（1 个 SQL 条件 + 1 处跳过上传）+ Application Passwords / Token 认证 + 4 个端点（`GET/POST /wp/v2/posts`、`PUT|PATCH /wp/v2/posts/<id>`、`GET /wp/v2/categories|tags`、`POST /wp/v2/media`）+ 薄客户端最小形态。
**可只做读**：先支持第三方工具"把站点当知识库读"（成本几乎为零），写入口之后再补。

### A.1 最小写入口清单（2026-09-14 代码调研结论）

**现状事实（已复核，带行号）**

| 事实 | 位置 |
|---|---|
| **无任何 `/wp/v2/*` 兼容端点**（全仓无 `register_rest_route`） | `worker/src/routes.js` 分发表 `:70-470`；PHP 侧无命中 |
| **无 Basic Auth / 应用密码**（只有 JWT + PBKDF2） | `worker/src/auth.js`：`verifyPassword :33`、`hashPassword :52`、`signJwt/verifyJwt :91/:103` |
| md 直存 + 渲染出口**已具备**（本项无需新代码） | `admin.js:478-480`（`content_format`）、`src/md.js`（marked） |
| 现有可复用写通道 | JWT 版 `/api/*`（`admin.js`）；X-Auth-Key 五接口（`/purge-cache :103`、`/style-list :123`、`builtin-sync :139`、`/d1-batch :159`、`/upload :193`、`/delete :196`，明文串比较）；媒体 `POST /api/media`（`admin.js:1509`，魔数校验 + R2 key 规则） |
| **覆盖风险实存** | `cf-static-export-pro.php:1109-1124`：`INSERT OR REPLACE INTO posts …` 且 `content_format` 硬编码 `'html'`、`status` 硬编码 `'published'` |
| 无审计表 / 无写幂等（仅 R2 上传有 md5 去重 `routes.js:609-627`） | 建表 `cf-static-export-pro.php:1077-1088`；`GET /api/audit` 是内容质检、非操作日志 |

**落点：Worker 端（推荐）** —— 第三方编辑器在外网，内网 WP（`192.168.2.2`）不可达；且「md 文章的家在 Worker」与候选 1 语义一致（md 永不流经 WP）。内网 WP 端可后续按需补，共用同一契约。

**端点契约（对第三方暴露 WP REST 形状，最小 4+1 个）**

| 端点 | 语义 | 复用实现（不新写业务逻辑） |
|---|---|---|
| `GET /wp/v2/posts` | 列表（`page/per_page/status/search/categories/tags`） | `admin.js:700` 列表 SQL → 映射 WP 响应形状 |
| `GET /wp/v2/posts/<id>` | 单篇（`content.rendered` 由 `mdToHtml` 出） | `admin.js:742` |
| `POST /wp/v2/posts` | 新建（`title/content/status/slug/categories/tags/excerpt/date`） | `admin.js:757` + `buildPostPayload :475` |
| `PUT\|PATCH /wp/v2/posts/<id>` | 更新 | `admin.js:791` |
| `GET /wp/v2/categories\|tags` | 分类/标签 | `admin.js:1451` |
| （媒体最小形态）`POST /wp/v2/media` | 上传图片 | 复用 `handleUpload :609` 或 `POST /api/media :1509` |

**认证：Basic Auth（用户名 + 应用密码）** —— 第三方客户端只认这套
- Worker 新增 `app_passwords(id, user_id, name, hash, created_at, last_used_at, revoked)`，哈希复用 `auth.js:52/33`（PBKDF2-SHA256）；
- 生成入口：后台用户管理「生成应用密码」（明文一次性显示，可撤销）；
- 中间件：`Authorization: Basic` → 校验 → 注入 `{uid, role}` 走现有权限判定；**不认 JWT**（第三方无 JWT 流程）。
- 备选（不推荐）：WP Application Passwords（只有内网可达）、直接复用 JWT（第三方不认）。

**防覆盖（候选 1，WP 侧 2 处改动）**
1. 导出 SQL（`cf-static-export-pro.php:1109-1124`）改真 upsert：`ON CONFLICT(wp_id) DO UPDATE SET … WHERE posts.content_format != 'md'`；
2. `do_export_post()`（`:581`）对 md 文章**跳过 R2 上传**（md 单页由 Worker `writeSinglePage()` 负责）。
- 配套（已有共识）：编辑器对 `content_format='html'` 的文档给「本文档由 WP 托管」忠告，不上锁、不加列。

**配套设施**
- **限速（公网写入口必须有）**：按应用密码 id / IP 的内存滑窗，复用 `routes.js:34-47` `xauthRateAllowed()` 形态（0 KV）；
- 审计：新表 `audit_log(ts,user_id,action,path,result)`，或最小档先 `console.log` 后置；
- 幂等：`POST` 支持 `X-Idempotency-Key`（或 slug 二次唯一校验）—— 现状重复提交会新增一篇（`wp_id` 由 `Date.now()` 生成，`admin.js:771`）。

**实施顺序（建议）**
1. **档 0 只读**（成本近零）：`GET` 三端点 + Basic Auth 应用密码 —— 可立刻用于「把站点当知识库读」；
2. **档 1 写**：`POST/PUT` + md 直存（已具备）+ 媒体；
3. **档 2 防覆盖**：WP 两处改动 —— 这是「md 不被 WP 导出覆盖」的硬保证；
4. **档 3 薄客户端**：Obsidian 插件 / 脚本最小形态。

**未决（需拍板）**：① 落点（Worker / 内网 WP / 两端）；② 首批范围；③ 应用密码是否只给管理员角色。

- **已完成（批 BQ，2026-09-14）**：s3 的 upload/delete 分支 + `public_url` 落地（日志给出公开地址）+ 双目标端到端验证（含修 local 的 `path_prefix` 未生效）。
- **待办**：用**有效 R2 S3 凭证**跑一次真机上传/删除；此前如需同时保留 Worker 与 s3，注意 s3 只勾「静态文件」能力。


- **进行中（批 BP，2026-09-14）**：多托管第 2 步 s3 —— SigV4（官方向量 11/11）+ `testConnection()`（结构化错误分类，含 R2 实测校准）+ 设置页 s3 字段与类型放行。**下一步**：s3 的 upload/delete 分支 + 真机实测。


- **进行中（批 BO，2026-09-14）**：多托管第 2 步 s3 —— SigV4 签名已实现并通过 AWS 官方向量离线自检（11/11）。**下一步**：s3 的三个适配器分支（upload / delete / `testConnection()` 结构化错误分类）+ 设置页字段与类型放行；之后请用户填一条 R2 S3 目标做真机实测。


- **已完成（批 BN，2026-09-14）**：s3 前置验证（`/health` 无需认证 ⇒ 测试连通性改用受保护接口）+ 修 `get_hosting_targets()` 白名单漏放 `local` 的 bug（纯静态档此前实际不可用）。
- **下一步**：`s3` 适配器 —— 字段 = endpoint / bucket / region / access_key / secret_key / path-style / **public_url**；能力 `{r2:true,d1:false,cache:false}`；`path_prefix` 生效；path-style 自动判断 + 可手工覆盖；适配器带 `testConnection()`（用 `ListObjectsV2` 做 **SigV4 签名自检**）。


- **已完成（批 BM，2026-09-14）**：多托管第 3 步 —— 设置页「托管目标」卡片（增删行 / 类型切换 / 能力勾选 / 启用 / 路径前缀）+ JSON 保存 + 「🔌 测试连通性」AJAX。
- **下一步**：第 2 步 `s3` 适配器（S3 兼容对象存储直传）—— **字段与签名方式待与用户商定**（endpoint/region/bucket/access key/secret/path-style 等）。


- **已完成（批 AL，2026-09-14）**：多托管第 2 步 —— `local` 适配器（写本地目录、零联网）+ `d1_batch`/`purge` 按能力分派 ⇒ **纯静态档可用**。
- **下一步**：第 3 步（设置页 targets 管理 UI）→ 第 2 步（`s3` 适配器，字段待商定）。


- **进行中（批 AK，2026-09-14）**：多托管配置第 1 步 —— 托管 target 抽象层（`cf_hosting_targets` + `for_each_target` 分派）已落地，行为与改造前等价；`local` / `s3` 适配器与设置页 UI 待做（具体托管站的配置细节待与用户商定）。


- **已完成（批 AI/AJ，2026-09-14）**：一键初始化的**部署侧**（setup.mjs + wrangler.toml.example + 从零部署 README + npm run setup）与**后台引导页**（空库进向导 / `#setup` 手动进入）。待在新库上实跑一次「向导 → 建号 → 登录」。


- **进行中（批 AG/AH，2026-09-14）**：一键初始化已有后端（`ensureCoreSchema` + `/api/setup`）；媒体文件名中文 → 小写 `%xx`（修 404）；媒体内容哈希去重；`schema.sql` 更新到 3.0.0。**下一步**：`/admin/` 初始化引导页 + `npm run setup` + 从零部署文档。


- **已完成（批 AE，2026-09-14）**：修 md 文章「数据更新了但单页还是旧的」—— 导出时清除 R2 旧单页，交给 Worker 按需渲染；插件侧加图片上传结果汇总提示与端点写法兼容。
- **待用户侧确认**：Obsidian 的图片为何没上传（`main.js` 是否重建 / 「自动上传图片」开关 / 附件能否找到）。


- **已完成（批 AD，2026-09-14）**：媒体上传通道 `POST /wp-json/wp/v2/media`（multipart 与 raw 两种形状，jpg/png/gif/webp，10MB）；Obsidian 插件改为**自动上传本地附件并替换引用**（失败退回图片前缀），版本 1.2.0。


- **进行中（批 AC，2026-09-14）**：第三方 md 编辑器联调 —— Worker 已宽容接受 `POST /wp-json/wp/v2/posts/<id>` 作为更新；改好的 Obsidian 插件源码入库 `tools/obsidian-md-publisher/`；下一步按 README 的联调清单真机跑一遍，再决定要不要补 `POST /wp/v2/media`。


- **已完成（批 AB，2026-09-14）**：「已导出」标记改为只在真的产出静态单页时写入（②/③）；仅「同步到 D1」记 `_cfse_synced_d1` 并在 WP 文章列表按三态显示（已导出 / 已同步 D1 / 待导出）。


- **已完成（批 AA，2026-09-14）**：发布行为改为**三个独立选项** —— ① 同步到 D1（文章+草稿）② 生成静态文件 ③ 上传到远端（勾 ③ 隐含 ②），默认全不勾；只勾 ① 时只写库不渲染。另修：草稿删除后 D1 行未清。


- **已完成（批 ZZ，2026-09-14）**：Worker 路由新增**单页按需渲染**（R2 miss → D1 + 当前风格模板 → 写回 R2），且 WP 侧 **md 文章不再上传 R2**（只写 D1）。⇒ 「WP 当 CMS 后台（只管数据，单页由 Worker 自愈）」与「WP 当静态插件（勾选后本地生成 / html 直接进 R2）」两种用法并存。


- **已完成（批 YY，2026-09-14）**：WP 侧「发布行为」三开关（自动生成单页 / 自动上传到远端 / 草稿也同步），**三项默认全关**（= WP 保存文章不动远端，内容交给 Worker/第三方编辑器；需要全自动静态化的站点再勾选）· 草稿同步只写 D1（status=draft）· 删除时一并清本地产物 + 远端清理聚合到请求结束批量执行（整批 2 次请求）。这也落实了 F.1 里“静态托管可拆分”的诉求。


- **待定（既有，未修）**：`?_cfse_internal=1` 抓取请求使插件 `__construct()` 早退 ⇒ `the_content` 的 md 渲染钩子未挂 ⇒ **「前台抓取」路径产出的单页里 md 未渲染**（批量导出时每进程第 2 篇起都走该路径）。修法一行，见 AGENTS.md「批WW」待定项。


**✅ 已实现（UU 批，2026-09-14）**：档 0+1+2 全部落地 —— `/wp-json/wp/v2/*`（与 `/wp/v2/*`）的 posts CRUD / categories / tags / users/me / types / taxonomies；Basic Auth（用户名 + 应用密码，也接受账号密码）＋ `app_passwords` 表与后台「🔑 应用密码」UI；WP 侧防覆盖（导出改真 upsert 且 `WHERE posts.wp_id < CFSE_WORKER_ID_MIN`＋`publish_artifact()` 对 Worker 托管文章跳过后半段）。**归属仍用 wp_id 形态**（未加 `origin` 列）。媒体上传（贴图）按用户指示暂缓。详见 AGENTS.md「UU 批」。


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

⚠️ **迁移后必须"重建全部单页"**：单页 HTML 里**写死了自身的 URL**（`{{POST_URL}}` 在渲染时就填好了），迁移只搬 R2 对象、不改文件内容 ⇒ 不重建的话，单页内部的链接仍指向旧地址。列表页是动态渲染，会自动跟着新 pattern 走，无需处理。（规律：**单页 = 静态快照，列表 = 动态** —— 模板、站点名、permalink 任何一项改了，单页都要重建。）

**执行顺序（用户 2026-09-13 决定）**：**先做 C 节第 8 条的「Worker 端批量重建的并发 + 断点」**，再实施 permalink 迁移 —— 因为两者的调度骨架**完全一样**（分批取文章 → 并发处理 → 进度可续做），差别只在"每篇做什么"（渲染 vs 按新结构 copy/delete）。建议抽成一个通用执行器（如 `POST /api/batch/run?job=rebuild|migrate&cursor=…&limit=…` → `{done, cursor, processed, errors}`），permalink 迁移直接复用。

> 关于「并发」在 Worker 上的含义：免费档 CPU 限 10ms，但 `await` 网络等待**不计 CPU** → 可以单请求内 `Promise.all` 并行 N 篇，也可以**前端同时发 K 个请求**各跑一段。后者更稳、更适合断点续做。



---

## C. 其它待办

1. ✅ **已完成（2026-09-13，deploy `0925530a-64a3-4c4f-bcf7-c744faf1be9a`）**：在 `handleRequest` 统一入口加了「按来源 IP 的内存滑窗限速」（600 次/分钟，isolate 级 0 KV 成本；本地单测：同 IP 连打 605 次 → 允许 600/拒绝 5，换 IP 立即放行）。⚠️ **仍缺 IP 白名单**（未做，如需可后续补）。原描述：**四个 `X-Auth-Key` 写接口无限速**：`/upload`、`/delete`、`/purge-cache`、`/d1-batch`（`routes.js:157/160/67/123`）共用单一 `X-Auth-Key`，**只校验 key，无频率限制 / IP 白名单**。⚠️ 别混淆：**限速只覆盖了 Worker 后台** —— `/api/*` 写操作有每用户 90 次/分（内存滑窗，`admin.js:30-42`/`:560-562`）、登录有 IP 限速 60s/10 次（`auth.js:128-133`）；而上面这四个「插件→Worker」接口**不在**该分支内，**是敞开的**（key 泄漏即可无限写）。对外产品化时这条升级为必做。
2. ✅ **批1 已实现（2026-09-14）**：`generate` / `publish` 两个动作 + CLI `--mode=auto|generate|publish` + 设置页「📦 内容发布」卡已落地（实测见 H 节）；**仍缺多托管目标配置**（F.2 缺口 3）—— 换后端目前仍靠改 `site_url`/`auth_key`。原说明：**「生成」与「上传」拆开**（用户构想）：目前 `save_post` 自动跑「生成 HTML → 上传 R2 → 写 D1」三件；希望拆成可分别触发，从而支持「WP 只写 D1、生成交给 Worker」的形态，也为将来独立的「WP 静态化插件（内置部署通道）」留接口。 —— **落地边界与改动点见 H 节。**
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

---

## F. 长远产品规划（用户 2026-09-13 提出，**暂不实施**）

现状一句话：**当前是一套"缝合怪"** —— 静态生成、模板/风格转换、云端上传三件事挤在同一个 WP 插件里（AGENTS.md「架构取舍」也这么记）。**代码层暂不物理拆分**（避免重构成本），但**产品形态**上将来可以拆成三条线：

| # | 产品 | 受众 | 现有雏形 | 拆分后要补的 |
|---|---|---|---|---|
| 1 | **Worker 端纯 CMS** | 任何人（不依赖 WP） | ✅ 已有：`/admin/` SPA + D1 + R2 + KV + 模板/风格体系 | 去掉"必须由 WP 灌数据"的假设；自带初始化（建库/账号/部署引导）；开箱体验与文档 |
| 2 | **WP 静态化托管插件** | 想把 WP 静态化托管出去的人 | 大部分已有：插件（导出 + 三级降级 + 并发）＋ Worker 接收端 | 「装插件 → 填账号信息 → 一键托管到对应站点」的闭环 |
| 3 | **风格生成**（WP 主题 → 模板包） | **保留自用** | ✅ 已有：`view/skin-builder.php` + 抓取链路 | 不对外，维持现状 |

**产品线 2 的关键扩展点**（用户特别提出）：**托管端应当可插拔** —— 不只有 Cloudflare（R2 + Worker + D1），未来"填一组 API 就多一个托管后端"。
→ 这要求把"上传/写入"抽象成**适配层**（契约：上传单页 / 写索引或元数据 / 清缓存），与"生成"彻底解耦。
→ **它与 C 节第 2 条「生成与上传拆开」是同一件事**（那条是这个扩展的前置）。

**排期**：整体标为**后续计划**，优先级低于 C / B 节里的具体待办；本处只记方向，不承诺时间。

### F.1 「纯静态档」为什么其实不用重做（用户 2026-09-13 的判断，成立）

- **抓取路线已经存在**：`fetch_from_frontend()` 抓的是 **WP 前台真实输出**，比 `render_with_template()`（插件内跑主题模板，依赖查询上下文）**更忠实**；debug 模式本就落本地文件。
  ⇒ 「抓取存本地」这个动作**代码上已有**，只差一个**独立按钮**。
- **可插拔后端已具雏形**：Worker 的 `/upload`、`/delete`、`/purge-cache` 本身就是"路径 + 字节 + 认证头"的通用形态 ⇒ **换后端 = 换 URL 和 Key**，抽象层主要是"多目标配置"。
- **改动形态**：把"全量导出"拆成 **`生成（存本地）`** 与 **`上传到远端`** 两个动作；选择不同托管目标时，只是给出的选项不同。

### F.2 完成规划还缺什么（缺口清单）

**产品线 2（WP 静态化托管）** —— 已具备：单页生成双路 / 本地落盘 / 上传·删除·清缓存三接口 / 并发导出。缺：

| # | 缺口 | 说明 |
|---|---|---|
| **1** | **列表页的静态生成** ⚠️**最大缺口** | 现只生成单页，列表页靠 D1 动态渲染；纯静态档无 D1 ⇒ **首页/分类/标签/归档/分页都要生成 HTML**。**可复用现有前台抓取逻辑**（抓的就是 WP 前台真实输出，落盘即静态页）：需一并抓**分页**、并做 **URL 归一化**（`normalize_source_hosts()` 已有）；抓取时机需定（发布后按需抓首页/相关分类，还是定时全量）。这是"纯静态托管"成立的关键，最费工 |
| 2 | **生成与上传拆开** | 即 C.2；也是"可插拔后端"的前置 |
| 3 | **多托管目标配置** | 现仅单一 `site_url` + `auth_key`；需可增删的 target 列表（URL / Key / 路径前缀 / 认证方式 / 能力标记[有无 D1]） |
| 4 | **feed 静态化** | `/feed/` 现由 Worker 动态生成；纯静态档需静态 XML |
| 5 | **搜索方案（待决策）** | `/?s=` 纯静态不可用 ⇒ 放弃，或上客户端索引（如 Pagefind） |
| 6 | **媒体批量/增量同步** | 单张上传已有；补批量与"已存在则跳过" |
| 7 | ~~**X-Auth-Key 接口限速**（原 C.1）~~ | ✅ **已完成（2026-09-14，commit `e1041ba`）**：统一入口按来源 IP 内存滑窗 600 次/分（isolate 级、0 KV）。⚠️ 仍缺 **IP 白名单** |
| **8** | **列表页抓取的 URL 改写与分页**（缺口 1 的实现细节） | 抓取路线现在只覆盖「单篇文章 URL」；纯静态档要**遍历分页**（首页/分类/标签/归档/分页）并把抓到页面里**指向原站/原结构的链接改写**为静态站形态 —— `normalize_source_hosts()` 只处理 host，分页链接与单页链接还需按 `storagePattern` 重写。这是纯静态档最实的一块工 |
| **9** | **md 格式标识链路**（2026-09-14 实测新增，见 **G 节**） | WP 侧写入的 md 被导出端硬编码成 `html` ⇒ 线上单页/列表/feed 全是 md 原文。修法三处：①写入端写 post_meta ②导出读 meta（去掉 `content_format` 硬编码）③单页渲染认 md（`the_content` + Parsedown，或把单页生成交给 Worker） |
| **10** | **单页写者冲突（防覆盖）** | 单页 R2 有**两个写者**（WP 导出链路 / Worker `writeSinglePage()`，均写同一 `storagePattern` key）。A 节候选 1 拿 `content_format='md'` 兼作「归属」标记，在「WP 侧也能产生 md」后会失效（自伤）⇒ 归属需单独表达（A 节候选 2 的 `origin` 列）。详见 **H 节第 3 点** |

**产品线 1（Worker 纯 CMS）** —— 缺：① 一键初始化（建 D1 表 / 建管理员 / 绑 KV·R2 / 部署引导）；② **第三方编辑器写入口**（见 **A 节**：REST 写子集 + Application Passwords + md 直存 + 候选 1 防覆盖）—— 这正是"纯 CMS"该有的能力，之前单列成 A 节，这里回指；③ 空库可跑的验证（不能假设由 WP 喂数据）；④ 对外文档（安装/配置/部署/备份恢复）；⑤ ❓**是否多租户**（一个 Worker 服务多站点？若做 SaaS 架构完全不同，需先明确）。

**产品线 3（风格生成）** —— 维持自用，无需完善。

**排期建议**：`C.2 生成/上传拆开` → **列表页静态生成**（产品线 2 的真正大件）→ 多后端抽象与产品拆分。

---

## G. md 文章链路缺陷（2026-09-14 实测诊断，**未修**）

**现象**：经 WP 侧写入的 md 文章，线上单页与列表摘要都是 **md 原文**（`## 标题`、代码块原样显示），不渲染。

**实测证据（2026-09-14）**
- D1（Cloudflare API 只读）：`content_format` 全表分布 **`html` 290 / `md` 3**；那 3 篇 md 的 `wp_id` 是 `Date.now()*100+rand` 形态（`178935742698156` 等）= **Worker 端新建**，渲染正常。
- 测试文「测试MD」（WP `ID 1751`）在 D1 里曾是 **`content_format="html"`**（WP 来源一律被写成 html）；其线上单页 `/%e6%b5%8b%e8%af%95md/`（35650B，带 `postid-1751`、无 `{{}}` 残留 ⇒ 由 WP `generate_post_html()` 生成）里 `<h2>` 计数 **0**、正文出现原始 `## 方法1：查看实时连接状态`；首页同篇摘要 = md 原文被压平成一段 `<p>…`。
- **来源区分特征**：WP 来源 = WP 自增 `wp_id`（1751…）；Worker 来源 = `Date.now()*100+rand`。两者在 D1 同表混存，**没有显式来源列**（正是 A 节候选 2 「`origin` 列」要解决的）。

**病灶链**
```
tool/index.php「MD」模式（md 原文直塞 post_content，WP 侧无任何格式标记）
  → save_post 自动导出
     ├ generate_post_html()：主题模板直接吐 post_content → R2 单页 = md 原文
     └ write_to_d1()：INSERT OR REPLACE … content_format 硬编码 'html'（:1109-1111）→ D1 标成 html
        → Worker 列表/feed 按 html 渲染（摘要=原文）；「重建全部单页」也会按 html 处理 ⇒ 会把原本正确的好页面弄坏
```

**关键验证（已做）**：`the_content` 过滤器**确实在渲染路径上** —— Twenty Ten 单页模板 `loop-single.php:37` 调 `the_content()`，一级渲染 `render_with_template()`（`:746-757`）就是 `load_template(single.php)`；二级降级 `fetch_from_frontend()` 抓 WP 前台同样过 `the_content`。⇒ **挂一个过滤器即可同时修好单页与 WP 前台显示**，不必逐处改模板。

**候选修法（互斥，待拍板）**
- ✅ **A′ 家在 WP —— 已实现（2026-09-14 批2）**：① `tool/index.php`「MD」模式用 `meta_input` 写 `content_format=md`（**不带下划线**的可见字段，与 D1 列同名，可在 WP 后台「自定义字段」面板手改；插件读取兼容旧隐藏键 `_cfse_content_format`）；② `build_post_meta()` 读 meta 决定 `content_format` + `write_to_d1()` 参数化（去 `'html'`/`'published'` 硬编码）+ md 摘要纯文本化；③ vendored Parsedown + `the_content` 过滤器（仅 meta=md）。实测与文件改动见 AGENTS.md「批2」。原方案：① `tool/index.php` 的 MD 模式加一行 `update_post_meta($post_id,'_cfse_content_format','md')`；② 插件导出读该 meta（去掉 `content_format` 硬编码，`status` 一并改读真实状态）；③ 插件 `add_filter('the_content', …)`，**仅当该篇 meta=md** 时用 `Parsedown->text()`（`setSafeMode(true)`；服务器 `tool/Parsedown.php` 现成）⇒ 单页/WP 前台渲染正确 + D1 存 md 原文带标记 ⇒ 列表/feed/重建也正确。
  边界：**只统一「格式与渲染」，不是双向同步** —— Worker 端改同一篇（同 wp_id），下次 WP 导出仍会覆盖（那是防覆盖，见 A 节候选 1）。遗留风险：在 WP 后台编辑器保存 md 文章会被 HTML 化（需实测/约定不进后台编辑器）。
- **内容检测式（不推荐）**：`the_content` 上用正则判断「疑似 md」（他人建议方案）。风险：md 与 HTML 混写的文章会被整篇重渲染；且**不解决 D1 标记**（列表/feed/重建仍错）。
- **B 家在 Worker**：`tool/index.php` 的 MD 模式改走 MD2HTML（或弃用），**WP 永不出 md**；md 只在 Worker 端新建（现有 3 篇 md 即此形态）。代价：放弃「md 原稿存在 WP、可在 WP 侧编辑」。

**数据处理状态**
- ✅ 「测试MD」（WP `1751`）**已删**（2026-09-14）：WP 库无、D1 无、线上单页 404、首页无残留 —— 走 `before_delete_post` → 插件 `delete_post()`（`:1323`）同步清 R2 + D1（含 `post_taxonomy` 与孤儿 `taxonomies`）。
- ⏳ **D1 里仍有 10 篇 Worker 端测试文**（`wp_id > 1e11`，均 `status=published`、线上可见）：`test mad geshi` / `testfor MD` / `testtmd`（md）+ `打发发达发放安抚发放到` / `滚滚滚余余余` / `fdafafafafaf` / `eraahagag` / `dererar` / `tesertererere` / `测大地方`（html）—— 待定是否清理。
- ⏳ 线上首页列表条目的作者链接残留未替换占位符 `href="{{SITE_URL}}/author/admin/"`（item 模板的 `{{SITE_URL}}` 未注入），独立处理。

---

## H. 「生成 / 上传」拆开的落地方案（2026-09-14 调研，细化 C.2）

**现状：没有边界** —— `do_export_post()`（`:581`）把四步串在一个函数里：生成 `generate_post_html()`（`:600`）→ 上传 `upload_to_r2_via_worker()`（`:616`，路径 `get_post_storage_path()` `:612`）→ 写 D1 `write_to_d1()`（`:629`）→ 清缓存 `purge_page_caches()`（`:639`）。

**⚠️ 更正：「调试模式」不是「仅生成」** —— `cf_debug_mode`（option；`CFSE_DEBUG_DIR = WP_CONTENT_DIR.'/cf-debug'`，`:31`）只在 `upload_to_r2_via_worker()` **内部**（`:950-954`）按 R2 相对路径镜像落一份副本，随后**继续上传** ⇒ 它是「上传前留档」。真正阻断上传的唯一条件是 `auth_key` 为空（`:959-962`，且会抛异常）。要做成「仅生成」，需在 `:612-623` 之间插一个分支（改动很小）。

**可拆的边界（按依赖划分）**

| 段 | 函数 | 依赖 | 能否独立 |
|---|---|---|---|
| 生成 | `generate_post_html()` `:700` → `render_with_template()` `:721` / `fetch_from_frontend()` `:778` | 强绑 WP 运行时（篡改 `$wp_query/$post/$more`、`load_template`、HTTP 回环到自身站点） | ❌ |
| 上传 | `upload_to_r2_via_worker()` `:948` | 仅 `site_url` / `auth_key` / `debug_mode` | ✅ 干净边界 |
| 写索引 | `write_to_d1()` `:1101` → `d1_batch()` `:1226` | 仅 config（降级直连 CF API） | ✅ |
| 删除 | `delete_from_r2()` `:1357` | 仅 config | ✅ |
| 清缓存 | `purge_page_caches()` `:685` | 仅 config | ✅ |

⇒ **「托管后端适配层」要抽象的正是这四个动作**：`/upload`、`/delete`、`/d1-batch`、`/purge-cache`（+ 可选 `/style-list`）。契约 = 路径 + 字节 + 认证头（见 F.1）。出网调用点全清单：`/purge-cache` `:685`/`:1309`、`/upload` `:957`、`/d1-batch` `:1230`、`/delete` `:1361`、`/style-list` `:1407`/`:1423`、CF `api.cloudflare.com` 兜底 `:1178`/`:1205`。

**拆分带来的三个直接收益**
1. **纯静态档**（F.2 缺口 1、2）：生成 → 落盘（`fetch_from_frontend()` 抓 WP 前台真实输出），上传另起一步；后台按钮拆成「抓取/生成到本地」与「上传到远端」。
2. **多托管目标**（F.2 缺口 3）：换后端 = 换 URL + Key + 认证方式（含「有无 D1」能力标记）。
3. **md 单页由谁渲染**（G 节第 ③ 项的替代解）：单页 R2 有**两个写者** —— WP 端导出链路 与 Worker 端 `writeSinglePage()`（`worker/src/admin.js:436`，仅 `/api/posts` POST/PUT/rebuild/batch publish 触发），两者写**同一个 key**（同一 `storagePattern`），谁后写谁覆盖 ⇒ 这正是「防覆盖」的核心矛盾。**md 单页若交给 Worker 生成**（D1 里 md 原文 + `marked` 已就绪，`admin.js:421`），G 节第 ③ 项不必做；留在 WP 生成才需要 ③。

**建议顺序**：拆边界（机械重构、不改行为）→ 加「仅生成」分支 + 多目标配置 → 定 md 单页生成归属（与 G 节 ③ 二选一）。

### H.1 拆开后的「渲染归属」技术路线（2026-09-14 草案，**已定路线、未编码**）

**先纠正两个前提（实测结论，别按旧印象设计）**

1. **现在不是"解耦"，而是"两条链路并存 + 互相覆盖"**：WP 的 `save_post` 自动跑「生成 → 上传 R2 → 写 D1」三件（`:581` → `:600`/`:616`/`:629`），**没有"只写 D1"这个选项**；Worker 端 `writeSinglePage()` 只在 `/api/posts` 的 POST/PUT/rebuild/batch publish 触发。两条链路写**同一个 R2 key**，谁后写谁覆盖。
2. **"WP 只写 D1、Worker 会自己渲染单页" —— 当前不成立**：Worker 端**没有**任何「D1 变化 → 自动重建单页」的机制。列表/feed 会自动跟上（查 D1 动态渲染），但**单页是 R2 对象，必须有人显式写**。

**⇒ 拆开后必须显式回答：写进 D1 之后，谁负责把单页写进存储？** 三个候选，与"托管后端能力"一一对应：

| 方案 | 谁生成单页 | 触发方式 | 适用档位 |
|---|---|---|---|
| **S1 本地生成**（现状） | WP（`generate_post_html()`） | 本地按钮 / 按需自动 | **纯静态档**（任意对象存储 / CDN，无运行时） |
| **S2 远端生成（内建）** | Worker `writeSinglePage()` | **写 D1 时服务端自动重建** ⇒ D1 成为唯一写入面，WP 无需额外调用 | 全功能档（Cloudflare）；最贴近"完全解耦" |
| **S3 远端生成（显式）** | Worker | WP 写完 D1 后再 `POST /api/posts/:id/rebuild` | 全功能档；改动最小但多一次请求，需给该接口配鉴权 |

**建议**：先落 **S3**（复用现成接口）、终态做 **S2**。S2 的注意点：`/d1-batch` 批量写 292 篇时不能逐篇同步重建（单请求时长），要复用现有批量执行器 + 进度键分批。

**渲染一致性的硬约束**：同一篇 md 由 `marked`（Worker/JS）与 `Parsedown`（PHP）渲染会有细节差异 ⇒ **同一篇文章的单页只能有一个渲染者**（由该站点的档位决定），否则**既互相覆盖又可能前后不一致**。这与「防覆盖」（A 节候选 1 / F.2 缺口 10）是同一件事的两面：**单页归属必须唯一**。

**本地生成要按格式分流** ✅（确认）：`generate_post_html()` 现在**不区分格式**（`post_content` 直接进主题模板）。改造点：读 `content_format` —— `md` → 先 `Parsedown->text()` 再进模板；`html` → 原样（现行为不变）。

**Parsedown vendoring** ✅（同意，附工程细节）
- 复制 `tool/Parsedown.php` → 插件内（建议 `vendor/parsedown/Parsedown.php`），`require_once` 前先 `class_exists('Parsedown')` 判断；
- **防类名冲突**：站点其它插件/主题可能也带 Parsedown（`Cannot redeclare class`）⇒ 建议改名或加命名空间（如 `CFSE_Parsedown`），并保留上游 MIT LICENSE 声明；
- 渲染时 `setSafeMode(true)`（防 XSS）；
- **必须复制而非引用 `tool/`** —— 插件将来要独立分发（产品线 2）。

**对产品线 2 的意义**：本地生成（S1）+ 抓取列表页（F.2 缺口 1/8）+ 上传到任意对象存储 = 纯静态托管闭环，Parsedown 随插件走。

**✅ 批1 已落地（2026-09-14，已同步服务器 + 真实验证）**：`build_post_meta()/build_post_artifact()`（生成，不联网）与 `publish_artifact()`（上传 R2 + 写 D1 + 清缓存）拆分完成，`do_export_post()` 串联两者 ⇒ 自动导出行为不变；public `generate_post_local()`（落盘 `WP_CONTENT_DIR/cfse-static/`）与 `publish_post_local()`（读本地产物发布，缺则跳过）已上线；CLI `--mode=generate|publish|auto` + 设置页两个按钮。实测：generate→落盘 30838B；publish→R2+D1+线上 200/30928B；未生成篇跳过；并发 mode 传参 `[2,2]`。

**未做**：多目标配置、`/d1-batch` 内建自动重建（**S2**）、md 单页渲染归属（G 节 ③ 与 S2 二选一）、列表页生成（F.2 缺口 1/8）。

---

## I. WP ↔ Worker 双向数据流（2026-09-14 澄清，**结论：目前不能，且有个更省事的替代**）

**问题**：WP 后台能否获取 / 编辑「只存在于 Worker 端（D1）」的文章？**答：不能。**

**现状事实（已核实）**
- 数据流**单向**：WP → Worker（`save_post` → 生成 → `/upload` + `/d1-batch`）。`worker/src/admin.js` 里只有 `GET /api/export`（**JSON 备份**，格式不是 WP 可导入的 WXR）与 `POST /api/import`（**导入到 Worker**，`wp_id` 用 `Date.now()*1000000+i`、按 slug 去重）；WP 插件里**没有任何导入逻辑**（`wp_insert_post(` 在插件中零命中）。
- Worker 的 X-Auth-Key 接口共 6 处（`/purge-cache`、`/style-list`、`builtin-sync`、`/d1-batch`、`/upload`、`/delete`）—— **没有供 WP 拉取文章的读接口**。
- 网络方向：WP（内网）→ Worker（公网）**可达**（插件已在用）；Worker → WP **不可达**（内网不可穿透，见 EVOLUTION 第八节）。⇒ 要做只能由 WP 主动拉。

**若要实现「WP 拉取 Worker 文章」，三件事缺一不可**
1. **新只读接口**（X-Auth-Key 鉴权，放 `routes.js` 非登录区，仿 `/style-list`）：如 `GET /sync/posts?since=…` 返回 `wp_id/slug/title/content/content_format/modified/status`；
2. **`wp_id` 对齐（关键坑）**：必须 `wp_insert_post(['import_id' => <Worker 侧的 wp_id>])` —— WP 允许指定正整数 ID，两端 ID 才能对上；否则 WP 分配新自增 ID ⇒ 下次导出按 `wp_id` upsert 会把它当**另一篇新文章**写进 D1，**产生重复文章**（Worker 文章的 ID 是时间戳形态，不会撞上 WP 自增序列）；
3. **归属规则**：拉回来即"两端都有" ⇒ 必须定谁说了算。WP 接管（此后编辑都在 WP、导出覆盖 Worker）自洽；若只想"只读"，就**不要**拉进 WP（否则必被覆盖或重复）。

**更省事的三条替代路径（建议优先考虑）**
- **A. Worker 后台 `/admin/` 直接编辑**：已支持 md 编辑 + `marked` 预览 —— **零开发**；
- **B. 第三方编辑器直连 Worker**（A 节写入口：`/wp/v2` 兼容 + 应用密码）—— 解决"外网 / 移动端写作"；
- **C. 统一诞生地**：md 文章一律在 WP 侧写（`tool/index.php` 或编辑页「📝 文档格式」勾选）→ 自动推送；不在 Worker 端新建 ⇒ **永远不需要"拉回来"**。

### I.1 用 `wp_id` 形态判定「诞生地」（2026-09-14 用户提出，**判据成立**）

**判据**：`wp_id` 的形态天然区分来源 —— WP 侧是**自增小整数**（当前 1751、1763…），Worker 侧是**时间戳形态**（新建用 `Date.now()*100+rand` ≈ 1.7e14，`/api/import` 用 `Date.now()*1000000+i`）。取一个远高于任何自增序列的阈值（如 `1e11`）即可零误判地区分。

**用例 1：生成「待拉取清单」（零新字段）** —— *D1 里存在 + WP 里不存在 + `wp_id` 为时间戳形态* = Worker 端诞生的文章 ⇒ 由 WP 主动拉取（网络方向只允许 WP → Worker）。

**用例 2：拉取后仍能标识来源** —— 拉取时用 `wp_insert_post(['import_id' => $worker_wp_id])` 保持两端 ID 一致（WP 允许指定正整数 ID），于是该篇在 WP 里**仍是时间戳形态** ⇒ "原生于 Worker"这个信息天然保留，**不需要新增 `origin` 列**。这与 `content_format` 的透传是同一套思路：让**已存在的数据**（形态 / 字段）驱动逻辑，而不是新增耦合。

**必须补的三点**
1. **判据集中一处**：插件与 Worker 共用同一个阈值常量/函数（如 `CFSE_WORKER_ID_MIN = 1e11`），别两边各写一套（将来调阈值不会有遗漏）。
2. **「拉取 = WP 接管」必须显式告知**：拉进 WP 后该篇由 WP 托管 —— 此后 Worker 侧的编辑会被下次 WP 导出覆盖。若想保留"两端都能改"，才需要显式 `origin`/锁（**未来项**，不是现在）。
3. **冲突处理**：拉取时若 WP 里已存在同 ID，**跳过并报告**（用 `get_post($wp_id)` 判断存在，天然幂等），不要盲覆盖 WP 现有内容。

**落地草图（未实施，等拍板）**
- **Worker 侧**：新增只读接口（X-Auth-Key，放 `routes.js` 非登录区，仿 `/style-list`）`GET /sync/posts?limit=&since=` → `{posts:[{wp_id,slug,title,content,content_format,excerpt,date,modified,status,categories,tags,featured_image}]}`；
- **WP 侧**：设置页新增「📥 从 Worker 拉取文章」—— 逐篇 `get_post($wp_id)` 判存在 → 不存在则 `wp_insert_post(['import_id'=>$wp_id, 'post_title'=>…, 'post_name'=>slug, 'post_content'=>…, 'post_status'=>…, 'meta_input'=>['content_format'=>…]])` + 分类/标签按 slug 映射（`wp_set_post_terms`，不存在则创建）→ 结束后报告「新增 N 篇 / 跳过 M 篇」；
- **可选**：给拉取来的篇加一个**仅展示用**的 meta（如 `_cfse_origin=worker`），后台显示"原生于 Worker"，**不参与任何数据流判断**。

**✅ 已实现（2026-09-14，批 J）**：Worker `GET /sync/posts`（deploy `c444bb6c-af61-47b7-b31a-f8d35a67fb13`）+ WP「☁️ 与 Worker 同步（拉取）」卡；`import_id` 保持两端 ID 一致、`get_post($wp_id)` 幂等跳过、分类/标签按 slug 映射、拉取期间短路导出。实测：新增 1 / 跳过 10（复跑 `[0,11,0,11]`）、正文 md5 与 D1 完全一致、格式 `md` 正确、清理后无残留。详见 AGENTS.md「批J」。

**补充（2026-09-14，批 K）**：已实现**单篇对齐**（编辑页「☁️ Worker 同步」面板：异步两端时间对比 + 「↻ 从 Worker 对齐本篇」）与**覆盖模式拉取**（拉取卡勾选「覆盖 WP 中已存在的同 ID 文章」）—— 即「对齐」的两个常用形态；自动对齐（每次导出前以 Worker 为准）**仍未做**，因为它等于把权威交给 Worker，需要时再加（语义见上文）。

**⚠️ 重要修正（2026-09-14，批 L）**：用 `import_id` 对齐两端 ID 会把 `wp_posts` 的 `AUTO_INCREMENT` 顶到时间戳量级（且 **删文章不会降回来**），从而**破坏「ID 形态 = 诞生地」判据**（WP 新文章也会被误判为 Worker 端诞生）。已改为**显式 meta** `_cfse_remote_wp_id` 记录远端 ID：WP 侧保持自增 ID，导出 / 删除 / 面板判据都用该 meta，ID 形态仅作兜底。教训：**不要用会被用户数据推高的列当来源判据**。（2026-09-14 批 N：面板按钮改**链接**（避免与 WP 主表单嵌套而被浏览器忽略）、仅「远端更新」时显示、文案「对齐本篇」+ 问号说明、对齐后跳回当前文章；对齐时同步 `post_modified` 使两端时间一致。）（2026-09-14 批 M 调整：面板**默认自动比对**（只读），但**永不自动覆盖** —— 三种比对结果都只提示，由用户决定是否点「↻ 从 Worker 对齐本篇」；设置页开关「编辑页自动比对 Worker 版本」默认开，关掉则完全手动。）




