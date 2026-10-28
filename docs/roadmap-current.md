# 当前路线（**压缩/新会话后先看这一份**）

> 本文件是"往哪个方向走"的导航图。细节见 `docs/main-file-split-plan.md`（拆分）与
> `docs/settings-ui-refactor.md`（设置页 UI 重构）。

---

## 🎯 目的（为什么要做这两件事）

让这个插件**能长期维护下去**。具体两件事，**顺序不能反**：

1. **拆掉 380KB 单文件**（`cf-static-export-pro.php`，现 6154 行 / 198 个函数）
   —— 因为它 **>31KB ⇒ 本项目的 `edit_file` 用不了**（read-evidence 死锁，见 AGENTS.md），
   每次改动都得"写 python 脚本 + 同回合 bash 执行"，慢且容易出错。
   **拆小之后每个文件都能直接编辑** ⇒ 工具链立刻顺畅。这是 Phase A 的真正理由。
2. **把设置页按"两个大类"重排**（`发布整站到托管商（纯静态）` / `同步到托管 CMS`）
   —— 现在动作藏在「📊 状态面板」的嵌套卡里、三个开关散在三处，用起来找不到东西。

**为什么先拆再改 UI**：UI 重构要搬很多 HTML 块（`settings_page()` 本身 660+ 行），
在 380KB 文件里做，每一步都要"python 脚本 + lint + 同步"，风险高、迭代慢；
**先把它拆成独立小文件（<31KB），之后就能直接 `edit_file`**。

---

## Phase A：拆分（进行中）

| Step | 内容 | 状态 |
|---|---|---|
| **0** | **盘点**：198 函数 / 6154 行；关键锚点已定位 | ✅ **完成 2026-09-25** |
| **1** | `includes/class-transports.php` —— 托管目标 + 各传输实现 | 🔄 **进行中**：1a 已搬（`817f2c7`）|
| 2 | `includes/class-admin-ui.php` —— 设置页 + metabox | ✅ **完成**（2a 11 方法 + 2b 7 方法；`settings_page()` 渲染 124047B 与搬前完全一致，metabox 探针全过）|
| 3 | `includes/class-worker-cms.php` —— D1 写入 / 拉取 / 对齐 / 评论同步 | ⬜ |
| 4 | `includes/class-generate.php` —— 生成与渲染管线 | ⬜ |
| 5 | `includes/class-db.php` + `helpers.php` —— 共用层 | ⬜ |
| — | **接线点（`add_action` / `add_filter` / `ajax_` / `activate`）始终留主文件** | — |

### Step 0 盘点结果（行号 2026-09-25 实测；⚠️ 会漂移，动手前重新 grep）

| 目标文件 | 应搬的函数（行号）|
|---|---|
| **class-transports** | `sanitize_hosting_targets` :266 · `render_hosting_targets_table` :442 · `render_cf_platform_fields` :518 · `render_hosting_target_row` :562 · `get_hosting_templates` :2614 · `get_hosting_targets` :2940 · `target_upload` :3136 · `target_delete` :3186 · `s3_request` :3649 · `sigv4_sign` :3725 · `target_deploy` :5511 |
| **class-admin-ui** | `add_admin_menu` :730 · `register_settings` :741 · `settings_page` :835 →（约至 :1660，**660+ 行**）|
| **class-generate** | `build_post_meta` :1668 · `build_post_artifact` :1711 · `publish_artifact` :1769 · `generate_post_html` :2347 · `post_process_html` :2497 |
| **class-db** | `ensure_d1_schema` :3311 · `write_to_d1` :3414 · `d1_execute` :3495 · `d1_query` :3525 · `d1_batch` :3782 |
| **class-worker-cms** | `fetch_worker_post` :4820 · `pull_posts_from_worker` :5015 · `sync_site_info` :3890 · `sync_cache_settings` :3871 · `delete_post` :4255 |
| **helpers** | 工具/日志/配置类函数；`init_hooks` :133 **留主文件**（接线点）|

⚠️ 三分之一的函数**不连续**（分散在各区）⇒ **逐个搬**，不是"切一刀"。

### Step 1 进展（分批搬，每批独立验证）

| 批次 | 方法 | 状态 |
|---|---|---|
| **1a** | `sigv4_uri_encode` `sigv4_canonical_query` `s3_creds` `s3_path_style` `s3_classify` `s3_request` `s3_test_connection` `sigv4_sign` `target_dir` `target_type`（原 `:3556-3776` 连续块）| ✅ `817f2c7` |
| **1b** | `get_hosting_templates` `get_hosting_targets` `default_worker_target` `sanitize_hosting_targets` `normalize_intent` `intent_requires` `validate_hosting_targets` `render_adjustments_notice` `for_each_target` `worker_request` `target_upload` `target_delete` `target_public_url` | ✅ `1e3e08d` |
| **1c** | `target_deploy` + 6 个 transport 部署 + 15 个依赖（`deploy_prepare` `resolve_site_dir` `site_file_list` `allowed_site_roots` `is_forbidden_site_dir` `mime_for_path` `github_api` `github_blob_sha` `gitlab_api` `netlify_api` `vercel_api` `webdav_encode` `webdav_request` `webdav_mkdirs` `ftp_mkdirs`）| ✅ `1c` 完成 |
| **1d** | `render_hosting_targets_table` `render_hosting_target_row` `render_cf_platform_fields`（UI ⇒ 归 Step 2 的 admin-ui）| ⬜ |

