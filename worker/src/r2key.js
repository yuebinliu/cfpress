/**
 * R2 key 规范（两种形态，各有用途 —— 见 docs/transports.md）：
 *
 * ① 存储 key = 「URL 形态」：非 ASCII 逐段编码为**小写 `%xx`**（`r2KeyFromUrl`）。
 *    ← **线上存储用这个**。R2 控制台按 `/` 分层显示（`%e6%b5%8b.../index.html` → 文件夹 + 文件）；
 *      若换成中文原文 key，控制台会把整条 key 平铺成一个名字（b8f52a4 引入的回归，已回退）。
 *    与 `admin.js` 的 postStoragePath() 历史形态、以及插件侧 get_permalink() 的 `%xx` 一致。
 *
 * ② 原文形态（`r2KeyFromPath`）：解码后的可读字符串。
 *    用于 **兼容读取**（R2 里可能还残留早期写下的中文原文对象）与 PHP s3 直传的实现细节。
 *
 * 用法约束（很重要）：
 *   - 两个函数只接受「URL 形态」输入：`url.pathname`、插件上传时的 storagePath、D1 里 `%xx` 形态的 slug。
 *   - **不要**用于「已经是存储 key」的输入（如 `list()` 返回的 `o.key`、KV 里记录的旧 key）——
 *     那些已经是存储形态，再处理会破坏含 `%` 的名字。
 */

/** URL 形态 → 解码后原文。非法输入（含穿越段、空）返回 null。 */
export function r2KeyFromPath(p) {
    let s = (p == null) ? '' : String(p);
    s = s.replace(/^\/+/, '');                       // R2 key 不带前导斜杠
    if (s === '') return null;

    let decoded;
    try {
        decoded = decodeURIComponent(s);              // 非法转义（如 %zz）会抛 URIError
    } catch (e) {
        decoded = s;                                  // 回退：保留原样（与 PHP rawurldecode 的语义一致）
    }

    // 防穿越：解码后不得出现 `.` / `..` / NUL 段（同时挡住 %2E%2E、%2F 组合绕过）
    const segs = decoded.split('/');
    for (let i = 0; i < segs.length; i++) {
        const seg = segs[i];
        if (seg === '.' || seg === '..') return null;
        if (seg.indexOf('\0') !== -1) return null;
    }
    return decoded;
}

/** URL 形态 → R2 存储 key（URL 形态：逐段 encodeURIComponent 后转小写）。非法输入返回 null。
 *  幂等：对已编码的输入重复调用结果不变。 */
export function r2KeyFromUrl(p) {
    const raw = r2KeyFromPath(p);                     // 复用：解码 + 防穿越
    if (!raw) return null;
    return raw
        .split('/')
        .map(function (seg) { return encodeURIComponent(seg).toLowerCase(); })
        .join('/');
}

/** R2 存储 key → URL 路径（逐段 percent-encoding，用于返回给客户端的 URL）。 */
export function r2UrlPath(key) {
    return String(key == null ? '' : key)
        .split('/')
        .map(function (seg) { return encodeURIComponent(seg); })
        .join('/');
}
