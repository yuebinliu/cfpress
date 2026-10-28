#!/bin/sh
# 服务器端：解压 /tmp/wp-themes/*.zip 到 wp-content/themes/，逐个判断块主题并生成风格包。
# 产出：plugins/cf/styles/<slug>/（tpl.json + 主题资源，扁平）；日志：/mnt/.../cfse-batch-fetch.log
# 下载的主题**保留**（用户要本地看风格）。
LOG=/mnt/optane/webstack/www/cfse-batch-fetch.log
THEMES=/mnt/optane/webstack/www/wp-content/themes
STYLES=/mnt/optane/webstack/www/wp-content/plugins/cf/styles
PHP=/var/www/html/wp-content/plugins/cf/tools/fse-skin-extract.php
STYLES_C=/var/www/html/wp-content/plugins/cf/styles

{
  echo "==================== 批量抓取开始 $(date '+%F %T') ===================="
  ok=0; skip=0; fail=0
  for z in /tmp/wp-themes/*.zip; do
    [ -f "$z" ] || continue
    slug=$(basename "$z" .zip)
    if [ -d "$THEMES/$slug/templates" ] && [ -f "$THEMES/$slug/theme.json" ]; then
      echo "[$(date '+%T')] $slug 已是块主题，跳过解压"
    else
      rm -rf "$THEMES/$slug"
      unzip -q -o "$z" -d "$THEMES" 2>&1 | sed "s/^/    /"
    fi
    # 判断块主题
    if [ ! -d "$THEMES/$slug/templates" ] || [ ! -f "$THEMES/$slug/theme.json" ]; then
      echo "[$(date '+%T')] -- $slug 不是块主题（缺 templates/ 或 theme.json），跳过"
      skip=$((skip+1))
      continue
    fi
    mkdir -p "$STYLES/$slug"
    out=$(docker exec web-php php "$PHP" --theme="$slug" --site=https://doc.yuebin.uk \
            --assets="$STYLES_C/$slug" --out="$STYLES_C/$slug/tpl.json" 2>&1)
    if echo "$out" | grep -q 'written:'; then
      size=$(echo "$out" | grep 'written:' | sed 's/.*(\([0-9]*\)B).*/\1/')
      unc=$(echo "$out" | grep 'uncovered:' | tail -1 | sed 's/^uncovered: //')
      nf=$(find "$STYLES/$slug" -type f | wc -l)
      echo "[$(date '+%T')] OK  $slug  tpl=${size}B  资源=${nf}  uncovered=$unc"
      ok=$((ok+1))
    else
      echo "[$(date '+%T')] FAIL $slug"
      echo "$out" | tail -5 | sed 's/^/    /'
      fail=$((fail+1))
    fi
  done
  echo "==================== 完成 $(date '+%F %T')  OK=$ok SKIP=$skip FAIL=$fail ===================="
  echo "当前主题：$(curl -s --max-time 20 http://192.168.2.2/cfse-current-theme.php | head -1)"
} >> "$LOG" 2>&1
