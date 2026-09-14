# 托管目标模板化 —— 设计基线

> **状态：设计文档，尚未实施（无代码改动）。**
> 目标：动手前把模型、边界、校验、迁移定下来，作为实施的唯一基线。
> 本文档取代之前的候选方案（"模板 = 能力组合"，已废弃，原因见 §2.2）。

---

## 1. 背景与要解决的问题

### 1.1 现状

多托管目标已落地：`cf_hosting_targets`（WP option，JSON 字符串）保存一组 target，`get_hosting_targets()` 解析后放进 `$this->config['hosting_targets']`，
再由 `for_each_target($capability, $send)` / `d1_batch()` 按**能力**分派。支持 `cfse-worker` / `local` / `s3` 三类。

**但"模板层"目前不存在** —— 等价于"所有 target 共用一个隐式模板"，能力靠每个 target 自己带 `capabilities`。

### 1.2 三个已知问题（都是实际踩过的）

| # | 问题 | 后果 |
|---|---|---|
| P1 | target 缺 `capabilities` 字段时被**默认推导成全能力** | 显示"能写 D1"，与实际不符（`s3` 目标曾误显示可写 D1） |
| P2 | 写入**一条**测试目标就顶掉"目标列表为空 ⇒ 兜底 Worker"机制 | D1 同步静默中断，且表现为"用户自己的配置坏了" |
| P3 | 能力散落在每个 target 上，无统一"该 provider 支持什么"的定义 | 无法在 UI 层阻止无效组合；只能靠运行期日志兜底 |

### 1.3 目标

1. 把"这台托管站具备哪些能力上限"收到**模板**里（选型决定的客观事实）。
2. target 只填**站点特定字段**（账号/端点/凭证）+ **路线选择**（在模板上限内）。
3. 校验前置到配置期（UI 可选性 + 保存警告），运行期只做兜底日志。
4. **不重构**现有已解耦的链路（R2 上传 / 渲染已有全静态与全功能两条路线都能跑通）。

---

## 2. 核心模型

### 2.1 三层概念

```
模板(provider)  ──声明──►  能力上限（这台 provider 最多能做到什么）
     ▲
     │ 引用
   target  ──填──►  站点字段（账号客观事实）+ 意图 + 选用能力（模板上限内的子集）
     ▲
     │ 校验
   意图(intent)  ──要求──►  必需能力（由"要服务什么"决定）
```

### 2.2 决策：模板按 **provider** 分，不按能力组合分

**废弃方案**：模板 = 能力组合（`cloudflare-full` / `static-only` / `local-preview`）。
**采用方案**：模板 = provider（`cloudflare` / `s3` / `local`），**一个 provider 一个模板**。

理由：

1. **配置时的自然思考顺序**是先想"这是哪家的托管"，再想"这个站开没开 D1"。模板对应第一步。
2. `cloudflare` 这一个 provider 内，**存在多条都合法的路线**：只勾 `r2` 走全静态（本地生成 → 上传，甚至发到 GitHub），
   或勾 `d1 + render + r2` 走全功能（md 进 D1 + Worker 自渲染 + R2）。两者**不是两个 provider**。
3. 按能力组合分裂模板会导致模板数量膨胀（每个组合一个），并且"能力作为模板定义维度"与"能力是可选属性"自相矛盾。

> 一句话：**模板定义 provider 的能力上限；走哪条路线由用户在模板上限内选。**

**判定"是不是同一个 provider"的三要素**：**写入机制（`transport`）** + **字段集** + **能力上限**。
三者都相同才算同一个 provider —— **能力相同 ≠ provider 相同**：

| provider | 写入机制 |
|---|---|
| `cloudflare` | Worker HTTP 接口（`/upload`、`/d1-batch`、purge）|
| `s3` | HTTP PUT 对象存储（SigV4）|
| `local` | 写文件系统（`file_put_contents`）|
| `github`（预留）| **git commit + push**，再经 CI 部署 |

GitHub Pages 与服务端能力**看起来**都只需 `r2`（写静态文件），但写入机制与字段集完全不同 ⇒
**它是独立 provider，不能由 `local` / `s3` 承担**。用 `local` 承担时实际发生的是"生成到本地再手工推"，
那是另一回事，却在 UI 上表现为"已发布" —— 属于**静默语义错位**，与问题 P2 同类。

### 2.3 能力：上限 vs 选用（**不设"只能关不能开"**）

早期草案曾提出"target 只能降级、不能升级"，**该限制已废弃** —— 它把"provider 上限"误当成"站点约束"。

