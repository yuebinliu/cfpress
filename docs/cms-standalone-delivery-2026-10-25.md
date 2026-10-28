# CMS 独立化 + 插件重构 —— 交付报告

> 日期：2026-10-25
> 范围：`cf-cms`（Worker CMS）+ `cfse-sync`（WordPress 同步插件）
> 生产 Worker：`wp-static-pro`（`yuebin.uk`），Version `47a24c67-aca1-4f4c-a367-cfe2188efe61`

---

## 一、这一轮做了什么

大魔王拍板：「把 CMS 独立出来，砍掉内置文档库」→ 完成；「同步插件混杂第三方托管接口，只需要和 CMS 同步的部分，UI 重新编排」→ 本轮完成。

### 起点与终点

| | 起点 | 终点 |
|---|---|---|
| CMS 代码 | 旧仓 `cf/worker/` 里的一份副本 | 独立仓 `cf-cms`，19 模块 / 7025 行 |
| 文档库 | 565 行渲染引擎 + 8 个 API 端点 + 后台 UI | **整体删除**（-1441 行，打包 -13%） |
| 插件 PHP | 4852 行，无 git 仓 | 5337 行，7 个 commit 的独立仓，1.4MB |
| 插件定位 | 「同步 + 静态托管 + 模板编辑器」混合体 | **CMS 的 WordPress 客户端** |

---

## 二、CMS 侧（`cf-cms`）

### 砍掉内置文档库

删除约 **1441 行**：

| 位置 | 内容 |
|---|---|
| `src/documents.js` | 文档库渲染引擎（565 行，整文件） |
| `src/routes.js` + `routes.template.js` | 文档库路由分支（各 65 行） |
| `src/admin.js` | 8 个 `/api/documents*` 端点（302 行）+ DDL + `purgeDocArtifacts` + 设置分支 |
| `src/site.js` | `docRoutes` 透传 |
| `static/admin/app.js` | `renderDocuments()`（345 行）+ NAVS + 两处视图数组 |
| `static/admin/index.html` | 导航项与视图容器 |
| `schema.sql` | `documents` 表 DDL |

### 数据清理

| 对象 | 处置 |
|---|---|
| D1 `documents` 表（46 行） | **DROP** |
| D1 `settings k='site'` 的 `docRoutes` | **已清**（WP 的 `Object.assign` 合并写入，你永远清不掉它，必须显式清） |
| R2 文档库 html 成品（47 个） | **已删**（静态分支正则 `html?` 匹配 ⇒ 只删代码不清 R2 = 功能下线、内容照旧在线） |
| R2 顶层目录页（17 个） | **已删** |
| KV `site:ctx` | **已删** |
| R2 md 原稿（46 个） | 保留（静态正则不含 `.md` ⇒ 天然不可达，作素材保险） |

