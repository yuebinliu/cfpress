# WP 静态化插件（新插件）抽取方案

> 状态：**已实施（2026-09-17，v0.1.0）** —— 独立仓库 `cf-static-hosting`，12 模块，已激活并端到端验证（含直连兜底）。
> 来源：本次会话"三个方向拆分"讨论的收敛结论 —— 方向一/二已闭环，只需抽取"方向二通用部分 + 方向三"另立新插件。
> 相关：`direction-classification.md`（方向分类清单）、`config-restoration-plan.md`（配置归属还原）、`transports.md`（10 种 transport）。

---

## 1. 背景与目标

**现状**：`cf-static-export-pro.php`（CFSE，357KB / 5701 行）把三个方向的代码混在一个文件里。

**结论（用户判断，2026-09-17）**：
- **方向一（Worker CMS 交互）+ 方向二（WP 插件本体）都已闭环** —— Worker CMS 在 `worker/` 内、WP 插件本体在跑。
- **只剩方向三（通用静态托管）**尚未独立。
- 因此**不再做"三方向物理拆分"**，而是**另立一个新插件**，把"通用静态托管"能力抽出去独立发布。

**目标**：一个**可独立发布的 WP 静态化插件**（"纯静态托管"产品线）—— 装到任意 WP 站，把整站抓成静态文件，发布到任意对象存储 / CDN。

---

## 2. 职责边界（重要，决定实现范围）

> **插件职责 = 忠实还原站点页面。不是"替用户处理动态功能"。**

- 插件做：抓取 WP 前台真实输出 → 后处理（**仅 host 改写**）→ 落盘 → 分发到目标。
- 插件**不做**：搜索框 / 评论框 / 其他动态功能的处理 —— 用户既然上静态插件，会自行处理（改主题、去掉、或换 Pagefind 等）。插件只需在**文档里注明"静态站不支持动态功能"**。
- **唯一的例外（必须做，且不算越界）**：**host 改写**（`normalize_source_hosts`）—— 把抓下来页面里的源站域名 / 内网 IP 改成静态站地址。这是"让页面在静态站上能正确工作"的必要处理，**不是**"替用户处理动态功能"。
  - 附带必然结果：搜索表单 `action` 指向静态站根 ⇒ 提交 `/?s=xxx` 在静态站 404。这是"忠实还原"的自然结果，用户自行决定是否改主题。

---

## 3. 与 CFSE 本体的关系

| 项 | 决定 |
|---|---|
| **抽取方式** | **复制抽取**（CFSE 本体不动 = 生产系统零回归风险；新插件独立一份） |
| **分叉成本** | 共用算法（生成 / transport）改动时**登记"需同步到新插件"**；长期可考虑抽共享库（composer 包 / git subtree），现在不做 |
| **结构** | 新插件**复制算法逻辑、重新组织结构**（干净的类划分）—— 不必照搬 CFSE 的缠绕代码；新插件本身即"结构清晰"样板 |
| **同站激活** | ⚠️ 两者都 hook `save_post`、都生成单页 ⇒ **同一站点不能同时激活**。定位：**新站用新插件 / 老站（自托管 CMS）用 CFSE** |
| **命名 / 前缀** | 目录 + 文本域 `cf-static-hosting`；显示名 `CF Static Hosting`；option 前缀 **`cfsh_`**、常量前缀 **`CFSH_`**（**独立于 CFSE 的 `cf_`/`CFSE_`**，避免"先装 A 后装 B"时配置串味）|

---

## 4. 抽取边界清单

### 4.1 留在 CFSE 本体（绑定 CMS，**不抽**）