修正后的规则：

- 模板声明**能力上限**（`capabilities`）—— provider 的客观事实，例如 Cloudflare 具备 `r2 / d1 / render / cache`。
- target 的**选用能力**必须是模板上限的**子集**（在范围内自由开关）。
- 超出上限 ⇒ 无效配置（UI 层不给出该选项；运行期校验拒绝）。

---

## 3. 模板 schema

```jsonc
{
  "id": "cloudflare",                     // 模板标识（= provider）
  "name": "Cloudflare（Worker + R2 + D1）",
  "transport": "worker-api",              // 写入机制：worker-api | fs | s3 | git（provider 三要素之一）
  "legacy_type": "cfse-worker",           // 回滚兼容：旧代码读的 type 值（双写一致性校验的依据）
  "capabilities": {                       // 能力**上限**：这台 provider 最多能做到什么
    "r2": true,                           // 存静态文件（R2 / 任意对象存储）
    "d1": true,                           // 写结构化数据（文章入库，供列表页/后台读取）
    "render": true,                       // 运行时按需渲染单页（Worker 可执行）
    "cache": true                         // 发布后失效缓存（清 KV）
  },
  "intents": ["md", "static-list", "static-only", "backup"],  // 该 provider 能承接的路线
  "defaults": {                           // 新建 target 时的默认值
    "capabilities": { "r2": true, "d1": true, "render": true, "cache": true },
    "intent": ["md", "static-list"]
  },
  "fields": ["endpoint", "auth_key", "path_prefix", "public_url"]  // 该 provider 需要填的站点字段
}
```

内置模板（首批）：

| id | transport | legacy_type | 能力上限 | 可承接的意图 | 需要的站点字段 |
|---|---|---|---|---|---|
| `cloudflare` | `worker-api` | `cfse-worker` | `r2 d1 render cache` | 全部（md / static-list / static-only / backup）| `endpoint` `auth_key` `path_prefix` `public_url`（可选）|
| `s3` | `s3` | `s3` | `r2` | `static-only` `backup` | `endpoint` `bucket` `region` `access_key` `secret_key` `path_style` `path_prefix` `public_url` |
| `local` | `fs` | `local` | `r2` | `static-only` `backup` | `dir` `path_prefix` `public_url`（可选）|
| `github`（**预留，暂未实现**）| `git` | — | `r2` | `static-only`（若实现）| `repo` `branch` `credentials`（deploy key 或 PAT）|

> **`github` 仅为预留记录**：写入形态是 push 到 Git 仓库 + CI 部署，与 `local` / `s3` 都不同，**不实现时不要用它冒充**。
> 若暂不实现，UI 上就**不出现**该 provider，文档如实写明"该场景需用户手工推" —— 不用 `local` / `s3` 顶替，避免"生成到本地"被误解为"已发布"。

> `s3` / `local` **没有 `d1` / `render` / `cache`** ⇒ "服务 md"路线在 UI 上**不出现**（不是警告，是不可选）。

---

## 4. target schema

```jsonc
{
  "id": "w1",
  "name": "主站 Worker",
  "template": "cloudflare",               // 引用模板（取代裸 type）
  "enabled": true,

  "intent": ["md", "static-list"],        // 意图（显式声明，见 §5）
  "capabilities": {                       // 选用能力：必须是模板上限的子集
    "r2": true, "d1": true, "render": true, "cache": true
  },

  // ---- 站点特定字段（账号客观事实）----
  "endpoint": "https://doc.yuebin.uk",
  "auth_key": "…",
  "path_prefix": "",
  "public_url": ""
}
```

`s3` target 额外：`bucket` / `region` / `access_key` / `secret_key` / `path_style`；`local` target 用 `dir`。

### 4.1 为什么"意图"要做成显式字段

从能力**反推**意图不可靠：勾了 `d1 + r2` 既可能是"全功能站"，也可能只是"顺手勾上"。
显式意图带来两件事：

1. 可以做**自洽性**校验（意图要求的必需能力 vs 实际勾选）；
2. 可以**反向驱动默认值**（选"全静态" ⇒ 自动只勾 `r2`），UI 更省事。

意图支持**多选**（一个站可以同时"服务 md + 服务静态列表 + 备份"）。

---

## 5. 意图枚举与必需能力映射

