# MD Publisher（Obsidian → CF Static Export Pro Worker）

把当前笔记的 **Markdown 原文**发布到自建 Worker（WP REST 兼容端点，`/wp-json/wp/v2/posts`）。零第三方依赖，只依赖 Obsidian 自身 API。

> 本目录是**可直接使用**的插件源码（在原方案基础上修正了三处会踩坑的地方，见下）。

## 安装

把整个 `obsidian-md-publisher/` 目录放到库的 `.obsidian/plugins/` 下，然后在 Obsidian「设置 → 第三方插件」里启用。

## 构建（如果改了 `main.ts`）

```bash
cd .obsidian/plugins/obsidian-md-publisher
npm init -y
npm install --save-dev typescript esbuild
npx esbuild main.ts --bundle --external:obsidian --format=cjs --target=es2018 --outfile=main.js
```

> 注意：目标平台用默认（browser）。Obsidian 插件跑在浏览器/Electron 环境，**不要**用 `--platform=node`。

## 配置（设置 → MD Publisher）

| 字段 | 说明 |
|---|---|
| **端点 URL** | `https://<你的站点>/wp-json/wp/v2/posts` |
| **用户名** | Worker 后台的用户名（应用密码要由该用户生成） |
| **应用密码** | Worker 后台「用户 → 🔑 应用密码 → 生成新密码」（明文只显示一次）。**认证固定 Basic** |
| **图片前缀** | 例如 `https://<你的站点>/wp-content/uploads`。笔记里的 `![[a.jpg]]` 会转成 `![a.jpg](前缀/a.jpg)`；留空则不转换 |

## Frontmatter 约定

```yaml
---
title: 文章标题          # 缺省用文件名
status: draft            # draft（默认，更安全）/ publish
slug: my-post            # 可选，缺省按标题生成
categories: [tech]       # 可选，建议写 slug
tags: [obsidian, md]     # 可选
wp_id: 1789....          # 首次发布后插件会自动写回；有此字段即走更新
---
正文 Markdown……
```

## 与 Worker 的契约

- `POST /wp-json/wp/v2/posts` 新建；**`PUT /wp-json/wp/v2/posts/<id>` 更新**（Worker 也宽容接受 `POST /wp-json/wp/v2/posts/<id>`，但按标准语义写 PUT 更稳）。
- 请求体带 `content_format: "md"` ⇒ Worker 把正文**按 md 原稿存储**，渲染出口（单页/列表/RSS）才用 `marked` 转 HTML。
- 认证：`Authorization: Basic base64(用户名:应用密码)`。**不支持 Bearer** —— 用 Bearer 会 401。
- 新文章 `wp_id` 由 Worker 生成（时间戳量级），归属判据即此形态；插件把返回的 `id` 写回 frontmatter。

## 已知限制

- **`[[笔记双链]]` 不会被渲染**（Worker 端用标准 CommonMark/GFM 渲染，不认 Obsidian wiki 语法）—— 图片已由插件转换，双链暂保持原样。
- **图片文件本身要能被访问**：`![[a.jpg]]` 只改引用地址，不会上传文件。需要你把图片放到「图片前缀」对应的位置（例如站点媒体库/R2 的 `wp-content/uploads/`）。若嫌麻烦，可后续给 Worker 补 `POST /wp/v2/media` 由插件自动上传。
- 未做重试/超时提示以外的错误恢复；失败会在 Obsidian 里弹 Notice，正文本身不会丢。

## 联调核对清单（第一次用时按此走）

1. 发一篇**纯文字**笔记（`status: publish`）：
   - Worker 后台能看到该篇，格式为 `md`、正文是原稿（含 `## ` 等标记）；
   - 访问 `https://<站点>/<slug>/` 应 200（若 R2 尚无单页，会现场渲染并回填，响应头带 `X-CFSE-Render: on-demand`）；
   - frontmatter 被写回 `wp_id`。
2. 再发一次同一篇（改点文字）：应走 `PUT` 更新成功（不是新增一篇）。
3. 发一篇带 `![[image.jpg]]` 的笔记：
   - 单页 HTML 里的 `<img src>` 应是「图片前缀 + 文件名」；
   - **直接访问那个图片地址**是否 200 —— 若是 404，说明文件还没放上去（这就是要不要做 `/media` 的判断依据）。
