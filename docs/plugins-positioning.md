# WP 侧两个插件的定位区分

> 结论先行：**`cf-static-hosting` 是「抓网页 → 发到任意目标」的通用静态托管插件；`cf/cf-static-export-pro.php` 是「直接把 WP 数据写进自家 Worker」的 CMS 深度同步插件。**
> 两者都 hook `save_post`，**同一个站点不要同时启用**。

本文只记录定位与能力边界，不含改造计划。核对时间：2026-10-20。

---

## 1. 一句话对照

| | `cf-static-hosting` | `cf/cf-static-export-pro.php` |
|---|---|---|
| 仓库 | `Documents/cf-static-hosting/`（独立 git，**已转私有**） | `Documents/cf/`（主仓库，内含 Worker + 插件） |
| 版本 | v0.1.0，单提交 `a230510`，12 类 3311 行 | 现役，`cf-static-export-pro.php` 2460 行 + `includes/` 3 类 4152 行 |
| 心智模型 | **URL 抓取式**：crawl 渲染后的 HTML → 分发到目标 | **数据同步式**：结构化写 D1 + R2 对象 + KV 失效 |
| 目标 | 9 种 transport，目标无关 | 只服务自家 CF Static Export Pro Worker |
| CMS | 内置一组 CMS 设置项（其中一个 transport 叫 `cfse-worker`） | CMS 是唯一目的，同步是双向的 |

---

## 2. 架构差异（这是根因，不是细节）

`cf-static-hosting/includes/class-publisher.php` 的注释已经把话说完了：

```
发布编排：抓取 URL → 落盘/分发到目标 → 标记已抓取。
「local 目标」即落盘 —— 所以「生成到本地」和「上传远端」是同一套流程的不同目标，
不需要单独的开关。
```

也就是说它不关心目标是不是 CMS。`local`（落盘）、`s3`、`ftp`、`webdav`、`cfse-worker` 走「逐文件上传」；`git`/`gitlab`/`netlify`/`vercel` 走 `class-deploy.php` 的「事务型整站部署」（一次提交/部署一个快照版本）。

主仓库插件相反——它没有 crawl 这一步（`fetch_from_frontend` 只用于兜底取渲染结果），主路径是 `write_to_d1()`：把文章、分类、标签、meta 打成一批 SQL，通过 `/d1-batch` 一次性 HTTP 提交，再单独传 R2 对象、清 KV。

一句话：**cf-static-hosting 产出的是「文件」，主插件产出的是「数据 + 文件」。**

---

## 3. 能力矩阵

### 3.1 分发目标

| transport | cf-static-hosting | 主插件 |
|---|---|---|
| `local` 落盘 | ✅ | ✅ |
| `s3` | ✅ | ✅（R2 兼容 S3 协议） |
| `ftp` / `webdav` | ✅（未真机验证） | ✅ |
| `git` / `gitlab` | ✅（事务型整站部署） | ✅ |
| `netlify` / `vercel` | ✅（未真机验证） | ✅ |
| `cfse-worker` | ✅（含直连回退） | —（它就是 Worker 自己） |
| GitHub Pages | ✅（走 `git`） | ✅ |

### 3.2 CMS 侧能力

| 能力 | cf-static-hosting | 主插件 | 备注 |
|---|---|---|---|
| 建表 / 补 schema | ✅ `ensure_schema()` | ✅ `migrate_d1_schema()` 可重建 links 表 | 主插件能迁移历史数据 |
| 文章写入 D1 | ✅ `write_post()` | ✅ `write_to_d1()` | |
| 从 Worker 拉回文章 | ✅ `pull_posts()` | ✅ `pull_posts_from_worker()` | |
| 直连回退（绕过 Worker 直接打 D1 API） | ✅ `d1_direct_batch()` | — | cf-static-hosting 特色 |
| 站点信息同步（标题/描述/菜单） | ❌ | ✅ `sync_site_info()` / `push_site_info()` | |
| **KV `site:ctx` 失效** | ❌ **完全没有** | ✅ `purge_site_ctx_option()`（清 `site:ctx` + `cache:config` + `rss:feed`） | **最关键的差异，见 §4.1** |
| 媒体上传 R2 | ⚠️ 只做「提取 URL + 分发到目标」（`class-media.php`） | ✅ `upload_media_to_r2()` / `sync_media_files()` | |
| 评论 | ❌ | ✅ 19 处引用 | 主插件管评论审核/注入链路 |
| 草稿同步 | ❌ | ✅ `sync_draft_to_d1()` | |
| Worker 归属判定（防覆盖 Worker 端诞生的文章） | ✅ `is_worker_owned()` | ✅ `is_worker_owned_post()` | 两边都有，逻辑一致 |
| 远端文章覆盖本地 | ✅ `apply_remote_post()` | ✅ `overwrite_post_from_remote()` | |

