# 风格体系简化（Style Refactor）—— 废除 tpl:* KV / 废弃 styles 表 / 按路径直读

> 状态：**设计稿 v1（定稿方向，实施中）**（2026-09）
> 范围：**只改 CMS（Worker）侧**；WP 侧插件暂不动。
> 目标：KV 几乎清零、D1 少一张表、风格「一个目录」、逻辑统一（内置与用户同形）。

---

## 0. 为什么要改（现状问题）

现状风格数据散在 **5 个 KV 键 + 1 张 D1 表**，且内置与用户两条线逻辑不同：

| 现状 | 问题 |
|---|---|
| `tpl:active`（KV）| 存「当前风格 6 类模板**全文**」（几十 KB）—— 只是「用哪个风格」的**指针 + 内容副本**，而内容本来就在 static/R2 |
| `tpl:index`（KV）| 内置风格清单（部署脚本注入） |
| `tpl:sindex`（KV）| 用户风格清单 |
| `tpl:store:<type>:<id>`（KV）| 自定义单类模板正文 |
| `tpl:style:<id>`（KV）| 迁移前遗留 |
| D1 `styles` 表 | 用户风格正文（含 version 多版本 + revert API）|

- 内置风格走 static（`wp-content/themes/<slug>/tpl.json` + 资源），用户风格走 D1 `tpl_json` + R2 `style/`（资源）—— **两条线**；
- 大风格包塞 D1 有大小风险；
- 多版本/回退是一整套 API，用户实际用不上（自己在三方编辑器改 + 复制粘贴即可）。

**关键事实（已核实）**：用户风格**资源**早已写在 `wp-content/themes/<slug>/`（R2），与内置**同路径**、请求链 **assets 命中 → R2 兜底**。所以本次是「把已经同形的两半接起来」。

---

## 1. 数据布局（定稿）

### 1.1 D1 `settings`（权威）

```jsonc
k='site'   → { name, url, description, storagePattern, logo, banner, social, menu, comments, docRoutes, ... }
k='cache'  → { ttl_list, ttl_sidebar, ttl_site, ttl_feed, feed_count, purge_on_publish, task_batch, task_concurrency }
k='style'  → {                                   // 新增（权威）
  active: "neve-fse",                            // 当前生效风格 id（指针；'' = 内置默认模板）
  user: [ { id:"my-skin", name:"我的风格" }, ... ] // 用户风格索引（上传/删除时由 API 写）
}
```

### 1.2 `site:ctx`（缓存视图，每请求读 1 次）

```
site:ctx.styleIndex     = [ 内置… + 用户… ]        // 合并后的清单（下拉直接用，零额外 IO）
site:ctx.activeStyleId  = "neve-fse"
```
- 内置部分：`getSiteContext` 重建时读 **assets `/wp-content/themes/index.json`**（部署脚本生成，免费）；
- 用户部分：读 D1 `k='style'.user`；
- 合并去重（内置优先，用户同 id 覆盖显示名）。

### 1.3 风格内容（按路径直读）

```
内置：assets /wp-content/themes/<id>/tpl.json   （部署打包，免费直出）
用户：R2     /wp-content/themes/<id>/tpl.json   （上传即写；assets 未命中时直出）
资源：同目录 /wp-content/themes/<id>/<相对路径>
```
**完全同形** —— 渲染 / 预览 / 上传 / 删除**一套逻辑**。

---

## 2. 读取链（渲染）

```
routes / admin 渲染
  └─ getActiveTplContent(env, 'pageHome')  // 签名不变
       └─ tplstore.getActiveTpl(env)
            ├─ site:ctx.activeStyleId（已在 site:ctx，几乎免费）
            ├─ 若为空 → 回退内置默认模板（沿用现有行为）
            └─ 读 wp-content/themes/<id>/tpl.json（assets → R2）
                 · isolate 级短缓存（TTL ~15s，键=id）→ 一次请求内多次 getter 只读 1 次
            → 返回 { single, item, pageHome, pageCategory, pageTag, pageArchive, sidebar, comments }
```

> **tplstore 的 4 个 getter 签名保持不变**（`getActiveTpl` / `getActiveTplContent` / `getActiveSidebarTpl` / `getActiveCommentsTpl`）⇒ **routes.js / admin.js / comments.js / preview.js 的调用点零改动**。

---

## 3. 操作语义（后台）

| 操作 | 实现 |
|---|---|
| **列清单** | `GET /api/style-list` → `site:ctx.styleIndex`（内置 + 用户，带 source 标记）|
| **预览** | 读该 id 的 `tpl.json` 渲染（不落盘、不改 active）|
| **应用** | 写 `D1 k='style'.active = id` + 清 `site:ctx`（下次读生效）|
| **上传（一个目录）** | `webkitdirectory` 选目录 → 遍历 → 逐文件写 `R2 wp-content/themes/<slug>/<相对路径>`；**先把旧 `tpl.json` 复制为 `tpl.json.bak`**（唯一回退底牌）→ 更新 `D1 k='style'.user`（加/更新该 id）→ 清 `site:ctx` |
| **编辑单个文件** | 同上（写单文件 + `.bak` 仅对 tpl.json）|
| **回退** | 用 `tpl.json.bak` 覆盖回 `tpl.json`（**只有一个原始包，无多版本**）|
| **删除** | 删 R2 `wp-content/themes/<id>/` 整个前缀 + 从 `D1 k='style'.user` 移除 + 若 active==id 则清空 active → 清 `site:ctx` |
| **导出** | 打包该目录（zip 或逐文件 JSON），供用户在三方编辑器改 |

