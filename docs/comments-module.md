# 评论模块方案（CFSE · 2026-09-19）

> 决策文档：评论在「WordPress → 静态站」链路里怎么做。**尚未实施**（除 §1.3 已改的探针部分）。
> 相关：`docs/skin-extraction-v3.md`（风格抓取）、`AGENTS.md`（项目交接）。

## 0. 一句话结论

> 状态（2026-09-19，**三条都已完成**）：
> - **骨架提取**：探针 3 条评论 → `tpl.json` 的 `comments` 段 = `{list 外壳, entry 单条, children 子条}`；
> - **路线 A**：设置页「💬 评论」`cf_comments_mode`（off/embed/native）+ `cf_comments_embed`；`site:ctx` 透传；Worker 填 `{{COMMENTS}}`；
> - **路线 B1**：D1 `comments` 表（三处 schema）+ WP 钩子单向同步（开关 `cf_comments_sync`，默认关）+ `cfseCommentItems()` 按骨架再生；
> - **路线 B2**：`POST /comments`（公开 + IP 限流，由 `cf_comments_accept` off/pending/auto 控制）+ `GET /sync/comments?unpulled=1` + WP 侧 `pull_comments_from_worker()`（meta `_cfse_remote_comment_id` 映射、不回环）。
> 收尾两条也已实现：**① 前台提交表单**（保留主题 `#respond` 改写为走 `/comments` + 内联脚本提交）；**② 半动态评论区**（标记 `<!--CFSE-COMMENTS-START id=N-->` + Worker 在 R2 命中时从 D1 现场注入，`max-age=60`）⇒ 改评论无需重建单页。
> 已完成：① 前台提交表单 ② 半动态评论区 ③ 回复按钮（嵌套渲染）④ 反垃圾（蜜罐 + 最小停留 + 内容校验）⑤ 尊重文章 `comment_status` ⑥ **Worker 后台评论控制台**（设置 + 审核/删除未回拉的评论）+ WP 侧**每小时定时回拉**（`cfse_pull_comments_cron`，默认开）。
> 仍缺（可选增强）：验证码 / Akismet 类强反垃圾、评论分页、评论通知、`comment_count` 进 posts 表、「清 Cloudflare 缓存」按钮（需 Zone:Cache Purge token）。
> ⚠️ 动 KV 后要等 ≥60 秒（Cloudflare KV 最终一致）再验证。

**先上「注入式独立模块」（路线 A）**——成本最低、立即可用、零数据责任；把「Worker 评论系统」（路线 B）作为**可选第二阶段**；
而「评论骨架提取」不是独立路线，它是 A 的「定位 + 清空原生评论区」和 B 的「渲染」共用的**基础设施**（已完成一半）。

## 1. 现状（已核实）

### 1.1 插件侧：完全不碰评论
- `cf-static-export-pro.php` 里 `wp_comments` / `comments_template` / `wp_insert_comment` **零匹配** ⇒ 没有同步、没有导出、没有导出钩子。
- `schema.sql` 只有 7 张表：`posts / settings / users / taxonomies / post_taxonomy / styles / app_passwords` ⇒ **D1 无 comments 表**。

### 1.2 Worker 侧：评论位是空的
- `worker/src/admin.js:482` `cfseIfBlock(h, 'COMMENTS', false)` ⇒ **恒 false**；`:486` `rep('{{COMMENTS}}', '')`。
- 抓取产出的 `single` 里评论区是「空 `#comments` 容器 + 元数据 ID 占位 + `{{#IF_COMMENTS}}`」。

### 1.3 本次已改（**已 lint 通过，未提交**）
`views/template-editor.php`：探针 A2 插 **3 条评论**（2 顶层 + 1 嵌套回复；CA 无作者 URL、**CB 有作者 URL**、CC 是回复），A1/A3 无评论；
探针删除时显式清评论并报条数（正常路径 + 异常路径）。
另外用临时探针 dump 出 twentyten 的**真实评论结构**（见 §5），这是写骨架/注入点的依据。

## 2. 三条路线对比