**备份**：`C:\Users\dog\Documents\cf-docs-backup\2026-10-25-orphan-r2\` —— 92 个对象，md5 全部校验通过。

### URL 结构运行时化

**问题**：以前 `routes.js` 里的路由正则是编译期常量（WP 插件生成）。两个后果：
1. 在 CMS 后台改 URL 结构**不生效**（路由层读的是编译进去的常量）
2. 插件点「部署 Worker」会重新生成，把线上 URL 结构**改回 WP 的 permalink**

**现在**：新增 `src/routepattern.js`（`patternToRouteRegex` / `fillStoragePattern` / `matchSinglePage`），路由规则运行时从 D1 `settings k='site'` 的 `storagePattern` 推导。

**删除 `routes.template.js`** —— 不再需要生成链，`routes.js` 成为手写真源。

效果：改 URL 结构 → CMS 后台改完**即刻生效**，不部署、不需要插件参与。

### 安全收窄

| 改动 | 之前 | 现在 |
|---|---|---|
| `/d1-batch` | 任意 SQL 执行 | 表分层白名单（读/写/改结构），禁 PRAGMA/ATTACH/多语句，批次上限 200→50 |
| 建账号/重置密码 | 走 `/d1-batch`（能读全部密码哈希） | 专用端点 `POST /sync/users`，SQL 模板写死在服务端 |
| `/sync/site-info` | 接受 `storagePattern` 与 `url` | 都不接受（`storagePattern` 归 CMS 后台管；`url` 语义与 WP 的 `cf_site_url` 冲突） |
| 新增 | — | `GET /sync/site-info`（只读，返回 `storagePattern`） |

### 顺带修的 bug

- **`schema.sql` 与 `ensureCoreSchema()` 漏了 i18n 的两列**（`lang` / `translation_key`）—— i18n 迁移是单独 ALTER 的，建表语句没同步。缺列 ⇒ 分类查询报错 ⇒ **所有分类计数变 0**。
- **`fillStoragePattern` 缺值时把占位符替换成空串** ⇒ 生成 `index.html` 指向根目录的错误路径且无报错。

### 测试

**127 项断言全过**：

| 文件 | 项数 | 覆盖 |
|---|---|---|
| `test/routepattern.test.mjs` | 36 | 推导逻辑 + 与旧编译期行为的一致性回归 |
| `test/sqlguard.test.mjs` | 44 | 危险 SQL 拦截 |
| `test/wp-sql-compat.test.mjs` | 34 | **WP 侧源码里真实抽出的 SQL 必须全放行** |
| `test/schema-i18n.test.mjs` | 13 | schema 与 ensureCoreSchema 对齐 |

---

## 三、插件侧（`cfse-sync`）

### 砍掉第三方 provider

`includes/class-transports.php`（1037 → 727 行）：

- **删**：8 个 S3 方法（`sigv4_*` / `s3_*`，-208 行）、`target_dir`、三个分派函数里的 local+s3 分支（-68 行）、s3 模板（-13 行）、sanitize 的 s3 分支（-9 行）
- **保留**：多目标抽象层（`for_each_target` / `worker_request` / `target_upload` / `target_delete` / `target_purge` / `get_hosting_targets` / `sanitize` / `validate`）

> **关键判断**：这些不是「静态托管」的活，是「向 CMS 写数据」的通道。多 CMS 实例（一套 WP 同步到多个自托管 CMS）是合理场景。
> **砍掉的是 provider 种类（不是目标数量）。**

`cfse-sync.php`（1615 → 1523 行）：删 10 个同名转发方法 + `ajax_test_targets` 里 local/github/s3 三段死分支。

### 断掉 routes 生成链

删 `sync_routes()` / `handle_sync_routes()` / `permalink_to_route_pattern()` / admin_post 注册 / UI 按钮。

**WP 侧也不再推 `storagePattern`** —— 以前 WP 每次「同步站点信息」都把 WP 的 permalink 推上去，把用户在 CMS 后台改好的结构覆盖回去。

### 发行包机制（方案 B，双源）

**CMS 侧**新增 `scripts/build-release.mjs` —— 手写 tar + gzip，零 npm 依赖。

**为什么是 tar.gz 不是 zip**：实测测试服务器 **`ZipArchive` 扩展没装**（`class_exists` 返回 false），而 `PharData` 是 PHP 自带的、普遍可用。

**体积 14.9MB → 342KB（-97.7%）**，两个原因叠加：
1. gzip 真压缩（tar 1811KB → 342KB）
2. **排除 5 个抓来的 WP 主题**（twentyfifteen / saaslauncher / twentyeleven / twentynineteen / twentyten，共 12.8MB）—— 那是 `cfse-style` 的产物，风格包由用户在 CMS 后台自己上传，不该让每个下载者背这个重量。同时自动过滤 `themes/index.json` 里对应的条目。

**插件侧**新增 `includes/class-release.php`：

| 源 | 地址 | 备注 |
|---|---|---|
| ① GitHub Releases | `api.github.com/repos/<owner>/<repo>/releases/latest` | 找 `.tar.gz` 资产 |
| ② 官网 | `<配置项>/downloads/cf-cms-latest.tar.gz` | 默认 `https://yuebin.uk`（**可配置项**，换域名不改代码） |
| ③ 固定生产地址 | `wp-static-pro.yuebinliu.workers.dev/downloads/...` | 第三备源 |

带 gzip 魔数校验（防把 404 HTML 页当包存下）+ 体积上限 20MB + 关键文件校验。

### 删除内嵌的 worker/ 副本

**336 个文件**（2.2MB → 1.4MB）。

为什么必须删：它是**逐字节拷贝**，早晚漂移。2026-10-25 真的漂了 —— CMS 仓删掉的 `documents.js` 还在插件副本里，部署一次就把已砍掉的文档库推回线上（老 Worker 的 `ensureCoreSchema()` 还重建了已 DROP 的 `documents` 表）。

删后 `resolve_source_dir()` **只有发行包**一条路，没下载就拒绝部署 —— 不再有任何「悄悄用旧副本」的路径。

### UI 重排为三屏状态机

新增 `includes/class-onboarding.php` + `assets/cfse-onboarding.css`。

| 状态 | 判定 | 显示 |
|---|---|---|
| **未连接** | 没填 CMS 地址 | 三步引导 + 「⬇️ 下载 CMS 发行包」按钮（**主路径**）+ 图文教程（次要按钮） |
| **已配置** | 填了地址没测过 | 显示已填的地址与密钥 + 「🔌 测试连接」 |
| **已连接** | 测通过 | 显示 CMS 地址，引导去同步内容 |

**基础层永远可见，高级层默认折叠**：👤 Worker 后台账号 / 🚀 部署 CMS / 🏗 站点列表（多 CMS 才需要）三个区块改成 `<details>`。

新增 `wp_ajax_cfse_test_connection`：只测主目标，测通即记状态；支持传入**未保存**的 endpoint/auth_key（填完不用先保存就能测）。