---

## 4. 已知差异带来的坑

### 4.1 配置改了不生效（cf-static-hosting 独有）

Worker 端配置缓存是三层：isolate 内存 30s → **KV `site:ctx`（TTL 7 天）** → D1 `settings`。

`cf-static-hosting` 全仓库搜不到 `site:ctx` / `kv` / `purge`（0 命中），主插件有 `purge_site_ctx_option()`。所以用 cf-static-hosting 改了站点标题、描述、导航菜单后，Worker 会**继续用 KV 里的旧值最多 7 天**。

绕过办法（手工，顺序不能颠倒）：

```bash
# 1. 改 D1
npx wrangler d1 execute <db> --remote --command "UPDATE settings SET value='...' WHERE key='menu'"
# 2. 删线上 KV —— 注意必须带 --remote，不带就是操作本地模拟 KV（曾踩过）
echo y | npx wrangler kv key delete "site:ctx" --namespace-id=<id> --remote
# 3. 等 ~60s 让 KV 删除全局传播
# 4. 再清一轮 R2 快照，否则传播窗口内旧配置会被重新烤进快照
```

### 4.2 评论不同步

cf-static-hosting 不碰评论表。如果站点用 Worker 原生评论，切到 cf-static-hosting 后新评论审核状态不会体现在任何一侧。

### 4.3 双插件冲突

两者都 hook `save_post` 并各自渲染/发布页面，同时启用会双写、互相覆盖。`cf-static-hosting/readme.txt:33` 已有这句警告。

---

## 5. 该用哪个

- **只想要「WP 发的文章，静态化后扔到任意地方」**（S3/Netlify/FTP/GitHub Pages，不关心 CMS）→ `cf-static-hosting`
- **站点就跑在 CF Static Export Pro Worker 上**（D1 存数据、R2 存页面、Worker 端写文章、评论、KV 缓存治理）→ 主仓库 `cf-static-export-pro.php`
- **想让 WP 当编辑器、Worker 当 CMS 且两边都能改** → 主仓库那份，双向同步和缓存失效都在里面
- 只想把 WP 当纯编辑器、不需要 Worker 端 CMS → `cf-static-hosting` 更轻

---

## 6. 写文档时的口径规则

`cf-static-hosting` 的对外描述里，CMS 只能是「**内置的可选目标之一**」，不能写成「CMS 一等公民」或「与 CF Static Export Pro 深度集成」。

待修正（用户明确「先不改代码」，暂不动）：

- `cf-static-hosting/readme.txt:23`
  现文：`* **Self-hosted Worker CMS**: first-class integration with the [CF Static Export Pro](...) Worker (private endpoints with automatic direct-connect fallback)`
  建议：弱化为「可选 transport 之一（`cfse-worker`，带直连回退）」，并加一句指向主仓库的完整 CMS 版说明。

- `cf-static-hosting/readme.txt:64`（changelog 里 `cfse-worker` 的措辞）同理。

仓库目前是 **private**，这句话外部看不到，等重新公开时再改。

---

## 7. 变更记录

