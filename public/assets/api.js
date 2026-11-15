/* =============================================================
 * assets/api.js —— 前端数据适配层
 *
 * 职责：
 *   1. 优先从 PHP 后端 /api/* 取真实数据，覆盖 data.js 的静态兜底数据
 *   2. 后端不可用时（纯静态托管）自动回退到 data.js，页面照常可用
 *   3. 提供下单 / 查单的封装，统一错误处理
 *
 * 加载顺序：data.js → api.js → 页面脚本（页面脚本注册到 window.__shopBoot）
 * ============================================================= */
(function () {
  'use strict';

  /* =============================================================
   * 接口基址自动探测
   *
   * 为什么需要：站点可能部署在根目录（/api/...）也可能在子目录（/shop/api/...）。
   * 写死 '/api' 时，子目录部署下请求会打到 /api/... 而实际接口在 /shop/api/...，
   * 结果就是「页面看着正常（回退静态数据），一点下单就 404」。
   *
   * 探测顺序：
   *   1. window.SHOP_API_BASE（部署方显式指定，最优先）
   *   2. <meta name="shop-api-base" content="...">
   *   3. 从当前页面路径推断：/shop/cart.html → /shop/api
   * ============================================================= */
  function detectApiBase() {
    if (typeof window.SHOP_API_BASE === 'string' && window.SHOP_API_BASE) {
      return window.SHOP_API_BASE.replace(/\/+$/, '');
    }
    var meta = document.querySelector('meta[name="shop-api-base"]');
    if (meta && meta.getAttribute('content')) {
      return meta.getAttribute('content').replace(/\/+$/, '');
    }
    // 从当前路径推断部署前缀：去掉最后一段文件名，再拼 /api
    var path = location.pathname || '/';
    var dir = path.replace(/[^/]*$/, '');        // /shop/cart.html → /shop/
    dir = dir.replace(/\/+$/, '');                // /shop/ → /shop
    // 虚拟目录路由（/admin/、/console/、/install/）的真实文件都在站点根，
    // 必须剥掉这一段，否则会拼出 /admin/api、/install/api 这类不存在的地址。
    dir = dir.replace(/\/(install|admin|console)$/, '');
    return dir + '/api';
  }

  var API = detectApiBase();
  var state = { source: 'static', error: null, apiBase: API };

  /* 把 HTTP 状态翻译成用户能看懂的话，避免只弹一个「404」 */
  function friendlyMessage(status, code, fallback) {
    if (code === 'not_installed') {
      return '站点尚未完成安装，请先访问 /install 初始化';
    }
    if (code === 'payment_not_configured') {
      return '支付功能尚未配置，请联系店主在后台填写易支付参数';
    }
    if (status === 404) {
      return '接口未找到（' + API + '）。请确认服务器已把 /api/* 转发到入口文件，'
           + '子目录部署时还需保证前端请求路径与后端路由一致';
    }
    if (status === 503) {
      return '服务暂时不可用，请稍后重试';
    }
    if (status === 0) {
      return '无法连接服务器，请检查网络或后端是否已启动';
    }
    return fallback || ('请求失败（HTTP ' + status + '）');
  }

  function get(path, opts) {
    opts = opts || {};
    var ctrl = new AbortController();
    var timer = setTimeout(function () { ctrl.abort(); }, opts.timeout || 6000);
    return fetch(API + path, {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
      signal: ctrl.signal
    }).then(function (res) {
      clearTimeout(timer);
      return res.json().catch(function () { return null; }).then(function (body) {
        if (!res.ok || !body || body.ok !== true) {
          var code = body && body.error ? body.error.code : 'http_' + res.status;
          var raw = body && body.error && body.error.message ? body.error.message : '';
          var err = new Error(friendlyMessage(res.status, code, raw));
          err.code = code;
          err.status = res.status;
          throw err;
        }
        return body.data;
      });
    }, function (e) {
      clearTimeout(timer);
      var err = new Error(friendlyMessage(0, 'network', ''));
      err.code = 'network';
      err.status = 0;
      throw err;
    });
  }

  function post(path, payload, headers) {
    var ctrl = new AbortController();
    var timer = setTimeout(function () { ctrl.abort(); }, 12000);
    var h = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (headers) { for (var k in headers) { if (Object.prototype.hasOwnProperty.call(headers, k)) h[k] = headers[k]; } }
    return fetch(API + path, {
      method: 'POST',
      headers: h,
      credentials: 'same-origin',
      body: JSON.stringify(payload),
      signal: ctrl.signal
    }).then(function (res) {
      clearTimeout(timer);
      return res.json().catch(function () { return null; }).then(function (body) {
        if (!res.ok || !body || body.ok !== true) {
          var code = body && body.error ? body.error.code : 'http_' + res.status;
          var raw = body && body.error && body.error.message ? body.error.message : '';
          var err = new Error(friendlyMessage(res.status, code, raw));
          err.code = code;
          err.status = res.status;
          throw err;
        }
        return body.data;
      });
    }, function () {
      clearTimeout(timer);
      var err = new Error(friendlyMessage(0, 'network', ''));
      err.code = 'network';
      err.status = 0;
      throw err;
    });
  }

  /* ---------- 数据装载 ---------- */
  function mergeCatalog(data) {
    var S = window.SHOP || (window.SHOP = {});
    if (Array.isArray(data.categories) && data.categories.length) S.categories = data.categories;
    if (Array.isArray(data.products)) S.products = data.products;
    if (Array.isArray(data.marquee) && data.marquee.length) S.marquee = data.marquee;
    if (!Array.isArray(S.reviews)) S.reviews = [];
    if (!Array.isArray(S.thinking)) S.thinking = [];
    return S;
  }

  function mergeProduct(p) {
    var S = window.SHOP || (window.SHOP = {});
    S.products = S.products || [];
    var hit = false;
    for (var i = 0; i < S.products.length; i++) {
      if (S.products[i].id === p.id) { S.products[i] = p; hit = true; break; }
    }
    if (!hit) S.products.push(p);
    if (Array.isArray(p.thinking) && p.thinking.length) S.thinking = p.thinking;
    return p;
  }

  function currentProductId() {
    try {
      var id = new URLSearchParams(location.search).get('id');
      return id && /^[a-z0-9][a-z0-9-]{0,31}$/.test(id) ? id : null;
    } catch (e) { return null; }
  }

  /* ---------- 站点设置（标题/副标题/版权，后台可改） ---------- */
  function applySite(s) {
    if (!s || typeof s !== 'object') return;
    window.SHOP_SITE = s;
    /**
     * @param hideIfEmpty 为空时是否隐藏元素。页脚类字段（备案号、版权、说明、
     *                    联系方式）后台留空就该整块消失，不能留下写死的默认文案。
     */
    function setText(sel, val, hideIfEmpty) {
      var nodes = document.querySelectorAll(sel);
      var empty = !(typeof val === 'string' && val.trim() !== '');
      for (var i = 0; i < nodes.length; i++) {
        var el = nodes[i];
        if (empty) {
          if (hideIfEmpty) {
            el.textContent = '';
            el.style.display = 'none';
          }
          continue;                 // 空值且不隐藏：保留 HTML 里的默认文案
        }
        el.style.display = '';      // 有值：恢复显示（防止上一轮隐藏残留）
        // 用 textContent 赋值，绝不拼 innerHTML —— 从根上避免存储型 XSS
        el.textContent = val;
      }
    }
    setText('[data-site="name"]', s.site_name);
    setText('[data-site="name-en"]', s.site_name_en);
    setText('[data-site="hero-title"]', s.hero_title);
    setText('[data-site="hero-subtitle"]', s.hero_subtitle);
    setText('[data-site="hero-eyebrow"]', s.hero_eyebrow);
    setText('[data-site="footer-note"]', s.footer_note, true);
    setText('[data-site="footer-copyright"]', s.footer_copyright, true);
    setText('[data-site="footer-icp"]', s.footer_icp, true);
    setText('[data-site="footer-contact"]', s.footer_contact, true);
    setText('[data-site="site-intro"]', s.site_intro);
    setText('[data-site="home-notice"]', s.home_notice);
    setText('[data-site="home-products-title"]', s.home_products_title);

    // 公告条：有内容才显示，空则隐藏
    var notices = document.querySelectorAll('[data-site-block="notice"]');
    for (var n = 0; n < notices.length; n++) {
      notices[n].hidden = !(typeof s.home_notice === 'string' && s.home_notice.trim() !== '');
    }

    // 流式标题：data-stream 属性也要同步，否则动画播的还是旧文案
    var streams = document.querySelectorAll('[data-site-stream="hero-title"]');
    for (var j = 0; j < streams.length; j++) {
      if (typeof s.hero_title === 'string' && s.hero_title) {
        streams[j].setAttribute('data-stream', s.hero_title);
      }
    }
    var subStreams = document.querySelectorAll('[data-site-stream="hero-subtitle"]');
    for (var k = 0; k < subStreams.length; k++) {
      if (typeof s.hero_subtitle === 'string' && s.hero_subtitle) {
        subStreams[k].setAttribute('data-stream', s.hero_subtitle);
      }
    }

    // 客服邮箱（复制按钮）
    if (typeof s.support_email === 'string' && s.support_email) {
      var copies = document.querySelectorAll('[data-site-copy="support_email"]');
      for (var m = 0; m < copies.length; m++) {
        copies[m].setAttribute('data-copy', s.support_email);
      }
    }

    // 浏览器标题与描述
    if (typeof s.meta_title === 'string' && s.meta_title) {
      document.title = s.meta_title;
    }
    if (typeof s.meta_description === 'string' && s.meta_description) {
      var md = document.querySelector('meta[name="description"]');
      if (md) md.setAttribute('content', s.meta_description);
    }
  }

  /* ---------- 启动：先取数，再跑页面脚本 ---------- */
  var booted = false;
  function runBoot() {
    if (booted) return;
    booted = true;
    var fns = window.__shopBootQueue || [];
    window.__shopBootQueue = [];
    fns.forEach(function (fn) {
      try { fn(); } catch (e) { console.error('[shop] boot error', e); }
    });
    document.documentElement.setAttribute('data-api-source', state.source);
    document.dispatchEvent(new CustomEvent('shop:ready', { detail: state }));
  }

  function boot() {
    var pid = currentProductId();
    var task = pid
      ? get('/products/' + encodeURIComponent(pid)).then(function (p) {
          mergeProduct(p);
          state.source = 'api';
        })
      : get('/catalog').then(function (d) {
          mergeCatalog(d);
          state.source = 'api';
        });

    // 站点设置与业务数据并行拉取；失败不影响页面（用 HTML 里的默认文案）
    var siteTask = get('/site').then(function (s) {
      applySite(s);
    }).catch(function () { /* 静默：保留 HTML 默认文案 */ });

    task.catch(function (err) {
      state.error = err.code || 'error';
      state.source = 'static';

      // 站点尚未安装：直接送去安装向导，而不是让用户看到一个空壳页面
      if (err.code === 'not_installed') {
        var base = API.replace(/\/api$/, '');
        location.replace(base + '/install');
        return;
      }

      // 静默回退：静态数据已在 data.js 中，页面照常渲染
      if (window.console && console.info) {
        console.info('[shop] 后端不可用，使用静态数据兜底：' + err.message);
      }
    }).then(function () {
      return siteTask;
    }).then(runBoot);
  }

  /* ---------- 对外接口 ---------- */
  window.ShopAPI = {
    state: state,
    apiBase: API,
    /** 站点设置（标题/副标题/版权等） */
    site: function () { return get('/site'); },
    /** 安装状态（未安装时前台据此跳转安装向导） */
    installStatus: function () { return get('/install/status'); },
    catalog: function (params) {
      var q = [];
      if (params) {
        ['cat', 'q', 'sort', 'page', 'per_page'].forEach(function (k) {
          if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
            q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
          }
        });
      }
      return get('/catalog' + (q.length ? '?' + q.join('&') : ''));
    },
    product: function (id) { return get('/products/' + encodeURIComponent(id)); },
    /** 发表真实评价（公开，服务端按 IP 限流） */
    postReview: function (id, payload) {
      return post('/products/' + encodeURIComponent(id) + '/reviews', payload);
    },
    /**
     * 购物袋金额预估（只读）。运费由服务端按数据库真实 type 计算，
     * 前端不再靠商品列表自行判断虚拟商品，避免缓存/分页/静态兜底导致误收运费。
     */
    quote: function (items) {
      return post('/orders/quote', { items: items });
    },
    createOrder: function (payload, idempotencyKey) {
      return post('/orders', payload, idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : null);
    },
    getOrder: function (orderNo, token) {
      return get('/orders/' + encodeURIComponent(orderNo) + '?token=' + encodeURIComponent(token));
    },
    /** 支付能力（是否启用、可用渠道） */
    paymentInfo: function () { return get('/payment/info'); },
    /** v5：当前前台登录态（未登录时 reject，err.status === 401） */
    me: function () { return get('/console/me'); },
    /**
     * 发起支付：服务端返回网关地址与已签名的表单字段。
     * 前端只负责把表单 POST 出去，绝不自己拼签名（密钥不在前端）。
     */
    payOrder: function (orderNo, token, channel) {
      return post('/orders/' + encodeURIComponent(orderNo) + '/pay', { token: token, channel: channel });
    },
    /** 已登录用户：按订单号发起在线支付（微信 / 支付宝） */
    payOrderForUser: function (orderNo, channel) {
      return post('/console/orders/' + encodeURIComponent(orderNo) + '/pay', { channel: channel });
    },
    /** 已登录用户：余额支付（服务端扣余额 + 写流水 + 自动发货） */
    payBalance: function (orderNo) {
      return post('/console/orders/' + encodeURIComponent(orderNo) + '/pay-balance', {});
    },
    /** 免费订单（¥0.00）直接支付成功（不跳转易支付） */
    freePay: function (orderNo) {
      return post('/orders/' + encodeURIComponent(orderNo) + '/free-pay', {});
    },
    /** 充值：返回网关表单 */
    recharge: function (amountCents, channel) {
      return post('/console/balance/recharge', { amount_cents: amountCents, channel: channel });
    },
    /** 生成幂等键（同一购物车重复点击不会重复下单） */
    newIdempotencyKey: function () {
      if (window.crypto && crypto.randomUUID) return crypto.randomUUID().replace(/-/g, '');
      var s = '';
      for (var i = 0; i < 32; i++) s += Math.floor(Math.random() * 16).toString(16);
      return s;
    }
  };

  /* 页面脚本通过此函数注册启动逻辑（在数据就绪后执行） */
  window.shopBoot = function (fn) {
    if (booted) { try { fn(); } catch (e) { console.error(e); } return; }
    (window.__shopBootQueue = window.__shopBootQueue || []).push(fn);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
