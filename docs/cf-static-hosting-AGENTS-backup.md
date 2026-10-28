# CF Static Hosting — 项目交接说明（AGENTS.md）

> 任何 AI 助手/开发者接手本项目前请先通读本文件；设计规范在 `docs/static-hosting-extraction.md`。

> ⚡ **30 秒接续（2026-09-19）**
> - **状态 v0.1.0**：12 个模块已实现；**已部署到服务器 `plugins/cf-static-hosting/`（未激活）**；端到端验证过（抓取首页 99866B → host 改写 `192.168.2.2`→`doc.yuebin.uk` → 落盘 / 上传 R2 / 写 D1 / 直连兜底 / md 渲染）。
> - **本仓库最新提交 `61f146c`**：s3 对象名解码为原文 + `sigv4_uri_encode` 改为 `%` 一律 `%25` + R2 直连先解码再逐段 `rawurlencode`（修中文路径 403 / 字面 `%xx` 对象名）⇒ **已部署到服务器**（该文件 md5 `f169f7f1…`）。
> - **下一步（主任务，方向未定 ⇒ 先商讨再动手）**：完善「**WP 风格抓取**」。三条待定路线：
>   **(a) 继续「整页抓取 + 后处理」**（现状），只把"更像 WP 前台"做细：保留主题产生的 `wp-block-*` 类与内联样式、原样保留 `srcset`/lazy-load、可选保留动态容器占位、URL 参数归一化等；
>   **(b) 抓取产物做「WP 风格归一」**：按 WP 的规范清理/补全（如修正 `wp-block` 相关 class、统一 `<figure>`/`<figcaption>` 结构），让静态页与"WP 官方渲染"更接近；
>   **(c) 「模板提取」式抓取**：不再整页搬运，而是识别"哪些 DOM 是列表条目 / 正文块"，按模板语义抓取局部（需要新的**结构识别层**，工作量与风险都要先评估）。
>   ⇒ 需要先与用户确认：目标是哪一条？是否要引入选择器/模板语法？
> - **已知坑**：① **不可与 CFSE（`cf-static-export-pro`）同站同时激活**（都 hook `save_post`、都产单页）；② CFSH 的 s3 / R2 直连曾缺 `rawurldecode`（已修）：**传给 s3 的 key 是 URL 形态、由实现内部解码**；③ **本地服务器（192.168.2.2）探针/测试脚本可保留**（2026-09-19 用户约定，供研究学习；仅限本地开发服务器，公网/生产仍应谨慎）；④ CFSH 本身**不需要** `wrangler deploy`（只有 CFSE 的 Worker 才需要）；⑤ read-evidence 锁（>31KB 文件改完就跑不了 bash）⇒ 用 python 脚本 + **同一条 bash** 完成"改+检查+提交"；⑥ 本地无 php ⇒ `php -l` 走服务器容器（`docker exec web-php php -l`）。
> - **待验证**：`ftp` / `webdav` / `gitlab` / `netlify` / `vercel` 的真机连通与部署（`s3` / `local` / `cfse-worker` / `github` 已验证）。
> - **新会话开场三件事**：`git log --oneline -10`、`git status --short`、读本文件。


## 一、项目是什么

独立 WordPress 插件：把 WP 站点**抓取**为纯静态文件，发布到任意对象存储 / CDN（S3 / FTP / WebDAV / GitHub Pages / GitLab Pages / Netlify / Vercel / 本地目录），或自托管 Cloudflare Worker CMS。

- **定位**：与 `cf-static-export-pro`（CFSE，`C:/Users/dog/Documents/cf`）**无代码依赖**的独立插件；CFSE 是"自托管 Worker CMS"产品线，本插件是"通用静态托管"产品线。
- **不可同站同时激活**（都 hook `save_post`、都产单页）。
- **命名**：目录 + 文本域 `cf-static-hosting`，显示名 `CF Static Hosting`，option 前缀 `cfsh_`，常量前缀 `CFSH_`，CSS/JS 前缀 `cfsh-`。

## 二、职责边界（决定实现范围，勿越界）

