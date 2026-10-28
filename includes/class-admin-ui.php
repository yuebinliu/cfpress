<?php
/**
 * CFSE_Admin_UI -- 从 cf-static-export-pro.php 拆出（详见 docs/roadmap-current.md）。
 *
 * 拆分纪律「只搬不改」：方法体逐字节照搬（含缩进）。
 * 唯一改动：private function -> public function（原为类内自调用，现由主类同名转发方法调用）。
 *
 * @package CFSE
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('CFSE_Admin_UI')) {
    return;
}

class CFSE_Admin_UI {

    private static $instance = null;

    /** 与原主类同名常量（原类内写作 self::META_SITE，拆出后需要本类自带） */
    const META_SITE = 'cfse_site';

    /** 与原主类同名属性：PHP 8.2+ 不允许动态属性，显式声明 */
    private $reported_dir_failure = false;

    /** 托管目标配置弹窗的 HTML 片段（原主类属性；本类内读写，见 render_hosting_target_row/table） */
    private $target_modals = array();

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
    }

    /** 桥接：$this->config -> 主类配置 */
    public function __get($name) {
        $p = CF_Static_Export_Pro::get_instance();
        if ($name === 'config') {
            return $p->get_config();
        }
        // 其余属性：反射读主类（public/private 都能读），避免拆出去的新类「看不见」主类状态
        if (property_exists($p, $name)) {
            $r = new ReflectionProperty($p, $name);
            $r->setAccessible(true);
            return $r->getValue($p);
        }
        return null;
    }

    /**
     * 桥接：新类里调用未搬来的方法时转发
     *  1) 先查 CFSE_Transports（托管目标/传输相关已在那里）
     *  2) 再回主类（cfse_proxy 可访问 private）
     */
    public function __call($name, $args) {
        if (class_exists('CFSE_Transports')) {
            $t = CFSE_Transports::get_instance();
            if (method_exists($t, $name)) {
                return call_user_func_array(array($t, $name), $args);
            }
        }
        return CF_Static_Export_Pro::get_instance()->cfse_proxy($name, $args);
    }

    /** 桥接：日志转发主类 */
    public function log($message, $level = 'INFO') {
        return CF_Static_Export_Pro::get_instance()->log($message, $level);
    }


    // ============================================
    // 设置页面
    // ============================================
    public function settings_page() {
        wp_enqueue_media();   // 「选择 / 上传」按钮用 WP 媒体库
        $stats = $this->get_export_stats();
        $routes_exists = file_exists(CFSE_WORKER_DIR . 'routes.js');
        $templates_exists = file_exists(CFSE_WORKER_DIR . 'template-engine.js');
        ?>
        <div class="wrap cfse-settings">
            <h1>☁️ CF静态导出Pro <span class="version">v<?php echo esc_html(CFSE_VERSION); ?></span></h1>

            <?php if (isset($_GET['exported']) && isset($_GET['total'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>✅ 批量导出完成！成功导出 <strong><?php echo intval($_GET['exported']); ?></strong> / <?php echo intval($_GET['total']); ?> 篇文章</p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['routes_synced']) && $_GET['routes_synced']): ?>
                <div class="notice notice-success is-dismissible"><p>✅ 路由规则已同步到本地 <code>worker/src/routes.js</code></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['templates_synced']) && $_GET['templates_synced']): ?>
                <div class="notice notice-success is-dismissible"><p>✅ 模板已同步到本地 <code>worker/static/wp-content/themes/wp-custom/tpl.json</code>（需本地 <code>wrangler deploy</code> 生效）</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['deployed']) && $_GET['deployed']): ?>
                <div class="notice notice-success is-dismissible"><p>✅ Worker 部署成功！（含主题资源 assets）</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['assets_staged']) && $_GET['assets_staged']): ?>
                <div class="notice notice-success is-dismissible"><p>✅ 主题资源已复制到 worker/static，点「🚀 初始化部署」随 Worker 上传。</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['style_assets_synced'])): $__sas = (int) $_GET['style_assets_synced']; ?>
                <div class="notice notice-<?php echo $__sas > 0 ? 'success' : 'warning'; ?> is-dismissible"><p><?php echo $__sas > 0 ? '✅ 已从 R2 同步 ' . $__sas . ' 个风格资源到 worker/static/style/，本地 wrangler deploy 后走 assets 直出。' : '⚠️ 未同步到风格资源（可能尚未上传过，或获取清单失败，详见日志）。'; ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php" id="cfse-options">
                <div class="cfse-grid">
