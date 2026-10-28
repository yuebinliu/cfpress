# 主文件方向分类清单（拆分第 0 步产物）

> 目的：把 `cf-static-export-pro.php`（357KB / 约 150 个方法）按"本体 + 衍生"归类，
> 让"方向"这个结构在物理拆分前先**在清单里可见**。这是拆分的第 0 步 —— 先看清楚哪些代码属于哪个方向，
> 再决定怎么拆（对应拆分讨论里的"第一步：切分方向边界"）。
> 状态：**分类清单（2026-09-17 初版，含"本体 vs 衍生"认知修正）**，仅文档，未改任何代码。
> 行号以 2026-09-17 的 `cf-static-export-pro.php` 为准。

## ⚠️ 根本前提：本体 vs 衍生（2026-09-17 用户申明，拆分决策的最高优先级约束）

**插件本体最初就是为「自托管 Worker CMS」打造的**（WP 导出 → Worker/D1/R2 全流程）。
**多托管是二次衍生** —— 发现本体已能全站静态化，就把出口开放给第三方用（"何不弄几个 transport 让别人使用，只要把接口弄起来就行"）。

**因此三个方向的真实关系不是平等并列，而是：**

```
方向一（Worker CMS 交互）＝ 本体的核心构成（本源，最初就在）
方向二（WP 静态插件本体）＝ 承载方向一的骨架 + 静态化能力（也是本源）
方向三（通用静态托管）   ＝ 衍生出口（后来加的 transport 层）
```

**散乱的真正来源（用户申明 + 体积演进佐证）**：不是本体结构问题，而是
**多托管 UI 设计过程中，把 Worker CMS 的配置信息与开关控制（平台凭证 / 缓存策略 / 发布行为 / 站点目录）
也塞进了"托管目标模型"**（09-16 的 `a0fad56` → `1c78c6f` → `e846be1` 一条线），
导致本体的配置入口被"目标弹窗"包裹，读代码时要在脑子里分"这到底是本体的还是衍生的"。

**对拆分的含义**：
1. 拆分的**首要目标不是"让三个方向物理可见"**，而是**"把衍生（多托管）从本体（Worker CMS）里剥出去，恢复本体的清晰"**；
2. 方向三（transport）剥离后，本体剩下的就是"WP ↔ Worker CMS"的单向/双向流程 —— 那才是插件的本来面目；
3. 多托管的配置（平台凭证/缓存策略/发布行为）**不该在"目标弹窗"里**，应回到本体的设置区（或独立的"多托管"设置区），
   目标只保留"出口相关"字段（endpoint / auth / path_prefix / 能力）；
4. 这个认知修正后，方向二的"散乱"定性要改 —— 它**不是天生散**，是**被衍生层塞乱的**，剥离后自然恢复。

## 方向定义（本体优先，修正版）

| 方向 | 定义 | 变更频率 | 目标 |
|---|---|---|---|
| **方向一** | **Worker CMS 交互（本体核心）** —— WP 与自托管 CMS（Cloudflare Worker + D1 + R2）的对接 | 低 | 让 WP 能把内容同步到 Worker CMS、从 Worker 拉回、双向对齐 |
| **方向二** | **WP 静态插件本体（骨架 + 静态化能力）** —— 设置页、模板、抓取、生成、发布行为 | 中 | WP 侧的核心静态化能力（承载方向一） |
| **方向三** | **通用静态托管（衍生）** —— transport 实现、部署事务、各家 API | 高 | 把产物发布到任意托管后端（可插拔，别人也能用） |
| **共用层** | 工具函数、日志、配置读取、数据库 | — | 被多个方向复用 |

> **关键判别**：判断一段代码属于方向一还是方向三，问一句 —— **"它是为 Worker CMS 服务的（本体），还是为任意托管后端服务的（衍生）？"**
> 例如：`d1_batch` 是方向一（Worker CMS 的数据层），但它"按能力分派"的壳是方向三（衍生出口也用它）。
> 这个判别贯穿下面所有跨方向方法的归属建议。

## 分类结果总览

