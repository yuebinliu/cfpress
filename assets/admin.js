/**
 * CF Static Export Pro 管理JS
 */
jQuery(document).ready(function($) {
    // ============================================
    // 1. 测试连接（原有功能）
    // ============================================
    $('#cf-test-connection').on('click', function() {
        var $btn = $(this);
        var $result = $('#cf-test-result');
        var account_id = $('#cf_account_id').val();
        var api_token = $('#cf_api_token').val();
        
        if (!account_id || !api_token) {
            $result.html('❌ 请先填写Account ID和API Token').css('color', '#f44336');
            return;
        }
        
        $btn.prop('disabled', true);
        $result.html('⏳ 测试中...').css('color', '#666');
        
        $.ajax({
            url: cfse_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cf_test_connection',
                nonce: cfse_ajax.nonce,
                account_id: account_id,
                api_token: api_token
            },
            success: function(response) {
                if (response.success) {
                    $result.html('✅ ' + response.data).css('color', '#4CAF50');
                } else {
                    $result.html('❌ ' + response.data).css('color', '#f44336');
                }
            },
            error: function() {
                $result.html('❌ 请求失败，请检查网络连接').css('color', '#f44336');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    // ============================================
    // 2. 模板编辑器变量插入（新增）
    // ============================================
    $(document).on('click', '.cfse-insert-var', function() {
        var varText = $(this).data('var');
        var textarea = $('#template-editor');
        if (textarea.length === 0) return;
        
        var cursorPos = textarea.prop('selectionStart');
        var currentVal = textarea.val();
        var newVal = currentVal.substring(0, cursorPos) + varText + currentVal.substring(cursorPos);
        textarea.val(newVal);
        
        var newPos = cursorPos + varText.length;
        textarea.prop('selectionStart', newPos);
        textarea.prop('selectionEnd', newPos);
        textarea.focus();
    });

    // ============================================
    // 3. 模板预览（新增）
    // ============================================
    $(document).on('click', '#cf-preview-template', function() {
        var $btn = $(this);
        var $box = $('#cf-preview-box');
        var $content = $('#cf-preview-content');
        var textarea = $('#template-editor');
        var type = $('input[name="template_type"]').val();
        
        if (textarea.length === 0 || !type) return;
        
        $box.show();
        $content.html('⏳ 加载中...');
        $btn.prop('disabled', true);
        
        $.ajax({
            url: cfse_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cf_preview_template',
                nonce: cfse_ajax.nonce,
                type: type,
                content: textarea.val()
            },
            success: function(response) {
                if (response.success) {
                    $content.html(response.data.html);
                } else {
                    $content.html('<p style="color:red;">❌ 预览失败: ' + (response.data || '未知错误') + '</p>');
                }
            },
            error: function() {
                $content.html('<p style="color:red;">❌ 请求失败，请重试</p>');
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    // ============================================
    // 4. 关闭预览（新增）
    // ============================================
    $(document).on('click', '#cf-close-preview', function() {
        $('#cf-preview-box').hide();
    });
});