<?php
/**
 * 模板编辑器 - 两层模板体系（页面壳 + 文章条目模板）
 *
 * 每个页面类型（homepage/category/tag/archive）由两部分组成：
 *   - 页面模板（page）：整页 HTML 外壳，可用占位符见 cfse_get_page_vars()
 *   - 条目模板（item）：单篇文章的 HTML 结构，可用占位符见 cfse_get_item_vars()
 * 保存后由 cfse_generate_templates_from_custom() 生成 worker/src/templates.js
 * （SITE 站点名/URL 同步时写死进产物，与 Worker 无运行时依赖）
 */
if (!defined('ABSPATH')) {
    exit;
}

// 风格生成 v2：内部渲染（tools/skin-render.php，每种页面一个进程）+ 探针 token 驱动映射
require_once dirname(__FILE__) . '/skin-builder.php';

// ============================================
// 1. 注册菜单和钩子
// ============================================
function cfse_add_template_editor_menu() {
    add_submenu_page(
        'options-general.php',
        '模板编辑器',
        '🎨 模板编辑器',
        'manage_options',
        'cf-template-editor',
        'cfse_render_template_editor'
    );
}

function cfse_register_template_editor_hooks() {
    add_action('admin_post_cf_save_template', 'cfse_handle_save_template');
    add_action('admin_post_cf_reset_template', 'cfse_handle_reset_template');
    add_action('admin_post_cf_fetch_skin', 'cfse_handle_fetch_skin');
    add_action('admin_post_cf_restore_templates', 'cfse_handle_restore_templates');
    add_action('admin_post_cf_create_style', 'cfse_handle_create_style');
    add_action('admin_post_cf_export_style', 'cfse_handle_export_style');
    add_action('admin_post_cf_activate_style', 'cfse_handle_activate_style');
    add_action('admin_post_cf_style_off', 'cfse_handle_style_off');
    add_action('wp_ajax_cf_preview_template', 'cfse_ajax_preview_template');
}