```
cf-static-export-pro.php (357KB)
├── 方向一：Worker CMS 交互        ~15 个方法  (4368-4676 集中)
├── 方向二：WP 静态插件本体        ~45 个方法  (分散但可归拢)
├── 方向三：通用静态托管           ~30 个方法  (2507-2833 + 3029-3159 + 3440-3739 + 4890-5508 四块)
├── 共用层：工具/日志/配置/DB      ~25 个方法  (分散)
└── 入口/钩子/管理界面             ~35 个方法  (add_* / handle_* / ajax_*)
```

> 注：入口/钩子/管理界面（`add_admin_menu`、`register_settings`、`settings_page`、各 `handle_*`、各 `ajax_*`）
> 是**接线点**，不是方向本身 —— 它们把三个方向的实现"接"到 WP 生命周期和 UI 上。拆分时它们大概率**留在主文件**（或收进一个 `admin-ui.php`），
> 作为薄壳调用各方向模块。这正是拆分讨论里"接线点收敛"要处理的部分。

---

## 方向一：Worker CMS 交互（~15 个方法）

WP ↔ 自托管 CMS（Worker/D1/R2）的数据对接。**主体集中在 4368-4676**。

| 方法 | 行号 | 说明 |
|---|---|---|
| `fetch_worker_post` | 4368 | 从 Worker 拉单篇 |
| `overwrite_post_from_remote` | 4386 | 用远端版本覆盖 WP 文章 |
| `handle_align_worker` | 4423 | 编辑页"对齐本篇" |
| `ajax_worker_status` | 4539 | 编辑页 Worker 版本状态查询 |
| `pull_posts_from_worker` | 4563 | 批量拉取 Worker 文章 |
| `get_remote_wp_id` | 4633 | 读 `_cfse_remote_wp_id` meta |
| `find_post_by_remote_id` | 4639 | 按远端 ID 找 WP 文章 |
| `apply_worker_terms` | 4649 | 远端分类/标签映射 |
| `handle_pull_worker` | 4676 | 拉取卡入口 |
| `d1_batch` / `worker_d1_batch` | 3666 / 3694 | 写 D1（含 CF API 降级）← **部分属方向一**（见下"跨方向"） |
| `write_to_d1` | 3300 | 写 D1 索引 ← **部分属方向一** |
| `ensure_d1_schema` / `migrate_d1_schema` | 3200 / 3254 | D1 表结构 ← **部分属方向一** |

## 方向二：WP 静态插件本体（~45 个方法）

WP 侧核心静态化能力：设置页、模板、抓取、生成、发布行为。

**设置页 / 管理界面（薄壳 + 接线点，可能留主文件）**

| 方法 | 行号 |
|---|---|
| `add_admin_menu` / `register_settings` / `enqueue_admin_assets` / `settings_page` | 717 / 728 / 788 / 800 |
| `add_export_column` / `render_export_column` / `add_manual_export_button` | 4331 / 4332 / 4343 |
| `add_site_metabox` / `render_site_metabox` / `save_site_meta` / `add_site_bulk_actions` / `handle_site_bulk_actions` | 1878-2012（多站点 UI，跨方向见下） |
| `add_doc_format_metabox` / `render_doc_format_metabox` / `save_doc_format_meta` / `render_markdown` / `md_to_plain` / `render_md_content` | 2015-2155（md 支持） |
| `render_worker_sync_metabox` | 2041（编辑页 Worker 同步面板，**接线点**） |

**生成 / 渲染管线**

| 方法 | 行号 | 说明 |
|---|---|---|
| `generate_post_html` | 2240 | 生成单页 HTML（主入口） |
| `render_with_template` | 2261 | 一级：内部模板渲染 |
| `fetch_from_frontend` | 2318 | 二级：前台抓取 |
| `normalize_source_hosts` | 2349 | URL host 归一化 |
| `generate_fallback_html` | 2384 | 三级：硬编码兜底 |
| `post_process_html` | 2390 | 生成后处理 |
| `is_valid_html` / `html_has_title` | 2430 / 2434 | 校验 |
| `extract_excerpt` | 2440 | 摘要提取 |
| `sync_media_files` / `upload_media_to_r2` | 2454 / 2476 | 媒体同步 |
| `export_post` / `export_post_by_switches` / `do_export_post` | 1472 / 1744 / 1790 | 导出分派（**跨方向**，见下） |
| `build_post_meta` / `build_post_artifact` / `write_artifact_local` | 1563 / 1604 / 1621 | 产物构建 |
| `publish_artifact` / `invalidate_md_single_page` | 1662 / 1731 | 发布（**跨方向**，见下） |
| `generate_post_local` / `publish_post_local` | 1798 / 1818 | 本地生成/发布 |
| `sync_draft_to_d1` | 1775 | 草稿同步 |
| `run_export_mode` | 2155 | 导出模式分派 |
| `export_posts_concurrent` / `handle_bulk_export` / `handle_manual_export` / `handle_auto_export` | 5548 / 5523 / 5514 / 4317 | 并发导出 |
| `collect_published_ids` | 4351 | 收集已发布 ID |