| 意图 | 必需能力 | 实际行为 | 备注 |
|---|---|---|---|
| `md`（服务 md 文章）| `d1` + `render` | 插件侧把 md **只写 D1**；单页由 **Worker 渲染后写入 R2** | 无 `render` ⇒ md **永远不会有静态产出** |
| `static-list`（服务 D1 动态列表）| `d1` | 列表页实时渲染读 D1 | |
| `static-only`（服务静态 HTML）| `r2` | 本地/远端生成静态文件并上传 | **不需要 `d1`、不需要 `render`；是正常状态，不警告** |
| `backup`（备份 / 分发）| `r2` | 只落对象，不参与服务 | |

> 说明 `render` 的真实作用（不是标签）：插件侧对 md **不上传 R2**，md 的静态页由 **Worker 渲染后写入 R2**。
> 因此一个没有 `render` 的站点（`s3` / `local`），md 文章**根本不会产生静态产出** —— 这就是 `render` 维度必须存在的原因。

---

## 6. 校验：三层

### 第一层 —— UI 可选性（配置期，阻止无效组合）

- 模板上限不覆盖某条路线 ⇒ 该意图**不出现**在选项里（`s3` / `local` 下看不到"服务 md"）。
- 选定意图后，能力复选框只列出**该意图需要 + 该 provider 支持**的项。

### 第二层 —— 保存时自洽性（配置期，警告而非阻止）

| 情况 | 处理 |
|---|---|
| 意图含 `md`，但未勾 `d1` 或 `render` | **警告**："md 文章不会出现在任何地方" |
| 意图含 `static-list`，但未勾 `d1` | **警告**："列表页不会有内容" |
| 意图含 `static-only` / `backup`，但未勾 `r2` | **警告**："静态文件不会上传" |
| 全部目标的能力并集**不含 `d1`** | **警告**："文章不会进 D1：Worker 后台 / 列表页看不到"（对应旧草案那条） |
| 全部目标的能力并集**不含 `r2` 且不含 `render`** | **警告**："静态单页永远不会出现"（更致命） |
| 纯静态路线（只勾 `r2`，未勾 `d1`）| **不警告** —— 这是合法且常见的用法 |

### 第三层 —— 运行期兜底（日志，不打断）

保留现有行为：能力不满足实际动作时**记 WARNING 并跳过**。
例如 `d1_batch()` 现有的 `没有启用「D1 数据」能力的托管目标，跳过 N 条 SQL（纯静态档无需写库）`。

---

## 7. 边界：能力开关 vs target 字段

| 类别 | 归属 | 例子 | 性质 |
|---|---|---|---|
| **能力开关** | target（受模板上限约束）| `r2` / `d1` / `render` / `cache` | **用户选择** |
| **站点字段** | target | `endpoint` / `bucket` / `region` / `credentials` / `path_prefix` / `public_url` | **账号客观事实** |

两者合起来决定实际行为。

补充说明：CF 模型下 `d1` 能力走 **Worker 的 `/d1-batch`**（D1 库由 Worker 端绑定），
所以 `cloudflare` 模板的 target **不需要填 `database_id`** —— 该字段只在"直连 Cloudflare D1 API 降级"路径上使用。

---

## 8. 实施硬约束（动手前必须认下）

1. **保住派生链**：`load_config()` 继续从"首个 `cfse-worker` 目标"派生 `site_url` / `auth_key`，
   使 10 处 `d1_batch` 调用点**零改动**。模板化不得切断这条链。
2. **分阶段**：
   - 阶段一（本次范围）：target / 模板层（JSON 结构 + 校验 + UI），**不动**旧 CF option。
     旧 `cf_account_id` / `cf_api_token` / `cf_r2_bucket` / `cf_d1_database` / `cf_site_url` / `cf_auth_key` 暂作"默认 cloudflare 模板"的取数来源。
   - 阶段二（稳定后）：把旧 option 收拢进模板/目标的 JSON 结构，设置页表单相应调整。

---

## 9. 迁移步骤

1. **留快照**：迁移前 `wp_options` 相关项（至少 `cf_hosting_targets`）导出留档，出问题可回滚。
2. **兼容读取**：`get_hosting_targets()` 同时接受旧结构（`type` 裸字段）与新结构（`template` 引用）——
   旧 target 按 `type` 映射到同名模板（`cfse-worker` → `cloudflare`）。
3. **现有条目处理**（本机现状）：
   - `w1`（`cfse-worker`，`endpoint=https://doc.yuebin.uk`）→ 升级为引用 `cloudflare` 模板，意图 `md + static-list`。
   - `s3t`（`s3`，测试残渣、凭证为假）→ 按结构升级为引用 `s3` 模板；**或直接删除**（它从来不是用户的配置）。倾向删除，是否保留由用户定。