**明确不做**：多版本、revert 历史、D1 styles 表（DROP）。

---

## 4. 缓存头（关键，别踩雷）

- **`tpl.json` 绝不能被 CDN/浏览器长缓存** —— 否则改了风格不生效。
  - assets 里的 `.json`：确认/压短 CF 缓存（必要时在部署配置里显式设短 TTL 或 `no-store` 语义）；
  - R2 直出那条由 Worker 控制（设短 `Cache-Control`）。
- 风格**资源**（css/js/图片）可较长缓存（内容哈希化由作者自行负责；本次不做自动哈希）。
- `site:ctx`：`ttl_site` 配置（默认 7 天）；「应用/上传/删除」都会**清一次** ⇒ 即时生效（KV 最终一致 ~60s）。

---

## 5. 迁移（一次性）

现有 D1 `styles` 表里的用户风格（约 4 个 `docshell*` + 若有的文章风格）→：

1. 逐个 `styles` 行（取每 id 最新 version）→ 把 `tpl_json` 写为 `R2 wp-content/themes/<id>/tpl.json`；
2. 若该风格另有资源（`/api/style-assets` 记录的 `wp-content/themes/<id>/...`）→ 已在 R2，无需搬；
3. 建 `D1 k='style'`：`active` = 迁移前 `tpl:active` 所对应的风格 id（或保持当前生效；无法对应则留空=默认模板），`user` = 迁移出的 id 清单；
4. `DROP TABLE styles`（确认无引用后）；
5. 清 `site:ctx`。

> 内置风格（static 里的 14 个）**无需迁移**（本就在 assets）。

---

## 6. 改动清单（CMS 侧）

| 文件 | 改动 |
|---|---|
| `worker/src/tplstore.js` | **重写**：按 `site:ctx.activeStyleId` 读 `tpl.json`（assets→R2 + isolate 短缓存）；4 个 getter 签名不变 |
| `worker/src/site.js` | `getSiteContext` 增读 `k='style'`；合并内置 `index.json` → `ctx.styleIndex` / `ctx.activeStyleId` |
| `worker/src/admin.js` | 风格 API 重写（list/preview/apply/upload-dir/delete/revert-bak/export）；`settings` 支持 `k='style'`；**移除** ensureStylesTable / pruneStyleVersions / version / revert 相关；新增上传目录端点 |
| `worker/src/preview.js` | 预览改为读 `tpl.json`（按 id）|
| `worker/static/admin/app.js` | 模板页改：风格下拉来自 `styleIndex`；「上传风格（选目录）」；编辑 `tpl.json`（可视化或原样）；「应用」；`tpl.json.bak` 回退；移除版本/多套模板 UI |
| `worker/scripts/sync-themes-index.mjs` | 保持写 `index.json`；**追加清一次 `site:ctx`**（内置变动后让清单刷新）|
| `schema.sql` / `admin.js` 的 `ensureCoreSchema` | 移除 `styles` 表 DDL（或保留注释说明已废弃）；`app_passwords` 等不动 |
| 新增 `worker/scripts/migrate-styles.mjs` | 迁移脚本（§5）|

**废除清单**（KV）：`tpl:active` / `tpl:index` / `tpl:sindex` / `tpl:store:*` / `tpl:style:*`。
**保留 KV**：`site:ctx` + 内容缓存（`rss:feed` / `sidebar:data` / 列表键）+ 临时（`login:*` / `task:*`）。

---

## 7. 风险与对策

| 风险 | 对策 |
|---|---|
| `tpl.json` 被 CDN 长缓存 → 改了不生效 | §4：压短缓存头；验证 `curl -I` |
| isolate 短缓存（15s）导致改动稍慢生效 | 可接受（与 CDN 60s 同量级）；必要时「应用」后等一会 |
| 迁移丢用户风格 | 迁移**不删**原 D1 行；先迁移验证无误，再单独 DROP 表 |
| 大风格包（tpl.json 很大）| 文件存储，无 D1 大小限制；assets 单文件上限 25MiB |
| 无多版本回退 | 只有 `tpl.json.bak`（用户确认）|

---

## 8. 与「拆分 WP-CMS / 文档-CMS」的关系

本次**先做**（用户排序）。风格体系收敛成「按 id 读 tpl.json」后，文章页壳与文档壳**共用同一机制**，拆分成本显著下降 ⇒ 本改动是拆分的**前置助力**。