- **2026-10-20**：`yuebinliu/cf-static-hosting` 由 public 改为 private（API `{"private":true}` 返回 `visibility: private`）。用户澄清两者定位：cf-static-hosting = 纯静态托管插件（内置 CMS 设置项）；`cf/cf-static-export-pro.php` = CMS 适配完善的版本。本文即据此建立。
- **2026-10-22**：三插件拆分落地，新增 §8。主仓库那份仍是「单文件全功能版」，拆出来的两个新插件已能独立安装。

---

## 8. 三插件拆分后的定位（2026-10-22）

原来 `cf-static-export-pro.php` 一个插件干三件互不相干的事，现在拆成三个**各自可独立安装**的插件（每个目录直接复制进 `wp-content/plugins/` 就能用）：

| 目录 | 插件名 | 一句话定位 | 主文件行数 |
|---|---|---|---|
| `Documents/cf/` | CF Static Export Pro（旧，未拆） | 全功能版，保留作参照/回滚 | 2266 |
| `Documents/cfse-sync/` | **CFSE Sync** | **只写数据**：文章/分类/标签 → D1，媒体 → R2，并删旧单页。渲染由 Worker 懒生成 | 1542 |
| `Documents/cfse-style/` | **CFSE Style** | **只读外观**：把当前主题抓成风格包（tpl.json + 主题资源 zip），不写任何远端 | 17 文件 / 320KB |
| `Documents/cf-static-hosting/` | WP Static Deploy | 通用静态托管，9 种 transport | — |

### 8.1 三者的边界

- **CFSE Sync 不做渲染**。`export_post()` 只做三件事：写 D1、传媒体、删旧单页（含 `.gz`）。
  页面 HTML 由 Worker 在首次请求时 `renderSinglePageOnDemand()` 懒生成 —— 所以「删旧单页」是核心机制，不删就永远是旧内容。
- **CFSE Style 不碰远端**。产物只落在 `wp-content/plugins/cfse-style/styles/<slug>/`，zip 内部路径是 `wp-content/themes/<slug>/…`
  （Worker 就按这个 URL 路径 fetch `tpl.json`，解压即生效，CMS 侧零适配代码）。
- **常量前缀已隔离**：CFSE Sync 用 `CFSE_*`，CFSE Style 用 `CFSE_STYLE_*`，两者**可以同站共存**（抓外观的站点常常同时是同步源站）。
  但**函数前缀仍是 `cfse_`**（70 余个函数同源），所以 CFSE Style 用 `class_exists('CF_Static_Export_Pro')` 挡住旧插件 ——
  不能用 `defined('CFSE_VERSION')`，那会误伤 CFSE Sync。

### 8.2 CFSE Style 的两条抓取路径（2026-10-22 新增 FSE 分支）

| | 经典主题 | 区块主题（FSE） |
|---|---|---|
| 判据 | `wp_is_block_theme()` 为假 | `wp_is_block_theme()` 为真（= 有 `templates/index.html`，**不是**「有 theme.json」） |
| 入口 | `tools/skin-render.php`，5 种页面各一个 CLI 进程 | `tools/fse-extract.php`，读 `templates/*.html` + `parts/*` + `patterns/*` |
| 定位方式 | 发 3 篇探针文章 + 3 条评论 → 渲染 → 靠 token/值锚找容器 | 逐块确定性映射；值型块用核心 `render_block()` 渲染一次拿真实结构再占位化 |
| 探针 | 需要（用完即删） | **不需要**（零残留） |
| 耗时 | ~20s（5 次子进程渲染） | ~2.5s |
| 产物 | 同一份 tpl.json 结构 | 同一份 tpl.json 结构（Worker 端零改动） |

**为什么区块主题不能复用渲染链路**（血泪，别试）：
`locate_template(array('single.php','singular.php','index.php'))` 在区块主题里会**静默回退到默认主题的 PHP 模板** ——
实测 `twentytwentyfour` 返回 `/themes/twentyten/single.php`，等于抓了另一个主题的外观，而日志里看不出任何异常。
改用 `get_block_template()` + `do_blocks()` 能渲染出正确内容，但产物里**既没有 `id="post-N"` 也没有 `entry-title`**，
`skin-builder.php` 的容器定位（全靠 WP 惯例类名）整体失效。所以只能走第三条路：读文件 + 逐块映射。

