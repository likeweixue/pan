/* ============================================================
 * VortexPan UI Core
 * 请求封装 / 弹窗 / 菜单 / 提示 / 图标 / 工具函数
 * ============================================================ */
(function (global) {
  'use strict';

  const P = {
    cfg: global.PAN || {},
    bus: {},
  };

  /* ---------------- DOM helper ---------------- */
  function el(tag, props, children) {
    const node = document.createElement(tag);
    if (props) {
      for (const k in props) {
        if (k === 'class' || k === 'className') node.className = props[k];
        else if (k === 'html') node.innerHTML = props[k];
        else if (k === 'style' && typeof props[k] === 'object') Object.assign(node.style, props[k]);
        else if (k.startsWith('on') && typeof props[k] === 'function') node.addEventListener(k.slice(2).toLowerCase(), props[k]);
        else if (props[k] !== null && props[k] !== undefined && props[k] !== false) node.setAttribute(k, props[k]);
      }
    }
    if (children != null) {
      (Array.isArray(children) ? children : [children]).forEach(c => {
        if (c == null || c === false) return;
        node.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
      });
    }
    return node;
  }
  function h(html) {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function clear(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }
  // html`` 模板标签：自动转义插值
  function html(strings, ...values) {
    return strings.reduce((acc, s, i) => acc + s + (i < values.length ? (Array.isArray(values[i]) ? values[i].join('') : values[i]) : ''), '');
  }

  /* ---------------- 请求 ---------------- */
  async function api(path, data, options) {
    const opt = options || {};
    const method = (opt.method || (Object.keys(data || {}).length ? 'POST' : 'GET')).toUpperCase();
    const isJson = !!opt.json;
    let url;
    if (/^https?:/.test(path)) url = path;
    else url = (P.cfg.index || 'index.php') + '?_route=' + (path.startsWith('/') ? path : '/' + path);

    const init = { method, credentials: 'same-origin', headers: {} };
    if (method === 'GET') {
      if (data && Object.keys(data).length) {
        const q = new URLSearchParams();
        for (const k in data) if (data[k] !== undefined && data[k] !== null) q.append(k, data[k]);
        url += '&' + q.toString();
      }
    } else if (isJson || opt.raw || data instanceof FormData) {
      if (data instanceof FormData) {
        init.body = data;
      } else {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(data || {});
      }
    } else {
      const fd = new FormData();
      for (const k in (data || {})) {
        const v = data[k];
        if (v === undefined || v === null) continue;
        if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
        else fd.append(k, v);
      }
      init.body = fd;
    }
    if (method !== 'GET') init.headers['X-CSRF-Token'] = P.cfg.csrf || '';

    let res;
    try {
      res = await fetch(url, init);
    } catch (e) {
      throw new Error('网络请求失败，请检查连接');
    }
    const text = await res.text();
    if (!text) throw new Error('服务器无响应');
    let json;
    try { json = JSON.parse(text); } catch (e) {
      throw new Error('返回数据异常：' + text.slice(0, 120));
    }
    if (!json.ok) {
      const err = new Error(json.error || '请求失败');
      err.code = res.status;
      err.payload = json;
      throw err;
    }
    return json.data;
  }

  /* ---------------- Toast ---------------- */
  function toast(msg, type, ms) {
    let root = document.getElementById('toast-root');
    if (!root) {
      root = el('div', { id: 'toast-root' });
      document.body.appendChild(root);
    }
    const icons = {
      ok: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg>',
      err: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>',
      info: '',
    };
    const t = h(`<div class="toast ${type || 'info'}">${icons[type] || icons.info}<span>${esc(msg)}</span></div>`);
    root.appendChild(t);
    setTimeout(() => {
      t.style.transition = 'opacity .25s, transform .25s';
      t.style.opacity = '0';
      t.style.transform = 'translateY(-8px)';
      setTimeout(() => t.remove(), 260);
    }, ms || 2400);
    return t;
  }

  /* ---------------- Dialog ---------------- */
  function dialog(opts) {
    const o = Object.assign({ title: '', body: '', okText: '确定', cancelText: '取消', size: '', onOk: null, hideCancel: false, danger: false }, opts);
    const mask = el('div', { class: 'mask' });
    const dlg = el('div', { class: 'dialog ' + (o.size ? 'dialog-' + o.size : '') });
    const head = el('div', { class: 'dialog-head' }, [
      el('span', { html: o.title }),
      el('button', {
        class: 'btn btn-sm btn-icon btn-ghost close',
        onclick: () => close(),
        html: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>',
      }),
    ]);
    const body = el('div', { class: 'dialog-body' });
    if (typeof o.body === 'string') body.innerHTML = o.body;
    else if (o.body) body.appendChild(o.body);
    const foot = el('div', { class: 'dialog-foot' });
    if (!o.hideCancel) {
      foot.appendChild(el('button', { class: 'btn', onclick: () => close() }, o.cancelText));
    }
    const okBtn = el('button', { class: 'btn ' + (o.danger ? 'btn-danger-solid' : 'btn-primary'), onclick: () => submit() }, o.okText);
    foot.appendChild(okBtn);

    dlg.appendChild(head); dlg.appendChild(body); dlg.appendChild(foot);
    mask.appendChild(dlg);
    document.getElementById('layer-root').appendChild(mask);

    function close() {
      document.removeEventListener('keydown', onKey);
      if (o.onCancel) o.onCancel();
      mask.remove();
    }
    function onKey(e) {
      if (e.key === 'Escape') close();
      if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') submit();
    }
    mask.addEventListener('mousedown', e => { if (e.target === mask) close(); });
    document.addEventListener('keydown', onKey);
    async function submit() {
      if (!o.onOk) return close();
      const r = await o.onOk(close, dlg, okBtn);
      if (r !== false) close();
    }
    setTimeout(() => { const f = dlg.querySelector('input,select,textarea'); if (f) f.focus(); }, 60);
    return { close, body, dlg };
  }

  function confirm(title, message, opts) {
    const o = Object.assign({ danger: true, okText: '确定', cancelText: '取消' }, opts || {});
    return new Promise(resolve => {
      dialog({
        title: title,
        size: 'sm',
        body: `<div style="color:var(--text-2);font-size:13.5px;line-height:1.7">${esc(message)}</div>`,
        okText: o.okText,
        cancelText: o.cancelText,
        danger: o.danger,
        onOk: () => resolve(true),
        onCancel: () => resolve(false),
      });
    });
  }

  function prompt(title, label, value, opts) {
    const o = Object.assign({ placeholder: '', type: 'text', multiline: false }, opts || {});
    return new Promise(resolve => {
      const field = o.multiline
        ? document.createElement('textarea')
        : document.createElement('input');
      field.className = 'input';
      if (!o.multiline) field.type = o.type;
      field.value = value || '';
      field.placeholder = o.placeholder;
      if (o.multiline) { field.rows = 6; field.style.height = 'auto'; }
      const body = h('<div class="field"><div class="field-label">' + esc(label) + '</div></div>');
      body.appendChild(field);
      const d = dialog({
        title: title,
        size: 'sm',
        body: body,
        danger: false,
        onOk: () => resolve(field.value),
        onCancel: () => resolve(null),
      });
      setTimeout(() => field.focus(), 50);
      field.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !o.multiline) { e.stopPropagation(); resolve(field.value); d.close(); }
      });
    });
  }

  /* ---------------- 右键菜单 ---------------- */
  function menu(x, y, items) {
    closeMenu();
    const m = el('div', { class: 'ctx-menu', style: { left: '0px', top: '0px' } });
    (items || []).forEach(it => {
      if (it === '-') return m.appendChild(el('div', { class: 'ctx-sep' }));
      m.appendChild(el('div', {
        class: 'ctx-item' + (it.danger ? ' danger' : '') + (it.disabled ? ' dis' : ''),
        onclick: (e) => { if (it.disabled) return; closeMenu(); it.on && it.on(e); },
      }, [el('span', { html: it.icon || '' }), el('span', {}, it.label)]));
    });
    document.body.appendChild(m);
    const rect = m.getBoundingClientRect();
    m.style.left = Math.min(x, innerWidth - rect.width - 8) + 'px';
    m.style.top = Math.min(y, innerHeight - rect.height - 8) + 'px';
    setTimeout(() => document.addEventListener('mousedown', outside), 0);
    function outside(e) { if (!m.contains(e.target)) closeMenu(); }
    return m;
  }
  function closeMenu() {
    document.querySelectorAll('.ctx-menu').forEach(n => n.remove());
  }

  /* ---------------- 工具 ---------------- */
  function size(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    const u = ['KB', 'MB', 'GB', 'TB', 'PB'];
    let i = -1;
    do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
    return (n >= 100 ? n.toFixed(0) : n.toFixed(1)) + ' ' + u[i];
  }
  function timeAgo(str) {
    if (!str) return '-';
    const t = new Date(String(str).replace(/-/g, '/')).getTime();
    if (!t) return String(str);
    const d = (Date.now() - t) / 1000;
    if (d < 60) return '刚刚';
    if (d < 3600) return Math.floor(d / 60) + ' 分钟前';
    if (d < 86400) return Math.floor(d / 3600) + ' 小时前';
    if (d < 2592000) return Math.floor(d / 86400) + ' 天前';
    const dt = new Date(t);
    return dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
  }
  function fullTime(str) {
    if (!str) return '-';
    return String(str).slice(0, 16).replace(/-/g, '/').replace(/\//g, '-');
  }
  function copy(text) {
    if (navigator.clipboard && location.protocol !== 'http:') {
      navigator.clipboard.writeText(text).then(() => toast('已复制到剪贴板', 'ok')).catch(() => fallback());
    } else fallback();
    function fallback() {
      const ta = el('textarea', { style: { position: 'fixed', opacity: '0' }, value: text });
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); toast('已复制到剪贴板', 'ok'); } catch (e) { toast('复制失败，请手动选择', 'err'); }
      ta.remove();
    }
  }
  function debounce(fn, ms) {
    let t; return function (...a) { clearTimeout(t); t = setTimeout(() => fn.apply(this, a), ms || 200); };
  }

  /* ---------------- 图标 ---------------- */
  const PATHS = {
    folder: '<path d="M3 7a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.82 1.2a2 2 0 0 0 1.66.9H19a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.2-3.2"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
    share: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.5-1.5"/>',
    star: '<path d="M12 3.5l2.6 5.3 5.9.9-4.2 4.1 1 5.8-5.3-2.8-5.3 2.8 1-5.8L3.5 9.7l5.9-.9z"/>',
    trash: '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13"/><path d="M9 7V4h6v3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    folderPlus: '<path d="M3 7a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.82 1.2a2 2 0 0 0 1.66.9H19a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 11v6M9 14h6"/>',
    copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/>',
    move: '<path d="M5 8h14M5 8l3-3M5 8l3 3"/><path d="M19 16H5m14 0l-3-3m3 3l-3 3"/>',
    edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
    close: '<path d="M18 6L6 18M6 6l12 12"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/>',
    more: '<circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/><circle cx="5" cy="12" r="1.5"/>',
    check: '<path d="M20 6L9 17l-5-5"/>',
    right: '<path d="M9 18l6-6-6-6"/>',
    left: '<path d="M15 18l-6-6 6-6"/>',
    down: '<path d="M6 9l6 6 6-6"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 3.5a3.5 3.5 0 0 1 0 7"/><path d="M18 21a7 7 0 0 0-3-5.7"/>',
    shield: '<path d="M12 3l8 3v6c0 5-3.4 8.4-8 9.5C7.4 20.4 4 17 4 12V6z"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    list: '<path d="M4 6h16M4 12h16M4 18h16"/>',
    columns: '<rect x="3" y="4" width="6" height="16" rx="1.5"/><rect x="11" y="4" width="6" height="16" rx="1.5"/><rect x="19" y="4" width="2.5" height="16" rx="1"/>',
    cloud: '<path d="M18 17a4 4 0 0 0-.9-7.9A6 6 0 0 0 6 10.3A3.5 3.5 0 0 0 6.5 17z"/>',
    play: '<path d="M6 4l14 8-14 8z"/>',
    file: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
    home: '<path d="M4 11l8-7 8 7v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.8"/><path d="M21 15l-5-5-9 9"/>',
    video: '<rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M10 9.5l5 2.5-5 2.5z"/>',
    audio: '<path d="M9 18V6l10-2v12"/><circle cx="6" cy="18" r="3"/><circle cx="16" cy="16" r="3"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a1.6 1.6 0 1 1-2.3 2.3l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a1.6 1.6 0 1 1-3.2 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a1.6 1.6 0 1 1-2.3-2.3l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a1.6 1.6 0 1 1 0-3.2h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a1.6 1.6 0 1 1 2.3-2.3l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a1.6 1.6 0 1 1 3.2 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a1.6 1.6 0 1 1 2.3 2.3l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a1.6 1.6 0 1 1 0 3.2h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
    chart: '<path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/>',
    terminal: '<rect x="2" y="4" width="20" height="16" rx="2.5"/><path d="M6 9l4 3-4 3"/><path d="M13 15h5"/>',
    key: '<circle cx="8" cy="12" r="4"/><path d="M12 12h9M18 12v3M15 12v2.5"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
    save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
    eye: '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    filter: '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
    sort: '<path d="M7 4v16M7 20l-3-3M7 20l3-3"/><path d="M13 7h8M13 12h6M13 17h4"/>',
    restore: '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 3v6h6"/><path d="M12 8v4l3 2"/>',
  };

  function icon(name, size, cls) {
    const s = size || 18;
    const stroke = '<svg class="' + (cls || '') + '" width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">' + (PATHS[name] || PATHS.file) + '</svg>';
    return stroke;
  }

  const KIND_COLOR = {
    folder: '#f4b73d', image: '#38bdf8', video: '#a78bfa', audio: '#34d399', code: '#60a5fa',
    text: '#94a3b8', doc: '#3b82f6', xls: '#22c55e', ppt: '#f97316', pdf: '#ef4444', zip: '#eab308',
    apk: '#8b5cf6', psd: '#06b6d4', font: '#f472b6', file: '#9ca3af',
  };

  /** 文件类型图标：图片类型返回缩略图，其它返回带扩展名的色块 */
  function fileIcon(node, size) {
    const s = size || 34;
    if (node.type === 'folder') {
      return '<svg width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="' + KIND_COLOR.folder + '">' + PATHS.folder + '</svg>';
    }
    if (node.kind === 'image') {
      const src = P.cfg.index + '?_route=/api/files/thumb&id=' + node.id;
      return `<img src="${src}" alt="" style="width:${s}px;height:${s}px;object-fit:cover;border-radius:8px;background:var(--surface-3)" loading="lazy" onerror="this.style.display='none'">`;
    }
    const c = KIND_COLOR[node.kind] || KIND_COLOR.file;
    const ext = (node.ext || 'FILE').slice(0, 4).toUpperCase();
    return `<svg width="${s}" height="${s}" viewBox="0 0 40 40">
      <path d="M9 4h13l9 9v23a3 3 0 0 1-3 3H9a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3z" fill="${c}" opacity="0.13"/>
      <path d="M9 4h13l9 9v23a3 3 0 0 1-3 3H9a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3z" fill="none" stroke="${c}" stroke-width="1.6" opacity=".65"/>
      <path d="M22 4l9 9h-9z" fill="${c}" opacity=".3"/>
      <rect x="6" y="24" width="28" height="10" rx="3" fill="${c}" opacity=".85"/>
      <text x="20" y="31.4" text-anchor="middle" font-size="7.4" font-weight="700" fill="#fff" font-family="-apple-system,sans-serif">${esc(ext)}</text>
    </svg>`;
  }

  P.el = el; P.h = h; P.esc = esc; P.html = html; P.clear = clear;
  P.api = api; P.toast = toast; P.dialog = dialog; P.confirm = confirm; P.prompt = prompt;
  P.menu = menu; P.closeMenu = closeMenu; P.icon = icon; P.fileIcon = fileIcon;
  P.size = size; P.timeAgo = timeAgo; P.fullTime = fullTime; P.copy = copy; P.debounce = debounce;

  global.Pan = P;
})(window);
