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

## B. 单页 permalink 交给本项目自己驱动（方案 A：搬迁 R2）—— 设计已讨论，**未拍板、未编码**

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
- 迁移必须三件套：① **分批 + 幂等**（`POST /api/permalink/migrate`，每批 N 篇、返回进度、可续做）；② 顺序 **先 copy 全量 → 切 pattern → 再删旧 key**（中途无 404 窗口，顶多短时间两份共存）；③ **旧 URL 处理** —— 纯加前缀的迁移**一条重写规则**就能 301，不必逐条存表。
- **语义风险**：改 slug / 改发布日期 → 该篇 key 变；用 `{category}` → 改分类 slug 会让**一整批**文章 key 变。所以迁移工具是必需品（顺带可加「R2 孤儿 key 清理」）。
- 落成后 `routes.template.js` + 设置页「🔄 同步路由规则」按钮可**退役**（permalink 不再需要 WP 灌入）—— 与「选项 B：废掉模板机制」是同一个决定。

**下一步（等用户拍板）**：确认走 A 还是 B、旧 URL 要不要 301 → 出落地清单（pattern 存哪、迁移 API 分批策略、重定向规则、WP 端如何读同一份设定）。

---

## C. 其它待办

1. **四个 `X-Auth-Key` 写接口无限速**：`/upload`、`/delete`、`/purge-cache`、`/d1-batch` 共用单一 `X-Auth-Key`，无频率限制 / IP 白名单（用户有其它站点「一天大量发布」的场景）。Worker 后台自身的写操作已有内存滑窗限速。
2. **「生成」与「上传」拆开**（用户构想）：目前 `save_post` 自动跑「生成 HTML → 上传 R2 → 写 D1」三件；希望拆成可分别触发，从而支持「WP 只写 D1、生成交给 Worker」的形态，也为将来独立的「WP 静态化插件（内置部署通道）」留接口。
3. **导出剩余耗时**：D1 写入已批量化、全量已并发（`tools/bulk-export-worker.php` + 设置页并发数），剩余是 WP 端渲染 + R2 上传的固定开销。
4. **HTML 稳定化**：同一篇两次渲染的 HTML 差约 15B（动态 id / 时间戳类片段），导致「上传去重」对 HTML 不命中。
5. **模板细节**：站点名在模板里是硬编码文本（未用 `{{SITE_NAME}}`）；含 `<svg>` 的容器不会被整体包进 `{{#IF_*}}` 块（不影响正确性）。
6. **媒体库大批量删除偏慢**（R2 单删约 1s，分批 20 即可）。
7. **单页重建**：模板改动只影响「新建 / 重建」的单页；需要时在 Worker 后台跑「重建全部单页」。

---

## D. 近期已完成（从待办移出）

- **旧键 `tpl:sindex-builtin` 已清理**（2026-09-13，commit `30852fa`）：内置风格索引统一走 `tpl:index`，去掉了双写与兼容读取，线上 KV 旧键已删、deploy 验证无回归。
- **`routes.template.js` 与 `routes.js` 的漂移**：本次只对齐了动到的那一行；整体对齐或退役（路线见 B 节末）**仍未做**。
