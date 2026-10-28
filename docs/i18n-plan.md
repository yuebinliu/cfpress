# 多语言站点方案（分阶段，最终英文占根）

> **决策演进（2026-10-19）**
>
> 最初拍板「一步到位：英文占根 + 中文进 `/zh/`」。同日复核后改为**分阶段**：
> 先做模板层与内容层，路由与语言模块 UI 后置。原因是现有 15 篇内容仍是中文，
> 立刻翻转会让主语言站点读到空配置（见 §3.3 的主语言语义）。
>
> **阶段 A（当前）**：中文占根，英文进 `/en/` —— 对线上零影响。
> **阶段 B（翻转）**：英文占根，中文进 `/zh/` —— 只改 `lang.js` 两行常量 + 一组 301。

---

## 1. URL 形态

### 阶段 A（现在，零风险）

```
/                         中文首页（主语言，现有 15 篇）
/what-is-cfse/            中文文章
/category/start/          中文分类
/en/                      英文首页
/en/what-is-cfse/         英文文章
/en/category/start/       英文分类
```

### 阶段 B（内容齐备后翻转）

```
/                         英文首页（默认，x-default）
/zh/                      中文首页
/zh/what-is-cfse/         中文文章
```

翻转时 `DEFAULT_LANG` 与 `PREFIX` 各改一行（`lang.js` 文件头有说明），
再给旧的中文路径加 301：`/foo/ → /zh/foo/`。
**注意**：R2 里的成品 key 不会自动跟着走（`foo/index.html` 仍是中文成品），
所以阶段 B 需要把成品按新 key 复制一遍，或让路由按语言重定向到新 key。

导航右侧 `EN | 中文`：从当前页跳到**同一篇的对译版本**（靠 `translation_key` 配对），
不是简单跳首页。每页 `<head>` 加：

```html
<link rel="alternate" hreflang="en" href="https://yuebin.uk/en/what-is-cfse/">
<link rel="alternate" hreflang="zh-Hans" href="https://yuebin.uk/what-is-cfse/">
<link rel="alternate" hreflang="x-default" href="https://yuebin.uk/en/what-is-cfse/">
```

## 2. 核心设计：站点 URL **派生**，不存储前缀

模板里所有链接都写成 `{{SITE_URL}}/category/{{...}}/`、`{{POST_URL}}`、`{{SEARCH_ACTION}}`。
只要**渲染时把 `{{SITE_URL}}` 填成该语言的站点根**，全站链接、搜索框 action、RSS 地址
就自动带前缀，不需要逐个改模板。这是本次改造能控制在几百行以内的关键。

**但不建议把带前缀的 URL 存进 `settings`。** 正解是派生：

```js
// lang.js
siteUrlFor('https://yuebin.uk', 'en')  // → https://yuebin.uk/en
siteUrlFor('https://yuebin.uk', 'zh')  // → https://yuebin.uk
```

理由：`site.url` 是「站点身份」，被 canonical / sitemap / RSS / `og:url` 共用。
把语言掺进去等于让站点身份随语言变化，加第三种语言就要再存一份 url。
而它本来就是 `base + prefix` 的纯函数 ⇒ **存 base，用时派生**。

> 这是对上一版方案的修正：上一版写「让 `site.url` 带上语言前缀」，
> 效果一样，但把派生值持久化了，属于不必要的耦合。

## 3. 数据层

### 3.1 `posts` 加两列

```sql
ALTER TABLE posts ADD COLUMN lang TEXT NOT NULL DEFAULT 'zh';
ALTER TABLE posts ADD COLUMN translation_key TEXT NOT NULL DEFAULT '';
CREATE INDEX IF NOT EXISTS idx_posts_lang_status_date ON posts(lang, status, date DESC);
```

- `lang`：`'en'` | `'zh'`。
- `translation_key`：同一篇文章的两个语言版本共用一个值（默认取主语言版的 slug）。
- 英文版与中文版 `slug` **可以相同** —— 因为 URL 前缀不同，`/foo/` 与 `/en/foo/` 不冲突。
- **存量 15 篇全部回填 `lang='zh'`, `translation_key=slug`**。
- 新建文章时 `lang` 由后台选择，`translation_key` 为空则自动取自身 slug。

**必须先解掉的约束**：`posts.slug` 现在是 `TEXT UNIQUE NOT NULL`（`schema.sql:20`），
两行同 slug 会直接报唯一冲突。所以 `lang` 上线时必须同时改成按语言复合唯一：

```sql
-- SQLite 不能直接改列约束，需重建表（或先接受「两语言 slug 不同」的过渡方案）
CREATE UNIQUE INDEX IF NOT EXISTS idx_posts_slug_lang ON posts(slug, lang);
```

过渡方案（不改表）：让英文 slug 独立（如 `what-is-cfse` 与中文 `renshi-cfse` 不同），
`UNIQUE(slug)` 继续成立，代价是语言切换必须查 `translation_key` 才能找到对译。
**推荐改复合唯一** —— 这样切换开关只换前缀，零查询。