4. **校验上线**：第一层 UI + 第二层保存警告先上；第三层运行期日志已存在，保持。
5. **回归验证**：
   - 全静态路线（只勾 `r2`）：本地生成 → 上传，无警告。
   - 全功能路线（`d1 + render + r2 + cache`）：发布文章 → 日志出现 `D1 批量执行 N 条（Worker）`。
   - 无 `d1` 目标的配置：日志出现"跳过 N 条 SQL"，**UI 保存时也有明确警告**（不再是静默）。

---

## 10. 开放问题（实施前需拍板）

1. ~~发布到 GitHub 归入 `static-only`、由 `local` / `s3` 承担~~ —— **已否决**（理由见 §2.2 判定标准：能力相同 ≠ provider 相同）。
   **结论：GitHub Pages 是独立 provider（`transport: git`），文档中记为"预留，暂未实现"。**
   将来若实现，两条候选路径待选：
   - **本地 git push**：需要服务器有 git + 认证（deploy key 或 PAT），并做增量提交（只 commit 变化文件）；
   - **GitHub Contents / Trees API**：无需本地 git，走 HTTP；但**必须批量提交**（Trees API 一次 commit 提交多文件），
     逐文件 PUT 会在全量重建时撞 API 速率限制（292 篇 × 2 文件 ≈ 584 次请求）。
   **在实现之前，本场景保持"用户手工推"**，不提供替代 provider。
2. **`cache` 能力的实际动作**目前只有"清 KV"（`cache` 对应 `purge`），是否需要扩展定义（例如 CDN 缓存刷新）。
3. **模板是否允许用户自定义 / 新增**（首批只内置 3 个），还是定位为"只读内置"。
4. **凭证不再明文回显**：`api_token` / `secret_key` 目前明文存 `wp_options` 且设置页 GET 明文回显；
   打包时是否顺手改为"只显示已设置状态"。（低成本改进，非本设计必需。）
5. **旧 option 合并（阶段二）的时机与触发条件**。

---

## 11. 阶段一验收标准（**已封板**）

> 三个待确认细节已全部拍板（见本节末），设计基线与验收标准**封板**，进入实施。

**阶段一范围**：target / 模板层（`transport` + 能力上限 + 意图 + 三层校验 + 新旧结构兼容读），**不动**旧 CF option、不动导出链。

### A. 回归：行为与现状**完全一致**

| # | 必须保持不变的行为 | 验收方式 |
|---|---|---|
| A1 | `load_config()` 从"首个 `cfse-worker` 目标"派生 `site_url` / `auth_key`；无该目标时回退 `cf_site_url` / `cf_auth_key` | 探针：分别构造"有 worker 目标""无目标"两种配置，断言派生结果 |
| A2 | `d1_batch()` 对 cloudflare 目标走 `worker_d1_batch()`（日志 `D1 批量执行 N 条（Worker）`）| 探针：`d1_batch(['SELECT 1 AS ok'])`，断言日志出现该行 |
| A3 | 目标列表为空时仍**兜底**成一条默认 cloudflare 目标（行为与现状一致）| 清空 `cf_hosting_targets` 后调用 `get_hosting_targets()`，断言返回 1 条且能力全开 |
| A4 | **导出链零改动**：`export_post()` / `publish_artifact()` / `target_upload()` / `target_delete()` / `target_purge()` 以及 10 处 `d1_batch` 调用点不修改 | `git diff` 审查：这些函数体无改动（`get_hosting_targets()` 自身允许改）|
| A5 | 设置页现有功能照常（托管目标表增删、三个发布开关、缓存策略、批量任务）| 渲染设置页 HTML，断言各控件与字段值在位 |
| A6 | 端到端：发布/导出一篇文章，结果与改造前一致（单页 + D1 + 日志）| 真机导出单篇，比对日志与结果 |

> **验收口径**：A 组全部通过才算"没有回归"；其中 **A1 / A2 / A4 为硬门禁**（派生链或调用点任何改动直接判失败）。

### B. 新增：模板层可读、可引用、可校验

