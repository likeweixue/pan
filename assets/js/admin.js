/* ============================================================
 * VortexPan - 管理后台
 * ============================================================ */
(function () {
  'use strict';
  const { el, h, esc, api, toast, dialog, confirm, prompt, icon, size, timeAgo, fullTime, copy, debounce } = Pan;
  const cfg = Pan.cfg;
  const app = document.getElementById('app');
  const S = { page: 'dash', data: {}, kw: '', pageNo: 1, size: 20 };

  const MENU = [
    { k: 'dash', n: '概览', i: 'chart' },
    { k: 'users', n: '用户管理', i: 'users' },
    { k: 'groups', n: '用户组', i: 'shield' },
    { k: 'files', n: '文件管理', i: 'folder' },
    { k: 'shares', n: '分享管理', i: 'share' },
    { k: 'settings', n: '系统设置', i: 'settings' },
    { k: 'logs', n: '操作日志', i: 'terminal' },
  ];

  function mount() {
    app.className = '';
    app.innerHTML = `<div class="admin-layout">
      <aside class="admin-side">
        <div class="brand" style="padding:4px 6px 14px">
          <div class="brand-logo">${icon('cloud', 19)}</div>
          <div><div class="brand-name">${esc(cfg.site.name)}</div><div class="brand-sub">管理后台</div></div>
        </div>
        <div class="nav">${MENU.map(m => `<div class="nav-item" data-m="${m.k}">${icon(m.i, 16)}<span>${m.n}</span></div>`).join('')}</div>
        <span class="spacer"></span>
        <div class="nav-item" id="back-site">${icon('left', 16)}<span>返回网盘</span></div>
      </aside>
      <main class="admin-main" id="am"></main>
    </div>`;
    $$m('[data-m]').forEach(n => n.onclick = () => { S.page = n.dataset.m; S.pageNo = 1; S.kw = ''; route(); });
    $('#back-site', app).onclick = () => location.href = cfg.index;
  }

  const $ = (s, r) => (r || document).querySelector(s);
  function $$m(s) { return Array.from(document.querySelectorAll(s)); }
  function activeMenu() {
    $$m('[data-m]').forEach(n => n.classList.toggle('active', n.dataset.m === S.page));
  }

  async function route() {
    activeMenu();
    const am = $('#am', app);
    am.innerHTML = '<div class="empty">加载中…</div>';
    const loader = {
      dash: renderDash, users: renderUsers, groups: renderGroups, files: renderFiles,
      shares: renderShares, settings: renderSettings, logs: renderLogs,
    }[S.page] || renderDash;
    await loader(am);
  }

  function head(title, actions) {
    return `<div class="admin-head"><h2>${title}</h2><span class="spacer"></span>${actions || ''}</div>`;
  }

  async function renderDash(am) {
    const d = await api('api/admin/stats');
    const max = Math.max(1, ...d.trend.map(t => t.count));
    am.innerHTML = head('概览', `<button class="btn btn-sm" id="rf">${icon('refresh', 14)} 刷新</button>`) + `
      <div class="stat-grid">
        ${[['用户总数', d.users, 'user'], ['文件数量', d.files, 'file'], ['文件夹', d.folders, 'folder'], ['分享链接', d.shares, 'share'],
      ['已用空间', d.used_text, 'cloud'], ['逻辑总量', d.total_size_text, 'chart'], ['今日上传', d.today_files, 'upload'], ['今日注册', d.today_users, 'users']]
      .map(s => `<div class="stat-card"><div class="label">${icon(s[2], 14)} ${s[0]}</div><div class="value">${esc(s[1])}</div><div class="sub">磁盘可用 ${d.storage_free_text}</div></div>`).join('')}
      </div>
      <div class="row-flex" style="gap:14px;align-items:stretch;flex-wrap:wrap">
        <div class="card" style="flex:1;min-width:340px">
          <div class="card-head"><h3>近 7 天上传趋势</h3></div>
          <div class="card-body">
            <div class="bar-chart">${d.trend.map(t => `<div class="bar" style="height:${Math.round(t.count / max * 100)}%"><span class="v">${t.count}</span><span class="d">${t.day}</span></div>`).join('')}</div>
          </div>
        </div>
        <div class="card" style="flex:1;min-width:340px">
          <div class="card-head"><h3>最近操作</h3></div>
          <div class="card-body" style="padding:6px 8px">
            ${d.recent.map(l => `<div class="info-row"><span class="k">${esc(l.username || '系统')} · ${esc(l.action)}</span><span class="v small">${esc(l.target || '')} <span class="muted">${fullTime(l.created_at)}</span></span></div>`).join('') || '<div class="empty small">暂无记录</div>'}
          </div>
        </div>
      </div>`;
    $('#rf', am).onclick = route;
  }

  async function renderUsers(am) {
    const r = await api('api/admin/users', { page: S.pageNo, size: S.size, keyword: S.kw });
    am.innerHTML = head('用户管理', `<button class="btn btn-sm btn-primary" id="add">${icon('plus', 14)} 新建用户</button>`) + `
      <div class="card"><div class="card-head">
        <input class="input" id="kw" placeholder="搜索用户名 / 邮箱 / 昵称" style="width:250px;height:30px;font-size:12.5px" value="${esc(S.kw)}">
        <button class="btn btn-sm" id="search">搜索</button><span class="spacer"></span><span class="chip">共 ${r.total} 个用户</span>
      </div>
      <div style="overflow:auto">
      <table class="table">
        <thead><tr><th>ID</th><th>用户</th><th>用户组</th><th>已用 / 配额</th><th>上传限速</th><th>下载限速</th><th>状态</th><th>注册时间</th><th style="text-align:right">操作</th></tr></thead>
        <tbody>${r.rows.map(u => `<tr>
          <td class="num muted">${u.id}</td>
          <td><div style="font-weight:500">${esc(u.nickname || u.username)}</div><div class="small muted">@${esc(u.username)}${u.email ? ' · ' + esc(u.email) : ''}</div></td>
          <td><span class="chip ${u.is_admin ? 'chip-primary' : ''}">${esc(u.group_name)}</span></td>
          <td class="small">${size(u.used)} / ${size(u.quota)}<div class="quota-bar" style="width:70px"><div class="quota-fill" style="width:${Math.min(100, u.percent)}%"></div></div></td>
          <td class="small num">${u.upload_speed ? size(u.upload_speed * 1024) + '/s' : '不限'}</td>
          <td class="small num">${u.download_speed ? size(u.download_speed * 1024) + '/s' : '不限'}</td>
          <td>${u.status ? '<span class="chip chip-success">正常</span>' : '<span class="chip chip-danger">禁用</span>'}</td>
          <td class="small muted">${fullTime(u.created_at)}</td>
          <td style="text-align:right;white-space:nowrap">
            <button class="btn btn-sm" data-edit="${u.id}">编辑</button>
            <button class="btn btn-sm btn-danger" data-del="${u.id}">删除</button>
          </td></tr>`).join('')}</tbody>
      </table></div>
      ${pager(r)}</div>`;
    bindPager(r, am, renderUsers);
    $('#search', am).onclick = () => { S.kw = $('#kw', am).value.trim(); S.pageNo = 1; renderUsers(am); };
    $('#add', am).onclick = () => userForm(null);
    $$m('[data-edit]').forEach(b => b.onclick = () => userForm(r.rows.find(u => u.id === +b.dataset.edit)));
    $$m('[data-del]').forEach(b => b.onclick = async () => {
      const u = r.rows.find(x => x.id === +b.dataset.del);
      if (!await confirm('删除用户', `确定删除用户「${u.username}」？其所有文件都会被清除，该操作不可撤销。`)) return;
      await api('api/admin/user-delete', { id: u.id }); toast('已删除', 'ok'); renderUsers(am);
    });
  }

  function bindPager(r, am, fn) {
    $$m('[data-pg]').forEach(b => b.onclick = () => { S.pageNo = +b.dataset.pg; fn(am); });
  }

  function pager(r) {
    const pages = Math.ceil(r.total / r.size) || 1;
    return `<div class="pager">
      <button class="btn btn-sm" data-pg="${Math.max(1, r.page - 1)}">上一页</button>
      <span class="small muted">第 ${r.page} / ${pages} 页</span>
      <button class="btn btn-sm" data-pg="${Math.min(pages, r.page + 1)}">下一页</button>
    </div>`;
  }

  function userForm(u) {
    const isNew = !u;
    api('api/admin/groups').then(groups => {
      const wrap = h(`<div>
        <div class="field"><div class="field-label">用户名</div><input class="input" id="f-u" value="${esc(u ? u.username : '')}" ${u ? '' : 'placeholder="3-20 位字母/数字/下划线"'}></div>
        <div class="field"><div class="field-label">昵称</div><input class="input" id="f-n" value="${esc(u ? u.nickname : '')}"></div>
        <div class="field"><div class="field-label">邮箱</div><input class="input" id="f-e" value="${esc(u ? u.email : '')}"></div>
        <div class="row-flex" style="gap:10px">
          <div class="field" style="flex:1"><div class="field-label">用户组</div><select class="select input" id="f-g">${groups.map(g => `<option value="${g.id}" ${u && u.group_id == g.id ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}</select></div>
          <div class="field" style="flex:1"><div class="field-label">状态</div><select class="select input" id="f-s"><option value="1" ${!u || u.status ? 'selected' : ''}>正常</option><option value="0" ${u && !u.status ? 'selected' : ''}>禁用</option></select></div>
        </div>
        <div class="row-flex" style="gap:10px">
          <div class="field" style="flex:1"><div class="field-label">独立配额（字节，0=继承组）</div><input class="input" id="f-q" value="${u ? u.quota : 0}"></div>
          <div class="field" style="flex:1"><div class="field-label">上传限速 KB/s</div><input class="input" id="f-us" value="${u ? u.upload_speed : 0}"></div>
          <div class="field" style="flex:1"><div class="field-label">下载限速 KB/s</div><input class="input" id="f-ds" value="${u ? u.download_speed : 0}"></div>
        </div>
        <div class="field"><div class="field-label">${isNew ? '初始密码' : '重置密码（留空不改）'}</div><input class="input" id="f-p" type="text" placeholder="${isNew ? '至少 6 位' : '留空则不修改'}"></div>
        <div class="field-hint">1 GB = 1073741824 字节</div>
      </div>`);
      dialog({
        title: isNew ? '新建用户' : '编辑用户：' + u.username, body: wrap, okText: '保存',
        onOk: async () => {
          const payload = {
            id: u ? u.id : 0,
            username: $('#f-u', wrap).value.trim(),
            nickname: $('#f-n', wrap).value.trim(),
            email: $('#f-e', wrap).value.trim(),
            group_id: +$('#f-g', wrap).value,
            status: +$('#f-s', wrap).value,
            quota: +$('#f-q', wrap).value || 0,
            upload_speed: +$('#f-us', wrap).value || 0,
            download_speed: +$('#f-ds', wrap).value || 0,
          };
          const pw = $('#f-p', wrap).value;
          if (pw) payload.password = pw;
          await api('api/admin/user-save', payload);
          toast('已保存', 'ok'); route();
        },
      });
    });
  }

  async function renderGroups(am) {
    const groups = await api('api/admin/groups');
    am.innerHTML = head('用户组 / 等级', `<button class="btn btn-sm btn-primary" id="add">${icon('plus', 14)} 新建用户组</button>`) + `
      <div class="card"><div style="overflow:auto"><table class="table">
        <thead><tr><th>ID</th><th>组名</th><th>空间配额</th><th>上传/下载限速</th><th>单文件上限</th><th>可分享</th><th>管理权限</th><th>用户数</th><th>排序</th><th style="text-align:right">操作</th></tr></thead>
        <tbody>${groups.map(g => `<tr>
          <td class="num muted">${g.id}</td>
          <td><div style="font-weight:500">${esc(g.name)}</div><div class="small muted">${esc(g.remark || '')}</div></td>
          <td class="small">${size(g.quota)}</td>
          <td class="small">${g.upload_speed ? size(g.upload_speed * 1024) + '/s' : '不限'} · ${g.download_speed ? size(g.download_speed * 1024) + '/s' : '不限'}</td>
          <td class="small">${g.max_filesize ? size(g.max_filesize) : '不限'}</td>
          <td>${g.can_share ? icon('check', 15) : '<span class="muted">—</span>'}</td>
          <td>${g.is_admin ? '<span class="chip chip-primary">是</span>' : '<span class="muted">否</span>'}</td>
          <td class="num">${g.user_count}</td>
          <td class="num muted">${g.sort}</td>
          <td style="text-align:right;white-space:nowrap">
            <button class="btn btn-sm" data-edit="${g.id}">编辑</button>
            <button class="btn btn-sm btn-danger" data-del="${g.id}" ${g.id == 1 ? 'disabled' : ''}>删除</button>
          </td></tr>`).join('')}</tbody></table></div></div>`;
    $('#add', am).onclick = () => groupForm(null);
    $$m('[data-edit]').forEach(b => b.onclick = () => groupForm(groups.find(g => g.id === +b.dataset.edit)));
    $$m('[data-del]').forEach(b => b.onclick = async () => {
      if (b.disabled) return;
      if (!await confirm('删除用户组', '确定删除该用户组？')) return;
      await api('api/admin/group-delete', { id: +b.dataset.del }); toast('已删除', 'ok'); renderGroups(am);
    });
  }

  function groupForm(g) {
    const wrap = h(`<div>
      <div class="field"><div class="field-label">组名</div><input class="input" id="g-n" value="${esc(g ? g.name : '')}"></div>
      <div class="row-flex" style="gap:10px">
        <div class="field" style="flex:1"><div class="field-label">空间配额（字节）</div><input class="input" id="g-q" value="${g ? g.quota : 5368709120}"></div>
        <div class="field" style="flex:1"><div class="field-label">单文件上限（字节，0=不限）</div><input class="input" id="g-m" value="${g ? g.max_filesize : 0}"></div>
      </div>
      <div class="row-flex" style="gap:10px">
        <div class="field" style="flex:1"><div class="field-label">上传限速 KB/s（0=不限）</div><input class="input" id="g-u" value="${g ? g.upload_speed : 0}"></div>
        <div class="field" style="flex:1"><div class="field-label">下载限速 KB/s（0=不限）</div><input class="input" id="g-d" value="${g ? g.download_speed : 0}"></div>
      </div>
      <div class="row-flex" style="gap:18px">
        <label class="row-flex"><input type="checkbox" class="cbx" id="g-s" ${!g || g.can_share ? 'checked' : ''}><span style="margin-left:6px">允许分享</span></label>
        <label class="row-flex"><input type="checkbox" class="cbx" id="g-a" ${g && g.is_admin ? 'checked' : ''}><span style="margin-left:6px">后台管理员</span></label>
        <div class="row-flex"><span class="small muted">排序</span><input class="input" id="g-o" style="width:80px" value="${g ? g.sort : 10}"></div>
      </div>
      <div class="field" style="margin-top:12px"><div class="field-label">备注</div><input class="input" id="g-r" value="${esc(g ? g.remark : '')}"></div>
    </div>`);
    dialog({
      title: g ? '编辑用户组' : '新建用户组', body: wrap, okText: '保存',
      onOk: async () => {
        await api('api/admin/group-save', {
          id: g ? g.id : 0, name: $('#g-n', wrap).value.trim(), quota: +$('#g-q', wrap).value || 0,
          max_filesize: +$('#g-m', wrap).value || 0, upload_speed: +$('#g-u', wrap).value || 0,
          download_speed: +$('#g-d', wrap).value || 0, can_share: $('#g-s', wrap).checked ? 1 : 0,
          is_admin: $('#g-a', wrap).checked ? 1 : 0, sort: +$('#g-o', wrap).value || 0,
          remark: $('#g-r', wrap).value.trim(),
        });
        toast('已保存', 'ok'); route();
      },
    });
  }

  async function renderFiles(am) {
    const r = await api('api/admin/nodes', { page: S.pageNo, size: S.size, keyword: S.kw });
    am.innerHTML = head('文件管理') + `
      <div class="card"><div class="card-head">
        <input class="input" id="kw" placeholder="按文件名搜索" style="width:250px;height:30px;font-size:12.5px" value="${esc(S.kw)}">
        <button class="btn btn-sm" id="search">搜索</button><span class="spacer"></span><span class="chip">共 ${r.total} 个文件</span>
      </div>
      <div style="overflow:auto"><table class="table">
        <thead><tr><th>ID</th><th>文件</th><th>所属用户</th><th>大小</th><th>类型</th><th>路径</th><th>上传时间</th><th style="text-align:right">操作</th></tr></thead>
        <tbody>${r.rows.map(n => `<tr>
          <td class="num muted">${n.id}</td>
          <td><div class="row-flex" style="gap:8px"><div style="width:26px">${Pan.fileIcon(n, 22)}</div><div style="font-weight:500">${esc(n.name)}</div></div></td>
          <td class="small">${esc(n.username || '-')}</td>
          <td class="small num">${size(n.size)}</td>
          <td class="small muted">${esc(n.ext || n.mime || '')}</td>
          <td class="small muted mono">${esc(n.id_path)}</td>
          <td class="small muted">${fullTime(n.created_at)}</td>
          <td style="text-align:right"><button class="btn btn-sm btn-danger" data-del="${n.id}">删除</button></td>
        </tr>`).join('')}</tbody></table></div>
      ${pager(r)}</div>`;
    bindPager(r, am, renderFiles);
    $('#search', am).onclick = () => { S.kw = $('#kw', am).value.trim(); S.pageNo = 1; renderFiles(am); };
    $$m('[data-del]').forEach(b => b.onclick = async () => {
      if (!await confirm('删除文件', '将连同物理文件一起删除，确定继续？')) return;
      await api('api/admin/node-delete', { ids: [+b.dataset.del] }); toast('已删除', 'ok'); renderFiles(am);
    });
  }

  async function renderShares(am) {
    const r = await api('api/admin/shares', { page: S.pageNo, size: S.size, keyword: S.kw });
    am.innerHTML = head('分享管理') + `
      <div class="card"><div class="card-head">
        <input class="input" id="kw" placeholder="搜索链接码 / 标题" style="width:250px;height:30px;font-size:12.5px" value="${esc(S.kw)}">
        <button class="btn btn-sm" id="search">搜索</button><span class="spacer"></span><span class="chip">共 ${r.total} 条</span>
      </div>
      <div style="overflow:auto"><table class="table">
        <thead><tr><th>ID</th><th>标题 / 文件</th><th>分享者</th><th>链接</th><th>提取码</th><th>有效期</th><th>访问/下载</th><th style="text-align:right">操作</th></tr></thead>
        <tbody>${r.rows.map(s => `<tr>
          <td class="num muted">${s.id}</td>
          <td><div style="font-weight:500">${esc(s.title)}</div><div class="small muted">${esc(s.name || '（已删除）')}</div></td>
          <td class="small">${esc(s.username || '-')}</td>
          <td><span class="small mono link-copy">${esc(s.url.replace(/^https?:\/\//, ''))}</span></td>
          <td>${s.pickcode ? '<span class="chip chip-warn">' + esc(s.pickcode) + '</span>' : '<span class="muted small">无</span>'}</td>
          <td class="small">${esc(s.expire_text)}</td>
          <td class="small num">${s.view_count} / ${s.download_count}</td>
          <td style="text-align:right;white-space:nowrap">
            <button class="btn btn-sm" data-copy="${esc(s.url)}">复制链接</button>
            <button class="btn btn-sm btn-danger" data-del="${s.id}">删除</button>
          </td></tr>`).join('')}</tbody></table></div>
      ${pager(r)}</div>`;
    bindPager(r, am, renderShares);
    $('#search', am).onclick = () => { S.kw = $('#kw', am).value.trim(); S.pageNo = 1; renderShares(am); };
    $$m('[data-copy]').forEach(b => b.onclick = () => copy(b.dataset.copy));
    $$m('[data-del]').forEach(b => b.onclick = async () => {
      if (!await confirm('删除分享', '删除后链接立即失效，确定继续？')) return;
      await api('api/admin/share-delete', { id: +b.dataset.del }); toast('已删除', 'ok'); renderShares(am);
    });
  }

  async function renderSettings(am) {
    const s = await api('api/admin/settings');
    am.innerHTML = head('系统设置', `<button class="btn btn-sm btn-primary" id="save">${icon('save', 14)} 保存设置</button>`) + `
      <div class="card">
        <div class="card-head"><h3>站点</h3></div>
        <div class="card-body">
          <div class="row-flex" style="gap:12px;flex-wrap:wrap">
            <div class="field" style="flex:1;min-width:200px"><div class="field-label">站点名称</div><input class="input" id="st-name" value="${esc(s.site_name || '')}"></div>
            <div class="field" style="flex:1;min-width:200px"><div class="field-label">站点副标题</div><input class="input" id="st-desc" value="${esc(s.site_desc || '')}"></div>
          </div>
          <div class="row-flex" style="gap:12px;flex-wrap:wrap">
            <div class="field" style="flex:1;min-width:200px"><div class="field-label">备案信息（可选）</div><input class="input" id="st-icp" value="${esc(s.icp || '')}"></div>
            <div class="field" style="flex:1;min-width:200px"><div class="field-label">页脚文字</div><input class="input" id="st-footer" value="${esc(s.footer || '')}"></div>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h3>注册与上传</h3></div>
        <div class="card-body">
          <div class="row-flex" style="gap:24px;flex-wrap:wrap;margin-bottom:14px">
            <label class="row-flex"><span>开放注册</span><span style="margin-left:8px"><label class="switch"><input type="checkbox" id="st-reg" ${s.register_open === '1' ? 'checked' : ''}><span></span></label></span></label>
            <label class="row-flex"><span>调试模式</span><span style="margin-left:8px"><label class="switch"><input type="checkbox" id="st-debug" ${s.debug === '1' ? 'checked' : ''}><span></span></label></span></label>
            <div class="field" style="flex:1;min-width:180px;margin:0"><div class="field-label">新用户默认组 ID</div><input class="input" id="st-group" value="${esc(s.default_group || '1')}"></div>
          </div>
          <div class="field"><div class="field-label">单文件上传上限（字节）</div><input class="input" id="st-max" value="${esc(s.max_upload_size || '')}"><div class="field-hint">当前 ${size(s.max_upload_size || 0)}，同时需保证 PHP 的 upload_max_filesize 与 post_max_size 不小于该值</div></div>
          <div class="field"><div class="field-label">分片大小（字节）</div><input class="input" id="st-chunk" value="${esc(s.chunk_size || '')}"></div>
          <div class="field"><div class="field-label">允许的文件后缀（英文逗号分隔，留空表示不限制）</div><textarea class="input" rows="3" id="st-allow">${esc(s.allow_ext || '')}</textarea></div>
          <div class="field"><div class="field-label">禁止的文件后缀</div><input class="input" id="st-deny" value="${esc(s.deny_ext || '')}"></div>
        </div>
      </div>`;
    $('#save', am).onclick = async () => {
      await api('api/admin/setting-save', {
        data: { // 以 JSON 体提交，避免嵌套对象被 FormData 字符串化
          site_name: $('#st-name', am).value,
          site_desc: $('#st-desc', am).value,
          icp: $('#st-icp', am).value,
          footer: $('#st-footer', am).value,
          register_open: $('#st-reg', am).checked ? '1' : '0',
          debug: $('#st-debug', am).checked ? '1' : '0',
          default_group: $('#st-group', am).value,
          max_upload_size: $('#st-max', am).value,
          chunk_size: $('#st-chunk', am).value,
          allow_ext: $('#st-allow', am).value,
          deny_ext: $('#st-deny', am).value,
        },
      }, { method: 'POST', json: true });
      toast('设置已保存', 'ok'); setTimeout(() => location.reload(), 600);
    };
  }

  async function renderLogs(am) {
    const r = await api('api/admin/logs', { page: S.pageNo, size: 20, keyword: S.kw });
    am.innerHTML = head('操作日志', `<button class="btn btn-sm btn-danger" id="clr">${icon('trash', 14)} 清空日志</button>`) + `
      <div class="card"><div class="card-head">
        <input class="input" id="kw" placeholder="搜索操作 / 对象 / 用户名" style="width:250px;height:30px;font-size:12.5px" value="${esc(S.kw)}">
        <button class="btn btn-sm" id="search">搜索</button><span class="spacer"></span><span class="chip">共 ${r.total} 条</span>
      </div>
      <div style="overflow:auto"><table class="table">
        <thead><tr><th>ID</th><th>用户</th><th>类型</th><th>操作</th><th>对象</th><th>IP</th><th>时间</th></tr></thead>
        <tbody>${r.rows.map(l => `<tr>
          <td class="num muted">${l.id}</td>
          <td class="small">${esc(l.username || '匿名')}</td>
          <td><span class="chip">${esc(l.type)}</span></td>
          <td class="small">${esc(l.action)}</td>
          <td class="small muted">${esc(l.target || '')}</td>
          <td class="small muted mono">${esc(l.ip)}</td>
          <td class="small muted">${fullTime(l.created_at)}</td>
        </tr>`).join('')}</tbody></table></div>
      ${pager(r)}</div>`;
    bindPager(r, am, renderLogs);
    $('#search', am).onclick = () => { S.kw = $('#kw', am).value.trim(); S.pageNo = 1; renderLogs(am); };
    $('#clr', am).onclick = async () => {
      if (!await confirm('清空日志', '确定清空全部操作日志？')) return;
      await api('api/admin/log-clear'); toast('已清空', 'ok'); renderLogs(am);
    };
  }

  mount();
  route();
})();