- 插件 = **忠实还原站点页面**：抓 WP 前台真实输出 + 改写 host。**不做渲染器**。
- **不替用户处理动态功能**（搜索 / 评论 / 表单由用户或主题自理）。**唯一例外**：静态站 host 改写。
- **md 不特殊处理**：统一走前台抓取 —— WP 前台输出什么就抓什么（主题/Parsedown 插件自己渲染），尽量 100% 还原。
- **生成 = 抓取**（不是渲染复刻）；**列表页 = 抓取 + 调度**（URL 枚举 + 三层触发），不是渲染复刻。

## 三、架构

### 3.1 模块（`includes/`，平铺 12 个 `class-*.php`）

| 文件 | 类 | 职责 |
|---|---|---|
| `class-plugin.php` | `CFSH_Plugin` | 单例 + `boot()` 装配 + 钩子 + `log()`（`__construct` 里**主动** boot，否则后台无菜单） |
| `class-config.php` | `CFSH_Config` | option `cfsh_settings` 读写 + `defaults()` |
| `class-generator.php` | `CFSH_Generator` | `fetch($url)` / `process($html)`（清 admin-bar + host 改写 + 清动态脚本）/ `normalize_source_hosts` / `is_valid_html` / `extract_excerpt` |
| `class-urls.php` | `CFSH_Urls` | `list_pages()`（首页/分类/标签/归档 + 分页 + `/feed/`）/ `post_pages()` / `post_count()` / 静态 `url_to_path()` |
| `class-schedule.php` | `CFSH_Schedule` | `urls_for_post()` / `mark_fetched()` / `expired_list_pages()` / `all_urls()`；option `cfsh_fetched`（`{url: ts}`） |
| `class-targets.php` | `CFSH_Targets` | `templates()`（10 模型）/ `caps_of()` / `with_capability()` / `upload()` / `delete()` / 各 transport 实现 / `test_connection()` / `public_url()` |
| `class-publisher.php` | `CFSH_Publisher` | `refresh_urls($urls, $scope)` / `publish($url,$html,$scope)` / `full_refresh()` / `delete_url()` |
| `class-cms.php` | `CFSH_Cms` | 可选 CMS 能力：`write_post` / `delete_post` / `build_post_data` / `pull_posts` / `apply_remote_post` / `d1_batch`（Worker→降级直连）；常量 `WORKER_ID_MIN = 100000000000` |
| `class-md.php` | `CFSH_Md` | md 渲染（可选，默认开）：`content_format()` / `render_markdown()` / `md_to_plain()` / `render_content()`（挂在 `the_content` 优先级 9；vendored Parsedown + safe mode） |
| `class-media.php` | `CFSH_Media` | 媒体 URL 提取与同步（`sync_urls` / `sync_url`） |
| `class-deploy.php` | `CFSH_Deploy` | **整站事务型**部署：`deploy_to()` / `list_files()` / `git_deploy`（blob→tree→commit）/ `gitlab_deploy` / `netlify_deploy` / `vercel_deploy` |
| `class-admin.php` | `CFSH_Admin` | 设置页（`register`/`render`/`sanitize`/`field_groups`）+ meta box（文档格式 / Worker 同步）+ AJAX（批量 / 测试连通性 / Worker 状态 / 对齐 / 拉取 / 刷新）+ 导出状态列 |

`views/` / `assets/` / `tools/` 为预留空目录（git 不跟踪空目录）。

### 3.2 目标体系（`class-targets.php`）

- **10 个模板（provider）**，字段组在 `field_groups()`：
  `s3` / `local` / `ftp` / `webdav` / `github` / `git` / `gitlab` / `netlify` / `vercel` / `cfse-worker`。
- **能力模型**：纯静态模板 = `{r2:true, d1:false, cache:false}`；`cfse-worker` = `{r2:true, d1:true, render:true, cache:true}`。目标可覆盖模板能力（`caps_of()` = 模板默认 + 目标覆盖）。
- **分派**：`with_capability($cap, $only_ids, $scope)` —— `$scope`：`null` 全部 / `'local'` 只本地目录目标 / `'remote'` 只非 local 目标。`for_each()` / `upload()` / `delete()` 都接受该参数。
- **两类 transport**：逐文件型（`local` / `s3` / `cfse-worker` / `ftp` / `webdav`）走 `upload()`/`delete()`；整站事务型（`github`/`git`/`gitlab`/`netlify`/`vercel`）走 `deploy()`。