<div class="cfse-col">
                        <div class="cfse-card">
                            <h2 style="margin-top:0;">🎯 托管目标</h2>
                            <p class="description">先选<b>模板</b>（= 托管方，决定能力上限：Cloudflare 支持 R2/D1/渲染/清缓存，S3 与本地目录只支持写文件），
                                再在模板允许范围内勾<b>能力</b>。例如「只想全静态」就选 Cloudflare 模板、只勾「静态文件」——这是完全正常的配置，不会报警告。
                                没有目标具备「文章数据」能力时写库会被跳过（不报错）。<b>Cloudflare 平台凭证与自托管运行时</b>在任意 <code>cloudflare</code> 目标的「⚙️ 配置」里改。</p>
                            <?php $this->render_hosting_targets_table(); ?>
                            <p style="margin-top:8px;">
                                <button type="button" class="button" id="cfse-add-target">＋ 添加目标</button>
                                <button type="button" class="button" id="cfse-test-targets">🔌 测试连通性</button>
                                <span id="cfse-test-result" class="description" style="margin-left:8px;"></span>
                                <span class="description" style="margin-left:8px;">（测试用的是<b>已保存</b>的配置，改动后请先保存）</span>
                            </p>
                            <script>
                            (function(){
                              var body = document.getElementById('cfse-targets-body');
                              if (!body) return;
                              var idx = body.querySelectorAll('tr.cfse-target-row').length;
                              // 字段联动：按「模板」显隐字段组（B7②）
                              //   cloudflare ⇒ endpoint + auth_key
                              //   s3         ⇒ endpoint + s3 组（隐藏 auth_key）
                              //   local      ⇒ dir（隐藏 endpoint / auth_key / s3）
                              // 「⚙️ 配置」打开对应弹窗（弹窗互斥天然成立）
                              function bindDetail(tr){
                                var btn = tr.querySelector('.cfse-toggle-detail');
                                if (!btn) return;
                                btn.addEventListener('click', function(){
                                  var m = document.getElementById(btn.getAttribute('data-modal'));
                                  if (m) m.style.display = 'block';
                                });
                              }
                              // 关闭弹窗（按钮或点遮罩）
                              document.addEventListener('click', function(e){
                                var t = e.target;
                                if (t && t.classList && t.classList.contains('cfse-modal-close')) {
                                  var box = t.closest ? t.closest('.cfse-target-modal') : null;
                                  if (box) box.style.display = 'none';
                                  return;
                                }
                                if (t && t.classList && t.classList.contains('cfse-target-modal')) {
                                  t.style.display = 'none';
                                }
                              });
                              // 模板 → 支持的能力（与 PHP get_hosting_templates() 一致；只有 cloudflare 支持 d1/render/cache）
                              var TPL_CAPS = {
                                'cloudflare': ['r2', 'd1', 'render', 'cache'],
                                's3': ['r2'], 'local': ['r2'], 'github': ['r2'], 'git': ['r2'], 'gitlab': ['r2'],
                                'netlify': ['r2'], 'vercel': ['r2'], 'ftp': ['r2'], 'webdav': ['r2']
                              };
                              var TPL_BLOCKS = {
                                'cloudflare': '.cfse-f-cloudflare', 's3': '.cfse-f-s3', 'github': '.cfse-f-github',
                                'netlify': '.cfse-f-netlify', 'vercel': '.cfse-f-vercel', 'git': '.cfse-f-git',
                                'gitlab': '.cfse-f-gitlab', 'ftp': '.cfse-f-ftp', 'webdav': '.cfse-f-webdav'
                              };
                              function rowIdx(tr){
                                var el = tr.querySelector('input[name^="cf_hosting_targets["]');
                                var m = el ? String(el.name).match(/^cf_hosting_targets\[(\d+)\]/) : null;
                                return m ? m[1] : null;
                              }
                              // 模板块内字段启停：隐藏块若仍可提交，其旧值会以同名键后写覆盖
                              // 用户在可见块里的输入（PHP $_POST 同名键后写胜）⇒ 「保存不上」。
                              // disabled 字段不随表单提交 ⇒ 每个目标只有当前模板块的字段会提交。
                              function setBlockEnabled(block, on){
                                if (!block) return;
                                var fs = block.querySelectorAll('input,select,textarea');
                                for (var i = 0; i < fs.length; i++) fs[i].disabled = !on;
                              }
                              function applyTemplate(tr, v, modal){
                                // ① 弹窗里的「模板相关块」显隐 + 启停（弹窗在 #cfse-modals，不在 tr 内）
                                Object.keys(TPL_BLOCKS).forEach(function(k){
                                  var el = modal ? modal.querySelector(TPL_BLOCKS[k]) : null;
                                  if (el) { el.style.display = (v === k) ? '' : 'none'; setBlockEnabled(el, v === k); }
                                });
                                // ② endpoint / dir / Auth Key 的显示规则（这些字段都在弹窗里，必须从 modal 找）
                                var ep = modal ? modal.querySelector('.cfse-f-endpoint') : null;
                                var dir = modal ? modal.querySelector('.cfse-f-dir') : null;
                                var isLocal = (v === 'local'), isS3 = (v === 's3'), isGithub = (v === 'github');
                                if (ep) { ep.style.display = (isLocal || isGithub) ? 'none' : ''; ep.disabled = (isLocal || isGithub); }
                                if (dir) { dir.style.display = isLocal ? '' : 'none'; dir.disabled = !isLocal; }
                                var wk = modal ? modal.querySelector('.cfse-f-workerkey') : null;
                                if (wk) { wk.style.display = (v === 'cloudflare') ? '' : 'none'; setBlockEnabled(wk, v === 'cloudflare'); }
                                // ③ 能力按模板可选范围置灰 / 启用（这一步就是「选模型 ⇒ 能力跟着变」）
                                var idx = rowIdx(tr);
                                if (idx !== null) {
                                  var allow = TPL_CAPS[v] || ['r2'];
                                  ['r2', 'd1', 'render', 'cache'].forEach(function(ck){
                                    var box = tr.querySelector('input[name="cf_hosting_targets[' + idx + '][capabilities][' + ck + ']"]');
                                    if (!box) return;
                                    var ok = allow.indexOf(ck) !== -1;
                                    box.disabled = !ok;
                                    var lab = box.parentNode;
                                    if (lab) {
                                      lab.style.opacity = ok ? '' : '.45';
                                      if (ok) lab.removeAttribute('title'); else lab.setAttribute('title', '该模板不支持此能力');
                                    }
                                    if (!ok) box.checked = false;
                                  });
                                }
                              }
                              // 表格行与弹窗里的 名称/模板 双向同步（改一处另一处跟着变 ⇒ 提交值一致）
                              function bindSync(tr, modal){
                                var idx = rowIdx(tr);
                                if (idx === null) return;
                                var q = 'cf_hosting_targets[' + idx + ']';
                                var pairs = [
                                  [tr.querySelector('input[name="' + q + '[name]"]'), modal ? modal.querySelector('input[name="' + q + '[name]"]') : null],
                                  [tr.querySelector('select[name="' + q + '[template]"]'), modal ? modal.querySelector('select[name="' + q + '[template]"]') : null]
                                ];
                                pairs.forEach(function (p) {
                                  var a = p[0], b = p[1];
                                  if (!a || !b) return;
                                  function mirror(src, dst) {
                                    ['input', 'change'].forEach(function (ev) {
                                      src.addEventListener(ev, function () {
                                        if (dst.value !== src.value) dst.value = src.value;
                                        if (src.tagName === 'SELECT') syncRow(tr);
                                      });
                                    });
                                  }
                                  mirror(a, b); mirror(b, a);
                                });
                              }
                              function syncRow(tr){
                                var tpl = tr.querySelector('.cfse-target-template');
                                var v = tpl ? tpl.value : 'cloudflare';
                                var openBtn = tr.querySelector('.cfse-toggle-detail');
                                var modal = openBtn ? document.getElementById(openBtn.getAttribute('data-modal')) : null;
                                applyTemplate(tr, v, modal);
                              }
                              function bind(tr){
                                var tpl = tr.querySelector('.cfse-target-template');
                                if (tpl) tpl.addEventListener('change', function(){ syncRow(tr); });
                                var del = tr.querySelector('.cfse-del-target');
                                if (del) del.addEventListener('click', function(){
                                  // 关键：弹窗在 #cfse-modals 里、独立于表格行；不一起删掉的话，
                                  // 弹窗里的 cf_hosting_targets[N][...] 仍会随表单提交 ⇒ 目标「删不掉」。
                                  var ob = tr.querySelector('.cfse-toggle-detail');
                                  if (ob) {
                                    var mdl = document.getElementById(ob.getAttribute('data-modal'));
                                    if (mdl && mdl.parentNode) mdl.parentNode.removeChild(mdl);
                                  }
                                  tr.parentNode.removeChild(tr);
                                });
                                bindSync(tr, tr.querySelector('.cfse-toggle-detail') ? document.getElementById(tr.querySelector('.cfse-toggle-detail').getAttribute('data-modal')) : null);
                                syncRow(tr);
                              }
                              Array.prototype.forEach.call(body.querySelectorAll('tr.cfse-target-row'), function(tr){ bind(tr); bindDetail(tr); });
                              var addBtn = document.getElementById('cfse-add-target');
                              if (addBtn) addBtn.addEventListener('click', function(){
                                var tmp = document.createElement('tbody');
                                tmp.innerHTML = '<tr class="cfse-target-row">' +
                                  '<td><input type="checkbox" name="cf_hosting_targets[' + idx + '][enabled]" value="1" checked /></td>' +
                                  '<td><input type="text" name="cf_hosting_targets[' + idx + '][name]" style="width:100%;" placeholder="例如：备份站" /></td>' +
                                  '<td><select name="cf_hosting_targets[' + idx + '][template]" class="cfse-target-template" style="width:100%;"><option value="cloudflare">Cloudflare（Worker + R2 + D1）</option><option value="s3">S3 兼容对象存储</option><option value="local">本地目录</option><option value="github">GitHub Pages（git 推送）</option><option value="git">通用 git（Gitee/Gitea）</option><option value="gitlab">GitLab Pages</option><option value="netlify">Netlify</option><option value="vercel">Vercel</option><option value="ftp">FTP / FTPS</option><option value="webdav">WebDAV（坚果云等）</option></select></td>' +
                                  '<td class="cfse-sum"><div><b class="cfse-sum-dir">（保存后显示）</b></div><div style="font-size:11px;color:#646970;">目录与站点 id 将在保存后生成</div></td>' +
                                  '<td style="white-space:nowrap;font-size:11px;">' +
                                    '<label><input type="checkbox" name="cf_hosting_targets[' + idx + '][capabilities][r2]" value="1" checked /> 静态文件</label><br/>' +
                                    '<label><input type="checkbox" name="cf_hosting_targets[' + idx + '][capabilities][d1]" value="1" checked /> 文章数据</label><br/>' +
                                    '<label><input type="checkbox" name="cf_hosting_targets[' + idx + '][capabilities][render]" value="1" checked /> 渲染</label><br/>' +
                                    '<label><input type="checkbox" name="cf_hosting_targets[' + idx + '][capabilities][cache]" value="1" checked /> 清缓存</label>' +
                                  '</td>' +
                                  '<td style="text-align:center;"><input type="checkbox" name="cf_hosting_targets[' + idx + '][is_default]" value="1" /></td>' +
                                  '<td style="white-space:nowrap;"><button type="button" class="button cfse-toggle-detail" data-modal="cfse-modal-' + idx + '">⚙️ 配置</button> <button type="button" class="button cfse-del-target" title="删除该目标">✕</button></td>' +
                                '</tr>';
                                var modalHtml = '<div class="cfse-target-modal" id="cfse-modal-' + idx + '" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;font-size:13px;">' +
                                  '<div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);background:#fff;padding:18px 22px;font-size:13px;line-height:1.6;border-radius:6px;width:min(92vw,780px);max-height:86vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.3);">' +
                                  '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;"><b style="font-size:15px;">⚙️ 站点配置</b><button type="button" class="button cfse-modal-close">关闭</button></div>' +
                                  '<div style="padding-bottom:10px;border-bottom:1px solid #f0f0f1;margin-bottom:10px;">' +
                                    '<div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;">' +
                                      '<span style="color:#646970;"><code>（保存后生成）</code></span>' +
                                      '<label>名称 <input type="text" name="cf_hosting_targets[' + idx + '][name]" style="width:150px;" placeholder="例如：备份站" /></label>' +
                                      '<label>模板 <select name="cf_hosting_targets[' + idx + '][template]" class="cfse-target-template" style="width:auto;"><option value="cloudflare">Cloudflare（Worker + R2 + D1）</option><option value="s3">S3 兼容对象存储</option><option value="local">本地目录</option><option value="github">GitHub Pages（git 推送）</option><option value="git">通用 git（Gitee/Gitea）</option><option value="gitlab">GitLab Pages</option><option value="netlify">Netlify</option><option value="vercel">Vercel</option><option value="ftp">FTP / FTPS</option><option value="webdav">WebDAV（坚果云等）</option></select></label>' +
                                      '<input type="hidden" name="cf_hosting_targets[' + idx + '][id]" value="" />' +
                                    '</div>' +
                                    '<div style="margin-top:8px;">' +
                                      '<label>本地保存目录 <code>wp-content/cfse-static/</code><input type="text" name="cf_hosting_targets[' + idx + '][site_dir]" style="width:140px;" placeholder="留空 = 站点 id" /></label>' +
                                      '<span class="description" style="font-size:12px;">（英文/数字/下划线/连字符；保存时会自动创建目录）</span>' +
                                    '</div>' +
                                  '</div>' +
                                  '<div style="margin-top:8px;">' +
                                    '<label>站点地址（Worker 域名）<br/><input type="text" class="cfse-f-endpoint" name="cf_hosting_targets[' + idx + '][endpoint]" style="width:100%;" placeholder="https://你的站点" /></label>' +
                                    '<span class="description" style="font-size:12px;">Cloudflare 目标填 Worker 域名；保存时会同步为本站的「静态站点 URL」</span>' +
                                  '</div>' +
                                  '<input type="text" class="cfse-f-dir" name="cf_hosting_targets[' + idx + '][dir]" style="width:100%;margin-top:6px;" placeholder="留空 = 默认本地目录" />' +
                                    '<div class="cfse-f-s3" style="margin-top:8px;display:none;font-size:12px;">' +
                                    '<b>对象存储（S3 兼容）</b>' +
                                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">' +
                                      '<label>Bucket<br/><input type="text" name="cf_hosting_targets[' + idx + '][bucket]" style="width:100%;" /></label>' +
                                      '<label>Region（R2 填 auto）<br/><input type="text" name="cf_hosting_targets[' + idx + '][region]" style="width:100%;" value="auto" /></label>' +
                                      '<label>Access Key<br/><input type="text" name="cf_hosting_targets[' + idx + '][access_key]" style="width:100%;" /></label>' +
                                      '<label>Secret Key<br/><input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][secret_key]" style="width:100%;" /></label>' +
                                      '<label>公开访问地址 public_url<br/><input type="text" name="cf_hosting_targets[' + idx + '][public_url]" style="width:100%;" placeholder="https://cdn.example.com" /></label>' +
                                      '<label>寻址方式<br/><select name="cf_hosting_targets[' + idx + '][path_style]" style="width:100%;"><option value="auto">自动</option><option value="path">path-style</option><option value="virtual">virtual-host</option></select></label>' +
                                    '</div>' +
                                  '</div>' +
                                  '<div class="cfse-f-github" style="margin-top:8px;display:none;font-size:12px;">' +
                                  '<label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="cf_hosting_targets[' + idx + '][public_url]" style="width:100%;" /></label>' +
                                    '<b>GitHub Pages</b>' +
                                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">' +
                                      '<label style="grid-column:1 / -1;">仓库 owner/name<br/><input type="text" name="cf_hosting_targets[' + idx + '][repo]" style="width:100%;" placeholder="owner/repo" /></label>' +
                                      '<label style="grid-column:1 / -1;">Token（需 repo 权限）<br/><input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][token]" style="width:100%;" /></label>' +
                                      '<label>分支<br/><input type="text" name="cf_hosting_targets[' + idx + '][branch]" style="width:100%;" value="gh-pages" /></label>' +
                                    '</div>' +
                                  '</div>' +
                                  '<div class="cfse-f-netlify" style="margin-top:8px;display:none;font-size:12px;">' +
                                  '<label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="cf_hosting_targets[' + idx + '][public_url]" style="width:100%;" /></label>' +
                                    '<b>Netlify</b>' +
                                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">' +
                                      '<label>Site ID（或站点名）<br/><input type="text" name="cf_hosting_targets[' + idx + '][site]" style="width:100%;" /></label>' +
                                      '<label>Personal Access Token<br/><input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][token]" style="width:100%;" /></label>' +
                                    '</div>' +
                                  '</div>' +
                                  '<div class="cfse-f-vercel" style="margin-top:8px;display:none;font-size:12px;">' +
                                  '<label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="cf_hosting_targets[' + idx + '][public_url]" style="width:100%;" /></label>' +
                                    '<b>Vercel</b>' +
                                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">' +
                                      '<label>项目名 project<br/><input type="text" name="cf_hosting_targets[' + idx + '][project]" style="width:100%;" /></label>' +
                                      '<label>Token<br/><input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][token]" style="width:100%;" /></label>' +
                                    '</div>' +
                                  '</div>' +
                                  '<div class="cfse-f-git" style="margin-top:8px;display:none;font-size:12px;">' +
                                  '<label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="cf_hosting_targets[' + idx + '][public_url]" style="width:100%;" /></label>' +
                                    '<b>通用 git（GitHub API 兼容）</b>' +
                                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">' +
                                      '<label style="grid-column:1 / -1;">API base<br/><input type="text" name="cf_hosting_targets[' + idx + '][api_base]" style="width:100%;" value="https://api.github.com" /></label>' +
                                      '<label style="grid-column:1 / -1;">仓库 owner/name<br/><input type="text" name="cf_hosting_targets[' + idx + '][repo]" style="width:100%;" /></label>' +
                                      '<label>Token<br/><input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][token]" style="width:100%;" /></label>' +
                                      '<label>分支<br/><input type="text" name="cf_hosting_targets[' + idx + '][branch]" style="width:100%;" value="gh-pages" /></label>' +
                                    '</div>' +
                                  '</div>' +
                                  '<div class="cfse-f-gitlab" style="margin-top:8px;display:none;font-size:12px;">' +
                                  '<label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="cf_hosting_targets[' + idx + '][public_url]" style="width:100%;" /></label>' +
                                    '<b>GitLab Pages</b>' +
                                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">' +
                                      '<label style="grid-column:1 / -1;">项目（group/project 或数字 ID）<br/><input type="text" name="cf_hosting_targets[' + idx + '][project]" style="width:100%;" /></label>' +
                                      '<label>Token（api 权限）<br/><input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][token]" style="width:100%;" /></label>' +
                                      '<label>分支<br/><input type="text" name="cf_hosting_targets[' + idx + '][branch]" style="width:100%;" value="gh-pages" /></label>' +
                                    '</div>' +
                                  '</div>' +
                                  '<div class="cfse-f-ftp" style="margin-top:8px;display:none;font-size:12px;">' +
                                  '<label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="cf_hosting_targets[' + idx + '][public_url]" style="width:100%;" /></label>' +
                                    '<b>FTP / FTPS</b>' +
                                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">' +
                                      '<label>主机<br/><input type="text" name="cf_hosting_targets[' + idx + '][host]" style="width:100%;" /></label>' +
                                      '<label>端口 / FTPS<br/><input type="text" name="cf_hosting_targets[' + idx + '][port]" style="width:56px;" value="21" /> <label style="font-size:12px;"><input type="checkbox" name="cf_hosting_targets[' + idx + '][ftps]" value="1" /> FTPS</label></label>' +
                                      '<label>用户名<br/><input type="text" name="cf_hosting_targets[' + idx + '][user]" style="width:100%;" /></label>' +
                                      '<label>密码<br/><input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][pass]" style="width:100%;" /></label>' +
                                      '<label style="grid-column:1 / -1;">远端根目录<br/><input type="text" name="cf_hosting_targets[' + idx + '][dir]" style="width:100%;" placeholder="/wwwroot 或 /public_html" /></label>' +
                                    '</div>' +
                                    '<span class="description" style="font-size:11px;">增量覆盖：只上传、不删除远端多余文件</span>' +
                                  '</div>' +
                                  '<div class="cfse-f-webdav" style="margin-top:8px;display:none;font-size:12px;">' +
                                  '<label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="cf_hosting_targets[' + idx + '][public_url]" style="width:100%;" /></label>' +
                                    '<b>WebDAV</b>' +
                                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">' +
                                      '<label style="grid-column:1 / -1;">WebDAV 地址<br/><input type="text" name="cf_hosting_targets[' + idx + '][endpoint]" style="width:100%;" placeholder="https://dav.jianguoyun.com/dav/站点目录" /></label>' +
                                      '<label>用户名<br/><input type="text" name="cf_hosting_targets[' + idx + '][user]" style="width:100%;" /></label>' +
                                      '<label>密码（坚果云用应用密码）<br/><input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][pass]" style="width:100%;" /></label>' +
                                    '</div>' +
                                    '<span class="description" style="font-size:11px;">增量覆盖：只上传、不删除远端多余文件</span>' +
                                  '</div>' +
                                  '<div class="cfse-f-workerkey" style="margin-top:8px;font-size:12px;display:none;">' +
                                    '<label>Auth Key<br/><input type="text" class="cfse-f-key" name="cf_hosting_targets[' + idx + '][auth_key]" style="width:100%;" /></label>' +
                                    '<span class="description" style="font-size:12px;">Worker 的 X-Auth-Key —— 只有 cloudflare 模板用得到</span>' +
                                  '</div>' +
                                  '<div style="margin-top:8px;font-size:12px;">' +
                                    '<label>路径前缀<br/><input type="text" name="cf_hosting_targets[' + idx + '][path_prefix]" style="width:100%;" placeholder="留空 = 目标根目录" /></label>' +
                                  '</div>' +
                                  '</div></div>';
                                var host = document.getElementById('cfse-modals');
                                if (host) host.insertAdjacentHTML('beforeend', modalHtml);
                                var tr = tmp.firstElementChild;
                                body.appendChild(tr);
                                bind(tr);
                                bindDetail(tr);
                                syncRow(tr);   // 弹窗已建好，同步一次「模板相关块」的显隐
                                idx++;
                              });
                              var testBtn = document.getElementById('cfse-test-targets');
                              if (testBtn) testBtn.addEventListener('click', function(){
                                var out = document.getElementById('cfse-test-result');
                                out.textContent = '测试中…';
                                jQuery.post(ajaxurl, { action: 'cfse_test_targets', _ajax_nonce: '<?php echo esc_js(wp_create_nonce('cfse_test_targets')); ?>' }, function(r){
                                  if (!r || !r.success) { out.textContent = '测试失败'; return; }
                                  out.innerHTML = (r.data.results || []).map(function(x){
                                    return (x.ok ? '✅ ' : '❌ ') + x.name + '：' + x.detail;
                                  }).join('　|　');
                                }).fail(function(){ out.textContent = '请求失败'; });
                              });
                            })();
                            </script>

                        </div>
                <div class="cfse-card" style="margin-top:16px;">
                    <h2>🛠️ CMS 自托管设置</h2>
