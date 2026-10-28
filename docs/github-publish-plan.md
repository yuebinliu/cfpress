# worker 侧 CMS 独立发布 GitHub —— 处理清单（2026-10-19）

> 目标：把 `worker/`（Cloudflare Worker + D1 + R2 + KV 的静态站/轻 CMS）单独开仓库公开。
> 现状：主仓库（WP 插件 + worker 混合）从未推过远端（`git remote` 为空），worker 下已跟踪 595 个文件。
> 结论先行：**没有发现硬编码密钥**；阻塞项是 5 个，建议项 7 个。全部做完约 1 天。

---

## A. 已完成（本次顺手做掉）

> **2026-10-19 晚更新**：按拍板结果（GPL-2.0-or-later / 瘦身 / i18n 先剔除 / 全新历史），
> 新仓库已落地在 **`C:/Users/dog/Documents/cf-static-export-pro/`**，两个提交
> （`aeb5b97` v2.3.0 initial release + lockfile），`--dry-run` 构建验证通过。
> 主仓库未动，历史完整保留。

| 项 | 说明 |
|---|---|
| ✅ 新仓库 `cf-static-export-pro` | 复制 worker → 瘦身 → 清理 → git init。58 个文件、~700K（原 16M）。剔除了 saaslauncher/twenty* 5 个外部主题、`static/wp-includes/`、`static/en/`（个人落地页）、`migrations/2026-10-19-i18n.sql`（i18n 选 B） |
| ✅ 个人信息清零 | `routes.js`/`lang.js` 注释改 `your-site.example`；全仓 grep `yuebin` = 0 |
| ✅ LICENSE = GPL-2.0 全文；package.json 补 license/description | |
| ✅ README 修正 | 外链 `../tools` `../schema.sql` 改为自包含描述；`schema.sql` 收进 `docs/`；目录树、`cd worker` 路径、ASSETS binding 说明全部对齐 |
| ✅ wrangler.toml.example 补 `binding = "ASSETS"` | 带「为什么不能省」的注释（setup.mjs 以 example 为母本生成，一处修两处生效） |
| ✅ 主题清单重建 | `sync-themes-index.mjs` 重扫，内置风格 10 个自研主题 |
| ✅ 路由生成关系注释修正 | `routes.js` 与 `routes.template.js` 的头注释改为自己仓库的真实路径与同步规则 |
| ✅ 离线构建验证 | example 配置 + 假 ID 走 `wrangler deploy --dry-run` 通过（375 KiB / gzip 86 KiB） |
| ✅ `tools/csdn-publisher/` 删除 | 从未提交过（纯本地 5 个文件，80K），已备份到 `C:/tmp/cf-trash/csdn-publisher-backup-20261019.zip` |
| ✅ `worker/wrangler.toml` 移出主仓库 git 跟踪 | 含真实 `database_id` / KV `id`。`.gitignore` 已加规则，磁盘文件保留、部署不受影响 |
| ✅ 密钥扫描 | `src/`、`scripts/`、`static/admin/` 无硬编码 secret；主仓库 git 历史扫描也无 |

---

## B. 阻塞项（不处理不能公开）

### B1. 仓库策略：全新历史 vs 保留历史

| 方案 | 做法 | 代价 |
|---|---|---|
| **全新历史（推荐）** | 复制 worker 内容到新目录 → `git init` → 一个 `v2.3.0 initial release` 提交 → push | 丢掉提交历史（可把主仓库留着当内部存档） |
| subtree split 保留历史 | `git subtree split -P worker -b worker-only` → 推该分支 | `wrangler.toml` 在历史里出现过 4 次（含真实 ID）；公开前必须 `git filter-repo` 清洗，且要复查全部历史是否还有其它本机信息。多花半天，收益只是旧提交记录 |

### B2. 个人信息清理（3 处代码 + 2 处资源）