> **语言不是分类。** 它不参与 `taxonomies`，不进 `posts.categories`，
> 也不会生成 `/category/en/` 这样的路径。语言只做两件事：
> ① 决定 URL 前缀；② 决定查 `posts` 时 `WHERE lang=?`。详见 §5 路由层。

### 3.2 `taxonomies` 加 `lang`

分类/标签名要译（快速开始 → Quick Start）。`taxonomies` 加 `lang TEXT DEFAULT 'zh'`，
英文分类用同名 slug、不同 name，两者共存。

### 3.3 站点设置：主语言 + 补丁行（不新开 KV，也不拆两套完整配置）

存储形态（仍然只用 D1 `settings` 表，**不新增 KV**）：

| key | 用途 |
|---|---|
| `settings.k = 'site'` | **主语言**站点设置（阶段 A 是中文，阶段 B 翻转后是英文） |
| `settings.k = 'site:<lang>'` | 其它语言的**差异补丁**，只写需要变的字段 |
| `settings.k = 'cache'` / `'style'` | 与语言无关，全局一份 |

补丁行建议只存这几个字段：`name` / `description` / `menu` / `footerHtml`。
`url` **不存**（由 §2 的 `siteUrlFor(base, lang)` 派生），
`logo` / `social` / `comments` / `layout` / `activeStyleId` 也不存（与语言无关，继承主语言）。

**「语言模块」的最小形态**（阶段 B 才做 UI，但现在把数据结构定死）：

```jsonc
// settings.k = 'site' 里新增一个子对象，不新开表、不新开行
"i18n": {
  "default": "en",                    // 默认语言（决定谁占根，应与 lang.js 的 DEFAULT_LANG 一致）
  "enabled": ["en", "zh"],            // 启用的语言；长度 1 = 单语站，切换开关自动隐藏
  "labels":  { "en": "English", "zh": "中文" },   // 切换开关显示名
  "detect":  "path"                   // path | cookie | accept-language（阶段 B 先只支持 path）
}
```

后台「语言」页只需三件事：勾选启用语言、指定默认语言、填各语言的名称与描述。
新增一种语言 = `enabled` 加一项 + 建一行 `site:<lang>` 补丁 + 风格目录里放一个 `tpl.<lang>.json`。
**没有第四步** —— 这是这套设计「不伤筋动骨」的关键。

> 为什么不拆两套完整配置：站点 logo、社交、评论模式、风格指针这些与语言无关，
> 拆两套必然出现「改了英文忘了中文」的漂移。
>
> 为什么主语言必须是「无前缀」的那个：`settings.k='site'` 的语义由主语言决定。
> 内容与语言对不上（比如把 en 设为主语言、但 `site` 行里还是中文），
> 主语言站点就会读到错误配置 —— 这正是当前阶段**不能立刻翻转**的原因。

### 3.4 缓存键加语言

`site:ctx` → 主语言用 `site:ctx`，其它语言用 `site:ctx:<lang>`；
边缘缓存键 `home:page:1` → `home:<lang>:page:1`。
**漏掉这一处会出现中英互相串页**，是最容易踩的坑。

## 4. 模板层（已实现）

风格包里允许按语言分文件，**一套风格包自带多语言**：

```
wp-content/themes/cfse-demo/
  tpl.json          ← 基础模板（内容源语言 = 中文），任何语言缺失时的兜底
  tpl.en.json       ← 英文覆盖层（已产出，19.9 KB，6 类模板 + 侧栏 + 评论）
```

`tplstore` 查找顺序（`readStyleTpl`）：

1. `tpl.<lang>.json` —— 语言覆盖层，**可以只写部分类型**
2. `tpl.json` —— 基础版
3. 合并：覆盖层里有的类型用覆盖层，其余用基础版（`mergeTpl`）

设计要点：

- **`tpl.json` 永远是中文（源语言），不随主语言翻转而交换文件。**
  阶段 A：`lang='zh'` → 找 `tpl.zh.json`（无）→ 回退 `tpl.json`（中文）✅
  阶段 B：`lang='en'` → 找 `tpl.en.json`（有）✅；`lang='zh'` → 回退 `tpl.json`（中文）✅
  两种阶段都不需要动模板文件。
- `lang` 为空 ⇒ 只读 `tpl.json`，**行为和改造前完全一致**（已用 mock 环境验证）。
- 新增一种语言 = 往风格目录放一个 `tpl.<lang>.json`，不用复制整个风格包，
  也不用在 D1 里存第二个 `styleId`。

> 只译**当前生效的风格包**。其余 13 套内置风格不提供英文版，切换到它们时英文站会显示中文 UI
> —— 后台需要给个提示（「该风格无英文模板」）。

## 5. 路由层

`handleRequest` 入口（`routes.js:99`）加一次解析：

```js
const { lang, path } = parseLang(url.pathname);   // 阶段 A：'/en/foo/' → {lang:'en', path:'/foo/'}
```

- `/en` 或 `/en/` → lang=`en`，内部路径 `/`。
- `/en/xxx` → lang=`en`，内部路径 `/xxx`。
- 其余 → lang=`zh`（主语言），路径不变。
- **`/enfoo/` 不会被误判成英文**（按「段」判断，不是 `startsWith`）。