### 修的两个真 bug

**① WP 与 CMS 算 R2 路径不一致** —— WP 用自己的 permalink 算，CMS 用 D1 的 `storagePattern` 算。用户改 URL 结构后两边算出不同路径 ⇒ WP 删不到旧单页 ⇒ Worker 命中 R2 直接返回、不回查 D1 ⇒ **文章更新不生效**。

修法：CMS 加 `GET /sync/site-info`（只读），WP 侧 `get_remote_storage_pattern()` 拿它（transient 缓存 5 分钟），拉不到才退回本地推导。

**② `default_worker_target()` 回落到 `get_site_url()`** —— 用户没填 CMS 地址时，默认目标回落到**本站 WordPress 的地址**：
- 状态机误判成「已配置」而不是「未连接」
- 同步会把自己的 WP 站当成 CMS 去请求

修法：没填就是空串（= 未连接）。

### 离线验证

新增 `test/verify-onboarding.php`（14 项断言）—— **不激活插件、不碰数据库**，用 WP 函数桩跑逻辑：

```
【① 状态判定】none / configured / connected 三态
【② 状态迁移】mark_connected / mark_unconnected
【③ get_primary】单表单优先于目标列表
【④ 目标列表对齐】第一个目标与单表单一致
【⑤ 发行包三源】GitHub + 直链
【⑥ 源码目录解析】
```

> 第一版跑出 1 项失败，正是上面那个 bug（②）—— 断言拦住了它。

---

## 四、线上终检

| 项 | 结果 |
|---|---|
| 前台页面 | **25/25 全 200**（含 `/en/`、feed、搜索、分类标签、全部 15 篇文章） |
| 文档库页面 | **4/4 全 404** |
| 模板残留 | 0 |
| 写通道 | `/style-list` 200 · `/purge-cache` 200 · `GET /sync/site-info` 200 |
| 后台 API | 未登录全 401 |
| 新旧 Worker 对比 | 13/13 逐字节相同 |

**回滚点**：`9b00e07c-20c6-405a-8efa-943778a5b069`（本轮切流前的版本）

---

## 五、这轮踩的坑（都记进两份 AGENTS.md）

1. **只删 R2 / DROP 表不够** —— 跑着老代码的 Worker 会**重建**（文档库路由重渲染成品；`ensureCoreSchema()` 重建表）。⇒ **必须先切代码再清数据**。（切流后 `documents` 表真的又被测试 Worker 建了出来）
2. **Cloudflare API 对含空格/特殊字符的 R2 key 返回 403** ⇒ 改走 Worker 的 `/delete` 端点（不同网关，支持批量）。
3. **WAF 按 User-Agent 拦 Python 的 `Python-urllib`**（403 code 1010）⇒ 同请求 curl 通、urllib 不通 ⇒ 脚本改用 curl。
4. **PHP 没装 `ZipArchive` 扩展** ⇒ 发行包改 tar.gz（`PharData` 是 PHP 自带的）。
5. **发行包体积 14.9MB** ⇒ 5 个抓来的 WP 主题占 12.8MB ⇒ 排除 + 同步过滤 `themes/index.json`。
6. **删代码块后必须做括号配平检查** —— `node --check` / `php -l` 查不出「留下孤儿代码」（语法合法但运行时 ReferenceError）。
7. **`workers.dev` 在本地网络被 DNS 污染** ⇒ 验证走自定义域名。
8. **`wrangler` 3.x 的 `kv key delete` 不认 `--remote`** ⇒ 用 `--binding=KV_CACHE`。
9. **`wrangler deploy -c <另一文件名>` 会让 `main` 路径解析错** ⇒ 直接改 `wrangler.toml` 的 `name`。
10. **Python heredoc 里 emoji 报 SyntaxError** ⇒ 改用 Write 工具写脚本文件再执行。
11. **PHP 括号配平检查会误判 admin-ui**（HTML/JS 输出里的 `{}` 被计入）⇒ 以基线版对比为准。

---

## 六、待办

1. **发行包要真正发布出去** —— 现在打包脚本有了，但 GitHub 仓库名（当前默认 `cfse/cf-cms`）与官网路径（`/downloads/cf-cms-latest.tar.gz`）还没定。**需要大魔王拍板。**
2. **插件还没在服务器上激活过** —— 服务器现在激活的是 `cf`（全功能版）。要让 `cfse-sync` 真正跑起来，得停用 `cf`、启用 `cfse-sync`。
3. **两个仓都没有 git remote** —— `cf` 与 `cf-cms` 要发版得先建 remote。
4. **安全基线还有两项没做** —— 密钥分离（`JWT_SECRET` vs `SYNC_KEY`）、md 正文消毒。
5. **同步插件改造还剩** —— `cf` 仓（全功能版）那边的 transports 与 routes 生成链还在，需要么退役、要么同样处理。