| 维度 | **A 注入式独立模块**（Disqus/Utterances 同构） | **B Worker 评论系统**（单向同步 + 拉取） | **C 纯静态骨架**（只提取，不接数据） |
|---|---|---|---|
| 实现成本 | 极低（约 1 天） | 高（表 + 同步 + 提交 + 限流 + 渲染） | 中（骨架 + 渲染端填充） |
| 评论是否「活」 | 活（第三方负责） | 活（自己负责） | **死**：只是上线那一刻的快照 |
| 与静态站契合 | 最好（iframe/脚本，天然解耦） | 好，但要决定「新评论可见性」（§4.2 修正②） | 一般 |
| 数据归属 | 第三方（隐私/GDPR 需评估） | 自己（要治理垃圾评论） | 无 |
| 外部依赖 | 第三方服务 | 只有 Cloudflare + 自己的 WP | 无 |
| 与主题原生观感 | 换成第三方 UI | **完全一致**（用主题原生结构） | 一致，但无数据 |
| 复用现有设施 | 复用「定位/清空评论区」 | 复用 posts 全套（D1 / 批量 / 同步 / 单页渲染） | 已完成大半 |

## 3. 路线 A：注入式独立模块（**✅ 已实现，2026-09-19**）

### 3.1 设计
- 新增设置：`cf_comments_mode`（`none` 隐藏 / `embed` 第三方注入 / 后续可扩展 `native`）+ `cf_comments_embed`（多行 HTML/JS，用户自填，例如 Utterances/giscus/Disqus/Cusdis 的片段）。
- 抓取阶段（`cfse_skin_single_comments()`）新增 `embed` 分支：**保留 `#comments` 容器位置，清空原生评论列表**，放入 `{{#IF_COMMENTS}}{{COMMENTS_EMBED}}{{/IF_COMMENTS}}`。
- Worker 渲染（`admin.js`）：`{{COMMENTS_EMBED}}` ← 站点设置（走 `site:ctx`）；`{{#IF_COMMENTS}}` 改为「embed 非空」。
- 顺带清理：`#respond` 表单（已删）、`.reply` 回复块、评论 feed `<link>`、（可选）`comment-reply.min.js`。

### 3.2 为什么它最划算
- **不需要** `comments` 表、同步链路、提交端点、限流、审核、嵌套渲染、分页 —— 上面这些在 B 里都是要单独做且要长期维护的。
- 与「静态站」的模型一致：评论本来就是动态数据，交给动态服务。
- **正好复用已完成的工作**：探针评论 + 评论容器定位（用来"找到位置 + 清掉原生评论区"）。

### 3.3 实施步骤
1. WP 设置页新增「💬 评论」卡：模式下拉 + embed 代码文本域 + 当前生效预览；保存写入 `site:ctx`（复用 `sync_site_info` 通道）。
2. `cfse_skin_single_comments()` 增加 `embed` 分支（默认仍是现状行为，零回归）。
3. Worker `admin.js`：填 `{{COMMENTS_EMBED}}`、改 `{{#IF_COMMENTS}}` 的判定；`routes.js`/`routes.template.js` 若涉及 `site:ctx` 字段需同步。
4. 真机验证：抓取 → 单页 200 → 页面里出现注入代码、原生评论区与 `.reply` 消失。

## 4. 路线 B：Worker 评论系统（**✅ 已实现**：B1 只读同步 + B2 公开提交与回拉）

### 4.1 数据模型
```sql
CREATE TABLE IF NOT EXISTS comments (
  comment_id   INTEGER PRIMARY KEY,   -- 与 WP comment_ID 对齐
  post_id      INTEGER NOT NULL,      -- WP 文章 ID（= posts.wp_id）
  parent_id    INTEGER NOT NULL DEFAULT 0,
  author       TEXT,
  author_email TEXT,
  author_url   TEXT,
  date         TEXT,
  content      TEXT,
  approved     INTEGER NOT NULL DEFAULT 0,
  type         TEXT DEFAULT 'comment',
  updated_at   TEXT
);
CREATE INDEX IF NOT EXISTS idx_comments_post ON comments(post_id);
CREATE INDEX IF NOT EXISTS idx_comments_parent ON comments(parent_id);
```
三处同步（规矩同 posts）：Worker `ensureCoreSchema` / WP `ensure_d1_schema` / `schema.sql`。

### 4.2 同步：**单向 + 拉取**（对原始设想的两处修正）

