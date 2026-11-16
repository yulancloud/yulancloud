/* =============================================================
 * admin/assets/api.js —— 后台 API 客户端
 *
 * 职责：
 *   1. 统一 fetch 封装：JSON、超时、错误归一化
 *   2. 401 自动跳登录页（会话过期/被踢）
 *   3. 所有写操作自动带同源 Origin（浏览器自动加），服务端做 CSRF 校验
 *   4. 提供 DOM 小工具（el / esc / money / toast / confirm）
 *
 * 安全：本文件不含任何密钥；所有权限判断都在服务端。
 * ============================================================= */
(function () {
  'use strict';

  /* =============================================================
   * 接口基址探测（与前台 assets/api.js 同一套逻辑）
   *
   * 后台页面在 /admin/ 下，接口在 /api/admin。
   * 写死 '/api/admin' 时，子目录部署（/shop/admin/）会请求 /api/admin
   * 而实际接口在 /shop/api/admin → 全部 404。
   * ============================================================= */
  function detectApiBase() {
    if (typeof window.SHOP_API_BASE === 'string' && window.SHOP_API_BASE) {
      return window.SHOP_API_BASE.replace(/\/+$/, '');
    }
    var meta = document.querySelector('meta[name="shop-api-base"]');
    if (meta && meta.getAttribute('content')) {
      return meta.getAttribute('content').replace(/\/+$/, '');
    }
    var path = location.pathname || '/';
    var dir = path.replace(/[^/]*$/, '').replace(/\/+$/, '');   // /shop/admin/ → /shop/admin
    // 剥掉虚拟目录段；与前台 api.js / 安装页保持一致
    dir = dir.replace(/\/(install|admin|console)$/, '');        // → /shop
    return dir + '/api';
  }

  var BASE = detectApiBase() + '/admin';

  /* ---------- 错误类型 ---------- */
  function ApiError(message, code, status) {
    this.name = 'ApiError';
    this.message = message || '请求失败';
    this.code = code || 'error';
    this.status = status || 0;
  }
  ApiError.prototype = Object.create(Error.prototype);

  /* ---------- 核心请求 ---------- */
  function request(method, path, body, opts) {
    opts = opts || {};
    var ctrl = new AbortController();
    var timer = setTimeout(function () { ctrl.abort(); }, opts.timeout || 15000);

    var init = {
      method: method,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      signal: ctrl.signal,
      cache: 'no-store'
    };
    if (body !== undefined && body !== null) {
      // v1.2 上传主题/插件包用 FormData：不能手写 Content-Type，
      // 必须让浏览器自动补 multipart/form-data; boundary=...，否则服务端收不到 $_FILES。
      if (typeof FormData !== 'undefined' && body instanceof FormData) {
        init.body = body;
      } else {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
      }
    }

    return fetch(BASE + path, init).then(function (res) {
      clearTimeout(timer);
      return res.text().then(function (text) {
        var data = null;
        if (text) {
          try { data = JSON.parse(text); } catch (e) { data = null; }
        }
        if (res.status === 401) {
          // 会话失效：跳登录页，并带上回跳地址
          if (!opts.silent401) {
            var back = encodeURIComponent(location.pathname + location.search);
            location.replace('login.html?next=' + back);
          }
          throw new ApiError('登录已过期，请重新登录', 'unauthorized', 401);
        }
        if (!res.ok || !data || data.ok !== true) {
          // 非 JSON 响应（多为 nginx 直接 404/502 的 HTML）：说明请求没进到 PHP 接口
          if (!data) {
            var rawMsg = res.status === 404
              ? '接口地址不存在（HTTP 404）：服务器未把 /api/* 转发到 PHP，请检查伪静态配置'
              : '接口返回了非 JSON 内容（HTTP ' + res.status + '），请检查服务器路由与 PHP 错误日志';
            throw new ApiError(rawMsg, res.status === 404 ? 'api_not_routed' : 'bad_response', res.status);
          }
          var msg = (data.error && data.error.message) || ('HTTP ' + res.status);
          var code = (data.error && data.error.code) || ('http_' + res.status);
          throw new ApiError(msg, code, res.status);
        }
        return data.data;
      });
    }, function (e) {
      clearTimeout(timer);
      if (e && e.name === 'AbortError') {
        throw new ApiError('请求超时，请检查网络', 'timeout', 0);
      }
      throw new ApiError('网络请求失败，请检查连接', 'network', 0);
    });
  }

  var API = {
    ApiError: ApiError,

    get: function (path, opts) { return request('GET', path, null, opts); },
    post: function (path, body, opts) { return request('POST', path, body || {}, opts); },
    put: function (path, body, opts) { return request('PUT', path, body || {}, opts); },
    del: function (path, opts) { return request('DELETE', path, null, opts); },

    /* ---- 会话 ---- */
    me: function () { return request('GET', '/me', null, { silent401: true }); },
    login: function (username, password) {
      return request('POST', '/login', { username: username, password: password }, { silent401: true });
    },
    logout: function () { return request('POST', '/logout', {}); },
    changePassword: function (oldPw, newPw) {
      return request('POST', '/password', { old_password: oldPw, new_password: newPw });
    },

    /* ---- 站点设置（标题/副标题/版权等文案） ---- */
    settings: function () { return request('GET', '/settings'); },
    saveSettings: function (settings) { return request('PUT', '/settings', { settings: settings }); },
    resetSettings: function () { return request('POST', '/settings/reset', {}); },

    /* ---- 概览 ---- */
    dashboard: function () { return request('GET', '/dashboard'); },

    /* ---- 商品 ---- */
    products: function (q) { return request('GET', '/products' + qs(q)); },
    product: function (id) { return request('GET', '/products/' + encodeURIComponent(id)); },
    createProduct: function (p) { return request('POST', '/products', p); },
    updateProduct: function (id, p) { return request('PUT', '/products/' + encodeURIComponent(id), p); },
    deleteProduct: function (id) { return request('DELETE', '/products/' + encodeURIComponent(id)); },
    setProductStatus: function (id, status) {
      return request('POST', '/products/' + encodeURIComponent(id) + '/status', { status: status });
    },
    adjustStock: function (id, delta) {
      return request('POST', '/products/' + encodeURIComponent(id) + '/stock', { delta: delta });
    },

    /* ---- 分类 ---- */
    categories: function () { return request('GET', '/categories'); },
    createCategory: function (c) { return request('POST', '/categories', c); },
    updateCategory: function (id, c) { return request('PUT', '/categories/' + encodeURIComponent(id), c); },
    deleteCategory: function (id) { return request('DELETE', '/categories/' + encodeURIComponent(id)); },

    /* ---- 订单 ---- */
    orders: function (q) { return request('GET', '/orders' + qs(q)); },
    order: function (no) { return request('GET', '/orders/' + encodeURIComponent(no)); },
    setOrderStatus: function (no, status, extra) {
      var body = { status: status };
      if (extra) { for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) body[k] = extra[k]; } }
      return request('POST', '/orders/' + encodeURIComponent(no) + '/status', body);
    },
    closeExpired: function () { return request('POST', '/orders/close-expired', {}); },

    /* ---- 评价 ---- */
    reviews: function (q) { return request('GET', '/reviews' + qs(q)); },
    createReview: function (r) { return request('POST', '/reviews', r); },
    updateReview: function (id, r) { return request('PUT', '/reviews/' + id, r); },
    deleteReview: function (id) { return request('DELETE', '/reviews/' + id); },
    setReviewStatus: function (id, status) {
      return request('POST', '/reviews/' + id + '/status', { status: status });
    },

    /* ---- 审计 ---- */
    audit: function (q) { return request('GET', '/audit' + qs(q)); },

    /* ---- v1.2 主题与插件（后台上传安装） ---- */
    extensions: function () { return request('GET', '/extensions'); },
    uploadExtension: function (formData) {
      return request('POST', '/extensions/upload', formData, { timeout: 60000 });
    },
    toggleExtension: function (type, id, enabled) {
      return request('POST', '/extensions/' + type + '/' + encodeURIComponent(id) + '/toggle',
                     { enabled: enabled });
    },
    activateTheme: function (id) {
      return request('POST', '/extensions/theme/' + encodeURIComponent(id) + '/activate', {});
    },
    deleteExtension: function (type, id) {
      return request('DELETE', '/extensions/' + type + '/' + encodeURIComponent(id));
    },

    /* ---- v1.2.1 功能插件底座 ---- */
    mailConfig: function () { return request('GET', '/mail/settings'); },
    saveMailConfig: function (config) { return request('PUT', '/mail/settings', { config: config }); },
    sendMailTest: function (to) { return request('POST', '/mail/test', { to: to }); },
    admins: function () { return request('GET', '/admins'); },
    createAdmin: function (data) { return request('POST', '/admins', data); },
    updateAdmin: function (id, data) { return request('PUT', '/admins/' + id, data); },
    setAdminStatus: function (id, status) { return request('POST', '/admins/' + id + '/status', { status: status }); },
    resetAdminPassword: function (id) { return request('POST', '/admins/' + id + '/reset-password', {}); },
    deleteAdmin: function (id) { return request('DELETE', '/admins/' + id); },

    /* ---- v5：用户 / 余额 ---- */
    users: function (q) { return request('GET', '/users' + qs(q)); },
    user: function (id) { return request('GET', '/users/' + id); },
    setUserStatus: function (id, status) {
      return request('POST', '/users/' + id + '/status', { status: status });
    },
    resetUserPassword: function (id) { return request('POST', '/users/' + id + '/reset-password', {}); },
    adjustBalance: function (id, deltaCents, remark) {
      return request('POST', '/users/' + id + '/balance', { delta_cents: deltaCents, remark: remark });
    },
    userLedger: function (id, q) { return request('GET', '/users/' + id + '/ledger' + qs(q)); },

    /* ---- v5：商品发货模板 ---- */
    productDelivery: function (id) { return request('GET', '/products/' + encodeURIComponent(id) + '/delivery'); },
    saveProductDelivery: function (id, payload) {
      return request('PUT', '/products/' + encodeURIComponent(id) + '/delivery', payload);
    },

    /* ---- v5：订单发货内容 ---- */
    orderDeliveries: function (no) { return request('GET', '/orders/' + encodeURIComponent(no) + '/deliveries'); },
    updateDelivery: function (id, content, status) {
      return request('PUT', '/deliveries/' + id, { content: content, status: status });
    },
    shipOrder: function (no, company, shipNo) {
      return request('POST', '/orders/' + encodeURIComponent(no) + '/ship', {
        ship_company: company, ship_no: shipNo
      });
    }
  };

  function qs(obj) {
    if (!obj) return '';
    var parts = [];
    for (var k in obj) {
      if (!Object.prototype.hasOwnProperty.call(obj, k)) continue;
      var v = obj[k];
      if (v === undefined || v === null || v === '') continue;
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
    }
    return parts.length ? '?' + parts.join('&') : '';
  }

  /* =============================================================
   * DOM / 格式化工具
   * ============================================================= */
  var U = {
    /** HTML 转义：所有来自数据库的文本进 innerHTML 前必须过这一层 */
    esc: function (s) {
      if (s === null || s === undefined) return '';
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    },

    /** 分 → ¥ 字符串 */
    money: function (cents) {
      var n = Number(cents) || 0;
      return '¥' + (n / 100).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },

    /** 分 → 元（编辑表单用，不带符号） */
    yuan: function (cents) {
      var n = Number(cents) || 0;
      return (n / 100).toFixed(2);
    },

    /** 元字符串 → 分（严格解析，非法返回 null） */
    toCents: function (v, allowNegative) {
      var s = String(v === null || v === undefined ? '' : v).trim();
      if (s === '') return null;
      // 余额扣款 UI 允许负数；商品价格等场景默认仍只接受正数。
      var re = allowNegative ? /^-?\d{1,9}(?:\.\d{1,2})?$/ : /^\d{1,9}(?:\.\d{1,2})?$/;
      if (!re.test(s)) return null;
      return Math.round(parseFloat(s) * 100);
    },

    /** 时间：把 UTC 的 "YYYY-MM-DD HH:MM:SS" 转成本地可读 */
    time: function (s) {
      if (!s) return '—';
      var str = String(s);
      // SQLite datetime('now') 是 UTC，补 Z 让浏览器按本地时区显示
      var iso = str.indexOf('T') >= 0 ? str : str.replace(' ', 'T') + 'Z';
      var d = new Date(iso);
      if (isNaN(d.getTime())) return str;
      var p = function (n) { return n < 10 ? '0' + n : String(n); };
      return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
        ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    },

    /** 相对时间 */
    ago: function (s) {
      if (!s) return '—';
      var iso = String(s).indexOf('T') >= 0 ? String(s) : String(s).replace(' ', 'T') + 'Z';
      var d = new Date(iso);
      if (isNaN(d.getTime())) return String(s);
      var diff = Math.floor((Date.now() - d.getTime()) / 1000);
      if (diff < 60) return '刚刚';
      if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前';
      if (diff < 86400) return Math.floor(diff / 3600) + ' 小时前';
      if (diff < 86400 * 30) return Math.floor(diff / 86400) + ' 天前';
      return U.time(s).slice(0, 10);
    },

    /** 状态 → 徽标 HTML */
    orderBadge: function (status) {
      var map = {
        pending:   ['待付款', 'warn'],
        paid:      ['已付款', 'ok'],
        shipped:   ['已发货', 'accent'],
        done:      ['已完成', 'ok'],
        cancelled: ['已取消', 'bad']
      };
      var m = map[status] || [status, ''];
      return '<span class="badge badge--' + m[1] + '">' + U.esc(m[0]) + '</span>';
    },

    productBadge: function (status) {
      var map = {
        active:  ['在售', 'ok'],
        hidden:  ['已下架', ''],
        soldout: ['售罄', 'warn']
      };
      var m = map[status] || [status, ''];
      return '<span class="badge badge--' + m[1] + '">' + U.esc(m[0]) + '</span>';
    },

    channelLabel: function (ch) {
      var map = { alipay: '支付宝', wxpay: '微信支付', qqpay: 'QQ 钱包', manual: '人工确认' };
      return map[ch] || (ch || '—');
    },

    /** 轻提示 */
    toast: function (msg, kind) {
      var zone = document.getElementById('toast-zone');
      if (!zone) {
        zone = document.createElement('div');
        zone.id = 'toast-zone';
        zone.className = 'toast-zone';
        zone.setAttribute('aria-live', 'polite');
        document.body.appendChild(zone);
      }
      var t = document.createElement('div');
      t.className = 'toast';
      if (kind === 'error') t.style.setProperty('--accent', '#c0463f');
      t.innerHTML = '<span class="toast__ico">' +
        (kind === 'error'
          ? '<svg class="ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.4"/><path d="M12 8v4.6M12 15.6v.4"/></svg>'
          : '<svg class="ico" viewBox="0 0 24 24"><path d="M5 12.6l4.2 4.2L19 7"/></svg>') +
        '</span><span>' + U.esc(msg) + '</span>';
      zone.appendChild(t);
      setTimeout(function () {
        t.classList.add('is-out');
        setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 300);
      }, 2500);
    },

    /** 确认对话框（原生 confirm 足够，避免自造弹层出 bug） */
    confirm: function (msg) {
      return window.confirm(msg);
    },

    /** 防抖 */
    debounce: function (fn, wait) {
      var timer = null;
      return function () {
        var args = arguments, self = this;
        clearTimeout(timer);
        timer = setTimeout(function () { fn.apply(self, args); }, wait || 300);
      };
    },

    /** 主题切换（与前台一致） */
    initTheme: function () {
      var saved = null;
      try { saved = localStorage.getItem('shiwu-theme'); } catch (e) {}
      if (saved === 'dark' || saved === 'light') {
        document.documentElement.setAttribute('data-theme', saved);
      }
      var btn = document.querySelector('[data-theme-toggle]');
      if (!btn) return;
      var sync = function () {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        btn.setAttribute('aria-label', dark ? '切换到亮色模式' : '切换到暗色模式');
        btn.innerHTML = dark
          ? '<svg class="ico ico--lg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.2"/><path d="M12 3.4v1.8M12 18.8v1.8M3.4 12h1.8M18.8 12h1.8M6 6l1.3 1.3M16.7 16.7L18 18M18 6l-1.3 1.3M7.3 16.7L6 18"/></svg>'
          : '<svg class="ico ico--lg" viewBox="0 0 24 24"><path d="M20 14.4A8.4 8.4 0 019.6 4a8.4 8.4 0 1010.4 10.4z"/></svg>';
      };
      sync();
      btn.addEventListener('click', function () {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        var next = dark ? 'light' : 'dark';
        document.body.classList.add('weight-shift');
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('shiwu-theme', next); } catch (e) {}
        sync();
        setTimeout(function () { document.body.classList.remove('weight-shift'); }, 520);
      });
    }
  };

  window.AdminAPI = API;
  window.AdminU = U;
})();
