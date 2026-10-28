# 风格抓取审计结论 + 本轮修复记录（2026-09-25 会话）

> 本文件是「把底摸一下 → 动手修」的完整产出：两条抓取路线的实测结论、形态重组性价比判定、
> 扫描到的真实缺陷清单、**已做的修复与验证数据**、以及剩余问题。
>
> 相关：`docs/skin-placeholder-split.md`（分工原则）、`docs/skin-extraction-v3.md`、`docs/style-refactor.md`。

---

## 0. 本轮新增的三个工具（只读，可复用）

| 工具 | 用途 |
|---|---|
| `tools/split-tpl.mjs` | 产物形态重组（v1 整页 → v2 共享 header/footer + 每页型片段）+ **逐字节等价自校验** |
| `tools/skin-leak-audit.mjs` | 扫产物里的「**快照泄漏**」——凡「本该是洞却烘焙进去」的值，都以"只可能来自抓取时刻"的形态残留 |
| `tools/skin-css-audit.mjs`（本会话早些）| head CSS 的三层归属：站点级 / 块类型级 / 块实例级 |

```sh
node tools/split-tpl.mjs   --check        # 形态重组可行性 + 收益（--write 才写回）
node tools/skin-leak-audit.mjs [--all]    # 快照泄漏审计（默认只打印有命中的包）
node tools/skin-css-audit.mjs --detail    # head CSS 三层归属
```

---

## 1. 结论：**形态重组不做**（实测收益远小于预期）

`tools/split-tpl.mjs --check` 对 14 个内置包：**切分全部逐字节等价（14/14）**，但收益：

| | 值 |
|---|---|
| 共享段合计（header + footer） | 31.9 K |
| 因「每页型各存一份」被重复的 | **127.6 K（raw）** |
| 折算到**一个站点**（只用一个包）| 每页型省 ~3 K gzip（经典包）/ ~9 K（saaslauncher）|

而且它要么**制造双轨**（提取器仍产 v1、手工转 v2），要么得**同时改提取器 + 全量重抓**。
⇒ **成本远超收益，判定：不做。** 工具留作「等价性验证器」。

> 修正记录：会话早段曾把「形态重组」排为第 0 步，理由是基于「单页 411 K / 六类合计 1.78 MB」的体积直觉。
> gzip 实测后该理由大幅减弱（saaslauncher 单页 411 K → **gzip 53.7 K**；站点级 CSS 冗余 gzip 76.9 K / 14 包）。

---

## 2. 结论：**经典主题不能"读文件取骨架"**（三条铁证，均为实测）

解剖 `twentyten` 原始主题源码：

1. **模板是薄壳、真结构在使用共用的 loop**：`index.php` 903 B（有效内容 4 行）、`single.php` 556 B；
   页型差异**全部**在 `loop.php` 内部的 `if ( is_archive() || is_search() ) : the_excerpt() else : the_content()`。
   ⇒ 一个 `loop.php` 服务 4 个页型；差异在运行期条件里，**读文件读不出来**。
2. **HTML 本身在运行期成形**：`header.php:75/82`
   ```php
   $heading_tag = ( is_home() || is_front_page() ) ? 'h1' : 'div';
   <<?php echo $heading_tag; ?> id="site-title">          // 标签名是变量
   ... <?php echo $is_front ? 'aria-current="page"' : ''; // 属性随上下文
   ```
3. **有些骨架源码里只有"兜底"**：`sidebar.php:21` 的真骨架来自 `dynamic_sidebar()`（widget 数据库）；
   `footer.php:44` 的 `do_action('twentyten_credits')`、`functions.php:468` 的 `twentyten_comment()` 回调同理。

**块主题相反**：`templates/*.html` 是纯 HTML（字面骨架 + 块注释就是洞），但**壳**（head / `body_class()` / block wrapper）仍必须渲染。

⇒ 现状的**混合策略**（经典＝渲染 + 值锚；块＝读文件 + `render_block()`）**是对的**，不是"跑偏"。
真正该改进的是**洞的识别方式**：现在靠"值锚猜"（覆盖率 by luck），应改成"源码出洞清单 + 值锚定位"（by construction）——**未实施**。

---

## 3. 审计结果与修复状态

修复前（`skin-leak-audit`，5/14 包命中）：`L1 探针残留 1 / L3 title 固化 4 / L4 feed title 16 / L5 时间戳 1`。
修复后：**`L1 0 / L3 0 / L4 10 / L5 1`**。