**修正①：「回拉」应改成「WP 主动拉取」。**
原设想是「Worker → WP：新评论回拉，让 WP 后台能看到」。但本项目的部署形态是 **WP 是源站（可能在内网/本机，本站即 `192.168.2.2`）**，
线上 Worker **无法反向访问它** ⇒ 「Worker 主动 POST 回 WP」不可行。
正解是与 posts 批 J 同构：Worker 提供 `GET /sync/comments?since=<ts>&limit=`（X-Auth-Key），
**由 WP 侧（按钮 / cron）主动拉取**并 `wp_insert_comment()` 落地 —— 方向虽反，但「WP 是唯一权威」这一点不变。

**修正②：必须显式决定「新评论多久可见」。**
单页是 **静态快照**（R2），评论渲染进去 ⇒ 每条新评论都要「重建该篇单页」才可见。两种做法：
- （默认）静态：评论写 D1 → 标记该篇待重建 → 由批量任务重建（可加开关「评论新增自动重建该篇」，默认关）。
- （可选）半动态：**只把评论区**做成 Worker 响应时注入（单页其余部分仍读 R2）：新评论立即可见，代价是单页响应多一次 D1 查询 + 字符串注入（可 TTL 缓存）。
建议先把架构点定下来再动手，否则后面要返工。

### 4.3 提交路径与防滥用
- 公开提交端点（例如 `POST /wp-json/wp/v2/comments` 的 Worker 实现或独立 `/comments`）必然面对刷接口：
  复用现有 `wpv2RateAllowed()`（内存滑窗、isolate 级、0 KV）；建议再加 honeypot + 最小间隔 + 长度上限。
- **审核**：与 WP 设置对齐（`comment_moderation`）；D1 可存全部状态、渲染端只输出 `approved=1`；
  或只同步 approved（更简单但丢失可见性）。
- 结构化字段（`content`）必须走 `wp_kses`/转义（前台注入风险）；HTML 由主题骨架承载。

### 4.4 渲染（与 §5 契约一致）
- Worker 新增 `cfseCommentItems(comments, tpl, site)`：按 `parent_id` 建树、按 `date` 排序、**递归再生骨架**（`<ul class="children">` 用子骨架）；
- `{{#IF_COMMENTS}}` 改成 `comments.length > 0`；`{{COMMENT_COUNT}}` 等按数据填。
- 单页 `renderSinglePageOnDemand` / 重建路径共用同一函数。

### 4.5 实施步骤（建议顺序）
1. D1 表（三处 schema）+ 手工 SQL 自检；
2. WP → Worker 同步：评论钩子（`wp_insert_comment` / `edit_comment` / `wp_set_comment_status` / `deleted_comment`）+ 开关 `cf_comments_sync`（**默认关**）+ 全量补同步入口；
3. Worker：只读渲染（`cfseCommentItems`）+ `{{#IF_COMMENTS}}` 按数据；
4. 真机验证（造评论 → D1 有行 → 重建该篇 → 单页出现评论）；
5. 之后才做「公开提交 + 拉取」闭环（含限流/审核策略）。

## 5. 骨架提取（A/B 共用基础设施；**✅ 已实现** —— `tpl.json` 的 `comments` 段 + 单页里唯一的 `{{COMMENTS}}` 注入点）

### 5.1 twentyten 真实结构（已 dump，供实现参考）
```html
<div id="comments">
  <h3 id="comments-title">3 Responses to <em>CFSEPROBE-DUMP-TITLE</em></h3>
  <ol class="commentlist">                                  <!-- 注意：不是 ol.comment-list -->
    <li class="comment even thread-even depth-1" id="li-comment-73">
      <div id="comment-73">
        <div class="comment-author vcard">
          <img src='https://secure.gravatar.com/avatar/<hash>?s=40&#038;d=mm&#038;r=g'
               srcset='…?s=80… 2x' class='avatar avatar-40 photo' …>
          <cite class="fn">CFSEPROBE-CA</cite> <span class="says">says:</span>
        </div>
        <div class="comment-meta commentmetadata"><a href="…/#comment-73">September 19, 2026 at 4:59 pm</a></div>
        <div class="comment-body"><p>CFSEPROBE-COMMENT-A</p></div>
        <div class="reply"><a class="comment-reply-link" href="…?replytocom=73#respond" data-* …>Reply</a></div>
      </div>
      <ul class="children"><li class="comment odd alt depth-2" id="li-comment-75">…</li></ul>
    </li>
    <li class="comment even thread-odd thread-alt depth-1" id="li-comment-74">… 作者带链接（cite.fn > a.url.rel）…</li>
  </ol>
  <div id="respond" class="comment-respond">…表单（应删除）…</div>
</div>
```
两个必须按真实形态处理的事实：
1. 列表容器在 twentyten 是 **`ol.commentlist`** ⇒ 定位放宽为「`#comments` 内第一个含 `<li>` 的 `ol|ul`」；
2. **作者有/无链接是两种形态**，而 IF 是整块替换（不能只包半个标签）⇒ 骨架写成
   `{{#IF_COMMENT_AUTHOR_URL}}<a href="{{COMMENT_AUTHOR_URL}}" class="url" rel="ugc external nofollow">{{COMMENT_AUTHOR}}</a>{{/IF_COMMENT_AUTHOR_URL}}{{COMMENT_AUTHOR_PLAIN}}`
   （渲染端：有 URL 保留 IF 块、`PLAIN` 填空；无 URL 删 IF 块、`PLAIN` 填名字）。