**已跑通的搬运模式（照做即可）**：
1. `write_file` 写 python 脚本：自动定位块边界（按函数名定位 + `^    \}$` 收尾）+ 提取方法体 + 打印签名
2. 生成或**追加**到 `includes/class-transports.php`（`private function` → `public function`，其余逐字节照搬）
3. 主文件：把原块换成**同名 private 转发方法**（`return CFSE_Transports::get_instance()->x(...)`）⇒ 调用点零改动
4. 门禁：`php -l`（主文件+新文件）→ 反射探针（`tmp/probe-*.php`，`invoke($obj, ...$args)` 形式）→ 部署 + `chown www-data` → 提交
5. 自检：新文件方法体「反向 `public`→`private` 后 == 原块」必须为 True（等价性证据；注意 `public` 方法**不要**反向替换）
6. ⚠️ **搬完必须静态扫描一遍**：列出「新类里 `$this->xxx(` 但新类未定义」的 ⇒ 补搬。光靠依赖正则**会漏**（1b 就漏了 `intent_requires`，最后靠探针才抓到）
7. ⚠️ 主类**属性**（如 `$reported_dir_failure`）在新类里用 ⇒ PHP 8.2+ **不允许动态属性**（线上是 8.3）⇒ 必须显式声明，否则抛 deprecated
8. ⚠️ **类常量**：新类里用 `self::META_SITE` 这类**主类常量** ⇒ 常量**不走 `__call`/`__get`** ⇒ 必须在新类里也声明（探针报 `Undefined constant CFSE_Admin_UI::META_SITE`）
9. ⚠️ **主类属性**：新类访问 `$this->target_modals` ⇒ `__get` 里用 `ReflectionProperty` **通用读取**（public/private 都能读）；若读写都在新类内，直接在新类声明该属性更简单
10. ⚠️ **跨包依赖**：admin-ui 用到 transports 的 `get_hosting_targets`/`get_hosting_templates`/`validate_hosting_targets` ⇒ 在 `__call` 里**先查 `CFSE_Transports` 再回主类**，不要重复搬（同一方法两处定义会漂移）
11. 新类共用桥接模板：`__get`（config + 反射读主类属性）/ `log()` / `__call`（先 Transports 后主类 `cfse_proxy`）/ 需要的 `const` / 用到的 `$属性` 声明

### ⚠️ 2026-09-25 Step 3a 踩的坑（最重要，Step 4/5 必看）

12. ⚠️⚠️ **方法边界绝不能用 `^    }$`** —— 方法内部可能有 4 空格缩进的 `}`（`if { }` 收尾）⇒ **提前截断**，残留半段代码 ⇒ 主文件语法错误。**必须用「下一个同级方法签名」定位**。
13. ⚠️⚠️ **主文件混用 TAB 与 4 空格缩进**（`init_hooks` 就是 TAB！）⇒ 边界正则必须是 `^[ \t]{0,4}(public|private|protected)\s+function\s+\w+\s*\(`。只认 4 空格时，`init_hooks` 这类**接线点**会被整段吞进相邻方法 ⇒ 症状 `Call to undefined method CF_Static_Export_Pro::init_hooks()`。
14. ⚠️ **引用参数**：`function x(&$out)` 搬走后，**转发调用实参不能带 `&`**（`->x($out)` ✓；`->x(&$out)` 语法错误 `unexpected token "&"`）。
15. ⚠️ **PHP 8.3 未声明的 static 属性会致命**（`self::$deleted_post_ids`）⇒ 新类必须显式声明（从主类照抄初值）。
16. ⚠️ **回滚主文件会丢掉附加步骤**（如 `require_once includes/class-worker-cms.php`）⇒ **回滚后必须重跑「加 require」等**。
17. ⚠️ **`pscp` 多文件到同一目录只传了一个**（实测）⇒ **逐个传**并用 `ls -la` 核对字节数。
18. 🔴 **部署铁律**：**`php -l` 通过才部署**。本轮两次「先部署后 lint」把线上弄坏两次（每次都要立刻回滚）——**改完先传 `cfse-lint/` 验证，再传插件目录**。
19. 工具：`tmp/move-fns4.py`（[ \t]{0,4} 边界 + & 处理）、`tmp/auto-move.py`（扫描→补搬→循环到干净）、`tmp/check3.py`（漏网扫描）。