| # | 能力 | 验收方式 |
|---|---|---|
| B1 | 内置 3 个模板可读（`cloudflare` / `s3` / `local`，各含 `transport` / `capabilities`（上限）/ `intents` / `fields` / `defaults`）| 探针打印模板定义，断言 3 个且字段齐全 |
| B2 | 模板为**只读内置**（无保存模板的接口）；target 通过 `template` 字段引用 | 断言无模板写入口；UI 上模板以下拉形式选择 |
| B3 | **第一层校验（UI 可选性）**：`s3` / `local` 模板下**不出现**"服务 md"意图 | 渲染设置页 HTML，断言切换模板后意图选项集合正确 |
| B4 | **第二层校验（保存警告）**：意图含 `md` 但缺 `d1`/`render` ⇒ 警告；纯静态（只勾 `r2`）⇒ **无警告** | 构造两组配置分别保存，断言警告文本的有无 |
| B5 | **第三层校验（运行期）保持**：能力不满足实际动作 ⇒ 记 WARNING 并跳过 | 断言日志出现跳过提示（现有行为）|
| B6 | target 的 `capabilities` 必须是模板上限的**子集**；超出时**裁剪 + 警告**（不拒绝保存）| 构造超限配置，断言保存后能力被裁剪且出现警告 |

### C. 边界：新旧结构读取优先级

| # | 规则 | 验收方式 |
|---|---|---|
| C1 | 单条 target：有 `template` ⇒ 用模板；仅有旧 `type` ⇒ 映射同名模板（`cfse-worker` → `cloudflare`）；**两者都有且冲突 ⇒ 以 `template` 为准并记日志** | 三种形态各构造一条，断言解析结果 |
| C2 | 保存时**一律写新结构**（含 `template`）；旧结构**只读兼容**，不回写 | 保存后检查 option JSON 形态 |
| C3 | **过渡期双写 + 一致性校验**：保存时同时保留 `type`（值 = 模板的 `legacy_type`）；并校验 `type` 与 `template` 是否**匹配**（`cloudflare`→`cfse-worker`、`s3`→`s3`、`local`→`local`）；**不一致 ⇒ 警告 + 记日志**（不自动改，因为无法判断哪个才是当前意图） | ① 正常保存 ⇒ JSON 同时含 `template` 与匹配的 `type`；② 构造不匹配的组合 ⇒ 断言出现警告 + 日志；③ **往返测试**：保存后用**旧结构语义**读取（只用 `type`）⇒ 断言解析结果与新结构一致 |
| C4 | 迁移幂等：重复读取 / 保存不改变语义与行为 | 连续保存两次，比对 JSON 与行为 |
| C5 | **禁止"内存覆盖 option"式假通过**：验收必须走真实 `update_option` 入口（反射改内存 config 只能用于辅助定位，不能作为通过依据）| 审查验证脚本：断言其通过 option / 表单入口写入 |
| C6 | **读时也校验一致性**（不一致可能由手改 JSON、或"回滚再升级"产生 —— 旧代码保存只写 `type`，`template` 会变陈旧）；冲突以 `template` 为准并记日志，**日志需限流**（每进程/每次检查最多一条，避免每次请求刷日志）| 构造三条不一致数据，断言：解析以 `template` 为准、日志只出现一次 |

### 待确认的三个细节（动手前拍板）

1. **兜底与显式默认并存**（对应 A3）：建议**保留**现有"列表为空 ⇒ 兜底"，同时**新增**显式"默认托管站"；优先级 = 显式 > 列表 > 兜底。
2. **超出模板上限的处理**（对应 B6）：建议**裁剪 + 警告**，不拒绝保存（与"不阻止不寻常但合法的组合"一致）。
3. **`type` 双写 + 一致性校验**（对应 C3/C6）：**已确认** —— 双写，但保存时校验 `type` 与模板的 `legacy_type` 是否匹配，不一致则警告 + 记日志；
   读时做同样校验（限流）。理由：双写的价值是"回滚时旧代码能跑"，旧代码的依据是 `type` 正确；
   若两者不一致，回滚后行为与新代码不同 ⇒ 双写反而制造"看起来安全、实际不安全"的假象。

---

## 附：与现状的对应关系

| 现状 | 模板化后 |
|---|---|
| `type: "cfse-worker" \| "local" \| "s3"` | `template: "cloudflare" \| "local" \| "s3"`（语义等价，`cfse-worker` 改名 `cloudflare`）|
| `capabilities`（缺省推导全能力 ⇒ P1）| 能力**上限**来自模板；target 的选用值校验为上限的子集（无"缺省推导"）|
| "目标列表为空 ⇒ 兜底 Worker"（隐式 ⇒ P2）| **显式**"默认托管站"配置；不再依赖列表是否为空 |
| 能力散落、运行期才发现（P3）| 配置期两层校验前置（UI 可选性 + 保存警告）|
