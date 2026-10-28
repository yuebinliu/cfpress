# 配置归属还原方案（拆分第 1 步：剥离衍生、恢复本体）

> 状态：**方案待确认（2026-09-17）**，未实施。
> 目标：把 Worker CMS 的配置（平台凭证 / 自托管运行时 / 缓存策略 / 站点地址 / Auth Key）从"托管目标弹窗"里拿出来，
> 回到本体的设置区 —— 这是"剥离衍生（多托管）、恢复本体（Worker CMS）"的第一步，纯 UI 重构，不碰任何业务逻辑。

## 现状（2026-09-17 代码核实）

设置页当前结构（`settings_page()`，左列基础配置卡 + 右列状态面板）：

```
左列「⚙️ 基础配置」卡（cf-static-export-pro.php:830-1161）
├── form（options.php）
│   ├── 导出模式 / 单页缓存头 / 批量大小 / 列表每页 / 重试 / 并发（本体公共项）
│   ├── 发布行为（gen_static / publish_remote —— 本体）
│   ├── 导出与渲染（clean_scripts / logging / compression / debug —— 本体）
│   └── 🎯 托管目标卡（874-1157）
│       ├── 目标表格（render_hosting_targets_table → render_hosting_target_row）
│       └── ⚙️ 配置弹窗（每个目标一个 modal）
│           ├── 名称 / 模板 / id / site_dir（本地保存目录）
│           ├── 站点地址 endpoint
│           ├── ☁️ Cloudflare 平台（Account ID / API Token / R2 Bucket / D1 ID）── ⚠️ 只在第一个 cf 目标弹窗
│           ├── ⚙️ 自托管运行时（sync_d1 / purge_sidebar / purge_lists / worker_auto_check）── ⚠️ 同上
│           ├── 缓存策略（5 个 TTL/feed + 预设按钮）── ⚠️ 同上
│           ├── Auth Key（workerkey）── ⚠️ 每个 cf 目标各一份
│           ├── 路径前缀（所有目标）
│           └── 各 transport 凭证（s3/github/netlify/vercel/git/gitlab/ftp/webdav 各家字段）
└── 保存按钮
```

**问题（用户申明的"越搞越乱"的实锤）**：
1. **平台凭证（Account ID/API Token/R2 Bucket/D1 ID）藏在"第一个 Cloudflare 目标的 ⚙️ 配置"里** ——
   用户不知道去哪填，且删掉那个目标就"丢"了配置入口；
2. **自托管运行时 + 缓存策略**（Worker CMS 的核心开关）同样藏在目标弹窗里 —— 纯静态用户根本不该看到它们，自托管用户却要"点进目标配置"才能改；
3. **Auth Key** 每个 cf 目标弹窗各一份，容易填错/改错；
4. **站点地址 endpoint** 既在目标里（每目标一个）又隐式同步为全局 `cf_site_url`，语义混乱。

## 方案：配置归属还原（三步）

### 第 1 步：把"Worker CMS 专属配置"从目标弹窗移到本体设置区（本方案核心）

新增一个本体卡片 **「☁️ 自托管 CMS（Cloudflare Worker + R2 + D1）」**，放在「托管目标」卡**之前**，包含：

| 字段 | option | 说明 |
|---|---|---|
| 站点地址（Worker 域名） | `cf_site_url`（恢复可见） | 本体主配置，不再从目标 endpoint 同步 |
| Account ID | `cf_account_id` | 平台凭证 |
| API Token | `cf_api_token` | 平台凭证 |
| R2 Bucket | `cf_r2_bucket` | 平台凭证 |
| D1 Database ID | `cf_d1_database` | 平台凭证 |
| Auth Key | `cf_auth_key`（恢复可见） | Worker X-Auth-Key |
| 保存文章时同步到 D1 | `cf_sync_d1` | 自托管运行时 |
| 发布后即时刷新侧边栏 | `cf_purge_sidebar` | 自托管运行时 |
| 发布后即时刷新列表页第一页 | `cf_purge_lists` | 自托管运行时 |
| 编辑页自动比对 Worker 版本 | `cf_worker_auto_check` | 自托管运行时 |
| 缓存策略（5 个 TTL/feed + 预设按钮） | `cf_cache_ttl_*` / `cf_feed_count` | 缓存策略 |

**这些字段原本就是全局 option**（`register_settings` 已注册），只是渲染位置错了 —— 移回即可，**存储格式 / 读取逻辑 / 同步钩子全都不变**。

### 第 2 步：目标弹窗瘦身（只留"出口相关"）

每个目标的 ⚙️ 配置弹窗**只保留**：
- 名称 / 模板 / id
- 本地保存目录 site_dir（多站点）
- 站点地址 endpoint（各目标自己的地址；**不再隐式同步全局 cf_site_url**）
- Auth Key（**从目标弹窗移除**，只在本体卡；目标不再各自一份）
- 路径前缀
- 各 transport 凭证（s3/github/netlify/vercel/git/gitlab/ftp/webdav 各家字段）

### 第 3 步：逻辑不变（关键保证）

- `register_settings` 的 option 清单**一字不改**
- `sanitize_hosting_targets()` / `get_hosting_targets()` **一字不改**
- `render_cf_platform_fields()` 整段**从目标弹窗移除**（它的全局字段移到本体卡）
- `is_first_cloudflare_target()` 不再需要（可删或留空）
- 同步钩子（`sync_cache_settings` / `sync_site_info` / `sync_site_settings`）**不动**
- 行为完全等价，只是配置的**填写位置**变了

## 涉及改动

| 文件 | 改动 |
|---|---|
| `cf-static-export-pro.php` | ① `settings_page()`：新增「☁️ 自托管 CMS」卡（本体字段），放在托管目标卡前；② `render_hosting_target_row()`：弹窗里移除平台凭证/自托管运行时/缓存策略/Auth Key 块；③ `render_cf_platform_fields()`：改为渲染到本体卡（或删除、字段内联进新卡）；④ `is_first_cloudflare_target()` 移除调用 |
| 内嵌 JS | 弹窗模板联动（`TPL_BLOCKS`）里 `cfse-f-cloudflare` 块只剩 endpoint/Auth Key 相关，联动逻辑同步精简 |

## 验证

1. `php -l` 语法通过
2. 设置页渲染：本体卡出现全部 11 个字段；托管目标弹窗不再含平台凭证/缓存策略/Auth Key
3. 保存链路：改缓存策略 TTL → D1 cache 行更新（钩子照常）；改 site_url → 同步 site 行
4. 目标删除后：平台凭证仍在本体卡（不再"丢配置入口"）

## 风险与说明

- **纯 UI 重构**，不碰存储/逻辑/同步 —— 风险极低
- 唯一行为变化：**Auth Key 从每目标一份变为全局一份**（语义更正确 —— X-Auth-Key 本来就是全局 Worker 的）
- `cf_site_url` 从"隐藏 + 自动同步"恢复为"可见可编辑"（本体主配置，用户应能看到）