**permalink / 路由**

| 方法 | 行号 |
|---|---|
| `permalink_to_route_pattern` / `permalink_to_storage_pattern` | 4122 / 4158 |
| `handle_sync_routes` / `sync_routes` | 4081 / 4090 |
| `handle_sync_templates` / `handle_deploy_worker` / `deploy_worker` | 4188 / 4204 / 4247 |

**缓存 / 站点信息**

| 方法 | 行号 |
|---|---|
| `purge_page_caches` / `purge_sites_caches` | 2186 / 2170 |
| `purge_site_ctx_option` / `sync_cache_settings` / `sync_site_info` / `sync_site_settings` | 3740 / 3755 / 3774 / 3799 |
| `handle_clear_cache` | 4294 |

**删除**

| 方法 | 行号 |
|---|---|
| `delete_post` / `delete_local_artifact` / `flush_pending_deletes` | 3807 / 3861 / 3884 |
| `delete_from_r2` | 3914（**跨方向**，见下） |

**风格/主题资源**

| 方法 | 行号 |
|---|---|
| `handle_sync_style_assets` / `sync_style_assets` | 3939 / 3953 |
| `handle_stage_theme_assets` / `stage_theme_assets` | 3989 / 4003 |
| `scan_theme_dir` / `rrmdir` | 4045 / 4065 |

## 方向三：通用静态托管（~30 个方法）

把产物发布到任意托管后端。**四块集中区**：2507-2833（模板/目标）、3029-3159（分派）、3440-3739（s3/签名/d1）、4890-5508（各 transport 部署）。

**托管目标 / 模板体系**

| 方法 | 行号 |
|---|---|
| `get_hosting_templates` | 2507 |
| `normalize_intent` / `intent_requires` | 2648 / 2662 |
| `validate_hosting_targets` / `render_adjustments_notice` | 2680 / 2795 |
| `default_worker_target` / `get_hosting_targets` | 2810 / 2833 |
| `site_dir_of` / `site_local_root` / `default_site` / `site_by_id` | 2920 / 2927 / 2932 / 2941 |
| `get_post_sites` / `get_post_site_ids_raw` | 2954 / 1867（多站点，跨方向） |
| `for_each_target` | 2981 |
| `sanitize_hosting_targets` / `render_hosting_targets_table` / `render_hosting_target_row` / `render_cf_platform_fields` / `is_first_cloudflare_target` | 245 / 421 / 549 / 497 / 484 |

**分派 / 上传 / 删除**

| 方法 | 行号 |
|---|---|
| `worker_request` | 3004 |
| `target_upload` / `target_delete` / `target_public_url` / `target_purge` / `target_dir` / `target_type` | 3029 / 3076 / 3133 / 3150 / 3652 / 3658 |
| `upload_to_r2_via_worker` | 3159 |

**s3 / 签名**

| 方法 | 行号 |
|---|---|
| `sigv4_uri_encode` / `sigv4_canonical_query` / `sigv4_sign` | 3440 / 3460 / 3609 |
| `s3_creds` / `s3_path_style` / `s3_classify` / `s3_request` / `s3_test_connection` | 3471 / 3494 / 3509 / 3533 / 3592 |

**transport 部署实现**

| 方法 | 行号 | transport |
|---|---|---|
| `github_api` / `github_blob_sha` / `github_deploy` | 4890 / 4932 / 4937 | github（含 git 复用） |
| `netlify_deploy` / `netlify_api` | 5083 / 5128 | netlify |
| `vercel_deploy` / `vercel_api` | 5150 / 5213 | vercel |
| `gitlab_deploy` / `gitlab_api` | 5235 / 5451 | gitlab |
| `ftp_deploy` / `ftp_mkdirs` | 5316 / 5365 | ftp |
| `webdav_deploy` / `webdav_mkdirs` / `webdav_encode` / `webdav_request` | 5377 / 5413 / 5429 / 5429 | webdav |
| `target_deploy` / `deploy_prepare` / `is_deploy_target` / `deploy_site_to_deploy_targets` | 5059 / 5071 / 5478 / 5483 | 部署分派 |