| 位置 | 内容 | 处理 |
|---|---|---|
| `worker/static/en/index.html` | 整页是你站点的英文落地页：canonical/hreflang/品牌全部指向 `yuebin.uk`，页脚署名 Yuebin | **不要随 CMS 分发**。它是今天为线上做的资产 —— 建议从仓库剔除、改由 R2 或部署脚本单独管理；或改造成 `static/en/index.html.example` |
| `worker/src/routes.js:9` | 头注释里 `https://doc.yuebin.uk - 静态站点URL` | 改成 `https://your-site.example` |
| `worker/src/lang.js:105` | 注释示例 `https://yuebin.uk` | 同上 |
| `worker/static/wp-content/themes/saaslauncher/tpl.json` | 从你 WP 站抓的模板快照，内嵌 `wp.yuebin.uk`、`yuebin's blog` | 该主题 4.7M 本来就是外部主题（见 B4），要么整体剔除要么清洗 tpl.json |

### B3. `wrangler.toml.example` 里残留的本机痕迹

`name = "wp-static-pro"`、`compatibility_date` 等没问题；确认 3 个 `REPLACE_*` 都还是占位符（已确认是）。**但 example 里没有 `[assets] binding = "ASSETS"`**（真实 toml 有，且是 index.json 自愈回退的必要项）——补进 example，否则新用户照抄会踩坑。

### B4. 主题资产（14M / 265 文件）与许可

全部合规，但要想清楚带多少出去：

| 目录 | 体积 | 许可 | 建议 |
|---|---|---|---|
| `themes/twentyten|eleven|fifteen|nineteen` | 5.5M | WordPress 官方主题，GPL-2.0+ | 可带（注明来源）；不想要可剔 |
| `themes/saaslauncher` | 4.7M | CozyThemes，**GPL-2.0+**（style.css 头已声明） | 许可上可分发；但 1.8M 的 `tpl.json` 是你站点的快照（B2），建议剔主题或清数据 |
| `themes/cfse-demo` 等 7 个自研 | ~0.5M | 自有 | 核心卖点，必带 |
| `static/wp-includes/` | 1.3M | WP 核心前端资源，GPL-2.0+ | 可带；README 注明 |
| `static/admin/vendor/marked.min.js` | 小 | MIT | 带，LICENSE 里列一下 |

两个选项：**全量带**（clone 大、开箱即用）或 **只带自研 + 一个 demo**（仓库干净，README 给 `scripts/sync-wp-includes.sh` 之类补齐指引）。

### B5. LICENSE 选择

- 捆绑物全是 GPL-2.0+（WP 主题 ×4、saaslauncher、wp-includes），自有代码无历史包袱。
- **最省心：整个仓库 `GPL-2.0-or-later`**，和被捆绑物同族，无嵌许可争议；
- 想宽松：自有代码 MIT + `static/wp-content/themes/`、`wp-includes/` 保留各自 GPL 并在 NOTICE 里声明 —— 可行但要在 README 讲清楚「哪些目录不是 MIT」。

### B6. 安全发布前的代码加固（2026-10-19 晚重新分级）

> 背景：`/d1-batch` 的唯一调用方是自家 WP 插件（`includes/class-worker-cms.php:758`
> `write_to_d1()`——发布文章时把整篇的 SQL 打包成一次 HTTP，走 Worker 的 D1 binding 近端写入），
> 语句是插件拼的固定模板 + 参数，有 `X-Auth-Key` + 600/min 限速双守护。攻击前提是 key 泄漏。

| 项 | 重新定级 | 理由 |
|---|---|---|
| `/d1-batch` 语句白名单 | **降级为 README 警告** | 白名单可能卡住插件将来加字段；唯一调用方是自家插件，接口能力≠实际用法。README 写明「此接口仅限自家插件使用、务必用高强度随机 key」 |
| ~~密钥分离~~ | **降级为 backlog** | AUTH_KEY_SECRET 已是 wrangler secret（非明文入库）；分离收益有限 |
| `GET /api/settings` adminOnly | **建议做（一行）** | editor 不该读全站设置——正确性问题，与发布无关 |
| 密钥恒时比较（8 处 `!==`） | **建议做（1h 机械替换）** | 实际可利用性低，但属于「写对了没有」 |
| `index.js:8` 响应头 try | **建议做（几行）** | 防 500 掩盖真实响应 |
| **key 强度自查** | **真正重要的一个** | 三个写通道（d1-batch/upload/delete）全押在 X-Auth-Key 上。公开仓库=发攻击说明书，key 若是弱口令一切白搭。用 `wrangler secret put AUTH_KEY_SECRET` 换成 ≥32 位随机串（不读旧值，直接换新 + WP 侧同步改） |