- **D1 数据层**：`write_to_d1` / `d1_batch` / `worker_d1_batch` / `d1_query` / `d1_execute` / `ensure_d1_schema` / `migrate_d1_schema`
- **双向对齐**：`fetch_worker_post` / `overwrite_post_from_remote` / `pull_posts_from_worker` / `apply_worker_terms` / `get_remote_wp_id` / `find_post_by_remote_id` / 编辑页 Worker 同步面板 / `is_worker_owned_post`（防覆盖）
- **cfse-worker transport + 缓存**：`worker_request` / `upload_to_r2_via_worker` / `purge_page_caches` / `sync_site_info` / `sync_cache_settings` / `sync_site_settings`
- **Worker 运维**：`sync_routes` / `sync_templates` / `deploy_worker` / `stage_theme_assets` / `scan_theme_dir` / `handle_seed_admin` / worker 版 `test_connection`
- **风格 / 模板体系**：`views/skin-builder.php` / `views/template-editor.php` / `worker/src/templates.js` 生成（服务 Worker 渲染，与"生成静态产物"无关）
- **第三方写入口**：`/wp-json/wp/v2/*`（给 Worker CMS 写内容）

### 4.2 进新插件（通用，复制）

- **生成（抓取）**：`fetch_from_frontend` / `post_process_html` / `normalize_source_hosts` / `is_valid_html` / `html_has_title` / `extract_excerpt`
- **产物 / 路径**：`get_post_storage_path`（用 `get_permalink` 派生，**天然通用**）
- **纯静态 transport（9 种）**：`s3`（含 `sigv4_*`）/ `local` / `ftp` / `webdav` / `github` / `git` / `gitlab` / `netlify` / `vercel` + `target_deploy` / `deploy_prepare` / `is_deploy_target` / `target_upload|delete|public_url|dir|type`
- **目标体系**：`get_hosting_templates`（去 cloudflare CMS 字段）/ `sanitize_hosting_targets` / `get_hosting_targets` / `for_each_target`
- **整站发布**：`allowed_site_roots` / `is_forbidden_site_dir` / `resolve_site_dir` / `site_file_list` / `mime_for_path` / `publish_site_dir_chunk` / `deploy_site_to_deploy_targets`
- **媒体**：`sync_media_files` + **泛化的"上传到目标"**（CFSE 里是 R2 专用，要改）
- **并发 / CLI**：`export_posts_concurrent` + `tools/bulk-export-worker.php`（改名去 worker 绑定）
- **发布行为开关**：`gen_static` / `publish_remote`

### 4.3 新插件**新增**（CFSE 没有 / 依赖 Worker）

| 项 | 说明 |
|---|---|
| **列表页抓取**（首页/分类/标签/归档/分页） | CFSE 靠 Worker 动态渲染，新插件必须抓成静态（见 §5） |
| **URL 枚举** | 抓取前列出所有列表页 URL（见 §5.2） |
| **刷新调度** | 精确触发 + TTL + cron 兜底 + 手动（见 §5.3） |
| **旧页清理** | 分页边界变化后删掉超界的高号页（见 §5.4） |
| **feed 抓取** | CFSE 的 feed 靠 Worker 动态；新插件枚举时多列一条 `/feed/` 抓下来（**只第一页，不做 `/feed/2/`**）—— 成本极低 |
| **反而不需要的** | `routes.js` / `storagePattern` / KV 配置 / D1 —— **整块消失**（无 Worker） |

---

## 5. 核心机制

### 5.1 生成 = 抓取（不是渲染）✅ 已定

新插件的"生成"**不做渲染器** —— 生成 = `HTTP GET`（抓 WP 前台） + 后处理（host 改写）。比"内部模板渲染"路线**简单一个数量级**，且忠实度最高（就是主题的真实输出）。

> 抓取前提：WP 前台可从 WP 自身访问（`fetch_from_frontend` 是 HTTP 回环抓自己）。CFSE 已验证此举可行（含 CLI 场景的 `--site` 修复）。

### 5.2 URL 枚举（不可省）

"抓一遍"的前提是知道**抓哪些 URL**：