**整站发布（出口层）**

| 方法 | 行号 |
|---|---|
| `allowed_site_roots` / `is_forbidden_site_dir` / `resolve_site_dir` | 4724 / 4729 / 4742 |
| `site_file_list` / `mime_for_path` | 4761 / 4794 |
| `publish_site_dir_chunk` / `ajax_publish_site` / `handle_deploy_git` | 4816 / 4872 / 5496 |

## 共用层（~25 个方法）

| 方法 | 行号 | 说明 |
|---|---|---|
| `get_instance` / `__construct` | 84 / 91 | 单例 / 构造 |
| `ensure_worker_files` / `generate_initial_file` | 111 / 123 | 初始文件 |
| `init_hooks` | 133 | 钩子注册（**接线点**） |
| `load_config` / `get_config` | 178 / 224 | 配置 |
| `register_cron_jobs` | 231 | cron |
| `get_export_stats` / `get_recent_logs` / `log` | 1402 / 1416 / 1424 | 统计 / 日志 |
| `get_post_storage_path` | 1436 | 存储路径（**跨方向**） |
| `get_post_terms` / `d1_execute` / `d1_query` | 3369 / 3379 / 3409 | D1 查询 |
| `increment_exported_count` | 4289 | 导出计数 |
| `is_worker_owned_post` | 1651 | Worker 归属判定（**跨方向**） |
| `get_content_format` | 2102 | 格式读取 |
| `get_stats_ajax` / `deactivate` | 4306 / 4312 | 统计 / 停用 |
| `handle_seed_admin` / `test_connection` | 5651 / 5681 | 初始化 / 连通性 |
| `ajax_test_targets` | 4453 | 测试连通性 AJAX（**接线点**） |
| `cfse_cli_php` | 5572 | CLI 入口 |

---

## 跨方向方法（拆分时需先决定归属 —— 这是清单最有价值的部分）

以下方法**同时服务两个方向**，是"三个方向能否切清"的试金石：

| 方法 | 行号 | 服务的方向 | 拆分建议 |
|---|---|---|---|
| `publish_artifact` | 1662 | **方向二 + 方向三** | 它既写本地产物（方向二）又上传远端（方向三）。建议：保留"编排"身份在主文件，内部调用方向三的 `target_upload`；或拆成"发布编排"+"目标分派"两层 |
| `delete_from_r2` | 3914 | **方向二 + 方向三** | 既删本地（方向二）又删远端（方向三）。同上 |
| `export_post` / `export_post_by_switches` / `do_export_post` | 1472 / 1744 / 1790 | **方向二 + 方向三** | 导出编排同时触发生成（二）与上传（三）。同上：编排留主文件，动作调模块 |
| `d1_batch` / `worker_d1_batch` / `write_to_d1` / `ensure_d1_schema` / `migrate_d1_schema` | 3666 / 3694 / 3300 / 3200 / 3254 | **方向一 + 方向三** | D1 既是"Worker CMS 的数据层"（方向一）也是"目标能力 d1"（方向三）。建议：D1 写库归方向一（Worker CMS 专属）；`d1_batch` 的"按能力分派"部分归方向三 |
| `get_post_storage_path` | 1436 | **方向二 + 方向三** | 既算本地路径（二）又算 R2 key（三）。建议：拆两个函数或加参数 |
| `is_worker_owned_post` | 1651 | **方向一 + 方向二** | WP 导出时判断"这篇是不是 Worker 端诞生的"（防覆盖）。建议：归方向一（它是 Worker CMS 的归属语义），方向二调用 |
| `get_post_sites` / `get_post_site_ids_raw` | 2954 / 1867 | **方向一 + 方向二 + 方向三** | 多站点归属，横跨全部方向。建议：单独抽成"站点域"（或放共用层），因为多站点是"站点即目标"的枢纽 |
| `sync_media_files` / `upload_media_to_r2` | 2454 / 2476 | **方向二 + 方向三** | 媒体同步既走 R2（三）又是生成管线的一部分（二） |
| 设置页里的内嵌 JS（`cfseFillCache` / `bindDetail` / `applyTemplate` 等） | 465-1400 | **接线点** | 设置页 UI 的 JS 是纯接线点，拆分时整块留主文件或收进独立 JS 文件 |

