# 托管 transport：机制与语义对照

> 状态：**代码实现完成**，其中 `github` 已真机验证（含中文路径修复），其余待验证。
> 相关文档：`hosting-templates.md`（模板 / 能力体系）、`hosting-config-context.md`（配置归属）。
> 维护提醒：新增一种 transport 时，必须同步更新本文件的对照表（`get_hosting_templates()` / `sanitize_hosting_targets()` / 设置页下拉 / `is_deploy_target()` / `target_deploy()` 五处）。

## 1. 两种部署模型

| 模型 | transport | 上传方式 |
|---|---|---|
| **逐文件型** | `cfse-worker` / `s3` / `local` / `ftp` / `webdav` | 每个文件一次请求（`/upload`、`PUT`、`ftp_put`）|
| **整站事务型** | `github` / `git` / `gitlab` / `netlify` / `vercel` | 一次提交或一次部署（blob→tree→commit、`actions[]`、deploy API）|

整站事务型不参与「发布整站」的逐文件阶段（`is_deploy_target()` 判定），统一走后台
**「📦 部署到托管商」**（`deploy_site_to_deploy_targets()` → `target_deploy()`）。

## 2. 完整对照表（**语义是重要差异，别混用**）

| transport | 上传 | 删除远端多余文件 | 语义 | 验证 |
|---|---|---|---|---|
| `cfse-worker` | 逐文件 `POST /upload` | 事件驱动（删文章 → `/delete`）| 覆盖 | ✅ |
| `s3` | 逐文件 `PUT`（SigV4）| 事件驱动（删文章 → `DELETE`）| 覆盖 | ✅ |
| `local` | 写本地文件 | 事件驱动（删文章 → 删文件）| 覆盖 | ✅ |
| **`ftp`** | 逐文件 `ftp_put` | **无** | **增量覆盖（≠ 同步）** | ⬜ |
| **`webdav`** | `PUT` | **无** | **增量覆盖（≠ 同步）** | ⬜ |
| `github` | 一次 commit（blob→tree→commit）| **整站对比删除**（仅 `path_prefix` 之下）| **真同步** | ✅ |
| `git` | 同上（`api_base` 可配：Gitea / 自建）| 同上 | **真同步** | ⬜ |
| `gitlab` | 一次 commit（`actions[]` 多文件）| 同上 | **真同步** | ⬜ |
| `netlify` | deploy（sha1 digest）| **天然**：每次 deploy 都是全新快照 | **真同步** | ⬜ |
| `vercel` | deployment（manifest + files）| **天然**：deployment 即快照 | **真同步** | ⬜ |

### 「事件驱动删除」是什么意思

`cfse-worker` / `s3` / `local` 的删除**由 WordPress 侧事件触发**（删除文章、改 permalink 迁移、清理旧文件时
调用 `target_delete()`）。它们**不做**「整站比对」——若远端存在产物目录里没有的文件（例如在别处手工放的），
不会被自动清理。所以严格说它们是「**覆盖写入 + 事件驱动删除**」，而非「镜像同步」。

## 3. 「增量覆盖」（ftp / webdav）的实际影响

只写不删 ⇒ 远端可能残留：

- 已删除文章的旧单页 —— **旧 URL 仍然可访问** ⚠️（内容已下架但还能被搜到 / 被访问）
- 改名或改 permalink 后的旧路径文件
- 历史遗留目录，随时间累积

因此：

- 需要**严格镜像**（远端 == 产物目录）时，请选整站事务型：`github` / `git` / `gitlab` / `netlify` / `vercel`；
- 或先发布到 `local` 目录，再用 rsync / 镜像工具自行同步；
- ftp / webdav 更适合「只增不改」的场景（备份、附件归档）；
- **计划**：等 FTP/WebDAV 真机验证通过后再补「清理」开关（默认关闭），使它们也能做严格同步。

## 4. 已知限制（待办 / 注意）

| transport | 限制 |
|---|---|
| `ftp` / `webdav` | **清理功能未实现**（`ftp_nlist` / `PROPFIND` 递归删除）。**已决定：等 FTP/WebDAV 真机验证通过后再做**（降低误删风险）；届时须**默认关闭 + 显式勾选**，且只在该目标的前缀范围内删除（删除不可逆，配错 endpoint 会误删）|
| `ftp` | 依赖 PHP `ftp` 扩展；FTPS 需服务端支持显式 TLS |
| `webdav` | 坚果云需用「应用密码」；无清理 |
| `netlify` | 部署限速 **3/分、100/天** |
| `vercel` | 首次部署要求项目**已存在**（未带 `projectSettings`）|
| `gitlab` | Pages 只发布 CI 产物 `public/`（默认 `path_prefix=public`，并自动带 `.gitlab-ci.yml`）；推完需等 CI |
| `git` | 依赖目标服务**完整实现** GitHub 的 git data API（Gitea 支持；**Gitee 的 API 不同，不适用**）|
| `cloudflare pages` | 需 BLAKE3（PHP 无内置）⇒ 建议用 `github` 目标 + Pages 的 **Git 集成**绕过 |

## 5. 关键路径形态（跨 transport 的坑）

| 场景 | 规则 |
|---|---|
| Worker 读取 URL → R2 key | `r2KeyFromPath()`：解码成**原文** |
| s3 对象名 | **原文**（canonical URI 里 `%` 编码成 `%25`）|
| git / github 文件路径 | **原文**（GitHub 按真实文件名查找；早期用 URL 形态导致 404，已修）|
| ftp 远端文件名 | **原文**（文件系统）|
| webdav 路径 | 内部用**原文**，URL 逐段 `rawurlencode` |
| `list()` 返回的 `o.key` / KV 里的旧 key | **已是存储形态，不得再解码** |
