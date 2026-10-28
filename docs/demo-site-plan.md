# 官方演示站改造方案（yuebin.uk）

> 状态：**第一版已落地**（2026-10-19）。本文记录信息架构、内容清单、已执行的变更、后续计划与回滚方式。
> 约束：只改线上 CMS 数据；本地 WordPress 完全不动；文档库（cf-docs）暂不介绍；WP 插件作为独立栏目。

---

## 一、结论：六个栏目，两条主线

把站点从"个人测试博客"改成"产品官网 + 文档入口"，栏目按**读者意图**划分，而不是按功能模块堆：

| 分类 | slug | 回答的问题 | 内容 |
|---|---|---|---|
| 快速开始 | `start` | 这是什么？怎么跑起来？ | 产品概览、架构、两条部署路线 |
| 使用指南 | `guide` | 后台怎么用？ | 后台导览、风格、评论、缓存 |
| **WordPress 插件** | `wordpress` | 插件怎么装、怎么配？ | 安装配置、三级生成、多出口 |
| 扩展与集成 | `extend` | 能不能接我的工具？ | Obsidian、REST API |
| 版本更新 | `release` | 改了什么？ | 版本说明（按版本号） |
| 关于项目 | `about` | 谁做的？边界在哪？ | FAQ、路线图、演示站本身 |

### 为什么这样分

1. **WordPress 插件单独成栏**：它将来是独立模块，有自己的安装、配置、发布、transport 体系。混在"插件与集成"里会让 CMS 用户误以为它是可选配件。
2. **快速开始与使用指南分开**：前者是"决策 + 跑通"，后者是"日常使用"。新用户不需要先读后台导览。
3. **版本更新单独成栏**：更新说明是长期资产，混在"关于"里会被淹没。
4. **文档库不出现**：cf-docs 还不完善、后续会剥离，现在介绍只会制造预期落差。相关文章已删除，后台导览、FAQ、路线图里的表述也一并清掉。
5. **风格只讲 CMS 侧**：`tpl.json` + 占位符 + 在线换肤。WP 主题抓取太复杂，不作为演示站内容，最多在插件文档里一句带过。

### 导航

顶栏 6 项：快速开始 / 使用指南 / WordPress 插件 / 扩展与集成 / 版本更新 / 关于项目。
首页承担"产品介绍"：Hero、两条路线、能力网格、最新文章、CTA。
分类页承担"目录"：标题 + 篇数 + 两列卡片。
页脚列出全部分类、归档与 RSS/GitHub。

---

## 二、内容清单（15 篇，均已发布）

| 分类 | slug | 标题 |
|---|---|---|
| start | `what-is-cfse` | 认识 CF Static Export Pro：一套插件，两种建站方式 |
| start | `architecture` | 动静分离是怎么工作的：D1、R2、KV 各管什么 |
| start | `quick-start-worker` | 5 分钟搭起一个 Cloudflare 独立 CMS |
| guide | `admin-tour` | 后台导览：内容、分类、媒体、模板、评论 |
| guide | `styles` | 风格系统：一个目录就是一套皮肤 |
| guide | `comments` | 静态站上的评论：提交、审核与回写 |
| guide | `cache-and-performance` | 缓存与成本：为什么列表实时、单页飞快 |
| wordpress | `quick-start-wordpress` | （同上，归入插件栏目） |
| wordpress | `wp-plugin` | WordPress 插件：三级生成、多出口与版本升级 |
| extend | `obsidian-plugin` | 在 Obsidian 里写完，一键发布 |
| extend | `rest-api` | 用 REST API 接入任何编辑器 |
| release | `release-2-5-0` | v2.5.0：分类表升级、assets 直出与一堆清理 |
| about | `faq` | 常见问题 |
| about | `demo-notes` | 这个演示站是怎么搭的 |
| about | `roadmap` | 路线图与设计取舍 |

> 注：`quick-start-wordpress` 同时出现在"快速开始"的阅读路径里（从首页 CTA 进入），但分类归属是 `wordpress`。

写作约定：

- 一篇只讲一件事，标题具体；
- 教程按"前置 → 步骤 → 验证 → 常见卡点"；
- 代码块可直接复制；
- 内部链接形成路径（概览 → 快速开始 → 使用指南 → 扩展）。

---

## 三、已执行的变更（2026-10-19）

### 1. 新风格 `cfse-demo`

- 目录：`worker/static/wp-content/themes/cfse-demo/`（`tpl.json` + `style.css`）；
- 6 类模板 + 侧边栏 + 评论骨架，`skin-validate` 通过；
- 已上传 R2 并应用为当前风格；
- 首页：Hero、数据条、两条路线、6 张能力卡、最新文章、CTA；
- 单页：窄栏阅读版式（760px），含面包屑、上下篇、评论；
- 列表页：两列卡片，分类/标签/归档共用版式；
- 没有使用站点 Logo 与横幅（原图是个人素材），品牌标识用 CSS 方块 + 站名。

> 该目录同时存在于本地 `static/`，下次 `npm run deploy` 会自动进 `themes/index.json`，成为内置风格。

### 2. 站点设置

| 项 | 新值 |
|---|---|
| 站点名 | `CF Static Export Pro` |
| 描述 | `WordPress 静态化 + Cloudflare 独立 CMS` |
| 菜单 | 6 个分类项（见上） |
| Logo / 横幅 | 清空（去掉个人素材） |
| 社交 | 仅 GitHub |
| `headExtra` | 清空（去掉 AdSense） |
| `docRoutes` | 清空（不再暴露文档库） |
| 列表每页 | 8 篇 |
| 批量任务 | 20 篇 / 并发 4 |

