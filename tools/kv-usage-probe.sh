#!/bin/sh
# Cloudflare KV 用量探针（只读）—— 把 KV 操作按「日期 × 动作」拆开
#
# 用途：怀疑「某个操作在吃 KV 额度」时用它定位。
#   KV 免费额度：读 100,000/天；写 1,000/天；删 1,000/天；列举(list) 1,000/天。
#   典型误判：批量发布/删除文章**并不会**写 KV；真正吃额度的是 `kv.list()`
#   （历史实现里 `purgeCache()`、`/purge-cache`、permalink 清理旧文件 才调它）。
#
# 用法（在 WP 原站宿主机上跑；token 从 WP option `cf_api_token` 读取，需 Account Analytics:Read）：
#   pscp -pw <pw> tools/kv-usage-probe.sh root@<host>:/tmp/
#   plink -ssh -pw <pw> root@<host> "sed -i 's/\r$//' /tmp/kv-usage-probe.sh && sh /tmp/kv-usage-probe.sh [起始日期 2026-10-05] [结束日期 2026-10-12]"
#
# 返回 JSON 里每一行 = 某天 × 某动作 × 某 namespace 的操作次数，例如：
#   {"count":561,"dimensions":{"actionType":"list","namespaceId":"f4e7...","date":"2026-10-11"}}
# 本项目 namespace = f4e7fdd895e247d7bec4250328a2d2ec（wrangler.toml 的 KV_CACHE）。
ACCT="32e005e5abe0d16e6f1c0820df15a728"
FROM="${1:-$(date -u -d '7 days ago' +%F 2>/dev/null || date -u -v-7d +%F 2>/dev/null || echo 2026-10-05)}"
TO="${2:-$(date -u +%F)}"
TOKEN=$(docker exec web-mysql mysql -uroot -proot123456 wordpress -N -e "SELECT option_value FROM wp_options WHERE option_name='cf_api_token' LIMIT 1" 2>/dev/null | tr -d '\r\n')
if [ -z "$TOKEN" ]; then echo 'NO_TOKEN_IN_WP_OPTIONS(cf_api_token)'; exit 1; fi
cat > /tmp/cf-kv-q.json <<EOF
{"query":"query { viewer { accounts(filter: {accountTag: \"$ACCT\"}) { kvOperationsAdaptiveGroups(limit: 200, filter: {date_geq: \"$FROM\", date_leq: \"$TO\"}) { count sum { requests } dimensions { date actionType namespaceId } } } } }"}
EOF
curl -s -X POST https://api.cloudflare.com/client/v4/graphql -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" --data @/tmp/cf-kv-q.json
echo
rm -f /tmp/cf-kv-q.json
