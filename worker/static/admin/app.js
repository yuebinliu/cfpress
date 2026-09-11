/* CFSE 管理后台 - 原生 JS（无框架） */
(function () {
  'use strict';

  var LS_A = 'cfse_access';
  var LS_R = 'cfse_refresh';

  function getA() { return localStorage.getItem(LS_A) || ''; }
  function setA(t) { localStorage.setItem(LS_A, t); }
  function getR() { return localStorage.getItem(LS_R) || ''; }
  function setR(t) { localStorage.setItem(LS_R, t); }
  function clearTokens() { localStorage.removeItem(LS_A); localStorage.removeItem(LS_R); }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  var flashTimer = null;
  function flash(el, msg, type) {
    var box = document.getElementById(el);
    if (!box) return;
    if (flashTimer) { clearTimeout(flashTimer); flashTimer = null; }
    if (!msg) { box.innerHTML = ''; box.className = 'flash'; return; }
    box.innerHTML = (type === 'ok' ? '✅ ' : '⚠️ ') + esc(msg);
    box.className = 'flash ' + (type === 'ok' ? 'ok' : 'err');
    // 自动消失：成功 3.5s、错误 6s
    flashTimer = setTimeout(function () { box.innerHTML = ''; box.className = 'flash'; flashTimer = null; }, type === 'ok' ? 3500 : 6000);
  }

  function refreshToken() {
    var r = getR();
    if (!r) return Promise.reject(new Error('no-refresh'));
    return fetch('/api/refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh: r }),
    }).then(function (res) { return res.json(); }).then(function (d) {
      if (d.access) { setA(d.access); return d.access; }
      throw new Error('refresh-fail');
    });
  }

  function api(path, opts) {
    opts = opts || {};
    var headers = Object.assign({}, opts.headers || {});
    var access = getA();
    if (access) headers['Authorization'] = 'Bearer ' + access;
    if (opts.body && typeof opts.body !== 'string' && !(opts.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.body);
    }
    var attempt = function (retried) {
      return fetch(path, Object.assign({}, opts, { headers: headers })).then(function (res) {
        if (res.status === 401 && !retried) {
          return refreshToken().then(function () {
            headers['Authorization'] = 'Bearer ' + getA();
            return attempt(true);
          }).catch(function () { clearTokens(); return Promise.reject(new Error('auth')); });
        }
        return res.json().catch(function () { return {}; }).then(function (data) {
          if (res.status >= 400) { var e = new Error(data.error || ('HTTP ' + res.status)); e.status = res.status; throw e; }
          return data;
        });
      });
    };
    return attempt(false);
  }

  function needAuth() {
    var has = getA();
    document.getElementById('login-card').classList.toggle('hidden', !!has);
    document.getElementById('view').style.display = has ? 'block' : 'none';
    document.getElementById('topbar').classList.toggle('hidden', !has);
    return !!has;
  }

  /* ---------- 登录 / 退出 ---------- */
  // 顶部导航点击：即使 hash 未变化（如在编辑器内点「文章」）也强制重载对应视图
  document.querySelectorAll('header nav a').forEach(function (a) {
    a.addEventListener('click', function () { setTimeout(goNav, 0); });
  });

  function bindLogin() {
    document.getElementById('login-user').value = localStorage.getItem('cfse_last_user') || '';
    document.getElementById('login-form').addEventListener('submit', function (e) {
      e.preventDefault();
      flash('flash-login', '', null);
      var u = document.getElementById('login-user').value.trim();
      var p = document.getElementById('login-pass').value;
      fetch('/api/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: u, password: p }),
      }).then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
        .then(function (r) {
          if (r.status === 200 && r.d.access) {
            setA(r.d.access); setR(r.d.refresh);
            localStorage.setItem('cfse_last_user', u);
            document.getElementById('login-pass').value = '';
            boot();
          } else {
            flash('flash-login', r.d.error || '登录失败', 'err');
          }
        }).catch(function () { flash('flash-login', '网络错误', 'err'); });
    });
  }

  document.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'btn-logout') {
      clearTokens();
      location.hash = '';
      boot();
    }
  });

  /* ---------- 文章列表 ---------- */
  var postsState = { page: 1, q: '', status: '', sort: 'date_desc', taxType: '', taxSlug: '' };
  var editorDirty = false;
  window.addEventListener('beforeunload', function (e) {
    if (editorDirty) {
      try {
        var t = document.getElementById('f-title');
        var eb = document.getElementById('ed-body');
        if (t) localStorage.setItem('cfse_draft', JSON.stringify({ title: t.value, content: eb ? eb.innerHTML : '', ts: Date.now() }));
      } catch (e2) {}
      e.preventDefault(); e.returnValue = '';
    }
  });
  document.addEventListener('click', function (e) {
    document.querySelectorAll('details[data-menu][open]').forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
    var t = e.target;
    if (t && t.closest) {
      var it = t.closest('details[data-menu] [data-copylink], details[data-menu] [data-dup], details[data-menu] [data-rebuild]');
      if (it) { var dd = it.closest('details'); if (dd) dd.removeAttribute('open'); }
    }
  });

  /* ---------- 最近编辑 ---------- */
  function rememberRecent(wpId, title) {
    var arr = [];
    try { arr = JSON.parse(localStorage.getItem('cfse_recent') || '[]') || []; } catch (e) {}
    arr = arr.filter(function (x) { return x.wp_id !== wpId; });
    arr.unshift({ wp_id: wpId, title: String(title || '').slice(0, 60) });
    arr = arr.slice(0, 8);
    try { localStorage.setItem('cfse_recent', JSON.stringify(arr)); } catch (e) {}
  }
  function renderRecentChips() {
    var row = document.getElementById('recent-row');
    if (!row) return;
    var arr = [];
    try { arr = JSON.parse(localStorage.getItem('cfse_recent') || '[]') || []; } catch (e) {}
    if (!arr.length) { row.style.display = 'none'; return; }
    row.style.display = '';
    row.innerHTML = '<span class="muted">最近编辑：</span>' + arr.map(function (x) {
      return '<button class="btn btn-sm" data-open="' + x.wp_id + '" title="' + esc(x.title) + '">' + esc(x.title.slice(0, 18)) + (x.title.length > 18 ? '…' : '') + '</button>';
    }).join(' ');
    row.querySelectorAll('[data-open]').forEach(function (b) {
      b.addEventListener('click', function () {
        api('/api/posts/' + b.getAttribute('data-open')).then(function (row2) { renderEditor(row2); }).catch(function () {
          var arr2 = [];
          try { arr2 = JSON.parse(localStorage.getItem('cfse_recent') || '[]') || []; } catch (e) {}
          localStorage.setItem('cfse_recent', JSON.stringify(arr2.filter(function (x) { return x.wp_id !== Number(b.getAttribute('data-open')); })));
          renderRecentChips();
          flash('flash-main', '文章不存在或已删除', 'err');
        });
      });
    });
  }

  function renderPosts() {
    var sec = document.getElementById('view-posts');
    sec.innerHTML =
      '<div id="recent-row" class="toolbar" style="flex-wrap:wrap;display:none;margin:0 0 8px;"></div>' +
      '<div class="muted" id="posts-stats" style="margin:2px 0 10px;"></div>' +
      '<div class="toolbar" style="flex-wrap:wrap;">' +
      '<button class="btn btn-primary" id="btn-new-post">＋ 新建文章</button>' +
      '<button class="btn" id="btn-audit">🔍 质检报告</button>' +
      '<input type="text" id="post-search" placeholder="搜索标题/正文…" value="' + esc(postsState.q) + '">' +
      '<select id="post-status"><option value="">全部状态</option><option value="published"' + (postsState.status === 'published' ? ' selected' : '') + '>已发布</option><option value="draft"' + (postsState.status === 'draft' ? ' selected' : '') + '>草稿</option></select>' +
      '<select id="post-sort"><option value="date_desc"' + (postsState.sort === 'date_desc' ? ' selected' : '') + '>日期最新</option><option value="date_asc"' + (postsState.sort === 'date_asc' ? ' selected' : '') + '>日期最早</option><option value="title_asc"' + (postsState.sort === 'title_asc' ? ' selected' : '') + '>标题 A-Z</option></select>' +
      '<span class="muted" style="margin-left:8px;">批量：</span>' +
      '<button class="btn btn-sm" id="btn-batch-pub" disabled>发布</button> ' +
      '<button class="btn btn-sm" id="btn-batch-draft" disabled>转草稿</button> ' +
      '<button class="btn btn-sm" id="btn-batch-cats" disabled>设分类</button> ' +
      '<button class="btn btn-sm btn-danger" id="btn-batch-del" disabled>删除</button>' +
      '</div>' +
      (postsState.taxType && postsState.taxSlug ? '<div class="toolbar" style="margin-top:8px;"><span class="muted">筛选：' + (postsState.taxType === 'tag' ? '标签' : '分类') + '「' + esc(postsState.taxSlug) + '」</span> <button class="btn btn-sm" id="tax-clear">✕ 清除筛选</button></div>' : '') +
      '<div class="card grid" id="audit-panel" style="display:none;"></div>' +
      '<div class="card grid" id="posts-box">加载中…</div>';

    document.getElementById('btn-new-post').addEventListener('click', function () { renderEditor(null); });
    document.getElementById('btn-audit').addEventListener('click', function () {
      var panel = document.getElementById('audit-panel');
      var show = panel.style.display === 'none';
      panel.style.display = show ? '' : 'none';
      if (show) renderAudit();
    });
    renderRecentChips();
    ['pub', 'draft', 'cats', 'del'].forEach(function (a) {
      document.getElementById('btn-batch-' + a).addEventListener('click', function () {
        var ids = [];
        document.querySelectorAll('[data-chk]:checked').forEach(function (c) { ids.push(Number(c.value)); });
        if (!ids.length) return;
        var action = { pub: 'publish', cats: 'setcats', del: 'delete' }[a] || a;
        if (action === 'delete' && !confirm('删除选中的 ' + ids.length + ' 篇文章？')) return;
        var extra = {};
        if (action === 'setcats') {
          var v = prompt('把选中的 ' + ids.length + ' 篇文章设为以下分类（逗号分隔，会替换原分类）：');
          if (v === null) return;
          v = v.trim();
          if (!v) return;
          extra.categories = splitNames(v);
        }
        api('/api/posts/batch', { method: 'POST', body: Object.assign({ ids: ids, action: action }, extra) }).then(function (d) {
          flash('flash-main', (action === 'del' ? '已删除 ' : '已处理 ') + d.done + ' 篇', 'ok');
          loadPosts();
        }).catch(function (e) { flash('flash-main', e.message, 'err'); });
      });
    });
    document.getElementById('view-posts').addEventListener('change', function (e) {
      var t = e.target;
      if (t && t.hasAttribute && t.hasAttribute('data-chk')) {
        var has = document.querySelectorAll('[data-chk]:checked').length > 0;
        ['pub', 'draft', 'cats', 'del'].forEach(function (a) {
          var b = document.getElementById('btn-batch-' + a);
          if (b) b.disabled = !has;
        });
      }
    });
    api('/api/stats').then(function (s) {
      var el = document.getElementById('posts-stats');
      if (el) {
        var mb = s.mediaBytes || 0;
        var sizeTxt = (mb >= 1048576 ? (mb / 1048576).toFixed(1) + 'MB' : Math.round(mb / 1024) + 'KB');
        el.textContent = '共 ' + s.posts + ' 篇（已发布 ' + s.published + ' · 草稿 ' + s.draft + '） · 分类 ' + s.categories + ' · 标签 ' + s.tags + ' · 媒体 ' + (s.media >= 0 ? s.media + ' 个 (' + sizeTxt + ')' : '?');
      }
    }).catch(function () {});
    document.getElementById('post-search').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { postsState.q = this.value.trim(); postsState.page = 1; loadPosts(); }
    });
    document.getElementById('post-status').addEventListener('change', function () {
      postsState.status = this.value; postsState.page = 1; loadPosts();
    });
    document.getElementById('post-sort').addEventListener('change', function () {
      postsState.sort = this.value; postsState.page = 1; loadPosts();
    });
    var taxClear = document.getElementById('tax-clear');
    if (taxClear) taxClear.addEventListener('click', function () {
      postsState.taxType = ''; postsState.taxSlug = ''; postsState.page = 1; loadPosts();
    });
    loadPosts();
  }

  function loadPosts() {
    var box = document.getElementById('posts-box');
    var qs = 'page=' + postsState.page + '&per=20' +
      (postsState.q ? '&q=' + encodeURIComponent(postsState.q) : '') +
      (postsState.status ? '&status=' + postsState.status : '') +
      (postsState.taxType && postsState.taxSlug ? '&type=' + postsState.taxType + '&tax=' + encodeURIComponent(postsState.taxSlug) : '');
    var so = { date_desc: ['date', 'desc'], date_asc: ['date', 'asc'], title_asc: ['title', 'asc'] }[postsState.sort] || ['date', 'desc'];
    qs += '&sort=' + so[0] + '&dir=' + so[1];
    api('/api/posts?' + qs).then(function (d) {
      var rows = (d.posts || []).map(function (p) {
        var cats = '';
        try { cats = (JSON.parse(p.categories || '[]') || []).map(function (c) { return c.name; }).join(', '); } catch (e) {}
        var tags = '';
        try { tags = (JSON.parse(p.tags || '[]') || []).map(function (t) { return t.name; }).join(', '); } catch (e) {}
        var link = '<a href="/' + esc(p.slug) + '/" target="_blank" data-inedit="' + p.wp_id + '">' + esc(p.title) + '</a>';
        var thumb = p.featured_image ? '<img src="' + esc(p.featured_image) + '" onerror="this.style.display=\'none\'" style="height:34px;max-width:52px;object-fit:cover;border-radius:3px;vertical-align:middle;margin-right:8px;" alt="">' : '';
        return '<tr><td><input type="checkbox" data-chk="' + p.wp_id + '" value="' + p.wp_id + '"></td><td>' + thumb + link +
          (p.status === 'draft' ? ' <span style="color:#b32d2e;font-size:12px;">草稿</span>' : '') + '</td>' +
          '<td>' + esc(String(p.date || '').slice(0, 10)) + '</td>' +
          '<td>' + esc(cats) + '</td><td>' + esc(tags) + '</td>' +
          '<td style="white-space:nowrap;position:relative;">' +
          '<button class="btn btn-sm" data-edit="' + p.wp_id + '">编辑</button> ' +
          '<button class="btn btn-sm btn-danger" data-del="' + p.wp_id + '">删除</button> ' +
          '<details style="display:inline-block;position:relative;vertical-align:middle;" data-menu>' +
          '<summary class="btn btn-sm" style="list-style:none;cursor:pointer;display:inline-block;">⋯</summary>' +
          '<div style="position:absolute;right:0;top:calc(100% + 4px);background:#fff;border:1px solid #ddd;border-radius:6px;z-index:6;box-shadow:0 2px 8px rgba(0,0,0,.15);min-width:140px;padding:6px;">' +
          '<div style="padding:2px 0;"><button class="btn btn-sm" data-copylink="' + esc(p.slug) + '" style="width:100%;text-align:left;">复制链接</button></div>' +
          '<div style="padding:2px 0;"><button class="btn btn-sm" data-dup="' + p.wp_id + '" style="width:100%;text-align:left;">复制为草稿</button></div>' +
          '<div style="padding:2px 0;"><button class="btn btn-sm" data-rebuild="' + p.wp_id + '" style="width:100%;text-align:left;">重建单页</button></div>' +
          '</div></details></td></tr>';
      }).join('');
      var empty = rows ? '' : '<tr><td colspan="6" class="muted">暂无文章</td></tr>';
      box.innerHTML = '<table><thead><tr><th style="width:26px;"></th><th>标题</th><th>日期</th><th>分类</th><th>标签</th><th>操作</th></tr></thead><tbody>' + rows + empty + '</tbody></table>' +
        '<div class="pager">' +
        (postsState.page > 1 ? '<button class="btn btn-sm" data-pg="' + (postsState.page - 1) + '">← 上一页</button>' : '') +
        '<span class="muted">第 ' + d.page + ' / ' + (d.pages || 1) + ' 页 · 共 ' + d.total + ' 篇</span>' +
        (postsState.page < (d.pages || 1) ? '<button class="btn btn-sm" data-pg="' + (postsState.page + 1) + '">下一页 →</button>' : '') +
        '</div>';

      box.querySelectorAll('[data-inedit]').forEach(function (a) {
        a.addEventListener('dblclick', function (e) {
          e.preventDefault();
          var cur = a.textContent;
          var td = a.parentNode;
          var inp = document.createElement('input');
          inp.value = cur;
          inp.style.width = '70%';
          td.replaceChild(inp, a);
          inp.focus();
          var finish = function (save) {
            var v = inp.value.trim();
            if (save && v && v !== cur) {
              api('/api/posts/' + a.getAttribute('data-inedit'), { method: 'PUT', body: { title: v } }).then(function () {
                flash('flash-main', '标题已更新', 'ok'); loadPosts();
              }).catch(function (err) { loadPosts(); flash('flash-main', err.message, 'err'); });
            } else { loadPosts(); }
          };
          inp.addEventListener('blur', function () { finish(false); });
          inp.addEventListener('keydown', function (e2) {
            if (e2.key === 'Enter') { e2.preventDefault(); finish(true); }
            else if (e2.key === 'Escape') { loadPosts(); }
          });
        });
      });
      box.querySelectorAll('[data-copylink]').forEach(function (b) {
        b.addEventListener('click', function () {
          var url = location.origin + '/' + b.getAttribute('data-copylink') + '/';
          copyText(url, function () { flash('flash-main', '链接已复制', 'ok'); }, function () { flash('flash-main', url, 'ok'); });
        });
      });
      box.querySelectorAll('[data-dup]').forEach(function (b) {
        b.addEventListener('click', function () {
          api('/api/posts/' + b.getAttribute('data-dup') + '/duplicate', { method: 'POST' }).then(function () {
            flash('flash-main', '已复制为草稿', 'ok');
            loadPosts();
          }).catch(function (e) { flash('flash-main', e.message, 'err'); });
        });
      });
      box.querySelectorAll('[data-rebuild]').forEach(function (b) {
        b.addEventListener('click', function () {
          api('/api/posts/' + b.getAttribute('data-rebuild') + '/rebuild', { method: 'POST' }).then(function (d) {
            flash('flash-main', d.rebuilt ? '单页已重建' : '草稿无需重建', 'ok');
          }).catch(function (e) { flash('flash-main', e.message, 'err'); });
        });
      });
      box.querySelectorAll('[data-edit]').forEach(function (b) {
        b.addEventListener('click', function () {
          var id = b.getAttribute('data-edit');
          api('/api/posts/' + id).then(function (p) { renderEditor(p); });
        });
      });
      box.querySelectorAll('[data-del]').forEach(function (b) {
        b.addEventListener('click', function () {
          var id = b.getAttribute('data-del');
          if (!confirm('确认删除该文章？（D1 数据与 R2 静态文件将一并删除）')) return;
          api('/api/posts/' + id, { method: 'DELETE' }).then(function () {
            flash('flash-main', '已删除', 'ok');
            loadPosts();
          }).catch(function (err) { flash('flash-main', err.message, 'err'); });
        });
      });
      box.querySelectorAll('[data-pg]').forEach(function (b) {
        b.addEventListener('click', function () { postsState.page = parseInt(b.getAttribute('data-pg'), 10); loadPosts(); });
      });
    }).catch(function (err) {
      box.innerHTML = '';
      flash('flash-main', err.message, 'err');
      if (err.message === 'auth') location.hash = '';
    });
  }

  /* ---------- 文章编辑 ---------- */
  function renderEditor(post) {
    var p = post || {};
    editorDirty = false;
    if (p.wp_id) rememberRecent(p.wp_id, p.title);
    var sec = document.getElementById('view-posts');
    sec.addEventListener('input', function () { editorDirty = true; });
    var curCats = [];
    try { curCats = (JSON.parse(p.categories || '[]') || []).map(function (c) { return c.name; }); } catch (e) {}
    var curTags = [];
    try { curTags = (JSON.parse(p.tags || '[]') || []).map(function (t) { return t.name; }); } catch (e) {}
    var dateVal = (String(p.date || '').length >= 16) ? String(p.date).slice(0, 16).replace(' ', 'T') : '';
    sec.innerHTML = '<h1>' + (p.wp_id ? '编辑文章' : '新建文章') + '</h1>' +
      '<div class="card">' +
      '<label>标题 *</label><input type="text" id="f-title" value="' + esc(p.title || '') + '">' +
      '<div class="row"><div><label>固定链接 (slug，留空自动)</label><input type="text" id="f-slug" value="' + esc(p.slug || '') + '"></div>' +
      '<div><label>状态</label><select id="f-status"><option value="published"' + (p.status === 'published' || !p.status ? ' selected' : '') + '>发布</option><option value="draft"' + (p.status === 'draft' ? ' selected' : '') + '>草稿</option></select></div>' +
      '<div><label>发布日期（留空=现在）</label><input type="datetime-local" id="f-date" value="' + dateVal + '"></div></div>' +
      '<div class="row"><div><label>分类</label>' +
      '<div style="position:relative;"><input type="text" id="f-cats" autocomplete="off" placeholder="点击选择，或直接输入新分类" value="' + esc(curCats.join(', ')) + '">' +
      '<div id="pop-cats" style="display:none;position:absolute;z-index:30;background:#fff;border:1px solid #ddd;border-radius:6px;box-shadow:0 2px 10px rgba(0,0,0,.14);width:100%;max-height:220px;overflow:auto;margin-top:2px;"></div></div></div>' +
      '<div><label>标签</label>' +
      '<div style="position:relative;"><input type="text" id="f-tags" autocomplete="off" placeholder="点击选择，或直接输入新标签" value="' + esc(curTags.join(', ')) + '">' +
      '<div id="pop-tags" style="display:none;position:absolute;z-index:30;background:#fff;border:1px solid #ddd;border-radius:6px;box-shadow:0 2px 10px rgba(0,0,0,.14);width:100%;max-height:220px;overflow:auto;margin-top:2px;"></div></div></div></div>' +
      '<label>封面图 URL（可选）</label><div style="display:flex;gap:6px;"><input type="url" id="f-image" placeholder="https://..." value="' + esc(p.featured_image || '') + '">' +
      '<button type="button" class="btn btn-sm" id="btn-first-img" title="从正文第一张图片取为封面">取正文首图</button></div>' +
      '<div style="margin-top:12px; display:flex; gap:8px; align-items:center;"><button class="btn" type="button" id="btn-upload">🖼 上传图片并插入正文</button>' +
      '<button class="btn" type="button" id="btn-lib">📁 从媒体库选择</button><input type="file" id="f-file" accept="image/*" multiple hidden></div>' +
      '<div id="lib-mask" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99;">' +
      '<div style="max-width:780px;margin:7vh auto;background:#fff;border-radius:8px;padding:16px 18px;max-height:80vh;overflow:auto;">' +
      '<div style="display:flex;justify-content:space-between;align-items:center;"><h1 style="font-size:17px;margin:0;">媒体库</h1><button class="btn btn-sm" id="lib-close">✕ 关闭</button></div>' +
      '<div id="lib-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-top:12px;"></div>' +
      '<div style="margin-top:12px;"><button class="btn btn-sm" id="lib-more" style="display:none;">加载更多</button></div></div></div>' +
      '<div id="draft-banner" style="display:none;background:#fff4e5;border:1px solid #ffcf8a;border-radius:6px;padding:8px 12px;margin-bottom:12px;">检测到未保存的草稿 <button class="btn btn-sm" id="draft-restore">恢复</button> <button class="btn btn-sm" id="draft-discard">丢弃</button></div>' +
      '<label style="margin-top:14px;">正文</label>' +
      '<div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap;">' +
      '<button type="button" class="btn btn-sm btn-primary" data-ed-mode="wysiwyg">✍ 富文本</button>' +
      '<button type="button" class="btn btn-sm" data-ed-mode="md">Markdown</button>' +
      '<button type="button" class="btn btn-sm" id="btn-ed-full" title="全屏编辑">⛶</button>' +
      '<span class="muted" style="font-size:12px;" id="ed-stat"></span></div>' +
      '<div id="ed-wysiwyg">' +
      '<div id="ed-tb" style="border:1px solid #ccc;border-radius:6px 6px 0 0;background:#f6f7f7;padding:4px 6px;display:flex;flex-wrap:wrap;gap:2px;">' +
      '<button type="button" class="btn btn-sm" data-cmd="bold" title="加粗"><b>B</b></button>' +
      '<button type="button" class="btn btn-sm" data-cmd="italic" title="斜体"><i>I</i></button>' +
      '<button type="button" class="btn btn-sm" data-cmd="underline" title="下划线"><u>U</u></button>' +
      '<button type="button" class="btn btn-sm" data-cmd="strikeThrough" title="删除线"><s>S</s></button>' +
      '<button type="button" class="btn btn-sm" data-cmd="undo" title="撤销">↶</button>' +
      '<button type="button" class="btn btn-sm" data-cmd="redo" title="重做">↷</button>' +
      '<button type="button" class="btn btn-sm" data-blk="h2">H2</button>' +
      '<button type="button" class="btn btn-sm" data-blk="h3">H3</button>' +
      '<button type="button" class="btn btn-sm" data-blk="blockquote">引用</button>' +
      '<button type="button" class="btn btn-sm" data-cmd="insertUnorderedList">• 列表</button>' +
      '<button type="button" class="btn btn-sm" data-cmd="insertOrderedList">1.列表</button>' +
      '<button type="button" class="btn btn-sm" data-blk="pre" title="代码块">代码</button>' +
      '<button type="button" class="btn btn-sm" data-link>链接</button>' +
      '<button type="button" class="btn btn-sm" data-cmd="insertHorizontalRule">—</button>' +
      '<button type="button" class="btn btn-sm" data-cmd="removeFormat">清除格式</button>' +
      '</div>' +
      '<div id="ed-body" contenteditable="true" style="border:1px solid #ccc;border-top:0;border-radius:0 0 6px 6px;min-height:320px;padding:12px 14px;line-height:1.7;overflow:auto;"></div></div>' +
      '<div id="ed-md" style="display:none;">' +
      '<div style="display:flex;gap:10px;">' +
      '<textarea id="f-content-md" style="flex:1;min-height:320px;font-family:ui-monospace,Consolas,monospace;font-size:13px;padding:8px;"></textarea>' +
      '<div id="ed-md-prev" style="flex:1;border:1px solid #eee;border-radius:6px;padding:10px 14px;min-height:320px;overflow:auto;line-height:1.7;"></div></div>' +
      '<p class="muted" style="font-size:12px;margin:6px 0 0;">Markdown 模式：左侧书写、右侧实时预览；保存时自动转换为 HTML 入库。</p></div>' +
      '<textarea id="f-content" style="display:none;"></textarea>' +
      '<label style="margin-top:14px;">摘要（可选，留空自动截取正文前 200 字）</label><textarea id="f-excerpt" style="min-height:60px;">' + esc(p.excerpt || '') + '</textarea>' +
      '<div style="margin-top:14px; display:flex; gap:8px;">' +
      '<button class="btn btn-primary" id="btn-save">💾 保存</button>' +
      '<button class="btn" id="btn-cancel">返回列表</button>' +
      '</div></div>';

    document.getElementById('btn-first-img').addEventListener('click', function () {
      var src = '';
      if (edMode === 'md') {
        var pv = document.getElementById('ed-md-prev');
        var im = pv && pv.querySelector('img');
        if (im) src = im.getAttribute('src') || '';
      } else {
        var im2 = edBody.querySelector('img');
        if (im2) src = im2.getAttribute('src') || '';
      }
      if (!src) { flash('flash-main', '正文中没有图片', 'err'); return; }
      document.getElementById('f-image').value = src;
      flash('flash-main', '已取正文首图为封面', 'ok');
    });
    // ===== 富文本 / Markdown 双模式编辑器 =====
    var isMdDoc = (p.content_format === 'md') && !!p.content;
    var edMode = isMdDoc ? 'md' : 'wysiwyg'; // md 原稿文档默认进入 md 模式
    var edBody = document.getElementById('ed-body');
    var mdArea = document.getElementById('f-content-md');
    if (isMdDoc) {
      edBody.innerHTML = mdToHtml(p.content);
      mdArea.value = p.content;
    } else {
      edBody.innerHTML = p.content || '';
    }
    function mdToHtml(src) {
      try { return (window.marked && marked.parse) ? marked.parse(src) : String(src || ''); } catch (e) { return String(src || ''); }
    }
    function renderMdPrev() {
      var pv = document.getElementById('ed-md-prev');
      if (pv) pv.innerHTML = mdToHtml(mdArea.value);
    }
    function edSync() {
      document.getElementById('f-content').value = edBody.innerHTML;
      var t = (edBody.textContent || '').trim();
      var el = document.getElementById('ed-stat');
      if (el) el.textContent = t ? ('约 ' + t.length + ' 字') : '';
    }
    edBody.addEventListener('input', function () { edSync(); });
    mdArea.addEventListener('input', function () { editorDirty = true; renderMdPrev(); });
    edSync(); renderMdPrev();
    function setEdMode(m) {
      edMode = m;
      document.getElementById('ed-wysiwyg').style.display = m === 'wysiwyg' ? '' : 'none';
      document.getElementById('ed-md').style.display = m === 'md' ? '' : 'none';
      document.querySelectorAll('[data-ed-mode]').forEach(function (b) {
        b.classList.toggle('btn-primary', b.getAttribute('data-ed-mode') === m);
      });
    }
    document.querySelectorAll('[data-ed-mode]').forEach(function (b) {
      b.addEventListener('click', function () {
        var m = b.getAttribute('data-ed-mode');
        if (m === 'md') { mdArea.value = edBody.innerHTML; renderMdPrev(); }
        else {
          edBody.innerHTML = mdToHtml(mdArea.value); edSync();
          if (isMdDoc) flash('flash-main', '切到富文本后若保存，该文将转为 HTML 格式（md 原稿将不再保留）', 'ok');
        }
        setEdMode(m);
      });
    });
    setEdMode(edMode);
    function editorContent() {
      if (edMode === 'md') return mdToHtml(mdArea.value);
      return edBody.innerHTML;
    }
    document.getElementById('btn-ed-full').addEventListener('click', function () {
      document.body.classList.toggle('ed-full');
      var b = document.getElementById('btn-ed-full');
      if (b) b.textContent = document.body.classList.contains('ed-full') ? '⛶ 退出全屏' : '⛶';
      window.scrollTo(0, 0);
    });
    // 富文本工具栏
    document.getElementById('ed-tb').querySelectorAll('[data-cmd]').forEach(function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        edBody.focus();
        var cmd = b.getAttribute('data-cmd');
        if (cmd === 'formatBlock') { document.execCommand('formatBlock', false, '<' + (b.getAttribute('data-val') || 'p') + '>'); }
        else { document.execCommand(cmd); }
        edSync();
      });
    });
    document.getElementById('ed-tb').querySelectorAll('[data-blk]').forEach(function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        edBody.focus();
        document.execCommand('formatBlock', false, '<' + b.getAttribute('data-blk') + '>');
        edSync();
      });
    });
    var linkBtn = document.querySelector('#ed-tb [data-link]');
    if (linkBtn) linkBtn.addEventListener('click', function () {
      var u = prompt('链接 URL：');
      if (u) { edBody.focus(); document.execCommand('createLink', false, u); edSync(); }
    });
    // 自动草稿（新建页提示恢复；刷新/离开时同步保存）
    if (!p.wp_id) {
      var dbtn = document.getElementById('draft-banner');
      var draftSaved = null;
      try { draftSaved = JSON.parse(localStorage.getItem('cfse_draft') || 'null'); } catch (e) {}
      if (dbtn && draftSaved && draftSaved.content) {
        dbtn.style.display = '';
        document.getElementById('draft-restore').addEventListener('click', function () {
          document.getElementById('f-title').value = draftSaved.title || '';
          edBody.innerHTML = draftSaved.content || '';
          edSync();
          dbtn.style.display = 'none';
        });
        document.getElementById('draft-discard').addEventListener('click', function () {
          localStorage.removeItem('cfse_draft');
          dbtn.style.display = 'none';
        });
      }
    }

    document.getElementById('btn-cancel').addEventListener('click', function () { renderPosts(); });
    // 分类/标签：一个可输入框 + 点击弹出的选择列表（已选高亮可切换；也可直接输入新名称）
    var renderEditorCache = {};
    function taxTokens(id) {
      return String(document.getElementById(id).value).split(/[,，]/).map(function (x) { return x.trim(); }).filter(Boolean);
    }
    function taxItems(type, cb) {
      var k = type === 'tag' ? 'tagCache' : 'catCache';
      if (renderEditorCache[k]) { cb(renderEditorCache[k]); return; }
      api(type === 'tag' ? '/api/tags' : '/api/categories').then(function (d) {
        renderEditorCache[k] = d.items || [];
        cb(renderEditorCache[k]);
      }).catch(function () {});
    }
    function drawTaxPop(type, items) {
      var pop = document.getElementById(type === 'tag' ? 'pop-tags' : 'pop-cats');
      var tokens = taxTokens(type === 'tag' ? 'f-tags' : 'f-cats');
      // 不做输入过滤：点开永远显示全部，已选排最前且高亮（可点击取消）；输入仅用于直接新增
      var sorted = (items || []).slice().sort(function (a, b) {
        var ai = tokens.indexOf(a.name) >= 0 ? 0 : 1;
        var bi = tokens.indexOf(b.name) >= 0 ? 0 : 1;
        return ai - bi || String(a.name).localeCompare(String(b.name), 'zh');
      });
      var html = sorted.map(function (x) {
        var on = tokens.indexOf(x.name) >= 0;
        return '<div data-taxi="' + type + '" data-tn="' + esc(x.name) + '" style="padding:7px 10px;cursor:pointer;border-bottom:1px solid #f2f2f2;' + (on ? 'background:#e8f0fe;' : '') + '">' +
          (on ? '✓ ' : '') + esc(x.name) + ' <span class="muted" style="font-size:12px;">(' + (x.cnt || 0) + ')</span></div>';
      }).join('');
      pop.innerHTML = html || '<div class="muted" style="padding:8px 10px;">（暂无，可直接在上方输入新' + (type === 'tag' ? '标签' : '分类') + '）</div>';
      pop.querySelectorAll('[data-taxi]').forEach(function (el) {
        el.addEventListener('mousedown', function (e) { e.preventDefault(); toggleTaxItem(el.getAttribute('data-taxi'), el.getAttribute('data-tn')); });
      });
    }
    function toggleTaxItem(type, name) {
      var id = type === 'tag' ? 'f-tags' : 'f-cats';
      var tokens = taxTokens(id);
      var i = tokens.indexOf(name);
      if (i >= 0) { tokens.splice(i, 1); } else { tokens.push(name); }
      document.getElementById(id).value = tokens.join(', ');
      editorDirty = true;
      drawTaxPop(type, renderEditorCache[type === 'tag' ? 'tagCache' : 'catCache'] || []);
    }
    function bindTaxCombo(type) {
      var id = type === 'tag' ? 'f-tags' : 'f-cats';
      var popId = type === 'tag' ? 'pop-tags' : 'pop-cats';
      var inp = document.getElementById(id);
      inp.addEventListener('focus', function () {
        taxItems(type, function (items) {
          drawTaxPop(type, items);
          var pop = document.getElementById(popId);
          if (pop) pop.style.display = '';
        });
      });
      inp.addEventListener('input', function () {
        editorDirty = true;
        taxItems(type, function (items) { drawTaxPop(type, items); });
      });
      inp.addEventListener('blur', function () {
        setTimeout(function () { var pop = document.getElementById(popId); if (pop) pop.style.display = 'none'; }, 180);
      });
    }
    bindTaxCombo('category');
    bindTaxCombo('tag');

    document.getElementById('btn-upload').addEventListener('click', function () {
      document.getElementById('f-file').click();
    });
    // ---- 媒体库选择器 ----
    var libCursor = null;
    function insertImgTag(url, alt) {
      var tag = '<img src="' + esc(url) + '" alt="' + esc(alt || '') + '" />';
      if (edMode === 'md') {
        mdArea.value += (mdArea.value ? '\n\n' : '') + '![' + esc(alt || '') + '](' + url + ')';
        renderMdPrev();
      } else {
        edBody.focus();
        document.execCommand('insertHTML', false, tag);
        edSync();
      }
      flash('flash-main', '已插入图片', 'ok');
    }
    function loadLib(cursor) {
      var list = document.getElementById('lib-list');
      api('/api/media' + (cursor ? '?cursor=' + encodeURIComponent(cursor) : '')).then(function (d) {
        var html = (d.items || []).map(function (it) {
          var alt = it.key.split('/').pop().replace(/\.[a-z0-9]+$/, '');
          return '<div style="border:1px solid #eee;border-radius:6px;padding:6px;">' +
            '<img src="' + esc(it.url) + '" style="width:100%;height:90px;object-fit:cover;border-radius:4px;" alt="">' +
            '<div style="margin-top:6px;display:flex;gap:4px;flex-wrap:wrap;">' +
            '<button class="btn btn-sm" data-lib-ins="' + esc(it.url) + '" data-alt="' + esc(alt) + '">插入</button>' +
            '<button class="btn btn-sm" data-lib-cov="' + esc(it.url) + '">作封面</button></div></div>';
        }).join('');
        if (cursor) list.insertAdjacentHTML('beforeend', html); else list.innerHTML = html;
        var more = document.getElementById('lib-more');
        if (more) more.style.display = d.truncated ? '' : 'none';
        libCursor = d.cursor || null;
        list.querySelectorAll('[data-lib-ins]').forEach(function (b) {
          b.addEventListener('click', function () {
            insertImgTag(b.getAttribute('data-lib-ins'), b.getAttribute('data-alt'));
          });
        });
        list.querySelectorAll('[data-lib-cov]').forEach(function (b) {
          b.addEventListener('click', function () {
            var f = document.getElementById('f-image');
            if (f) f.value = b.getAttribute('data-lib-cov');
            flash('flash-main', '已设为封面图', 'ok');
          });
        });
      }).catch(function (e) { flash('flash-main', e.message, 'err'); });
    }
    document.getElementById('btn-lib').addEventListener('click', function () {
      libCursor = null;
      document.getElementById('lib-mask').style.display = '';
      loadLib();
    });
    document.getElementById('lib-close').addEventListener('click', function () { document.getElementById('lib-mask').style.display = 'none'; });
    document.getElementById('lib-mask').addEventListener('click', function (e) { if (e.target.id === 'lib-mask') this.style.display = 'none'; });
    document.getElementById('lib-more').addEventListener('click', function () { loadLib(libCursor); });
    document.getElementById('f-file').addEventListener('change', function () {
      var files = Array.prototype.slice.call(this.files || []);
      this.value = '';
      if (files.length) uploadManyIntoEditor(files);
    });
    // 富文本主体支持拖拽/粘贴图片直接上传（md 模式请用上传按钮）
    var edEvt = edBody;
    edEvt.addEventListener('dragover', function (e) { e.preventDefault(); });
    edEvt.addEventListener('drop', function (e) {
      e.preventDefault();
      var fs = Array.prototype.slice.call((e.dataTransfer && e.dataTransfer.files) || []).filter(function (f) { return /^image\//.test(f.type); });
      if (fs.length) uploadManyIntoEditor(fs);
    });
    edEvt.addEventListener('paste', function (e) {
      var it = e.clipboardData && e.clipboardData.items;
      if (!it) return;
      for (var i = 0; i < it.length; i++) {
        if (it[i].kind === 'file' && /^image\//.test(it[i].type)) {
          e.preventDefault();
          uploadIntoEditor(it[i].getAsFile());
          break;
        }
      }
    });
    function uploadIntoEditor(file) { return uploadManyIntoEditor([file]); }
    function uploadManyIntoEditor(files) {
      var btn = document.getElementById('btn-upload');
      var old = btn.innerHTML;
      var done = 0;
      var total = files.length;
      btn.textContent = '上传中 0/' + total + '…';
      btn.disabled = true;
      return files.reduce(function (p, f) {
        return p.then(function () {
          return uploadOneToEditor(f).then(function () {
            done++;
            btn.textContent = '上传中 ' + done + '/' + total + '…';
          });
        });
      }, Promise.resolve()).then(function () {
        btn.innerHTML = old; btn.disabled = false;
        flash('flash-main', total > 1 ? ('已上传 ' + done + ' 张图片并插入正文') : '图片已上传并插入正文', 'ok');
      }).catch(function (err) {
        btn.innerHTML = old; btn.disabled = false;
        flash('flash-main', '上传中断（成功 ' + done + '/' + total + '）：' + err.message, 'err');
      });
    }
    function uploadOneToEditor(file) {
      var fd = new FormData();
      fd.append('file', file);
      return api('/api/media', { method: 'POST', body: fd }).then(function (d) {
        if (!d || !d.url) throw new Error('上传失败');
        var alt = String(file.name || '').toLowerCase().replace(/\.[a-z0-9]+$/, '').replace(/[^a-z0-9\u4e00-\u9fa5-]+/g, ' ').trim().slice(0, 60);
        if (edMode === 'md') {
          mdArea.value += (mdArea.value ? '\n\n' : '') + '![' + esc(alt) + '](' + d.url + ')';
          renderMdPrev();
        } else {
          edBody.focus();
          document.execCommand('insertHTML', false, '<img src="' + esc(d.url) + '" alt="' + esc(alt) + '" />');
          edSync();
        }
        return d;
      });
    }
    document.getElementById('btn-save').addEventListener('click', function () {
      var payload = {
        title: document.getElementById('f-title').value.trim(),
        slug: document.getElementById('f-slug').value.trim(),
        status: document.getElementById('f-status').value,
        content: (edMode === 'md') ? mdArea.value : edBody.innerHTML,
        content_format: (edMode === 'md') ? 'md' : 'html',
        excerpt: document.getElementById('f-excerpt') ? document.getElementById('f-excerpt').value.trim() : '',
        featured_image: document.getElementById('f-image').value.trim(),
        categories: splitNames(document.getElementById('f-cats').value),
        tags: splitNames(document.getElementById('f-tags').value),
      };
      var dv = document.getElementById('f-date') ? document.getElementById('f-date').value : '';
      if (dv) payload.date = dv.replace('T', ' ') + ':00';
      if (!payload.title) { flash('flash-main', '标题不能为空', 'err'); return; }
      var path = p.wp_id ? '/api/posts/' + p.wp_id : '/api/posts';
      var method = p.wp_id ? 'PUT' : 'POST';
      api(path, { method: method, body: payload }).then(function () {
        try { localStorage.removeItem('cfse_draft'); } catch (e) {}
        flash('flash-main', '已保存' + (payload.status === 'published' ? '（前台列表已刷新）' : '（草稿，前台不显示）'), 'ok');
        renderPosts();
      }).catch(function (err) { flash('flash-main', err.message, 'err'); });
    });
  }

  function splitNames(s) {
    return String(s || '').split(/[,，]/).map(function (x) { return x.trim(); }).filter(Boolean).map(function (x) { return { name: x }; });
  }

  /* ---------- 分类 / 标签 ---------- */
  function taxPanel(type, label) {
    var el = document.createElement('div');
    el.className = 'card grid';
    el.innerHTML = '<h1>' + label + '</h1>' +
      '<div class="toolbar"><input type="text" placeholder="新增' + label + '名称" data-add="' + type + '"><button class="btn btn-primary" data-addbtn="' + type + '">新增</button></div>' +
      '<table><thead><tr><th>名称</th><th>slug</th><th>文章数</th><th>操作</th></tr></thead><tbody data-tbody="' + type + '"><tr><td colspan="4" class="muted">加载中…</td></tr></tbody></table>';
    var host = document.getElementById(type === 'categories' ? 'cats-wrap' : 'tags-wrap');
    if (!host) return el;
    host.innerHTML = '';
    host.appendChild(el);

    el.querySelector('[data-addbtn="' + type + '"]').addEventListener('click', function () {
      var name = el.querySelector('[data-add="' + type + '"]').value.trim();
      if (!name) return;
      api('/api/' + type, { method: 'POST', body: { name: name } }).then(function () {
        loadTax(type);
      }).catch(function (err) { flash('flash-main', err.message, 'err'); });
    });
    loadTax(type);
    return el;
  }

  function loadTax(type) {
    var tbody = document.querySelector('[data-tbody="' + type + '"]');
    if (!tbody) return;
    api('/api/' + type).then(function (d) {
      var rows = (d.items || []).map(function (it) {
        return '<tr><td>' + esc(it.name) + '</td><td class="muted">' + esc(it.slug) + '</td><td>' + (it.cnt || 0) + '</td>' +
          '<td style="white-space:nowrap;">' +
          '<button class="btn btn-sm" data-taxlist="' + type + '" data-slug="' + esc(it.slug) + '">文章</button> ' +
          '<button class="btn btn-sm" data-renameterm="' + type + '" data-slug="' + esc(it.slug) + '">改名</button> ' +
          '<button class="btn btn-sm btn-danger" data-delterm="' + type + '" data-slug="' + esc(it.slug) + '">删除</button></td></tr>';
      }).join('');
      tbody.innerHTML = rows || '<tr><td colspan="4" class="muted">空</td></tr>';
      tbody.querySelectorAll('[data-taxlist]').forEach(function (b) {
        b.addEventListener('click', function () {
          postsState.taxType = b.getAttribute('data-taxlist');
          postsState.taxSlug = b.getAttribute('data-slug');
          postsState.q = ''; postsState.status = ''; postsState.sort = 'date_desc'; postsState.page = 1;
          location.hash = 'posts';
        });
      });
      tbody.querySelectorAll('[data-renameterm]').forEach(function (b) {
        b.addEventListener('click', function () {
          var slug = b.getAttribute('data-slug');
          var t = b.getAttribute('data-renameterm');
          var name = prompt('输入新名称：', slug);
          if (name === null) return;
          name = name.trim();
          if (!name) { flash('flash-main', '名称不能为空', 'err'); return; }
          api('/api/' + t + '/' + encodeURIComponent(slug), { method: 'PUT', body: { name: name } }).then(function () {
            flash('flash-main', '已改名', 'ok');
            loadTax(t);
          }).catch(function (err) { flash('flash-main', err.message, 'err'); });
        });
      });
      tbody.querySelectorAll('[data-delterm]').forEach(function (b) {
        b.addEventListener('click', function () {
          var slug = b.getAttribute('data-slug');
          var t = b.getAttribute('data-delterm');
          if (!confirm('删除该' + (t === 'categories' ? '分类' : '标签') + '？相关文章的关联会被移除。')) return;
          api('/api/' + t + '/' + encodeURIComponent(slug), { method: 'DELETE' }).then(function () {
            flash('flash-main', '已删除', 'ok');
            loadTax(t);
          }).catch(function (err) { flash('flash-main', err.message, 'err'); });
        });
      });
    }).catch(function (err) { tbody.innerHTML = '<tr><td colspan="4">' + esc(err.message) + '</td></tr>'; });
  }

  function renderTax() {
    var sec = document.getElementById('view-tax');
    sec.innerHTML = '<div id="cats-wrap"></div><div id="tags-wrap"></div>';
    taxPanel('categories', '分类');
    taxPanel('tags', '标签');
  }

  /* ---------- 媒体库 ---------- */
  function renderMedia() {
    var sec = document.getElementById('view-media');
    sec.innerHTML = '<h1>媒体库</h1>' +
      '<div style="margin:0 0 10px;"><button class="btn btn-sm btn-danger" id="media-del-sel" style="display:none;">删除选中</button> <span class="muted">勾选后批量删除</span></div>' +
      '<div class="card grid" id="media-box">加载中…</div>';
    document.getElementById('view-media').addEventListener('change', function (e) {
      var t = e.target;
      if (t && t.hasAttribute && t.hasAttribute('data-mdel')) {
        var has = document.querySelectorAll('[data-mdel]:checked').length > 0;
        var b = document.getElementById('media-del-sel');
        if (b) b.style.display = has ? '' : 'none';
      }
    });
    document.getElementById('media-del-sel').addEventListener('click', function () {
      var keys = [];
      document.querySelectorAll('[data-mdel]:checked').forEach(function (c) { keys.push(c.value); });
      if (!keys.length) return;
      if (!confirm('删除选中的 ' + keys.length + ' 个媒体文件？引用它们的文章将显示为缺失。')) return;
      Promise.all(keys.map(function (k) {
        return api('/api/media?key=' + encodeURIComponent(k), { method: 'DELETE' });
      })).then(function () {
        flash('flash-main', '已删除 ' + keys.length + ' 个文件', 'ok');
        loadMedia();
      }).catch(function (e) { flash('flash-main', e.message, 'err'); loadMedia(); });
    });
    loadMedia();
  }

  function loadMedia(cursor) {
    var box = document.getElementById('media-box');
    var qs = cursor ? ('?cursor=' + encodeURIComponent(cursor)) : '';
    api('/api/media' + qs).then(function (d) {
      var rows = (d.items || []).map(function (it) {
        return '<tr><td><input type="checkbox" data-mdel="' + esc(it.key) + '" value="' + esc(it.key) + '"></td><td><img src="' + esc(it.url) + '" style="height:42px;max-width:80px;object-fit:cover;border-radius:3px;" alt=""></td>' +
          '<td class="muted">' + esc(it.key.split('/').pop()) + '</td>' +
          '<td class="muted">' + (it.size > 1048576 ? (it.size / 1048576).toFixed(1) + 'MB' : Math.round(it.size / 1024) + 'KB') + '</td>' +
          '<td style="white-space:nowrap;"><button class="btn btn-sm" data-copy="' + esc(it.url) + '">复制URL</button> ' +
          '<button class="btn btn-sm" data-replkey="' + esc(it.key) + '">替换</button> ' +
          '<button class="btn btn-sm btn-danger" data-delkey="' + esc(it.key) + '">删除</button></td></tr>';
      }).join('');
      box.innerHTML = '<table><thead><tr><th style="width:26px;"></th><th>预览</th><th>文件名</th><th>大小</th><th>操作</th></tr></thead><tbody>' +
        (rows || '<tr><td colspan="5" class="muted">暂无媒体</td></tr>') + '</tbody></table>' +
        (d.truncated ? '<div style="margin-top:10px;"><button class="btn btn-sm" id="media-more">加载更多</button></div>' : '');
      var more = document.getElementById('media-more');
      if (more) more.addEventListener('click', function () { loadMedia(d.cursor); });
      box.querySelectorAll('[data-delkey]').forEach(function (b) {
        b.addEventListener('click', function () {
          var key = b.getAttribute('data-delkey');
          if (!confirm('删除该媒体文件？引用它的页面将显示为缺失。')) return;
          api('/api/media?key=' + encodeURIComponent(key), { method: 'DELETE' }).then(function () {
            flash('flash-main', '已删除', 'ok');
            loadMedia(cursor);
          }).catch(function (e) { flash('flash-main', e.message, 'err'); });
        });
      });
      box.querySelectorAll('[data-replkey]').forEach(function (b) {
        b.addEventListener('click', function () {
          var key = b.getAttribute('data-replkey');
          var inp = document.createElement('input');
          inp.type = 'file'; inp.accept = 'image/*'; inp.style.display = 'none';
          document.body.appendChild(inp);
          inp.addEventListener('change', function () {
            var f = inp.files && inp.files[0];
            document.body.removeChild(inp);
            if (!f) return;
            if (!confirm('用该文件替换当前图片？（引用此图的文章 URL 不变，自动生效）')) return;
            var fd = new FormData();
            fd.append('file', f);
            b.disabled = true;
            api('/api/media?key=' + encodeURIComponent(key), { method: 'PUT', body: fd }).then(function () {
              flash('flash-main', '图片已替换', 'ok');
              loadMedia(cursor);
            }).catch(function (e) { b.disabled = false; flash('flash-main', e.message, 'err'); });
          });
          inp.click();
        });
      });
      box.querySelectorAll('[data-copy]').forEach(function (b) {
        b.addEventListener('click', function () {
          var t = b.getAttribute('data-copy');
          copyText(t, function () { flash('flash-main', 'URL 已复制', 'ok'); }, function () { flash('flash-main', t, 'ok'); });
        });
      });
    }).catch(function (e) { box.innerHTML = ''; flash('flash-main', e.message, 'err'); });
  }

  /* ---------- 用户管理（仅管理员可见有效操作） ---------- */
  function renderUsers() {
    var sec = document.getElementById('view-users');
    sec.innerHTML = '<h1>用户</h1><div class="card grid" id="users-load">加载中…</div>';
    api('/api/users').then(function (d) {
      var rows = (d.users || []).map(function (u) {
        return '<tr><td><strong>' + esc(u.user_login) + '</strong>' + (u.user_status === 0 ? ' <span style="color:#b32d2e;">已禁用</span>' : '') + '</td>' +
          '<td>' + esc(u.display_name || '') + '</td><td>' + esc(u.user_email || '') + '</td><td>' + esc(u.role || '') + '</td>' +
          '<td class="muted">' + esc(String(u.user_registered || '').slice(0, 10)) + '</td>' +
          '<td style="white-space:nowrap;"><button class="btn btn-sm" data-tgl="' + u.id + '" data-status="' + u.user_status + '">' + (u.user_status === 1 ? '禁用' : '启用') + '</button> ' +
          '<button class="btn btn-sm" data-pw="' + u.id + '">重置密码</button> ' +
          '<button class="btn btn-sm btn-danger" data-deluser="' + u.id + '">删除</button></td></tr>';
      }).join('');
      var add = '<div class="toolbar" style="margin-top:12px;border-top:1px solid #eee;padding-top:12px;">' +
        '<input placeholder="用户名" id="u-name" style="max-width:130px;">' +
        '<input placeholder="密码(≥8)" type="password" id="u-pass" style="max-width:130px;">' +
        '<input placeholder="邮箱" id="u-email" style="max-width:170px;">' +
        '<input placeholder="昵称" id="u-disp" style="max-width:110px;">' +
        '<select id="u-role" style="max-width:100px;"><option value="editor">编辑</option><option value="author">作者</option><option value="admin">管理员</option><option value="subscriber">订阅者</option></select>' +
        '<button class="btn btn-primary" id="u-add">新增用户</button></div>';
      sec.innerHTML = '<h1>用户</h1><div class="card grid"><table><thead><tr><th>用户名</th><th>昵称</th><th>邮箱</th><th>角色</th><th>注册</th><th>操作</th></tr></thead><tbody>' + rows + '</tbody></table>' + add + '</div>';
      bindUserActions();
    }).catch(function (e) {
      sec.innerHTML = '<h1>用户</h1><div class="card"><p>' + (e.status === 403 ? '（仅管理员可管理用户）' : esc(e.message)) + '</p></div>';
    });
  }

  function bindUserActions() {
    var add = document.getElementById('u-add');
    if (add) add.addEventListener('click', function () {
      var body = {
        username: document.getElementById('u-name').value.trim(),
        password: document.getElementById('u-pass').value,
        email: document.getElementById('u-email').value.trim(),
        display_name: document.getElementById('u-disp').value.trim(),
        role: document.getElementById('u-role').value,
      };
      api('/api/users', { method: 'POST', body: body }).then(function () {
        flash('flash-main', '已新增用户', 'ok');
        renderUsers();
      }).catch(function (e) { flash('flash-main', e.message, 'err'); });
    });
    document.querySelectorAll('[data-tgl]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-tgl');
        var st = parseInt(b.getAttribute('data-status'), 10);
        api('/api/users/' + id, { method: 'PUT', body: { user_status: st === 1 ? 0 : 1 } }).then(function () {
          flash('flash-main', '已更新', 'ok');
          renderUsers();
        }).catch(function (e) { flash('flash-main', e.message, 'err'); });
      });
    });
    document.querySelectorAll('[data-pw]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-pw');
        var pw = prompt('输入该用户的新密码（至少 8 位）：');
        if (pw === null) return;
        api('/api/users/' + id, { method: 'PUT', body: { password: pw } }).then(function () {
          flash('flash-main', '密码已重置', 'ok');
        }).catch(function (e) { flash('flash-main', e.message, 'err'); });
      });
    });
    document.querySelectorAll('[data-deluser]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-deluser');
        if (!confirm('删除该用户？')) return;
        api('/api/users/' + id, { method: 'DELETE' }).then(function () {
          flash('flash-main', '已删除', 'ok');
          renderUsers();
        }).catch(function (e) { flash('flash-main', e.message, 'err'); });
      });
    });
  }

  /* ---------- 质检报告 ---------- */
  function renderAudit() {
    var panel = document.getElementById('audit-panel');
    panel.innerHTML = '加载中…';
    api('/api/audit').then(function (d) {
      function seg(title, n, items) {
        var rows = (items || []).map(function (it) {
          return '<div style="padding:5px 0;border-bottom:1px solid #f3f3f3;"><a href="#" data-open="' + it.wp_id + '">' + esc(it.title) + '</a> <span class="muted">/' + esc(it.slug) + '/</span></div>';
        }).join('');
        return '<div style="margin-bottom:16px;"><strong>' + title + '（' + n + '）</strong>' + (rows || '<div class="muted" style="padding:4px 0;">无</div>') + '</div>';
      }
      panel.innerHTML = seg('未设置分类', d.noCat, d.noCatItems) +
        seg('缺封面图', d.noFeature, d.noFeatureItems) +
        seg('标题超 80 字', d.longTitle, d.longTitleItems);
      panel.querySelectorAll('[data-open]').forEach(function (a) {
        a.addEventListener('click', function (e) {
          e.preventDefault();
          api('/api/posts/' + a.getAttribute('data-open')).then(function (row) { renderEditor(row); }).catch(function (err) { flash('flash-main', err.message, 'err'); });
        });
      });
    }).catch(function (e) { panel.innerHTML = ''; flash('flash-main', e.message, 'err'); });
  }

  /* ---------- 设置（站点名 / 改密 / 内容维护） ---------- */
  function renderSettings() {
    var sec = document.getElementById('view-settings');
    sec.innerHTML = '<h1>设置</h1>' +
      '<div class="card"><h1 style="font-size:16px;">账号资料</h1>' +
      '<p class="muted" id="who-line">加载中…</p>' +
      '<div class="row"><div><label>昵称</label><input type="text" id="m-name"></div>' +
      '<div><label>邮箱</label><input type="email" id="m-email"></div></div>' +
      '<div style="margin-top:10px;"><button class="btn btn-primary" id="m-save">保存资料</button></div></div>' +
      '<div class="card"><h1 style="font-size:16px;">站点信息</h1>' +
      '<p class="muted">站名用于标题/页脚/侧边栏（保存即清缓存生效，历史静态单页需重建或全量导出）。站点描述用于 RSS 频道描述。</p>' +
      '<label>站点名</label><input type="text" id="s-name">' +
      '<label>站点描述（用于 RSS 频道，可选）</label><textarea id="s-desc" style="min-height:54px;"></textarea>' +
      '<div style="margin-top:10px;"><button class="btn btn-primary" id="s-save">保存站点信息</button></div></div>' +
      '<div class="card"><h1 style="font-size:16px;">缓存策略</h1>' +
      '<p class="muted">控制动态页（列表页/侧边栏）的缓存方式；文章单页始终直读 R2。数值 <b>0 = 直查 D1 不缓存</b>（最省 KV 写入额度）。保存后即时生效（会自动清一次相关缓存）。</p>' +
      '<div style="margin:8px 0;display:flex;gap:6px;flex-wrap:wrap;">' +
      '<button class="btn btn-sm" id="cp-preset-d1">预设：全 D1（最省 KV 写）</button>' +
      '<button class="btn btn-sm" id="cp-preset-mix">预设：混合（侧边栏 KV）</button>' +
      '<button class="btn btn-sm" id="cp-preset-kv">预设：全 KV（高流量）</button></div>' +
      '<div class="row"><div><label>列表页 TTL（秒，0=直查 D1）</label><input type="number" id="c-ttl-list" min="0"></div>' +
      '<div><label>侧边栏 TTL（秒，0=直查 D1）</label><input type="number" id="c-ttl-sidebar" min="0"></div>' +
      '<div><label>站点信息 TTL（秒，0=不缓存）</label><input type="number" id="c-ttl-site" min="0"></div>' +
      '<div><label>Feed TTL（秒，0=不缓存；默认 4 小时）</label><input type="number" id="c-ttl-feed" min="0"></div>' +
      '<div><label>Feed 输出条数（1-100）</label><input type="number" id="c-feed-count" min="1" max="100"></div></div>' +
      '<label style="margin-top:8px;display:block;"><input type="checkbox" id="c-purge"> 发布文章时清列表第一页/侧边栏缓存（消耗 KV 写额度，建议保持关闭）</label>' +
      '<div style="margin-top:10px;"><button class="btn btn-primary" id="c-save">保存缓存策略</button> <span class="muted" id="c-note"></span></div></div>' +
      '<div class="card"><h1 style="font-size:16px;">修改密码</h1>' +
      '<label>原密码</label><input type="password" id="p-old" autocomplete="current-password">' +
      '<label>新密码（至少 8 位）</label><input type="password" id="p-new" autocomplete="new-password">' +
      '<div style="margin-top:10px;"><button class="btn btn-primary" id="p-save">修改密码</button></div></div>' +
      '<div class="card"><h1 style="font-size:16px;">内容维护</h1>' +
      '<p class="muted">用当前单页壳重建全部文章的静态单页（用于改名 / 换模板后统一旧单页外观与站点信息）。分批执行，页数多时请稍候。</p>' +
      '<div style="margin-top:10px;"><button class="btn btn-primary" id="rb-btn">重建全部单页</button> ' +
      '<button class="btn" id="purge-btn">清空全站缓存</button> <span class="muted" id="rb-prog"></span></div></div>' +
      '<div class="card"><h1 style="font-size:16px;">数据备份 / 恢复</h1>' +
      '<p class="muted">导出全部文章为 JSON；导入时按 slug 跳过已存在的文章，其余新增。</p>' +
      '<div style="margin-top:10px;"><button class="btn" id="exp-btn">导出备份</button> ' +
      '<button class="btn" id="imp-btn">导入备份</button> ' +
      '<input type="file" id="imp-file" accept=".json,application/json" hidden>' +
      '<span class="muted" id="bk-prog"></span></div></div>';

    api('/api/me').then(function (u) {
      document.getElementById('who-line').textContent = '登录用户：' + (u.username || '') + '（' + (u.role || '') + '）';
      document.getElementById('m-name').value = u.displayName || '';
      document.getElementById('m-email').value = u.email || '';
    }).catch(function (err) { flash('flash-main', err.message, 'err'); });

    document.getElementById('m-save').addEventListener('click', function () {
      api('/api/me', {
        method: 'PUT',
        body: { display_name: document.getElementById('m-name').value.trim(), email: document.getElementById('m-email').value.trim() },
      }).then(function () {
        flash('flash-main', '资料已保存', 'ok');
      }).catch(function (err) { flash('flash-main', err.message, 'err'); });
    });

    api('/api/settings').then(function (d) {
      document.getElementById('s-name').value = (d.site && d.site.name) || '';
      document.getElementById('s-desc').value = (d.site && d.site.description) || '';
      var c = d.cache || {};
      document.getElementById('c-ttl-list').value = (c.ttl_list !== undefined ? c.ttl_list : 0);
      document.getElementById('c-ttl-sidebar').value = (c.ttl_sidebar !== undefined ? c.ttl_sidebar : 0);
      document.getElementById('c-ttl-site').value = (c.ttl_site !== undefined ? c.ttl_site : 604800);
      document.getElementById('c-ttl-feed').value = (c.ttl_feed !== undefined ? c.ttl_feed : 14400);
      document.getElementById('c-feed-count').value = (c.feed_count !== undefined ? c.feed_count : 20);
      document.getElementById('c-purge').checked = !!c.purge_on_publish;
    }).catch(function (err) { flash('flash-main', err.message, 'err'); });

    // 缓存策略：三档预设 + 保存
    function fillCacheTtls(list, sidebar, site) {
      document.getElementById('c-ttl-list').value = list;
      document.getElementById('c-ttl-sidebar').value = sidebar;
      document.getElementById('c-ttl-site').value = site;
    }
    document.getElementById('cp-preset-d1').addEventListener('click', function () { fillCacheTtls(0, 0, 604800); flash('flash-main', '已填入「全 D1」预设，记得点保存', 'ok'); });
    document.getElementById('cp-preset-mix').addEventListener('click', function () { fillCacheTtls(0, 86400, 604800); flash('flash-main', '已填入「混合」预设，记得点保存', 'ok'); });
    document.getElementById('cp-preset-kv').addEventListener('click', function () { fillCacheTtls(3600, 86400, 604800); flash('flash-main', '已填入「全 KV」预设，记得点保存', 'ok'); });
    document.getElementById('c-save').addEventListener('click', function () {
      var body = {
        cache: {
          ttl_list: parseInt(document.getElementById('c-ttl-list').value, 10) || 0,
          ttl_sidebar: parseInt(document.getElementById('c-ttl-sidebar').value, 10) || 0,
          ttl_site: parseInt(document.getElementById('c-ttl-site').value, 10) || 0,
          ttl_feed: parseInt(document.getElementById('c-ttl-feed').value, 10) || 0,
          feed_count: Math.min(100, Math.max(1, parseInt(document.getElementById('c-feed-count').value, 10) || 20)),
          purge_on_publish: document.getElementById('c-purge').checked,
        },
      };
      api('/api/settings', { method: 'PUT', body: body }).then(function (r) {
        flash('flash-main', '缓存策略已保存并即时生效', 'ok');
        var n = document.getElementById('c-note');
        if (n && r.cache) n.textContent = '列表 ' + r.cache.ttl_list + 's · 侧边栏 ' + r.cache.ttl_sidebar + 's · 站点 ' + r.cache.ttl_site + 's · Feed ' + (r.cache.ttl_feed || 0) + 's/' + (r.cache.feed_count || 20) + '篇';
      }).catch(function (err) { flash('flash-main', err.message, 'err'); });
    });

    document.getElementById('s-save').addEventListener('click', function () {
      var name = document.getElementById('s-name').value.trim();
      var desc = document.getElementById('s-desc').value.trim();
      if (!name) { flash('flash-main', '站点名不能为空', 'err'); return; }
      api('/api/settings', { method: 'PUT', body: { site: { name: name, description: desc } } }).then(function () {
        flash('flash-main', '站点信息已更新并清空缓存，列表页立即生效', 'ok');
        setWho();
      }).catch(function (err) { flash('flash-main', err.message, 'err'); });
    });

    document.getElementById('p-save').addEventListener('click', function () {
      var oldp = document.getElementById('p-old').value;
      var newp = document.getElementById('p-new').value;
      if (newp.length < 8) { flash('flash-main', '新密码至少 8 位', 'err'); return; }
      api('/api/me/password', { method: 'POST', body: { old_password: oldp, new_password: newp } }).then(function () {
        clearTokens();
        flash('flash-login', '密码已修改，请用新密码重新登录', 'ok');
        boot();
      }).catch(function (err) { flash('flash-main', err.message, 'err'); });
    });

    document.getElementById('rb-btn').addEventListener('click', function () {
      var btn = document.getElementById('rb-btn');
      var prog = document.getElementById('rb-prog');
      btn.disabled = true;
      prog.textContent = '开始…';
      function run(offset, done) {
        api('/api/posts/rebuild-single?limit=15&offset=' + offset, { method: 'POST' }).then(function (d) {
          prog.textContent = '已重建 ' + d.rebuilt + ' / 共 ' + d.total + (d.nextOffset ? '…' : '');
          if (d.nextOffset) { run(d.nextOffset, done); }
          else { done(); }
        }).catch(function (err) { done(); flash('flash-main', '重建中断：' + err.message, 'err'); });
      }
      run(0, function () {
        btn.disabled = false;
        prog.textContent = '完成';
        flash('flash-main', '全部单页已重建', 'ok');
      });
    });

    document.getElementById('purge-btn').addEventListener('click', function () {
      var prog = document.getElementById('rb-prog');
      if (!confirm('清空全站 KV 缓存（首页/侧栏/sitemap/RSS/单页/站点信息）？')) return;
      prog.textContent = '清理中…';
      api('/api/cache/purge', { method: 'POST', body: {} }).then(function (d) {
        prog.textContent = '已清空 ' + d.cleared + ' 组缓存';
        flash('flash-main', '缓存已清空', 'ok');
      }).catch(function (e) { prog.textContent = ''; flash('flash-main', e.message, 'err'); });
    });

    document.getElementById('exp-btn').addEventListener('click', function () {
      var prog = document.getElementById('bk-prog');
      prog.textContent = '导出中…';
      api('/api/export').then(function (d) {
        var blob = new Blob([JSON.stringify(d, null, 2)], { type: 'application/json' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'cfse-backup-' + new Date().toISOString().slice(0, 10) + '.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
        prog.textContent = '已导出 ' + d.total + ' 篇';
      }).catch(function (e) { prog.textContent = ''; flash('flash-main', e.message, 'err'); });
    });
    document.getElementById('imp-btn').addEventListener('click', function () {
      document.getElementById('imp-file').click();
    });
    document.getElementById('imp-file').addEventListener('change', function () {
      var file = this.files && this.files[0];
      this.value = '';
      if (!file) return;
      var prog = document.getElementById('bk-prog');
      var rd = new FileReader();
      rd.onload = function () {
        var obj;
        try { obj = JSON.parse(rd.result); } catch (e) { prog.textContent = ''; flash('flash-main', '文件不是有效 JSON', 'err'); return; }
        prog.textContent = '导入中…';
        api('/api/import', { method: 'POST', body: obj }).then(function (d) {
          var sk = (d.skipped || []).length ? '，跳过 ' + d.skipped.length + ' 篇' : '';
          prog.textContent = '已导入 ' + d.imported + ' 篇' + sk;
          flash('flash-main', '导入完成', 'ok');
        }).catch(function (e) { prog.textContent = ''; flash('flash-main', e.message, 'err'); });
      };
      rd.readAsText(file);
    });
  }

  /* ---------- 模板 / 风格（KV） ---------- */
  var TPL_META = {
    single: { label: '单页', hint: '文章单页整页 HTML。占位符：{{SITE_URL}} {{SITE_NAME}} {{SITE_DESC}} {{POST_TITLE}} {{POST_DATE}} {{POST_EXCERPT}} {{POST_CONTENT}} {{POST_URL}} {{POST_CATEGORIES}} {{POST_TAGS}}；条件块 {{#IF_CATEGORIES}}…{{/IF_CATEGORIES}}、{{#IF_TAGS}}…{{/IF_TAGS}}。' },
    item: { label: '列表', hint: '列表里每篇文章的卡片（四个列表页共用）。占位符：{{POST_TITLE}} {{POST_URL}} {{POST_DATE}} {{POST_EXCERPT}} {{POST_CATEGORIES}} {{POST_TAGS}} + 条件块 {{#IF_CATEGORIES}}…{{/IF_CATEGORIES}}、{{#IF_TAGS}}…{{/IF_TAGS}}。' },
    pageHome: { label: '首页', hint: '首页与搜索页整页 HTML。{{CONTENT}} 为文章列表，另有 {{TITLE}} {{TOTAL_POSTS}} {{PAGINATION_PREV}} {{PAGINATION_NEXT}} {{SITE_NAME}} {{SITE_URL}} {{SITE_DESC}} {{SITE_FOOTER}}。' },
    pageCategory: { label: '分类', hint: '分类归档整页 HTML；{{TITLE}} 为分类名，其余同首页外壳。' },
    pageTag: { label: '标签', hint: '标签归档整页 HTML；{{TITLE}} 为标签名，其余同首页外壳。' },
    pageArchive: { label: '归档', hint: '日期归档整页 HTML；另有 {{YEAR}}、{{MONTH}}，其余同首页外壳。' },
  };
  var TPL_ORDER = ['pageHome', 'pageCategory', 'pageTag', 'pageArchive', 'single', 'item'];
  function tplSlug(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40); }

  function openStylePreview(pages) {
    var w = window.open('', '_blank');
    if (!w) { flash('flash-main', '浏览器拦截了新窗口，请允许弹窗', 'err'); return; }
    var labels = { single: '文章单页', pageHome: '首页', pageCategory: '分类页', pageTag: '标签页', pageArchive: '归档页', item: '列表条目' };
    var tabs = pages.map(function (p, i) { return '<button data-i="' + i + '"' + (i === 0 ? ' class="on"' : '') + '>' + (labels[p.kind] || p.kind) + '</button>'; }).join('');
    var frames = pages.map(function (p, i) { return '<iframe data-i="' + i + '"' + (i === 0 ? '' : ' style="display:none"') + '></iframe>'; }).join('');
    w.document.open();
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>风格预览</title><style>' +
      'body{margin:0;font:14px/1.6 -apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f0f0f1}' +
      '#tabs{position:fixed;top:0;left:0;right:0;height:44px;display:flex;gap:4px;align-items:center;background:#1d2327;padding:0 10px;z-index:9}' +
      '#tabs button{background:none;border:1px solid #3c434a;color:#c7c9cc;padding:5px 14px;border-radius:4px;cursor:pointer;font-size:13px}' +
      '#tabs button.on{background:#2271d1;border-color:#2271d1;color:#fff}' +
      'iframe{border:0;width:100%;height:calc(100vh - 44px);margin-top:44px;display:block;background:#fff}' +
      '</style></head><body><div id="tabs">' + tabs + '<span style="color:#8a8f94;margin-left:auto;font-size:12px;">仅本地预览，不影响线上</span></div>' + frames + '</body></html>');
    w.document.close();
    pages.forEach(function (p, i) {
      var f = w.document.querySelector('iframe[data-i="' + i + '"]');
      if (f) f.setAttribute('srcdoc', p.html || '');
    });
    w.document.getElementById('tabs').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-i]');
      if (!b) return;
      var i = b.getAttribute('data-i');
      w.document.querySelectorAll('#tabs button').forEach(function (x) { x.classList.toggle('on', x === b); });
      w.document.querySelectorAll('iframe').forEach(function (f) { f.style.display = (f.getAttribute('data-i') === i) ? '' : 'none'; });
    });
  }

  function renderTpl() {
    var sec = document.getElementById('view-tpl');
    var labels = TPL_ORDER.map(function (k) { return '<button class="btn btn-sm' + (k === 'pageHome' ? ' btn-primary' : '') + '" data-kind="' + k + '">' + esc(TPL_META[k].label) + '</button>'; }).join(' ');
    var html = '<div class="card grid"><h1 style="margin:0 0 6px;">模板 / 风格</h1>' +
      '<p class="muted" style="margin:0;">风格 = 6 个模板的整体组合（首页/分类/标签/归档外壳 + 单页壳 + 列表条目），存 KV；每次切换即一次 KV 写。应用前先预览；应用后列表页即刻生效，单页需重建。</p></div>';

    html += '<div class="card grid"><h3 style="margin:0 0 8px;">风格应用</h3>' +
      '<div class="toolbar" style="flex-wrap:wrap;">' +
      '<select id="st-sel" style="max-width:300px;"></select>' +
      '<button class="btn btn-sm" id="st-preview">👁 预览</button>' +
      '<button class="btn btn-sm btn-primary" id="st-apply">应用</button>' +
      '<label style="margin:0;font-weight:400;"><input type="checkbox" id="st-rebuild"> 应用后重建全部单页</label>' +
      '<span class="muted" id="st-prog"></span>' +
      '</div><div class="toolbar" style="flex-wrap:wrap;margin-top:8px;">' +
      '<button class="btn btn-sm btn-primary" id="st-dirbtn">📦 上传风格（目录或 JSON）</button>' +
      '<input type="file" id="st-dir" webkitdirectory multiple hidden>' +
      '<button class="btn btn-sm" id="st-dl">📥 下载风格</button>' +
      '<button class="btn btn-sm btn-danger" id="st-del">🗑 删除风格</button>' +
      '</div>' +
      '<p class="muted" style="margin:8px 0 0;">风格目录：<code>pageHome.html</code>（或 <code>home.html</code>）、<code>pageCategory.html</code>（<code>category.html</code>）、<code>pageTag.html</code>（<code>tag.html</code>）、<code>pageArchive.html</code>（<code>archive.html</code>）、<code>single.html</code>、<code>item.html</code>，可选 <code>style.json</code> 提供风格名；其余文件（css/js/字体/图片）自动作为风格资源上传 R2。</p>' +
      '<p class="muted" style="margin:4px 0 0;">资源引用建议：把资源放进风格目录、模板里用 <code>/style/&lt;风格名&gt;/文件名</code> 引用（R2 直出、与部署解耦）；引用 <code>/wp-content/themes/...</code> 依赖部署打包的 static（assets 免费直出，未命中自动回退 R2），改动主题后需在 WP 后台点「同步主题资源」再 <code>wrangler deploy</code>；引用 <code>{{SITE_URL}}/wp-content/...</code> 则走 WP 原站。</p>' +
      '</div>';

    html += '<div class="card grid"><h3 style="margin:0 0 8px;">自定义风格</h3>' +
      '<div class="toolbar" style="flex-wrap:wrap;">' + labels + '</div>' +
      '<p class="muted" data-tplhint style="margin:8px 0;"></p>' +
      '<div class="toolbar" style="flex-wrap:wrap;">' +
      '<select id="kd-sel" style="max-width:300px;"></select>' +
      '<button class="btn btn-sm" id="kd-prev">👁 预览</button>' +
      '<button class="btn btn-sm btn-primary" id="kd-save">💾 保存</button>' +
      '<button class="btn btn-sm" id="kd-revert">↩ 回退版本</button>' +
      '<span class="muted" id="kd-cur"></span>' +
      '</div>' +
      '<textarea data-tplarea style="min-height:320px;font-family:ui-monospace,Consolas,monospace;font-size:12px;"></textarea>' +
      '</div>';
    sec.innerHTML = html;

    var contents = {};
    var curKind = 'pageHome';
    var loadedStyle = { id: '', name: '当前生效' };
    var styles = [];
    var uiReady = false;

    function kindArea() { return sec.querySelector('[data-tplarea]'); }
    function setCurKind(k) {
      if (uiReady) contents[curKind] = kindArea().value;
      curKind = k;
      sec.querySelectorAll('[data-kind]').forEach(function (x) { x.classList.toggle('btn-primary', x.getAttribute('data-kind') === k); });
      sec.querySelector('[data-tplhint]').textContent = TPL_META[k].hint;
      kindArea().value = contents[k] || '';
    }
    // 风格选项：主显示风格包 slug（name 常被站点名/主题名污染，只作补充说明）
    function styleOptHtml(s) {
      var id = String(s.id || '');
      var nm = String(s.name || '');
      var extra = (nm && nm !== id) ? ' — ' + nm : '';
      return '<option value="' + esc(id) + '">' + esc(id) + esc(extra) + '</option>';
    }

    function fillStyleSel() {
      var sel = document.getElementById('st-sel');
      var builtin = [];
      var mine = [];
      styles.forEach(function (s) { (s.source === 'builtin' ? builtin : mine).push(s); });

      var opts = '<option value="">（选择风格…）</option>'
        + '<option value="__default__">默认风格（部署内置模板，无资源依赖）</option>';
      if (builtin.length) {
        opts += '<optgroup label="📦 内置风格（随 wrangler deploy 更新）">'
          + builtin.map(styleOptHtml).join('') + '</optgroup>';
      }
      if (mine.length) {
        opts += '<optgroup label="📤 我上传/保存的风格（存 D1，改完即生效）">'
          + mine.map(styleOptHtml).join('') + '</optgroup>';
      }
      sel.innerHTML = opts;

      var kd = document.getElementById('kd-sel');
      var ko = '<option value="">（保持当前生效模板）</option>';
      styles.forEach(function (s) { ko += styleOptHtml(s); });
      kd.innerHTML = ko;
      kd.value = loadedStyle.id || '';
      renderStyleFoot();
    }

    function renderStyleFoot() {
      var area = '<div id="st-foot" style="margin-top:8px;"></div>';
      // 兼容：把摘要渲染到 kd-cur
    }
    function refreshStyles() {
      return api('/api/templates').then(function (d) {
        styles = d.styles || [];
        fillStyleSel();
      });
    }
    /** 按「当前生效」加载 6 类内容（也用于初次进入） */
    function loadActive() {
      return api('/api/templates').then(function (d) {
        var act = d.active || {};
        var jobs = TPL_ORDER.map(function (k) {
          var st = act[k] || { id: 'default' };
          if (st.id === 'default') { contents[k] = ''; return Promise.resolve(); }
          return api('/api/templates/content?type=' + k + '&id=' + encodeURIComponent(st.id)).then(function (r) {
            contents[k] = r.content || '';
          }).catch(function () { contents[k] = ''; });
        });
        return Promise.all(jobs).then(function () {
          loadedStyle = { id: '', name: '当前生效' };
          styles = d.styles || [];
          fillStyleSel();
          uiReady = true;
          setCurKind(curKind);
        });
      });
    }
    /** 按某个风格包加载 6 类内容（内置=static，用户=API） */
    function loadStyle(id) {
      if (!id) return loadActive();
      return api('/api/templates/style/get?id=' + encodeURIComponent(id)).then(function (d) {
        if (d.builtin) {
          // 内置 → fetch static tpl.json
          return fetch('/wp-content/themes/' + encodeURIComponent(id) + '/tpl.json').then(function (r) {
            if (!r.ok) throw new Error('内置风格资源未部署（' + r.status + '）：请先在 WP 端生成并 wrangler deploy');
            return r.json();
          }).then(function (obj) {
            var tp = (obj && obj.tpl) ? obj.tpl : obj;
            TPL_ORDER.forEach(function (k) { contents[k] = String((tp && tp[k]) || ''); });
            loadedStyle = { id: d.id || id, name: d.name || id, builtin: true };
            uiReady = true;
            setCurKind(curKind);
          });
        }
        TPL_ORDER.forEach(function (k) { contents[k] = String((d.tpl && d.tpl[k]) || ''); });
        loadedStyle = { id: d.id || id, name: d.name || id, builtin: false };
        uiReady = true;
        setCurKind(curKind);
      });
    }

    // ---- 风格应用卡 ----
    document.getElementById('st-preview').addEventListener('click', function () {
      var id = document.getElementById('st-sel').value;
      api('/api/templates/style/preview', { method: 'POST', body: id ? { id: id } : {} }).then(function (d) {
        openStylePreview(d.pages || []);
      }).catch(function (e) { flash('flash-main', e.message, 'err'); });
    });
    document.getElementById('st-apply').addEventListener('click', function () {
      var id = document.getElementById('st-sel').value;
      if (!id) { flash('flash-main', '先选择一个风格', 'err'); return; }
      var prog = document.getElementById('st-prog');
      var getTpl = null;
      if (id === '__default__') {
        getTpl = Promise.resolve({ tpl: {}, name: '默认风格（内置模板）' });
      } else {
        getTpl = api('/api/templates/style/get?id=' + encodeURIComponent(id)).then(function (g) {
          if (g.builtin) {
            return fetch('/wp-content/themes/' + encodeURIComponent(id) + '/tpl.json').then(function (r) {
              if (!r.ok) throw new Error('内置风格资源未部署（' + r.status + '）：请先在 WP 端生成并 wrangler deploy');
              return r.json();
            }).then(function (obj) {
              var tp = (obj && obj.tpl) ? obj.tpl : obj;
              return { tpl: tp, name: g.name || id };
            });
          }
          return { tpl: g.tpl || {}, name: g.name || id };
        });
      }
      getTpl.then(function (g) {
        var txt = Object.keys(g.tpl || {}).map(function (k) { return String(g.tpl[k] || ''); }).join('\n');
        if (/\/wp-content\//.test(txt)) {
          if (!confirm('该风格引用了 /wp-content/... 静态资源。\n\n这类资源由：部署打包的 static（assets 免费直出）→ 未命中回退 R2。改动主题后需在 WP 后台点「同步主题资源」再 wrangler deploy。\n\n若不想依赖部署，建议把资源放进风格目录、模板里改用 /style/<风格名>/文件名 引用（R2 直出）。\n\n仍要应用吗？')) return null;
        }
        return api('/api/templates/style/apply', { method: 'POST', body: { id: id, name: g.name, tpl: g.tpl } });
      }).then(function (d) {
        if (!d) return;
        flash('flash-main', '已应用风格「' + d.name + '」', 'ok');
        return refreshStyles();
      }).then(function () {
        if (document.getElementById('st-rebuild').checked) {
          prog.textContent = '开始重建单页…';
          (function run(offset) {
            api('/api/posts/rebuild-single?limit=15&offset=' + offset, { method: 'POST' }).then(function (d) {
              prog.textContent = '重建单页 ' + d.rebuilt + ' / 共 ' + d.total + (d.nextOffset ? '…' : '');
              if (d.nextOffset) run(d.nextOffset);
              else { prog.textContent = '重建完成'; flash('flash-main', '风格已应用，单页已重建', 'ok'); }
            }).catch(function (err) { prog.textContent = '重建中断：' + err.message; });
          })(0);
        }
      }).catch(function (e) { flash('flash-main', e.message, 'err'); });
    });

    document.getElementById('st-dirbtn').addEventListener('click', function () { document.getElementById('st-dir').click(); });
    document.getElementById('st-dir').addEventListener('change', function () {
      var files = Array.prototype.slice.call(this.files || []);
      this.value = '';
      if (!files.length) return;
      var prog = document.getElementById('st-prog');
      // 单文件 JSON → 直接导入风格包（兼容 WP 端「导出风格包 JSON」与手动分享）
      if (files.length === 1 && /\.json$/i.test(files[0].name || '')) {
        var rdr0 = new FileReader();
        rdr0.onload = function () {
          var obj0 = null;
          try { obj0 = JSON.parse(String(rdr0.result || '')); } catch (e) { flash('flash-main', 'JSON 解析失败：' + e.message, 'err'); return; }
          var tplIn = (obj0 && obj0.tpl) ? obj0.tpl : obj0;
          if (!tplIn || typeof tplIn !== 'object') { flash('flash-main', '风格包格式不正确（缺 tpl 对象）', 'err'); return; }
          var nm0 = (obj0 && obj0.name) ? String(obj0.name) : files[0].name.replace(/\.json$/i, '');
          api('/api/templates/style/save', { method: 'POST', body: { name: nm0, tpl: tplIn } }).then(function (d) {
            flash('flash-main', '已导入风格「' + d.name + '」——可预览/应用', 'ok');
            refreshStyles();
          }).catch(function (e) { flash('flash-main', e.message, 'err'); });
        };
        rdr0.readAsText(files[0]);
        return;
      }
      var nameMap = { 'single.html': 'single', 'item.html': 'item', 'pagehome.html': 'pageHome', 'home.html': 'pageHome', 'pagecategory.html': 'pageCategory', 'category.html': 'pageCategory', 'pagetag.html': 'pageTag', 'tag.html': 'pageTag', 'pagearchive.html': 'pageArchive', 'archive.html': 'pageArchive' };
      var tpl = {};
      var assets = [];
      var styleName = '';
      var rel0 = (files[0].webkitRelativePath || '').split('/')[0] || '';
      var reads = [];
      files.forEach(function (f) {
        var base = (f.name || '').toLowerCase();
        if (base === 'style.json') {
          reads.push(new Response(f).json().then(function (o) { if (o && o.name) styleName = String(o.name); }).catch(function () {}));
        } else if (nameMap[base]) {
          var kind = nameMap[base];
          reads.push(new Response(f).text().then(function (t) { tpl[kind] = String(t); }));
        } else {
          assets.push(f);
        }
      });
      Promise.all(reads).then(function () {
        var kinds = Object.keys(tpl);
        if (!kinds.length) throw new Error('未识别到模板文件（需要 single.html/item.html/pageHome.html 等命名）');
        if (!styleName) styleName = rel0 || ('风格 ' + new Date().toISOString().slice(0, 16).replace('T', ' '));
        prog.textContent = '模板 ' + kinds.length + ' 个，资源 ' + assets.length + ' 个，保存中…';
        return api('/api/templates/style/save', { method: 'POST', body: { name: styleName, tpl: tpl } }).then(function (d) {
          if (!assets.length) return { saved: d, done: 0, failed: 0 };
          var slug = tplSlug(styleName) || 'style';
          var done = 0, failed = 0;
          return assets.reduce(function (p, f) {
            return p.then(function () {
              var fd = new FormData();
              fd.append('file', f);
              fd.append('style', slug);
              fd.append('path', f.webkitRelativePath || f.name);
              prog.textContent = '保存中… 资源 ' + (done + failed) + '/' + assets.length;
              return api('/api/style-assets', { method: 'POST', body: fd }).then(function () { done++; }).catch(function () { failed++; });
            });
          }, Promise.resolve()).then(function () { return { saved: d, done: done, failed: failed }; });
        });
      }).then(function (r) {
        prog.textContent = '完成';
        flash('flash-main', '已导入风格「' + r.saved.name + '」' + (r.done ? '，资源 ' + r.done + ' 个' + (r.failed ? '（失败 ' + r.failed + '）' : '') : '') + '——可预览/应用', 'ok');
        refreshStyles();
      }).catch(function (e) { prog.textContent = ''; flash('flash-main', e.message, 'err'); });
    });
    document.getElementById('st-dl').addEventListener('click', function () {
      var id = document.getElementById('st-sel').value;
      api('/api/templates/style/get' + (id ? '?id=' + encodeURIComponent(id) : '')).then(function (d) {
        var blob = new Blob([JSON.stringify(d, null, 2)], { type: 'application/json;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'cfse-style-' + (d.name || 'current').replace(/[^\w\u4e00-\u9fa5-]+/g, '-') + '.json';
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
      }).catch(function (e) { flash('flash-main', e.message, 'err'); });
    });
    document.getElementById('st-del').addEventListener('click', function () {
      var id = document.getElementById('st-sel').value;
      if (!id) { flash('flash-main', '先选择一个风格', 'err'); return; }
      var cur = styles.filter(function (s) { return s.id === id; })[0];
      if (cur && cur.source === 'builtin') {
        if (!confirm('「' + cur.name + '」是内置风格（部署自 WP 端），不可删除。\n\n可「另存为」生成用户风格，或从 static 回退原版。继续？')) return;
        flash('flash-main', '内置风格不可删除，可另存为用户风格', 'err');
        return;
      }
      if (!confirm('删除所选风格？\n（已应用到线上的模板与 R2 资源不受影响；D1 历史版本一并删除）')) return;
      api('/api/templates/style/delete', { method: 'POST', body: { id: id } }).then(function () {
        flash('flash-main', '已删除', 'ok'); refreshStyles();
      }).catch(function (e) { flash('flash-main', e.message, 'err'); });
    });

    // ---- 自定义风格卡 ----
    sec.querySelectorAll('[data-kind]').forEach(function (b) {
      b.addEventListener('click', function () { setCurKind(b.getAttribute('data-kind')); });
    });
    document.getElementById('kd-sel').addEventListener('change', function () {
      var v = this.value;
      if (!v) return;
      loadStyle(v).catch(function (e) { flash('flash-main', e.message, 'err'); });
    });
    document.getElementById('kd-prev').addEventListener('click', function () {
      contents[curKind] = kindArea().value;
      var has = TPL_ORDER.some(function (k) { return (contents[k] || '').trim(); });
      if (!has) { flash('flash-main', '内容为空（当前是内置默认）', 'err'); return; }
      api('/api/templates/style/preview', { method: 'POST', body: { tpl: contents } }).then(function (d) {
        openStylePreview(d.pages || []);
      }).catch(function (e) { flash('flash-main', e.message, 'err'); });
    });
    document.getElementById('kd-save').addEventListener('click', function () {
      contents[curKind] = kindArea().value;
      var has = TPL_ORDER.some(function (k) { return (contents[k] || '').trim(); });
      if (!has) { flash('flash-main', '内容为空，没有可保存的模板', 'err'); return; }
      var isBuiltin = !!(loadedStyle.builtin);
      var def = (loadedStyle.id && !isBuiltin) ? loadedStyle.name : ((loadedStyle.name || '') + ' v2');
      var name = prompt(isBuiltin ? '「' + loadedStyle.name + '」是内置风格，将另存为用户风格。\n请输入新风格名（不得与内置同名）：' : '保存为风格名（同名覆盖=新版本，新名=新建）：', def);
      if (name === null || !String(name).trim()) return;
      name = String(name).trim();
      var body = { name: name, tpl: contents };
      if (!isBuiltin && loadedStyle.id && name === loadedStyle.name) body.id = loadedStyle.id;
      api('/api/templates/style/save', { method: 'POST', body: body }).then(function (d) {
        loadedStyle = { id: d.id, name: d.name, builtin: false };
        flash('flash-main', '已保存风格「' + d.name + '」（版本 v' + d.version + '，可在上方选择后应用）', 'ok');
        return refreshStyles();
      }).catch(function (e) { flash('flash-main', e.message, 'err'); });
    });

    document.getElementById('kd-revert').addEventListener('click', function () {
      if (!loadedStyle.id || loadedStyle.builtin) { flash('flash-main', '仅用户风格可回退历史版本', 'err'); return; }
      api('/api/templates/style/versions', { method: 'POST', body: { id: loadedStyle.id } }).then(function (d) {
        var vs = d.versions || [];
        if (!vs.length) { flash('flash-main', '没有历史版本', 'err'); return; }
        var opts = vs.map(function (v) { return v.version + ' - ' + v.name + '（' + String(v.updated_at || '').slice(0, 16) + '）'; });
        var pick = prompt('选择要回退的版本（回退后另存为新版本，历史保留）：\n\n' + opts.join('\n') + '\n\n输入版本号：', String(vs[0].version));
        if (pick === null) return;
        var ver = parseInt(pick, 10);
        if (!ver) { flash('flash-main', '无效版本号', 'err'); return; }
        return api('/api/templates/style/revert', { method: 'POST', body: { id: loadedStyle.id, version: ver } }).then(function (r) {
          flash('flash-main', '已回退到 v' + ver + '（新版本 v' + r.version + ' 已应用）', 'ok');
          return refreshStyles();
        });
      }).catch(function (e) { flash('flash-main', e.message, 'err'); });
    });
    document.getElementById('kd-cur').textContent = '';

    kindArea().addEventListener('input', function () { contents[curKind] = this.value; });

    loadActive().catch(function (e) { flash('flash-main', e.message, 'err'); });
  }

  function fallbackCopy(t, done, fail) {
    var inp = document.createElement('textarea');
    inp.value = t; document.body.appendChild(inp); inp.select();
    try { document.execCommand('copy'); done(); } catch (e) { fail(); }
    document.body.removeChild(inp);
  }
  function copyText(t, done, fail) {
    if (navigator.clipboard) { navigator.clipboard.writeText(t).then(done, function () { fallbackCopy(t, done, fail); }); }
    else { fallbackCopy(t, done, fail); }
  }

  /* ---------- 路由 / 启动 ---------- */
  var NAVS = { posts: renderPosts, tax: renderTax, media: renderMedia, users: renderUsers, settings: renderSettings, tpl: renderTpl };

  function goNav() {
    var nav = location.hash.replace('#', '') || 'posts';
    // 深链：#edit/<id>、#new
    var editM = nav.match(/^edit\/(\d+)$/);
    if (editM || nav === 'new') {
      document.querySelectorAll('header nav a').forEach(function (a) {
        a.classList.toggle('active', a.getAttribute('data-nav') === 'posts');
      });
      ['posts', 'tax', 'media', 'users', 'settings', 'tpl'].forEach(function (k) {
        var el = document.getElementById('view-' + (k === 'tax' ? 'tax' : k));
        if (el) el.classList.toggle('hidden', k !== 'posts');
      });
      if (nav === 'new') { renderEditor(null); return; }
      api('/api/posts/' + editM[1]).then(function (row) { renderEditor(row); }).catch(function () { renderPosts(); });
      return;
    }
    var ALIAS = { taxonomies: 'tax', categories: 'tax', templates: 'tpl' };
    if (ALIAS[nav]) nav = ALIAS[nav];
    if (!NAVS[nav]) nav = 'posts';
    document.querySelectorAll('header nav a').forEach(function (a) {
      a.classList.toggle('active', a.getAttribute('data-nav') === nav);
    });
    ['posts', 'tax', 'media', 'users', 'settings', 'tpl'].forEach(function (k) {
      var el = document.getElementById('view-' + (k === 'tax' ? 'tax' : k));
      if (el) el.classList.toggle('hidden', nav !== k);
    });
    NAVS[nav]();
  }

  window.addEventListener('hashchange', function () {
    if (getA()) goNav();
  });

  function setWho() {
    var el = document.getElementById('who');
    if (!el) return;
    el.textContent = '';
    if (!getA()) return;
    api('/api/me').then(function (u) {
      el.textContent = u.username ? (u.username + (u.role ? ' · ' + u.role : '')) : '';
    }).catch(function () { el.textContent = ''; });
    api('/api/settings').then(function (s) {
      var sn = document.getElementById('site-name');
      if (sn && s.site && s.site.name) sn.textContent = '☁️ ' + String(s.site.name).slice(0, 24);
    }).catch(function () {});
  }

  function boot() {
    flash('flash-main', '', null);
    flash('flash-login', '', null);
    if (!needAuth()) { setWho(); return; }
    setWho();
    goNav();
  }

  bindLogin();
  boot();
})();