---

## 体积演进证据（git 实测，佐证"散乱来自衍生层"）

`cf-static-export-pro.php` 行数随批次变化：

| 节点 | 行数 | 增量 | 事件 |
|---|---|---|---|
| `e7f4cdd`（09-11 基线） | 1888 | — | 本体基线（为 Worker CMS 打造） |
| `135b45d`（批1 生成/上传拆分） | 2164 | +276 | 拆动作（本体能力） |
| `bb7ed78`（UU 批 第三方入口） | 2622 | +458 | 写入口（Worker CMS 方向） |
| `f0caa35`（批AK 多托管抽象） | 2937 | +315 | **多托管起步（衍生）** |
| `27bed72`（批BM targets UI） | 3229 | +292 | **targets 表格（衍生 UI）** |
| `33b6cea`（批BQ s3+local） | 3546 | +317 | transport（衍生） |
| `e58fe23`（09-15 配置上下文） | 3879 | +333 | 设计 |
| `b518f78`（阶段一模板化） | 3605 | -274 | 收敛（模板化反而减了） |
| `457c9c6`（09-16 多站点定稿） | 5049 | **+1444** | **多站点设计（衍生×多站点）** |
| `a0fad56`（UI 分家） | 5438 | +389 | **自托管收卡（配置塞衍生）** |
| `1c78c6f`（平台凭证进弹窗） | 5435 | -3 | **配置塞弹窗（衍生 UI）** |
| `a21b83a`（09-17 最新） | 5701 | +266 | 设置页收尾 |

**结论**：
- 本体基线 1888 行；**多托管/多站点/UI 塞配置 = +3813 行（净增 2 倍）**。
- **两段暴增主因**：`457c9c6` 多站点（+1444）与 `a0fad56`→`a21b83a` 配置塞弹窗（约 +660）。
- 这正好对应用户申明：**"后来想把 Worker CMS 的配置信息及开关控制也纳入模型里才越搞越乱"**。

## 关键结论（供拆分决策，本体优先修正版）

1. **方向三（transport）边界最清晰**，四块集中区（模板/分派/s3/部署），实现函数可干净搬进 `transports/`。
   → 印证拆分讨论"先拆方向三"的判断。
2. **方向一（Worker CMS 交互）是本体的核心构成**（4368-4676 一带 + D1 写入），可拆成 `worker-cms.php` 或 `sync.php`。
3. **方向二（WP 静态插件本体）不是天生散乱，是被衍生层（多托管 UI）塞乱的** —— 剥离后自然恢复。
   剥离的具体动作：**把平台凭证/缓存策略/发布行为/站点目录从"目标弹窗"里拿出来，回到本体的设置区**（或独立"多托管"设置区），
   目标只保留出口相关字段（endpoint / auth / path_prefix / 能力）。
4. **真正的难点是 8 个跨方向方法 + 大量接线点**。这些不先定归属，拆完会有"不知道该放哪"的代码。
5. **接线点（add_* / handle_* / ajax_* + 设置页内嵌 JS）应留主文件作为薄壳** —— 这与拆分讨论"接线点收敛成声明式"互补：接线点收敛 = 把散在各处的分派逻辑收成一张表/一组回调，而不是收进某个模块。

## 建议的下一步（本体优先）

- [ ] **先做"配置归属还原"**：把 Worker CMS 的配置（平台凭证/缓存策略/发布行为/站点目录）从目标弹窗移回本体设置区 —— 这是"剥离衍生、恢复本体"的第一步，纯 UI 重构，不碰逻辑
- [ ] 对 8 个跨方向方法逐一拍板归属（可以开会讨论，也可以我逐行分析后给建议）
- [ ] 确定"接线点收敛"的形态（声明式路由表？按 transport 的注册表？）
- [ ] 之后才动手拆方向三（transport）—— 见 `docs/transports.md` 的五处同步清单