**实测覆盖（2026-10-22，测试服务器 `192.168.2.2`，95 个主题）**

| | 主题数 | 成功 | 探针/站点值泄漏 | 未解析中间态 | 单主题耗时 |
|---|---|---|---|---|---|
| 区块主题（FSE 路径） | 57 | **57** | 0 | 0 | ~2.5s |
| 经典主题（渲染路径） | 37 | 33 | — | — | ~20s |

- 区块主题里 10 个（blockwist / calm-canvas / flexa / neve-fse / nutri-coach / quarkora / quillwork / raft /
  the-coffee-shop / therapy-coach）的 `front-page.html` **本身就是落地页**（hero + 特性 + CTA，没有 `wp:query`）
  ⇒ 抓出来的 `pageHome` 没有 `{{CONTENT}}`。这是**忠实还原**（真实首页也没有文章列表），不是 bug；
  但「CMS 首页没有文章列表」对博客站来说可能不符合预期，要不要在 CMS 侧兜一层（首页无列表时自动补）待定。
- 经典主题 4 个失败：`dacnis`（定位不到条目容器）、`hello-biz`（首页渲染时探针不在页内）、
  `iris-wp`（归档页渲染 exit=1）、`ona-architecture`（5 种页面全部渲染失败）。
  这 4 个是**渲染链路本来的问题**，与本次 FSE 改动无关（失败时保留上一套模板，日志里有明确原因）。


### 8.3 区块主题链路的两个真 bug（2026-10-22 修，都只有跑多主题才暴露）

**① 模板优先级反了：`index.html` 覆盖 `home.html`**
`$files` 里 `front-page.html / home.html / index.html` 都映射到 `pageHome`，旧代码是「后到先赢」⇒ `index.html` 赢。
但 `twentytwentyfour` 的 `home.html` 是**首页落地页**（Études 那套），`index.html` 才是博客列表 ⇒
抓出来的「首页」跟真实首页完全不是一回事（TT4/TT5 全中招，其它主题大多没有 home.html 所以看不出来）。
修法：改成**先到先赢**（= WP 自己的模板层级 front-page > home > index），但 `index.html` 仍然要跑一遍 ——
它可能是唯一带 `wp:query` 的模板，条目骨架只能从它身上取。

**② 兜底清理写在循环体内，把下一轮该展开的 pattern 提前抹掉**
展开 parts/patterns 是「多轮交替」的：一轮只展开一层，下一轮继续。旧代码每轮末尾都执行
`preg_replace('/\{\{(?:PART|PATTERN):[^}]*\}\}/', '', $v)`，于是**本轮刚展开出来、下一轮才该继续展开**
的 `{{PATTERN:x}}` 被当场抹成空串。后果：

- `twentytwentyfour` 首页 → `page-home-business` → 6 个 section，第二轮把 6 个 section 全抹掉 ⇒
  首页只剩 header + 空 `<main>` + footer（看起来像「CSS 丢了」，其实是**内容**没了，极易误判）
- `mroya` 四层链（home → template-home-studio → page-home-studio → section → query-grid）更彻底，
  条目模板直接为空

单层 pattern 的主题不受影响，所以「只抽几个主题试试」永远发现不了。
修法：清理移到循环**外面**（`item` 的二次展开循环同样处理）。


### 8.4 风格包资源：从「整目录拷」改成「引用可达性」（2026-10-22）

**问题**：旧打包逻辑是「按扩展名递归拷整个主题目录」。后果是包里塞满没人读的东西 ——
`theme.json`、`styles/*.json`、整套 svg 图标库、`woff2`+`ttf`+`eot` 三套字体兜底、demo 图。
90 个测试包共 **403.9 MB**，其中：