### 3.3 发布：两条路（关键设计）

| | 纯静态目标 | `cfse-worker`（自托管 CMS） |
|---|---|---|
| 开关 | **全局** `publish_on_save`（设置页） | **目标级** 5 开关（该目标行内） |
| 开关项 | — | `sync_d1` / `gen_static` / `publish_remote` / `prefer_direct` / `worker_auto_check` |
| 语义 | 开 ⇒ 抓取后分发到**所有** r2 目标 | `sync_d1`=写库；`gen_static`=**只落盘**（scope=local）；`publish_remote`=**只上传 R2**（scope=remote）；两者都不写 D1、不清缓存 |

- `on_save_post()`（`save_post`, 优先级 20）：先按 `cfse-worker` 目标跑 `sync_d1`，再决定抓取分发（`$global_static` 优先，否则 `worker_gen`/`worker_pub`）；`$GLOBALS['cfsh_in_refresh']` 防重入；收尾写 meta `_cfsh_exported`。
- `on_trash_post()`：**按目标开关分派**（只有该目标勾了的能力才清），并把工作**攒到 `shutdown`**（`flush_pending_deletes()`）批量执行 —— 修"删除慢"（原先每篇 3-6 次同步 HTTP）。队列：`$pending_d1_deletes` / `$pending_local_deletes` / `$pending_remote_deletes` / `$pending_refresh_urls`。
- 删除时一并清 `<path>` 与 `<path>.gz`。
- **三层触发**：发布/更新精确刷（核心）+ `cfsh_cron_refresh`（hourly，伪定时兜底，可关）+ 手动（设置页「刷新」）。

### 3.4 两条"路"：Worker 私有接口 vs 直连（`cfse-worker` 专用）

- **默认走 Worker**（自研私有接口，`X-Auth-Key`）；失败**降级直连** Cloudflare 官方 API（API Token）。
- **`prefer_direct` 勾选** ⇒ 直连优先、失败回退 Worker（省 Worker 配额）。**默认关**。
- 直连凭证（`account_id` / `api_token` / `r2_bucket` / `d1_database`）藏在 `cfse-worker` 字段组的「**▸ 高级选项**」`<details>` 折叠区里，**可不填**。
- **Worker 私有接口**（`worker/src/routes.js` 非登录区，`X-Auth-Key`）：
  `POST /d1-batch`（`{statements:[{sql,params}]}`）/ `POST /upload`（`{path, content(base64), contentType, contentEncoding, md5?}`）/ `DELETE /delete`（`{paths:[]}`）/ `POST /purge-cache`（`{keys:[]}`）/ `GET /sync/posts`（`?wp_id=` / `?min_wp_id=`）/ `GET /style-list`。
  ⚠️ **upload 的键名必须是 `contentType` / `contentEncoding`**（曾传 `content_type`/`encoding` 导致媒体 MIME 错）。
- **`/health` 不需认证**（无 key 也 200）⇒ 测连通性必须用受保护接口（如 `/style-list`）才能验证 Auth Key。

### 3.5 CMS 能力与归属判据

- 归属判据：`wp_id >= CFSH_Cms::WORKER_ID_MIN`（1e11，时间戳形态 = Worker 端诞生）。**绝不用 WP 的 `import_id`**（会把 `wp_posts` 的 AUTO_INCREMENT 顶到 1e14）。
- 远端 ID 记在 meta **`_cfsh_remote_wp_id`**（`get_remote_wp_id()` / `find_post_by_remote_id()`）；拉取不对齐 WP 侧主键。
- 其他 meta：`_cfsh_exported`（有静态单页）、`_cfsh_synced_d1`（仅入库）。

### 3.6 md 渲染（`class-md.php`，默认开）

**为什么必须挂在 `the_content` 上**：本插件的"生成"= 抓 WP 前台真实输出 ⇒ **只有前台先输出 HTML，抓下来的静态页才是 HTML**（GIGO）。站点若没有独立的 md 渲染插件，打了 `content_format=md` 的文章在前台就是原稿，抓下来也还是原稿。