| 类型 | URL 形态 | 枚举依据 |
|---|---|---|
| 单页 | `get_permalink($post)` | 遍历所有已发布文章 |
| 首页 | `/`、`/page/2/`… | 文章总数 ÷ 每页数 → 总页数 |
| 分类 | `/category/<slug>/` + `/page/N/` | 分类列表 + 各自文章数 |
| 标签 | `/tag/<slug>/` + `/page/N/` | 标签列表 + 各自文章数 |
| 归档 | `/<year>/`、`/<year>/<month>/` + `/page/N/` | 文章的日期分布 |
| **feed** | `/feed/` | **一条固定 URL，只抓第一页**（不做 `/feed/2/`）|

- 全部用 WP API 查询可得，实现不难但**必须有**（这就是之前说的"URL 枚举"）。
- **深度可配**：每个列表抓前 N 页（**默认 N=1**，即只抓首页/第一页）。

### 5.3 触发策略（用户定稿）

| 层 | 触发 | 覆盖 | 可靠性 |
|---|---|---|---|
| **核心** | **发布 / 更新时顺手刷** | 该篇 + 首页 + 该篇的分类/标签/归档页（**精确触发，不依赖 TTL**） | 必做 |
| **保险** | **cron 兜底** | 漏网的（如仅改标题但未触发、或跨事件变化） | 伪定时，**不依赖**（不跑也不影响核心） |
| **控制** | **手动触发** | 全站 / 选择性刷新 | 用户主动 |

- **"发布/更新时顺手刷"不依赖 TTL**：新文章上线 → 首页/该分类/该标签/该归档**必然变化**，精确刷它们即可。
- **简化：更新文章 = 精确触发** —— 「更新时刷**该篇 + 首页 + 所属分类/标签/归档页**」，**不区分"改了什么"**（不做变更类型判断，避免复杂度和漏判）。`save_post` / `trashed_post` 都会触发。
- **cron 是"伪定时"**（依赖 WP 访问触发），纯静态站访问可能少 ⇒ cron 可能不跑 ⇒ **设计上不让核心功能依赖它**。
- **TTL 由用户设**：用于"非发布场景的列表页重抓"（如定时全站刷新），是**生成侧调度**，不是运行时 TTL。

### 5.4 分页边界变化 → 重抓 + 清理旧页

- 新文章进来 → 分页错位（第 1 页挤掉旧文章往后推）。
- 文章删除 → 分页数变少 → **旧的超界高号页（如 `/page/7/`）必须删掉**，否则残留可访问。
- ⇒ "刷新"不是只抓增量：要**重抓前 N 页**（深度）+ **清理超界页**（远端删除）。

---

## 6. 新插件结构（建议）

```
cf-static-hosting/
├── cf-static-hosting.php        # 插件头 + 引导（薄）
├── includes/
│   ├── class-plugin.php         # 单例 + 钩子注册
│   ├── class-config.php         # option 读写（前缀 cfsh_）
│   ├── class-generator.php      # 抓取单页 + 列表页（HTTP GET + host 改写）
│   ├── class-urls.php           # ★ URL 枚举（列表页清单 + 分页 + 深度 + /feed/）
│   ├── class-schedule.php       # ★ 触发策略 + TTL 表（精确/cron/手动）
│   ├── class-path.php           # get_permalink → 相对路径
│   ├── class-publisher.php      # 落盘 + 分发到目标 + 旧页清理
│   ├── class-targets.php        # 目标配置（模板/sanitize/validate）
│   ├── class-media.php          # 媒体同步（泛化到各目标）
│   ├── class-admin.php          # 设置页
│   ├── class-batch.php          # 并发抓取/发布（分批、可中断）
│   └── transports/
│       ├── interface-transport.php
│       ├── class-transport-s3.php      # + sigv4
│       ├── class-transport-local.php
│       ├── class-transport-ftp.php / -webdav.php
│       └── class-transport-git.php     # github/git/gitlab/netlify/vercel
├── views/                       # 设置页 UI
├── assets/                      # CSS/JS
└── tools/                       # CLI 批量
```

**关键**：这个方案下**没有"渲染器"**（不需要内部模板渲染那套复杂度），生成 = 抓取 + 后处理。

---

## 7. 配置项（草案）