<div class="cfse-sec">
    <h4 style="margin:14px 0 4px;">📤 文章导出 / 拉取 <span class="dashicons dashicons-editor-help" title="点「导出文章」时按下面「导出参数」执行；与「其他自动设置」相互独立。"></span></h4>
    <div class="cfse-grp-body">
        <div class="cfse-grp-sub">导出参数：</div>
        <label><input type="checkbox" form="cfse-options" name="cf_manual_sync_d1" value="1" <?php checked(!empty($this->config['manual_sync_d1'])); ?> /> 导出到 D1</label>
        <label><input type="checkbox" form="cfse-options" name="cf_manual_gen_static" value="1" id="cf-manual-gen" <?php checked(!empty($this->config['manual_gen_static'])); ?> /> 生成静态文件</label>
        <label><input type="checkbox" form="cfse-options" name="cf_manual_pub_remote" value="1" id="cf-manual-pub" <?php checked(!empty($this->config['manual_pub_remote'])); ?> onchange="if(this.checked){var g=document.getElementById('cf-manual-gen'); if(g) g.checked=true;}" /> 上传到 R2（含「生成静态文件」）</label>
        <div class="cfse-grp-sub">拉取参数：</div>
        <label><input type="checkbox" name="cfse_pull_overwrite" value="1" form="cfse-pull-form" /> 拉取时覆盖同 ID 文章（以 Worker 版本为准）</label>
        <div class="cfse-actions" style="margin-top:10px;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_bulk_export')); ?>" style="display:inline;">
                <?php wp_nonce_field('cf_bulk_export'); ?>
                <button type="submit" class="button button-primary" onclick="return confirm('按「导出参数」的勾选导出全部已发布文章，确认继续？')">📤 导出文章</button>
            </form>
            <form id="cfse-pull-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_pull_worker')); ?>" style="display:inline;">
                <?php wp_nonce_field('cf_pull_worker'); ?>
                <button type="submit" class="button" onclick="return confirm('从 Worker 拉取「Worker 端诞生」的文章到 WordPress，确认继续？')">☁️ 从 Worker 拉取</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_clear_cache')); ?>" style="display:inline;">
                <?php wp_nonce_field('cf_clear_cache'); ?>
                <button type="submit" class="button" onclick="return confirm('重置导出标记？只清 WP 侧记账，不碰文件 / D1 / R2 / 缓存。')">♻️ 重置导出标记</button>
            </form>
        </div>
    </div>
</div>
<div class="cfse-sec">
    <h4 style="margin:14px 0 4px;">💬 评论同步设置 <span class="dashicons dashicons-editor-help" title="静态站评论：读者提交 → 存 D1（待审）→ WP 拉取落地 → 你在这里审核；前台展示与审核在 Worker 后台「💬 评论」页配置。"></span></h4>
    <div class="cfse-grp-body">
        <label><input type="checkbox" form="cfse-options" name="cf_comments_pull_cron" value="1" <?php checked(!empty($this->config['comments_pull_cron'])); ?> /> 每小时自动从 Worker 拉取评论</label>
        <label><input type="checkbox" form="cfse-options" name="cf_comments_sync" value="1" <?php checked(!empty($this->config['comments_sync'])); ?> /> 把 WP 评论自动同步到 D1</label>
        <p style="margin-top:8px;">
            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cfse_sync_comments'), 'cfse_sync_comments')); ?>">⬆️ 同步评论到 D1</a>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cfse_pull_comments'), 'cfse_pull_comments')); ?>">⬇️ 从 Worker 拉取评论</a>
        </p>
    </div>
</div>
<div class="cfse-sec">
    <h4 style="margin:14px 0 4px;">⚙️ 其他自动设置 <span class="dashicons dashicons-editor-help" title="控制「发布 / 编辑文章时」的自动行为，与「导出参数」相互独立。"></span></h4>
    <div class="cfse-grp-body">
        <div class="cfse-grp-sub">发布文章时：</div>
        <label><input type="checkbox" form="cfse-options" name="cf_sync_d1" value="1" <?php checked(!empty($this->config['sync_d1'])); ?> /> 自动同步到 D1</label>
        <label><input type="checkbox" form="cfse-options" name="cf_gen_static" value="1" id="cf-gen-static" <?php checked($this->config['gen_static']); ?> /> 生成静态文件</label>
        <label><input type="checkbox" form="cfse-options" name="cf_publish_remote" value="1" id="cf-pub-remote" <?php checked($this->config['publish_remote']); ?> onchange="if(this.checked){var g=document.getElementById('cf-gen-static'); if(g) g.checked=true;}" /> 上传到 R2（含「生成静态文件」）</label>
        <div class="cfse-grp-sub">编辑页面时：</div>
        <label><input type="checkbox" form="cfse-options" name="cf_worker_auto_check" value="1" <?php checked(!empty($this->config['worker_auto_check'])); ?> /> 自动比对 Worker 版本</label>
    </div>
</div>
<div class="cfse-sec">
    <h4 style="margin:14px 0 4px;">🔁 站点信息同步 <span class="dashicons dashicons-editor-help" title="只同步基础字段：站点名 / 描述 / URL / 存储结构 / 时区 / 默认作者。导航菜单、单页布局、文档库映射、logo、横幅、社交、评论 全部由 CMS 后台维护，永远不会被覆盖。"></span></h4>
    <div class="cfse-grp-body">
        <p class="description" style="margin-top:0;">WP 侧<b>不再自动</b>把站点信息写进 D1（改站点标题/描述、同步模板、初始化部署 都不会写）。需要把当前 WP 站点信息推给线上 CMS 时点下面的按钮 —— <b>合并写入</b>，不会动 CMS 后台独有的设置。</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_sync_site_info')); ?>" style="display:inline;">
            <?php wp_nonce_field('cf_sync_site_info'); ?>
            <button type="submit" class="button">🔄 同步站点设置到 D1</button>
        </form>
        <?php if (isset($_GET['site_info_synced'])): ?>
            <?php if ($_GET['site_info_synced'] === '1'): ?>
                <div class="notice notice-success is-dismissible" style="margin:10px 0 0;"><p>✅ 站点基础信息已合并写入 D1（导航菜单 / 单页布局 / 文档库映射 未被改动）。</p></div>
            <?php else: ?>
                <div class="notice notice-error is-dismissible" style="margin:10px 0 0;"><p>❌ 同步失败：没有可用的「D1 数据」能力目标，或请求被拒（详见插件日志）。</p></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<div class="cfse-sec">
    <h4 style="margin:14px 0 4px;">🔧 Worker端管理 <span class="dashicons dashicons-editor-help" title="仅「自托管 CMS」需要：创建 Worker 后台账号、同步风格资源。"></span></h4>
    <div class="cfse-grp-body">