// ============================================
// 2. 模板编辑器主界面
// ============================================
function cfse_render_template_editor() {
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'homepage';
    $custom_templates = get_option('cfse_custom_templates', array());
    $backup_snapshots = get_option('cfse_custom_templates_backup', array());
    if (!is_array($backup_snapshots)) $backup_snapshots = array();
    $backup_snapshots = array_reverse($backup_snapshots, true); // 新的在前

    // 风格包（styles）列表与当前模式
    $active_style = get_option('cfse_active_style', '');
    $styles_list = array();
    $styles_root = CFSE_PLUGIN_DIR . 'styles';
    if (is_dir($styles_root)) {
        foreach (glob($styles_root . '/*', GLOB_ONLYDIR) as $dir) {
            $sid = basename($dir);
            $meta = array();
            $mf = $dir . '/manifest.json';
            if (file_exists($mf)) {
                $decoded = json_decode((string) file_get_contents($mf), true);
                if (is_array($decoded)) $meta = $decoded;
            }
            $styles_list[$sid] = array(
                'name' => isset($meta['name']) && $meta['name'] !== '' ? $meta['name'] : $sid,
                'version' => isset($meta['version']) ? $meta['version'] : '-',
                'generated_at' => isset($meta['generated_at']) ? $meta['generated_at'] : '-',
                'has_templates' => file_exists($dir . '/templates.js'),
            );
        }
        krsort($styles_list); // id 带时间戳后缀，新的在前
    }

    // 兼容旧格式：旧版本只存了页面模板字符串
    $raw = isset($custom_templates[$active_tab]) ? $custom_templates[$active_tab] : array();
    if (is_string($raw)) {
        $saved_page = $raw;
        $saved_item = null;
    } else {
        $saved_page = isset($raw['page']) ? $raw['page'] : null;
        $saved_item = isset($raw['item']) ? $raw['item'] : null;
    }

    $page_html = $saved_page !== null ? $saved_page : cfse_get_default_template($active_tab, 'page');
    $item_html = $saved_item !== null ? $saved_item : cfse_get_default_template($active_tab, 'item');

    $tab_config = cfse_get_tab_config();
    $page_vars = cfse_get_page_vars();
    $item_vars = cfse_get_item_vars();
    ?>
    <script>
    // 供本页 AJAX（预览）使用
    window.cfse_ajax = window.cfse_ajax || {
        ajax_url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo wp_create_nonce('cfse_ajax_nonce'); ?>'
    };
    </script>
    <div class="wrap cfse-template-editor">
        <h1>🎨 模板编辑器 <span class="version">v<?php echo CFSE_VERSION; ?></span></h1>
        <p class="description">两层模板：<strong>页面模板</strong>（整页外壳）+ <strong>文章条目模板</strong>（列表里每篇文章的卡片）。保存后 → 初始化部署。</p>

        <?php if (isset($_GET['saved']) && $_GET['saved']): ?>
            <div class="notice notice-success is-dismissible">
                <p>✅ 模板已保存！请点击后台 <strong>"初始化部署"</strong> 将更新推送到 Cloudflare Worker。</p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['reset']) && $_GET['reset']): ?>
            <div class="notice notice-warning is-dismissible">
                <p>🔄 模板已重置为默认值。</p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['fetched']) && $_GET['fetched'] === '1'): ?>
            <div class="notice notice-success is-dismissible">
                <p>📥 已从 WordPress 自动抓取外观并写入四个页签模板（旧模板已备份到 option：<code>cfse_custom_templates_backup</code>）。请「👁️ 预览」检查 → 后台「初始化部署」生效。</p>
            </div>
        <?php elseif (isset($_GET['fetched']) && $_GET['fetched'] === '0'): ?>
            <div class="notice notice-error is-dismissible">
                <p>❌ 抓取外观失败，模板未被修改（详见 <code><?php echo esc_html(CFSE_LOG_FILE); ?></code> 日志）。</p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['restored']) && $_GET['restored'] === '1'): ?>
            <div class="notice notice-success is-dismissible">
                <p>♻️ 已从备份快照恢复四个页签模板，templates.js 已重新生成。请检查后点「初始化部署」。</p>
            </div>
        <?php elseif (isset($_GET['restored']) && $_GET['restored'] === '0'): ?>
            <div class="notice notice-error is-dismissible">
                <p>❌ 恢复失败：快照不存在或已损坏。</p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['style_created']) && $_GET['style_created'] === '1'): ?>
            <div class="notice notice-success is-dismissible"><p>📸 已创建风格包快照（styles 目录），可复制到其它站复用或随时激活回滚。</p></div>
        <?php elseif (isset($_GET['style_activated']) && $_GET['style_activated'] === '1'): ?>
            <div class="notice notice-success is-dismissible"><p>▶ 已激活风格包 <code><?php echo esc_html(isset($_GET['style_id']) ? sanitize_text_field(wp_unslash($_GET['style_id'])) : ''); ?></code>。请点「初始化部署」使线上生效。</p></div>
        <?php elseif (isset($_GET['style_off']) && $_GET['style_off'] === '1'): ?>
            <div class="notice notice-success is-dismissible"><p>↩ 已回到 WP 模板模式（下次部署将从模板编辑器重新生成）。</p></div>
        <?php elseif (isset($_GET['style_error']) && $_GET['style_error'] === '1'): ?>
            <div class="notice notice-error is-dismissible"><p>❌ 风格包操作失败：目标目录不存在或复制失败（详见日志）。</p></div>
        <?php endif; ?>

        <!-- 使用说明 -->
        <div class="cfse-guide" style="background: #f0f6fc; padding: 15px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #2271b1;">
            <h4 style="margin-top: 0;">📌 使用说明（两层手动自定义）</h4>
            <ol style="margin: 5px 0 0 20px;">
                <li><strong>页面模板</strong> = 整页 HTML。文章列表放在 <code>{{CONTENT}}</code>，上下页导航是 <code>{{PAGINATION_PREV}}</code> / <code>{{PAGINATION_NEXT}}</code>（自动生成链接，无上一/下一页时为空白），其余 <code>{{XX}}</code> 见下方"页面标识符"。</li>
                <li><strong>文章条目模板</strong> = 每篇文章重复的结构。可用 <code>{{POST_TITLE}}</code> <code>{{POST_URL}}</code> <code>{{POST_DATE}}</code> <code>{{POST_EXCERPT}}</code> <code>{{POST_CATEGORIES}}</code> <code>{{POST_TAGS}}</code> 等。</li>
                <li>没有分类/标签的文章，其整段内容不会输出 —— 用 <code>{{#IF_CATEGORIES}}…{{/IF_CATEGORIES}}</code> 与 <code>{{#IF_TAGS}}…{{/IF_TAGS}}</code> 包裹即可（见默认条目模板示例）。</li>
                <li>站点名 <code>{{SITE_NAME}}</code>、站点链接 <code>{{SITE_URL}}</code> 保存时会写死进模板。</li>
                <li>点击「👁️ 预览」查看效果 → 「💾 保存模板」→ 后台「初始化部署」。</li>
            </ol>
        </div>

        <!-- 备份与恢复 -->
        <div class="cfse-guide" style="background:#fdfaf3; border-left-color:#d4a017; padding:12px 15px; margin-bottom:15px;">
            <h4 style="margin-top:0;">🗂 模板备份与恢复</h4>
            <p style="margin:4px 0 8px; color:#555; font-size:13px;">
                「📥 抓取外观」覆盖前会自动把当前模板备份到 <code>cfse_custom_templates_backup</code>（保留最近 5 份，时间戳为 UTC）。想回到历史版本，点对应「恢复」（恢复前会先备份当前模板）。
            </p>
            <?php if (empty($backup_snapshots)): ?>
                <p style="color:#999; margin:4px 0;">暂无备份。</p>
            <?php else: ?>
                <ul style="margin:6px 0 0 18px; line-height:1.9;">
                    <?php foreach ($backup_snapshots as $bs_ts => $bs_data): ?>
                        <li>
                            <code><?php echo esc_html($bs_ts); ?></code>
                            <a class="button button-small"
                               href="<?php echo esc_url(admin_url('admin-post.php?action=cf_restore_templates&ts=' . rawurlencode($bs_ts) . '&_wpnonce=' . wp_create_nonce('cf_restore_templates'))); ?>"
                               onclick="return confirm('确认恢复到 <?php echo esc_js($bs_ts); ?> 的模板？当前模板会先被备份。')">♻️ 恢复此版本</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- 风格包（styles） -->
        <div class="cfse-guide" style="background:#eef7ee; border-left-color:#2e7d32; padding:12px 15px; margin-bottom:15px;">
            <h4 style="margin-top:0;">🎨 风格包（styles）</h4>
            <p style="margin:4px 0 8px; color:#555; font-size:13px;">
                风格包 = 站点无关的模板快照（templates.js + 静态资源 + manifest），可复制到其它站复用、随时回滚。
                当前模式：
                <?php if ($active_style !== '' && isset($styles_list[$active_style])): ?>
                    <strong style="color:#2e7d32;">激活风格：<code><?php echo esc_html($active_style); ?></code></strong>
                    —— 此后点「初始化部署」将使用此快照（不再从 WP 模板生成/同步主题资源）。
                    <a class="button button-small" style="margin-left:6px;"
                       href="<?php echo esc_url(admin_url('admin-post.php?action=cf_style_off&_wpnonce=' . wp_create_nonce('cf_style_off'))); ?>"
                       onclick="return confirm('退出激活风格，回到 WP 模板模式？（下次部署将从模板编辑器重新生成）')">↩ 回到 WP 模板模式</a>
                <?php elseif ($active_style !== ''): ?>
                    <strong style="color:#c0392b;">激活风格 <code><?php echo esc_html($active_style); ?></code> 目录缺失</strong>（部署将自动回退 WP 模板模式并清除标记）
                <?php else: ?>
                    <strong>WP 模板模式</strong>（部署时从模板编辑器/抓取结果生成 templates.js）
                <?php endif; ?>
            </p>
            <form style="display:flex; gap:6px; align-items:center; margin:6px 0 10px; flex-wrap:wrap;" method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="cf_create_style" />
                <?php wp_nonce_field('cf_create_style'); ?>
                <input type="text" name="style_name" placeholder="风格显示名（可留空 = 主题名+时间戳）" style="min-width:220px;" />
                <button type="submit" class="button button-primary">📸 把当前模板快照为新风格</button>
            </form>
            <p style="margin:0 0 10px;"><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cf_export_style'), 'cf_export_style')); ?>">📤 导出风格包 JSON（可直接导入 Worker 后台「模板」页）</a></p>
            <?php if (empty($styles_list)): ?>
                <p style="color:#999; margin:4px 0;">暂无风格包。点上方按钮把当前模板存为第一个风格快照。</p>
            <?php else: ?>
                <ul style="margin:4px 0 0 18px; line-height:1.9;">
                    <?php foreach ($styles_list as $sid => $meta): ?>
                        <li>
                            <code><?php echo esc_html($sid); ?></code>
                            <span style="color:#666; font-size:12px;"><?php echo esc_html($meta['name'] . ' · v' . $meta['version'] . ' · ' . $meta['generated_at']); ?></span>
                            <?php if ($sid === $active_style): ?>
                                <strong style="color:#2e7d32;">（当前激活）</strong>
                            <?php else: ?>
                                <a class="button button-small" style="margin-left:4px;"
                                   href="<?php echo esc_url(admin_url('admin-post.php?action=cf_activate_style&id=' . rawurlencode($sid) . '&_wpnonce=' . wp_create_nonce('cf_activate_style'))); ?>"
                                   onclick="return confirm('激活风格 <?php echo esc_js($sid); ?>？将覆盖当前 worker/src 产物，之后点「初始化部署」生效。')">▶ 激活此风格</a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Tab 切换 -->
        <h2 class="nav-tab-wrapper">
            <?php foreach ($tab_config as $key => $config): ?>
                <a href="?page=cf-template-editor&tab=<?php echo esc_attr($key); ?>"
                   class="nav-tab <?php echo $active_tab === $key ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html($config['label']); ?>
                </a>
            <?php endforeach; ?>
        </h2>

        <!-- 编辑器主体 -->
        <div class="cfse-editor-content">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_save_template')); ?>" id="cfse-template-form">
                <?php wp_nonce_field('cf_save_template'); ?>
                <input type="hidden" name="template_type" value="<?php echo esc_attr($active_tab); ?>">

                <!-- 工具栏 -->
                <div class="cfse-toolbar">
                    <a href="<?php echo esc_url(admin_url('admin-post.php?action=cf_fetch_skin&_wpnonce=' . wp_create_nonce('cf_fetch_skin'))); ?>"
                       class="button button-primary"
                       onclick="return confirm('将从 WordPress 原站自动抓取页面外观，覆盖四个页签的模板（旧模板会自动备份到 option，可恢复）。\n\n过程：临时发布一篇带分类/标签的探针文章 → 抓取原站首页 → 自动把正文列表与侧边栏三块替换为动态标识符 → 删除探针文章。\n\n继续？')">📥 抓取外观（自动）</a>
                    <button type="button" class="button" id="cf-preview-template">👁️ 预览（保存前看效果）</button>
                    <button type="submit" class="button button-primary">💾 保存模板</button>
                    <a href="<?php echo esc_url(admin_url('admin-post.php?action=cf_reset_template&type=' . $active_tab . '&_wpnonce=' . wp_create_nonce('cf_reset_template'))); ?>"
                       class="button" onclick="return confirm('确认重置为默认模板？当前页签未保存的自定义将丢失。')">🔄 重置默认</a>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=cf-static-export-pro')); ?>" class="button">⚙️ 返回设置</a>
                </div>

                <!-- 页面标识符列表 -->
                <div class="cfse-template-vars">
                    <strong>📌 页面标识符（点击插入到"页面模板"）：</strong>
                    <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px;">
                        <?php foreach ($page_vars as $var => $desc): ?>
                            <span class="var-item var-page" data-insert="<?php echo esc_attr($var); ?>">
                                <code class="var-code"><?php echo esc_html($var); ?></code>
                                <span style="color:#999; font-size:12px;"><?php echo esc_html($desc); ?></span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($active_tab !== 'single'): ?>
                <div class="cfse-template-vars" style="background:#fdfaf3;border-color:#e8d9b0;">
                    <strong>📌 条目标识符（点击插入到"文章条目模板"）：</strong>
                    <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px;">
                        <?php foreach ($item_vars as $var => $desc): ?>
                            <span class="var-item var-item-tpl" data-insert="<?php echo esc_attr($var); ?>">
                                <code class="var-code"><?php echo esc_html($var); ?></code>
                                <span style="color:#999; font-size:12px;"><?php echo esc_html($desc); ?></span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <p style="color:#c0392b;font-size:12px;margin:6px 0 0;">
                        ⚠️ 条目模板需包含整段 HTML（外层可用任意标签，最终会拼接进列表）。用 {{#IF_TAGS}}…{{/IF_TAGS}} 包裹"有标签才显示"的区块。
                    </p>
                </div>
                <?php endif; ?>

                <?php if ($active_tab === 'single'): ?>
                <div class="cfse-template-vars" style="background:#eef7ee;border-color:#b8e6bf;">
                    <strong>📌 单页占位符（点击插入到"页面模板"）：</strong>
                    <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px;">
                        <span class="var-item var-page" data-insert="{{POST_TITLE}}"><code class="var-code">{{POST_TITLE}}</code><span style="color:#999; font-size:12px;">文章标题</span></span>
                        <span class="var-item var-page" data-insert="{{POST_CONTENT}}"><code class="var-code">{{POST_CONTENT}}</code><span style="color:#999; font-size:12px;">正文（整段 HTML）</span></span>
                        <span class="var-item var-page" data-insert="{{POST_DATE}}"><code class="var-code">{{POST_DATE}}</code><span style="color:#999; font-size:12px;">发布日期</span></span>
                        <span class="var-item var-page" data-insert="{{POST_URL}}"><code class="var-code">{{POST_URL}}</code><span style="color:#999; font-size:12px;">文章链接</span></span>
                        <span class="var-item var-page" data-insert="{{POST_EXCERPT}}"><code class="var-code">{{POST_EXCERPT}}</code><span style="color:#999; font-size:12px;">摘要</span></span>
                    </div>
                    <p style="color:#555;font-size:12px;margin:6px 0 0;">
                        单页壳由「📥 抓取外观」自动从主题 single 页抓取生成；下方为极简兜底模板。正文整段替换进 <code>{{POST_CONTENT}}</code>。本页签无"条目模板"。
                    </p>
                </div>
                <?php endif; ?>

                <!-- 页面模板编辑器 -->
                <div class="cfse-editor-block">
                    <div class="cfse-editor-head">
                        <h3>🖼️ 页面模板 <span class="sub">(<?php echo esc_html($tab_config[$active_tab]['label']); ?>)</span></h3>
                    </div>
                    <textarea
                        name="template_content"
                        id="template-editor"
                        class="cfse-editor-textarea"
                        rows="24"
                        data-target="page"
                    ><?php echo esc_textarea($page_html); ?></textarea>
                </div>

                <?php if ($active_tab !== 'single'): ?>
                <!-- 条目模板编辑器 -->
                <div class="cfse-editor-block">
                    <div class="cfse-editor-head">
                        <h3>📄 文章条目模板 <span class="sub">(单篇文章结构，重复拼接)</span></h3>
                    </div>
                    <textarea
                        name="template_content_item"
                        id="item-editor"
                        class="cfse-editor-textarea cfse-editor-textarea-item"
                        rows="24"
                        data-target="item"
                    ><?php echo esc_textarea($item_html); ?></textarea>
                </div>
                <?php else: ?>
                <div class="cfse-editor-block" style="background:#f7f7f7;padding:12px 16px;border-radius:6px;">
                    <p style="color:#666;font-size:13px;margin:0;">📄 本页签为「文章单页」壳模板（无条目模板）。保存后由 Worker 发布文章时渲染静态单页；「📥 抓取外观」可自动抓取主题 single 页生成更完整的壳。</p>
                </div>
                <?php endif; ?>

                <!-- 预览区域 -->
                <div id="cf-preview-box" style="display:none; margin-top: 15px;">
                    <div style="background: #f0f0f0; padding: 8px 15px; border-radius: 4px 4px 0 0; display: flex; justify-content: space-between; align-items: center;">
                        <strong>👁️ 预览</strong>
                        <button type="button" id="cf-close-preview" class="button button-small">✕ 关闭</button>
                    </div>
                    <div id="cf-preview-content" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 0 0 4px 4px; max-height: 600px; overflow: auto;">
                        加载中...
                    </div>
                </div>
            </form>
        </div>
    </div>
    <style>
    .cfse-template-editor .nav-tab-wrapper { margin-bottom: 20px; }
    .cfse-editor-content { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .cfse-toolbar { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
    .cfse-template-vars { background: #f8f9fa; padding: 12px 15px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #e5e5e5; }
    .cfse-template-vars .var-item { display: inline-block; background: #fff; padding: 2px 10px; border-radius: 3px; margin: 2px; border: 1px solid #ddd; cursor: pointer; }
    .cfse-template-vars .var-code { background: none; padding: 0; border: none; font-weight: bold; color: #2271b1; cursor: pointer; }
    .cfse-template-vars .var-code:hover { color: #135e96; text-decoration: underline; }
    .cfse-editor-block { margin-bottom: 20px; }
    .cfse-editor-head h3 { margin: 0 0 8px 0; }
    .cfse-editor-head .sub { color: #999; font-size: 13px; font-weight: normal; }
    .cfse-editor-wrapper .cfse-editor-textarea,
    .cfse-editor-textarea {
        width: 100%;
        min-height: 300px;
        font-family: 'Courier New', Courier, monospace;
        font-size: 13px;
        line-height: 1.6;
        padding: 15px;
        border: 1px solid #ddd;
        border-radius: 4px;
        tab-size: 4;
        background: #fafafa;
        box-sizing: border-box;
    }
    .cfse-editor-textarea:focus { border-color: #2271b1; box-shadow: 0 0 0 2px rgba(34,113,177,0.2); background: #fff; }
    .cfse-editor-textarea-item { background: #fdfaf3; border-color: #e8d9b0; }
    .cfse-guide ol { line-height: 1.9; }
    #cf-preview-content { font-size: 14px; line-height: 1.8; }
    @media (max-width: 782px) { .cfse-editor-textarea { min-height: 240px; } }
    </style>
    <script>
    jQuery(document).ready(function($) {
        // 变量插入：点击标识符插到对应 textarea 的光标位置
        function insertVar(textareaSel, text) {
            var textarea = $(textareaSel);
            var cursorPos = textarea.prop('selectionStart');
            var currentVal = textarea.val();
            var newVal = currentVal.substring(0, cursorPos) + text + currentVal.substring(cursorPos);
            textarea.val(newVal);
            var newPos = cursorPos + text.length;
            textarea.prop('selectionStart', newPos);
            textarea.prop('selectionEnd', newPos);
            textarea.focus();
        }
        $('.var-item').on('click', function() {
            var text = $(this).attr('data-insert');
            if ($(this).hasClass('var-item-tpl')) {
                insertVar('#item-editor', text);
            } else {
                insertVar('#template-editor', text);
            }
        });

        // 预览
        var previewBox = $('#cf-preview-box');
        var previewContent = $('#cf-preview-content');
        $('#cf-preview-template').on('click', function() {
            var content = $('#template-editor').val();
            var itemContent = $('#item-editor').val();
            var type = $('input[name="template_type"]').val();
            previewBox.show();
            previewContent.html('⏳ 加载中...');
            $.ajax({
                url: cfse_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cf_preview_template',
                    nonce: cfse_ajax.nonce,
                    type: type,
                    content: content,
                    content_item: itemContent
                },
                success: function(response) {
                    if (response.success) {
                        previewContent.html(response.data.html);
                    } else {
                        previewContent.html('<p style="color: red;">❌ 预览失败: ' + (response.data || '未知错误') + '</p>');
                    }
                },
                error: function() {
                    previewContent.html('<p style="color: red;">❌ 请求失败</p>');
                }
            });
        });
        $('#cf-close-preview').on('click', function() {
            previewBox.hide();
        });
    });
    </script>
    <?php
}

// ============================================
// 3. 标识符与页签配置
// ============================================
function cfse_get_tab_config() {
    return array(
        'homepage' => array('label' => '🏠 首页'),
        'category' => array('label' => '📂 分类页'),
        'tag'      => array('label' => '🏷️ 标签页'),
        'archive'  => array('label' => '📅 归档页'),
        'single'   => array('label' => '📄 文章单页'),
    );
}

/**
 * 页面模板占位符（全部会被运行时替换）
 */
function cfse_get_page_vars() {
    return array(
        '{{SITE_NAME}}' => '站点名（保存时写死）',
        '{{SITE_URL}}' => '站点首页地址（保存时写死）',
        '{{SITE_DESC}}' => '站点描述（Worker 渲染时从站点设置注入）',
        '{{CONTENT}}' => '文章列表（核心，必须出现一次）',
        '{{TITLE}}' => '页面标题（首页=站点名；分类/标签=分类/标签名；归档=年月归档）',
        '{{TOTAL_POSTS}}' => '总文章数',
        '{{PAGINATION_PREV}}' => '上一页链接（整段 <a>，没有则空白）',
        '{{PAGINATION_NEXT}}' => '下一页链接（整段 <a>，没有则空白）',
        '{{YEAR}}' => '年份（仅归档页）',
        '{{MONTH}}' => '月份（仅归档页）',
        '{{SIDEBAR_CATEGORIES}}' => '侧边栏-分类列表（动态生成 <li>，自动更新）',
        '{{SIDEBAR_ARCHIVES}}' => '侧边栏-归档列表（动态生成 <li>，自动更新）',
        '{{SIDEBAR_TAGS}}' => '侧边栏-标签云（动态生成 <li>，字号随文章数变化）',
    );
}

/**
 * 文章条目模板占位符
 */
function cfse_get_item_vars() {
    return array(
        '{{POST_TITLE}}' => '文章标题',
        '{{POST_URL}}' => '文章链接（站点URL + 文章路径）',
        '{{POST_DATE}}' => '发布日期（YYYY-MM-DD）',
        '{{POST_EXCERPT}}' => '文章摘要',
        '{{POST_CATEGORIES}}' => '分类链接（多个用逗号分隔；无分类为空）',
        '{{POST_TAGS}}' => '标签链接（多个用逗号分隔；无标签为空）',
        '{{#IF_TAGS}}…{{/IF_TAGS}}' => '条件块：有标签才输出内部内容',
        '{{#IF_CATEGORIES}}…{{/IF_CATEGORIES}}' => '条件块：有分类才输出内部内容',
    );
}

/**
 * 内置默认模板：页面壳 + 文章条目
 */
function cfse_get_default_template($type, $part = 'page') {
    $tpls = cfse_default_templates();
    return isset($tpls[$type][$part]) ? $tpls[$type][$part] : '';
}

function cfse_default_templates() {
    $site_footer = '<div style="text-align:center;color:#666;font-size:12px;padding:20px 0;border-top:1px solid #eee;margin-top:30px;">
    <a href="{{SITE_URL}}/">{{SITE_NAME}}</a> · CF Static Export Pro
</div>';

    return array(
        'homepage' => array(
            'page' => '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{SITE_NAME}}{{#IF_hasPage}} | Page {{PAGE_NUMBER}}{{/IF_hasPage}}</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; max-width: 820px; margin: 0 auto; padding: 20px; line-height: 1.7; color: #333; }
    h1 { border-bottom: 2px solid #333; padding-bottom: 10px; }
    h2.entry-title { margin-bottom: 4px; }
    h2.entry-title a { color: #007bff; text-decoration: none; }
    a { color: #007bff; text-decoration: none; } a:hover { text-decoration: underline; }
    .navigation { overflow: hidden; margin: 18px 0; }
    .nav-previous { float: left; }
    .nav-next { float: right; }
    .entry-meta, .entry-utility { color: #666; font-size: 0.9em; margin: 6px 0; }
</style>
</head>
<body>
<h1><a href="{{SITE_URL}}/" style="color:#333;text-decoration:none;">{{SITE_NAME}}</a></h1>
<div id="nav-above" class="navigation">
    <div class="nav-previous">{{PAGINATION_PREV}}</div>
    <div class="nav-next">{{PAGINATION_NEXT}}</div>
</div>
{{CONTENT}}
<div id="nav-below" class="navigation">
    <div class="nav-previous">{{PAGINATION_PREV}}</div>
    <div class="nav-next">{{PAGINATION_NEXT}}</div>
</div>
{{SITE_FOOTER}}
</body>
</html>',
            'item' => '<article style="border-bottom:1px solid #eee;padding:16px 0;">
    <h2 class="entry-title"><a href="{{POST_URL}}">{{POST_TITLE}}</a></h2>
    <div class="entry-meta">Posted on {{POST_DATE}}</div>
    <div class="entry-content">{{POST_EXCERPT}}</div>
    <div class="entry-utility">
        {{#IF_CATEGORIES}}<span class="cat-links">Posted in {{POST_CATEGORIES}}</span> | {{/IF_CATEGORIES}}
        {{#IF_TAGS}}<span class="tag-links">Tagged {{POST_TAGS}}</span>{{/IF_TAGS}}
    </div>
</article>',
        ),
        'category' => array(
            'page' => '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Category: {{TITLE}} | {{SITE_NAME}}</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; max-width: 820px; margin: 0 auto; padding: 20px; line-height: 1.7; color: #333; }
    a { color: #007bff; text-decoration: none; } a:hover { text-decoration: underline; }
    h1 { border-bottom: 2px solid #333; padding-bottom: 10px; }
    .navigation { overflow: hidden; margin: 18px 0; }
    .nav-previous { float: left; } .nav-next { float: right; }
</style>
</head>
<body>
<h1><a href="{{SITE_URL}}/" style="color:#333;text-decoration:none;">{{SITE_NAME}}</a></h1>
<p style="color:#666;">分类：{{TITLE}}（共 {{TOTAL_POSTS}} 篇）</p>
<div class="navigation"><div class="nav-previous">{{PAGINATION_PREV}}</div><div class="nav-next">{{PAGINATION_NEXT}}</div></div>
{{CONTENT}}
<div class="navigation"><div class="nav-previous">{{PAGINATION_PREV}}</div><div class="nav-next">{{PAGINATION_NEXT}}</div></div>
{{SITE_FOOTER}}
</body>
</html>',
            'item' => cfse_default_item_card(),
        ),
        'tag' => array(
            'page' => '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tag: {{TITLE}} | {{SITE_NAME}}</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; max-width: 820px; margin: 0 auto; padding: 20px; line-height: 1.7; color: #333; }
    a { color: #007bff; text-decoration: none; } a:hover { text-decoration: underline; }
    h1 { border-bottom: 2px solid #333; padding-bottom: 10px; }
    .navigation { overflow: hidden; margin: 18px 0; }
    .nav-previous { float: left; } .nav-next { float: right; }
</style>
</head>
<body>
<h1><a href="{{SITE_URL}}/" style="color:#333;text-decoration:none;">{{SITE_NAME}}</a></h1>
<p style="color:#666;">标签：{{TITLE}}（共 {{TOTAL_POSTS}} 篇）</p>
<div class="navigation"><div class="nav-previous">{{PAGINATION_PREV}}</div><div class="nav-next">{{PAGINATION_NEXT}}</div></div>
{{CONTENT}}
<div class="navigation"><div class="nav-previous">{{PAGINATION_PREV}}</div><div class="nav-next">{{PAGINATION_NEXT}}</div></div>
{{SITE_FOOTER}}
</body>
</html>',
            'item' => cfse_default_item_card(),
        ),
        'archive' => array(
            'page' => '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Archives: {{YEAR}} {{MONTH}} | {{SITE_NAME}}</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; max-width: 820px; margin: 0 auto; padding: 20px; line-height: 1.7; color: #333; }
    a { color: #007bff; text-decoration: none; } a:hover { text-decoration: underline; }
    h1 { border-bottom: 2px solid #333; padding-bottom: 10px; }
    .navigation { overflow: hidden; margin: 18px 0; }
    .nav-previous { float: left; } .nav-next { float: right; }
</style>
</head>
<body>
<h1><a href="{{SITE_URL}}/" style="color:#333;text-decoration:none;">{{SITE_NAME}}</a></h1>
<p style="color:#666;">归档：{{YEAR}} {{MONTH}}</p>
<div class="navigation"><div class="nav-previous">{{PAGINATION_PREV}}</div><div class="nav-next">{{PAGINATION_NEXT}}</div></div>
{{CONTENT}}
<div class="navigation"><div class="nav-previous">{{PAGINATION_PREV}}</div><div class="nav-next">{{PAGINATION_NEXT}}</div></div>
{{SITE_FOOTER}}
</body>
</html>',
            'item' => cfse_default_item_card(),
        ),
        'single' => array(
            // 文章单页壳：Worker 发布/编辑文章时渲染静态单页用（兜底模板；「抓取外观」会抓取主题 single 页生成更完整壳）
            'page' => '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{POST_TITLE}} - {{SITE_NAME}}</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; max-width: 820px; margin: 0 auto; padding: 20px; line-height: 1.8; color: #333; }
    a { color: #007bff; text-decoration: none; } a:hover { text-decoration: underline; }
    h1.entry-title { border-bottom: 2px solid #333; padding-bottom: 10px; }
    .entry-meta { color: #888; font-size: 0.9em; }
    .back { margin-top: 26px; display: block; }
</style>
</head>
<body>
<h1 class="entry-title">{{POST_TITLE}}</h1>
<div class="entry-meta">Posted on {{POST_DATE}}</div>
<div class="entry-content">{{POST_CONTENT}}</div>
<a class="back" href="{{SITE_URL}}/">← 返回首页</a>
</body>
</html>',
            'item' => '',
        ),
    );
}

function cfse_default_item_card() {
    return '<article style="border-bottom:1px solid #eee;padding:16px 0;">
    <h2 style="margin-bottom:4px;"><a href="{{POST_URL}}" style="color:#007bff;text-decoration:none;">{{POST_TITLE}}</a></h2>
    <div style="color:#666;font-size:0.9em;margin:4px 0;">{{POST_DATE}}</div>
    <div>{{POST_EXCERPT}}</div>
    <div style="color:#666;font-size:0.9em;margin-top:6px;">
        {{#IF_CATEGORIES}}<span>分类：{{POST_CATEGORIES}}</span> {{/IF_CATEGORIES}}
        {{#IF_TAGS}}<span>标签：{{POST_TAGS}}</span>{{/IF_TAGS}}
    </div>
</article>';
}

// ============================================
// 4. 保存 / 重置 / 预览
// ============================================
function cfse_handle_save_template() {
    if (!current_user_can('manage_options')) {
        wp_die('权限不足');
    }
    check_admin_referer('cf_save_template');

    $active_tab = sanitize_text_field($_POST['template_type']);
    $page = wp_unslash($_POST['template_content'] ?? '');
    $item = wp_unslash($_POST['template_content_item'] ?? '');

    $custom_templates = get_option('cfse_custom_templates', array());
    $custom_templates[$active_tab] = array('page' => $page, 'item' => $item);
    update_option('cfse_custom_templates', $custom_templates);

    cfse_generate_templates_from_custom();

    cfse_log_message("模板已保存: {$active_tab}", 'INFO');
    wp_redirect(add_query_arg(array('saved' => '1', 'tab' => $active_tab), wp_get_referer()));
    exit;
}

function cfse_handle_reset_template() {
    if (!current_user_can('manage_options')) {
        wp_die('权限不足');
    }
    check_admin_referer('cf_reset_template');

    $type = sanitize_text_field($_GET['type']);
    $custom_templates = get_option('cfse_custom_templates', array());
    unset($custom_templates[$type]);
    update_option('cfse_custom_templates', $custom_templates);

    cfse_generate_templates_from_custom();

    cfse_log_message("模板已重置: {$type}", 'INFO');
    wp_redirect(add_query_arg(array('reset' => '1', 'tab' => $type), wp_get_referer()));
    exit;
}

/**
 * 预览：用真实文章把「页面模板 + 条目模板」渲染一遍
 */
function cfse_ajax_preview_template() {
    check_ajax_referer('cfse_ajax_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('权限不足');
    }

    $type = sanitize_text_field($_POST['type'] ?? 'homepage');
    $page_html = wp_unslash($_POST['content'] ?? '');
    $item_html = wp_unslash($_POST['content_item'] ?? '');
    if ($page_html === '') $page_html = cfse_get_default_template($type, 'page');
    if ($item_html === '') $item_html = cfse_get_default_template($type, 'item');

    $site_name = get_bloginfo('name');
    $site_url = rtrim(cfse_site_url(), '/');

    $posts = get_posts(array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 5,
        'orderby' => 'date',
        'order' => 'DESC'
    ));

    $post_list = '';
    foreach ($posts as $post) {
        $categories = cfse_preview_terms($post->ID, 'category');
        $tags = cfse_preview_terms($post->ID, 'post_tag');

        $item = $item_html;
        $item = str_replace('{{SITE_URL}}', $site_url, $item);
        $item = cfse_preview_if_block($item, 'CATEGORIES', !empty($categories));
        $item = cfse_preview_if_block($item, 'TAGS', !empty($tags));
        $item = str_replace('{{POST_TITLE}}', esc_html($post->post_title), $item);
        $item = str_replace('{{POST_URL}}', esc_url($site_url . '/' . $post->post_name . '/'), $item);
        $item = str_replace('{{POST_DATE}}', esc_html(substr($post->post_date, 0, 10)), $item);
        $excerpt = $post->post_excerpt ? $post->post_excerpt : wp_trim_words($post->post_content, 40);
        $item = str_replace('{{POST_EXCERPT}}', wpautop(esc_html($excerpt)), $item);

        $cat_links = array();
        foreach ($categories as $c) {
            // slug 已是 WP 库内编码形态，直接拼接；勿二次 rawurlencode
            $cat_links[] = '<a href="' . esc_url($site_url . '/category/' . $c['slug'] . '/') . '">' . esc_html($c['name']) . '</a>';
        }
        $item = str_replace('{{POST_CATEGORIES}}', implode(', ', $cat_links), $item);

        $tag_links = array();
        foreach ($tags as $t) {
            // slug 已是 WP 库内编码形态，直接拼接；勿二次 rawurlencode
            $tag_links[] = '<a href="' . esc_url($site_url . '/tag/' . $t['slug'] . '/') . '">' . esc_html($t['name']) . '</a>';
        }
        $item = str_replace('{{POST_TAGS}}', implode(', ', $tag_links), $item);

        $post_list .= $item . "\n";
    }

    $title = cfse_preview_title($type, $site_name);
    $html = $page_html;
    $html = str_replace('{{SITE_NAME}}', esc_html($site_name), $html);
    $html = str_replace('{{SITE_URL}}', esc_url($site_url), $html);
    $html = str_replace('{{TITLE}}', esc_html($title), $html);
    $html = str_replace('{{CONTENT}}', $post_list, $html);
    $html = str_replace('{{TOTAL_POSTS}}', wp_count_posts('post')->publish, $html);
    $html = cfse_preview_if_block($html, 'hasPage', false); // 预览默认第 1 页，无分页页名
    $html = str_replace('{{YEAR}}', date('Y'), $html);
    $html = str_replace('{{MONTH}}', date('m'), $html);

    // 分页：预览给一组示意链接
    $pagination = '<a href="#" style="color:#007bff;">← Older posts</a>';
    $html = str_replace('{{PAGINATION_PREV}}', $pagination, $html);
    $html = str_replace('{{PAGINATION_NEXT}}', '', $html);
    $html = str_replace('{{SITE_FOOTER}}', '<div style="text-align:center;color:#666;font-size:12px;padding:20px 0;border-top:1px solid #eee;margin-top:30px;">' . esc_html($site_name) . '</div>', $html);

    // 动态侧边栏三块（预览用真实站点分类/标签/归档数据）
    $html = str_replace('{{SIDEBAR_CATEGORIES}}', cfse_preview_sidebar_categories(), $html);
    $html = str_replace('{{SIDEBAR_ARCHIVES}}', cfse_preview_sidebar_archives(), $html);
    $html = str_replace('{{SIDEBAR_TAGS}}', cfse_preview_sidebar_tags(), $html);

    wp_send_json_success(array('html' => $html));
}

function cfse_preview_terms($post_id, $taxonomy) {
    $out = array();
    $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'all'));
    if (is_wp_error($terms) || empty($terms)) return $out;
    foreach ($terms as $t) {
        $out[] = array('name' => $t->name, 'slug' => $t->slug);
    }
    return $out;
}

function cfse_preview_sidebar_categories() {
    $terms = get_terms(array('taxonomy' => 'category', 'hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC'));
    if (is_wp_error($terms) || empty($terms)) return '';
    $site = rtrim(cfse_site_url(), '/');
    $out = '';
    foreach ($terms as $t) {
        $out .= '<li class="cat-item"><a href="' . esc_url($site . '/category/' . $t->slug . '/') . '">' . esc_html($t->name) . '</a></li>' . "\n";
    }
    return $out;
}

function cfse_preview_sidebar_archives() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT DATE_FORMAT(post_date, '%Y-%m') AS ym, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' GROUP BY ym ORDER BY ym DESC");
    if (empty($rows)) return '';
    $month_names = array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
    $site = rtrim(cfse_site_url(), '/');
    $out = '';
    foreach ($rows as $r) {
        $parts = explode('-', $r->ym);
        $label = $month_names[(int) $parts[1] - 1] . ' ' . $parts[0];
        $out .= '<li><a href="' . esc_url($site . '/' . $parts[0] . '/' . $parts[1] . '/') . '">' . $label . '</a></li>' . "\n";
    }
    return $out;
}

function cfse_preview_sidebar_tags() {
    $terms = get_terms(array('taxonomy' => 'post_tag', 'hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC'));
    if (is_wp_error($terms) || empty($terms)) return '';
    $site = rtrim(cfse_site_url(), '/');
    $scaled = array();
    foreach ($terms as $t) {
        $scaled[] = (int) round(log10($t->count + 1) * 100);
    }
    $min = min($scaled);
    $max = max($scaled);
    $spread = ($max - $min) > 0 ? ($max - $min) : 1;
    $out = '';
    $pos = 0;
    foreach ($terms as $t) {
        $pos++;
        $size = 8 + ($scaled[$pos - 1] - $min) * 14 / $spread;
        $count = (int) $t->count;
        $name = esc_html($t->name);
        $aria = $name . ' (' . $count . ($count === 1 ? ' item' : ' items') . ')';
        $out .= '<li><a href="' . esc_url($site . '/tag/' . $t->slug . '/') . '" class="tag-cloud-link tag-link-position-' . $pos . '" style="font-size: ' . $size . 'pt;" aria-label="' . $aria . '">' . $name . '</a></li>' . "\n";
    }
    return $out;
}

function cfse_preview_if_block($html, $flag, $cond) {
    $open = '{{#IF_' . $flag . '}}';
    $close = '{{/IF_' . $flag . '}}';
    while (strpos($html, $open) !== false) {
        $s = strpos($html, $open);
        $e = strpos($html, $close, $s);
        if ($e === false) break;
        $inner = substr($html, $s + strlen($open), $e - $s - strlen($open));
        $replacement = $cond ? $inner : '';
        $html = substr($html, 0, $s) . $replacement . substr($html, $e + strlen($close));
    }
    return $html;
}

function cfse_preview_title($type, $site_name) {
    if ($type === 'homepage') return $site_name;
    if ($type === 'archive') return '归档';
    return '分类/标签';
}

function cfse_site_url() {
    $cfg = get_option('cf_site_url', '');
    if ($cfg) return $cfg;
    if (function_exists('get_site_url')) return get_site_url();
    return home_url();
}

// ============================================
// 5. 生成 templates.js（两层模板 → JS 函数）
// ============================================
function cfse_generate_templates_from_custom() {
    $custom_templates = get_option('cfse_custom_templates', array());
    $base_path = CFSE_WORKER_DIR . 'templates.base.js';
    $target_path = CFSE_WORKER_DIR . 'templates.js';

    if (!file_exists($base_path)) {
        cfse_log_message('模板基础文件不存在: ' . $base_path, 'ERROR');
        return false;
    }

    $content = file_get_contents($base_path);

    $site_name = get_bloginfo('name');
    $site_url = rtrim(cfse_site_url(), '/');
    $storage_pattern = cfse_storage_pattern();

    // 公共运行时（与注入的 4 个函数共享）。
    // ⚠️ 站点信息（名称/URL/存储路径）不再写死进模板：渲染函数从 options.site 取，
    //    由 Worker 运行时从 D1 settings 读取（site:ctx KV 缓存），模板实现站点无关（风格包可复用）。
    $helpers = cfse_helpers_js() . "\n";

    $types = array(
        'homepage' => 'renderHomepage',
        'category' => 'renderCategoryPage',
        'tag' => 'renderTagPage',
        'archive' => 'renderArchivePage',
    );
    $injectBlock = $helpers;
    foreach ($types as $type => $func_name) {
        $raw = isset($custom_templates[$type]) ? $custom_templates[$type] : array();
        if (is_string($raw)) {
            $page_html = $raw;
            $item_html = cfse_get_default_template($type, 'item');
        } else {
            $page_html = isset($raw['page']) ? $raw['page'] : cfse_get_default_template($type, 'page');
            $item_html = isset($raw['item']) ? $raw['item'] : cfse_get_default_template($type, 'item');
        }
        $injectBlock .= cfse_build_template_function($func_name, $page_html, $item_html, $type) . "\n";
    }

    // 文章单页壳模板：不作为渲染函数导出，导出为常量供 Worker 发布文章时渲染静态单页
    $single_raw = isset($custom_templates['single']) ? $custom_templates['single'] : array();
    $single_page = is_string($single_raw) ? $single_raw
        : (isset($single_raw['page']) && $single_raw['page'] !== '' ? $single_raw['page'] : cfse_get_default_template('single', 'page'));
    $injectBlock .= "\n// 单页壳模板（Worker 发布文章时渲染静态单页；占位符：POST_TITLE/POST_CONTENT/POST_DATE/POST_URL/POST_EXCERPT/SITE_URL/SITE_NAME/SITE_FOOTER）\n";
    $injectBlock .= "export const CFSE_SINGLE_TPL = " . json_encode($single_page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";

    $content = str_replace("{{INJECT_TEMPLATES}}", $injectBlock, $content);

    if (file_put_contents($target_path, $content) === false) {
        cfse_log_message('写入 templates.js 失败: ' . $target_path, 'ERROR');
        return false;
    }

    cfse_log_message('templates.js 已从自定义模板生成', 'INFO');
    return true;
}

/**
 * 由 permalink 推导 R2 存储路径模板（与 routes.js 同源，用于生成文章 URL）
 */
function cfse_storage_pattern() {
    $permalink = get_option('permalink_structure');
    if (empty($permalink) || $permalink === '/%postname%/') return '{slug}/index.html';
    $pattern = trim($permalink, '/');
    $tags = array(
        '%year%' => '{year}', '%monthnum%' => '{month}', '%day%' => '{day}',
        '%hour%' => '{hour}', '%minute%' => '{minute}', '%second%' => '{second}',
        '%postname%' => '{slug}', '%post_id%' => '{id}',
        '%category%' => '{category}', '%author%' => '{author}',
    );
    foreach ($tags as $k => $v) $pattern = str_replace($k, $v, $pattern);
    return $pattern . '/index.html';
}

/**
 * 生成条目渲染 + 分页的公共 JS helper（一次注入，4 个函数共用）
 */
function cfse_helpers_js() {
    return <<<'JS'

// 解析 WP 写库的 categories/tags 列（结构化 JSON [{name,slug}]；兼容旧版纯 names 数组）
function cfseParseTaxo(raw) {
    if (!raw) return [];
    let arr = null;
    try { arr = JSON.parse(raw); } catch (e) { return []; }
    if (!Array.isArray(arr)) return [];
    return arr.map(x => {
        if (typeof x === 'string') return { name: x, slug: x };
        if (x && typeof x === 'object') return { name: String(x.name || ''), slug: String(x.slug || x.name || '') };
        return null;
    }).filter(x => x !== null && x.name !== '');
}

// 文章绝对 URL（站点上下文 site.url + site.storagePattern）
function cfsePostUrl(post, site) {
    let p = (site && site.storagePattern) || '';
    const d = String(post.date || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    const vals = { year: d ? d[1] : '', month: d ? d[2] : '', day: d ? d[3] : '', hour: '', minute: '', second: '', id: String(post.wp_id || ''), slug: String(post.slug || ''), category: '', author: '' };
    p = p.replace(/\{(year|month|day|hour|minute|second|id|slug|category|author)\}/g, (m, k) => vals[k] || '');
    p = p.replace(/\/?index\.html$/, '');
    return ((site && site.url) || '') + '/' + p.split('/').filter(Boolean).join('/') + '/';
}

// 条件块处理：{{#IF_FLAG}}...{{/IF_FLAG}}，cond 为 false 时整块删除
function cfseIfBlock(tpl, flag, cond) {
    const open = '{{#IF_' + flag + '}}';
    const close = '{{/IF_' + flag + '}}';
    while (tpl.includes(open)) {
        const s = tpl.indexOf(open);
        const e = tpl.indexOf(close, s);
        if (e < 0) break;
        const inner = tpl.slice(s + open.length, e);
        const rep = cond ? inner : '';
        tpl = tpl.slice(0, s) + rep + tpl.slice(e + close.length);
    }
    return tpl;
}

// 文本替换（split/join 避免正则特殊字符）
function cfseReplace(tpl, token, value) {
    return tpl.split(token).join(String(value == null ? '' : value));
}

// 渲染单篇文章条目
function cfseRenderItem(itemTpl, post, site) {
    const cats = cfseParseTaxo(post.categories);
    const tags = cfseParseTaxo(post.tags);
    const baseUrl = (site && site.url) || '';
    const catBase = baseUrl + '/category/';
    const tagBase = baseUrl + '/tag/';
    // slug 为 WP 库内已编码形态（含 %xx 小写编码），直接拼接，勿再 encodeURIComponent / rawurlencode
    const catLinks = cats.map(c => '<a href="' + catBase + (c.slug || '') + '/">' + escapeHtml(c.name) + '</a>').join(', ');
    const tagLinks = tags.map(t => '<a href="' + tagBase + (t.slug || '') + '/">' + escapeHtml(t.name) + '</a>').join(', ');

    let h = itemTpl;
    h = cfseIfBlock(h, 'CATEGORIES', cats.length > 0);
    h = cfseIfBlock(h, 'TAGS', tags.length > 0);
    h = cfseReplace(h, '{{POST_TITLE}}', escapeHtml(post.title));
    h = cfseReplace(h, '{{POST_URL}}', cfsePostUrl(post, site));
    h = cfseReplace(h, '{{POST_DATE}}', escapeHtml(String(post.date || '').slice(0, 10)));
    h = cfseReplace(h, '{{POST_EXCERPT}}', escapeHtml(post.excerpt || ''));
    h = cfseReplace(h, '{{POST_CATEGORIES}}', catLinks);
    h = cfseReplace(h, '{{POST_TAGS}}', tagLinks);
    // 站点级占位符：条目模板里同样会出现站点链接（如作者归档 /author/<slug>/、站点首页）
    h = cfseReplace(h, '{{SITE_URL}}', (site && site.url) || '');
    h = cfseReplace(h, '{{SITE_NAME}}', (site && site.name) || '');
    h = cfseReplace(h, '{{SITE_DESC}}', (site && site.description) || '');
    return h;
}

// 拼接文章列表（不触发条目内分页逻辑）
function cfseRenderItems(itemTpl, posts, site) {
    return (posts || []).map(p => cfseRenderItem(itemTpl, p, site)).join('\n');
}

// ============================================
// 动态侧边栏三块（数据由 routes.js 传入 options.sidebar）
// ============================================
const CFSE_MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];

// 分类列表 <li>（class 与 WP categories widget 一致，不含数字 term_id）
function cfseSidebarCategoryItems(categories, site) {
    if (!Array.isArray(categories)) return '';
    const baseUrl = (site && site.url) || '';
    return categories.map(c => {
        const name = escapeHtml(c.name);
        const slug = String(c.slug || '');
        return '<li class="cat-item"><a href="' + baseUrl + '/category/' + slug + '/">' + name + '</a></li>';
    }).join('\n');
}

// 归档月份 <li>（格式同 WP archives widget：March 2006 → /2006/03/）
function cfseSidebarArchiveItems(archives, site) {
    if (!Array.isArray(archives)) return '';
    const baseUrl = (site && site.url) || '';
    return archives.map(a => {
        const ym = String(a.ym || '');
        const m = ym.match(/^(\d{4})-(\d{2})$/);
        if (!m) return '';
        const year = m[1];
        const month = parseInt(m[2], 10);
        const label = CFSE_MONTH_NAMES[month - 1] + ' ' + year;
        return '<li><a href="' + baseUrl + '/' + year + '/' + (month < 10 ? '0' + month : month) + '/">' + label + '</a></li>';
    }).filter(Boolean).join('\n');
}

// 标签云 <li>（字号按 WP 算法：round(log10(count+1)*100) 后线性映射 8~22pt；
// tag-link-position-N 为排序序号，aria-label 与 WP 一致）
function cfseSidebarTagItems(tags, site) {
    if (!Array.isArray(tags) || tags.length === 0) return '';
    const baseUrl = (site && site.url) || '';
    const scaled = (tags || []).map(t => {
        const count = parseInt(t.cnt, 10) || 0;
        return { tag: t, s: Math.round(Math.log10(count + 1) * 100) };
    });
    const minS = Math.min.apply(null, scaled.map(x => x.s));
    const maxS = Math.max.apply(null, scaled.map(x => x.s));
    const spread = (maxS - minS) > 0 ? (maxS - minS) : 1;
    return scaled.map((x, i) => {
        const count = parseInt(x.tag.cnt, 10) || 0;
        const size = 8 + (x.s - minS) * 14 / spread;
        const name = escapeHtml(x.tag.name);
        const aria = name + ' (' + count + (count === 1 ? ' item' : ' items') + ')';
        return '<li><a href="' + baseUrl + '/tag/' + String(x.tag.slug || '') + '/" class="tag-cloud-link tag-link-position-' + (i + 1) + '" style="font-size: ' + size + 'pt;" aria-label="' + aria + '">' + name + '</a></li>';
    }).join('\n');
}
JS;
}

/**
 * 构建单个渲染函数：页面壳模板 + 条目模板 → JS export function
 */
function cfse_build_template_function($func_name, $page_html, $item_html, $type) {
    $page_js = json_encode($page_html, JSON_UNESCAPED_UNICODE);
    $item_js = json_encode($item_html, JSON_UNESCAPED_UNICODE);
    $archive_extra = ($type === 'archive')
        ? '    const year = options.year || "";' . "\n"
        . '    const month = options.month || "";' . "\n"
        . '    const pageTitle = (year ? year + (month ? " 年 " + month + " 月" : " 年") : "归档");' . "\n"
        . '    const pageVar = pageTitle;'
        : '    const pageVar = "";';
    $title_expr = '    const site = (options && options.site) || {};' . "\n"
        . '    const titleName = options.name || options.slug || site.name || "";';

    return <<<JSFUNC
export function {$func_name}(data, page, totalPosts, options = {}) {
    const posts = data.posts || [];
    const totalPages = data.totalPages || 1;
    const currentPage = data.currentPage || 1;
    const baseUrl = options.baseUrl || "/";
{$title_expr}
{$archive_extra}

    const pageTpl = (options && options.pageTpl) || {$page_js};
    const itemTpl = (options && options.itemTpl) || {$item_js};

    const postListHtml = posts.length > 0
        ? cfseRenderItems(itemTpl, posts, site)
        : ((options && options.emptyHtml) ? options.emptyHtml : '');
    const pagPrefix = (options && options.paginationBase) ? options.paginationBase : (baseUrl + 'page/');
    const pagSuffix = (options && options.paginationBase) ? '' : '/';
    const prevHtml = currentPage > 1 ? `<a href="\${pagPrefix}\${currentPage - 1}\${pagSuffix}">← Older posts</a>` : '';
    const nextHtml = currentPage < totalPages ? `<a href="\${pagPrefix}\${currentPage + 1}\${pagSuffix}">Newer posts →</a>` : '';

    let html = pageTpl;
    html = cfseReplace(html, '{{SITE_NAME}}', site.name || '');
    html = cfseReplace(html, '{{SITE_DESC}}', site.description || '');
    html = cfseReplace(html, '{{SITE_URL}}', site.url || '');
    html = cfseReplace(html, '{{CONTENT}}', postListHtml);
    html = cfseReplace(html, '{{PAGINATION_PREV}}', prevHtml);
    html = cfseReplace(html, '{{PAGINATION_NEXT}}', nextHtml);
    html = cfseReplace(html, '{{TOTAL_POSTS}}', String(totalPosts));
    html = cfseIfBlock(html, 'hasPage', page > 1);
    html = cfseReplace(html, '{{PAGE_NUMBER}}', String(page));
    html = cfseReplace(html, '{{SITE_FOOTER}}', site.footerHtml || '');

    // TITLE / 归档年月
    if (typeof year !== 'undefined' && year !== null) {
        html = cfseReplace(html, '{{YEAR}}', year);
        html = cfseReplace(html, '{{MONTH}}', month);
        html = cfseReplace(html, '{{TITLE}}', pageTitle);
    } else {
        html = cfseReplace(html, '{{TITLE}}', titleName);
    }

    // 动态侧边栏三块（模板里没有 {{SIDEBAR_*}} 占位符时自动跳过，兼容旧模板）
    const sb = (options && options.sidebar) || {};
    html = cfseReplace(html, '{{SIDEBAR_CATEGORIES}}', cfseSidebarCategoryItems(sb.categories, site));
    html = cfseReplace(html, '{{SIDEBAR_ARCHIVES}}', cfseSidebarArchiveItems(sb.archives, site));
    html = cfseReplace(html, '{{SIDEBAR_TAGS}}', cfseSidebarTagItems(sb.tags, site));

    return html;
}
JSFUNC;
}

function cfse_log_message($message, $level = 'INFO') {
    if (class_exists('CF_Static_Export_Pro')) {
        $plugin = CF_Static_Export_Pro::get_instance();
        if (method_exists($plugin, 'log')) {
            $plugin->log($message, $level);
            return;
        }
    }
    error_log("[CFSE] [{$level}] {$message}");
}

// ============================================
// 6. 「抓取外观」自动生成
//    探针文章（带分类/标签）→ 抓原站首页 → 清洗成 页面壳 + 条目模板 → 备份后写四个页签
// ============================================
function cfse_handle_fetch_skin() {
    if (!current_user_can('manage_options')) wp_die('权限不足');
    check_admin_referer('cf_fetch_skin');
    @set_time_limit(180);
    list($ok, $logs) = cfse_fetch_skin_from_wordpress();
    foreach ($logs as $line) {
        cfse_log_message($line, $ok ? 'INFO' : 'WARNING');
    }
    wp_redirect(add_query_arg(array('fetched' => $ok ? '1' : '0'), admin_url('options-general.php?page=cf-template-editor')));
    exit;
}

/**
 * 「抓取外观 / 生成风格」v2：用探针文章 + **内部渲染**（每种页面一个独立进程）拿到页面 HTML，
 * 再用探针内容 token（标题/正文/摘要/日期/分类名/标签名/permalink）定位结构，生成 6 类模板。
 *
 * 相比旧流程（HTTP 抓前台 + twentyten 类名正则）：
 *   - 不依赖主题的 CSS 类名 / 注释锚，换主题也能生成；
 *   - 不经过 HTTP、不受页面缓存干扰；渲染进程里没有登录用户，不会把 admin bar 的 CSS/JS 带进来；
 *   - 是 per-PHP-进程 的 WP load-once 语义要求"每种页面一个新进程"（load_template 是 require_once）。
 * 任何一步失败都会自动回退旧流程 cfse_fetch_skin_from_wordpress_legacy()。
 */
function cfse_fetch_skin_from_wordpress() {
    $logs = array();
    $probe_id = 0;
    try {
        if (!function_exists('cfse_skin_render_all') || !function_exists('cfse_skin_map_templates')) {
            throw new Exception('skin-builder.php 未加载（检查 views/skin-builder.php）');
        }

        // 1. 探针要挂的分类 / 标签（全部取自真实存在的 term，避免制造垃圾 term）
        $cat_ids = array();
        $cat_names = array();
        $cats = get_terms(array('taxonomy' => 'category', 'hide_empty' => false, 'number' => 5));
        if (!is_wp_error($cats) && !empty($cats)) {
            foreach (array_slice($cats, 0, 2) as $t) {
                $cat_ids[] = (int) $t->term_id;
                $cat_names[] = (string) $t->name;
            }
        }
        $tag_names = array();
        $tags = get_terms(array('taxonomy' => 'post_tag', 'hide_empty' => false, 'number' => 5));
        if (!is_wp_error($tags) && !empty($tags)) {
            foreach (array_slice($tags, 0, 2) as $t) {
                $tag_names[] = (string) $t->name;
            }
        }

        // 2. token 探针（日期 = 现在，保证它出现在首页/归档第一页第一条；_cfse_probe 让主插件跳过导出）
        $token = 'CFSEPROBE' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
        $author = !empty($admins) ? (int) $admins[0] : 1;
        $probe_id = wp_insert_post(array(
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_author'   => $author,
            'post_title'    => $token . '-TITLE',
            'post_content'  => '<p>' . $token . '-CONTENT</p>',
            'post_excerpt'  => $token . '-EXCERPT',
            'post_category' => $cat_ids,
            'tags_input'    => $tag_names,
            'meta_input'    => array('_cfse_probe' => '1'),
        ));
        if (is_wp_error($probe_id) || !$probe_id) {
            throw new Exception('探针文章创建失败: ' . (is_wp_error($probe_id) ? $probe_id->get_error_message() : '未知错误'));
        }
        $logs[] = '探针文章已发布 #' . $probe_id . '（token ' . $token . '）';

        // 3. 内部渲染：首页/分类/标签/归档/单页各一个进程
        @set_time_limit(180);
        $html = cfse_skin_render_all($probe_id, home_url());
        $logs[] = '内部渲染：' . (empty($html) ? '全部失败' : implode(', ', array_keys($html)));
        foreach ((array) (isset($GLOBALS['cfse_skin_render_fails']) ? $GLOBALS['cfse_skin_render_fails'] : array()) as $ft => $why) {
            $logs[] = '渲染失败 ' . $ft . ': ' . $why;
        }
        foreach (array('home', 'category', 'tag', 'archive', 'single') as $need) {
            if (empty($html[$need])) {
                $why = isset($GLOBALS['cfse_skin_render_fails'][$need]) ? $GLOBALS['cfse_skin_render_fails'][$need] : '未知原因';
                throw new Exception('内部渲染缺少 ' . $need . ' 页面（' . $why . '）');
            }
        }

        // 4. token 驱动结构映射
        $probe_post = get_post($probe_id);
        $ctx = array(
            'token'       => $token,
            'probe_id'    => $probe_id,
            'probe_title' => $token . '-TITLE',
            'permalink'   => get_permalink($probe_id),
            'post_date'   => $probe_post ? $probe_post->post_date : current_time('mysql'),
            'cat_names'   => $cat_names,
            'tag_names'   => $tag_names,
            'site_url'    => home_url(),
            'site_name'   => get_bloginfo('name'),
            'site_desc'   => get_bloginfo('description'),
            'html'        => $html,
        );
        $tpls = cfse_skin_map_templates($ctx);
        foreach ((array) $tpls['warns'] as $w) {
            $logs[] = $w;
        }
        if (empty($tpls['pageHome']) || empty($tpls['item']) || empty($tpls['single'])) {
            throw new Exception('映射结果不完整（pageHome / item / single 至少一项为空）');
        }

        // 5. 写入四个页签 + 单页（option 结构与旧版一致，下游不变）
        $logs[] = '旧模板已备份到 option: ' . cfse_backup_custom_templates();
        $custom = get_option('cfse_custom_templates', array());
        if (!is_array($custom)) $custom = array();
        $custom['homepage'] = array('page' => $tpls['pageHome'],     'item' => $tpls['item']);
        $custom['category'] = array('page' => $tpls['pageCategory'], 'item' => $tpls['item']);
        $custom['tag']      = array('page' => $tpls['pageTag'],      'item' => $tpls['item']);
        $custom['archive']  = array('page' => $tpls['pageArchive'],  'item' => $tpls['item']);
        $custom['single']   = array('page' => $tpls['single'],       'item' => '');
        update_option('cfse_custom_templates', $custom);
        $logs[] = '模板已写入：首页 / 分类 / 标签 / 归档（共用同一份条目模板）+ 文章单页';

        // 6. 清理探针
        wp_delete_post($probe_id, true);
        $logs[] = '探针文章已删除（含 _cfse_probe 标记，未进 R2/D1）';
        $probe_id = 0;

        // 7. 重新生成 templates.js（沿用旧下游）
        if (!cfse_generate_templates_from_custom()) {
            throw new Exception('templates.js 重新生成失败（模板已写入，可在编辑器点「保存模板」重试）');
        }
        $logs[] = 'templates.js 已重新生成（回退基线）';

        // 8. 直接产出风格包（tpl.json + 主题资源）：线上渲染走的是 tpl:active / tpl.json，
        //    所以抓取完即可得到风格包，无需再手动点「快照为新风格」；同名覆盖，不堆 theme-2/3…
        $pkg = cfse_create_style_package(get_bloginfo('name') . ' - ' . get_option('stylesheet', 'theme'), false);
        if (!empty($pkg['ok'])) {
            foreach ((array) $pkg['logs'] as $x) { $logs[] = $x; }
        } else {
            foreach ((array) $pkg['logs'] as $x) { $logs[] = '风格包: ' . $x; }
            $logs[] = '风格包自动生成失败（模板已写入 cfse_custom_templates；常见原因是 styles/ 目录不可写，可在编辑器点「📦 快照为新风格」重试）';
        }
        return array(true, $logs);
    } catch (Throwable $e) {
        if ($probe_id) {
            wp_delete_post($probe_id, true);
            $logs[] = '异常路径已清理探针文章 #' . $probe_id;
        }
        $logs[] = '内部渲染链路失败：' . $e->getMessage();
        $logs[] = '—— 自动回退旧抓取流程 ——';
        list($ok, $legacy_logs) = cfse_fetch_skin_from_wordpress_legacy();
        return array($ok, array_merge($logs, $legacy_logs));
    }
}

/**
 * 旧版抓取流程（HTTP 抓原站首页/探针单页 + 主题类名正则清洗）。
 * 保留为 v2 失败时的自动回退路径。
 */
function cfse_fetch_skin_from_wordpress_legacy() {
    $logs = array();
    $probe_id = 0;
    try {
        // 1. 探针要挂的分类与标签（全部取自真实存在的 term，避免残留垃圾）
        $cat_ids = array();
        $cats = get_terms(array('taxonomy' => 'category', 'hide_empty' => false, 'number' => 5));
        if (!is_wp_error($cats) && !empty($cats)) {
            $cats = array_slice($cats, 0, 2);
            foreach ($cats as $t) {
                $cat_ids[] = (int) $t->term_id;
            }
        }
        $tag_names = array('cloudflare', 'nginx');
        $tags = get_terms(array('taxonomy' => 'post_tag', 'hide_empty' => false, 'number' => 5, 'fields' => 'names'));
        if (!is_wp_error($tags) && !empty($tags)) {
            $tag_names = array_slice(array_values($tags), 0, 2);
        }
        // 2. 发布探针文章（meta _cfse_probe 使主插件跳过 R2/D1 导出）
        $probe_title = 'CFSE 外观探针 ' . gmdate('YmdHis');
        $probe_body = '<p>外观探针正文 ' . wp_generate_password(10, false) . '，仅用于自动识别页面模板结构，抓取后立即删除。</p>';
        $probe_id = wp_insert_post(array(
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_title'    => $probe_title,
            'post_content'  => $probe_body,
            'post_excerpt'  => '外观探针摘要文本。',
            'post_category' => $cat_ids,
            'tags_input'    => $tag_names,
            'meta_input'    => array('_cfse_probe' => '1'),
        ));
        if (is_wp_error($probe_id) || !$probe_id) {
            throw new Exception('探针文章创建失败: ' . (is_wp_error($probe_id) ? $probe_id->get_error_message() : '未知错误'));
        }
        $logs[] = '探针文章已发布 #' . $probe_id;
        $probe_url = get_permalink($probe_id);
        // 3. 抓原站首页（_cfse_internal 避免插件参与前台渲染；随机参数绕过中间层缓存）
        sleep(1);
        $fetch_url = add_query_arg(array('_cfse_internal' => '1', 'cfse_skin_probe' => $probe_id, 't' => time()), home_url('/'));
        $resp = wp_remote_get($fetch_url, array(
            'timeout'    => 60,
            'sslverify'  => false,
            'headers'    => array('User-Agent' => 'CF-Static-Export-Pro-Skin/2.6', 'X-CF-Export-Internal' => '1'),
        ));
        if (is_wp_error($resp)) {
            throw new Exception('抓取原站失败: ' . $resp->get_error_message());
        }
        $html = wp_remote_retrieve_body($resp);
        if (strlen($html) < 5000) {
            throw new Exception('抓取内容异常（过短），模板未修改');
        }
        $logs[] = '原站首页抓取成功（' . strlen($html) . ' 字节）';
        if (strpos($html, 'id="post-' . $probe_id . '"') === false) {
            throw new Exception('探针文章未出现在首页列表（可能存在页面缓存或置顶干扰），请稍后重试。模板未被修改。');
        }
        // 4. 清洗成「页面壳 + 条目模板」
        list($page_tpl, $item_tpl, $warns) = cfse_build_templates_from_home_html($html, $probe_id, $probe_url);
        $logs = array_merge($logs, $warns);
        // 5. 备份旧模板 → 写四个页签
        $logs[] = '旧模板已备份到 option: ' . cfse_backup_custom_templates();
        $custom = get_option('cfse_custom_templates', array());
        foreach (array('homepage', 'category', 'tag', 'archive') as $tab) {
            $page = $page_tpl;
            if ($tab !== 'homepage') {
                // 分类/标签/归档页在正文区上方补页面标题行（区分页面类型，占位 {{TITLE}} 渲染时填充）
                $page = str_replace('{{CONTENT}}', "<div class=\"archive-page-head\"><h1 class=\"page-title\">{{TITLE}}</h1></div>\n{{CONTENT}}", $page);
            }
            $custom[$tab] = array('page' => $page, 'item' => $item_tpl);
        }
        update_option('cfse_custom_templates', $custom);
        $logs[] = '四个页签模板已写入（同一页面壳，各页签可再独立微调）';

        // 5.5 抓取探针单页 → 生成「文章单页」壳模板（供 Worker 发布文章时渲染静态单页；失败不影响四页签）
        try {
            $single_url = add_query_arg(array('_cfse_internal' => '1', 'cfse_skin_probe' => $probe_id, 't' => time()), $probe_url);
            $resp_single = wp_remote_get($single_url, array(
                'timeout'   => 60,
                'sslverify' => false,
                'headers'   => array('User-Agent' => 'CF-Static-Export-Pro-Skin/2.6', 'X-CF-Export-Internal' => '1'),
            ));
            $single_html = is_wp_error($resp_single) ? '' : wp_remote_retrieve_body($resp_single);
            if (strlen($single_html) > 5000 && strpos($single_html, 'id="post-' . $probe_id . '"') !== false) {
                list($single_tpl, $swarns) = cfse_build_single_template_from_html($single_html, $probe_id);
                $custom_again = get_option('cfse_custom_templates', array());
                $custom_again['single'] = array('page' => $single_tpl, 'item' => '');
                update_option('cfse_custom_templates', $custom_again);
                $logs[] = '单页壳模板已抓取生成（模板编辑器新增「📄 文章单页」页签可查看）';
                $logs = array_merge($logs, $swarns);
            } else {
                $logs[] = '提示：探针单页抓取不完整，保留原 single 模板（若未生成则使用极简兜底壳）';
            }
        } catch (Throwable $e) {
            $logs[] = '单页壳抓取失败（不影响四页签）: ' . $e->getMessage();
        }

        // 6. 清理探针文章
        wp_delete_post($probe_id, true);
        $logs[] = '探针文章已删除（因 _cfse_probe 标记，未污染 R2/D1）';
        $probe_id = 0;
        // 7. 重新生成 worker/src/templates.js
        if (!cfse_generate_templates_from_custom()) {
            throw new Exception('templates.js 重新生成失败（四个页签已写入，可去编辑器点「保存模板」重试生成）');
        }
        $logs[] = 'templates.js 已重新生成，待后台「初始化部署」推送到 Cloudflare Worker';
        return array(true, $logs);
    } catch (Throwable $e) {
        if ($probe_id) {
            wp_delete_post($probe_id, true);
            $logs[] = '异常路径已清理探针文章 #' . $probe_id;
        }
        $logs[] = '抓取失败: ' . $e->getMessage();
        return array(false, $logs);
    }
}

/**
 * 备份当前模板（保留最近 5 份）
 */
function cfse_backup_custom_templates() {
    $backups = get_option('cfse_custom_templates_backup', array());
    if (!is_array($backups)) $backups = array();
    $backups[gmdate('Ymd_His')] = get_option('cfse_custom_templates', array());
    while (count($backups) > 5) {
        array_shift($backups);
    }
    update_option('cfse_custom_templates_backup', $backups);
    return 'cfse_custom_templates_backup';
}

/**
 * 把抓到的原站首页 HTML 清洗成：
 *   - page：整页壳（正文列表 → {{CONTENT}}，分页 → {{PAGINATION_*}}，侧边栏三块 → {{SIDEBAR_*}}）
 *   - item：探针文章条目 → 占位符条目模板
 * 依赖 twentyten（或同类带 #nav-above / #post-N / entry-* 与 cat-links 等注释结构的主题）可预期输出。
 *
 * @param string      $html       抓取的原站首页 HTML
 * @param int         $probe_id   探针文章 ID（条目定位锚）
 * @param string|null $probe_url  探针文章固定链接
 * @param string|null $base_url   站点根地址（默认取 home_url()）
 * @return array [pageTpl, itemTpl, warns]
 */
function cfse_build_templates_from_home_html($html, $probe_id, $probe_url = null, $base_url = null) {
    $warns = array();
    if ($base_url === null) {
        $base_url = function_exists('home_url') ? home_url() : '';
    }
    $base = rtrim((string) $base_url, '/');
    $probe_id = (int) $probe_id;

    // ---- 0. 站点 URL / 站点名占位化 ----
    if ($base !== '') {
        $html = str_replace($base, '{{SITE_URL}}', $html);
    }
    if (function_exists('get_bloginfo')) {
        $site_name = (string) get_bloginfo('name');
        $variants = array_unique(array($site_name, htmlspecialchars($site_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
        foreach ($variants as $v) {
            if ($v !== '') $html = str_replace($v, '{{SITE_NAME}}', $html);
        }
    }
    $html = str_replace('?_cfse_internal=1', '', $html);
    $html = str_replace('&_cfse_internal=1', '', $html);

    // ---- 1. 抽取探针条目原始片段（<div id="post-N"> … </div><!-- #post-N -->）----
    $start_marker = '<div id="post-' . $probe_id . '"';
    $start = strpos($html, $start_marker);
    if ($start === false) {
        throw new Exception('在抓取结果中找不到探针条目容器');
    }
    $end_marker = '<!-- #post-' . $probe_id . ' -->';
    $end = strpos($html, $end_marker, $start);
    if ($end === false) {
        throw new Exception('探针条目缺少结束注释，无法安全切片');
    }
    $item_raw = substr($html, $start, $end - $start);

    // ---- 2. 条目模板化 ----
    $item = $item_raw;
    if ($probe_url) {
        // HTML 中 href 是 WP 编码形态（如 cfse-%e5%a4%96...），保持原样匹配，勿 rawurldecode；
        // 即便此处匹配不上，上方标题行正则会强制把 href 替换为 {{POST_URL}}（兜底）
        $slug = trim((string) parse_url($probe_url, PHP_URL_PATH), '/');
        $item = str_replace('{{SITE_URL}}/' . $slug . '/', '{{POST_URL}}', $item);
        $item = str_replace($probe_url, '{{POST_URL}}', $item);
    }
    $item = preg_replace('#(<h2 class="entry-title"><a href=")[^"]*(" rel="bookmark">).*?(</a></h2>)#s', '$1{{POST_URL}}$2{{POST_TITLE}}$3', $item, 1);
    $item = preg_replace(
        '#(<div class="entry-meta">).*?(</div><!-- \.entry-meta -->)#s',
        '<div class="entry-meta"><span class="meta-prep meta-prep-author">Posted on</span> <a href="{{POST_URL}}" rel="bookmark"><span class="entry-date">{{POST_DATE}}</span></a></div><!-- .entry-meta -->',
        $item,
        1
    );
    $item = preg_replace(
        '#(<div class="entry-content">).*?(</div><!-- \.entry-content -->)#s',
        '<div class="entry-content">{{POST_EXCERPT}}</div><!-- .entry-content -->',
        $item,
        1
    );
    $item = preg_replace(
        '#(<div class="entry-utility">).*?(</div><!-- \.entry-utility -->)#s',
        '<div class="entry-utility">{{#IF_CATEGORIES}}<span class="cat-links"><span class="entry-utility-prep entry-utility-prep-cat-links">Posted in</span> {{POST_CATEGORIES}}</span>{{/IF_CATEGORIES}} {{#IF_TAGS}}<span class="meta-sep">|</span> <span class="tag-links"><span class="entry-utility-prep entry-utility-prep-tag-links">Tagged</span> {{POST_TAGS}}</span>{{/IF_TAGS}}</div><!-- .entry-utility -->',
        $item,
        1
    );
    $item = preg_replace('#<div id="post-\d+" class="[^"]*">#', '<div class="post type-post status-publish format-standard hentry">', $item, 1);
    $item = trim($item);
    if ($item === '' || strpos($item, '{{POST_TITLE}}') === false) {
        throw new Exception('条目模板清洗异常（缺少 {{POST_TITLE}}），模板未修改');
    }

    // ---- 3. 页面壳手术 ----
    // 分页导航容器 → 固定双占位结构（首页第 1 页没有 Newer posts 时 WP 不输出 nav-next div，
    // 这里统一补全，使翻到第 2+ 页时「Newer posts」也能正常显示）
    $html = preg_replace_callback(
        '~(<div id="nav-(above|below)" class="navigation">).*?(</div><!-- #nav-\2 -->)~s',
        function ($m) {
            return $m[1] . "\n\t\t\t" . '<div class="nav-previous">{{PAGINATION_PREV}}</div>' . "\n\t\t\t" . '<div class="nav-next">{{PAGINATION_NEXT}}</div>' . "\n\t\t" . $m[3];
        },
        $html
    );
    if (strpos($html, '{{PAGINATION_PREV}}') === false) {
        $warns[] = '提示：未找到分页导航容器（#nav-above / #nav-below），页面壳将不含分页占位符';
    }
    // 挖掉正文列表区 → {{CONTENT}}（从 nav-above 注释后 到 nav-below 前）
    $anchor = '<!-- #nav-above -->';
    $p1 = strpos($html, $anchor);
    if ($p1 !== false) {
        $p1 += strlen($anchor);
        $p2 = strpos($html, '<div id="nav-below"', $p1);
        if ($p2 === false) {
            $p2 = strpos($html, '<!-- #content -->', $p1);
        }
        if ($p2 === false) {
            $p2 = strlen($html);
        }
        $html = substr($html, 0, $p1) . "\n\t{{CONTENT}}\n" . substr($html, $p2);
    } else {
        $warns[] = '提示：页面壳中未找到 #nav-above 注释，未自动放置 {{CONTENT}}（请手动在正文列表位置放置）';
    }
    // 侧边栏三块 → 动态占位
    $cnt = 0;
    $html = preg_replace('#(<ul class="[^"]*wp-block-categories-list[^"]*"[^>]*>).*?(</ul>)#s', '$1{{SIDEBAR_CATEGORIES}}$2', $html, -1, $cnt);
    if (!$cnt) $warns[] = '提示：未找到分类列表容器（ul.wp-block-categories-list），页面壳缺少 {{SIDEBAR_CATEGORIES}}';
    $cnt = 0;
    $html = preg_replace('#(<li id="archives-2"[^>]*>.*?<ul>).*?(</ul>)#s', '$1{{SIDEBAR_ARCHIVES}}$2', $html, -1, $cnt);
    if (!$cnt) {
        $html = preg_replace('#(<li[^>]*class="[^"]*widget_archive[^"]*"[^>]*>.*?<ul>).*?(</ul>)#s', '$1{{SIDEBAR_ARCHIVES}}$2', $html, -1, $cnt);
    }
    if (!$cnt) $warns[] = '提示：未找到归档列表容器（#archives-2 / .widget_archive），页面壳缺少 {{SIDEBAR_ARCHIVES}}';
    $cnt = 0;
    $html = preg_replace("#(<ul class=['\"]wp-tag-cloud[^>]*>).*?(</ul>)#s", '$1{{SIDEBAR_TAGS}}$2', $html, -1, $cnt);
    if (!$cnt) $warns[] = '提示：未找到标签云容器（ul.wp-tag-cloud），页面壳缺少 {{SIDEBAR_TAGS}}';

    return array($html, $item, $warns);
}

/**
 * 「♻️ 恢复」处理：把某个备份快照恢复为当前四个页签模板
 */
function cfse_handle_restore_templates() {
    if (!current_user_can('manage_options')) wp_die('权限不足');
    check_admin_referer('cf_restore_templates');
    $ts = isset($_GET['ts']) ? sanitize_text_field(wp_unslash($_GET['ts'])) : '';
    $ok = cfse_restore_templates_snapshot($ts);
    wp_redirect(add_query_arg(array('restored' => $ok ? '1' : '0'), admin_url('options-general.php?page=cf-template-editor')));
    exit;
}

/**
 * 恢复指定时间戳的备份快照（恢复前先备份当前模板，防止误操作）
 *
 * @param string $ts 备份时间戳（gmdate('Ymd_His')）
 * @return bool
 */
function cfse_restore_templates_snapshot($ts) {
    $backups = get_option('cfse_custom_templates_backup', array());
    if (!is_array($backups) || $ts === '' || !isset($backups[$ts]) || !is_array($backups[$ts])) {
        return false;
    }
    cfse_backup_custom_templates(); // 恢复前先留底当前模板
    update_option('cfse_custom_templates', $backups[$ts]);
    if (!cfse_generate_templates_from_custom()) {
        return false;
    }
    cfse_log_message('模板已从备份恢复: ' . $ts, 'INFO');
    return true;
}

// ============================================
// 7. 风格包（styles）：快照 / 激活 / 退出
// ============================================

/** 递归复制目录（跳过隐藏/无关文件） */
function cfse_dir_copy($src, $dst) {
    if (!is_dir($src)) return false;
    if (!is_dir($dst)) wp_mkdir_p($dst);
    $ok = true;
    $items = scandir($src);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.git' || $item === '.svn' || $item === '.DS_Store') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            $ok = cfse_dir_copy($s, $d) && $ok;
        } else {
            $ok = copy($s, $d) && $ok;
        }
    }
    return $ok;
}

/** 递归删除目录 */
function cfse_dir_remove($dir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . '/' . $item;
        if (is_dir($p) && !is_link($p)) {
            cfse_dir_remove($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

/** 「📸 快照当前模板为新风格」 */
/**
 * 导出风格包 JSON：{name, tpl:{single,item,pageHome,pageCategory,pageTag,pageArchive}}
 * 可直接导入 Worker 后台「模板」页（风格包 → 导入 JSON）。
 */
function cfse_handle_export_style() {
    if (!current_user_can('manage_options')) wp_die('无权限');
    check_admin_referer('cf_export_style');
    $ct = get_option('cfse_custom_templates', array());
    $get = function ($tab, $field) use ($ct) {
        $v = isset($ct[$tab]) ? $ct[$tab] : null;
        if (is_string($v)) return ($field === 'page') ? $v : '';
        return ($v && isset($v[$field]) && $v[$field] !== '') ? (string) $v[$field] : '';
    };
    $item = $get('homepage', 'item');
    if ($item === '') $item = $get('category', 'item');
    if ($item === '') $item = $get('tag', 'item');
    if ($item === '') $item = $get('archive', 'item');
    $payload = array(
        'name' => 'WP 导出 ' . gmdate('Y-m-d H:i'),
        'tpl' => array(
            'single' => $get('single', 'page'),
            'item' => $item,
            'pageHome' => $get('homepage', 'page'),
            'pageCategory' => $get('category', 'page'),
            'pageTag' => $get('tag', 'page'),
            'pageArchive' => $get('archive', 'page'),
        ),
    );
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="cfse-style-' . gmdate('Ymd-His') . '.json"');
    echo $json;
    exit;
}

function cfse_create_style_package($display_name = '', $unique_suffix = true) {
    @set_time_limit(120);
    $logs = array();
    $style_root_dir = CFSE_PLUGIN_DIR . 'styles';
    if (!wp_mkdir_p($style_root_dir) || !is_writable($style_root_dir)) {
        cfse_log_message('styles 目录不可写: ' . $style_root_dir, 'ERROR');
        $logs[] = 'styles 目录不可写: ' . $style_root_dir;
        return array('ok' => false, 'slug' => '', 'dir' => '', 'files' => 0, 'tpl' => array(), 'logs' => $logs);
    }

    // 主题 slug 唯一（同主题重复生成加后缀）
    $theme_slug = function_exists('get_option') ? get_option('stylesheet', 'theme') : 'theme';
    $base_slug = sanitize_title($theme_slug, 'theme', 'save');
    $slug = $base_slug;
    if ($unique_suffix) {
        $n = 2;
        while (is_dir($style_root_dir . '/' . $slug)) {
            $slug = $base_slug . '-' . $n;
            $n++;
        }
    }
    $dst_dir = $style_root_dir . '/' . $slug;
    if (!wp_mkdir_p($dst_dir) || !is_writable($dst_dir)) {
        cfse_log_message('风格包目录创建失败: ' . $dst_dir, 'ERROR');
        $logs[] = '风格包目录创建失败: ' . $dst_dir;
        return array('ok' => false, 'slug' => '', 'dir' => '', 'files' => 0, 'tpl' => array(), 'logs' => $logs);
    }

    // 1) tpl.json（6 类模板 JSON，与 Worker 导出格式一致；内置风格只读源头）
    $ct = get_option('cfse_custom_templates', array());
    $get = function ($tab, $field) use ($ct) {
        $v = isset($ct[$tab]) ? $ct[$tab] : null;
        if (is_string($v)) return ($field === 'page') ? $v : '';
        return ($v && isset($v[$field]) && $v[$field] !== '') ? (string) $v[$field] : '';
    };
    $item = $get('homepage', 'item');
    if ($item === '') $item = $get('category', 'item');
    if ($item === '') $item = $get('tag', 'item');
    if ($item === '') $item = $get('archive', 'item');
    $tpl_payload = array(
        'name' => $display_name !== '' ? $display_name : (get_bloginfo('name') . ' - ' . $theme_slug),
        'tpl' => array(
            'single' => $get('single', 'page'),
            'item' => $item,
            'pageHome' => $get('homepage', 'page'),
            'pageCategory' => $get('category', 'page'),
            'pageTag' => $get('tag', 'page'),
            'pageArchive' => $get('archive', 'page'),
        ),
    );
    // tpl.json 在下方 $dest_theme 就绪后写入（与主题资源同目录）

    // 2) 主题资源 → wp-content/themes/<slug>/（保留原生 WP 路径，Worker 端 assets 直出）
    $theme_dirs = array_unique(array(get_template_directory(), get_stylesheet_directory()));
    $dest_theme = $dst_dir . '/wp-content/themes/' . $slug;
    // 镜像一份到 Worker 的 static 资源目录：内置风格约定 tpl.json + 主题资源走 assets 直出，
    // 部署（wrangler deploy）后 Worker 才能 fetch /wp-content/themes/<slug>/tpl.json 应用该风格。
    $static_theme = CFSE_PLUGIN_DIR . 'worker/static/wp-content/themes/' . $slug;
    $mirrors = array($dest_theme, $static_theme);
    foreach ($mirrors as $mirror) {
        if (!wp_mkdir_p($mirror)) {
            cfse_log_message('风格资源目录创建失败: ' . $mirror, 'ERROR');
            $logs[] = '风格资源目录创建失败: ' . $mirror;
            return array('ok' => false, 'slug' => '', 'dir' => '', 'files' => 0, 'tpl' => array(), 'logs' => $logs);
        }
    }
    $tpl_json_str = wp_json_encode($tpl_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    foreach ($mirrors as $mirror) {
        file_put_contents($mirror . '/tpl.json', $tpl_json_str);
    }
    $allowed = array('css', 'js', 'mjs', 'json', 'map', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'txt');
    $max_bytes = 8 * 1024 * 1024;
    $copied_files = 0;
    foreach ($theme_dirs as $theme_dir) {
        if (!is_dir($theme_dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($theme_dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) continue;
            if ($file->getSize() > $max_bytes) continue;
            $rel = substr($file->getPathname(), strlen($theme_dir));
            $rel = ltrim($rel, '/\\');
            foreach ($mirrors as $mirror) {
                $rel_path = $mirror . '/' . $rel;
                if (!is_dir(dirname($rel_path))) wp_mkdir_p(dirname($rel_path));
                if (copy($file->getPathname(), $rel_path) && $mirror === $dest_theme) $copied_files++;
            }
        }
    }

    // 3) 同步内置风格索引到 Worker KV（部署后 Worker 后台可见）
    cfse_sync_builtin_styles();

    cfse_log_message('已创建风格包: ' . $slug . '（tpl.json + 主题资源 ' . $copied_files . ' 个，内置索引已同步）', 'INFO');
    $logs[] = '风格包已生成: styles/' . $slug . '（tpl.json + 主题资源 ' . $copied_files . ' 个，已同步内置索引）';
    $logs[] = '已镜像到 worker/static/wp-content/themes/' . $slug . '/（本地 wrangler deploy 后该内置风格即可用）';
    return array('ok' => true, 'slug' => $slug, 'dir' => $dst_dir, 'files' => $copied_files, 'tpl' => $tpl_payload, 'logs' => $logs);
}

/** 「快照为新风格」后台按钮：权限/nonce 检查 → 调用 cfse_create_style_package() → 跳转 */
function cfse_handle_create_style() {
    if (!current_user_can('manage_options')) wp_die('权限不足');
    check_admin_referer('cf_create_style');
    $style_name = isset($_GET['style_name']) ? sanitize_text_field(wp_unslash($_GET['style_name'])) : '';
    $res = cfse_create_style_package($style_name, true);
    if (empty($res['ok'])) {
        wp_redirect(add_query_arg(array('style_error' => '1'), admin_url('options-general.php?page=cf-template-editor')));
        exit;
    }
    wp_redirect(add_query_arg(array('style_created' => '1', 'style_id' => rawurlencode($res['slug'])), admin_url('options-general.php?page=cf-template-editor')));
    exit;
}

/**
 * 把 styles/ 目录下所有内置风格写入 Worker KV 索引（tpl:sindex-builtin，X-Auth-Key 鉴权）。
 * 部署后调用：扫描 styles/<slug>/wp-content/themes/<slug>/tpl.json → 调 Worker /api/templates/style/builtin-sync。
 */
function cfse_sync_builtin_styles() {
    $site_url = rtrim((string) get_option('cf_site_url', get_site_url()), '/');
    $auth = (string) get_option('cf_auth_key', '');
    if ($site_url === '' || $auth === '') return false;
    $styles_root = CFSE_PLUGIN_DIR . 'styles';
    $items = array();
    if (is_dir($styles_root)) {
        foreach (glob($styles_root . '/*', GLOB_ONLYDIR) as $dir) {
            $slug = basename($dir);
            $tpl_file = $dir . '/wp-content/themes/' . basename($dir) . '/tpl.json';
            if (!file_exists($tpl_file)) continue;
            $data = json_decode((string) file_get_contents($tpl_file), true);
            $name = (is_array($data) && isset($data['name'])) ? (string) $data['name'] : $slug;
            $mtime = filemtime($tpl_file);
            $items[] = array(
                'id' => $slug,
                'name' => $name,
                'updated' => $mtime ? gmdate('Y-m-d H:i:s', $mtime) : gmdate('Y-m-d H:i:s'),
            );
        }
    }
    if (empty($items)) return false;
    $resp = wp_remote_post($site_url . '/api/templates/style/builtin-sync', array(
        'timeout' => 30,
        'sslverify' => false,
        'headers' => array('X-Auth-Key' => $auth, 'Content-Type' => 'application/json'),
        'body' => wp_json_encode(array('styles' => $items)),
    ));
    if (is_wp_error($resp)) {
        cfse_log_message('内置风格索引同步失败: ' . $resp->get_error_message(), 'WARNING');
        return false;
    }
    cfse_log_message('内置风格索引已同步 ' . count($items) . ' 个到 Worker KV', 'INFO');
    return true;
}

/** 「▶ 激活风格」：把 styles/<id> 快照拷为 worker 主产物并置 active 标记 */
function cfse_handle_activate_style() {
    if (!current_user_can('manage_options')) wp_die('权限不足');
    check_admin_referer('cf_activate_style');
    $id = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';
    $style_dir = CFSE_PLUGIN_DIR . 'styles/' . $id;
    if ($id === '' || !is_dir($style_dir) || !file_exists($style_dir . '/templates.js')) {
        wp_redirect(add_query_arg(array('style_error' => '1'), admin_url('options-general.php?page=cf-template-editor')));
        exit;
    }

    // 1) templates.js → worker/src/
    if (!copy($style_dir . '/templates.js', CFSE_WORKER_DIR . 'templates.js')) {
        wp_redirect(add_query_arg(array('style_error' => '1'), admin_url('options-general.php?page=cf-template-editor')));
        exit;
    }
    // 2) static/ → worker/static（先清空旧 wp-content 再整体复制，避免残留其它主题资源）
    $static_dst = CFSE_PLUGIN_DIR . 'worker/static';
    if (is_dir($style_dir . '/static')) {
        if (is_dir($static_dst . '/wp-content')) {
            cfse_dir_remove($static_dst . '/wp-content');
        }
        cfse_dir_copy($style_dir . '/static', $static_dst);
    }

    update_option('cfse_active_style', $id);
    cfse_log_message('已激活风格包: ' . $id . '（请点「初始化部署」生效）', 'INFO');
    wp_redirect(add_query_arg(array('style_activated' => '1', 'style_id' => rawurlencode($id)), admin_url('options-general.php?page=cf-template-editor')));
    exit;
}

/** 「↩ 退出激活风格」，回到 WP 模板模式 */
function cfse_handle_style_off() {
    if (!current_user_can('manage_options')) wp_die('权限不足');
    check_admin_referer('cf_style_off');
    delete_option('cfse_active_style');
    cfse_log_message('已退出激活风格，回到 WP 模板模式', 'INFO');
    wp_redirect(add_query_arg(array('style_off' => '1'), admin_url('options-general.php?page=cf-template-editor')));
    exit;
}

/**
 * 把抓到的探针「文章单页」清洗成可复用单页壳模板：
 *   标题→{{POST_TITLE}}、日期→{{POST_DATE}}、正文区→{{POST_CONTENT}}，
 *   元信息区(entry-meta)→占位符、分类/标签区(entry-utility)→{{#IF_CATEGORIES}}/{{#IF_TAGS}} 条件块，
 *   并清理探针自身的 URL / 标题 / feed / oEmbed / shortlink 残留，
 *   移除评论区与上下篇导航；head/header/menu/sidebar/footer 保留（同抓壳首页）。
 * 依赖 twentyten（或同类带 entry-title/entry-content/#comments 注释结构的主题）。
 *
 * @param string $html     抓取的探针单页 HTML
 * @param int    $probe_id 探针文章 ID（校验/记录用）
 * @param string|null $base_url 站点根（默认 home_url()）
 * @return array [singleTpl, warns]
 */
function cfse_build_single_template_from_html($html, $probe_id = 0, $base_url = null) {
    $warns = array();
    if ($base_url === null) {
        $base_url = function_exists('home_url') ? home_url() : '';
    }
    $base = rtrim((string) $base_url, '/');
    if ($base !== '') {
        $html = str_replace($base, '{{SITE_URL}}', $html);
    }
    if (function_exists('get_bloginfo')) {
        $site_name = (string) get_bloginfo('name');
        $variants = array_unique(array($site_name, htmlspecialchars($site_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
        foreach ($variants as $v) {
            if ($v !== '') $html = str_replace($v, '{{SITE_NAME}}', $html);
        }
    }
    $html = str_replace('?_cfse_internal=1', '', $html);
    $html = str_replace('&_cfse_internal=1', '', $html);

    // 探针自身的 URL / 标题 / 动态端点 link 清理（避免探针数据残留进单页模板）
    if ($probe_id) {
        $probe_permalink = (string) get_permalink($probe_id);
        $probe_title = (string) get_the_title($probe_id);
        if ($probe_permalink !== '' && $base !== '' && strpos($probe_permalink, $base) === 0) {
            $probe_rel = trim(substr($probe_permalink, strlen($base)), '/'); // HTML 中的 slug 形态（已编码）
            if ($probe_rel !== '') {
                // 探针 comments feed link 删除（静态站无该路由）
                $html = preg_replace('~<link rel="alternate" type="application/rss+xml" title="[^"]*"[^>]*href="{{SITE_URL}}/' . preg_quote($probe_rel, '~') . '/feed/"[^>]*/>~', '', $html);
                // head/canonical/entry 内的探针 URL → {{POST_URL}}
                $html = str_replace('{{SITE_URL}}/' . $probe_rel . '/', '{{POST_URL}}', $html);
            }
        } else {
            $warns[] = '警告：无法从 permalink 解析探针 slug，探针 URL 可能残留';
        }
        // oEmbed / shortlink（静态站无 wp-json、无 ?p= 路由）
        $html = preg_replace('~<link rel="alternate" title="oEmbed [(](JSON|XML)[)]"[^>]*/>~', '', $html);
        $html = preg_replace("~<link rel='shortlink'[^>]*/>~", '', $html);
        if ($probe_title !== '') {
            $html = str_replace($probe_title, '{{POST_TITLE}}', $html);
        }
    } else {
        $warns[] = '提示：未传探针文章 ID，探针 URL/标题可能残留在模板中';
    }

    // 标题 → {{POST_TITLE}}
    $cnt = 0;
    $html = preg_replace('~(<h1 class="entry-title">).*?(</h1>)~s', '$1{{POST_TITLE}}$2', $html, 1, $cnt);
    if (!$cnt) $warns[] = '提示：单页未找到 h1.entry-title，未生成 {{POST_TITLE}}';

    // 发布日期 → {{POST_DATE}}
    $html = preg_replace('~(<span class="entry-date">).*?(</span>)~s', '$1{{POST_DATE}}$2', $html, 1, $cnt);
    if (!$cnt) $warns[] = '提示：单页未找到 span.entry-date，未生成 {{POST_DATE}}';

    // 正文区 → {{POST_CONTENT}}（保留外层 div.entry-content 与主题结构）
    $cnt = 0;
    $html = preg_replace('~(<div class="entry-content">).*?(</div><!-- \.entry-content -->)~s', '$1{{POST_CONTENT}}$2', $html, 1, $cnt);
    if (!$cnt) {
        $warns[] = '提示：单页未找到 entry-content（含 .entry-content 注释），请检查主题结构';
    }

    // 元信息区 → 简洁版占位符（与列表条目模板一致）
    $cnt = 0;
    $html = preg_replace('~(<div class="entry-meta">).*?(</div><!-- \.entry-meta -->)~s', '<div class="entry-meta"><span class="meta-prep meta-prep-author">Posted on</span> <a href="{{POST_URL}}" rel="bookmark"><span class="entry-date">{{POST_DATE}}</span></a></div><!-- .entry-meta -->', $html, 1, $cnt);
    if (!$cnt) $warns[] = '提示：单页未找到 entry-meta（含 .entry-meta 注释），元信息区可能残留';

    // 分类/标签区（entry-utility）→ 条件块占位符（渲染时按文章实际分类/标签填充）
    $cnt = 0;
    $html = preg_replace('~(<div class="entry-utility">).*?(</div><!-- \.entry-utility -->)~s', '<div class="entry-utility">{{#IF_CATEGORIES}}<span class="cat-links"><span class="entry-utility-prep entry-utility-prep-cat-links">Posted in</span> {{POST_CATEGORIES}}</span>{{/IF_CATEGORIES}} {{#IF_TAGS}}<span class="meta-sep">|</span> <span class="tag-links"><span class="entry-utility-prep entry-utility-prep-tag-links">Tagged</span> {{POST_TAGS}}</span>{{/IF_TAGS}}</div><!-- .entry-utility -->', $html, 1, $cnt);
    if (!$cnt) $warns[] = '提示：单页未找到 entry-utility（含 .entry-utility 注释），分类/标签可能残留';

    // 移除评论与回应区（静态站无评论；仅在存在注释锚时删除，保证不误伤）
    $c1 = 0; $c2 = 0;
    $html = preg_replace('~<div id="comments">.*?</div><!-- #comments -->~s', '', $html, 1, $c1);
    $html = preg_replace('~<div id="respond"[^>]*>.*?</div><!-- #respond -->~s', '', $html, 1, $c2);
    if (!$c1 && !$c2) {
        $warns[] = '提示：单页未发现评论/回应容器注释（或主题结构不同），评论区可能残留';
    }

    // 删除上下篇导航容器（带注释锚时；避免残留指向固定 slug 的链接）
    $html = preg_replace('~<div id="nav-(above|below)"[^>]*>.*?</div><!-- #nav-\1 -->~s', '', $html, -1, $navc);
    if (!$navc) {
        $warns[] = '提示：单页未发现上下篇导航注释容器（#nav-above/#nav-below），若存在上一篇/下一篇链接请手动清理';
    }

    if (strpos($html, '{{POST_CONTENT}}') === false) {
        $warns[] = '警告：单页壳缺少 {{POST_CONTENT}}，Worker 发布时正文将无法注入';
    }
    return array($html, $warns);
}