### B7. README 外部引用修正

| 引用 | 问题 |
|---|---|
| `../tools/obsidian-md-publisher/` | 在主仓库 tools/ 下 —— 单独成库后路径失效。要么把该插件一并放进新仓库 `tools/`，要么链接改成它的独立仓库地址 |
| `../schema.sql` | 同上。Worker 本身 `ensureCoreSchema()` 自动建表（admin.js:196），不依赖该文件；可把 schema.sql 复制进 `docs/schema.sql` 作参考，或删引用 |

---

## C. 建议项（发布质量）

1. **`routes.template.js` 与 `routes.js` 是生成关系**：主仓库靠约定（手改 template 会被覆盖）。独立仓库要么带上生成脚本、要么只留 `routes.js` 并删掉 template，避免贡献者改错文件。
2. **LICENSE 之外的元数据**：`package.json` 补 `repository`/`license`/`author` 字段；仓库根加 `LICENSE`、`SECURITY.md`（漏洞披露邮箱）、可选 `CONTRIBUTING.md`。
3. **CI**：加一个最小的 GitHub Actions —— `node --check src/*.js` + `npx wrangler deploy --dry-run`，免费且能挡低级错误。
4. **测试**：目前零测试。评论渲染/深度平铺那套纯函数测试（本次 32 项）稍微通用化就能进 `test/`，配合 CI 价值很高。
5. **i18n 功能是半成品，发布前必须二选一**（已核实）：`ensureCoreSchema()`（admin.js:196）的建表 DDL **不含** `posts.lang` / `translation_key` / `taxonomies.lang`——这些只存在于 `migrations/2026-10-19-i18n.sql`；前台查询（database.js）虽然支持可选 `lang` 参数，但 `routes.js` 还没传。后果：新库能跑（不传 lang 就不引用该列），但仓库里带着一个「跑不起来」的 i18n 迁移文件和 lang.js / tpl.en.json。
   - **选 A（发布前做完）**：把 i18n 三列补进 `ensureCoreSchema` 的 DDL + `routes.js` 传入 lang —— i18n 成为完整功能；
   - **选 B（发布先不带）**：剔除 `migrations/2026-10-19-i18n.sql`，`lang.js` / `tplstore` 的 lang 参数、`tpl.en.json` 暂时保留（向后兼容、不引用列，不碍事）——等做完再发。
6. **版本对齐**：`package.json` 2.3.0 vs schema.sql 头 3.1.0，发布前统一口径。
7. **仓库描述/话题**：`cloudflare-worker` `cms` `d1` `static-site` `wordpress`，配一张 `/admin/` 截图进 README。

---

## D. 建议的执行顺序

```
1. 决定 B1（历史策略）+ B5（license）          ✅ 已定：全新历史 + GPL-2.0-or-later
2. B6 安全加固（d1-batch 白名单等 5 项）        ← 发布前唯一剩下的硬性工作（半天）
3. 复制到新仓库 → git init → LICENSE/CI/README  ✅ 已完成（cf-static-export-pro/）
4. 用 wrangler.toml.example 从零走一遍 setup.mjs，验证新用户路径能通
5. push 到 GitHub（见下）
```

第 4 步是关键：**用一个干净的 Cloudflare 账号（或先 --dry-run，本次已过）验证「clone → setup → deploy → /admin/ 初始化 → 写一篇文章」全链路**，这是别人拿到仓库的第一条路，必须亲自走通再发布。

### push 步骤（B6 做完之后）

```bash
cd /c/Users/dog/Documents/cf-static-export-pro
gh repo create cf-static-export-pro --public --source=. --push
# 或手动：
#   GitHub 建空仓库 → git remote add origin git@github.com:<你>/cf-static-export-pro.git
#   git push -u origin main
```

### push 前最后自查清单

- [ ] B6 五项加固完成并部署自测
- [ ] 全仓 `grep -ri yuebin` = 0
- [ ] `grep -rE "(secret|token|key)\s*=\s*['\"][^'\"]{8,}"` 无命中
- [ ] README 快速通读一遍（以陌生人视角）
- [ ] `npm run deploy --dry-run` 在干净 clone 上能过