<?php if (isset($_GET['local_generated'])): ?>
                            <div class="notice notice-success is-dismissible" style="margin:10px 0 0;"><p>✅ 生成到本地完成：成功 <?php echo intval($_GET['local_generated']); ?> / <?php echo isset($_GET['total']) ? intval($_GET['total']) : 0; ?> 篇。</p></div>
                        <?php elseif (isset($_GET['published'])): ?>
                            <div class="notice notice-success is-dismissible" style="margin:10px 0 0;"><p>🚀 上传到远端完成：成功 <?php echo intval($_GET['published']); ?> / <?php echo isset($_GET['total']) ? intval($_GET['total']) : 0; ?> 篇（本地无产物的篇会被跳过，见日志）。</p></div>
                        <?php endif; ?>
                        <?php if (isset($_GET['pulled'])): ?>
                            <div class="notice notice-success is-dismissible" style="margin:10px 0 0;"><p>☁️ 拉取完成：共 <?php echo isset($_GET['pull_total']) ? intval($_GET['pull_total']) : 0; ?> 篇，新增 <?php echo intval($_GET['pulled']); ?> 篇，对齐更新 <?php echo isset($_GET['pull_updated']) ? intval($_GET['pull_updated']) : 0; ?> 篇，跳过 <?php echo isset($_GET['pull_skipped']) ? intval($_GET['pull_skipped']) : 0; ?> 篇。</p></div>
                        <?php endif; ?>
        <div style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">
            <h4 style="margin-top:0;">👤 Worker 后台账号</h4>
            <p class="description">
                为 Worker 管理后台创建登录用户（PBKDF2-SHA256 存入 D1 <code>users</code> 表）。用户名默认取本站第一个管理员；
                密码仅本次提交时使用、不落 WordPress。可在 Worker 后台登录后修改。
            </p>
            <?php $seed_admin = get_users(array('role' => 'administrator', 'number' => 1)); $seed_u = !empty($seed_admin) ? $seed_admin[0] : null; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_seed_admin')); ?>" style="margin-top:6px;">
                <?php wp_nonce_field('cf_seed_admin'); ?>
                <label>用户名 <input type="text" name="cf_admin_username" value="<?php echo esc_attr($seed_u ? $seed_u->user_login : 'admin'); ?>" /></label>
                &nbsp;
                <label>初始密码 <input type="password" name="cf_admin_password" autocomplete="new-password" required /></label>
                &nbsp;
                <label>邮箱 <input type="email" name="cf_admin_email" value="<?php echo esc_attr($seed_u ? $seed_u->user_email : ''); ?>" /></label>
                <button type="submit" class="button button-primary">👤 写入 Worker 后台用户</button>
            </form>
            <?php if (isset($_GET['seeded']) && $_GET['seeded'] === '1'): ?>
                <div class="notice notice-success is-dismissible" style="margin:8px 0 0;"><p>✅ Worker 后台用户已写入 D1（需「🚀 初始化部署」后，Worker 的 /api/login 才能用新用户登录）。</p></div>
            <?php elseif (isset($_GET['seeded']) && $_GET['seeded'] === '0'): ?>
                <div class="notice notice-error is-dismissible" style="margin:8px 0 0;"><p>❌ 写入失败：用户名无效或密码少于 8 位。</p></div>
            <?php endif; ?>
        </div>

        <div style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">
                        <h4 style="margin-top:0;">🔧 Worker 代码管理</h4>
                        <p class="description">
                            本地 Worker 代码位于 <code><?php echo esc_html(CFSE_WORKER_DIR); ?></code>
                            <?php if ($routes_exists): ?>✅ routes.js<?php else: ?>❌ routes.js<?php endif; ?>
                            <?php if ($templates_exists): ?>✅ template-engine.js<?php else: ?>❌ template-engine.js<?php endif; ?>
                        </p>
                        <div style="margin: 10px 0; display: flex; gap: 10px; flex-wrap: wrap;">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_sync_style_assets')); ?>" style="display:inline;">
                                <?php wp_nonce_field('cf_sync_style_assets'); ?>
                                <button type="submit" class="button">🧩 同步风格资源</button>
                            </form>
                        </div>
                        <p class="description" style="font-size:12px;color:#999;">💡 模板在「🎨 模板编辑器」修改。<a href="<?php echo esc_url(admin_url('options-general.php?page=cf-template-editor')); ?>" class="button button-small" style="margin-left:6px;">🎨 模板编辑器</a></p>
                    </div>
    </div>
</div>

                </div>
                </div>

<div class="cfse-col">

<div class="cfse-card">
                    <h2>📊 状态面板</h2>
                    <div class="cfse-stats">
                        <div class="stat-item"><span class="stat-label">总文章</span><span class="stat-value"><?php echo esc_html($stats['total_posts']); ?></span></div>
                        <div class="stat-item"><span class="stat-label">已导出</span><span class="stat-value"><?php echo esc_html($stats['exported_posts']); ?></span></div>
                        <div class="stat-item"><span class="stat-label">待导出</span><span class="stat-value"><?php echo esc_html($stats['pending_posts']); ?></span></div>
                        <div class="stat-item"><span class="stat-label">最后导出</span><span class="stat-value"><?php echo esc_html($stats['last_export']); ?></span></div>
                    </div>
                    <div class="cfse-progress">
                        <div class="progress-bar" style="width: <?php echo esc_attr($stats['progress']); ?>%;"><?php echo esc_html(round($stats['progress'], 1)); ?>%</div>
                    </div>



                </div>

<div class="cfse-card" style="margin-top:16px;">
                        <h3 style="margin-top:0;">🌐 静态托管设置</h3>
                        <?php
                        $cfse_r2_names = array();
                        foreach ($this->get_hosting_targets() as $cfse_t) {
                            if (!empty($cfse_t['capabilities']['r2'])) {
                                $cfse_r2_names[] = (isset($cfse_t['name']) && $cfse_t['name'] !== '')
                                    ? $cfse_t['name']
                                    : (isset($cfse_t['id']) ? $cfse_t['id'] : '?');
                            }
                        }
                        ?>
                        <p class="description">
                            把一个<b>完整的静态产物目录</b>整体发布到具备「静态文件」能力的目标（首页/列表页/资源/图片一并发布）。
                        </p>
                        <p style="margin:8px 0 4px;">
                            <label style="font-size:12px;">产物目录：<input type="text" id="cfse-site-dir" class="regular-text" style="width:70%;" value="<?php echo esc_attr(CFSE_LOCAL_DIR); ?>" placeholder="留空 = 按所选站点的目录" />
                                <br/><span class="description" style="font-size:11px;">多站点下留空即可：会按「🎯 托管目标」里各站点的目录逐个发布；填了则只发布该目录（兼容旧用法）。</span></label>
                        </p>
                        <p class="description" style="font-size:11px;">
                            仅允许 <code><?php echo esc_html(CFSE_LOCAL_DIR); ?></code> 与 <code><?php echo esc_html(WP_CONTENT_DIR); ?></code> 下的子目录（<b>不含 plugins / themes / mu-plugins 源码目录</b>）。
                            </p>
                        <p style="margin:6px 0;font-size:12px;">
                            当前生效目标（具备静态文件能力）：<b><?php echo $cfse_r2_names ? esc_html(implode('、', $cfse_r2_names)) : '（无，请先在「🎯 托管目标」中配置）'; ?></b>
                        </p>
                        <div style="margin-top:10px;">
                            <button type="button" class="button button-primary" id="cfse-publish-site-btn">🚀 发布整站</button>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cfse_deploy_git')); ?>" style="display:inline;margin-left:8px;">
                                <?php wp_nonce_field('cfse_deploy_git'); ?>
                                <input type="hidden" name="cfse_git_dir" value="<?php echo esc_attr(CFSE_LOCAL_DIR); ?>" />
                                <button type="submit" class="button" onclick="return confirm('把该目录部署到所有启用中的「部署型」目标（GitHub Pages / GitLab / Netlify / Vercel / 通用 git / FTP / WebDAV）？仓库类目标不动默认分支。')">📦 部署到托管商</button>
                            </form>
                            <span id="cfse-publish-site-status" style="margin-left:10px;font-size:12px;color:#50575e;"></span>
                        </div>
                        <div id="cfse-publish-site-detail" style="margin-top:8px;font-size:12px;color:#50575e;"></div>
                        <script>
                        (function(){
                          var btn = document.getElementById('cfse-publish-site-btn');
                          if (!btn) return;
                          var nonce = '<?php echo esc_js(wp_create_nonce('cfse_publish_site')); ?>';
                          btn.addEventListener('click', function(){
                            var dirEl = document.getElementById('cfse-site-dir');
                            var st = document.getElementById('cfse-publish-site-status');
                            var dt = document.getElementById('cfse-publish-site-detail');
                            var dir = dirEl ? dirEl.value : '';
                            if (!dir) { alert('请填写产物目录'); return; }
                            if (!confirm('将把该目录整体发布到已启用的托管目标（同名文件会被覆盖），确认继续？')) return;
                            btn.disabled = true;
                            var offset = 0, okAll = 0, failAll = 0, bytesAll = 0, total = 0, first = true;
                            function step(){
                              var body = new URLSearchParams();
                              body.append('action', 'cfse_publish_site');
                              body.append('_ajax_nonce', nonce);
                              body.append('dir', dir);
                              body.append('offset', String(offset));
                              body.append('limit', '200');
                              if (first) body.append('reset', '1');
                              st.textContent = '发布中… ' + offset + (total ? (' / ' + total) : '');
                              fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                                .then(function(r){ return r.json(); })
                                .then(function(j){
                                  if (!j || !j.success) {
                                    btn.disabled = false;
                                    st.textContent = '❌ ' + ((j && j.data && j.data.error) ? j.data.error : '请求失败');
                                    return;
                                  }
                                  var d = j.data; first = false;
                                  total = d.total; okAll += d.ok; failAll += d.failed; bytesAll += d.bytes;
                                  offset = d.next_offset;
                                  dt.textContent = '目标目录：' + d.dir
                                    + ((d.errors && d.errors.length) ? (' ｜ 错误示例：' + d.errors.join(' ; ')) : '');
                                  if (d.done) {
                                    btn.disabled = false;
                                    st.textContent = '✅ 完成：' + okAll + ' 成功 / ' + failAll + ' 失败 / 共 ' + total
                                      + ' 个文件，' + Math.round(bytesAll / 1024) + ' KB';
                                  } else { step(); }
                                })
                                .catch(function(e){ btn.disabled = false; st.textContent = '❌ ' + e; });
                            }
                            step();
                          });
                        })();
                        </script>
                    </div>

                <!-- ☁️ CMS 自托管设置 -->
                <div class="cfse-card">
                    <h2>⚙️ 基础配置</h2>
                        <?php settings_fields('cf_static_export_pro'); ?>
                        <table class="form-table">
                            <tr><th><label for="cf_export_mode">导出模式</label></th>
                                <td><select id="cf_export_mode" name="cf_export_mode">
                                        <option value="template" <?php selected($this->config['export_mode'], 'template'); ?>>内部模板渲染</option>
                                        <option value="fetch" <?php selected($this->config['export_mode'], 'fetch'); ?>>前台抓取</option>
                                        <option value="auto" <?php selected($this->config['export_mode'], 'auto'); ?>>自动降级（推荐）</option>
                                    </select></td></tr>
                            <tr><th><label for="cf_batch_size">批量大小</label></th>
                                <td><input type="number" id="cf_batch_size" name="cf_batch_size" value="<?php echo esc_attr($this->config['batch_size']); ?>" style="width:100px" min="1" />
                                    <p class="description">每批导出文章数（影响导出耗时，不影响静态站分页）</p></td></tr>
                            <tr><th><label for="cf_posts_per_page">列表每页文章数</label></th>
                                <td><input type="number" id="cf_posts_per_page" name="cf_posts_per_page" value="<?php echo esc_attr($this->config['posts_per_page']); ?>" style="width:100px" min="1" />
                                    <p class="description">首页/分类/标签/归档每页显示多少篇，用于计算总页数与分页导航（默认与 WP「设置→阅读」一致 = 10）。保存后需点一次「🔄 同步路由规则」生效。</p></td></tr>
                            <tr><th><label for="cf_max_retries">上传重试次数</label></th>
                                <td><input type="number" id="cf_max_retries" name="cf_max_retries" value="<?php echo esc_attr($this->config['max_retries']); ?>" style="width:100px" min="1" /></td></tr>
                            <tr><th><label for="cf_export_concurrency">全量导出并发数</label></th>
                                <td><input type="number" id="cf_export_concurrency" name="cf_export_concurrency" value="<?php echo esc_attr($this->config['export_concurrency']); ?>" style="width:100px" min="1" max="8" />
                                    <p class="description">「🔄 全量导出」时并行进程数（1~8，默认 4）。并发越高全量越快，同时占用更多服务器资源；设为 1 即逐篇串行。R2/D1 写入为幂等覆盖，并发安全。</p></td></tr>
                            <tr><th>导出与渲染</th>
                                <td>
                                    <p class="description">这两个用法都有效（无论你发布到自托管 CMS 还是纯静态托管）。</p>
                                    <label><input type="checkbox" name="cf_clean_dynamic_scripts" value="1" <?php checked($this->config['clean_scripts']); ?> /> 移除动态脚本</label><br/>
                                    <label><input type="checkbox" name="cf_enable_logging" value="1" <?php checked($this->config['enable_logging']); ?> /> 启用日志</label><br/>
                                    <label><input type="checkbox" name="cf_enable_compression" value="1" <?php checked($this->config['enable_compression']); ?> /> 启用Gzip压缩</label><br/>
                                    <label><input type="checkbox" name="cf_debug_mode" value="1" <?php checked($this->config['debug_mode']); ?> /> 调试模式</label>
                                </td></tr>
                        </table>
                    </div>
