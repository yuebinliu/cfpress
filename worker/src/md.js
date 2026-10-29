import { marked } from 'marked';

marked.setOptions({ gfm: true, breaks: true });

/**
 * 标题文本 → 锚点 id。
 * 保留中英文 / 数字 / 下划线，其余（标点、空格、行内标签）归一成 `-`。
 * 全空的标题兜底成 `section`，避免出现 id=""。
 */
function slugify(text) {
    const s = String(text == null ? '' : text)
        .replace(/<[^>]*>/g, '')            // 去掉行内标签（<code> 等）
        .replace(/&[a-zA-Z#0-9]+;/g, '')    // 去掉 HTML 实体
        .trim()
        .toLowerCase()
        .replace(/[^\w\u4e00-\u9fa5]+/g, '-')
        .replace(/-{2,}/g, '-')
        .replace(/^-+|-+$/g, '');
    return s || 'section';
}

// 标题带 id —— marked v5 起移除了 headerIds，默认不生成任何锚点
marked.use({
    renderer: {
        heading(token) {
            const inner = this.parser.parseInline(token.tokens || []);
            const id = slugify(inner);
            return '<h' + token.depth + ' id="' + id + '">' + inner + '</h' + token.depth + '>\n';
        },
    },
});

/**
 * 同名标题去重：第二个起追加 -1 / -2 …
 * 不加这一步，两个「Hook system」会拿到同一个 id，点锚点永远跳到第一个。
 */
function dedupeHeadingIds(html) {
    const seen = Object.create(null);
    return String(html).replace(/<(h[1-6]) id="([^"]*)"/g, function (m, tag, id) {
        if (!id) return m;
        if (seen[id] === undefined) { seen[id] = 0; return m; }
        seen[id] += 1;
        return '<' + tag + ' id="' + id + '-' + seen[id] + '"';
    });
}

/**
 * 剥离 YAML frontmatter（`---\nkey: value\n---`），返回 { data, body }。
 * marked 不认识 frontmatter，不剥的话它会渲染成一条 <hr> + 一段文本。
 * 只在文件**开头**才认，正文里的 `---` 分隔线不受影响。
 */
export function parseFrontmatter(src) {
    const s = String(src == null ? '' : src);
    const m = s.match(/^---[ \t]*\r?\n([\s\S]*?)\r?\n---[ \t]*(?:\r?\n|$)/);
    if (!m) return { data: {}, body: s };
    const data = {};
    for (const line of m[1].split(/\r?\n/)) {
        const hit = line.match(/^\s*([A-Za-z0-9_-]+)\s*:\s*(.*)$/);
        if (!hit) continue;
        let v = hit[2].trim();
        if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) {
            v = v.slice(1, -1);
        }
        data[hit[1].toLowerCase()] = v;
    }
    return { data, body: s.slice(m[0].length) };
}

/** Markdown → HTML（渲染出口统一走这里；失败原样返回） */
export function mdToHtml(src) {
    try {
        const out = marked.parse(parseFrontmatter(src).body);
        const html = typeof out === 'string' ? out : String(out || '');
        return dedupeHeadingIds(html);
    } catch (e) {
        return String(src || '');
    }
}

/** Markdown → 纯文本（用于摘要 excerpt；剥标签与图片链接） */
export function mdToText(src, maxLen = 200) {
    try {
        const html = mdToHtml(src);
        const text = String(html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        return text.length > maxLen ? text.slice(0, maxLen) + '...' : text;
    } catch (e) {
        return '';
    }
}
