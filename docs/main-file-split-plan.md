# 主文件拆分计划（`cf-static-export-pro.php` → 多文件）

> 定调（用户 2026-09-25）：把 380KB 主文件按"主功能"拆开，方便后续维护与重构。
> **本文只给结构、顺序、纪律**；逐步实施、每步独立可验证。

---

## 0. 为什么值得拆（第 3 条最实际）

1. 单文件 **380KB / 5700+ 行** —— 改个设置页都要先在一个 660 行的函数里找位置
2. `docs/direction-classification.md` 已做过完整分类（4 方向 + 8 个跨方向方法），**照它分即可**，不用重新分析
3. ⚠️ **read-evidence 死锁**：本项目 **>31KB 的文件无法用 `edit_file`**（AGENTS.md 记录的坑），只能"写 python 脚本 + 同回合 bash 执行"。
   **拆小之后每个文件都能直接编辑** ⇒ 工具链立刻顺畅。**这是最直接的收益。**

---

## 1. 已有成熟模式：`views/`（照它做）

**模板编辑器已经是独立文件了**：
- `views/template-editor.php`（123KB）、`views/skin-builder.php`（85KB）
- 方式：**普通函数 + `cfse_` 前缀**，主文件 `require_once`（`template-editor.php:16` require `skin-builder.php`）

⇒ **新拆的文件沿用这个模式**（最低风险、已被生产验证）。

⚠️ **不要引入 PHP 命名空间** —— 那会牵扯每一个调用点；且项目已有教训：
**CFSE 与 CFSH 不可同站同时激活，正是因为同名常量/类冲突**。
⇒ 新文件用**普通类 + 前缀类名**（如 `CFSE_Transports`）+ `require_once`。

---

## 2. 目标结构（建议）

```
cf-static-export-pro.php      ← 主类 + 接线点（add_action/add_filter/ajax_）+ 构造 + activate/deactivate
includes/
  class-transports.php        ← ① 方向三：托管目标 / 各传输实现
                                （cloudflare-worker / s3 / local / ftp / webdav / git / github / gitlab / netlify / vercel）
  class-admin-ui.php          ← ② 设置页渲染（settings_page + 各 render_*）
  class-worker-cms.php        ← ③ 方向一：D1 写入 / 拉取 / 对齐 / 评论同步
  class-generate.php          ← 方向二：生成与渲染管线（build_post_* / publish_artifact / post_process_html …）
  class-db.php                ← 共用层：d1_execute / d1_query / schema 迁移
  helpers.php                 ← 工具函数 / 日志 / 配置读取
views/                        ← 已存在，继续用
```

---

## 3. 既有分类速查（09-17 版：**行号已过时，结论仍有效**）

| 方向 | 方法数 | 当时的聚集位置 |
|---|---|---|
| **方向三 通用静态托管**（衍生）| ~30 | 四块：模板/目标、分派、s3+签名、各 transport 部署 |
| **方向一 Worker CMS 交互**（本体）| ~15 | 集中在一带 |
| **方向二 WP 静态插件本体** | ~45 | 分散（设置页 / 模板 / 抓取 / 生成 / 发布行为）|
| **共用层** | ~25 | 工具 / 日志 / 配置 / DB |
| **接线点** | ~35 | **始终留主文件**，当薄壳 |

**8 个跨方向方法（拆分前要拍板归属）**：
`publish_artifact` / `delete_from_r2` / `export_post`·`export_post_by_switches`·`do_export_post` /
`d1_batch`·`worker_d1_batch` / `write_to_d1` / `ensure_d1_schema`·`migrate_d1_schema` /
`get_post_storage_path` / `is_worker_owned_post` / `get_post_sites`·`get_post_site_ids_raw` / `sync_media_files`·`upload_media_to_r2`

---

## 4. 顺序（每步独立可验证）

```
Step 0  重新盘点：grep 全部 `function` 名 + 行号，按上表归类（09-17 的行号已失效）
Step 1  transports（方向三）      ← 边界最清晰、最独立；先拿它验证拆分模式
Step 2  admin-ui（设置页）        ← ★ 拆完它，设置页 UI 重构才好做
Step 3  worker-cms（方向一）      ← D1 写入 / 拉取 / 对齐 / 评论同步
Step 4  generate（生成管线）
Step 5  共用层 + helpers
（接线点始终留主文件）
```

**⚠️ 顺序建议：先拆、再重构 UI。** 现在 `settings_page()` 埋在 380KB 里，搬块的每一步都提心吊胆；
**先把它拆成独立文件（Step 2），再实施 `docs/settings-ui-refactor.md`**，会轻松得多。

---

## 5. 每步的做法与验证（硬纪律）

| 项 | 要求 |
|---|---|
| **只搬不改** | 逻辑一行不改 ⇒ `git diff` 应只有"位移"。真要改一个 `if`，**单独提交并说明** |
| **搬运方式** | 主文件用 `edit_file` 删、新文件用 `write_file` 建（**别用 PowerShell 写**，编码坑）|
| **可见性** | 现在全是主类方法 ⇒ 搬到新类要处理 `private → public`、`$this` 引用 |
| **风格要么全函数、要么全类** | 沿用 `views/`（普通函数 + 前缀）就**显式传参**；若要类，则构造函数接收 config。**同一个文件别混用** |
| **门禁** | 每步：`php -l` 通过 ⇒ 同步服务器 ⇒ **实际跑一遍**（导出 1 篇 / 保存设置 / 测试连通性 / 拉取）|
| **备份** | 服务器 `/tmp/` 留备份；git **每步一提交** |
| **回滚** | 单步可回滚：`git revert` + 重新同步该文件 |

---

## 6. 风险清单

1. **常量/类重名** ⇒ 新文件的所有全局符号加 `CFSE_` 前缀；**不要 namespace**
2. **单例 + 实例方法**：现在大量 `CF_Static_Export_Pro::get_instance()->xxx()` ⇒ 搬移时保持"单例 + 实例方法"最省事
3. **`$this->config`**：很多方法依赖它 ⇒ 拆出去的类应**在构造函数接收 config**（或持有主类引用），避免满地 `$this->config` 复制
4. **`uninstall.php` / `schema.sql`**：拆分不影响；但**新增文件路径要在插件主文件里 require**（激活时）
5. **activate / deactivate 钩子**：仍是接线点 ⇒ **留主文件**
6. **页面缓存**：拆分后若改了文件包含顺序，注意 `init_hooks()` 里钩子注册的时机不变