| # | 规则 | 现象 | 状态 | 修法 |
|---|---|---|---|---|
| 1 | **L3** | 归档页 `<title>` 固化成抓取当月（`September \| 2026 \| {{SITE_NAME}}`）| ✅ **已修** | `views/skin-builder.php` → `cfse_skin_page_shell()`：新增 archive 分支，把「月份+年份」→ `{{TITLE}}`（只对 `$type === 'archive'`）|
| 2 | **L1** | 探针 slug 残留：`{{SITE_URL}}/cfseprobeXX-title/` 及其 `#respond` 变体没被占位化（`twentynineteen`）| ✅ **已修** | `cfse_skin_replace_site()` 末尾：把探针 permalink 的 path 段换成 `{{POST_URL}}`（长形态先替换）|
| 3 | **L4** | RSS link 的 `title` 硬编码站点名（`yuebin&#039;s blog &raquo; Feed`）| ⚠️ **经典包已清（重抓即可）**；`saaslauncher` **未修** | 经典：`cfse_skin_replace_site()` 早已在 `cfse_skin_single_shell()` 里调用（:1423），那 3 个包只是**旧产物**；块：需改 `tools/fse-skin-extract.php` 的 `fse_shell_parts()` |
| 4 | **L5** | `<time datetime="2026-09-25T12:00:49+00:00">` —— 日期是**抓取时刻** | ❌ **未修**（见 §5）| 已尝试扩充 `cfse_skin_date_candidates()`（加 ISO 格式 + modified），**未生效** |
| 5 | 缺口 | **导航菜单是固化 HTML，没走 `{{MENU}}`** ⇒ 后台改菜单对这些包不生效 | ❌ 未做 | 需在 `skin-builder.php` 新增 nav menu 占位化 |

> 共同机理：**这些全出在「壳」上，不在骨架上**。骨架的洞"看得见"，壳的洞靠上下文反推 ⇒ 反推不到就烘焙住了。

---

## 4. 本轮实际改动（可回滚）

**代码**（`views/skin-builder.php`，本地与服务器 md5 一致后修改）：

1. `cfse_skin_page_shell()`：`archive` 的 `<title>` 日期归档 → `{{TITLE}}`；（修复 §3-1）
2. `cfse_skin_replace_site()`：探针 permalink 的 slug 段 → `{{POST_URL}}`；（修复 §3-2）
3. `cfse_skin_date_candidates()`：加 `post_modified` / `post_modified_gmt` 与 ISO 格式 `'c'`、`'Y-m-d\TH:i:sP'`；调用处传 modified。（**意图修 §3-4，但实测未生效**）

**服务器操作**：

- 改前备份：`/tmp/skin-builder.php.bak-20260925`
- 门禁：每次 `docker exec web-php php -l` **通过才覆盖**
- 探针：`/mnt/optane/webstack/www/cfse-fpm-run.php`（一次性，**用完已删**）；支持 `?t=<theme>` 切主题，抓完**立即切回** + `register_shutdown_function` 兜底
- 重抓：`twentyten`（不切主题）、`twentyeleven`、`twentyfifteen`、`twentynineteen`（切主题→抓→切回）
  - 每次抓取后复查主题 = `twentyten` ✅；探针矩阵自动清理，**`CFSEPROBE%` 残留 = 0** ✅
  - 耗时 16.9–20.9 s/主题
- 产物已拉回本地 `worker/static/wp-content/themes/<slug>/tpl.json`（4 个包）

**验证**：`node tools/skin-leak-audit.mjs` → `L1 1→0`、`L3 4→0`、`L4 16→10`（经典包全清）。
聚焦测试（`tools/cfse-focus-test.php`，零副作用）：`cfse_skin_replace_site` 探针 slug 替换 **5/5 PASS**。

---

## 5. 剩余问题（下次接手从这里继续）

1. **`twentynineteen` 的 `<time>` 日期仍是抓取时刻**（§3-4）。已加 modified/ISO 候选但未命中 ⇒ 下一步应诊断
   `cfse_html_element_by_marker($html, 'id="post-N"')` 取到的容器**是否包含**该 `<time>`（怀疑它在容器之外，
   或 `cfse_skin_replace_item` 的日期替换 `break` 语义吃掉了正确候选）。注意：**旧产物同样是错的**（值更早），故未变差。
2. **`saaslauncher` 的 L4**（10 处）：`fse_shell_parts()` 产出 head 后未做站点名归一化 ⇒ 需改 `tools/fse-skin-extract.php`
   （该文件是**块主题**路线，与经典路线独立）。
3. **导航菜单未占位化**（4 个经典包）：改 CMS 菜单对这些包不生效。
4. `secure.gravatar.com` 应加入 `skin-leak-audit.mjs` 白名单（评论头像，第三方域，非泄漏）。

---

## 6. 不是 bug 的项（记录以免下次误判）