<div class="cfse-card" style="margin-top:16px;">
                        <h4 style="margin-top:0;">📋 最近日志</h4>
                        <pre><?php echo esc_html($this->get_recent_logs(10)); ?></pre>
                    </div>
</div>
            </div>
                </div>

                        <div style="text-align:right;margin-top:18px;">
                            <button type="submit" form="cfse-options" class="button button-primary button-hero">保存配置</button>
                        </div>
                </form>

<style>
        .cfse-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(320px, 460px); gap: 20px; align-items: start; margin-top: 20px; align-items: start; }
        .cfse-grid > .cfse-col { min-width: 0; }
        .cfse-grid > .cfse-col > .cfse-card,
        .cfse-grid > .cfse-col > form > .cfse-card { min-width: 0; }
        .cfse-grid .cfse-card code, .cfse-grid .stat-value { overflow-wrap: anywhere; word-break: break-word; }
        .cfse-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .cfse-card h2 { margin-top: 0; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; }
        .cfse-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .stat-item { background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center; }
        .stat-label { display: block; color: #666; font-size: 12px; text-transform: uppercase; }
        .stat-value { display: block; font-size: 24px; font-weight: bold; margin-top: 5px; }
        .cfse-progress { margin: 15px 0; background: #f0f0f0; border-radius: 20px; overflow: hidden; }
        .progress-bar { background: linear-gradient(90deg, #4CAF50, #8BC34A); color: #fff; padding: 8px 15px; text-align: right; font-size: 12px; transition: width 0.3s; }
        .cfse-actions { display: flex; gap: 10px; margin: 15px 0; flex-wrap: wrap; }
        .cfse-sec { border-top: 1px solid #f0f0f0; padding: 2px 0; }
        .cfse-sec > h4 { font-weight: 600; }
        .cfse-sec label { display: block; margin: 5px 0; }
        .cfse-grp-sub { font-size: 13px; color: #646970; margin: 8px 0 2px; }
        .cfse-logs pre { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 6px; overflow: auto; max-height: 200px; font-size: 12px; line-height: 1.5; }
        .version { font-size: 14px; color: #666; font-weight: normal; }
        @media (max-width: 1200px) { .cfse-grid { grid-template-columns: 1fr; } }
        </style>
        <?php
    }


    public function enqueue_admin_assets() {
        wp_enqueue_style('cfse-admin', CFSE_PLUGIN_URL . 'assets/admin.css', array(), CFSE_VERSION);
        wp_enqueue_script('cfse-admin', CFSE_PLUGIN_URL . 'assets/admin.js', array('jquery'), CFSE_VERSION, true);
        wp_localize_script('cfse-admin', 'cfse_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cfse_ajax_nonce'),
        ));
    }


    public function register_settings() {
        $fields = array(
            'cf_account_id' => array('sanitize' => 'sanitize_text_field'),
            'cf_api_token' => array('sanitize' => 'sanitize_text_field'),
            'cf_r2_bucket' => array('sanitize' => 'sanitize_text_field'),
            'cf_d1_database' => array('sanitize' => 'sanitize_text_field'),
            'cf_site_url' => array('sanitize' => 'esc_url_raw'),
            'cf_export_mode' => array('sanitize' => 'sanitize_text_field', 'default' => 'auto'),
            'cf_cache_ttl' => array('sanitize' => 'intval', 'default' => 3600),
            'cf_clean_dynamic_scripts' => array('sanitize' => 'boolval', 'default' => true),
            'cf_batch_size' => array('sanitize' => 'intval', 'default' => 50),
            'cf_export_concurrency' => array('sanitize' => 'intval', 'default' => 4),
            'cf_max_retries' => array('sanitize' => 'intval', 'default' => 3),
            'cf_enable_logging' => array('sanitize' => 'boolval', 'default' => true),
            'cf_enable_compression' => array('sanitize' => 'boolval', 'default' => true),
            'cf_debug_mode' => array('sanitize' => 'boolval', 'default' => false),
            'cf_auth_key' => array('sanitize' => 'sanitize_text_field', 'default' => ''),
            // 列表每页文章数（默认取 WP「设置→阅读」的 posts_per_page，即 10）
            'cf_posts_per_page' => array('sanitize' => 'intval', 'default' => 10),
            'cf_worker_auto_check' => array('sanitize' => 'boolval', 'default' => true),
            'cf_sync_d1' => array('sanitize' => 'boolval', 'default' => 0),
            // 「📤 导出文章」的手动导出参数（与「发布文章时」的 cf_sync_d1/cf_gen_static/cf_publish_remote 分离）
            'cf_manual_sync_d1' => array('sanitize' => 'boolval', 'default' => 0),
            'cf_manual_gen_static' => array('sanitize' => 'boolval', 'default' => 0),
            'cf_manual_pub_remote' => array('sanitize' => 'boolval', 'default' => 0),
            // 多托管目标（JSON 字符串；sanitize 负责把表单数组转成 JSON）
            'cf_hosting_targets' => array('sanitize' => array($this, 'sanitize_hosting_targets'), 'default' => ''),
            'cf_gen_static' => array('sanitize' => 'boolval', 'default' => 0),
            'cf_publish_remote' => array('sanitize' => 'boolval', 'default' => 0),
            // 评论：off=隐藏评论区 / embed=注入第三方片段（Disqus·giscus·Utterances…）/ native=用骨架+自有数据（B 阶段）
            'cf_comments_mode' => array('sanitize' => 'sanitize_text_field', 'default' => 'off'),
            'cf_comments_embed' => array('sanitize' => array($this, 'sanitize_embed_code'), 'default' => ''),
            'cf_comments_sync' => array('sanitize' => 'boolval', 'default' => 1),
            // 是否接受站外（静态站前台）提交的评论：off=拒收（默认）| pending=收下待审 | auto=直接通过
            'cf_comments_accept' => array('sanitize' => 'sanitize_text_field', 'default' => 'off'),
            // 定时把 Worker 端评论拉回 WP（默认开；否则 WP 后台看不到读者评论）
            'cf_comments_pull_cron' => array('sanitize' => 'boolval', 'default' => 1),
            'cf_banner_url' => array('sanitize' => 'esc_url_raw', 'default' => ''),
            'cf_logo_url'   => array('sanitize' => 'esc_url_raw', 'default' => ''),
        );
        foreach ($fields as $key => $args) {
            // WP 的 register_setting() 第三个参数认的是 sanitize_callback —— 此前误写成 'sanitize'
            // （未知键被忽略），导致所有 cf_* 的 sanitize 从未生效：cf_hosting_targets 的表单数组
            // 被直接 maybe_serialize() 存成 PHP 序列化，而读取端只认 JSON 字符串 ⇒ 解析为空 ⇒
            // 目标列表退回兜底默认值（表现为「保存后消失」）。
            if (isset($args['sanitize'])) {
                $args['sanitize_callback'] = $args['sanitize'];
                unset($args['sanitize']);
            }
            register_setting('cf_static_export_pro', $key, $args);
        }
        // ⚠️ 2026-09 定稿：WP 侧**不再自动写 D1**。
        // 历史坑：旧实现用整行覆盖写 settings 的 site 行 ⇒ 抹掉过 CMS 后台独有配置
        //   （导航菜单 / 单页布局 / 文档库映射），也覆盖过 cache 行（列表每页数 / 批量任务参数）。
        // 现在：站点基础信息只在设置页点「🔄 同步站点设置到 D1」时**合并写入**；
        //       缓存策略（TTL / feed 条数 / 发布后清缓存）完全由 CMS 后台维护，WP 侧不再读写。
    }


    public function add_admin_menu() {
        $hook = add_options_page(
            'CF静态导出Pro',
            'CF静态导出',
            'manage_options',
            'cf-static-export-pro',
            array($this, 'settings_page')
        );
        add_action("load-{$hook}", array($this, 'enqueue_admin_assets'));
    }


    /** 单行（字段名 cf_hosting_targets[N][xxx]，与 sanitize 对应） */
    public function render_hosting_target_row($i, $t, $warns = array()) {
        $templates = $this->get_hosting_templates();
        $tplId = isset($t['template']) ? $t['template'] : (isset($t['type']) ? $t['type'] : 'cloudflare');
        if (!isset($templates[$tplId])) $tplId = 'cloudflare';
        $tpl = $templates[$tplId];
        $caps = isset($t['capabilities']) && is_array($t['capabilities']) ? $t['capabilities'] : $tpl['defaults']['capabilities'];
        $intent = isset($t['intent']) && is_array($t['intent']) ? $t['intent'] : $tpl['defaults']['intent'];
        $n = 'cf_hosting_targets[' . (int) $i . ']';
        ?>
        <tr class="cfse-target-row">
            <td><input type="checkbox" name="<?php echo esc_attr($n); ?>[enabled]" value="1" <?php checked(!empty($t['enabled'])); ?> /></td>
            <td>
                <input type="text" name="<?php echo esc_attr($n); ?>[name]" value="<?php echo esc_attr(isset($t['name']) ? $t['name'] : ''); ?>" style="width:100%;" placeholder="例如：主站" />
            </td>
            <td>
                <select name="<?php echo esc_attr($n); ?>[template]" class="cfse-target-template" style="width:100%;">
                    <?php foreach ($templates as $tid => $tp) { ?>
                        <option value="<?php echo esc_attr($tid); ?>" <?php selected($tplId, $tid); ?>><?php echo esc_html($tp['name']); ?></option>
                    <?php } ?>
                </select>
            </td>
            <td class="cfse-sum">
                <?php
                $sumDir = $this->site_dir_of($t);
                $sumMod = 'cfse-static/' . $sumDir . '/';
                $sumEnd = isset($t['endpoint']) && $t['endpoint'] !== '' ? $t['endpoint'] : (isset($t['dir']) && $t['dir'] !== '' ? $t['dir'] : '');
                ?>
                <div><b><?php echo esc_html($sumDir); ?></b></div>
                <div style="font-size:12px;color:#646970;overflow-wrap:anywhere;">产物 <code><?php echo esc_html($sumMod); ?></code><?php if ($sumEnd !== '') { ?><br/><?php echo esc_html($sumEnd); ?><?php } ?></div>
            </td>
            <td style="white-space:nowrap;font-size:11px;">
                <?php foreach (array('r2' => '静态文件', 'd1' => '文章数据', 'render' => '渲染', 'cache' => '清缓存') as $ck => $clabel) { ?>
                    <label<?php if (empty($tpl['capabilities'][$ck])) echo ' style="opacity:.45;" title="该模板不支持此能力"'; ?>>
                        <input type="checkbox" name="<?php echo esc_attr($n); ?>[capabilities][<?php echo esc_attr($ck); ?>]" value="1" <?php checked(!empty($caps[$ck])); ?><?php disabled(empty($tpl['capabilities'][$ck])); ?> /> <?php echo esc_html($clabel); ?>
                    </label><br/>
                <?php } ?>
            </td>
            <td style="text-align:center;"><input type="checkbox" name="<?php echo esc_attr($n); ?>[is_default]" value="1" <?php checked(!empty($t['is_default'])); ?> /></td>
            <td style="white-space:nowrap;">
                <button type="button" class="button cfse-toggle-detail" data-modal="cfse-modal-<?php echo (int) $i; ?>">⚙️ 配置</button>
                <button type="button" class="button cfse-del-target" title="删除该目标">✕</button>
            </td>
        </tr>
        <?php ob_start(); ?>
                <div style="padding-bottom:10px;border-bottom:1px solid #f0f0f1;margin-bottom:10px;">
                    <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;">
                    <span style="font-size:12px;color:#646970;"><code><?php echo esc_html(isset($t['id']) ? $t['id'] : ''); ?></code></span>
                    <label style="font-size:12px;">名称 <input type="text" name="<?php echo esc_attr($n); ?>[name]" value="<?php echo esc_attr(isset($t['name']) ? $t['name'] : ''); ?>" style="width:150px;" /></label>
                    <label style="font-size:12px;">模板 <select name="<?php echo esc_attr($n); ?>[template]" class="cfse-target-template" style="width:auto;">
                        <?php foreach ($templates as $tid => $tp) { ?>
                            <option value="<?php echo esc_attr($tid); ?>" <?php selected($tplId, $tid); ?>><?php echo esc_html($tp['name']); ?></option>
                        <?php } ?>
                    </select></label>
                    <input type="hidden" name="<?php echo esc_attr($n); ?>[id]" value="<?php echo esc_attr(isset($t['id']) ? $t['id'] : ''); ?>" />
                    </div>
                    <div style="margin-top:8px;">
                        <label>本地保存目录 <code>wp-content/cfse-static/</code><input type="text" name="<?php echo esc_attr($n); ?>[site_dir]" value="<?php echo esc_attr(isset($t['site_dir']) ? $t['site_dir'] : ''); ?>" style="width:140px;" placeholder="留空 = 站点 id" /></label>
                        <span class="description" style="font-size:12px;">（英文/数字/下划线/连字符；保存时会自动创建该目录。改名只影响今后的产物，已生成的文件不会跟着移动）</span>
                    </div>
                </div>
                <div style="margin-top:8px;">
                    <label>站点地址（Worker 域名）<br/><input type="text" class="cfse-f-endpoint" name="<?php echo esc_attr($n); ?>[endpoint]" value="<?php echo esc_attr(isset($t['endpoint']) ? $t['endpoint'] : ''); ?>" style="width:100%;" placeholder="https://你的站点" /></label>
                    <span class="description" style="font-size:12px;">Cloudflare 目标填 Worker 域名；保存时会同步为本站的「静态站点 URL」</span>
                </div>
                <?php $this->render_cf_platform_fields($this->is_first_cloudflare_target($t)); ?>
                <div class="cfse-f-s3" style="margin-top:8px;display:none;font-size:12px;">
                    <b>对象存储（S3 兼容）</b>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">
                        <label>Bucket<br/><input type="text" name="<?php echo esc_attr($n); ?>[bucket]" value="<?php echo esc_attr(isset($t['bucket']) ? $t['bucket'] : ''); ?>" style="width:100%;" /></label>
                        <label>Region（R2 填 auto）<br/><input type="text" name="<?php echo esc_attr($n); ?>[region]" value="<?php echo esc_attr(isset($t['region']) ? $t['region'] : 'auto'); ?>" style="width:100%;" /></label>
                        <label>Access Key<br/><input type="text" name="<?php echo esc_attr($n); ?>[access_key]" value="<?php echo esc_attr(isset($t['access_key']) ? $t['access_key'] : ''); ?>" style="width:100%;" /></label>
                        <label>Secret Key<br/><input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[secret_key]" value="<?php echo esc_attr(isset($t['secret_key']) ? $t['secret_key'] : ''); ?>" style="width:100%;" /></label>
                        <label>公开访问地址 public_url<br/><input type="text" name="<?php echo esc_attr($n); ?>[public_url]" value="<?php echo esc_attr(isset($t['public_url']) ? $t['public_url'] : ''); ?>" style="width:100%;" placeholder="https://cdn.example.com" /></label>
                        <label>寻址方式<br/><select name="<?php echo esc_attr($n); ?>[path_style]" style="width:100%;"><option value="auto" <?php selected(isset($t['path_style']) ? $t['path_style'] : 'auto', 'auto'); ?>>自动</option><option value="path" <?php selected(isset($t['path_style']) ? $t['path_style'] : 'auto', 'path'); ?>>path-style</option><option value="virtual" <?php selected(isset($t['path_style']) ? $t['path_style'] : 'auto', 'virtual'); ?>>virtual-host</option></select></label>
                    </div>
                </div>
                <div class="cfse-f-github" style="margin-top:8px;display:none;font-size:12px;">
                    <b>GitHub Pages</b>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">
                        <label style="grid-column:1 / -1;">仓库 owner/name<br/><input type="text" name="<?php echo esc_attr($n); ?>[repo]" value="<?php echo esc_attr(isset($t['repo']) ? $t['repo'] : ''); ?>" style="width:100%;" placeholder="owner/repo" /></label>
                        <label style="grid-column:1 / -1;">Token（需 repo 权限）<br/><input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[token]" value="<?php echo esc_attr(isset($t['token']) ? $t['token'] : ''); ?>" style="width:100%;" /></label>
                        <label>分支<br/><input type="text" name="<?php echo esc_attr($n); ?>[branch]" value="<?php echo esc_attr(isset($t['branch']) ? $t['branch'] : 'gh-pages'); ?>" style="width:100%;" /></label>
                        <label>&nbsp;<br/><span class="description" style="font-size:11px;">部署时创建/更新该分支（不动默认分支）</span></label>
                    </div>
                        <label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="<?php echo esc_attr($n); ?>[public_url]" value="<?php echo esc_attr(isset($t['public_url']) ? $t['public_url'] : ''); ?>" style="width:100%;" placeholder="https://你的站点地址" /></label>
                </div>
                <div class="cfse-f-netlify" style="margin-top:8px;display:none;font-size:12px;">
                    <b>Netlify</b>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">
                        <label>Site ID（或站点名）<br/><input type="text" name="<?php echo esc_attr($n); ?>[site]" value="<?php echo esc_attr(isset($t['site']) ? $t['site'] : ''); ?>" style="width:100%;" /></label>
                        <label>Personal Access Token<br/><input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[token]" value="<?php echo esc_attr(isset($t['token']) ? $t['token'] : ''); ?>" style="width:100%;" /></label>
                    </div>
                        <label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="<?php echo esc_attr($n); ?>[public_url]" value="<?php echo esc_attr(isset($t['public_url']) ? $t['public_url'] : ''); ?>" style="width:100%;" placeholder="https://你的站点地址" /></label>
                </div>
                <div class="cfse-f-vercel" style="margin-top:8px;display:none;font-size:12px;">
                    <b>Vercel</b>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">
                        <label>项目名 project<br/><input type="text" name="<?php echo esc_attr($n); ?>[project]" value="<?php echo esc_attr(isset($t['project']) ? $t['project'] : ''); ?>" style="width:100%;" /></label>
                        <label>Token<br/><input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[token]" value="<?php echo esc_attr(isset($t['token']) ? $t['token'] : ''); ?>" style="width:100%;" /></label>
                    </div>
                        <label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="<?php echo esc_attr($n); ?>[public_url]" value="<?php echo esc_attr(isset($t['public_url']) ? $t['public_url'] : ''); ?>" style="width:100%;" placeholder="https://你的站点地址" /></label>
                </div>
                <div class="cfse-f-git" style="margin-top:8px;display:none;font-size:12px;">
                    <b>通用 git（GitHub API 兼容）</b>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">
                        <label style="grid-column:1 / -1;">API base<br/><input type="text" name="<?php echo esc_attr($n); ?>[api_base]" value="<?php echo esc_attr(isset($t['api_base']) ? $t['api_base'] : 'https://api.github.com'); ?>" style="width:100%;" /></label>
                        <label style="grid-column:1 / -1;">仓库 owner/name<br/><input type="text" name="<?php echo esc_attr($n); ?>[repo]" value="<?php echo esc_attr(isset($t['repo']) ? $t['repo'] : ''); ?>" style="width:100%;" /></label>
                        <label>Token<br/><input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[token]" value="<?php echo esc_attr(isset($t['token']) ? $t['token'] : ''); ?>" style="width:100%;" /></label>
                        <label>分支<br/><input type="text" name="<?php echo esc_attr($n); ?>[branch]" value="<?php echo esc_attr(isset($t['branch']) ? $t['branch'] : 'gh-pages'); ?>" style="width:100%;" /></label>
                    </div>
                        <label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="<?php echo esc_attr($n); ?>[public_url]" value="<?php echo esc_attr(isset($t['public_url']) ? $t['public_url'] : ''); ?>" style="width:100%;" placeholder="https://你的站点地址" /></label>
                </div>
                <div class="cfse-f-gitlab" style="margin-top:8px;display:none;font-size:12px;">
                    <b>GitLab Pages</b>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">
                        <label style="grid-column:1 / -1;">项目（group/project 或数字 ID）<br/><input type="text" name="<?php echo esc_attr($n); ?>[project]" value="<?php echo esc_attr(isset($t['project']) ? $t['project'] : ''); ?>" style="width:100%;" /></label>
                        <label>Token（api 权限）<br/><input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[token]" value="<?php echo esc_attr(isset($t['token']) ? $t['token'] : ''); ?>" style="width:100%;" /></label>
                        <label>分支<br/><input type="text" name="<?php echo esc_attr($n); ?>[branch]" value="<?php echo esc_attr(isset($t['branch']) ? $t['branch'] : 'gh-pages'); ?>" style="width:100%;" /></label>
                    </div>
                        <label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="<?php echo esc_attr($n); ?>[public_url]" value="<?php echo esc_attr(isset($t['public_url']) ? $t['public_url'] : ''); ?>" style="width:100%;" placeholder="https://你的站点地址" /></label>
                </div>
                <div class="cfse-f-ftp" style="margin-top:8px;display:none;font-size:12px;">
                    <b>FTP / FTPS</b>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">
                        <label>主机<br/><input type="text" name="<?php echo esc_attr($n); ?>[host]" value="<?php echo esc_attr(isset($t['host']) ? $t['host'] : ''); ?>" style="width:100%;" /></label>
                        <label>端口 / FTPS<br/><input type="text" name="<?php echo esc_attr($n); ?>[port]" value="<?php echo esc_attr(isset($t['port']) ? $t['port'] : 21); ?>" style="width:56px;" /> <label style="font-size:12px;"><input type="checkbox" name="<?php echo esc_attr($n); ?>[ftps]" value="1" <?php checked(!empty($t['ftps'])); ?> /> FTPS</label></label>
                        <label>用户名<br/><input type="text" name="<?php echo esc_attr($n); ?>[user]" value="<?php echo esc_attr(isset($t['user']) ? $t['user'] : ''); ?>" style="width:100%;" /></label>
                        <label>密码<br/><input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[pass]" value="<?php echo esc_attr(isset($t['pass']) ? $t['pass'] : ''); ?>" style="width:100%;" /></label>
                        <label style="grid-column:1 / -1;">远端根目录<br/><input type="text" name="<?php echo esc_attr($n); ?>[dir]" value="<?php echo esc_attr(isset($t['dir']) ? $t['dir'] : ''); ?>" style="width:100%;" placeholder="/wwwroot 或 /public_html" /></label>
                    </div>
                    <span class="description" style="font-size:11px;">增量覆盖：只上传、不删除远端多余文件</span>
                        <label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="<?php echo esc_attr($n); ?>[public_url]" value="<?php echo esc_attr(isset($t['public_url']) ? $t['public_url'] : ''); ?>" style="width:100%;" placeholder="https://你的站点地址" /></label>
                </div>
                <div class="cfse-f-webdav" style="margin-top:8px;display:none;font-size:12px;">
                    <b>WebDAV</b>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">
                        <label style="grid-column:1 / -1;">WebDAV 地址<br/><input type="text" name="<?php echo esc_attr($n); ?>[endpoint]" value="<?php echo esc_attr(isset($t['endpoint']) ? $t['endpoint'] : ''); ?>" style="width:100%;" placeholder="https://dav.jianguoyun.com/dav/站点目录" /></label>
                        <label>用户名<br/><input type="text" name="<?php echo esc_attr($n); ?>[user]" value="<?php echo esc_attr(isset($t['user']) ? $t['user'] : ''); ?>" style="width:100%;" /></label>
                        <label>密码（坚果云用应用密码）<br/><input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[pass]" value="<?php echo esc_attr(isset($t['pass']) ? $t['pass'] : ''); ?>" style="width:100%;" /></label>
                    </div>
                    <span class="description" style="font-size:11px;">增量覆盖：只上传、不删除远端多余文件</span>
                        <label style="grid-column:1 / -1;">公开访问地址 public_url<br/><input type="text" name="<?php echo esc_attr($n); ?>[public_url]" value="<?php echo esc_attr(isset($t['public_url']) ? $t['public_url'] : ''); ?>" style="width:100%;" placeholder="https://你的站点地址" /></label>
                </div>
                <div class="cfse-f-workerkey" style="margin-top:8px;font-size:12px;display:none;">
                    <label>Auth Key<br/><input type="text" class="cfse-f-key" name="<?php echo esc_attr($n); ?>[auth_key]" value="<?php echo esc_attr(isset($t['auth_key']) ? $t['auth_key'] : ''); ?>" style="width:100%;" /></label>
                    <span class="description" style="font-size:12px;">Worker 的 X-Auth-Key（与 Worker 的 AUTH_KEY_SECRET 相同）—— 只有 cloudflare 模板用得到</span>
                </div>
                <div style="margin-top:8px;font-size:12px;">
                    <label>路径前缀<br/><input type="text" name="<?php echo esc_attr($n); ?>[path_prefix]" value="<?php echo esc_attr(isset($t['path_prefix']) ? $t['path_prefix'] : ''); ?>" style="width:100%;" placeholder="留空 = 目标根目录" /></label>
                    <span class="description" style="font-size:12px;">产物写到该目标的哪个子目录（对象存储=对象名前缀；git 型=仓库子目录，且部署只清理该前缀之下）。所有模板都适用</span>
                </div>
                <div style="text-align:right;margin-top:12px;">
                <button type="submit" class="button button-primary">保存</button>
            </div>
        <?php $this->target_modals[$i] = ob_get_clean(); ?>
        <?php if (!empty($warns)) { ?>
        <tr class="cfse-row-warn"><td colspan="7" style="background:#fff8e5;border-left:3px solid #dba617;padding:8px 12px;">
            <?php foreach ($warns as $w) { ?>
                <div style="margin:2px 0;">⚠ <?php echo esc_html($w['message']); ?>
                <?php if (!empty($w['fix']) && is_array($w['fix'])) { ?>
                    <button type="button" class="button button-small cfse-fix-btn" data-fix="<?php echo esc_attr(implode(',', $w['fix'])); ?>" style="margin-left:6px;">以模板为准修正 type</button>
                <?php } ?></div>
            <?php } ?>
        </td></tr>
        <?php } ?>
        <?php
    }


    public function render_cf_platform_fields($render = true) {
        if (!$render) {
            // 平台凭证 / 自托管运行时 / 缓存策略都是「全局 option」，只在一个目标弹窗里渲染一次，
            // 否则页面上会出现多份同名 input，提交时 PHP 只取最后一个（其它目标的旧值）⇒ 本地改了存不上。
            ?>
            <div style="margin-top:10px;font-size:12px;color:#646970;">
                平台凭证与缓存策略是<b>全局设置</b>，只在站点列表中的<b>第一个 Cloudflare 目标</b>（或默认目标）的配置里填写一次，所有目标共用。
            </div>
            <?php
            return;
        }
        $c = $this->config;
        ?>
        <div class="cfse-f-cloudflare" style="margin-top:10px;display:none;font-size:12px;">
            <b>☁️ Cloudflare 平台</b>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:4px;">
                <label>Account ID<br/><input type="text" name="cf_account_id" value="<?php echo esc_attr($c['account_id']); ?>" style="width:100%;" /></label>
                <label>API Token<br/><input type="password" autocomplete="new-password" spellcheck="false" name="cf_api_token" value="<?php echo esc_attr($c['api_token']); ?>" style="width:100%;" placeholder="D1:Edit / R2:Edit / Account:Read" /></label>
                <label>R2 Bucket<br/><input type="text" name="cf_r2_bucket" value="<?php echo esc_attr($c['r2_bucket']); ?>" style="width:100%;" /></label>
                <label>D1 Database ID<br/><input type="text" name="cf_d1_database" value="<?php echo esc_attr($c['d1_database']); ?>" style="width:100%;" /></label>
                <input type="hidden" name="cf_site_url" value="<?php echo esc_attr($c['site_url']); ?>" />
                <input type="hidden" name="cf_auth_key" value="<?php echo esc_attr($c['auth_key']); ?>" />
                <span class="description" style="grid-column:1 / -1;font-size:12px;">站点地址取自上面的「站点地址（Worker 域名）」，保存时自动同步为本站的静态站点 URL。Auth Key 请填在下面的「Auth Key」里（每个站点各自独立）。</span>
            </div>
            <?php /* 缓存策略（TTL / feed 条数）已移至 CMS 后台维护：D1 settings k='cache' */ ?>
        </div>
        <?php
    }


    /** 设置页：托管目标表格（已有目标逐行渲染；新增行由页面内 JS 生成） */
    public function render_hosting_targets_table() {
        // 渲染必须包含「未启用」的目标：否则取消勾选后它就从界面上消失，再保存即被删除。
        $targets = $this->get_hosting_targets(true);
        // 冷路径校验（§12）：行内警告 + 集合级提示
        $vr = $this->validate_hosting_targets(false);
        $byId = array(); $global = array();
        foreach ($vr['warnings'] as $w) {
            if ($w['id'] === '(全部目标)') $global[] = $w; else $byId[$w['id']][] = $w;
        }
        ?>
        <div style="overflow-x:auto;">
        <table class="widefat striped" id="cfse-targets" style="max-width:100%;font-size:12.5px;line-height:1.5;">
            <thead><tr>
                <th style="width:46px;">启用</th>
                <th style="width:140px;">名称</th>
                <th style="width:150px;">模板</th>
                <th>站点 / 目录</th>
                <th style="width:160px;">能力</th>
                <th style="width:56px;">默认</th>
                <th style="width:120px;">操作</th>
            </tr></thead>
            <tbody id="cfse-targets-body">
            <?php foreach ($targets as $i => $t) {
                $tid = isset($t['id']) ? $t['id'] : '';
                $this->render_hosting_target_row($i, $t, isset($byId[$tid]) ? $byId[$tid] : array());
            } ?>
            </tbody>
        </table>
        </div>
        <div id="cfse-modals">
        <?php foreach ($this->target_modals as $mi => $mhtml) { ?>
            <div class="cfse-target-modal" id="cfse-modal-<?php echo (int) $mi; ?>" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;font-size:13px;">
                <div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);background:#fff;padding:18px 22px;font-size:13px;line-height:1.6;border-radius:6px;width:min(92vw,780px);max-height:86vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.3);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <b style="font-size:14px;">⚙️ 站点配置</b>
                        <button type="button" class="button cfse-modal-close">关闭</button>
                    </div>
                    <?php echo $mhtml; ?>
                </div>
            </div>
        <?php } ?>
        </div>
        <script>
        </script>
        <?php if (!empty($global)) { ?>
            <div style="margin-top:8px;background:#fff8e5;border-left:3px solid #dba617;padding:8px 12px;">
                <?php foreach ($global as $w) { ?>
                    <div style="margin:2px 0;">⚠ <?php echo esc_html($w['message']); ?></div>
                <?php } ?>
            </div>
        <?php }
    }


    // ============================================
    // 站点（= 托管目标）辅助
    // ============================================

    /** 目标的目录名（site_dir 优先，缺省回退到 id） */
    public function site_dir_of($target) {
        $d = isset($target['site_dir']) ? preg_replace('/[^0-9a-zA-Z_-]/', '', (string) $target['site_dir']) : '';
        if ($d !== '') return $d;
        return isset($target['id']) ? (string) $target['id'] : 'default';
    }


    public function get_recent_logs($lines = 10) {
        if (!file_exists(CFSE_LOG_FILE)) return '暂无日志';
        $logs = @file(CFSE_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($logs === false) return '无法读取日志文件';
        $recent = array_slice($logs, -$lines);
        return implode("\n", $recent);
    }


    // ============================================
    // 统计和日志
    // ============================================
    public function get_export_stats() {
        $post_counts = wp_count_posts('post');
        $total_posts = isset($post_counts->publish) ? (int) $post_counts->publish : 0;
        $exported = (int) get_option('cf_exported_count', 0);
        $last_export = get_option('cf_last_export_time', '从未');
        return array(
            'total_posts' => $total_posts,
            'exported_posts' => min($exported, $total_posts),
            'pending_posts' => max(0, $total_posts - $exported),
            'last_export' => $last_export === '从未' ? '从未' : human_time_diff(strtotime($last_export)) . '前',
            'progress' => $total_posts > 0 ? ($exported / $total_posts * 100) : 0,
        );
    }


    /** Cloudflare 目标「⚙️ 配置」弹窗里的平台与运行时块（字段是全局 option：平台级的一份配置） */
    /** 是否是「第一个 Cloudflare 目标」（全局字段只在这里渲染一次）；带缓存，渲染一页只算一次 */
    public function is_first_cloudflare_target($t) {
        static $firstId = null;
        if ($firstId === null) {
            $firstId = '';
            foreach ($this->get_hosting_targets() as $x) {
                $tpl = isset($x['template']) ? $x['template'] : (isset($x['type']) && $x['type'] === 'cfse-worker' ? 'cloudflare' : '');
                if ($tpl === 'cloudflare') { $firstId = (string) (isset($x['id']) ? $x['id'] : ''); break; }
            }
        }
        $id = (string) (isset($t['id']) ? $t['id'] : '');
        return ($firstId !== '' && $id === $firstId);
    }

    public function render_export_column($column, $post_id) {
        if ($column !== 'cf_exported') return;
        if (get_post_meta($post_id, '_cf_exported', true)) {
            echo '<span title="已生成静态单页（本地或 R2）">✅ 已导出</span>';
        } elseif (get_post_meta($post_id, '_cfse_synced_d1', true)) {
            echo '<span title="只把数据同步进了 D1（未生成静态单页）；单页由 Worker 按需渲染">☁️ 已同步 D1</span>';
        } else {
            echo '<span title="还没有同步/导出">⏳ 待导出</span>';
        }
    }


    /**
     * 「☁️ Worker 同步」面板（只对 Worker 端诞生的篇显示）：
     * 异步查两端版本时间（不拖慢编辑页）+ 一键对齐本篇。
     */
    public function render_worker_sync_metabox($post) {
        $pid = (int) $post->ID;
        $auto = !empty($this->config['worker_auto_check']);
        // 用链接而不是 <form>：WP 编辑页的主表单包裹整个编辑区，内层 <form> 会被浏览器忽略
        $align_url = wp_nonce_url(admin_url('admin-post.php?action=cfse_align_worker&post_id=' . $pid), 'cfse_align_worker_' . $pid);
        echo '<p class="description">本篇由 <b>Worker 端诞生</b>，可把 Worker 的最新版本对齐过来。</p>';
        echo '<p style="margin:8px 0;"><button type="button" class="button" id="cfse-check-btn">🔍 检查 Worker 端版本</button></p>';
        echo '<div id="cfse-worker-status" style="margin:8px 0;padding:8px;background:#f6f7f7;border-radius:3px;font-size:12px;line-height:1.7;color:#646970;">'
            . ($auto ? '正在查询…' : '点上面的按钮查询两端版本时间。') . '</div>';
        echo '<p id="cfse-align-wrap" style="margin:8px 0;display:none;">'
            . '<a href="' . esc_url($align_url) . '" class="button button-primary" id="cfse-align-btn">对齐本篇</a>'
            . ' <span class="dashicons dashicons-editor-help" style="color:#787c82;vertical-align:text-bottom;" title="用 Worker 端的标题 / 正文 / 别名 / 分类标签覆盖本篇（会覆盖 WP 侧对该篇的修改）"></span>'
            . '</p>';
        if (isset($_GET['cfse_aligned'])) {
            echo $_GET['cfse_aligned'] === '1'
                ? '<p style="margin:8px 0 0;color:#2271b1;"><b>✅ 已对齐到 Worker 版本</b></p>'
                : '<p style="margin:8px 0 0;color:#b32d2e;"><b>❌ 对齐失败</b>（未能从 Worker 取到该篇，见日志）</p>';
        }
        $ajax_nonce = wp_create_nonce('cfse_worker_status');
        // 按钮只在「远端更新」时出现；本地较新 / 一致 都不给覆盖入口（避免误覆盖）
        echo '<script>jQuery(function($){'
            . 'var $s=$("#cfse-worker-status"),$w=$("#cfse-align-wrap");'
            . 'function cfseCheck(){'
            . '$w.hide();$s.html("查询中…");'
            . '$.post(ajaxurl,{action:"cfse_worker_status",post_id:' . $pid . ',_ajax_nonce:"' . esc_js($ajax_nonce) . '"},function(r){'
            . 'if(!r||!r.ok){$s.html("（查询不到 Worker 版本：该篇可能尚未同步到 Worker，或接口不可用）");return;}'
            . 'var h="Worker 版本：" + r.worker_modified + "<br/>本篇版本：" + r.local_modified;'
            . 'if(r.need_sync){h += "<br/><b style=\'color:#b32d2e;\'>⚠︎ 远端更新，建议对齐</b>";$w.show();}'
            . 'else if(r.local_newer){h += "<br/><b style=\'color:#996800;\'>本地较新</b> —— 若以 Worker 版本覆盖，会丢失本地修改。";}'
            . 'else{h += "<br/><span style=\'color:#1d6f42;\'>✔ 两端一致</span>";}'
            . '$s.html(h);});}'
            . '$("#cfse-check-btn").on("click",cfseCheck);'
            . 'if(' . ($auto ? 'true' : 'false') . '){cfseCheck();}'
            . '});</script>';
    }


    public function render_doc_format_metabox($post) {
        $is_md = ($this->get_content_format($post->ID) === 'md');
        wp_nonce_field('cfse_doc_format_save', 'cfse_doc_format_nonce');
        echo '<label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="cfse_content_format_md" value="1" ' . checked($is_md, true, false) . ' /> <span>存为 MD 格式</span></label>';
        echo '<details style="margin-top:8px;">'
            . '<summary style="cursor:pointer;color:#2271b1;font-size:12px;">'
            . '<span class="dashicons dashicons-editor-help" style="font-size:15px;width:15px;height:15px;vertical-align:text-bottom;"></span> 这是什么？</summary>'
            . '<div class="description" style="margin:6px 0 0;padding-left:8px;border-left:3px solid #c3c4c7;font-size:12px;line-height:1.7;">'
            . '本文正文是 <b>Markdown 原稿</b>时勾选。勾选后，静态站的列表 / 订阅 / 单页都会按 Markdown 排版渲染。<br/>'
            . '<b>建议</b>：md 原稿请用编辑器右上角的「文本」标签页粘贴 —— 「可视」模式保存会重排正文。'
            . '</div></details>';
    }


    public function render_site_metabox($post) {
        if (!$post || $post->post_type !== 'post') return;
        $targets = $this->get_hosting_targets(true);          // 含停用（显示但禁用）
        $checked = $this->get_post_site_ids_raw($post->ID);
        $def = $this->default_site();
        $defName = '';
        foreach ($targets as $t) {
            if ($def && (string) $t['id'] === (string) $def['id']) $defName = (string) $t['name'];
        }
        // 摘要
        $names = array();
        foreach ($targets as $t) {
            if (in_array((string) $t['id'], $checked, true)) $names[] = $t['name'];
        }
        $summary = empty($names)
            ? ($def ? ('未选择 → 使用默认站点「' . $defName . '」') : '未选择（且无默认站点 → 本篇不生成、不发布）')
            : implode('、', $names);
        ?>
        <?php wp_nonce_field('cfse_site_' . $post->ID, 'cfse_site_nonce'); ?>
        <div id="cfse-site-box">
            <p style="margin:4px 0;"><b>当前：</b><span id="cfse-site-summary"><?php echo esc_html($summary); ?></span></p>
            <button type="button" class="button" id="cfse-site-open">⚙️ 设置</button>
            <p class="description" style="font-size:11px;margin-top:6px;">
                选中的站点都会**生成并发布**（同一篇可多站点）；不选则走默认站点。
                默认站点：<code><?php echo $def ? esc_html($defName . ' / ' . $this->site_dir_of($def)) : '（未设置）'; ?></code>
            </p>
        </div>
        <div id="cfse-site-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;">
            <div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);background:#fff;padding:18px 22px;font-size:13px;line-height:1.6;border-radius:6px;min-width:340px;max-width:min(90vw,520px);max-height:80vh;overflow:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);">
                <h3 style="margin-top:0;">🌐 选择站点</h3>
                <p class="description" style="margin:2px 0 10px;">勾选的站点都会生成产物并发布；一个都不勾 = 走默认站点。</p>
                <?php foreach ($targets as $t) {
                    $tid = (string) $t['id'];
                    $dis = empty($t['enabled']);
                    ?>
                    <label style="display:block;margin:5px 0;<?php echo $dis ? 'opacity:.5;' : ''; ?>">
                        <input type="checkbox" name="cfse_site[]" value="<?php echo esc_attr($tid); ?>"
                            <?php checked(in_array($tid, $checked, true)); ?><?php disabled($dis); ?> />
                        <?php echo esc_html($t['name']); ?>
                        <code style="font-size:11px;"><?php echo esc_html($this->site_dir_of($t)); ?></code>
                        <?php if (!empty($t['is_default'])) { ?><span style="color:#2271b1;">（默认）</span><?php } ?>
                        <?php if ($dis) { ?><span style="color:#999;">（已停用）</span><?php } ?>
                    </label>
                <?php } ?>
                <?php if (empty($targets)) { ?>
                    <p>还没有站点。请到「设置 → CF 静态导出 → 🎯 托管目标」添加。</p>
                <?php } ?>
                <p style="text-align:right;margin-bottom:0;">
                    <button type="button" class="button button-primary" id="cfse-site-close">完成</button>
                </p>
            </div>
        </div>
        <script>
        (function () {
            var open = document.getElementById('cfse-site-open');
            var modal = document.getElementById('cfse-site-modal');
            var close = document.getElementById('cfse-site-close');
            if (!open || !modal) return;
            open.addEventListener('click', function () { modal.style.display = 'block'; });
            if (close) close.addEventListener('click', function () { modal.style.display = 'none'; });
            modal.addEventListener('click', function (e) { if (e.target === modal) modal.style.display = 'none'; });
            // 勾选变化时即时更新摘要（不必等保存）
            var boxes = modal.querySelectorAll('input[name="cfse_site[]"]');
            function refresh() {
                var picked = [];
                for (var i = 0; i < boxes.length; i++) {
                    if (boxes[i].checked) {
                        var lab = boxes[i].parentNode;
                        picked.push((lab.textContent || '').replace(/（默认）|（已停用）/g, '').trim());
                    }
                }
                var el = document.getElementById('cfse-site-summary');
                if (el) el.textContent = picked.length ? picked.join('、') : '未选择（走默认站点；若无默认站点则本篇不生成、不发布）';
            }
            for (var i = 0; i < boxes.length; i++) boxes[i].addEventListener('change', refresh);
        })();
        </script>
        <?php
    }


    /** 全站默认站点（托管目标里勾了「默认站点」且启用的第一个；没有则 null） */
    public function default_site() {
        $targets = isset($this->config['hosting_targets']) ? $this->config['hosting_targets'] : array();
        foreach ($targets as $t) {
            if (!empty($t['is_default']) && !empty($t['enabled'])) return $t;
        }
        return null;
    }


    /**
     * 读取文章内容格式，返回值只有 'md' / 'html'。
     * 优先可见字段 content_format（与 D1 posts.content_format 同名；WP 后台编辑页的「自定义字段」
     * 面板里可手动添加/修改），兼容批2 初版写过的隐藏字段 _cfse_content_format。
     */
    public function get_content_format($post_id) {
        $value = get_post_meta($post_id, 'content_format', true);
        if ($value === '' || $value === null || $value === false) {
            $value = get_post_meta($post_id, '_cfse_content_format', true); // 兼容旧标记
        }
        return (strtolower(trim((string) $value)) === 'md') ? 'md' : 'html';
    }


    // ============================================
    // Markdown 渲染（md 文章；与 Worker 端 marked 对齐）
    // ============================================
    // ============================================
    // 文章编辑页「📝 文档格式」面板（Markdown 原稿标记）
    // ============================================
    // ============================================
    // 文章编辑页「🌐 站点」面板（多站点归属）
    // ============================================

    /** 文章显式标记的站点 id（**不回落默认站点** —— 面板要如实显示"未选"） */
    public function get_post_site_ids_raw($post_id) {
        $raw = get_post_meta($post_id, self::META_SITE, false);
        $ids = array();
        foreach ((array) $raw as $v) {
            if (is_array($v)) { foreach ($v as $x) $ids[] = (string) $x; }
            elseif (is_string($v) && strpos($v, ',') !== false) { foreach (explode(',', $v) as $x) $ids[] = trim($x); }
            elseif ($v !== '' && $v !== null) { $ids[] = (string) $v; }
        }
        return array_values(array_unique(array_filter($ids, 'strlen')));
    }
}