### 5.2 占位符契约（B 的渲染端按此实现；A 只需要定位/清空）
| 占位符 | 含义 |
|---|---|
| `{{COMMENT_ID}}` | 评论 ID（`li-comment-N` / `comment-N` / `#comment-N` 锚点） |
| `{{COMMENT_AUTHOR}}` / `{{COMMENT_AUTHOR_URL}}` / `{{COMMENT_AUTHOR_PLAIN}}` | 作者名 / 站点 URL / 无 URL 时的纯文本名 |
| `{{COMMENT_AVATAR}}` / `{{COMMENT_AVATAR_2X}}` | 头像 URL（40px / 80px） |
| `{{COMMENT_DATE}}` | 日期文本（主题格式） |
| `{{COMMENT_DEPTH}}` / `{{COMMENT_ODD_EVEN}}` / `{{COMMENT_ALT}}` / `{{COMMENT_THREAD_CLASS}}` | 嵌套深度 / 交替类（保住交替底色） |
| `{{COMMENT_CONTENT}}` | 正文 HTML |
| `{{COMMENT_COUNT}}` | `#comments-title` 里的数字 |
| `{{COMMENT_CHILDREN}}` + `{{#IF_COMMENT_CHILDREN}}` | 子回复骨架（递归） |
| `{{COMMENTS}}` / `{{COMMENTS_EMBED}}` + `{{#IF_COMMENTS}}` | 列表整体 / 第三方注入片段 / 有评论才显示 |

处理规则：`#respond` 删 → `.reply` 块删（静态站回复无效）→ `data-*`/`replytocom` 删 → gravatar → 头像占位 →
`li-comment-N`/`comment-N` → `{{COMMENT_ID}}` → `depth-N`/交替类 → 占位 → `<ul class="children">` 剥离为子骨架 →
`#comments-title` 数字 → `{{COMMENT_COUNT}}`（标题里的文章名走既有 `{{POST_TITLE}}` 替换）→ 评论分页容器删。

## 6. 建议顺序

1. **A（约 1 天）**：立即可用、无数据责任、复用已有定位能力；
2. 若要「主题原生 UI + 自己的数据」→ **B**，按 §4.2 的两处修正执行（先定「拉取方向」与「评论区静态/半动态」）；
3. 骨架提取（§5）在 A 里用来「定位 + 清空」，在 B 里用来「渲染」，两条路都不浪费。

## 7. 风险与开放问题
- **A**：第三方隐私/GDPR、可用性依赖、观感变化；自托管型（Isso/Cusdis）需用户自己有服务。
- **B**：垃圾评论治理、刷接口（限流现只有 isolate 级内存滑窗）、审核可见性策略、评论数/标题与主题输出的一致性、历史评论迁移、评论深链（`#comment-N`）在静态页上的落点。
- **通用**：`comment_count` 是否进 `posts` 表（供列表页显示评论数）——目前未定。
- 探针相关：评论会顶高 `wp_comments` 的 AUTO_INCREMENT（无害，别当判据）；「最近评论 / 最近文章」widget 会把探针文本带进模板 ⇒ 需要 `sanitize` 按 `CFSEPROBE` 前缀清理（已列入待办）。