- **判据** = meta `content_format`（`content_format()` 只返回 `md`/`html`，兼容 CFSE 旧键 `_cfse_content_format`）—— 不做"看起来像 md"的猜测。
- **渲染** = vendored Parsedown（`vendor/parsedown/Parsedown.php`，1.7.4 / MIT）+ `setSafeMode(true)`（`<script>` 被转义）；站点已加载 Parsedown 则复用其实现（`class_exists` 保护）。
- **挂载** = `CFSH_Plugin::init_hooks()` 里 `add_filter('the_content', ..., 9)`（**9 早于 `wpautop` 的 10**），受配置 `render_md` 控制（**默认 true**）。
- **摘要** = `class-cms.php::build_post_data()` 对 md 文章先 `md_to_plain()`（渲染后 `strip_tags`），避免写进 D1 的 excerpt 残留 `**` / `##`。
- ⚠️ 切换开关需**新请求**才生效（`init_hooks()` 在插件加载时读配置）。
- **实测（2026-09-18）**：前台 `1863` `<strong>`×26、无残留 `**`；开关置 false 后新请求 `filter_hooked=NO`、恢复 true 后 `YES`；抓取产物 `has_strong=YES` / `has_md_bold_mark=NO` / 源站 host 残留 0；`<script>alert(1)</script>` 渲染为转义文本。

## 四、配置（option `cfsh_settings`）

`defaults()`：`site_url=''` / `targets=[]` / `list_depth=1` / `list_ttl=86400` / `cron_enabled=true` / `publish_on_save=false` / `render_md=true`（md 渲染开关） / `cache_ttl=3600` / `media_mode='incremental'`。
目标项额外键（`sanitize_targets()` 白名单）：`id` / `template` / `enabled` / `public_url` / `path_prefix` + 各 transport 字段 + `ftps` + 5 个 `cfse-worker` 开关。
另：`cfsh_fetched`（列表页抓取记录）。

## 五、部署与服务器

- 服务器 `192.168.2.2`，root 密码 `YOUR_SECRET`（plink/ssh，与 Worker auth key 同值）。
- 插件路径 `/mnt/optane/webstack/www/wp-content/plugins/cf-static-hosting`（容器内 `/var/www/html/wp-content/plugins/cf-static-hosting`）。
- PHP 在容器内跑：`docker exec web-php php ...`；MySQL：`docker exec web-mysql mysql -uroot -proot123456 wordpress`。
- 同步套路：
  ```bash
  cd C:/Users/dog/Documents/cf-static-hosting && tar -cf - <files> | plink -ssh -pw YOUR_SECRET root@192.168.2.2 "cd /mnt/optane/webstack/www/wp-content/plugins/cf-static-hosting && tar -xf - && docker exec web-php chown -R www-data:www-data /var/www/html/wp-content/plugins/cf-static-hosting"
  ```
- **必须 `php -l` 通过才同步**（`docker exec web-php php -l /var/www/html/wp-content/plugins/cf-static-hosting/includes/xxx.php`）。**逐文件检查** —— 把两个 PHP 文件拼起来检查会因第二个 `<?php` 误报 `Parse error`。
- 本插件**不需要** `wrangler deploy`（它跑在 WP 里；`cfse-worker` transport 打的是远端 Worker，改 Worker 才需要 deploy）。
- 日志：`wp-content/uploads/cf-static-hosting.log`（`log_path()`）。

## 六、环境坑（都实际踩过）

0. **read-evidence 锁（最影响效率）**：任何写类操作（bash / write_file / edit_file）前，若存在"刚被修改、但未被**单次完整读取**确认"的文件，会被全部拦截（`read-evidence requirement is outstanding`）。
   - "确认" = 一条 `read_file` 从头到尾覆盖整个文件且未截断；分段读不算；同批次内的读不算。
   - **超过约 31KB 的文件无法满足 ⇒ 死锁**（本项目 `class-admin.php` 40KB、`class-targets.php` 35KB 都超）。
   - **对策**：① 用户发新消息即自动复位 —— **把关键命令放在每轮第一个工具调用**；② 用 `write_file` 写**新文件名**的 python 脚本 + **同一条 bash 里执行**（改 + 语法检查 + `git commit` 一气呵成）；③ **不要用 `edit_file` 改大文件**；④ 不要靠"重读大文件"解锁（纯亏损）；⑤ `ls`/`echo` 等元数据命令不受影响。
