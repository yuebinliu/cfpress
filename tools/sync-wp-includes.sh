#!/bin/sh
# 把 WP 核心资源（块样式 + 常用 JS）镜像到 worker/static/wp-includes/，路径原样保留 ⇒ assets 直接命中。
SRC=/mnt/optane/webstack/www/wp-includes
DST=/mnt/optane/webstack/www/wp-content/plugins/cf/worker/static/wp-includes
mkdir -p "$DST"

# 1) 块样式：wp-includes/blocks/<block>/{style.min.css,theme.min.css}
cd "$SRC" || exit 1
for f in $(find blocks -type f -name '*.min.css' 2>/dev/null); do
  mkdir -p "$DST/$(dirname "$f")"
  cp "$f" "$DST/$f"
done

# 2) 常用 JS（head 里常见的那些 + 用户提到的 jquery）
for f in \
  js/jquery/jquery.min.js \
  js/jquery/jquery-migrate.min.js \
  js/comment-reply.min.js \
  js/wp-emoji-release.min.js \
  js/wp-emoji-loader.min.js \
  js/dist/script-modules/interactivity/index.min.js \
  css/dist/block-library/style.min.css \
  css/dist/block-library/theme.min.css ; do
  if [ -f "$SRC/$f" ]; then
    mkdir -p "$DST/$(dirname "$f")"
    cp "$SRC/$f" "$DST/$f"
  else
    echo "  (源里没有: $f)"
  fi
done

echo "copied files=$(find "$DST" -type f | wc -l)  size=$(du -sh "$DST" | cut -f1)"
echo
echo "=== 抽查 ==="
ls "$DST"/js/jquery/ 2>/dev/null
echo "blocks 子目录数: $(find "$DST/blocks" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l)"
