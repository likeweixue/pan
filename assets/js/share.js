/* ============================================================
 * VortexPan - 分享提取页
 * ============================================================ */
(function () {
  'use strict';
  const { el, h, esc, api, toast, dialog, confirm, prompt, menu, icon, fileIcon, size, timeAgo, fullTime, copy } = Pan;
  const cfg = Pan.cfg;
  const CODE = cfg.code;
  const IDX = cfg.index;
  const app = document.getElementById('app');
  const S = { info: null, root: null, nodes: [], crumbs: [], current: null, sel: new Set() };
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));

  function url(p, q) {
    let u = IDX + '?_route=' + p;
    if (q) for (const k in q) u += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(q[k]);
    return u;
  }

  app.className = 'share-page';
  app.innerHTML = `<div class="share-wrap"><div class="share-card" id="sc"><div class="share-lock">加载中…</div></div>
    <div class="share-footer">Powered by ${esc(cfg.site.name)} · ${esc(cfg.site.desc || '')}</div></div>`;

  boot();

  async function boot() {
    try {
      S.info = await api('api/guest/info', { code: CODE });
    } catch (e) {
      return renderError(e.message || '链接无效');
    }
    if (S.info.need) return renderLock(S.info.need);
    await loadList(S.info.node.id);
  }

  function renderError(msg) {
    $('#sc', app).innerHTML = `<div class="share-lock">
      <div style="color:var(--text-3)">${icon('close', 40)}</div>
      <div style="font-size:17px;font-weight:600;margin-top:12px">${esc(msg)}</div>
      <div class="small muted" style="margin-top:6px">链接可能已失效、被取消或文件已删除</div>
    </div>`;
  }

  function renderLock(need) {
    const isPwd = need === 'password';
    $('#sc', app).innerHTML = `<div class="share-lock">
      <div style="color:var(--primary)">${icon(isPwd ? 'key' : 'shield', 42)}</div>
      <div style="font-size:18px;font-weight:600;margin-top:12px">${esc(S.info.title)}</div>
      <div class="small muted" style="margin-top:4px">请输入${isPwd ? '访问密码' : '提取码'}后查看文件</div>
      <div class="input-wrap"><input class="input" id="lk-v" placeholder="${isPwd ? '访问密码' : '提取码'}" autocomplete="off"></div>
      <button class="btn btn-primary" id="lk-ok" style="margin-top:12px">立即查看</button>
      <div class="small muted" style="margin-top:14px">来自 ${esc(S.info.owner)} · ${esc(S.info.expire_text)}</div>
    </div>`;
    const go = async () => {
      const v = $('#lk-v', app).value.trim();
      if (!v) return toast('请输入' + (isPwd ? '密码' : '提取码'), 'err');
      try { await api('api/guest/unlock', { code: CODE, value: v }); location.reload(); }
      catch (e) { toast(e.message, 'err'); }
    };
    $('#lk-ok', app).onclick = go;
    $('#lk-v', app).onkeydown = e => { if (e.key === 'Enter') go(); };
    setTimeout(() => $('#lk-v', app).focus(), 100);
  }

  async function loadList(nodeId) {
    try {
      const r = await api('api/guest/list', { code: CODE, node_id: nodeId, sort: 'name_asc' });
      S.nodes = r.nodes || [];
      S.crumbs = r.breadcrumb || [];
      S.current = r.current;
      S.root = S.info.node;
      render();
    } catch (e) { renderError(e.message); }
  }

  function render() {
    const info = S.info;
    const c = S.current;
    const isFile = c && c.type === 'file';
    $('#sc', app).innerHTML = `
      <div class="share-head">
        <div style="display:grid;place-items:center;width:52px;height:52px;border-radius:14px;background:var(--primary-weak);color:var(--primary)">${fileIcon(c || { type: 'folder', ext: '' }, 30)}</div>
        <div style="flex:1;min-width:0">
          <div class="share-title">${esc(info.title)}</div>
          <div class="share-sub">
            <span>${esc(info.owner)} 分享</span>
            <span>${icon('clock', 13)} ${esc(info.expire_text)}</span>
            <span>${info.view_count} 次访问 · ${info.download_count} 次下载</span>
            ${info.has_pickcode ? '<span class="chip chip-warn">需提取码</span>' : ''}
            ${info.has_password ? '<span class="chip chip-warn">需密码</span>' : ''}
          </div>
        </div>
        <button class="btn btn-sm" id="sh-copy-link">${icon('link', 14)} 复制链接</button>
      </div>
      ${isFile ? fileBody(c) : folderBody()}
      <div class="share-actions">
        ${info.allow_download ? `<a class="btn btn-primary" href="${dlUrl(c.id)}" target="_blank">${icon('download', 15)} ${isFile ? '下载文件' : '下载全部（打包）'}</a>` : '<span class="chip chip-danger">分享者未开启下载</span>'}
        ${cfg.user ? `<button class="btn" id="sh-save">${icon('save', 15)} 保存到我的网盘</button>` : `<a class="btn" href="${IDX}">登录后转存</a>`}
        ${isFile && info.allow_preview && previewable(c) ? `<button class="btn" id="sh-preview">${icon('eye', 15)} 预览</button>` : ''}
      </div>`;

    $('#sh-copy-link', app).onclick = () => copy(location.href);
    $('#sh-save', app) && ($('#sh-save', app).onclick = doSave);
    $('#sh-preview', app) && ($('#sh-preview', app).onclick = () => preview(c));
    if (isFile) {
      $('#sh-dl-file', app) && ($('#sh-dl-file', app).href = dlUrl(c.id));
    } else {
      $$('[data-crumb]', app).forEach(n => n.onclick = () => loadList(+n.dataset.crumb));
      $$('tbody tr[data-id]', app).forEach(row => {
        const id = +row.dataset.id;
        const node = S.nodes.find(x => x.id === id);
        row.onclick = () => { if (node.type === 'folder') loadList(id); };
        row.ondblclick = () => node.type === 'folder' ? loadList(id) : preview(node);
        $$('[data-act]', row).forEach(b => b.onclick = e => {
          e.stopPropagation();
          if (b.dataset.act === 'dl') location.href = dlUrl(id);
          if (b.dataset.act === 'pv') preview(node);
          if (b.dataset.act === 'open') loadList(id);
        });
      });
    }
  }

  function dlUrl(id) { return url('/api/guest/download', { code: CODE, node_id: id }); }

  function previewable(n) {
    return n.type === 'file' && ['image', 'video', 'audio', 'pdf'].includes(n.kind);
  }

  function preview(n) {
    const src = url('/api/guest/preview', { code: CODE, node_id: n.id });
    let body;
    if (n.kind === 'image') body = `<img src="${src}">`;
    else if (n.kind === 'video') body = `<video src="${src}" controls autoplay style="max-width:100%;max-height:100%"></video>`;
    else if (n.kind === 'audio') body = `<div class="pv-audio-wrap"><div class="big-icon">${icon('audio', 60)}</div><audio src="${src}" controls autoplay></audio></div>`;
    else body = `<iframe src="${src}"></iframe>`;
    const pv = h(`<div class="previewer" id="previewer">
      <div class="pv-head"><span class="pv-title">${esc(n.name)}</span><span class="spacer"></span>
        <a class="btn btn-sm" href="${dlUrl(n.id)}">${icon('download', 14)} 下载</a>
        <button class="btn btn-sm btn-icon" id="pv-close">${icon('close', 16)}</button></div>
      <div class="pv-body">${body}</div></div>`);
    document.body.appendChild(pv);
    $('#pv-close', pv).onclick = () => pv.remove();
    pv.addEventListener('click', e => { if (e.target === pv) pv.remove(); });
  }

  function fileBody(c) {
    return `<div class="share-body">
      <div class="card">
        <div class="card-body row-flex" style="gap:14px">
          <div style="display:grid;place-items:center;width:64px;height:64px;border-radius:14px;background:var(--surface-2)">${fileIcon(c, 40)}</div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:15px;word-break:break-all">${esc(c.name)}</div>
            <div class="small muted" style="margin-top:4px">${size(c.size)} · ${fullTime(c.created_at)} · ${esc(c.mime || c.ext)}</div>
          </div>
        </div>
      </div>
    </div>`;
  }

  function folderBody() {
    if (!S.nodes.length) {
      return `<div class="share-body"><div class="empty">${icon('folder', 42)}<div class="empty-title">这个文件夹是空的</div></div></div>`;
    }
    return `<div class="share-body">
      <div class="crumbs" style="margin-bottom:8px">
        ${S.crumbs.map((c, i) => `<span class="crumb" data-crumb="${c.id}">${esc(c.name)}</span>${i < S.crumbs.length - 1 ? '<span class="crumb-sep">/</span>' : ''}`).join('')}
      </div>
      <div class="card">
        <div style="overflow:auto;max-height:440px">
          <table class="table">
            <thead><tr><th>名称</th><th style="width:100px;text-align:right">大小</th><th style="width:120px;text-align:right">时间</th><th style="width:120px;text-align:right">操作</th></tr></thead>
            <tbody>${S.nodes.map(n => `<tr data-id="${n.id}" style="cursor:pointer">
              <td><div class="row-flex" style="gap:9px">${fileIcon(n, 22)}<div><div style="font-weight:500">${esc(n.name)}</div></div></div></td>
              <td style="text-align:right" class="small">${n.type === 'folder' ? '—' : size(n.size)}</td>
              <td style="text-align:right" class="small muted">${timeAgo(n.updated_at || n.created_at)}</td>
              <td style="text-align:right;white-space:nowrap">
                ${n.type === 'folder' ? `<button class="btn btn-sm" data-act="open">打开</button>` : ''}
                ${previewable(n) && S.info.allow_preview ? `<button class="btn btn-sm" data-act="pv">预览</button>` : ''}
                ${S.info.allow_download ? `<button class="btn btn-sm" data-act="dl">下载</button>` : ''}
              </td></tr>`).join('')}</tbody>
          </table>
        </div>
      </div>
    </div>`;
  }

  function doSave() {
    const wrap = h(`<div>
      <div class="field"><div class="field-label">保存到</div>
        <select class="select input" id="sv-dir"><option value="0">根目录</option></select></div>
      <div class="small muted">将把「${esc(S.current.name)}」完整复制到你的网盘</div>
    </div>`);
    api('api/files/tree', { parent_id: 0 }).then(list => {
      const sel = $('#sv-dir', wrap);
      list.forEach(f => sel.appendChild(h(`<option value="${f.id}">${esc(f.name)}/</option>`)));
    });
    dialog({
      title: '保存到我的网盘', size: 'sm', body: wrap, okText: '保存',
      onOk: async () => {
        try {
          await api('api/guest/save', { code: CODE, node_id: S.current.id, target_id: +$('#sv-dir', wrap).value });
          toast('已保存到我的网盘', 'ok');
        } catch (e) { toast(e.message, 'err'); }
      },
    });
  }
})();