### Step 1 的做法（1a 已完成，模式见上）
1. 重新 `grep` 确认上表行号（会漂移）
2. 新建 `includes/class-transports.php`：**普通类 `CFSE_Transports`**，构造函数收 `config`；方法体**原样搬**（`$this->` 保持）
3. 主文件：删掉这些方法；顶部 `require_once` 引入；**保留同名转发方法**（`$this->target_upload(...)` → `CFSE_Transports::get_instance()->target_upload(...)`）⇒ **改动面最小、调用点零改动**
4. 门禁：`php -l` → 同步服务器 → 后台**「🔌 测试连通性」跑一遍** → `git commit`
5. 纪律：**只搬不改**（`git diff` 应只有位移）；每步一提交；`php -l` 通过才覆盖；备份到服务器 `/tmp/`

**⚠️ 不用 PHP 命名空间**（会牵扯每个调用点）—— 项目已有教训：**CFSE 与 CFSH 不可同站同时激活，正是同名常量/类冲突**。

---

**2026-09-26 收尾（已部署）**：右列重排为「状态面板 / 🌐 静态托管设置（原发布整站改名）/ 🛠️ CMS 自托管设置（卡片 + 4 折叠组）/ 最近日志」；新增 `cf_manual_*`（「导出文章」参数，与发布时自动分离，`export_post_manual()` + `mode=manual`）；删「生成到本地 / 上传到远端」按钮、移除评论「站外提交」下拉、Worker 代码管理只留同步风格资源；修掉 `ff566f7` 引入的左列多余 `</div>` 布局 bug。

## Phase B：设置页 UI 重构 ✅ **已完成**（2026-09-25）

- ✅ **步骤 1**：4 个运行时开关从「托管目标弹窗」搬回设置页（`56593b0`）
- ✅ **步骤 4（删除清单）**：单页缓存头 / 横幅·Logo / 评论展示模式（`dd63b38`）
- ✅ **步骤 2+3（主操作区）**：新建「☁️ 同步到托管 CMS」卡 = 📤 导出（`cf_gen_static` / `cf_publish_remote`
  + 全量导出 / 重置标记）/ 📥 拉取 / ⚙️ 运行时（4 开关）。**跨 form 的 input 用 HTML5 `form="cfse-options"`**（`c669a0e`）
- ✅ **步骤 5（问号说明）**：📤 / 📥 各配 `dashicons-editor-help` + `title`
- ✅ **其它**：托管目标上移到基础配置上方；🌐 发布整站 / 📋 最近日志 独立成卡；
  `.cfse-grid > form { display: contents }`（`b902358`）

⚠️ **仍待人工确认（诚实标注）**：在浏览器里点一次「保存配置」，然后核对
`SELECT option_name, option_value FROM wp_options WHERE option_name LIKE 'cf_%'`。
`form="cfse-options"` 是 HTML5 标准做法、探针也确认 6 个 checkbox 属性就位，但**没有在真机提交过表单**。

照 `docs/settings-ui-refactor.md`（已定稿，含布局图与搬迁对照表）：

- ✅ **步骤 1 已完成**：4 个运行时开关（`cf_sync_d1` / `cf_purge_*` / `cf_worker_auto_check`）
  从「托管目标弹窗」搬回设置页的「☁️ Worker端管理」区（提交 `56593b0`）
- ⬜ 步骤 2：建**主操作区**（右列中段，状态面板与日志之间）两张卡：🌐 发布整站 / ☁️ 同步到托管 CMS
- ⬜ 步骤 3：三个开关聚到「📤 导出」（`cf_sync_d1` 已就位，还差 `cf_gen_static` / `cf_publish_remote` 从基础配置搬来）
- ⬜ 步骤 4：删除清单 —— 单页缓存头 / 横幅·Logo（交给 CMS）/ 评论展示模式
- ⬜ 步骤 5：问号说明（`dashicons-editor-help`+`title` / `<details>`，项目内有现成先例）
- ⬜ 其它：托管目标移到基础配置**上方**

---

## 其它待办（不在本路线内，别忘了）

- **`saaslauncher`** 的站点名归一化（块主题提取器 `fse_shell_parts()` 的 L4 残留，10 处）
- **`twentynineteen`** 的 `<time>` 日期残留（抓取时刻写死）
- **`ury`** 块主题抓取失败的原因
- **全量导出 504 的治本**：改成"后台任务 + 分批 + 进度"（现在是一次请求跑完，`ignore_user_abort(true)` 让它在 504 后仍在跑，看不到进度）
- 「清 KV 缓存」入口（现在只有"重置导出标记"，真清缓存的能力在 Worker 侧，没有按钮）

---

## 最近的提交（便于对账）

| 提交 | 内容 |
|---|---|
| `03b888b` | 拆分计划文档 |
| `56593b0` | 拆分 Step 0 + 「运行时」4 开关归位 |
| `fd165e4` | 设置页 UI 重构方案定稿 |
| `1172e33` | 「重置导出标记」（改名 + 补删 `_cfse_synced_d1`）|
| `3425341` | 后台「文章单页布局」开关 + 渲染时注入 |
| `173b9f0` | 经典提取器补 `{{MENU}}` 占位化 |