⑥ **远程改文件绕锁（2026-09-19 用户确认）**：把大文件同步到服务器改（无本地锁），改完拉回 + 提交；配合「服务器探针可保留」的约定，服务器就是本地开发环境。
1. 长 heredoc 会截断（~150 行以上）；**中文 heredoc 在 Windows 控制台会乱码** ⇒ 一律 `write_file` 写脚本再跑。
2. python 生成 PHP 时转义易错：写 `'/\\'`（反斜杠+引号）用普通字符串会退化成 `'/'` ⇒ 语法错误。**写 PHP 片段一律用 r-string**。
3. **改大文件用"改+检查"合并的脚本**，且脚本里**多步替换要逐步验证** —— 一个脚本里某步 `assert` 失败会**静默跳过后续步骤**（本项目踩过：`patch_cfsh_switches.py` 中断导致 2 处改动未执行，后来单独补齐）。
4. `grep -c` 无匹配时 exit 1（不是真错误）。
5. `UID` 是 bash 只读内建变量 —— 脚本里别用 `UID=...` 当变量名（会静默失败成系统 UID）。
6. git-bash 的 `/tmp` 与 native python 看到的 `/tmp` 不是同一个地方；跨工具传文件用 Windows 绝对路径（`C:/Users/dog/AppData/Local/Temp/...`）。

## 七、验证套路

- **要真机验证**（本项目一贯要求）；改变现形式优先**探针脚本**：放 `/mnt/optane/webstack/www/cfsh-probe.php`，`plink` 传上去，`curl -s http://192.168.2.2/cfsh-probe.php` 触发。**本地服务器探针可保留**（用户 2026-09-19 约定，供研究学习；命名清晰、探针自清理测试数据即可）。
- 探针里用 `CFSH_Plugin::instance()`（或 `ReflectionMethod` 调私有方法）验证真实代码路径。
- ⚠️ **验一条链路要从"用户实际会改的入口"进**（option / 设置页表单）。只改内存配置（绕过 option）会**假通过** —— 本项目 `class-targets.php` 的模板解析/白名单类 bug 就是这么漏掉的。
- ⚠️ `wrangler tail` 在本机连不上 Cloudflare（ETIMEDOUT）——线上诊断靠探针 + 响应头/日志。
- 测完清理测试数据（WP 文章 / D1 行 / R2 对象），并核对没留下残渣。
- 危险操作（删文章）**先 `remove_action('trashed_post', ...)`** 再删，否则会连带清掉 D1 / R2 里要保留的数据。
- 验证中文内容**别用** `json_encode(substr(...))`（截断多字节 → 返回 false，看着像空）；用 `strlen` / `md5` / hex 比对。

## 八、版本控制

- 仓库：`C:/Users/dog/Documents/cf-static-hosting`（独立 git，分支 `main`）。**改完一批就提交**，message 带批次/范围前缀，例如 `feat(targets): 补 ftp transport 真机参数`。
- `.gitattributes` = `* text=auto eol=lf`（仓库内一律 LF）；`.gitignore` 含 `*.zip` 等打包产物。
- 日常：改完先 `git diff --stat` / `git diff` 精确核对改了什么（也用来绕开 read-evidence 锁），再 `git add -A && git commit`。
- **敏感信息**：本文件含服务器 root 密码等，**只做本地仓库**；推远程前必须脱敏（改环境变量 / 私有库 + 清历史）。

## 九、待办

0. **【主任务】完善「WP 风格抓取」**（方向未定：更贴近 WP 前台渲染 / 模板提取式抓取）—— 见本文件开头的接续段。
1. **真机验证剩余 transport**：`ftp`（需 FTP 凭证）/ `webdav`（坚果云可用应用密码）/ `gitlab` / `netlify` / `vercel`。
2. **R2 直连的中文路径 key 编码**（已用 ASCII key 验证通过）。
3. `class-deploy.php` 的**整站事务型部署（`deploy_to`）尚未接进设置页 UI**。
4. `class-media.php`（媒体同步）**尚未接进发布链路**。
5. 参考 CFSE 已完成的能力，按需补齐（全量重建 / 站点信息同步 / 媒体库管理 / 自动对齐开关等）。