### 3. 旧内容处理

- 285 篇测试文章 → **全部转为草稿**（数据保留，可恢复）；
- 对应的 R2 单页在转草稿时一并删除 ⇒ 旧链接现在 404；
- 旧分类（生活 / 胡思乱想 / 资源 / hosting）仍留在 `taxonomies`，但已无已发布文章，不出现在侧边栏；直接访问分类 URL 会得到空列表页。
- 73 条旧评论保留在 D1（无文章引用，不影响前台）。

### 4. 安全

- 操作使用临时管理员 `cfse_ops` 完成，**已删除该账号**；
- 本地备份在 `tmp/demo-backup/`（文章全量、设置、分类关系、评论、文档、用户列表）。

---

## 四、回滚方式

| 要恢复什么 | 怎么做 |
|---|---|
| 旧文章 | `UPDATE posts SET status='published' WHERE wp_id IN (...)`，再「重建全部单页」 |
| 旧分类 | 分类行还在，重新给文章挂上即可 |
| 旧风格 | 后台模板页切回 `minimal-reading` |
| 站点设置 | 用 `tmp/demo-backup/` 里的 settings 备份覆盖 `settings k='site'` |
| 完全回滚 | 按备份 JSON 重建 D1 行 + 重建单页（R2 单页会按需重新生成） |

> R2 单页删除是**可逆**的：只要 D1 里文章还在，访问 URL 会触发按需渲染并回填。

---

## 五、后续计划

### P1：插件发布（下一步）

1. 给 WP 插件补发布元信息：版本号、更新日志、`readme.txt`、截图；
2. 打包 `cf-static-export-pro-<version>.zip`（排除 `.git`、`tmp`、`worker` 开发目录）；
3. 产物放 R2 `downloads/`，在"WordPress 插件"分类下加一篇「下载与安装」；
4. 每次发版更新：版本号 + changelog 文章 + 下载链接（文件名带版本，旧版保留）；
5. Obsidian 插件同样处理：构建 `main.js` → 打包 → 下载页 + 安装说明。

### P2：栏目深化

- `wordpress` 栏目补：transport 矩阵（S3/GitHub/Netlify/Vercel/FTP/WebDAV）、常见报错、从 2.4 升级；
- `release` 栏目补历史版本（按现有插件头注释与 git 记录回溯）；
- `guide` 栏目补：媒体库、用户与权限、备份与导出。

### P3：站点体验

- 归档月份改中文（当前 `October 2026`，来自渲染器的英文月份表）；
- 分类页加一句栏目说明（当前只有标题 + 篇数，CMS 分类表没有 description 字段，需要改表或写死映射）；
- 首页 Hero 的文案做成可配置（现在写在 `tpl.json` 里，改文案要改模板）；
- 给文章加封面图时，卡片显示缩略图（`item` 模板已支持 `{{#IF_THUMBNAIL}}`，当前没启用）。

### 明确不做

- 不在演示站介绍文档库（等 cf-docs 剥离完成）；
- 不做"从 WordPress 主题抓取风格"的教程（复杂度高，放插件文档）；
- 不迁移本地 WordPress 数据。

---

## 六、修复记录

### 2026-10-19：正文里的 `{{...}}` 被当成模板占位符

**现象**：`/styles/` 的占位符表格里，`{{POST_URL}}` 变成了当前页 URL，`{{SIDEBAR_CATEGORIES}}` 变成了一串分类 `<li>`，其余 `{{SITE_NAME}}` 等被整段清空。

**原因**：`renderSingleHtml()` 里 `{{POST_CONTENT}}` 在中间就被替换，后面还有 `POST_URL` / `POST_CATEGORIES` / `SIDEBAR_*` 等 `rep()`，以及出口的 `cfseStripLeftovers()`；正文里的示例文本因此被二次替换或清理。

**修复**：正文先用哨兵 `@@CFSE_BODY_7F3A@@` 占位，所有模板替换与残留清理跑完后，最后再注入正文；`injectCodeAssets` / `cfseNoReferrerImgs` 也移到注入之后。已部署（Version `fc8a853d-cb53-4fb7-8c5f-99a63db91e13`），15 篇单页已重建。

### 2026-10-19：代码块深色底 + 浅色高亮主题

**现象**：代码块背景是深色 `#0d1117`，但 `injectCodeAssets` 注入的是 highlight.js 的 `github.min.css`（浅色主题，深色 token），导致代码几乎看不清。

**修复**：`cfse-demo` 的 `.content pre` 改为浅色 `#f6f8fa` + 边框，与 github 高亮主题一致。

### 2026-10-19：插件文章标题

标题从「三级生成、主题抓取与多出口」改为「三级生成、多出口与版本升级」，与"不介绍 WP 主题抓取"的约束一致。

---

## 七、日常维护

- 写文章：`/admin/` → 文章 → 新建（Markdown）；
- 发版：写一篇 `release` 分类文章，标题带版本号；
- 改外观：模板 → 编辑 `cfse-demo` → 保存 → 重建全部单页；
- 改导航/站点信息：设置 → 保存（列表页立即生效，单页需重建）；
- 备份：定期导出 D1 的 `posts` / `settings` / `taxonomies`，以及 R2 的 `wp-content/uploads/`。