| 配置 | 说明 |
|---|---|
| 站点地址（静态站 URL） | 用于 host 改写（把源站域名换成它） |
| 托管目标（多目标） | 复用 CFSE 的模板/能力体系（去掉 cloudflare） |
| 发布行为 | 生成静态（落盘）/ 上传到远端 |
| **列表页深度** | 每个列表抓前 N 页（**默认 1**） |
| **列表页 TTL** | 列表页重抓的间隔（**用户设**，默认 1 天） |
| **cron 兜底开关** | 是否启用 cron 兜底（默认开，但不依赖） |
| **媒体同步策略** | 全量 / 增量（**设置框，用户选**） |
| 并发数 / 批量大小 | 同 CFSE |

---

## 8. 已知限制（文档中需向用户说明）

- **静态站不支持动态功能**：搜索（`/?s=`）、评论提交、表单提交等 —— 由用户/主题自行处理。
- **md 文章**：插件抓的是**前台输出**（忠实还原用户访问的页面）。若前台本身不支持 md 渲染（主题/插件没装），抓下来就是 md 原文 —— 这是**正确行为**（真实还原），不是插件缺陷；md 渲染是用户/主题的事。
- **列表页有 TTL 延迟**：列表页不是"发布即最新"（除非该次发布精确触发了它）；首页是精确刷新的。
- **媒体/图片**：同步策略由用户在设置里选（全量 / 增量）。
- **与 CFSE 不能同站共存**（见 §3）。

---

## 9. 决策记录

**已定（2026-09-17 收敛）**：
- ✅ **抽取范围** = 方向二通用部分 + 方向三（不含 D1 / 双向对齐 / cfse-worker / 风格体系）
- ✅ **抽取方式** = 复制抽取（CFSE 不动）
- ✅ **职责边界** = 忠实还原站点页面，不处理动态功能（仅 host 改写）
- ✅ **生成路径** = 抓取为主，**不要渲染器**
- ✅ **列表页** = 用"抓取 + 调度"代替"渲染复刻"（URL 枚举 + 三层触发）
- ✅ **触发** = 发布/更新即精确刷「该篇 + 首页 + 所属列表页」，**不区分改了什么**
- ✅ **md** = 用户的事（插件只忠实抓前台输出）
- ✅ **feed** = 抓（枚举时多一条 `/feed/`，只第一页）
- ✅ **命名** = 目录/文本域 `cf-static-hosting`、显示名 `CF Static Hosting`、前缀 `cfsh_` / `CFSH_`
- ✅ **首发范围** = **一并做**（单页抓取 + transport + 列表页抓取；前两者复制成熟代码，列表页核心是 URL 枚举 + 调度）
- ✅ **深度 / TTL 默认值** = 深度默认 **1**；TTL **用户设**（默认 1 天）
- ✅ **媒体同步策略** = 设置框（全量 / 增量），用户选

**未定**：无 —— 规划已收敛，可进入实施（搭骨架）。

---

## 10. Worker 私有接口与"两条路"（实施后补充，2026-09-17）

### 10.1 Worker 有**多条**接口（不是只有 D1）

`cfse-worker` transport 走的是 **CFSE 自研的私有接口**（Worker 端 `routes.js` 实现，`X-Auth-Key` 认证）—— **不是 Cloudflare 官方标准**：

| 接口 | 作用 | 方法 |
|---|---|---|
| `/d1-batch` | 批量写 D1（SQL 语句数组） | POST |
| `/upload` | 上传文件到 R2（`{path, content(base64), contentType, contentEncoding}`） | POST |
| `/delete` | 删除 R2 文件（`{paths: []}`） | DELETE |
| `/purge-cache` | 清 KV 缓存（`{keys: []}`） | POST |
| `/sync/posts` | 拉取文章（`?wp_id=` / `?min_wp_id=`） | GET |
| `/style-list` | 列风格资源（`style/` 前缀） | GET |

⇒ **走 Worker 时，D1 与 R2 都能用**（不同接口），不是"只能写 D1、R2 不可用"。

### 10.2 两条路：Worker（自研私有）vs 直连（Cloudflare 官方 API）