| 内容 | 体积 | 说明 |
|---|---|---|
| `tpl.json` ×90 | 32.6 MB | 必需 |
| `manifest.json` ×90 | 0.4 MB | 必需 |
| 主题自带 json ×803 | 6.5 MB | **死重量**，全是 `theme.json` / `styles/*.json` / `preset.json` |
| 其余（svg 2751 / woff2 634 / ttf+eot+woff 727 / 图片） | ~364 MB | 大部分用不到 |

**关键事实：Worker 侧一个都不读。** 取证：

- `worker/src/tplstore.js:65` 硬编码 `wp-content/themes/<id>/tpl.json`；`:69` 读 `tpl.<lang>.json`
- `worker/src/site.js:235` 只读 `wp-content/themes/index.json`（风格清单）
- `worker/src/preview.js:34` 同上（画廊预览）
- 全仓 `grep "theme.json"` → **零命中**
- Worker 内置 16 个风格包里 json 只有 `tpl.json`×15 + `tpl.en.json`×1 + `index.json`×1，
  一个 `theme.json` 都没有
- 内置包 `tpl.json` 内部引用的资源全是 `{{SITE_URL}}/wp-content/themes/<id>/…css|woff2|jpg`，**零个 `.json`**

`theme.json` 为什么不需要带：它的产物是 WP 编译好的 CSS 变量，经 `wp_enqueue_global_styles`
内联成 `<style id="global-styles-inline-css">` 塞进 `wp_head()`。抓取时
`ob_start(); wp_head();` 拿到的整段 head 已经含这段内联样式，直接烤进 `tpl.json` 了。
包里那份 `theme.json` 是「已经兑过奖的彩票」。

**修法**：`class-style-pack.php` 新增 `cfse_style_collect_assets()`，沿引用递归收集：

```
tpl.json → css → url() / @import → 字体 / 背景图
```

- 必须递归：主题的 `style.css` 常常只有一行 `@import`，真正规则在 `assets/css/` 下；
  那些 css 又用 `url(../fonts/x.woff2)` 引字体，少走一层就缺字体
- **不遍历目录**：没被引用到的文件一律不进包
- 白名单里去掉 `json` / `map`（前者没人读，后者线上无意义）

**实测**（改动前后同一份抓取，四个主题）：

| 主题 | 旧包 | 新包 | 文件数 | 引用断链 |
|---|---|---|---|---|
| `twentyten`（经典） | 1.3 MB | 212 KB | 29 → 4 | 0 |
| `twentytwentyfive`（区块） | 8.3 MB | 424 KB | 121 → 3 | 0 |
| `the-coffee-shop`（区块） | 19.9 MB | 3.3 MB | 186 → 12 | 0 |
| `saaslauncher`（区块） | 7.1 MB | 4.9 MB | 196 → 58 | 1（主题自身坏引用） |

四包合计 36.5 MB → 8.8 MB（-76%）。

**两个实现坑**（都是实测撞出来的）：

1. **逗号截断**：可变字体文件名带逗号 —— `OpenSans-VariableFont_wdth,wght.ttf`。
   提取引用时为了处理 `srcset`（`"a.jpg 100w, b.jpg 200w"`）把逗号当分隔符，
   结果字体名被截成 `..._wdth`（不存在的路径）。修法：CSS/SVG 内容里允许逗号（`url()` 没有 srcset 语义），
   只对 HTML 文本排除；再加一道「A 是已收到文件 B 的前缀 ⇒ A 是截断产物」的清理。
2. **`wp-content/themes/<slug>/*` 这种说明性文字**会被当成引用 ⇒ 包里出现 `missing: *`。
   修法：`$push` 里过滤通配符和模板片段，以及「既没目录也没扩展名」的串（JS 里的 `url(a)` 也靠这条挡掉）。

**新增 `tools/verify-pack.php`**：把收集器指向已生成的包自身（`theme/` 目录）再跑一遍，
`missing` 就是包内所有断链。复用收集器而不是另写检查，是为了避免
「生成时算通过、校验时算失败」这种自相矛盾。

```bash
php tools/verify-pack.php <slug>
```

依赖 `cfse_style_theme_dirs()` 上的 `cfse_style_theme_dirs` filter（把主题根改指向包目录）。


