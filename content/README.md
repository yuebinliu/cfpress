# content/ — 文档原稿

这里是文档站的 **Markdown 原稿**，和线上 R2 的 `_src/` 一一对应：

```
content/docs/install.md          →  _src/docs/install.md          →  /docs/install.html
content/plugin/index.md          →  _src/plugin/index.md          →  /plugin/        （该层首页）
content/docs/reference/api.md    →  _src/docs/reference/api.md    →  /docs/reference/api.html
```

目录名（`docs` / `plugin`）就是文档库的 `abc`，也是 URL 的第一段（`xyz`）。
**路径即层级**，不需要额外配置：放进 `plugin/` 就出现在 `/plugin/` 的树里。

## 为什么原稿在仓库里

成品（`_pages/`）随时可以从原稿重建，原稿不行。所以：

- **备份只需要 `_src/`**（= 这个目录），成品不必进版本库；
- 改一篇文档是一次 commit，能看 diff、能回滚；
- 成品页的"成品"性质决定了它不该被当成真相。

## 发布一篇

改动后同步到线上（需要 `worker/wrangler.prod.toml` 和 Cloudflare 凭据）：

```bash
cd worker

# 1) 传原稿（路径要和 content/ 下的结构一致）
npx wrangler r2 object put "cfpress/_src/plugin/overview.md" \
  --file="../content/plugin/overview.md" \
  --content-type="text/markdown; charset=utf-8"

# 2) 登记元数据（已存在则更新标题/摘要）
npx wrangler d1 execute cfpress --remote --config wrangler.prod.toml --command "
  INSERT INTO documents (source_prefix, path, format, title, excerpt, status, date, updated_at)
  VALUES ('plugin','overview.md','md','The WordPress plugin','...','published','2026-10-30','2026-10-30')
  ON CONFLICT(source_prefix, path) DO UPDATE SET
    title = excluded.title, excerpt = excluded.excerpt, updated_at = excluded.updated_at"

# 3) 让旧成品失效（否则访问还是旧页面）
npx wrangler r2 object delete "cfpress/_pages/plugin/overview.html"
```

> 通过后台「文档与上传」上传时，第 2、3 步由接口自动完成；
> 上面这套是命令行路径，适合批量/脚本化。

## 已知的待补工具

`worker/scripts/push-docs.mjs` 还没写。它应该做：遍历 `content/`、
逐篇 put 到 `_src/`、按 H1 提取标题写进 `documents`、最后清掉受影响的成品。

在那之前，批量改动只能按上面的三步手工来。
