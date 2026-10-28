# 风格模板契约（手册）

> ⚙️ **本文件由 `tools/contract-doc.mjs` 从 `docs/skin-contract.json` 生成 —— 不要手改。**
> 改契约请改 json；工程/审计内容（行号、覆盖率、待办）见 `docs/skin-audit.json`。
> 一致性由 `node tools/contract-check.mjs` 断言（手册 ≡ 渲染器实际替换集）。

风格模板契约（手册）—— 写风格时看这一份。真相来源 = worker/src/{templates,admin,comments}.js；本文件是它的规范化投影，由 tools/contract-check.mjs 断言一致。

共 **72** 项：58 个占位符 + 14 个条件块，分 7 组。

## 规则

### 渲染模型（先读这条）

渲染 = **纯字符串替换**（`split/join`），不是模板引擎。

- 没有循环、没有表达式、没有 `else`、没有过滤器、没有条件运算；
- 占位符**写几次都替换**，**没写的毫无成本**（不需要声明、不需要成对）；
- 同一个占位符出现多次 ⇒ 全部替换。唯一例外是 `{{COMMENT_CHILDREN}}`：它走递归，只替换本层那一处（全局替换会无限自复制）。

### 未知 token 会被静默清空

模板里出现清单外的 {{XXX}} ⇒ 出口兜底 cfseStripLeftovers() 把它清空，并在 console 记一行 [cfse] unreplaced placeholders（生产不可见）。这是本系统唯一一类"静默失败"。

### 未实现 ≠ 非法

本清单只描述**已实现**的集合（清单 ≡ 渲染器实际替换的 token）。**未实现 ≠ 非法**：清单不收录未实现的标识符，也不禁用它们 —— 将来要引入新名（例如把 {{POST_TAGS}} 缩短），走「先补渲染器 → 补本清单 → 抓取端归一化 → 重抓」的顺序即可，本文件不设禁令。

### 做不到的事（能力上限）

| 想要 | 能吗 | 说明 |
|---|:-:|---|
| 写循环（多条 / 多列 / 页码列表） | ❌ | 没有循环语法；"多条"只有 `{{CONTENT}}`，以及侧边栏 / 评论三处骨架由渲染器循环填充 |
| 分页页码 `1 2 3 …` | ❌ | 只有 `{{PAGINATION_PREV}}` / `{{PAGINATION_NEXT}}`，且**链接 HTML 由渲染器生成**，文案与类名不可自定义 |
| `{{#IF_X}}…{{#ELSE}}` | ❌ | 无 else，也没有取反 flag |
| 条件里做比较（`{{#IF_COUNT>3}}`） | ❌ | 条件只有条件块表里那些 flag |
| 同名 IF 嵌套 | ❌ | 渲染器只找第一个闭合标记 |
| 引用任意字段（`{{POST_MODIFIED}}`） | ❌ | 只认本手册列出的；未列入的会被静默清空 |

以上都是**能力上限，不是缺陷** —— 需要这些效果时改用 CSS/JS，或改渲染器（见审计文件的 `openIssues`）。

### 条件块规则