| 路径 | 接口 | 认证 | 资源消耗 |
|---|---|---|---|
| **Worker** | `{endpoint}/d1-batch`、`/upload`…（**自研私有**） | `X-Auth-Key` | **Worker 请求配额**（免费 10 万/天，账号级共享） |
| **直连** | `api.cloudflare.com/client/v4/...`（**官方 API**） | `Bearer {API Token}` | **CF API 速率限制**（约 1200 请求/5 分钟） |

- **默认走 Worker**（一次 HTTP，Worker 内近端写 D1/R2）
- **`prefer_direct` 开关**：勾选后**优先直连**（省 Worker 配额；需填「高级选项」凭证）
- **兜底**：非 prefer_direct 时 Worker 失败**自动降级直连**；prefer_direct 时直连失败回退 Worker

### 10.3 直连凭证（cfse-worker 的「▸ 高级选项」，**可不填**）

| 字段 | 用途 |
|---|---|
| `account_id` + `api_token` + `d1_database` | 直连 CF D1 API（`/accounts/{id}/d1/database/{db}/query`） |
| `account_id` + `api_token` + `r2_bucket` | 直连 CF R2 API（`/accounts/{id}/r2/buckets/{bucket}/objects/{key}`，PUT / DELETE） |

不填 ⇒ 只走 Worker（主路径）；填了 ⇒ Worker 挂了也能直连。

**实测验证（2026-09-17）**：endpoint 改不可达 → `cURL error 7` → 降级直连 → **D1 批量 4 条成功、R2 上传/删除成功**（日志确认）⇒ **D1 / R2 直连 API 形态均正确**。

### 10.4 设计：纯静态"两条路" + cfse-worker 特殊

| 目标 | 驱动 | 行为 |
|---|---|---|
| **纯静态**（s3/local/ftp/webdav/git/…） | **全局 `publish_on_save`**（简单） | 发布/更新 → 抓取「该篇 + 首页 + 所属列表页」→ 分发到所有启用的 r2 目标；删除 → 清文件 + 刷列表 |
| **cfse-worker**（自托管 CMS 定制） | **目标级开关**（复杂） | 见下表 |

cfse-worker 目标级开关：

| 开关 | 行为 |
|---|---|
| `sync_d1` | 写 D1（`/d1-batch`，失败降级直连） |
| `gen_static` | 抓取 → **只落盘**（只发 `local` 目标） |
| `publish_remote` | 抓取 → **只上传 R2**（只发非 local 的 r2 目标） |
| `prefer_direct` | 优先直连 CF API（省 Worker 配额） |
| `worker_auto_check` | 编辑页自动比对 Worker 版本 |

要点：
- **md 不特殊处理** —— 统一前台抓取（渲染是用户站点的事）
- **写库与生成/上传解耦** —— `sync_d1` 管写 D1；`gen_static`/`publish_remote` 管文件
- **删除**：cfse-worker 按目标开关（sync_d1→清 D1；gen_static→清 local 文件；publish_remote→清远端文件）；纯静态看全局开关
- **异步**：删除的远端清理攒到 `shutdown` 批量执行（避免同步阻塞卡慢）

---

## 11. 实施记录（v0.1.0，2026-09-17）

- **仓库**：`C:/Users/dog/Documents/cf-static-hosting`（独立 git，与 CFSE 分离）
- **模块**：12 个（主文件 + plugin / config / generator / urls / schedule / targets / publisher / cms / media / deploy / admin）
- **已验证**：语法 12/12；激活（WP `activate_plugin`）；抓取（首页 99866B、host 改写 0 残留、`{{` 残留 0）；发布落盘（index.html + slug/index.html）；**直连兜底（D1 + R2，实测通过）**
- **已删除**：`purge_sidebar`/`purge_lists`（不需要）、「导入 CFSE 配置」功能（不依赖 CFSE）
- **待验证**：`ftp` / `webdav` / `gitlab` / `netlify` / `vercel` 真机（github 在 CFSE 侧已验证）

