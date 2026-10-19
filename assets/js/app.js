/* ============================================================
 * VortexPan - 主应用（文件管理器）
 * ============================================================ */
(function () {
  'use strict';
  const { el, h, esc, api, toast, dialog, confirm, prompt, menu, icon, fileIcon, size, timeAgo, fullTime, copy, debounce } = Pan;
  const cfg = Pan.cfg;
  const IDX = cfg.index;
  const app = document.getElementById('app');

  const S = {
    mode: 'all',           // all | star | trash | search | type | shares
    filterType: '',
    keyword: '',
    parentId: 0,
    nodes: [],
    crumbs: [],
    sel: new Set(),
    lastIdx: -1,
    view: localStorage.getItem('vp_view') || 'list',
    sort: localStorage.getItem('vp_sort') || 'name_asc',
    user: cfg.user || null,
    infoId: null,
    showInfo: localStorage.getItem('vp_info') === '1',
    tree: {},              // parentId -> {open, loaded, children}
    colPath: [],
    colCache: {},
    clipboard: null,       // {ids, mode:'copy'|'cut'}
    shares: [],
    pvIndex: -1,
    pvList: [],
  };

  const TYPE_TABS = [
    { k: 'image', n: '图片' }, { k: 'video', n: '视频' }, { k: 'audio', n: '音乐' },
    { k: 'doc', n: '文档' }, { k: 'zip', n: '压缩包' }, { k: 'code', n: '代码' }, { k: 'other', n: '其它' },
  ];

  function apiUrl(p, q) {
    let u = IDX + '?_route=' + p;
    if (q) for (const k in q) u += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(q[k]);
    return u;
  }
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.from((r || document).querySelectorAll(s)); }

  /* ================= 启动 ================= */
  function boot() {
    if (!S.user) return renderAuth();
    mount();
    reload();
    loadTree(0, true);
    loadStat();
    bindGlobal();
  }

  /* ================= 登录 / 注册 ================= */
  function renderAuth() {
    const canReg = cfg.site.register_open;
    app.className = 'auth-page';
    app.innerHTML = `
      <div class="auth-wrap">
        <div class="auth-side">
          <div class="logo"><span class="box">${icon('cloud', 22)}</span><span>VortexPan</span></div>
          <div>
            <h1>把文件放进属于<br>自己的那朵云</h1>
            <p>${esc(cfg.site.desc || '轻量、干净、够快的私有网盘系统')}</p>
          </div>
          <div class="auth-feat">
            <div>${icon('columns', 17)} <span>列表 / 网格 / Mac 分栏视图</span></div>
            <div>${icon('upload', 17)} <span>拖拽上传 · 断点分片 · 秒传</span></div>
            <div>${icon('share', 17)} <span>短链分享 · 提取码 · 有效期</span></div>
            <div>${icon('shield', 17)} <span>配额 / 限速 / 用户组权限</span></div>
          </div>
        </div>
        <div class="auth-form">
          <div class="auth-tabs">
            <button data-t="login" class="active">登录</button>
            ${canReg ? '<button data-t="reg">注册</button>' : ''}
          </div>
          <div id="auth-pane"></div>
        </div>
      </div>`;
    $$('.auth-tabs button', app).forEach(b => b.onclick = () => {
      $$('.auth-tabs button', app).forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      renderAuthPane(b.dataset.t);
    });
    renderAuthPane('login');
  }

  function renderAuthPane(tab) {
    const pane = $('#auth-pane', app);
    if (tab === 'reg') {
      pane.innerHTML = `<h2>创建账号</h2><div class="sub">注册后即可获得网盘空间</div>
        <div class="field"><label class="field-label">用户名</label><input class="input" id="r-u" placeholder="3-20 位字母/数字/下划线"></div>
        <div class="field"><label class="field-label">邮箱（可选）</label><input class="input" id="r-e" placeholder="you@example.com"></div>
        <div class="field"><label class="field-label">密码</label><input class="input" id="r-p" type="password" placeholder="至少 6 位"></div>
        <div class="field"><label class="field-label">确认密码</label><input class="input" id="r-p2" type="password" placeholder="再次输入"></div>
        <button class="btn btn-primary btn-lg" id="r-btn" style="width:100%;margin-top:6px">立即注册</button>
        <div class="auth-swap">已有账号？<a href="javascript:;" id="to-login">去登录</a></div>`;
      $('#to-login', pane).onclick = () => $$('.auth-tabs button', app)[0].click();
      $('#r-btn', pane).onclick = async () => {
        const btn = $('#r-btn', pane); btn.disabled = true;
        try {
          await api('api/auth/register', {
            username: $('#r-u', pane).value.trim(), email: $('#r-e', pane).value.trim(),
            password: $('#r-p', pane).value, password_confirm: $('#r-p2', pane).value,
          });
          toast('注册成功，正在进入…', 'ok');
          setTimeout(() => location.reload(), 500);
        } catch (e) { toast(e.message, 'err'); btn.disabled = false; }
      };
      return;
    }
    pane.innerHTML = `<h2>欢迎回来</h2><div class="sub">登录后继续管理你的文件</div>
      <div class="field"><label class="field-label">用户名 / 邮箱</label><input class="input" id="l-u" placeholder="请输入用户名"></div>
      <div class="field"><label class="field-label">密码</label><input class="input" id="l-p" type="password" placeholder="请输入密码"></div>
      <div class="row-flex" style="margin:2px 0 18px">
        <label class="row-flex"><input type="checkbox" class="cbx" id="l-r"><span class="small muted" style="margin-left:6px">记住我</span></label>
      </div>
      <button class="btn btn-primary btn-lg" id="l-btn" style="width:100%">登录</button>
      ${cfg.site.register_open ? '<div class="auth-swap">还没有账号？<a href="javascript:;" id="to-reg">注册一个</a></div>' : '<div class="auth-swap">站点当前未开放注册</div>'}`;
    $('#to-reg', pane) && ($('#to-reg', pane).onclick = () => { const b = $$('.auth-tabs button', app)[1]; b && b.click(); });
    const submit = async () => {
      const btn = $('#l-btn', pane); btn.disabled = true; btn.textContent = '登录中…';
      try {
        await api('api/auth/login', { username: $('#l-u', pane).value.trim(), password: $('#l-p', pane).value, remember: $('#l-r', pane).checked ? 1 : 0 });
        location.reload();
      } catch (e) { toast(e.message, 'err'); btn.disabled = false; btn.textContent = '登录'; }
    };
    $('#l-btn', pane).onclick = submit;
    $$('#l-p,#l-u', pane).forEach(i => i.onkeydown = e => { if (e.key === 'Enter') submit(); });
    setTimeout(() => $('#l-u', pane) && $('#l-u', pane).focus(), 100);
  }

  /* ================= 主骨架 ================= */
  function mount() {
    app.className = '';
    app.innerHTML = `
      <div class="layout">
        <aside class="sidebar" id="sb"></aside>
        <main class="main">
          <div class="topbar">
            <div class="topbar-title">${esc(cfg.site.name)}</div>
            <div class="crumbs" id="crumbs"></div>
            <div class="search">
              ${icon('search', 15)}
              <input id="search-input" placeholder="搜索我的文件" value="">
              <button class="btn btn-sm btn-ghost" id="search-clear" style="display:none;padding:0 4px">${icon('close', 14)}</button>
            </div>
            <button class="btn btn-icon" id="btn-upload-top" title="上传文件">${icon('upload', 17)}</button>
            <div id="top-user"></div>
          </div>
          <div class="toolbar" id="toolbar"></div>
          <div class="selection-bar" id="selbar"></div>
          <div class="content" id="content"></div>
        </main>
        <aside class="info-panel" id="info" style="display:none"></aside>
      </div>
      <div id="upload-drawer"></div>`;

    $('#search-input', app).addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        const v = e.target.value.trim();
        if (!v) return;
        S.mode = 'search'; S.keyword = v; S.sel.clear(); S.parentId = 0;
        $('#search-clear', app).style.display = '';
        reload();
      }
    });
    $('#search-clear', app).onclick = () => {
      $('#search-input', app).value = ''; $('#search-clear', app).style.display = 'none';
      S.mode = 'all'; S.keyword = ''; reload();
    };
    $('#btn-upload-top', app).onclick = pickFiles;
    renderTopUser();
  }

  function renderTopUser() {
    const u = S.user;
    $('#top-user', app).innerHTML = `<div class="avatar" style="cursor:pointer" id="u-av">${esc((u.nickname || u.username || 'U')[0].toUpperCase())}</div>`;
    $('#u-av', app).onclick = e => {
      e.stopPropagation();
      menu(e.clientX, e.clientY + 6, [
        { label: '个人资料', icon: icon('user', 15), on: showProfile },
        { label: '修改密码', icon: icon('key', 15), on: showPassword },
        u.is_admin ? { label: '管理后台', icon: icon('shield', 15), on: () => location.href = apiUrl('/admin') } : '-',
        '-',
        { label: '退出登录', icon: icon('logout', 15), danger: true, on: async () => { await api('api/auth/logout'); location.reload(); } },
      ]);
    };
  }

  function showProfile() {
    const d = dialog({
      title: '个人资料', size: 'sm',
      body: (() => {
        const wrap = h(`<div>
          <div class="field"><div class="field-label">昵称</div><input class="input" id="p-nick" value="${esc(S.user.nickname || '')}"></div>
          <div class="field"><div class="field-label">邮箱</div><input class="input" id="p-email" value="${esc(S.user.email || '')}"></div>
          <div class="field"><div class="field-label">手机号</div><input class="input" id="p-phone" value="${esc(S.user.phone || '')}"></div>
          <div class="row-flex muted small" style="gap:16px;margin-top:4px">
            <span>用户组：${esc(S.user.group_name)}</span>
            <span>已用 ${esc(size(S.user.used))} / ${esc(size(S.user.quota))}</span>
          </div>
        </div>`);
        return wrap;
      })(),
      onOk: async () => {
        await api('api/auth/profile', { nickname: $('#p-nick').value.trim(), email: $('#p-email').value.trim(), phone: $('#p-phone').value.trim() });
        toast('已保存', 'ok'); await refreshUser();
      },
    });
  }

  function showPassword() {
    dialog({
      title: '修改密码', size: 'sm',
      body: `<div class="field"><div class="field-label">原密码</div><input class="input" id="pw-old" type="password"></div>
             <div class="field"><div class="field-label">新密码</div><input class="input" id="pw-new" type="password" placeholder="至少 6 位"></div>`,
      onOk: async () => {
        await api('api/auth/password', { old_password: $('#pw-old').value, new_password: $('#pw-new').value });
        toast('密码已修改', 'ok');
      },
    });
  }

  async function refreshUser() {
    S.user = await api('api/auth/me');
    renderTopUser(); renderSidebar();
  }

  /* ================= 侧边栏 ================= */
  function renderSidebar() {
    const u = S.user;
    const nav = [
      { id: 'all', name: '全部文件', icon: 'folder' },
      { id: 'star', name: '我的收藏', icon: 'star' },
      { id: 'shares', name: '我的分享', icon: 'share' },
      { id: 'trash', name: '回收站', icon: 'trash' },
    ];
    const activeId = S.mode === 'type' ? '' : S.mode;
    $('#sb', app).innerHTML = `
      <div class="brand">
        <div class="brand-logo">${icon('cloud', 19)}</div>
        <div><div class="brand-name">${esc(cfg.site.name)}</div><div class="brand-sub">v${esc(cfg.version)}</div></div>
      </div>
      <div class="nav">
        ${nav.map(n => `<div class="nav-item ${activeId === n.id ? 'active' : ''}" data-nav="${n.id}">${icon(n.icon, 17)}<span>${n.name}</span></div>`).join('')}
      </div>
      <div class="nav-sep">文件类型</div>
      <div class="nav">
        ${TYPE_TABS.map(t => `<div class="nav-item ${S.mode === 'type' && S.filterType === t.k ? 'active' : ''}" data-type="${t.k}">${icon(['image', 'video', 'audio', 'doc', 'xls', 'ppt', 'zip', 'code', 'text'].includes(t.k) ? t.k : 'file', 17)}<span>${t.n}</span></div>`).join('')}
      </div>
      <div class="nav-sep">文件夹</div>
      <div class="tree" id="tree"></div>
      <div class="quota">
        <div class="quota-top"><span>存储空间</span><span>${size(u.used)} / ${size(u.quota)}</span></div>
        <div class="quota-bar"><div class="quota-fill ${u.percent > 90 ? 'full' : u.percent > 70 ? 'hot' : ''}" style="width:${Math.min(100, u.percent || 0)}%"></div></div>
        <div class="small muted" style="margin-top:7px;display:flex;justify-content:space-between">
          <span>剩余 ${size(Math.max(0, u.quota - u.used))}</span>
          ${u.download_speed ? `<span>↓ ${size(u.download_speed * 1024)}/s</span>` : '<span>不限速</span>'}
        </div>
      </div>
      <div class="user-card" id="sb-user">
        <div class="avatar">${esc((u.nickname || u.username || 'U')[0].toUpperCase())}</div>
        <div class="user-meta"><div class="user-name">${esc(u.nickname || u.username)}</div><div class="user-group">${esc(u.group_name)}</div></div>
        ${u.is_admin ? `<span class="chip chip-primary">管理员</span>` : ''}
      </div>`;

    $$('[data-nav]', app).forEach(n => n.onclick = () => {
      const id = n.dataset.nav;
      S.mode = id; S.filterType = ''; S.keyword = ''; S.parentId = 0; S.sel.clear(); S.colPath = [];
      $('#search-input', app).value = ''; $('#search-clear', app).style.display = 'none';
      reload();
    });
    $$('[data-type]', app).forEach(n => n.onclick = () => {
      S.mode = 'type'; S.filterType = n.dataset.type; S.keyword = ''; S.sel.clear();
      reload();
    });
    $('#sb-user', app).onclick = e => {
      menu(e.clientX + 10, e.clientY, [
        { label: '个人资料', icon: icon('user', 15), on: showProfile },
        { label: '修改密码', icon: icon('key', 15), on: showPassword },
        S.user.is_admin ? { label: '管理后台', icon: icon('shield', 15), on: () => location.href = apiUrl('/admin') } : '-',
        '-',
        { label: '退出登录', icon: icon('logout', 15), danger: true, on: async () => { await api('api/auth/logout'); location.reload(); } },
      ]);
    };
    renderTree();
  }

  async function loadTree(parentId, open) {
    if (!S.tree[parentId]) S.tree[parentId] = { open: false, loaded: false, children: [] };
    const t = S.tree[parentId];
    if (!t.loaded) {
      try { t.children = await api('api/files/tree', { parent_id: parentId }); t.loaded = true; }
      catch (e) { toast(e.message, 'err'); return; }
    }
    if (open) t.open = true;
    renderTree();
  }

  function renderTree() {
    const root = h(`<div>
      <div class="tree-node ${S.mode === 'all' && S.parentId === 0 ? 'active' : ''}" data-fold="0">${icon('home', 16)}<span class="tree-label">根目录</span></div>
      ${renderTreeNodes(0)}
    </div>`);
    const box = $('#tree', app);
    if (!box) return;
    box.innerHTML = '';
    box.appendChild(root);

    $$('[data-fold]', box).forEach(n => {
      n.onclick = async e => {
        e.stopPropagation();
        const id = +n.dataset.fold;
        const t = S.tree[id];
        if (id !== 0 && (!t || !t.open)) await loadTree(id, true);
        else if (t) { t.open = !t.open; renderTree(); }
        if (S.mode !== 'all') S.mode = 'all';
        if (S.view === 'columns' && id !== 0) { S.colPath = [id]; }
        S.parentId = id; S.sel.clear(); S.filterType = '';
        await reload();
        renderSidebar();
      };
      n.addEventListener('dragover', e => { e.preventDefault(); n.classList.add('drop-in'); });
      n.addEventListener('dragleave', () => n.classList.remove('drop-in'));
      n.addEventListener('drop', async e => {
        e.preventDefault(); n.classList.remove('drop-in');
        const raw = e.dataTransfer.getData('text/plain');
        if (!raw) return;
        const ids = JSON.parse(raw);
        if (ids.includes(+n.dataset.fold)) return;
        await doMove(ids, +n.dataset.fold);
      });
    });
  }

  function renderTreeNodes(parentId, depth) {
    const t = S.tree[parentId];
    if (!t || !t.open || !t.children.length) return '';
    depth = depth || 0;
    if (depth > 6) return '';
    return `<div class="tree-children">${t.children.map(c => `
      <div class="tree-node ${S.mode === 'all' && S.parentId === c.id ? 'active' : ''}" data-fold="${c.id}">
        <span class="tree-caret ${S.tree[c.id] && S.tree[c.id].open ? 'open' : ''}">${icon('right', 13)}</span>
        ${icon('folder', 15)}
        <span class="tree-label">${esc(c.name)}</span>
      </div>${renderTreeNodes(c.id, depth + 1)}`).join('')}</div>`;
  }

  /* ================= 面包屑 / 工具栏 ================= */
  function renderCrumbs() {
    const box = $('#crumbs', app);
    box.innerHTML = S.crumbs.map((c, i) => `
      <span class="crumb ${i === S.crumbs.length - 1 ? 'current' : ''}" data-c="${c.id}">${i === 0 ? icon('home', 14) : ''}${esc(c.name)}</span>
      ${i < S.crumbs.length - 1 ? '<span class="crumb-sep">/</span>' : ''}`).join('');
    $$('[data-c]', box).forEach(n => n.onclick = () => {
      if (S.view === 'columns') { S.colPath = n.dataset.c == 0 ? [] : [+n.dataset.c]; }
      S.parentId = +n.dataset.c; S.mode = 'all'; S.sel.clear(); reload();
    });
  }

  function renderToolbar() {
    const t = $('#toolbar', app);
    const canUp = S.mode === 'all';
    const opts = ['name_asc', 'name_desc', 'size_desc', 'size_asc', 'time_desc', 'time_asc']
      .map(v => `<option value="${v}" ${S.sort === v ? 'selected' : ''}>${({ name_asc: '名称 ↑', name_desc: '名称 ↓', size_desc: '大小 ↓', size_asc: '大小 ↑', time_desc: '时间 ↓', time_asc: '时间 ↑' })[v]}</option>`).join('');
    t.innerHTML = `
      <button class="btn btn-primary btn-sm" id="tb-upload">${icon('upload', 15)} 上传文件</button>
      ${canUp ? `<button class="btn btn-sm" id="tb-folder">${icon('folderPlus', 15)} 新建文件夹</button>` : ''}
      <span class="spacer"></span>
      ${S.mode === 'trash' ? `<button class="btn btn-sm btn-danger" id="tb-clear">${icon('trash', 15)} 清空回收站</button>` : ''}
      <select class="select input" id="tb-sort" style="width:110px;height:30px;font-size:12.5px">${opts}</select>
      <div class="view-switch">
        <button data-v="list" class="${S.view === 'list' ? 'active' : ''}" title="列表视图">${icon('list', 16)}</button>
        <button data-v="grid" class="${S.view === 'grid' ? 'active' : ''}" title="图标视图">${icon('grid', 16)}</button>
        <button data-v="columns" class="${S.view === 'columns' ? 'active' : ''}" title="分栏视图">${icon('columns', 16)}</button>
      </div>
      <button class="btn btn-sm btn-icon" id="tb-refresh" title="刷新">${icon('refresh', 16)}</button>
      <button class="btn btn-sm btn-icon ${S.showInfo ? 'btn-primary' : ''}" id="tb-info" title="信息面板">${icon('info', 16)}</button>`;

    $('#tb-upload', t).onclick = pickFiles;
    $('#tb-folder', t) && ($('#tb-folder', t).onclick = createFolder);
    $('#tb-clear', t) && ($('#tb-clear', t).onclick = clearTrash);
    $('#tb-refresh', t).onclick = () => { S.tree = {}; S.colCache = {}; loadTree(0, true); reload(); };
    $('#tb-info', t).onclick = () => { S.showInfo = !S.showInfo; localStorage.setItem('vp_info', S.showInfo ? '1' : '0'); renderToolbar(); renderInfo(); };
    $('#tb-sort', t).onchange = e => { S.sort = e.target.value; localStorage.setItem('vp_sort', S.sort); reload(); };
    $$('[data-v]', t).forEach(b => b.onclick = () => {
      S.view = b.dataset.v; localStorage.setItem('vp_view', S.view);
      if (S.view === 'columns') S.colPath = S.parentId ? [S.parentId] : [];
      renderToolbar(); renderContent();
    });
  }

  /* ================= 数据加载 ================= */
  async function reload() {
    try {
      if (S.mode === 'shares') {
        S.shares = await api('api/share/my');
        S.nodes = [];
      } else {
        const params = { parent_id: S.parentId, sort: S.sort };
        if (S.mode === 'search') params.keyword = S.keyword;
        else if (S.mode === 'type') params.type = S.filterType;
        else if (S.mode === 'star') params.type = 'star';
        else if (S.mode === 'trash') params.type = 'trash';
        const r = await api('api/files/list', params);
        S.nodes = r.nodes || [];
        S.crumbs = r.breadcrumb || [];
        S.user = r.user || S.user;
      }
    } catch (e) { toast(e.message, 'err'); S.nodes = []; }
    renderSidebar(); renderCrumbs(); renderToolbar(); renderContent(); renderInfo(); renderSelBar();
  }

  async function loadStat() {
    try {
      const r = await api('api/files/stat');
      S.user = r.user || S.user;
      renderSidebar();
    } catch (e) { /* ignore */ }
  }

  /* ================= 内容区 ================= */
  function renderContent() {
    if (S.mode === 'shares') return renderShares();
    if (S.view === 'grid') return renderGrid();
    if (S.view === 'columns') return renderColumns();
    return renderList();
  }

  function selectedNodes() { return S.nodes.filter(n => S.sel.has(n.id)); }

  function renderShares() {
    const c = $('#content', app);
    c.innerHTML = `<div class="list-view">
      <div class="card" style="margin:12px">
        <div class="card-head"><h3>我的分享</h3><span class="chip">${S.shares.length} 条</span></div>
        <div style="overflow:auto">
        ${S.shares.length ? `<table class="table">
          <thead><tr><th>名称</th><th>链接</th><th>提取码</th><th>有效期</th><th>访问/下载</th><th>状态</th><th style="text-align:right">操作</th></tr></thead>
          <tbody>${S.shares.map(s => `<tr>
            <td><div class="row-flex" style="gap:8px">${icon(s.node && s.node.type === 'folder' ? 'folder' : 'file', 16)}<div><div style="font-weight:500">${esc(s.title)}</div><div class="small muted">${esc(s.node ? s.node.name : '（已删除）')}</div></div></div></td>
            <td><span class="small mono link-copy" data-copy="${esc(s.url)}">${esc(s.url.replace(/^https?:\/\//, ''))}</span></td>
            <td>${s.has_pickcode ? `<span class="chip chip-warn">${esc(s.pickcode)}</span>` : '<span class="muted small">无</span>'}</td>
            <td class="small">${esc(s.expire_text)}</td>
            <td class="small num">${s.view_count} / ${s.download_count}</td>
            <td>${s.status ? '<span class="chip chip-success">生效中</span>' : '<span class="chip">已关闭</span>'}</td>
            <td style="text-align:right;white-space:nowrap">
              <button class="btn btn-sm" data-sh-copy="${esc(s.url)}">复制链接</button>
              <button class="btn btn-sm" data-sh-toggle="${s.id}">${s.status ? '关闭' : '开启'}</button>
              <button class="btn btn-sm btn-danger" data-sh-del="${s.id}">删除</button>
            </td></tr>`).join('')}</tbody></table>` : '<div class="empty"><div class="empty-title">还没有分享内容</div><div class="small">在文件上右键 → 创建分享链接</div></div>'}
        </div>
      </div></div>`;
    $$('[data-copy]', c).forEach(n => n.onclick = () => copy(n.dataset.copy));
    $$('[data-sh-copy]', c).forEach(n => n.onclick = () => copy(n.dataset.shCopy));
    $$('[data-sh-del]', c).forEach(n => n.onclick = async () => {
      if (!await confirm('删除分享', '删除后链接立即失效，确定继续？')) return;
      await api('api/share/delete', { id: +n.dataset.shDel }); toast('已删除', 'ok'); reload();
    });
    $$('[data-sh-toggle]', c).forEach(n => n.onclick = async () => {
      const s = S.shares.find(x => x.id === +n.dataset.shToggle);
      await api('api/share/update', { id: s.id, status: s.status ? 0 : 1 });
      toast('已更新', 'ok'); reload();
    });
  }

  function renderList() {
    const c = $('#content', app);
    if (!S.nodes.length) return emptyBox(c);
    c.innerHTML = `<div class="list-view" id="lv">
      <div class="list-head">
        <span class="col-check"><input type="checkbox" class="cbx" id="ck-all"></span>
        <span class="col-icon"></span><span class="col-name">名称</span>
        <span class="col-size">大小</span><span class="col-time">修改时间</span><span class="col-act"></span>
      </div>
      <div id="rows">${S.nodes.map((n, i) => rowHtml(n, i)).join('')}</div>
    </div>`;
    bindRows($('#rows', c), $('#lv', c));
    $('#ck-all', c).onchange = e => {
      if (e.target.checked) S.nodes.forEach(n => S.sel.add(n.id)); else S.sel.clear();
      syncSelUI();
    };
    syncSelUI();
  }

  function rowHtml(n, i) {
    const modTime = n.updated_at || n.created_at || (S.mode === 'trash' ? n.deleted_at : '');
    return `<div class="list-row ${S.clipboard && S.clipboard.mode === 'cut' && S.clipboard.ids.includes(n.id) ? 'cut' : ''}" data-id="${n.id}" data-i="${i}" draggable="true">
      <span class="col-check"><input type="checkbox" class="cbx ck" ${S.sel.has(n.id) ? 'checked' : ''}></span>
      <span class="col-icon">${fileIcon(n, 26)}</span>
      <span class="col-name">
        <span class="txt">${esc(n.name)}</span>
        ${n.star ? `<span style="color:#f59e0b">${icon('star', 13)}</span>` : ''}
        ${n.share_code ? `<span class="chip chip-primary" style="height:18px;padding:0 6px">分享</span>` : ''}
      </span>
      <span class="col-size">${n.type === 'folder' ? '—' : size(n.size)}</span>
      <span class="col-time">${timeAgo(modTime)}</span>
      <span class="col-act">
        ${S.mode === 'trash'
        ? `<button class="btn btn-sm btn-icon btn-ghost" data-act="restore" title="恢复">${icon('restore', 15)}</button>
             <button class="btn btn-sm btn-icon btn-ghost" data-act="purge" title="彻底删除">${icon('trash', 15)}</button>`
        : `<button class="btn btn-sm btn-icon btn-ghost" data-act="download" title="下载">${icon('download', 15)}</button>
             <button class="btn btn-sm btn-icon btn-ghost" data-act="share" title="分享">${icon('share', 15)}</button>
             <button class="btn btn-sm btn-icon btn-ghost" data-act="more" title="更多">${icon('more', 15)}</button>`}
      </span>
    </div>`;
  }

  function emptyBox(c) {
    const map = {
      search: ['没有找到相关文件', '换个关键词试试'],
      trash: ['回收站是空的', '删除的文件会存放在这里'],
      star: ['还没有收藏的文件', '右键文件可以加入收藏'],
      type: ['这里还没有文件', ''],
    };
    const [t, s] = map[S.mode] || ['这里空空如也', '拖拽文件到此处，或点击「上传文件」'];
    c.innerHTML = `<div class="empty">${icon('folder', 46)}<div class="empty-title">${t}</div><div class="small">${s}</div></div>`;
  }

  function bindRows(container, scrollBox) {
    $$('.list-row', container).forEach(row => {
      const id = +row.dataset.id, i = +row.dataset.i;
      row.addEventListener('click', e => {
        if (e.target.closest('.cbx')) return;
        const n = S.nodes.find(x => x.id === id);
        if (!n) return;
        if (e.metaKey || e.ctrlKey) {
          S.sel.has(id) ? S.sel.delete(id) : S.sel.add(id); S.lastIdx = i;
        } else if (e.shiftKey && S.lastIdx >= 0) {
          const [a, b] = [Math.min(S.lastIdx, i), Math.max(S.lastIdx, i)];
          for (let k = a; k <= b; k++) S.sel.add(S.nodes[k].id);
        } else {
          S.sel.clear(); S.sel.add(id); S.lastIdx = i;
        }
        syncSelUI(); S.infoId = id; renderInfo();
      });
      row.addEventListener('dblclick', () => openNode(S.nodes.find(x => x.id === id)));
      row.addEventListener('contextmenu', e => { e.preventDefault(); if (!S.sel.has(id)) { S.sel.clear(); S.sel.add(id); S.lastIdx = i; syncSelUI(); } S.infoId = id; renderInfo(); showCtxMenu(e.clientX, e.clientY, id); });
      row.addEventListener('dragstart', e => {
        let ids = Array.from(S.sel);
        if (!ids.includes(id)) ids = [id];
        e.dataTransfer.setData('text/plain', JSON.stringify(ids));
        e.dataTransfer.effectAllowed = 'move';
      });
      row.addEventListener('dragover', e => {
        e.preventDefault();
        const n = S.nodes.find(x => x.id === id);
        if (n && n.type === 'folder') row.classList.add('drop-into');
      });
      row.addEventListener('dragleave', () => row.classList.remove('drop-into'));
      row.addEventListener('drop', async e => {
        e.preventDefault(); row.classList.remove('drop-into');
        const n = S.nodes.find(x => x.id === id);
        if (!n || n.type !== 'folder') return;
        const raw = e.dataTransfer.getData('text/plain');
        if (raw) { const ids = JSON.parse(raw); if (!ids.includes(id)) await doMove(ids, id); return; }
        if (e.dataTransfer.files.length) await startUpload(e.dataTransfer.files, id);
      });
      $$('[data-act]', row).forEach(b => b.onclick = e => {
        e.stopPropagation();
        const n = S.nodes.find(x => x.id === id);
        const act = b.dataset.act;
        if (act === 'download') download([id]);
        if (act === 'share') shareDialog([id]);
        if (act === 'restore') doRestore([id]);
        if (act === 'purge') doPurge([id]);
        if (act === 'more') { S.sel.clear(); S.sel.add(id); syncSelUI(); showCtxMenu(e.clientX, e.clientY + 10, id); }
      });
    });
    $$('.ck', container).forEach(cb => cb.addEventListener('click', e => {
      e.stopPropagation();
      const row = cb.closest('.list-row'); const id = +row.dataset.id;
      cb.checked ? S.sel.add(id) : S.sel.delete(id);
      syncSelUI();
    }));
  }

  function renderGrid() {
    const c = $('#content', app);
    if (!S.nodes.length) return emptyBox(c);
    c.innerHTML = `<div class="grid-view">${S.nodes.map((n, i) => `
      <div class="grid-item" data-id="${n.id}" data-i="${i}" draggable="true">
        <div class="check-bubble"><input type="checkbox" class="cbx ck" ${S.sel.has(n.id) ? 'checked' : ''}></div>
        <div class="thumb">${fileIcon(n, 58)}</div>
        <div class="g-name" title="${esc(n.name)}">${esc(n.name)}</div>
        <div class="g-meta">${n.type === 'folder' ? '文件夹' : size(n.size)} · ${timeAgo(n.updated_at || n.created_at)}</div>
      </div>`).join('')}</div>`;
    $$('.grid-item', c).forEach(it => {
      const id = +it.dataset.id, i = +it.dataset.i;
      it.onclick = e => {
        if (e.target.closest('.cbx')) return;
        if (e.metaKey || e.ctrlKey) S.sel.has(id) ? S.sel.delete(id) : S.sel.add(id);
        else { S.sel.clear(); S.sel.add(id); }
        S.lastIdx = i; S.infoId = id; syncSelUI(); renderInfo();
      };
      it.ondblclick = () => openNode(S.nodes.find(x => x.id === id));
      it.oncontextmenu = e => { e.preventDefault(); if (!S.sel.has(id)) { S.sel.clear(); S.sel.add(id); } syncSelUI(); showCtxMenu(e.clientX, e.clientY, id); };
      it.addEventListener('dragstart', e => {
        let ids = Array.from(S.sel); if (!ids.includes(id)) ids = [id];
        e.dataTransfer.setData('text/plain', JSON.stringify(ids));
      });
      it.addEventListener('dragover', e => { e.preventDefault(); const n = S.nodes.find(x => x.id === id); if (n && n.type === 'folder') it.classList.add('selected'); });
      it.addEventListener('dragleave', () => it.classList.remove('selected'));
      it.addEventListener('drop', async e => {
        e.preventDefault();
        const n = S.nodes.find(x => x.id === id);
        const raw = e.dataTransfer.getData('text/plain');
        if (raw) { if (n && n.type === 'folder') await doMove(JSON.parse(raw), id); return; }
        if (e.dataTransfer.files.length) await startUpload(e.dataTransfer.files, (n && n.type === 'folder') ? id : S.parentId);
      });
    });
    $$('.ck', c).forEach(cb => cb.onclick = e => {
      e.stopPropagation();
      const it = cb.closest('.grid-item'); const id = +it.dataset.id;
      cb.checked ? S.sel.add(id) : S.sel.delete(id);
      syncSelUI();
    });
    syncSelUI();
  }

  /* ---------- 分栏视图（Mac Finder） ---------- */
  async function renderColumns() {
    const c = $('#content', app);
    c.innerHTML = '<div class="columns-view" id="cv"><div class="column-empty" style="padding:60px">加载中…</div></div>';
    const path = [0].concat(S.colPath);
    const promises = path.map(async pid => {
      if (!S.colCache[pid]) {
        S.colCache[pid] = await api('api/files/list', { parent_id: pid, sort: S.sort });
      }
      return S.colCache[pid];
    });
    const cols = await Promise.all(promises);
    S.nodes = cols[cols.length - 1].nodes || [];
    S.crumbs = cols[cols.length - 1].breadcrumb || [];
    renderCrumbs();

    const cv = $('#cv', c);
    cv.innerHTML = path.map((pid, idx) => {
      const data = cols[idx];
      const isLast = idx === path.length - 1;
      return `<div class="column-pane ${isLast ? 'active' : ''} ${pid === S.parentId ? '' : ''}">
        <div class="column-scroll" data-pane="${pid}" data-i="${idx}">
          ${data.nodes.length ? data.nodes.map(n => `<div class="column-row ${S.sel.has(n.id) ? 'selected' : ''} ${S.colPath.includes(n.id) && n.type === 'folder' ? 'selected' : ''} ${S.clipboard && S.clipboard.mode === 'cut' && S.clipboard.ids.includes(n.id) ? 'cut' : ''}" data-id="${n.id}" data-type="${n.type}" draggable="true">
            ${fileIcon(n, 20)}
            <span class="cr-name">${esc(n.name)}</span>
            <span class="cr-size">${n.type === 'folder' ? '' : size(n.size)}</span>
            ${n.type === 'folder' ? icon('right', 13) : ''}
          </div>`).join('') : '<div class="column-empty">无内容</div>'}
        </div></div>`;
    }).join('') + detailPaneHtml();

    $$('.column-row', cv).forEach(row => {
      const id = +row.dataset.id, type = row.dataset.type;
      row.onclick = () => {
        S.sel.clear(); S.sel.add(id); S.infoId = id;
        if (type === 'folder') {
          const idx = S.colPath.indexOf(id);
          if (idx >= 0) S.colPath = S.colPath.slice(0, idx + 1);
          else S.colPath.push(id);
          S.parentId = id;
          const t = S.tree[S.colPath[S.colPath.length - 2] || 0];
          renderColumns().then(() => { if (t) renderSidebar(); });
        }
        renderColumnsTail();
      };
      row.ondblclick = () => openNode(S.nodes.concat(...cols.map(c => c.nodes)).find(x => x.id === id));
      row.oncontextmenu = e => {
        e.preventDefault(); S.sel.clear(); S.sel.add(id); S.infoId = id;
        const flat = []; cols.forEach(cc => (cc.nodes || []).forEach(n => flat.push(n)));
        S.nodes = flat;
        showCtxMenu(e.clientX, e.clientY, id);
      };
      row.addEventListener('dragover', e => { if (type === 'folder') { e.preventDefault(); row.classList.add('selected'); } });
      row.addEventListener('dragleave', () => row.classList.remove('selected'));
      row.addEventListener('drop', async e => {
        e.preventDefault(); row.classList.remove('selected');
        const raw = e.dataTransfer.getData('text/plain');
        if (raw && type === 'folder') { const ids = JSON.parse(raw); if (!ids.includes(id)) await doMove(ids, id); return; }
        if (e.dataTransfer.files.length) await startUpload(e.dataTransfer.files, type === 'folder' ? id : S.parentId);
      });
      row.addEventListener('dragstart', e => e.dataTransfer.setData('text/plain', JSON.stringify([id])));
    });
    syncSelUI();
  }

  function renderColumnsTail() {
    const cv = $('#cv', app);
    if (!cv) return;
    const old = cv.querySelector('.detail-pane');
    if (old) old.remove();
    cv.insertAdjacentHTML('beforeend', detailPaneHtml());
    bindDetailPane();
    const sel = cv.querySelectorAll('.column-row.selected');
    sel.forEach(s => { if (!S.sel.has(+s.dataset.id)) s.classList.remove('selected'); });
  }

  function getInfoNode() {
    if (!S.infoId) return null;
    if (S.view === 'columns') {
      let found = S.nodes.find(n => n.id === S.infoId);
      if (found) return found;
      for (const k in S.colCache) {
        const f = (S.colCache[k].nodes || []).find(n => n.id === S.infoId);
        if (f) return f;
      }
      return null;
    }
    return S.nodes.find(n => n.id === S.infoId) || null;
  }

  function detailPaneHtml() {
    const n = getInfoNode();
    if (!n) return `<div class="column-pane last-pane"><div class="column-empty" style="padding-top:60px">未选择文件</div></div>`;
    return `<div class="column-pane last-pane detail-pane">
      <div class="column-scroll" style="padding:14px">
        <div style="text-align:center">
          <div style="display:grid;place-items:center;margin-bottom:10px">${fileIcon(n, 58)}</div>
          <div style="font-weight:600;word-break:break-all">${esc(n.name)}</div>
          <div class="small muted">${n.type === 'folder' ? '文件夹' : size(n.size)}</div>
          ${n.type === 'folder' ? `<div class="small muted">${size(0)}</div>` : ''}
        </div>
        <div class="divider"></div>
        ${metaRowsHtml(n)}
        <div class="divider"></div>
        <div style="display:flex;flex-direction:column;gap:6px">
          ${S.mode === 'trash' ? `
            <button class="btn btn-sm" data-dact="restore">${icon('restore', 14)} 恢复</button>
            <button class="btn btn-sm btn-danger" data-dact="purge">${icon('trash', 14)} 彻底删除</button>` : `
            <button class="btn btn-sm btn-primary" data-dact="download">${icon('download', 14)} 下载</button>
            <button class="btn btn-sm" data-dact="share">${icon('share', 14)} 创建分享</button>
            <button class="btn btn-sm" data-dact="rename">${icon('edit', 14)} 重命名</button>
            <button class="btn btn-sm btn-danger" data-dact="trash">${icon('trash', 14)} 移入回收站</button>`}
        </div>
      </div></div>`;
  }

  function bindDetailPane() {
    $$('[data-dact]', app).forEach(b => b.onclick = () => {
      const id = S.infoId;
      const map = {
        download: () => download([id]), share: () => shareDialog([id]),
        rename: () => renameInline(id, 'columns'), trash: () => doTrash([id]),
        restore: () => doRestore([id]), purge: () => doPurge([id]),
      };
      map[b.dataset.dact] && map[b.dataset.dact]();
    });
  }

  function metaRowsHtml(n) {
    const rows = [
      ['类型', n.type === 'folder' ? '文件夹' : (n.mime || n.ext || '未知')],
      ['大小', n.type === 'folder' ? '—' : size(n.size)],
      ['创建时间', fullTime(n.created_at)],
      ['修改时间', fullTime(n.updated_at)],
    ];
    if (n.type === 'file') rows.push(['下载次数', n.download_count + ' 次']);
    return rows.map(r => `<div class="info-row"><span class="k">${r[0]}</span><span class="v">${esc(r[1])}</span></div>`).join('');
  }

  /* ================= 信息面板 ================= */
  function renderInfo() {
    const box = $('#info', app);
    if (!S.showInfo) { box.style.display = 'none'; return; }
    box.style.display = '';
    const n = getInfoNode();
    if (!n) {
      box.innerHTML = `<div class="info-head"><div class="info-preview">${icon('file', 40)}</div><div class="info-title">未选择</div><div class="info-sub">点击文件查看详细信息</div></div>`;
      return;
    }
    const pv = n.type === 'file' && (n.kind === 'image' || n.kind === 'video')
      ? (n.kind === 'image'
        ? `<img src="${apiUrl('/api/files/thumb', { id: n.id })}" alt="">`
        : `<video src="${apiUrl('/api/files/preview', { id: n.id })}" style="max-width:100%;max-height:100%"></video>`)
      : `<div>${fileIcon(n, 62)}</div>`;
    box.innerHTML = `
      <div class="info-head">
        <div class="info-preview">${pv}</div>
        <div class="info-title">${esc(n.name)}</div>
        <div class="info-sub">${n.type === 'folder' ? '文件夹' : size(n.size)} · ${timeAgo(n.updated_at || n.created_at)}</div>
      </div>
      <div class="info-body">${metaRowsHtml(n)}</div>
      <div class="info-foot">
        ${S.mode === 'trash' ? `
          <button class="btn btn-sm" data-iact="restore">${icon('restore', 14)} 恢复</button>
          <button class="btn btn-sm btn-danger" data-iact="purge">${icon('trash', 14)} 彻底删除</button>` : `
          <button class="btn btn-sm btn-primary" data-iact="download">${icon('download', 14)} 下载</button>
          <button class="btn btn-sm" data-iact="share">${icon('share', 14)} 分享</button>
          <button class="btn btn-sm" data-iact="rename">${icon('edit', 14)} 重命名</button>
          <button class="btn btn-sm" data-iact="star">${icon('star', 14)} ${n.star ? '取消收藏' : '收藏'}</button>
          <button class="btn btn-sm btn-danger" data-iact="trash">${icon('trash', 14)} 删除</button>`}
      </div>`;
    $$('[data-iact]', box).forEach(b => b.onclick = () => {
      const id = n.id;
      ({
        download: () => download([id]), share: () => shareDialog([id]),
        rename: () => renameInline(id, S.view), trash: () => doTrash([id]),
        restore: () => doRestore([id]), purge: () => doPurge([id]),
        star: async () => { await api('api/files/star', { id, value: n.star ? 0 : 1 }); toast('已更新', 'ok'); reload(); },
      })[b.dataset.iact]();
    });
  }

  /* ================= 选择态 ================= */
  function syncSelUI() {
    document.querySelectorAll('.list-row,.grid-item,.column-row').forEach(r => {
      const id = +r.dataset.id;
      r.classList.toggle('selected', S.sel.has(id));
      const cb = r.querySelector('.ck'); if (cb) cb.checked = S.sel.has(id);
    });
    renderSelBar();
  }

  function renderSelBar() {
    const bar = $('#selbar', app);
    const n = S.sel.size;
    bar.classList.toggle('show', n > 0);
    if (!n) return;
    if (S.mode === 'trash') {
      bar.innerHTML = `${icon('trash', 16)} 已选中 ${n} 项
        <span class="spacer"></span>
        <button class="btn btn-sm" id="sb-restore">${icon('restore', 14)} 恢复</button>
        <button class="btn btn-sm btn-danger" id="sb-purge">${icon('trash', 14)} 彻底删除</button>
        <button class="btn btn-sm btn-ghost" id="sb-clear">取消</button>`;
      $('#sb-restore', bar).onclick = () => doRestore(Array.from(S.sel));
      $('#sb-purge', bar).onclick = () => doPurge(Array.from(S.sel));
      $('#sb-clear', bar).onclick = () => { S.sel.clear(); syncSelUI(); };
      return;
    }
    bar.innerHTML = `${icon('check', 16)} 已选中 ${n} 项
      <span class="spacer"></span>
      <button class="btn btn-sm" id="sb-dl">${icon('download', 14)} 下载</button>
      <button class="btn btn-sm" id="sb-share">${icon('share', 14)} 分享</button>
      <button class="btn btn-sm" id="sb-copy">${icon('copy', 14)} 复制</button>
      <button class="btn btn-sm" id="sb-cut">${icon('move', 14)} 剪切</button>
      <button class="btn btn-sm btn-danger" id="sb-del">${icon('trash', 14)} 删除</button>
      <button class="btn btn-sm btn-ghost" id="sb-clear">取消</button>`;
    const ids = Array.from(S.sel);
    $('#sb-dl', bar).onclick = () => download(ids);
    $('#sb-share', bar).onclick = () => shareDialog(ids);
    $('#sb-copy', bar).onclick = () => { S.clipboard = { ids, mode: 'copy' }; toast(`已复制 ${ids.length} 项到剪贴板`, 'ok'); renderContent(); };
    $('#sb-cut', bar).onclick = () => { S.clipboard = { ids, mode: 'cut' }; toast(`已剪切 ${ids.length} 项`, 'ok'); renderContent(); };
    $('#sb-del', bar).onclick = () => doTrash(ids);
    $('#sb-clear', bar).onclick = () => { S.sel.clear(); syncSelUI(); };
  }

  /* ================= 右键菜单 ================= */
  function showCtxMenu(x, y, id) {
    const node = S.nodes.find(n => n.id === id) || getInfoNode();
    if (!node) return;
    const sel = Array.from(S.sel);
    const multi = sel.length > 1;
    const items = [];
    if (S.mode === 'trash') {
      items.push(
        { label: '恢复文件', icon: icon('restore', 15), on: () => doRestore(sel.length ? sel : [id]) },
        { label: '彻底删除', icon: icon('trash', 15), danger: true, on: () => doPurge(sel.length ? sel : [id]) },
        '-',
        { label: '清空回收站', icon: icon('trash', 15), danger: true, on: clearTrash });
      return menu(x, y, items);
    }
    if (node.type === 'folder') items.push({ label: '打开', icon: icon('folder', 15), on: () => openNode(node) });
    else items.push({ label: '预览', icon: icon('eye', 15), on: () => openPreview(node) });
    items.push(
      { label: '下载', icon: icon('download', 15), on: () => download(sel.length ? sel : [id]) },
      { label: '创建分享链接', icon: icon('share', 15), on: () => shareDialog(sel.length ? sel : [id]) },
      '-');
    if (!multi) items.push({ label: '重命名', icon: icon('edit', 15), on: () => renameInline(id, S.view) });
    const selIds = sel.length ? sel : [id];
    items.push(
      { label: '复制到…', icon: icon('copy', 15), on: () => pickFolderDialog('复制到目标文件夹', '复制', async tid => {
        try { await api('api/files/copy', { ids: selIds, target_id: tid }); toast('已复制', 'ok'); S.colCache = {}; reload(); loadStat(); }
        catch (e) { toast(e.message, 'err'); } }) },
      { label: '移动到…', icon: icon('move', 15), on: () => pickFolderDialog('移动到目标文件夹', '移动', async tid => { await doMove(selIds, tid); }) },
      { label: '复制', icon: icon('copy', 15), on: () => { S.clipboard = { ids: selIds, mode: 'copy' }; toast('已复制到剪贴板', 'ok'); } },
      { label: '剪切', icon: icon('move', 15), on: () => { S.clipboard = { ids: selIds, mode: 'cut' }; toast('已剪切', 'ok'); renderContent(); } },
      { label: '粘贴到此处', icon: icon('save', 15), disabled: !S.clipboard, on: () => pasteInto(node.type === 'folder' ? node.id : S.parentId) },
      '-',
      { label: node.star ? '取消收藏' : '加入收藏', icon: icon('star', 15), on: async () => { await api('api/files/star', { id, value: node.star ? 0 : 1 }); toast('已更新', 'ok'); reload(); } },
      { label: '详细信息', icon: icon('info', 15), on: () => { S.showInfo = true; localStorage.setItem('vp_info', '1'); S.infoId = id; renderToolbar(); renderInfo(); } },
      '-',
      { label: '移入回收站', icon: icon('trash', 15), danger: true, on: () => doTrash(sel.length ? sel : [id]) });
    menu(x, y, items);
  }

  /* ================= 文件操作 ================= */
  function openNode(n) {
    if (!n) return;
    if (n.type === 'folder') {
      S.mode = 'all'; S.filterType = ''; S.parentId = n.id; S.sel.clear(); S.colCache = {};
      if (S.view === 'columns') S.colPath = [n.id];
      reload(); loadStat();
    } else {
      openPreview(n);
    }
  }

  function download(ids) {
    if (!ids.length) return;
    if (ids.length === 1) {
      window.location.href = apiUrl('/api/files/download', { ids: ids[0] });
      return;
    }
    const form = el('form', { method: 'POST', action: IDX, target: '_blank', style: { display: 'none' } });
    form.appendChild(el('input', { name: '_route', value: '/api/files/download' }));
    form.appendChild(el('input', { name: '_token', value: cfg.csrf }));
    ids.forEach(id => form.appendChild(el('input', { name: 'ids[]', value: id })));
    document.body.appendChild(form);
    form.submit();
    setTimeout(() => form.remove(), 1000);
    toast('开始打包下载，请稍候…', 'ok');
  }

  function createFolder() {
    prompt('新建文件夹', '文件夹名称', '新建文件夹').then(async name => {
      if (!name) return;
      try { await api('api/files/folder', { parent_id: S.parentId, name }); toast('已创建', 'ok'); reload(); loadStat(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }

  function renameInline(id, view) {
    const n = S.nodes.find(x => x.id === id) || getInfoNode();
    if (!n) return;
    const base = n.ext ? n.name.slice(0, -(n.ext.length + 1)) : n.name;
    prompt('重命名', '新名称', n.name).then(async name => {
      if (!name || name === n.name) return;
      if (n.ext && name.indexOf('.') === -1) name = name + '.' + n.ext;
      try { await api('api/files/rename', { id, name }); toast('已重命名', 'ok'); reload(); }
      catch (e) { toast(e.message, 'err'); }
    });
  }

  async function doTrash(ids) {
    if (!ids.length) return;
    if (!await confirm('移入回收站', `确定把选中的 ${ids.length} 项移入回收站？`)) return;
    try { await api('api/files/trash', { ids }); toast('已移入回收站', 'ok'); S.sel.clear(); reload(); loadStat(); }
    catch (e) { toast(e.message, 'err'); }
  }

  async function doRestore(ids) {
    try { await api('api/files/restore', { ids }); toast('已恢复', 'ok'); S.sel.clear(); reload(); loadStat(); }
    catch (e) { toast(e.message, 'err'); }
  }

  async function doPurge(ids) {
    if (!await confirm('彻底删除', '删除后无法找回，确定继续？', { okText: '删除' })) return;
    try { await api('api/files/purge', { ids }); toast('已彻底删除', 'ok'); S.sel.clear(); reload(); loadStat(); }
    catch (e) { toast(e.message, 'err'); }
  }

  async function clearTrash() {
    if (!await confirm('清空回收站', '此操作不可恢复，确定清空？', { okText: '清空' })) return;
    try { await api('api/files/clear-trash'); toast('回收站已清空', 'ok'); reload(); loadStat(); }
    catch (e) { toast(e.message, 'err'); }
  }

  async function doMove(ids, target) {
    try { await api('api/files/move', { ids, target_id: target }); toast('已移动', 'ok'); S.colCache = {}; S.tree = {}; await loadTree(0, true); reload(); }
    catch (e) { toast(e.message, 'err'); }
  }

  async function pasteInto(targetId) {
    if (!S.clipboard) return;
    const { ids, mode } = S.clipboard;
    if (mode === 'cut' && ids.includes(targetId)) return toast('不能移动到自身', 'err');
    try {
      if (mode === 'cut') { await api('api/files/move', { ids, target_id: targetId }); S.clipboard = null; }
      else { await api('api/files/copy', { ids, target_id: targetId }); }
      toast(mode === 'cut' ? '已移动' : '已复制', 'ok');
      S.colCache = {}; reload(); loadStat();
    } catch (e) { toast(e.message, 'err'); }
  }

  function pickFolderDialog(title, okText, cb) {
    const wrap = h(`<div>
      <div class="field"><div class="field-label">选择目标位置</div><div id="pick-tree" style="max-height:280px;overflow:auto;border:1px solid var(--border);border-radius:12px;padding:8px"></div></div>
    </div>`);
    let current = 0;
    async function draw(container, parentId, depth) {
      const list = await api('api/files/tree', { parent_id: parentId });
      list.forEach(f => {
        const row = h(`<div class="tree-node" data-p="${f.id}">
          <span class="tree-caret">${icon('right', 13)}</span>${icon('folder', 15)}
          <span class="tree-label">${esc(f.name)}</span></div>`);
        container.appendChild(row);
        const holder = el('div', { class: 'tree-children', style: { display: 'none' } });
        container.appendChild(holder);
        row.querySelector('.tree-caret').onclick = e => {
          e.stopPropagation();
          const open = holder.style.display !== 'none';
          holder.style.display = open ? 'none' : '';
          row.querySelector('.tree-caret').classList.toggle('open', !open);
          if (!open && !holder.dataset.loaded) { holder.dataset.loaded = '1'; draw(holder, f.id, depth + 1); }
        };
        row.onclick = e => {
          e.stopPropagation();
          container.querySelectorAll('.tree-node').forEach(x => x.classList.remove('active'));
          row.classList.add('active'); current = f.id;
        };
      });
    }
    const box = $('#pick-tree', wrap);
    const rootRow = h(`<div class="tree-node active" data-p="0"><span class="tree-caret" style="opacity:0">${icon('right', 13)}</span>${icon('home', 15)}<span class="tree-label">根目录</span></div>`);
    box.appendChild(rootRow);
    const rootHolder = el('div', { class: 'tree-children' });
    box.appendChild(rootHolder);
    rootRow.onclick = () => { box.querySelectorAll('.tree-node').forEach(x => x.classList.remove('active')); rootRow.classList.add('active'); current = 0; };
    if (S.mode === 'all' && S.parentId) {
      // 默认选中当前目录
    }
    draw(rootHolder, 0, 0);
    dialog({ title: title, size: 'sm', body: wrap, okText: okText, onOk: () => cb(current) });
  }

  /* ================= 分享 ================= */
  function shareDialog(ids) {
    if (!S.user.can_share) return toast('当前用户组无分享权限', 'err');
    const targetId = ids[0];
    const node = S.nodes.find(n => n.id === targetId) || getInfoNode();
    const wrap = h(`<div>
      <div class="field"><div class="field-label">分享标题</div><input class="input" id="sh-title" value="${esc(node ? node.name : '')}"></div>
      <div class="field">
        <div class="field-label">有效期</div>
        <div class="pill-tabs" id="sh-period" style="flex-wrap:wrap">
          <button data-p="forever" class="active">永久</button>
          <button data-p="1d">1 天</button>
          <button data-p="7d">7 天</button>
          <button data-p="30d">30 天</button>
          <button data-p="custom">自定义</button>
        </div>
        <input class="input" id="sh-expire" style="display:none;margin-top:8px" type="date" placeholder="YYYY-MM-DD">
      </div>
      <div class="field">
        <div class="field-label">提取码（可选）</div>
        <div class="row-flex">
          <input class="input" id="sh-pick" placeholder="留空则不需要提取码" maxlength="8">
          <button class="btn btn-sm" id="sh-gen">随机</button>
        </div>
        <div class="field-hint">访问者需输入提取码才能查看文件</div>
      </div>
      <div class="field">
        <div class="field-label">访问密码（可选）</div>
        <input class="input" id="sh-pwd" placeholder="更强的保护，可留空">
      </div>
      <div class="row-flex" style="gap:18px;margin-top:6px">
        <label class="row-flex"><input type="checkbox" class="cbx" id="sh-pre" checked><span style="margin-left:6px">允许预览</span></label>
        <label class="row-flex"><input type="checkbox" class="cbx" id="sh-dl" checked><span style="margin-left:6px">允许下载</span></label>
      </div>
    </div>`);
    let period = 'forever';
    $$('#sh-period button', wrap).forEach(b => b.onclick = () => {
      $$('#sh-period button', wrap).forEach(x => x.classList.remove('active'));
      b.classList.add('active'); period = b.dataset.p;
      $('#sh-expire', wrap).style.display = period === 'custom' ? '' : 'none';
    });
    $('#sh-gen', wrap).onclick = () => { $('#sh-pick', wrap).value = Math.random().toString(36).slice(2, 6).toUpperCase(); };
    const d = dialog({
      title: '创建分享链接', body: wrap, okText: '生成链接',
      onOk: async () => {
        const r = await api('api/share/create', {
          node_id: targetId, title: $('#sh-title', wrap).value, period,
          expire_at: period === 'custom' ? $('#sh-expire', wrap).value : '',
          pickcode: $('#sh-pick', wrap).value.trim(), password: $('#sh-pwd', wrap).value,
          allow_preview: $('#sh-pre', wrap).checked ? 1 : 0, allow_download: $('#sh-dl', wrap).checked ? 1 : 0,
        });
        showShareResult(r);
      },
    });
  }

  function showShareResult(s) {
    const url = s.url;
    const wrap = h(`<div>
      <div class="field"><div class="field-label">分享链接</div>
        <div class="row-flex" style="gap:8px">
          <input class="input" id="sr-url" value="${esc(url)}" readonly>
          <button class="btn btn-primary" id="sr-copy">复制</button>
        </div></div>
      ${s.has_pickcode ? `<div class="field"><div class="field-label">提取码</div><div class="row-flex"><input class="input" id="sr-pick" value="${esc(s.pickcode)}" readonly><button class="btn" id="sr-copy2">复制</button></div></div>` : ''}
      <div class="row-flex small muted" style="gap:14px">${icon('clock', 14)} ${esc(s.expire_text)}${s.has_password ? ' · 已设置访问密码' : ''}</div>
    </div>`);
    const d = dialog({ title: '分享已创建', size: 'sm', body: wrap, okText: '完成', hideCancel: true, onOk: () => { } });
    $('#sr-copy', wrap).onclick = () => { copy(url); toast('链接已复制', 'ok'); };
    $('#sr-copy2', wrap) && ($('#sr-copy2', wrap).onclick = () => { copy(s.pickcode); toast('提取码已复制', 'ok'); });
    setTimeout(() => { $('#sr-url', wrap).select(); }, 100);
    reload();
  }

  /* ================= 上传 ================= */
  const UP = { items: [], running: 0, open: false, seq: 0 };

  function pickFiles() {
    const inp = el('input', { type: 'file', multiple: true, style: { display: 'none' } });
    document.body.appendChild(inp);
    inp.onchange = () => { startUpload(Array.from(inp.files)); inp.remove(); };
    inp.click();
  }

  async function computeHash(file, limitMB) {
    if (!window.crypto || !crypto.subtle) return '';
    if (file.size > (limitMB || 120) * 1048576) return '';
    try {
      const buf = await file.arrayBuffer();
      const dig = await crypto.subtle.digest('SHA-256', buf);
      return Array.from(new Uint8Array(dig)).map(b => b.toString(16).padStart(2, '0')).join('');
    } catch (e) { return ''; }
  }

  function xhr(url, fd, onProgress) {
    return new Promise((resolve, reject) => {
      const x = new XMLHttpRequest();
      x.open('POST', url);
      x.setRequestHeader('X-CSRF-Token', cfg.csrf);
      x.upload.onprogress = e => { if (e.lengthComputable && onProgress) onProgress(e.loaded, e.total); };
      x.onload = () => {
        let j; try { j = JSON.parse(x.responseText); } catch (e) { return reject(new Error('返回数据异常')); }
        j.ok ? resolve(j.data) : reject(new Error(j.error || '上传失败'));
      };
      x.onerror = () => reject(new Error('网络错误'));
      x.send(fd);
    });
  }

  async function startUpload(files, targetId) {
    files = Array.from(files || []);
    if (!files.length) return;
    const pid = targetId !== undefined ? targetId : S.parentId;
    if (S.mode !== 'all') { toast('请先切换到普通文件夹再上传', 'err'); return; }
    const maxSize = cfg.site.max_upload_size || 0;
    const chunkSize = cfg.site.chunk_size || 4194304;
    UP.open = true;

    for (const f of files) {
      const item = { id: ++UP.seq, file: f, name: f.name, size: f.size, loaded: 0, status: 'wait', msg: '' };
      UP.items.push(item);
      renderUpload();
      if (maxSize && f.size > maxSize) { item.status = 'err'; item.msg = '超出单文件限制'; renderUpload(); continue; }
      runUpload(item, pid, chunkSize).catch(e => { item.status = 'err'; item.msg = e.message; renderUpload(); toast(e.message, 'err'); });
    }
  }

  async function runUpload(item, pid, chunkSize) {
    const url = IDX + '?_route=';
    item.status = 'run';
    const useChunk = item.size > chunkSize * 1.2;
    let res;
    if (!useChunk) {
      const fd = new FormData();
      fd.append('file', item.file);
      fd.append('parent_id', pid);
      res = await xhr(url + '/api/files/upload', fd, (loaded, total) => { item.loaded = loaded; renderUpload(); });
      item.status = 'ok'; item.loaded = item.size;
      item.instant = res && res.instant;
      renderUpload();
      finishUpload(item);
      return;
    }
    // 分片
    const hash = await computeHash(item.file, 120);
    const init = await Pan.api('api/files/upload-init', { name: item.name, size: item.size, hash, parent_id: pid });
    if (init.instant) {
      item.status = 'ok'; item.loaded = item.size; item.instant = true;
      renderUpload(); finishUpload(item); return;
    }
    const total = init.chunks;
    for (let i = 0; i < total; i++) {
      const start = i * init.chunk_size;
      const blob = item.file.slice(start, Math.min(start + init.chunk_size, item.size));
      const fd = new FormData();
      fd.append('chunk', blob, 'blob');
      fd.append('token', init.token);
      fd.append('index', i);
      await xhr(url + '/api/files/upload-chunk', fd, loaded => {
        item.loaded = start + loaded; renderUpload();
      });
    }
    res = await Pan.api('api/files/upload-complete', { token: init.token });
    item.status = 'ok'; item.loaded = item.size;
    renderUpload(); finishUpload(item);
  }

  function finishUpload(item) {
    item.done = true;
    setTimeout(() => {
      UP.items = UP.items.filter(x => x !== item);
      renderUpload();
      if (!UP.items.length) refreshAll();
    }, 2200);
  }

  async function refreshAll() {
    await loadStat();
    if (S.mode === 'all') { S.colCache = {}; S.tree = {}; await loadTree(0, true); await reload(); }
  }

  function renderUpload() {
    let box = $('#upload-drawer', app);
    if (!box) return;
    const list = UP.items;
    const running = list.filter(i => i.status === 'run' || i.status === 'wait').length;
    if (!list.length) { box.innerHTML = ''; return; }
    if (!box.firstChild) {
      box.innerHTML = `<div class="upload-drawer">
        <div class="upload-head">${icon('upload', 16)}<span id="up-title">上传队列</span><span class="spacer"></span>
          <span class="chip chip-primary" id="up-count">0</span>
          <button class="btn btn-sm btn-icon btn-ghost" id="up-close">${icon('close', 14)}</button></div>
        <div class="upload-list" id="up-list"></div></div>`;
      $('#up-close', box).onclick = () => { UP.items = []; renderUpload(); };
    }
    $('#up-count', box).textContent = running + ' 项进行中';
    $('#up-list', box).innerHTML = list.map(i => {
      const pct = i.size ? Math.min(100, Math.round(i.loaded / i.size * 100)) : 100;
      const bar = i.status === 'ok' ? '<div class="upload-bar ok"><i style="width:100%"></i></div>'
        : i.status === 'err' ? '<div class="upload-bar err"><i style="width:100%"></i></div>'
          : `<div class="upload-bar"><i style="width:${pct}%"></i></div>`;
      const right = i.status === 'ok'
        ? (i.instant ? '<span class="chip chip-success">秒传</span>' : '<span class="chip chip-success">完成</span>')
        : i.status === 'err' ? `<span class="chip chip-danger">${esc(i.msg || '失败')}</span>`
          : `<span class="small muted">${pct}%</span>`;
      return `<div class="upload-item"><div class="upload-row">${icon('file', 15)}<span class="upload-name" title="${esc(i.name)}">${esc(i.name)}</span>${right}</div>
        <div class="upload-meta"><span>${size(i.loaded)} / ${size(i.size)}</span></div>${bar}</div>`;
    }).join('');
  }

  /* ================= 预览 ================= */
  const TEXT_EXT = ['txt', 'md', 'json', 'log', 'csv', 'html', 'htm', 'css', 'js', 'ts', 'php', 'py', 'java', 'go', 'c', 'cpp', 'h', 'sql', 'yml', 'yaml', 'xml', 'sh', 'ini', 'conf', 'vue', 'jsx', 'tsx', 'rs', 'rb', 'swift', 'kt'];

  function previewable(n) {
    if (n.type === 'folder') return false;
    return ['image', 'video', 'audio', 'pdf'].includes(n.kind) || TEXT_EXT.includes(n.ext);
  }

  function openPreview(node) {
    if (!node) return;
    const list = S.nodes.filter(previewable);
    S.pvList = list;
    S.pvIndex = list.findIndex(n => n.id === node.id);
    if (S.pvIndex < 0) { S.pvList = [node]; S.pvIndex = 0; }
    drawPreviewer();
  }

  function drawPreviewer() {
    let pv = document.getElementById('previewer');
    if (!pv) { pv = h(`<div class="previewer" id="previewer"></div>`); document.body.appendChild(pv); }
    const n = S.pvList[S.pvIndex];
    if (!n) { pv.remove(); return; }
    const src = apiUrl('/api/files/preview', { id: n.id });
    let body = '';
    if (n.kind === 'image') body = `<img src="${src}" alt="${esc(n.name)}">`;
    else if (n.kind === 'video') body = `<video src="${src}" controls autoplay style="max-width:100%;max-height:100%"></video>`;
    else if (n.kind === 'audio') body = `<div class="pv-audio-wrap"><div class="big-icon">${icon('audio', 66)}</div><div style="margin-bottom:12px">${esc(n.name)}</div><audio src="${src}" controls autoplay></audio></div>`;
    else if (n.kind === 'pdf') body = `<iframe src="${src}" title="pdf"></iframe>`;
    else body = `<div class="pv-code" id="pv-code">加载中…</div>`;

    pv.innerHTML = `
      <div class="pv-head">
        ${icon(['image', 'video', 'audio'].includes(n.kind) ? n.kind : 'file', 18)}
        <span class="pv-title">${esc(n.name)}</span>
        <span class="small" style="opacity:.6">${S.pvIndex + 1}/${S.pvList.length}</span>
        <span class="spacer"></span>
        ${n.type === 'file' && TEXT_EXT.includes(n.ext) ? `<button class="btn btn-sm" id="pv-edit">${icon('edit', 14)} 编辑</button>` : ''}
        <a class="btn btn-sm" href="${apiUrl('/api/files/download', { ids: n.id })}">${icon('download', 14)} 下载</a>
        <button class="btn btn-sm btn-icon" id="pv-close">${icon('close', 16)}</button>
      </div>
      <div class="pv-body" style="position:relative">
        ${S.pvList.length > 1 ? `<div class="pv-nav prev">${icon('left', 20)}</div><div class="pv-nav next">${icon('right', 20)}</div>` : ''}
        ${body}
      </div>`;
    $('#pv-close', pv).onclick = closePreview;
    $('.pv-nav.prev', pv) && ($('.pv-nav.prev', pv).onclick = () => { S.pvIndex = (S.pvIndex - 1 + S.pvList.length) % S.pvList.length; drawPreviewer(); });
    $('.pv-nav.next', pv) && ($('.pv-nav.next', pv).onclick = () => { S.pvIndex = (S.pvIndex + 1) % S.pvList.length; drawPreviewer(); });
    $('#pv-edit', pv) && ($('#pv-edit', pv).onclick = () => editText(n));
    if (body.includes('pv-code')) loadCode(pv, n);
  }

  async function loadCode(pv, n) {
    try {
      const r = await api('api/files/text', { id: n.id });
      $('#pv-code', pv).textContent = r.content;
    } catch (e) { $('#pv-code', pv).textContent = '无法读取内容：' + e.message; }
  }

  function closePreview() { const p = document.getElementById('previewer'); p && p.remove(); }

  function editText(n) {
    dialog({
      title: '编辑：' + n.name, size: 'lg',
      body: (() => {
        const wrap = h('<div><textarea class="input" id="ed-txt" rows="18" style="font-family:var(--mono);font-size:13px"></textarea></div>');
        api('api/files/text', { id: n.id }).then(r => { $('#ed-txt', wrap).value = r.content; });
        return wrap;
      })(),
      onOk: async () => {
        await api('api/files/text-save', { id: n.id, content: $('#ed-txt').value });
        toast('已保存', 'ok'); closePreview(); reload();
      },
    });
  }

  /* ================= 全局事件 ================= */
  function bindGlobal() {
    document.addEventListener('keydown', e => {
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
      const k = e.key.toLowerCase();
      if (document.getElementById('previewer')) {
        if (e.key === 'Escape') closePreview();
        if (e.key === 'ArrowLeft' && S.pvList.length > 1) { S.pvIndex = (S.pvIndex - 1 + S.pvList.length) % S.pvList.length; drawPreviewer(); }
        if (e.key === 'ArrowRight' && S.pvList.length > 1) { S.pvIndex = (S.pvIndex + 1) % S.pvList.length; drawPreviewer(); }
        return;
      }
      if ((e.metaKey || e.ctrlKey) && k === 'a') { e.preventDefault(); S.nodes.forEach(n => S.sel.add(n.id)); syncSelUI(); }
      if ((e.metaKey || e.ctrlKey) && k === 'c') { S.clipboard = { ids: Array.from(S.sel), mode: 'copy' }; toast('已复制', 'ok'); }
      if ((e.metaKey || e.ctrlKey) && k === 'x') { S.clipboard = { ids: Array.from(S.sel), mode: 'cut' }; toast('已剪切', 'ok'); renderContent(); }
      if ((e.metaKey || e.ctrlKey) && k === 'v') pasteInto(S.parentId);
      if (e.key === 'F2' && S.infoId) renameInline(S.infoId, S.view);
      if (e.key === 'Delete' && S.sel.size) S.mode === 'trash' ? doPurge(Array.from(S.sel)) : doTrash(Array.from(S.sel));
      if (e.key === 'Escape') S.sel.clear(), syncSelUI();
    });

    // 拖拽整页上传
    let depth = 0;
    document.addEventListener('dragenter', e => {
      if (S.mode !== 'all') return;
      depth++;
      if (depth === 1 && !document.querySelector('.drop-mask')) {
        document.body.appendChild(h(`<div class="drop-mask"><div class="drop-card">${icon('upload', 40)}<div class="t">释放以上传文件</div><div class="s">将上传到当前目录</div></div></div>`));
      }
    });
    document.addEventListener('dragleave', e => {
      if (S.mode !== 'all') return;
      depth--; if (depth <= 0) { depth = 0; const m = document.querySelector('.drop-mask'); m && m.remove(); }
    });
    document.addEventListener('dragover', e => e.preventDefault());
    document.addEventListener('drop', async e => {
      e.preventDefault(); depth = 0;
      const m = document.querySelector('.drop-mask'); m && m.remove();
      if (!e.dataTransfer.files.length || S.mode !== 'all') return;
      await startUpload(e.dataTransfer.files);
    });

    document.addEventListener('click', () => Pan.closeMenu());
  }

  boot();
})();