{{#IF_X}} … {{/IF_X}} 必须成对；X 只认 conditionals 列出的 flag；同名 IF 不可嵌套（渲染器只找第一个闭合标记）；flag 拼错或未实现 ⇒ 两个标记被当未知 token 清掉，中间内容"永远显示"（不报错）。

### 转义：你不用管，但要知道值是什么

转义由渲染器按**值所在的上下文**统一处理，模板作者不需要自己转义：

- **文本位置** → 值已 HTML 转义（`escape` 列为 `text`）；
- **URL / 属性位置** → 值已做属性转义（`escape` 列为 `attr`）；
- **HTML 内容** → 原样输出（`escape` 列为 `html`），**不要把这类占位符塞进属性里**；
- `num` / `const` 是数字与固定类名，无需转义。

例外：`escape` 为 `mixed` 的项表示各渲染路径尚未统一（当前为空 —— 2026-09-22 已统一）。

### 骨架段（sidebar / comments）

sidebar = 单个 <li> 骨架，渲染器按数据循环填充（骨架里不能写循环标记）；comments = {list, entry, children, form} 四段，entry/children 由渲染器递归再生。两者都属"有值才写入 tpl.json"，因此不同风格包的段可能不同。

### 半动态评论区（不重建单页即可见新评论）

想让新评论"不重建单页即可见"，single 模板必须把评论区包进 <!--CFSE-COMMENTS-START id={{CFSE_POST_ID}}--> … <!--CFSE-COMMENTS-END-->。无标记 ⇒ 评论区只是静态快照，须重建单页。

### 预览能验证什么 / 不能验证什么

`GET /preview/<slug>/`（6 个页签，只读，直接读 `tpl.json`，不动线上）。

| 能验证 | 不能验证（预览里必然为空 / 看不到） |
|---|---|
| 站点级占位符、菜单、logo、社交 | `{{PREV_*}}` / `{{NEXT_*}}`（预览不传上下篇 ⇒ `{{#IF_PREV}}` 永远为假） |
| 列表条目、摘要、日期、分类标签 | `{{COMMENTS}}`（预览不传评论） |
| 侧边栏三块（**用真实数据**） | 多页分页（预览 `totalPages=1` ⇒ `{{PAGINATION_NEXT}}` 为空） |
| 单页正文 / 元信息 / 标题 | "无分类 / 无标签 / 无作者 / 无图"的空值路径（样例数据全都有值） |
| `{{TITLE}}`（分类 / 标签 / 归档样例） | 搜索页（`{{TITLE}}` 会变成站点名） |

⇒ **空值路径要自己构造**（例如临时用一篇无标签的文章看条目是否留残句）。

### 改动 → 生效路径

模板存入 tpl:active 后：列表页每请求渲染 ⇒ 即改即生效；single 是写进 R2 的**静态快照** ⇒ 改模板后必须重建单页。预览 /preview/<slug>/ 直接读 tpl.json，且不传上下篇与评论 ⇒ {{#IF_PREV}} 与 {{COMMENTS}} 在预览里必然为空。

### 生成侧的两条路径

生成侧有两条路径：经典链路（skin-builder.php，能产 sidebar + comments 段）与 FSE 提取器（tools/fse-skin-extract.php，只产 sidebar 段）。段的缺失由"哪条路径产的"决定，不是配置开关。

### 本手册自身的可信度（哪些有机械保障）

- **占位符 / 条件块清单（tokens）**：有机械保障 —— `node tools/contract-check.mjs` 断言「手册 ≡ 渲染器实际替换的 token」，不一致即 exit 1。
- **规则各节（含散文）**：**没有**机械保障 —— 它们描述能力边界与行为，没法用集合比对判定。改渲染器行为后要**手工**复核对应段落。
- 唯一的辅助检查：断言会以 **warning** 提示"散文里引用了手册中不存在的 token"。写教学反例时用 `~~{{XXX}}~~`，或在 `tools/contract-check.mjs` 的 `EXAMPLES` 里登记。

### 旧版文档在哪（历史，不参与校验）

契约重建前的手写版本（271 行，含当时的分工说明与历史记录）归档在 `docs/skin-template-contract.legacy.md`，**不参与** `tools/contract-check.mjs` 校验，内容可能已过期。查历史取舍可以看它，写风格一律以本手册为准。

### 评论骨架用 WP 标准结构（否则样式/生态不兼容）

写 `comments` 段（`list` / `entry` / `children` / `form`）时，照 **WP 主题的标准结构**来（可直接参考内置包 `twentyten` 的 `tpl.json`）：

- `list`：`<ol class="commentlist">{{COMMENTS}}</ol>` + 标题（可用 `{{COMMENT_COUNT}}`）；
- `entry`：`<li id="li-comment-{{COMMENT_ID}}" class="comment {{COMMENT_ODD_EVEN}} {{COMMENT_ALT}} {{COMMENT_THREAD_CLASS}} depth-{{COMMENT_DEPTH}}">`，内层 `div#comment-{{COMMENT_ID}}`，含 `.comment-author.vcard`（头像 + `<cite class="fn">`）、`.comment-meta.commentmetadata`（链接到 `{{POST_URL}}#comment-{{COMMENT_ID}}`）、`.comment-body`、`.reply > a.comment-reply-link[data-cfse-parent="{{COMMENT_ID}}"]`；
- **`children` 段是单个 `<li>`** —— 别再套 `<ul class="children">`（entry 里已有一层，套了会叠成两层 `<ul>`）；
- `form`：`div#respond.comment-respond` + `form#commentform.comment-form`，`action="/comments"`；字段名必须是 `comment` / `author` / `email` / `url`（注入脚本按这些名字取值），并放 `<input type="hidden" name="comment_post_ID" value="{{COMMENT_POST_ID}}">`。

`{{#IF_COMMENT_CHILDREN}}` 的摆法：entry 里写 `<ul class="children">{{#IF_COMMENT_CHILDREN}}{{COMMENT_CHILDREN}}{{/IF_COMMENT_CHILDREN}}</ul>`（与 WP 一致，无子评论时留空 `<ul>`）；children 段里直接嵌 `{{COMMENT_CHILDREN}}`。

头像用 `{{COMMENT_AVATAR}}`（+ `{{COMMENT_AVATAR_2X}}` 作 2x），日期用 `{{COMMENT_DATE}}`（配 `{{COMMENT_DATETIME}}`）。

## 命名规则

- **rule_placeholder**：{{域_名}}，域 ∈ SITE / POST / SIDEBAR / COMMENT / ITEM / PAGINATION；页面级另有裸名：CONTENT / TITLE / YEAR / MONTH / PAGE_NUMBER / TOTAL_POSTS
- **rule_conditional**：{{#IF_状态}} … {{/IF_状态}}，状态 ∈ LOGO / SOCIAL / MENU / CATEGORIES / TAGS / AUTHOR / THUMBNAIL / COMMENTS / PREV / NEXT / hasPage / COMMENT_AUTHOR_URL / COMMENT_CHILDREN
- **decided_2026_09_22**：{{POST_TAGS}} / {{POST_CATEGORIES}} 是当前**已实现**的名字（2026-09-22 决定：保持它们，不动）。短名 {{TAGS}} / {{CATEGORIES}} 目前**未实现** —— 写入模板会被出口兜底静默清空，但**不视为非法**，未来可引入（引入顺序见 rules.not_implemented_policy）。
- **why**：域前缀与 {{SIDEBAR_TAGS}} / {{COMMENT_*}} / {{ITEM_*}} 天然消歧；改短名需渲染器+抓取端+全部内置包重抓，且重抓前旧包标签位会空白（会重演 legacy-prevnext 那类故障）。
- **not_implemented_policy**：本清单只描述**已实现**的集合（清单 ≡ 渲染器实际替换的 token）。**未实现 ≠ 非法**：清单不收录未实现的标识符，也不禁用它们 —— 将来要引入新名（例如把 {{POST_TAGS}} 缩短），走「先补渲染器 → 补本清单 → 抓取端归一化 → 重抓」的顺序即可，本文件不设禁令。

## token 与 label（别混）

- **token**：可插入模板、渲染器必须支持的标识符。唯一真相来源 = 本文件 tokens；消费端（后台插入按钮 / 校验器 / 抓取端归一化目标）都读它。
- **label**：面板上展示给人看的说明文案。随便写、不参与校验 —— 可以写成 {{#IF_TAGS}}…{{/IF_TAGS}} 这种示意形态。
- **gotcha**：后台的清单数组把两者混在一个数组里（键当 label、值当说明），导致按「文件里所有 {{…}} 字面量」扫描的方案会把 label 误当 token。清单结构应拆成 {token, label}。

## 字段说明

- **group**：所属分组（site / list-pages / post / single-only / sidebar-skeleton / comment-skeleton / conditionals）
- **kind**：placeholder = 会被替换的占位符；conditional = {{#IF_X}} 条件块
- **origin**：wp = WP 核心输出单元；system = 本系统自有（附 wp_call / wp_equiv 近似参考）
- **scope**：value 标量 | fragment HTML 片段 | block 完整块（含外层） | raw-html 原样 HTML 内容
- **escape**：text 已文本转义 | attr 已属性转义 | html 原样输出 | num 数字 | const 固定类名
- **source**：数据来源：site:ctx（后台可配） / posts / comments / 列表查询上下文 / 渲染时计算
- **pages**：准许使用的模板；写在别的模板里会被静默清空
- **emitted_form**：实际回吐形态（从渲染器实现读出）

## 占位符与条件块

### 站点级（值来自 site:ctx，后台可配）

| 占位符 | 值形态 | 转义 | 可用模板 | 输出 / 说明 |
|---|---|---|---|---|
| `{{MENU}}` | block | html | pageArchive, pageCategory, pageHome, pageTag, single | <ul class="menu"><li class="menu-item[ menu-item-has-children]"><a href="{url}">{title}</a>[<ul class="sub-men… |
| `{{SEARCH_ACTION}}` | value | attr | pageArchive, pageCategory, pageHome, pageTag, single | https://example.com/（属性值；<form>/<input name="s"> 自己写） ｜ 只给 action 值；<form>/<input name="s"> 由模板写 |
| `{{SITE_BANNER}}` | fragment | html | pageHome, pageCategory, pageTag, pageArchive, single | <img src="{banner}" alt="{SITE_NAME}" class="site-banner-img" />（空 ⇒ 空串） ｜ 横条图；与 {{#IF_BANNER}} 搭配，空则不显示 |
| `{{SITE_DESC}}` | value | text | item, pageArchive, pageCategory, pageHome, pageTag, single | 纯文本（同上来路差异） ｜ 同上（single 转义） |
| `{{SITE_FOOTER}}` | fragment | html | pageArchive, pageCategory, pageHome, pageTag, single | <div style="text-align:center;color:#666;…"><a href="{SITE_URL}/">{SITE_NAME}</a> · CF Static Export Pro</div>… |
| `{{SITE_LOGO}}` | block | html | pageArchive, pageCategory, pageHome, pageTag, single | <div class="wp-block-site-logo"><a href="{SITE_URL}/" class="custom-logo-link" rel="home"><img src="{logo}" cl… |
| `{{SITE_NAME}}` | value | text | item, pageArchive, pageCategory, pageHome, pageTag, single | 纯文本（页壳/item 不转义；single 转义） ｜ ⚠️ single 里 escHtml，页面壳/item 里不转义 |
| `{{SITE_URL}}` | value | attr | item, pageArchive, pageCategory, pageHome, pageTag, single | https://example.com（无尾斜杠） ｜ 无尾斜杠；可出现在文本与属性两种位置 |
| `{{SOCIAL_LINKS}}` | fragment | html | pageArchive, pageCategory, pageHome, pageTag, single | <li class="wp-block-social-link"><a href="{url}" class="wp-block-social-link-anchor" rel="me noopener">{Servic… |

### 页面壳（首页 / 分类 / 标签 / 归档 / 搜索）

| 占位符 | 值形态 | 转义 | 可用模板 | 输出 / 说明 |
|---|---|---|---|---|
| `{{CONTENT}}` | fragment | html | pageArchive, pageCategory, pageHome, pageTag | item 模板渲染 N 次后的拼接（以换行分隔） ｜ item 模板渲染 N 次后拼接；不写它 ⇒ 页面无文章 |
| `{{MONTH}}` | value | num | pageArchive, pageCategory, pageHome, pageTag | MM（仅归档页有值） ｜ 同上 |
| `{{PAGE_NUMBER}}` | value | num | pageArchive, pageCategory, pageHome, pageTag | 数字文本（当前页码） |
| `{{PAGINATION_NEXT}}` | block | html | pageArchive, pageCategory, pageHome, pageTag | <a class="wp-block-query-pagination-next" href="…page/{n+1}/">Newer posts →</a>；末页 ⇒ 空串 ｜ 同上；无页码列表能力 |
| `{{PAGINATION_PREV}}` | block | html | pageArchive, pageCategory, pageHome, pageTag | <a class="wp-block-query-pagination-previous" href="…page/{n-1}/">← Older posts</a>；第 1 页 ⇒ 空串 ｜ 整段 <a>；HTML 由… |
| `{{SIDEBAR_ARCHIVES}}` | fragment | html | pageArchive, pageCategory, pageHome, pageTag, single | 有骨架 ⇒ 按骨架再生；无骨架 ⇒ <li><a href="{SITE_URL}/{YYYY}/{MM}/">{March YYYY}</a></li> ×N ｜ 骨架缺失 ⇒ 内置 <li>（March 2006 →… |
| `{{SIDEBAR_CATEGORIES}}` | fragment | html | pageArchive, pageCategory, pageHome, pageTag, single | 有骨架 ⇒ 按骨架再生；无骨架 ⇒ <li class="cat-item"><a href="{SITE_URL}/category/{slug}/">{name}</a></li> ×N ｜ 有 sidebar 骨架… |
| `{{SIDEBAR_TAGS}}` | fragment | html | pageArchive, pageCategory, pageHome, pageTag, single | 有骨架 ⇒ 按骨架再生；无骨架 ⇒ <li><a href="{SITE_URL}/tag/{slug}/" class="tag-cloud-link tag-link-position-{N}" style="fon… |
| `{{TITLE}}` | value | text | pageArchive, pageCategory, pageHome, pageTag | 纯文本（分类名/标签名/归档名；首页与搜索页 = 站点名） ｜ 首页与搜索页落站点名（无"搜索词"占位符） |
| `{{TOTAL_POSTS}}` | value | num | pageArchive, pageCategory, pageHome, pageTag | 数字文本（该一级列表的文章总数） ｜ 该页所在一级列表的总数 |
| `{{YEAR}}` | value | num | pageArchive, pageCategory, pageHome, pageTag | YYYY（仅归档页有值；其它页面写了 ⇒ 被清空） ｜ 仅 pageArchive 传值；其它页面写了会被清空 |

### 文章（列表条目 item 与单页都用）

| 占位符 | 值形态 | 转义 | 可用模板 | 输出 / 说明 |
|---|---|---|---|---|
| `{{COMMENT_COUNT_TEXT}}` | value | text | item | No Comments / 1 Comment / N Comments ｜ 抓取端新骨架用它；旧包靠渲染端正则兜底改写 |
| `{{COMMENT_COUNT}}` | value | num | comments, item | 数字文本 ｜ 列表条目里 = 该篇评论数；评论区 list 里 = 参与渲染的条数 |
| `{{POST_AUTHOR_URL}}` | value | attr | item, single | {SITE_URL}/author/{slug}/；无作者 slug ⇒ 空串 ｜ 空作者 ⇒ 空串（配合 {{#IF_AUTHOR}}） |
| `{{POST_AUTHOR}}` | value | text | item, single | 纯文本（per-post 作者，否则 site:ctx.defaultAuthor.name） ｜ 无 per-post 作者时回退 site:ctx.defaultAuthor |
| `{{POST_CATEGORIES}}` | fragment | html | item, single | <a href="{SITE_URL}/category/{slug}/">{name}</a>，多个以 ", " 分隔；无 ⇒ 空串 ｜ 保留名（2026-09-22 决定不改短名）；{{TAGS}} / {{CATE… |
| `{{POST_DATE}}` | value | text | item, single | YYYY-MM-DD ｜ 截取 YYYY-MM-DD |
| `{{POST_EXCERPT}}` | value | text | item, single | 纯文本（已转义；md 文章由 WP 侧 md_to_plain 生成） ｜ md 文章的摘要由 WP 侧 md_to_plain 生成 |
| `{{POST_TAGS}}` | fragment | html | item, single | <a href="{SITE_URL}/tag/{slug}/">{name}</a>，多个以 ", " 分隔；无 ⇒ 空串 ｜ 保留名（2026-09-22 决定不改短名）；{{TAGS}} / {{CATEGORIE… |
| `{{POST_THUMBNAIL}}` | value | attr | item, single | 图片 URL（特色图；无 ⇒ 正文首图；再无 ⇒ 空串） ｜ 不转义 |
| `{{POST_TITLE}}` | value | text | comments, item, single | 纯文本（已转义） ｜ 也在评论区的标题/锚链接里出现 |
| `{{POST_URL}}` | value | attr | comments, item, single | https://example.com/{storagePattern}/（无尾斜杠规则同 SITE_URL） ｜ 按 site.storagePattern 拼 |

### 仅单页 single

| 占位符 | 值形态 | 转义 | 可用模板 | 输出 / 说明 |
|---|---|---|---|---|
| `{{CFSE_POST_ID}}` | value | num | single | 数字（posts.wp_id） ｜ 半动态评论标记 <!--CFSE-COMMENTS-START id={{CFSE_POST_ID}}--> 用 |
| `{{COMMENTS}}` | fragment | html | comments, single | mode=embed ⇒ 管理员填的第三方片段；native ⇒ 骨架再生的评论树；off / 文章关闭评论 ⇒ 空串 ｜ mode=embed→第三方片段；native→骨架再生；off/关闭→空 |
| `{{NEXT_TITLE}}` | value | text | single | 更新一篇的标题 |
| `{{NEXT_URL}}` | value | attr | single | 更新一篇的永久链接 |
| `{{POST_CONTENT}}` | raw-html | html | single | 正文 HTML（md 文章先经 Worker 侧 marked 渲染） ｜ md 文章由 Worker 侧 marked 渲染后再填入 |
| `{{PREV_TITLE}}` | value | text | single | 更早一篇的标题（已转义） ｜ 模板里没出现则保留主题原文案 |
| `{{PREV_URL}}` | value | attr | single | 更早一篇的永久链接；无 ⇒ 空串（配 {{#IF_PREV}}） |

### 侧边栏骨架段（sidebar）内部

| 占位符 | 值形态 | 转义 | 可用模板 | 输出 / 说明 |
|---|---|---|---|---|
| `{{ITEM_COUNT}}` | value | num | sidebar.archives, sidebar.categories, sidebar.tags | 条目计数 |
| `{{ITEM_NAME}}` | value | text | sidebar.archives, sidebar.categories, sidebar.tags | 条目显示名 |
| `{{ITEM_POSITION}}` | value | num | sidebar.archives, sidebar.categories, sidebar.tags | 序号（从 1 起，tag-link-position-N 用） |
| `{{ITEM_SIZE}}` | value | num | sidebar.archives, sidebar.categories, sidebar.tags | 字号数字（仅 tags 有值；categories/archives ⇒ 空串） |
| `{{ITEM_URL}}` | value | attr | sidebar.archives, sidebar.categories, sidebar.tags | 分类/归档/标签条目的 href（属性值） ｜ 仅出现在 sidebar 骨架里，不在 6 类模板里 |

### 评论骨架段（comments）内部

| 占位符 | 值形态 | 转义 | 可用模板 | 输出 / 说明 |
|---|---|---|---|---|
| `{{COMMENT_ALT}}` | value | const | comments | alt / 空串 |
| `{{COMMENT_AUTHOR_PLAIN}}` | value | text | comments | 纯作者名（无链接分支用） |
| `{{COMMENT_AUTHOR_URL}}` | value | attr | comments | 作者站点；空 ⇒ {{#IF_COMMENT_AUTHOR_URL}} 整块换成 COMMENT_AUTHOR_PLAIN ｜ 空 ⇒ {{#IF_COMMENT_AUTHOR_URL}} 整块换用 COMMENT_AU… |
| `{{COMMENT_AUTHOR}}` | value | text | comments | 作者名（有链接时用作 <a> 文本） |
| `{{COMMENT_AVATAR_2X}}` | value | attr | comments | 同上，s= 换 80 ｜ gravatar 的 s= 参数换 80 |
| `{{COMMENT_AVATAR}}` | value | attr | comments | https://secure.gravatar.com/avatar/{hash}?s=…（WP 侧算好写入 D1） ｜ 值由 WP 侧算好同步进 D1（avatar 列，含 md5） |
| `{{COMMENT_CHILDREN}}` | fragment | html | comments | 子评论子树（递归，同 entry 骨架） ｜ ⚠️ 同名占位符在子树里必须走递归，不可全局替换 |
| `{{COMMENT_CONTENT}}` | raw-html | html | comments | 评论正文 HTML（原样） |
| `{{COMMENT_DATE}}` | value | text | comments | WP 按主题 date_format 算好的文本（D1 date_text 列） ｜ 值由 WP 侧按主题 date_format 算好（date_text 列） |
| `{{COMMENT_DATETIME}}` | value | attr | comments | ISO 时间串（属性值；现有内置包未使用） ｜ 现有内置包未使用 |
| `{{COMMENT_DEPTH}}` | value | num | comments | 数字（1 = 顶层） |
| `{{COMMENT_ID}}` | value | num | comments | 数字 |
| `{{COMMENT_ODD_EVEN}}` | value | const | comments | odd / even |
| `{{COMMENT_POST_ID}}` | value | num | comments.form | 数字（评论表单的 post id） |
| `{{COMMENT_THREAD_CLASS}}` | value | const | comments | thread-even / thread-odd |

### 条件块（{{#IF_X}} … {{/IF_X}}）

| 条件块 | 条件 | 可用模板 | 说明 |
|---|---|---|---|
| `AUTHOR` | 有作者名 | item, single | per-post 或站点默认作者 |
| `BANNER` | 横幅图非空 | pageHome, pageCategory, pageTag, pageArchive, single | — |
| `CATEGORIES` | 文章有分类 | item, single | — |
| `COMMENT_AUTHOR_URL` | 评论作者有站点链接 | comments | — |
| `COMMENT_CHILDREN` | 该评论有子评论 | comments | — |
| `COMMENTS` | 评论区内容非空 | single | embed 片段非空 / native 有内容 |
| `hasPage` | 当前页 > 1 | pageArchive, pageCategory, pageHome, pageTag | ⚠️ 全系统唯一小写 flag |
| `LOGO` | 站点 logo 非空 | pageArchive, pageCategory, pageHome, pageTag, single | — |
| `MENU` | 菜单非空 | pageArchive, pageCategory, pageHome, pageTag, single | 数据源是 CMS 菜单 |
| `NEXT` | 有下一篇（更新） | single | — |
| `PREV` | 有上一篇（更早） | single | — |
| `SOCIAL` | 社交链接非空 | pageArchive, pageCategory, pageHome, pageTag, single | — |
| `TAGS` | 文章有标签 | item, single | — |
| `THUMBNAIL` | 有缩略图 | item, single | — |