- **`tj.yuebin.uk`**（20 处）：导航菜单里的一条**自定义链接**（`内镜统计系统` → `https://tj.yuebin.uk`），
  出现在 4 个经典包的每个页型。本身不是泄漏，但揭示了 §3-5 的菜单缺口。
- **`www.w3.org` / `s.w.org` / `gmpg.org` / `wordpress.org`**：命名空间、emoji 脚本、xfn profile、WP 版权链接 —— 已列入白名单。

---

## 7. 本次会话保留的只读工具/样本（按用户要求不删）

- `tools/skin-css-audit.mjs`、`tools/split-tpl.mjs`、`tools/skin-leak-audit.mjs`
- `tmp/skin-css-audit.json`（三层判定明细）、`tmp/themes/twentyten/`（主题源码解剖样本）
- `tmp/probe-*.mjs`、`tmp/find-iso.mjs`、`tmp/check-new.mjs`、`tmp/newstyles/`（新产物对照）
- 服务器：`/tmp/fetch-one.sh`（抓单个主题）、`/tmp/probe-baseline.sh`、`/tmp/cleanup.sh`、`/tmp/cfse-focus-test.php`

---

## 8. 第二轮（同日）：补上 `{{MENU}}`（A 类缺口）

**做了什么**（提交 `173b9f0`）：

- `views/template-editor.php`：`$ctx` 新增 `menu_item_ids`（遍历 `get_nav_menu_locations()` 收集 WP 菜单项 ID）
- `views/skin-builder.php`：新增 `cfse_skin_nav_menu()` —— 用 **`menu-item-<ID>` 作值锚**（不依赖主题 class）
  定位「第一个 → 最后一个菜单项」范围，向上取最近的 `<ul>` 作菜单根，整段换成
  `{{#IF_MENU}}{{MENU}}{{/IF_MENU}}`（保留 `<ul>` 的父容器外壳，与 FSE 提取器对 `wp:navigation` 的处理一致）。
  `page_shell` / `single_shell` 各调用一次。
- **配套（必需）**：把现有 WP 菜单导出并写入 CMS（`PUT /api/settings` → `site:ctx.menu`），
  否则 `{{MENU}}` 渲染为空 ⇒ **菜单会消失**。实测 4 项 → 12 项（含 2 个子项），同域地址归一化为相对路径。

**验证**：4 个经典包重抓后均 `MENU=true` / `IF_MENU=true` / `tj.yuebin.uk 残留=false`；
部署 `93e2bdeb`；线上首页（`style.active = twentyten`）菜单渲染正常（含 `sub-menu`）、`{{MENU}}` 0 残留；
twentyten 产物 110095B → 98088B。

**踩到的坑（都值得记）**：
- 清理探针后忘了重传 ⇒ `curl` 404 ⇒ **抓取"静默没跑"**（靠输出空白才发现）；
- PowerShell `Set-Content -Encoding UTF8` 写出的 JSON **带 BOM** ⇒ `JSON.parse` 失败（脚本里 `.replace(/^\uFEFF/,'')` 兜住）；
- `POST /api/login` 返回的 token 字段名是 **`access`**，不是 `token`；
- `Invoke-WebRequest` 把 UTF-8 响应按 Latin-1 解码 ⇒ 中文 grep 误判（实际内容是对的）；
- **`settings` 表在 D1，不在 WordPress 的 MySQL 里**（查 d1 要用 `wrangler d1 execute`）。

---

## 9. 关于 B（`{{BODY_CLASS}}`）：结论是**不需要抓**

用户问"B 是不是很难抓"—— 是的，但**不需要抓**：

- **经典链路已经抓到了**：`cfse_skin_render_all()` 本就每页型一个进程 ⇒ `body_class()` 各自正确
  （实测产物里是 `home blog` / `archive category` / `single single-post`）。要做的话只需"把它变成洞"。
- **块链路抓不到**：`fse_shell_parts()` 在 CLI 上下文调 `get_body_class()` ⇒ 只有兜底值（实测六类全同）。
- ⇒ **对"单页双列/单列"这个目标，最省事的做法不是抓 body class，而是由 worker 注入我们自己的布局 class
  （如 `cfse-layout-single`）+ 一段布局覆盖 CSS**：不动提取器、不重抓、经典与块主题通用。
  完整 `{{BODY_CLASS}}` 洞的收益有限（body class 其余取值对静态站影响小），**建议不做**。

**下一步候选**（按价值）：
1. **布局开关**（单页双列/单列）—— 走"自己的布局 class"路线，独立于抓取链路；
2. `saaslauncher` 的 L4（10 处，块主题提取器的站点名归一化）；
3. `twentynineteen` 的 `<time>` 日期（§3-4），需先诊断容器边界；
4. `ury` 那个块主题抓取失败的原因。