**R2 成品 key 用「原始路径」，不用剥离后的路径**：

| URL | R2 key |
|---|---|
| `/foo/` | `foo/index.html` |
| `/en/foo/` | `en/foo/index.html` |

### 为什么数据库里没有 `en`，R2 key 却有 `en/`？

因为 **R2 key 完全由请求路径推导，全程不查数据库**：

- `routes.js:99` `const path = url.pathname;` —— 原样，不剥前缀
- `routes.js:399` `getFromR2(env.R2_BUCKET, r2KeyFromPath(path))`
- `r2key.js:15` `r2KeyFromPath` 只做两件事：去前导斜杠 + `decodeURIComponent`

所以 `/en/foo/` 天然映射到 `en/foo/index.html`。
数据库里 `posts.slug = 'foo'`，语言存在 `posts.lang` 列，**跟 R2 key 没有任何关系**。

`en` **不是分类**，不是 `taxonomies` 的一行，不生成 `/category/en/`，
也不会出现在 `posts.categories` 里。它只是一个 URL 段，被路由层解读成语言。

四层各用各的机制，互不依赖：

| 层 | 用什么区分语言 |
|---|---|
| R2（成品 HTML） | 路径前缀（`en/foo/index.html`） |
| D1（文章内容） | `posts.lang` 列 |
| 模板 | `tpl.<lang>.json` |
| 站点设置 | `site:<lang>` 补丁行 |
| 对外 URL | `siteUrlFor(base, lang)` 派生 |

**代价**：`en`（以及翻转后的 `zh`）要成为保留 slug，`validateSlug` 需拒绝，
否则一篇叫 `en` 的文章会和语言前缀打架。

所有 `SELECT ... FROM posts` 都要加 `AND lang = ?`；`categories`/`tags` 同理。

## 6. 迁移步骤

### 阶段 A：英文上线但不占根（零风险，可先做）

1. `tpl.en.json` 已产出，随 assets 部署即可（不改任何运行时行为）。
2. 执行 schema 迁移：`posts.lang` / `translation_key` / `taxonomies.lang`（**先不改唯一约束**）。
3. 回填存量 15 篇：`lang='zh'`, `translation_key=slug`。
4. 导入英文文章（`lang='en'`，`translation_key` 对齐中文 slug）。
5. 路由层接 `parseLang`，按 `lang` 查库、按 `lang` 选模板、按 `lang` 派生 `SITE_URL`。
6. 验证：`/`、`/en/`、`/foo/`、`/en/foo/` 四条路径；中文站行为必须与改造前逐字节一致。

### 阶段 B：翻转（内容齐备后）

7. `lang.js` 改两行：`DEFAULT_LANG='en'`、`PREFIX={en:'',zh:'/zh'}`。
8. `settings` 里把主语言语义迁移：新建 `site` 行存英文配置，原中文配置转存 `site:zh`。
9. R2 成品按新 key 复制（`foo/index.html` → `zh/foo/index.html`），英文成品落到根。
10. 加 301：`/foo/ → /zh/foo/`（仅对没有英文对译的旧路径）。
11. 清理根下遗留的中文成品。

## 7. 风险清单

| 风险 | 说明 | 处理 |
|---|---|---|
| 中英串页 | 缓存键没带 lang | 全量审查 `edgeGet/edgeSet` 的 key |
| 链接不带前缀 | 某处硬编码 `https://yuebin.uk` | 统一走派生的 `SITE_URL`；正文内绝对链接需改写 |
| 主语言语义错位 | 翻转了 `DEFAULT_LANG` 但 `site` 行内容没换 | 阶段 B 的第 8 步必须与第 7 步同时完成 |
| slug 唯一冲突 | 两语言同 slug 撞 `UNIQUE(slug)` | 改复合唯一 `UNIQUE(slug, lang)`，或过渡期让英文 slug 独立 |
| 旧中文成品残留 | 翻转后根下 `foo/index.html` 仍是中文 | 阶段 B 第 11 步按 D1 的 en 列表核对 |
| 风格切换丢英文 | 其他风格包无英文模板 | 后台提示，或只允许英文站用 cfse-demo |
| slug 撞前缀 | 某篇文章恰好叫 `en` / `zh` | `validateSlug` 拒绝保留字 |

## 8. 进度

- [x] `docs/i18n-plan.md`（本文）
- [x] `lang.js`（`parseLang` / `withLang` / `switchLang` / `htmlLang` / `siteUrlFor`），8 条用例全过
- [x] `getSiteContext(env, lang)` + `site:<lang>` 补丁行合并
- [x] `tplstore` 语言层（`tpl.<lang>.json` 覆盖 + 回退 + 合并），mock 环境验证通过
- [x] `tpl.en.json`（19.9 KB，6 类模板 + 侧栏 + 评论，占位符与中文版逐一对齐）
- [ ] schema 迁移 SQL + 回填（SQL 已写，未执行）
- [ ] 15 篇英文正文
- [ ] `routes.js` 路由与查询改造
- [ ] `admin.js` 语言字段与筛选
- [ ] 部署 + 四路径验证
