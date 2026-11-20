/* =============================================================
 * 用户中心 /console 前端
 *
 * 设计要点：
 *  - 所有数据来自 /api/console/*，不信任前端任何金额
 *  - 会话靠 HttpOnly Cookie，JS 读不到 token（防 XSS 窃取）
 *  - 写请求自动带同源 Origin（浏览器默认行为），服务端做 CSRF 校验
 *  - 未登录时展示登录/注册，登录后按 hash 路由切换视图
 *  - 所有用户内容用 textContent 写入，绝不拼 innerHTML（防 XSS）
 * ============================================================= */
(function () {
  'use strict';

  // v1.2.2 插件扩展点：插件可往这些数组注册回调，向登录/注册表单注入字段或校验逻辑。
  window.ConsoleExt = { hooks: { authForm: [], beforeSubmit: [] }, currentMode: null };

  /* ---------- API 基址自动探测（支持子目录部署） ---------- */
  function detectApiBase() {
    var meta = document.querySelector('meta[name="shop-api-base"]');
    if (meta && meta.getAttribute('content')) {
      return meta.getAttribute('content').replace(/\/+$/, '');
    }
    var path = location.pathname || '/';
    var dir = path.replace(/[^/]*$/, '').replace(/\/+$/, '');
    // 剥掉虚拟目录段；与前台 api.js / 安装页保持一致
    dir = dir.replace(/\/(install|admin|console)$/, '');
    return dir + '/api';
  }
  var API = detectApiBase();

  var state = {
    user: null,
    site: {},
    payment: { enabled: false, channels: [] },
    view: 'orders',
    orders: [],
    orderDetail: null,
    ledger: [],
    recharges: [],
    rechargeAmount: 10000,
    yulan: [],
    yulanDetail: null,
    // v1.4
    tickets: [],
    ticketDetail: null,
    cloudVms: []
  };

  /* ---------- 工具 ---------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  }
  function money(cents) {
    var n = Number(cents || 0) / 100;
    return '¥' + n.toFixed(2);
  }
  function fmtTime(s) {
    if (!s) return '—';
    // 后端存 UTC（datetime('now')），转本地显示
    var d = new Date(String(s).replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return String(s);
    var p = function (x) { return x < 10 ? '0' + x : String(x); };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
      ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  function toast(msg, isErr) {
    var zone = $('#toastZone');
    if (!zone) return;
    var t = el('div', 'toast');
    t.appendChild(el('span', 'toast__ico', isErr ? '!' : '✓'));
    t.appendChild(el('span', '', msg));
    zone.appendChild(t);
    setTimeout(function () {
      t.classList.add('is-out');
      setTimeout(function () { t.remove(); }, 300);
    }, 2600);
  }

  /* ---------- 请求封装 ---------- */
  function request(method, path, body) {
    var opt = {
      method: method,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    };
    if (body !== undefined) {
      opt.headers['Content-Type'] = 'application/json';
      opt.body = JSON.stringify(body);
    }
    return fetch(API + path, opt).then(function (res) {
      // 先读文本再解析：接口被 nginx 直接 404（伪静态未配）时返回的是 HTML，
      // 直接 res.json() 会失败，旧代码就统一显示「服务器返回异常」，掩盖了真实原因。
      return res.text().then(function (text) {
        var json = null;
        if (text) {
          try { json = JSON.parse(text); } catch (err) { json = null; }
        }

        // 401：未登录 / 会话过期 → 统一跳登录页，不提示「服务器异常」
        if (res.status === 401) {
          var e401 = new Error((json && json.error && json.error.message) || '未登录');
          e401.code = (json && json.error && json.error.code) || 'unauthorized';
          e401.status = 401;
          throw e401;
        }

        // 非 JSON 响应：说明请求没进到 PHP 接口（路由/伪静态问题），给出可诊断提示
        if (json === null) {
          var eRaw = new Error(
            res.status === 404
              ? '接口地址不存在（HTTP 404）：服务器未把 /api/* 转发到 PHP，请检查伪静态配置'
              : '接口返回了非 JSON 内容（HTTP ' + res.status + '），请检查服务器路由与 PHP 错误日志'
          );
          eRaw.code = res.status === 404 ? 'api_not_routed' : 'bad_response';
          eRaw.status = res.status;
          throw eRaw;
        }

        if (!res.ok || !json.ok) {
          var e = new Error((json.error && json.error.message) || '请求失败');
          e.code = (json.error && json.error.code) || 'error';
          e.status = res.status;
          throw e;
        }
        return json.data;
      });
    });
  }

  /* ---------- 站点文案（后台可改，实时生效） ---------- */
  function applySite(s) {
    state.site = s || {};
    document.querySelectorAll('[data-site-name]').forEach(function (n) {
      if (s.site_name) n.textContent = s.site_name;
    });
    document.querySelectorAll('[data-site-name-en]').forEach(function (n) {
      if (s.site_name_en) n.textContent = s.site_name_en;
    });
    document.querySelectorAll('[data-footer-copyright]').forEach(function (n) {
      n.textContent = s.footer_copyright || '';
      n.style.display = s.footer_copyright ? '' : 'none';
    });
    document.querySelectorAll('[data-footer-note]').forEach(function (n) {
      n.textContent = s.footer_note || '';
      n.style.display = s.footer_note ? '' : 'none';
    });
    if (s.meta_title) document.title = '用户中心 · ' + s.site_name;
  }

  /* =============================================================
   * 登录 / 注册
   * ============================================================= */
  function renderAuth(mode) {
    var app = $('#app');
    app.textContent = '';

    var wrap = el('div', 'auth');
    var card = el('div', 'auth__card');
    card.appendChild(el('h1', 'auth__title', '登录用户中心'));
    card.appendChild(el('p', 'auth__sub', '登录后可查看订单、发货内容与账户余额。'));

    var tabs = el('div', 'auth__tabs');
    var tLogin = el('button', 'auth__tab', '登录');
    var tReg = el('button', 'auth__tab', '注册');
    tLogin.type = 'button'; tReg.type = 'button';
    tLogin.setAttribute('aria-selected', mode === 'login' ? 'true' : 'false');
    tReg.setAttribute('aria-selected', mode === 'register' ? 'true' : 'false');
    tLogin.onclick = function () { renderAuth('login'); };
    tReg.onclick = function () { renderAuth('register'); };
    tabs.appendChild(tLogin); tabs.appendChild(tReg);
    card.appendChild(tabs);

    var form = el('form', 'auth__form');
    var errBox = el('div', 'auth__err');
    errBox.hidden = true;

    function field(label, name, type, placeholder, autocomplete) {
      var g = el('div');
      g.appendChild(el('label', 'auth__label', label));
      var i = el('input', 'field');
      i.type = type; i.name = name; i.placeholder = placeholder || '';
      i.autocomplete = autocomplete || 'off';
      i.required = true;
      g.appendChild(i);
      return { group: g, input: i };
    }

    var fUser = field('用户名', 'username', 'text', '3-32 位字母数字', 'username');
    form.appendChild(fUser.group);

    if (mode === 'register') {
      var fName = field('昵称（可选）', 'display_name', 'text', '显示名称', 'nickname');
      fName.input.required = false;
      form.appendChild(fName.group);
    }

    var fPw = field('密码', 'password', 'password', mode === 'register' ? '至少 8 位，含字母和数字' : '请输入密码', mode === 'register' ? 'new-password' : 'current-password');
    form.appendChild(fPw.group);

    if (mode === 'register') {
      form.appendChild(el('p', 'auth__hint', '密码使用 Argon2id 哈希存储，我们无法看到你的明文密码。'));
    }

    form.appendChild(errBox);

    var submit = el('button', 'btn btn--primary btn--block', mode === 'login' ? '登录' : '注册并登录');
    submit.type = 'submit';
    form.appendChild(submit);

    form.onsubmit = function (ev) {
      ev.preventDefault();
      errBox.hidden = true;
      submit.disabled = true;
      submit.textContent = '处理中…';

      var payload = {
        username: fUser.input.value.trim(),
        password: fPw.input.value
      };
      if (mode === 'register') {
        payload.display_name = fName.input.value.trim();
      }

      // v1.2.2 插件钩子：插件可往 payload 追加字段（如邮箱验证码），
      // 或返回 false 阻止提交（前端先校验；后端仍有对应校验钩子）。
      if (window.ConsoleExt && Array.isArray(window.ConsoleExt.hooks.beforeSubmit)) {
        for (var i = 0; i < window.ConsoleExt.hooks.beforeSubmit.length; i++) {
          var ret;
          try { ret = window.ConsoleExt.hooks.beforeSubmit[i](payload, mode, form); }
          catch (e2) { ret = false; }
          if (ret === false) {
            submit.disabled = false;
            submit.textContent = mode === 'login' ? '登录' : '注册并登录';
            return;
          }
        }
      }

      var path = mode === 'login' ? '/console/login' : '/console/register';
      request('POST', path, payload).then(function (data) {
        state.user = data.user;
        toast(mode === 'login' ? '登录成功' : '注册成功');
        location.hash = '#/orders';
        boot();
      }).catch(function (e) {
        errBox.textContent = e.message;
        errBox.hidden = false;
        submit.disabled = false;
        submit.textContent = mode === 'login' ? '登录' : '注册并登录';
      });
    };

    // v1.2.2 插件钩子：允许插件在注册/登录表单注入额外字段与交互
    if (window.ConsoleExt && Array.isArray(window.ConsoleExt.hooks.authForm)) {
      window.ConsoleExt.hooks.authForm.forEach(function (fn) {
        try { fn(form, mode, { field: field, err: function (m) { errBox.textContent = m; errBox.hidden = !m; } }); }
        catch (e) { /* 单个插件出错不影响登录 */ }
      });
    }

    card.appendChild(form);
    wrap.appendChild(card);
    app.appendChild(wrap);
    if (window.ConsoleExt) { window.ConsoleExt.currentMode = mode; }
  }

  /* =============================================================
   * 主框架
   * ============================================================= */
  function renderShell() {
    var app = $('#app');
    app.textContent = '';

    // 防御：overview 未返回时用空统计，避免渲染报错
    var st = state.stats || { total_orders: 0, pending_orders: 0, paid_orders: 0, spent_cents: 0, delivered_items: 0 };
    state.stats = st;

    // 概览
    var ov = el('div', 'ov');
    function ovCard(k, v, accent) {
      var c = el('div', 'ov__card' + (accent ? ' ov__card--accent' : ''));
      c.appendChild(el('div', 'ov__k', k));
      var val = el('div', 'ov__v');
      val.appendChild(document.createTextNode(v));
      c.appendChild(val);
      return c;
    }
    ov.appendChild(ovCard('账户余额', money(state.user.balance_cents), true));
    ov.appendChild(ovCard('订单总数', String(st.total_orders)));
    ov.appendChild(ovCard('待付款', String(st.pending_orders)));
    ov.appendChild(ovCard('已发货内容', String(st.delivered_items)));
    app.appendChild(ov);

    // 标签
    var tabs = el('div', 'tabs');
    var views = [
      ['orders', '我的订单'],
      ['yulan', '控制台'],
      ['cloud', '我的云主机'],
      ['tickets', '工单'],
      ['balance', '余额与充值'],
      ['profile', '账户设置']
    ];
    views.forEach(function (v) {
      var b = el('button', 'tab', v[1]);
      b.type = 'button';
      b.setAttribute('aria-selected', state.view === v[0] ? 'true' : 'false');
      b.onclick = function () { location.hash = '#/' + v[0]; };
      tabs.appendChild(b);
    });
    app.appendChild(tabs);

    var body = el('div', 'console__body');
    body.id = 'viewBody';
    app.appendChild(body);

    if (state.view === 'orders') renderOrders(body);
    else if (state.view === 'yulan') renderYulan(body);
    else if (state.view === 'cloud') renderCloudVms(body);
    else if (state.view === 'tickets') renderTickets(body);
    else if (state.view === 'balance') renderBalance(body);
    else renderProfile(body);
  }

  /* =============================================================
   * 我的订单
   * ============================================================= */
  function renderOrders(root) {
    if (state.orderDetail) return renderOrderDetail(root);

    var head = el('div', 'sec-title');
    head.appendChild(el('h2', '', '我的订单'));
    var refresh = el('button', 'btn btn--sm btn--ghost', '刷新');
    refresh.type = 'button';
    refresh.onclick = function () { loadOrders().then(function () { renderShell(); }); };
    head.appendChild(refresh);
    root.appendChild(head);

    if (!state.orders.length) {
      var e = el('div', 'empty-box');
      e.appendChild(document.createTextNode('还没有订单。'));
      var a = el('a', '', '去逛逛 →');
      a.href = '../index.html';
      e.appendChild(a);
      root.appendChild(e);
      return;
    }

    var list = el('div', 'orders');
    state.orders.forEach(function (o) {
      var card = el('div', 'order');

      var h = el('div', 'order__head');
      h.appendChild(el('span', 'order__no', o.order_no));
      h.appendChild(el('span', 'order__time', fmtTime(o.created_at)));
      h.appendChild(el('span', 'order__spacer'));
      var badge = el('span', 'badge badge--' + o.status, o.status_label);
      h.appendChild(badge);
      card.appendChild(h);

      var b = el('div', 'order__body');
      var items = el('div', 'order__items');
      items.textContent = o.item_qty + ' 件商品' + (o.has_delivery ? ' · 含发货内容' : '');
      b.appendChild(items);
      b.appendChild(el('div', 'order__total', money(o.total)));

      var acts = el('div', 'order__acts');
      var view = el('button', 'btn btn--sm', '查看详情');
      view.type = 'button';
      view.onclick = function () { openOrder(o.order_no); };
      acts.appendChild(view);

      if (o.status === 'pending') {
        var pay = el('button', 'btn btn--sm btn--primary', '去支付');
        pay.type = 'button';
        pay.onclick = function () { openOrder(o.order_no); };
        acts.appendChild(pay);
      }
      b.appendChild(acts);
      card.appendChild(b);
      list.appendChild(card);
    });
    root.appendChild(list);
  }

  function openOrder(no) {
    request('GET', '/console/orders/' + encodeURIComponent(no)).then(function (d) {
      state.orderDetail = d;
      renderShell();
    }).catch(function (e) { toast(e.message, true); });
  }

  function renderOrderDetail(root) {
    var o = state.orderDetail;

    var head = el('div', 'sec-title');
    var back = el('button', 'btn btn--sm btn--ghost', '← 返回订单列表');
    back.type = 'button';
    back.onclick = function () { state.orderDetail = null; renderShell(); };
    head.appendChild(back);
    head.appendChild(el('span', 'badge badge--' + o.status, o.status_label));
    root.appendChild(head);

    // 基本信息
    var c1 = el('div', 'detail-card');
    c1.appendChild(el('h3', '', '订单信息'));
    var kv = el('dl', 'kv');
    function row(k, v) {
      kv.appendChild(el('dt', '', k));
      kv.appendChild(el('dd', '', v));
    }
    row('订单号', o.order_no);
    row('下单时间', fmtTime(o.created_at));
    if (o.paid_at) row('支付时间', fmtTime(o.paid_at));
    row('收货人', o.buyer_name);
    row('联系电话', o.buyer_phone);
    row('收货地址', o.buyer_address);
    if (o.note) row('备注', o.note);
    if (o.ship_company) row('快递', o.ship_company + ' ' + o.ship_no);
    c1.appendChild(kv);
    root.appendChild(c1);

    // 商品
    var c2 = el('div', 'detail-card');
    c2.appendChild(el('h3', '', '商品明细'));
    var items = el('div', 'items');
    o.items.forEach(function (it) {
      var r = el('div', 'item-row');
      var nm = el('div', 'item-row__name');
      nm.appendChild(el('div', '', it.name));
      var opt = [it.color, it.size].filter(Boolean).join(' / ');
      if (opt) nm.appendChild(el('div', 'item-row__opt', opt));
      r.appendChild(nm);
      r.appendChild(el('span', 'item-row__qty', '×' + it.qty));
      r.appendChild(el('span', 'item-row__amt', money(it.line)));
      items.appendChild(r);
    });
    c2.appendChild(items);

    var sum = el('div', '');
    sum.style.marginTop = '14px';
    sum.style.paddingTop = '12px';
    sum.style.borderTop = '1px solid var(--hair)';
    [['商品小计', money(o.subtotal)], ['运费', money(o.ship)], ['优惠', o.discount ? '-' + money(o.discount) : money(0)]]
      .forEach(function (p) {
        var r = el('div', 'summary__row');
        r.appendChild(el('span', '', p[0]));
        r.appendChild(el('b', '', p[1]));
        sum.appendChild(r);
      });
    var tot = el('div', 'summary__total');
    tot.appendChild(el('span', '', '实付金额'));
    tot.appendChild(el('b', '', money(o.total)));
    sum.appendChild(tot);
    c2.appendChild(sum);
    root.appendChild(c2);

    // 发货内容
    var c3 = el('div', 'detail-card');
    c3.appendChild(el('h3', '', '发货内容'));
    if (!o.deliveries || !o.deliveries.length) {
      c3.appendChild(el('p', 'tiny', '暂无发货内容。'));
    } else {
      o.deliveries.forEach(function (d) {
        var box = el('div', 'delivery' + (d.kind === 'virtual' ? ' delivery--virtual' : ''));
        var dh = el('div', 'delivery__head');
        dh.appendChild(el('span', 'delivery__name', d.product_name));
        dh.appendChild(el('span', 'badge badge--' + (d.status === 'delivered' ? 'done' : 'pending'), d.status_label));
        dh.appendChild(el('span', 'badge', d.kind_label));
        if (d.content) {
          var cp = el('button', 'btn btn--sm btn--ghost delivery__copy', '复制');
          cp.type = 'button';
          cp.onclick = function () {
            navigator.clipboard.writeText(d.content).then(function () {
              toast('已复制');
            }).catch(function () { toast('复制失败，请手动选择', true); });
          };
          dh.appendChild(cp);
        }
        box.appendChild(dh);
        if (d.content) {
          var pre = el('pre', 'delivery__content');
          pre.textContent = d.content;
          box.appendChild(pre);
        } else {
          box.appendChild(el('p', 'tiny', '等待管理员发货。'));
        }
        c3.appendChild(box);
      });
    }
    root.appendChild(c3);

    // 支付操作
    if (o.status === 'pending') {
      var c4 = el('div', 'detail-card');
      c4.appendChild(el('h3', '', '支付'));
      var acts = el('div', 'order__acts');

      var balBtn = el('button', 'btn btn--primary', '余额支付（' + money(state.user.balance_cents) + '）');
      balBtn.type = 'button';
      balBtn.onclick = function () {
        if (!confirm('确认使用余额支付 ' + money(o.total) + '？')) return;
        balBtn.disabled = true;
        request('POST', '/console/orders/' + encodeURIComponent(o.order_no) + '/pay-balance', {})
          .then(function (d) {
            toast('支付成功，余额 ' + money(d.balance));
            state.user.balance_cents = d.balance;
            return refreshAll();
          })
          .then(function () { openOrder(o.order_no); })
          .catch(function (e) { toast(e.message, true); balBtn.disabled = false; });
      };
      acts.appendChild(balBtn);

      if (state.payment.enabled && state.payment.channels.length) {
        state.payment.channels.forEach(function (ch) {
          var b = el('button', 'btn', ch.label);
          b.type = 'button';
          b.onclick = function () { payOnline(o.order_no, ch.id, b); };
          acts.appendChild(b);
        });
      } else {
        acts.appendChild(el('span', 'tiny', '在线支付未配置，可用余额支付或联系店主。'));
      }
      c4.appendChild(acts);
      root.appendChild(c4);
    }
  }

  /* 在线支付：拿网关表单参数 → 自动 POST 到网关 */
  function payOnline(orderNo, channel, btn) {
    btn.disabled = true;
    request('POST', '/console/orders/' + encodeURIComponent(orderNo) + '/pay', { channel: channel })
      .then(function (d) {
        var form = document.createElement('form');
        form.method = d.method || 'POST';
        form.action = d.gateway;
        form.style.display = 'none';
        Object.keys(d.fields || {}).forEach(function (k) {
          var i = document.createElement('input');
          i.type = 'hidden';
          i.name = k;
          i.value = d.fields[k];
          form.appendChild(i);
        });
        document.body.appendChild(form);
        form.submit();
      })
      .catch(function (e) { toast(e.message, true); btn.disabled = false; });
  }

  /* =============================================================
   * 余额与充值
   * ============================================================= */
  function renderBalance(root) {
    var hero = el('div', 'bal-hero');
    var left = el('div');
    left.appendChild(el('div', 'bal-hero__k', '账户余额'));
    var v = el('div', 'bal-hero__v');
    v.appendChild(el('small', '', '¥'));
    v.appendChild(document.createTextNode((state.user.balance_cents / 100).toFixed(2)));
    left.appendChild(v);
    hero.appendChild(left);

    var right = el('div');
    right.appendChild(el('div', 'bal-hero__k', '充值'));
    var row = el('div', 'recharge-row');
    [1000, 5000, 10000, 50000].forEach(function (amt) {
      var b = el('button', 'recharge-amt', money(amt));
      b.type = 'button';
      b.setAttribute('aria-pressed', state.rechargeAmount === amt ? 'true' : 'false');
      b.onclick = function () {
        state.rechargeAmount = amt;
        renderShell();
      };
      row.appendChild(b);
    });
    right.appendChild(row);

    var payRow = el('div', 'recharge-row');
    if (state.payment.enabled && state.payment.channels.length) {
      state.payment.channels.forEach(function (ch) {
        var b = el('button', 'btn btn--sm btn--primary', '充值 ' + money(state.rechargeAmount) + ' · ' + ch.label);
        b.type = 'button';
        b.onclick = function () { doRecharge(ch.id, b); };
        payRow.appendChild(b);
      });
    } else {
      payRow.appendChild(el('span', 'tiny', '在线充值未配置（需在服务端设置易支付商户号与密钥）。'));
    }
    right.appendChild(payRow);
    hero.appendChild(right);
    root.appendChild(hero);

    // 流水
    var c1 = el('div', 'detail-card');
    c1.appendChild(el('h3', '', '余额流水'));
    if (!state.ledger.length) {
      c1.appendChild(el('p', 'tiny', '暂无流水记录。'));
    } else {
      var lg = el('div', 'ledger');
      state.ledger.forEach(function (r) {
        var row2 = el('div', 'ledger__row');
        var l = el('div');
        l.appendChild(el('div', '', r.type_label + (r.remark ? ' · ' + r.remark : '')));
        l.appendChild(el('div', 'ledger__t', fmtTime(r.created_at) + (r.order_no ? ' · ' + r.order_no : '')));
        row2.appendChild(l);
        var amt = el('div', 'ledger__amt ' + (r.amount > 0 ? 'ledger__amt--in' : 'ledger__amt--out'),
          (r.amount > 0 ? '+' : '') + money(r.amount));
        row2.appendChild(amt);
        row2.appendChild(el('div', 'ledger__bal', '余额 ' + money(r.balance_after)));
        lg.appendChild(row2);
      });
      c1.appendChild(lg);
    }
    root.appendChild(c1);

    // 充值记录
    var c2 = el('div', 'detail-card');
    c2.appendChild(el('h3', '', '充值记录'));
    if (!state.recharges.length) {
      c2.appendChild(el('p', 'tiny', '暂无充值记录。'));
    } else {
      var lg2 = el('div', 'ledger');
      state.recharges.forEach(function (r) {
        var row3 = el('div', 'ledger__row');
        var l = el('div');
        l.appendChild(el('div', '', r.recharge_no));
        l.appendChild(el('div', 'ledger__t', fmtTime(r.created_at)));
        row3.appendChild(l);
        row3.appendChild(el('div', 'ledger__amt', money(r.amount)));
        row3.appendChild(el('div', 'ledger__bal', r.status_label));
        lg2.appendChild(row3);
      });
      c2.appendChild(lg2);
    }
    root.appendChild(c2);
  }

  function doRecharge(channel, btn) {
    btn.disabled = true;
    request('POST', '/console/balance/recharge', {
      amount_cents: state.rechargeAmount,
      channel: channel
    }).then(function (d) {
      var form = document.createElement('form');
      form.method = d.method || 'POST';
      form.action = d.gateway;
      form.style.display = 'none';
      Object.keys(d.fields || {}).forEach(function (k) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = k; i.value = d.fields[k];
        form.appendChild(i);
      });
      document.body.appendChild(form);
      form.submit();
    }).catch(function (e) { toast(e.message, true); btn.disabled = false; });
  }

  /* =============================================================
   * 账户设置
   * ============================================================= */
  function renderProfile(root) {
    var c1 = el('div', 'detail-card');
    c1.appendChild(el('h3', '', '基本资料'));

    var form = el('form', 'auth__form');
    form.style.maxWidth = '420px';

    var g1 = el('div');
    g1.appendChild(el('label', 'auth__label', '用户名（不可修改）'));
    var u = el('input', 'field');
    u.value = state.user.username;
    u.disabled = true;
    g1.appendChild(u);
    form.appendChild(g1);

    var g2 = el('div');
    g2.appendChild(el('label', 'auth__label', '昵称'));
    var dn = el('input', 'field');
    dn.value = state.user.display_name || '';
    dn.maxLength = 40;
    g2.appendChild(dn);
    form.appendChild(g2);

    var g3 = el('div');
    g3.appendChild(el('label', 'auth__label', '邮箱（可选）'));
    var em = el('input', 'field');
    em.type = 'email';
    em.value = state.user.email || '';
    g3.appendChild(em);
    form.appendChild(g3);

    var save = el('button', 'btn btn--primary', '保存资料');
    save.type = 'submit';
    form.appendChild(save);

    form.onsubmit = function (ev) {
      ev.preventDefault();
      save.disabled = true;
      request('PUT', '/console/profile', {
        display_name: dn.value.trim(),
        email: em.value.trim()
      }).then(function (d) {
        state.user = d.user;
        toast('资料已保存');
        renderShell();
      }).catch(function (e) { toast(e.message, true); save.disabled = false; });
    };
    c1.appendChild(form);
    root.appendChild(c1);

    // 改密
    var c2 = el('div', 'detail-card');
    c2.appendChild(el('h3', '', '修改密码'));
    var f2 = el('form', 'auth__form');
    f2.style.maxWidth = '420px';

    function pwField(label, name) {
      var g = el('div');
      g.appendChild(el('label', 'auth__label', label));
      var i = el('input', 'field');
      i.type = 'password'; i.name = name; i.autocomplete = 'new-password'; i.required = true;
      g.appendChild(i);
      return { g: g, i: i };
    }
    var oldPw = pwField('当前密码', 'old_password');
    var newPw = pwField('新密码（至少 8 位，含字母和数字）', 'new_password');
    f2.appendChild(oldPw.g);
    f2.appendChild(newPw.g);

    var btn = el('button', 'btn btn--primary', '更新密码');
    btn.type = 'submit';
    f2.appendChild(btn);

    f2.onsubmit = function (ev) {
      ev.preventDefault();
      btn.disabled = true;
      request('POST', '/console/password', {
        old_password: oldPw.i.value,
        new_password: newPw.i.value
      }).then(function () {
        toast('密码已更新，其他设备已退出登录');
        oldPw.i.value = ''; newPw.i.value = '';
        btn.disabled = false;
      }).catch(function (e) { toast(e.message, true); btn.disabled = false; });
    };
    c2.appendChild(f2);
    root.appendChild(c2);
  }

  /* =============================================================
   * 控制台 · 我的欲蓝财务魔方商品
   *
   * 数据来自 /api/console/yulan/accounts，服务端已强制按登录用户过滤。
   * 详情里可修改「登录用户名」和「密码」：网站不存储密码，改密由 Windows
   * APP 以管理员权限强制重置（无需旧密码）。所有内容一律用 textContent。
   * ============================================================= */
  /* =============================================================
   * v1.4：我的云主机
   * ============================================================= */
  function vmStatusBadge(s) {
    var map = { running: '运行中', stopped: '已停止', pending: '待开通', creating: '开通中', failed: '失败', cancelled: '已取消' };
    return el('span', 'badge badge--' + (s === 'running' ? 'done' : (s === 'failed' ? 'warn' : '')), map[s] || s);
  }
  function renderCloudVms(root) {
    var head = el('div', 'sec-title');
    head.appendChild(el('h2', '', '我的云主机'));
    root.appendChild(head);
    var tip = el('div', 'hint');
    tip.textContent = '购买魔方云商品并支付后，云主机实例会自动出现在这里。可进行开机 / 关机 / 重启 / 重置密码等操作。';
    tip.style.marginBottom = '14px';
    root.appendChild(tip);

    var items = state.cloudVms || [];
    if (!items.length) {
      root.appendChild(el('div', 'empty-box', '暂无云主机实例'));
      return;
    }
    items.forEach(function (v) {
      var card = el('div', 'order-card');
      var top = el('div', 'order-card__top');
      top.appendChild(el('strong', '', v.instance_name || v.external_id || ('#' + v.id)));
      top.appendChild(vmStatusBadge(v.status));
      card.appendChild(top);

      var spec = [v.cpu, v.mem, v.disk, v.bandwidth].filter(Boolean).join(' / ');
      var lines = [
        ['IP 地址', v.ip || '—'],
        ['操作系统', v.os || '—'],
        ['配置', spec || '—'],
        ['地区', v.region || '—'],
        ['订单', v.order_no || '—'],
      ];
      var meta = el('div', 'kv');
      lines.forEach(function (ln) {
        var row = el('div', 'kv__row');
        row.appendChild(el('span', 'kv__k', ln[0]));
        row.appendChild(el('span', 'kv__v', ln[1]));
        meta.appendChild(row);
      });
      card.appendChild(meta);

      var acts = el('div', 'order-card__actions');
      [['start', '开机'], ['stop', '关机'], ['restart', '重启'], ['reset-password', '重置密码']].forEach(function (a) {
        var b = el('button', 'btn btn--sm btn--ghost', a[1]);
        b.type = 'button';
        b.onclick = function () { vmAction(v.id, a[0]); };
        acts.appendChild(b);
      });
      card.appendChild(acts);
      root.appendChild(card);
    });
  }
  function vmAction(vmId, action) {
    var opts = {};
    if (action === 'reset-password') {
      var np = window.prompt('输入新密码（留空由系统生成）：', '');
      if (np === null) return;
      if (np) opts.password = np;
    }
    if (action !== 'reset-password' && !window.confirm('确定执行「' + action + '」？')) return;
    request('POST', '/console/cloud-vms/' + vmId + '/action', { action: action, opts: opts }).then(function (r) {
      toast(r.detail || '操作成功');
      loadCloudVms().then(function () { renderShell(); });
    }).catch(function (e) { toast(e.message, true); });
  }

  /* =============================================================
   * v1.4：工单
   * ============================================================= */
  function ticketStatusBadge(s) {
    var map = { open: '待处理', processing: '处理中', replied: '已回复', closed: '已关闭' };
    return el('span', 'badge badge--' + (s === 'replied' || s === 'closed' ? 'done' : 'warn'), map[s] || s);
  }
  function renderTickets(root) {
    if (state.ticketDetail) return renderTicketDetail(root);
    var head = el('div', 'sec-title');
    head.appendChild(el('h2', '', '工单'));
    var add = el('button', 'btn btn--primary', '提交工单');
    add.type = 'button';
    add.onclick = function () { state.ticketDetail = { _new: true }; renderShell(); };
    head.appendChild(add);
    root.appendChild(head);

    var items = state.tickets || [];
    if (!items.length) {
      root.appendChild(el('div', 'empty-box', '还没有工单，遇到问题可点「提交工单」。'));
      return;
    }
    items.forEach(function (t) {
      var card = el('div', 'order-card');
      var top = el('div', 'order-card__top');
      top.appendChild(el('strong', '', t.title));
      top.appendChild(ticketStatusBadge(t.status));
      card.appendChild(top);
      var meta = el('div', 'hint');
      meta.textContent = t.ticket_no + ' · ' + t.category_label + ' · ' + fmtTime(t.created_at);
      card.appendChild(meta);
      var acts = el('div', 'order-card__actions');
      var view = el('button', 'btn btn--sm btn--ghost', '查看详情');
      view.type = 'button';
      view.onclick = function () {
        request('GET', '/console/tickets/' + t.id).then(function (d) {
          state.ticketDetail = d.item || d;
          renderShell();
        }).catch(function (e) { toast(e.message, true); });
      };
      acts.appendChild(view);
      card.appendChild(acts);
      root.appendChild(card);
    });
  }
  function renderTicketDetail(root) {
    var t = state.ticketDetail;
    if (t._new) {
      var head = el('div', 'sec-title');
      head.appendChild(el('h2', '', '提交工单'));
      root.appendChild(head);
      var form = el('div', 'form-card');
      var fTitle = textField('标题', 'title', '简要描述问题');
      var fOrder = textField('关联订单号（可选）', 'order_no', '');
      var fCat = selectField('分类', 'category', [['technical', '技术故障'], ['account', '账号问题'], ['payment', '支付/充值'], ['product', '商品/交付'], ['other', '其他']]);
      var fPri = selectField('优先级', 'priority', [['normal', '普通'], ['low', '低'], ['high', '高']]);
      var fBody = el('div', 'fld');
      fBody.appendChild(el('label', '', '问题描述'));
      var bodyTa = el('textarea', 'field'); bodyTa.rows = 6; bodyTa.id = 'tk-body';
      fBody.appendChild(bodyTa);
      form.appendChild(fTitle.node); form.appendChild(fOrder.node);
      form.appendChild(fCat.node); form.appendChild(fPri.node); form.appendChild(fBody);
      var submit = el('button', 'btn btn--primary', '提交');
      submit.type = 'button';
      submit.style.marginTop = '12px';
      submit.onclick = function () {
        var payload = {
          title: fTitle.input.value.trim(),
          order_no: fOrder.input.value.trim(),
          category: fCat.sel.value,
          priority: fPri.sel.value,
          body: bodyTa.value.trim(),
        };
        if (!payload.title || !payload.body) { toast('请填写标题和问题描述', true); return; }
        submit.disabled = true;
        request('POST', '/console/tickets', payload).then(function () {
          toast('工单已提交');
          state.ticketDetail = null;
          loadTickets().then(function () { renderShell(); });
        }).catch(function (e) { toast(e.message, true); submit.disabled = false; });
      };
      form.appendChild(submit);
      var back = el('button', 'btn btn--ghost', '返回');
      back.type = 'button'; back.style.marginLeft = '8px';
      back.onclick = function () { state.ticketDetail = null; renderShell(); };
      form.appendChild(back);
      root.appendChild(form);
      return;
    }

    var h = el('div', 'sec-title');
    h.appendChild(el('h2', '', t.ticket_no || ('工单 #' + t.id)));
    var backBtn = el('button', 'btn btn--ghost', '返回列表');
    backBtn.type = 'button';
    backBtn.onclick = function () { state.ticketDetail = null; renderShell(); };
    h.appendChild(backBtn);
    root.appendChild(h);
    var info = el('div', 'hint');
    info.textContent = t.title + ' · ' + (t.status_label || t.status) + ' · ' + fmtTime(t.created_at);
    root.appendChild(info);

    (t.messages || []).forEach(function (m) {
      var box = el('div', 'msg-box');
      var who = el('div', 'hint', (m.role === 'admin' ? '客服' : '我') + ' · ' + (m.author || '') + ' · ' + fmtTime(m.created_at));
      box.appendChild(who);
      var body = el('div', 'msg-body', m.body);
      box.appendChild(body);
      root.appendChild(box);
    });

    if (t.status !== 'closed') {
      var wrap = el('div', 'form-card');
      var ta = el('textarea', 'field'); ta.rows = 4; ta.id = 'tk-reply';
      wrap.appendChild(ta);
      var row = el('div', 'order-card__actions');
      var send = el('button', 'btn btn--primary', '追加回复');
      send.type = 'button';
      send.onclick = function () {
        if (!ta.value.trim()) { toast('请填写回复内容', true); return; }
        send.disabled = true;
        request('POST', '/console/tickets/' + t.id + '/reply', { body: ta.value.trim() }).then(function (d) {
          toast('已回复');
          state.ticketDetail = d.item || d;
          renderShell();
        }).catch(function (e) { toast(e.message, true); send.disabled = false; });
      };
      var close = el('button', 'btn btn--ghost', '关闭工单');
      close.type = 'button';
      close.onclick = function () {
        request('POST', '/console/tickets/' + t.id + '/close', {}).then(function (d) {
          toast('工单已关闭');
          state.ticketDetail = d.item || d;
          renderShell();
        }).catch(function (e) { toast(e.message, true); });
      };
      row.appendChild(send); row.appendChild(close);
      wrap.appendChild(row);
      root.appendChild(wrap);
    }
  }
  function textField(label, id, placeholder) {
    var fld = el('div', 'fld');
    fld.appendChild(el('label', '', label));
    var input = el('input', 'field'); input.id = 'tk-' + id; input.placeholder = placeholder || '';
    fld.appendChild(input);
    return { node: fld, input: input };
  }
  function selectField(label, id, options) {
    var fld = el('div', 'fld');
    fld.appendChild(el('label', '', label));
    var sel = el('select', 'field'); sel.id = 'tk-' + id;
    options.forEach(function (o) {
      var op = el('option', '', o[1]); op.value = o[0];
      sel.appendChild(op);
    });
    fld.appendChild(sel);
    return { node: fld, sel: sel };
  }

  function yulanBadge(role) {
    return el('span', 'badge badge--' + (role === 'admin' ? 'paid' : 'done'),
      role === 'admin' ? '管理员账号' : '普通账号');
  }

  function renderYulan(root) {
    if (state.yulanDetail) return renderYulanDetail(root);

    var head = el('div', 'sec-title');
    head.appendChild(el('h2', '', '控制台 · 我的欲蓝财务魔方'));
    var refresh = el('button', 'btn btn--sm btn--ghost', '刷新');
    refresh.type = 'button';
    refresh.onclick = function () { loadYulan().then(function () { renderShell(); }); };
    head.appendChild(refresh);
    root.appendChild(head);

    root.appendChild(el('p', 'tiny',
      '这里列出你已购买并创建成功的欲蓝财务魔方账号。进入「详情」可修改登录用户名与密码；密码不存储，改密由服务器 APP 以管理员权限强制重置。'));

    if (!state.yulan.length) {
      var e = el('div', 'empty-box');
      e.appendChild(document.createTextNode('还没有已创建的欲蓝财务魔方商品。'));
      var a = el('a', '', '去选购 →');
      a.href = '../index.html#products';
      e.appendChild(a);
      root.appendChild(e);
      return;
    }

    var list = el('div', 'orders');
    state.yulan.forEach(function (it) {
      var card = el('div', 'order');
      var h = el('div', 'order__head');
      h.appendChild(el('span', 'order__no', it.product_name || '欲蓝财务魔方'));
      h.appendChild(el('span', 'order__time', fmtTime(it.created_at)));
      h.appendChild(el('span', 'order__spacer'));
      h.appendChild(yulanBadge(it.role));
      card.appendChild(h);

      var b = el('div', 'order__body');
      var info = el('div', 'order__items');
      info.appendChild(el('div', '', '登录用户名：' + it.account));
      if (it.connection) info.appendChild(el('div', '', '连接地址：' + it.connection));
      info.appendChild(el('div', '', '订单号：' + it.order_no));
      b.appendChild(info);

      var acts = el('div', 'order__acts');
      var detail = el('button', 'btn btn--sm btn--primary', '详情 / 修改');
      detail.type = 'button';
      detail.onclick = function () { openYulan(it.id); };
      acts.appendChild(detail);
      b.appendChild(acts);
      card.appendChild(b);
      list.appendChild(card);
    });
    root.appendChild(list);
  }

  function openYulan(id) {
    request('GET', '/console/yulan/accounts/' + id).then(function (d) {
      state.yulanDetail = d.item;
      renderShell();
    }).catch(function (e) { toast(e.message, true); });
  }

  function renderYulanDetail(root) {
    var it = state.yulanDetail;

    var head = el('div', 'sec-title');
    var back = el('button', 'btn btn--sm btn--ghost', '← 返回控制台');
    back.type = 'button';
    back.onclick = function () { state.yulanDetail = null; renderShell(); };
    head.appendChild(back);
    head.appendChild(yulanBadge(it.role));
    root.appendChild(head);

    // 基本信息
    var c1 = el('div', 'detail-card');
    c1.appendChild(el('h3', '', '商品信息'));
    var kv = el('dl', 'kv');
    function row(k, v) {
      kv.appendChild(el('dt', '', k));
      kv.appendChild(el('dd', '', v));
    }
    row('商品', it.product_name || '欲蓝财务魔方');
    row('订单号', it.order_no);
    row('登录用户名', it.account);
    row('账号类型', it.role === 'admin' ? '管理员' : '普通用户');
    row('连接地址', it.connection || '（未配置，请咨询客服）');
    row('登录密码', '平台不存储，无法查看；可随时在下方重设');
    row('创建时间', fmtTime(it.created_at));
    if (it.updated_at) row('最近修改', fmtTime(it.updated_at));
    c1.appendChild(kv);
    root.appendChild(c1);

    // 修改用户名 / 密码
    var c2 = el('div', 'detail-card');
    c2.appendChild(el('h3', '', '修改登录用户名 / 密码'));
    c2.appendChild(el('p', 'tiny',
      '两项至少填一项，留空表示不修改。改用户名后请用新用户名登录远程桌面；改密码后请用新密码登录（原来的密码立即失效）。'));

    var form = el('form', 'auth__form');
    form.style.maxWidth = '420px';

    var gAcc = el('div');
    gAcc.appendChild(el('label', 'auth__label', '新用户名（留空不改）'));
    var iAcc = el('input', 'field');
    iAcc.type = 'text'; iAcc.placeholder = '1-20 位字母、数字、点、下划线、连字符';
    iAcc.autocomplete = 'off'; iAcc.maxLength = 20;
    gAcc.appendChild(iAcc);
    form.appendChild(gAcc);

    var gPw = el('div');
    gPw.appendChild(el('label', 'auth__label', '新密码（留空不改，至少 6 位）'));
    var iPw = el('input', 'field');
    iPw.type = 'password'; iPw.placeholder = '至少 6 位';
    iPw.autocomplete = 'new-password';
    gPw.appendChild(iPw);
    form.appendChild(gPw);

    var gPw2 = el('div');
    gPw2.appendChild(el('label', 'auth__label', '确认新密码'));
    var iPw2 = el('input', 'field');
    iPw2.type = 'password'; iPw2.placeholder = '再输入一次新密码';
    iPw2.autocomplete = 'new-password';
    gPw2.appendChild(iPw2);
    form.appendChild(gPw2);

    var errBox = el('div', 'auth__err');
    errBox.hidden = true;
    form.appendChild(errBox);

    var save = el('button', 'btn btn--primary', '保存修改');
    save.type = 'submit';
    form.appendChild(save);

    form.onsubmit = function (ev) {
      ev.preventDefault();
      errBox.hidden = true;
      var na = iAcc.value.trim();
      var np = iPw.value;
      var np2 = iPw2.value;
      if (na === '' && np === '') {
        errBox.textContent = '请至少填写新用户名或新密码';
        errBox.hidden = false; return;
      }
      if (np !== '' && np !== np2) {
        errBox.textContent = '两次输入的新密码不一致';
        errBox.hidden = false; return;
      }
      if (np !== '' && np.length < 6) {
        errBox.textContent = '新密码至少 6 位';
        errBox.hidden = false; return;
      }
      var payload = {};
      if (na !== '') payload.new_account = na;
      if (np !== '') payload.new_password = np;
      if (!confirm('确认修改该欲蓝账号？\n' +
        (na !== '' ? '新用户名：' + na + '\n' : '') +
        (np !== '' ? '密码：将强制重置为新密码\n' : '') +
        '\n修改后请用新信息登录远程桌面。')) return;

      save.disabled = true;
      save.textContent = '提交中…';
      request('POST', '/console/yulan/accounts/' + it.id + '/update', payload)
        .then(function (d) {
          toast(d.message || '修改成功');
          state.yulanDetail = d.item;
          return loadYulan();
        })
        .then(function () { renderShell(); })
        .catch(function (e) {
          errBox.textContent = e.message;
          errBox.hidden = false;
          save.disabled = false;
          save.textContent = '保存修改';
        });
    };
    c2.appendChild(form);
    root.appendChild(c2);
  }

  /* =============================================================
   * 数据加载
   * ============================================================= */
  function loadOrders() {
    return request('GET', '/console/orders?limit=50').then(function (d) {
      state.orders = d.items || [];
    });
  }
  function loadLedger() {
    return request('GET', '/console/balance/ledger?limit=50').then(function (d) {
      state.ledger = d.items || [];
    });
  }
  function loadRecharges() {
    return request('GET', '/console/balance/recharges?limit=20').then(function (d) {
      state.recharges = d.items || [];
    });
  }
  function loadYulan() {
    return request('GET', '/console/yulan/accounts').then(function (d) {
      state.yulan = d.items || [];
    }).catch(function () { state.yulan = state.yulan || []; });
  }
  // v1.4
  function loadTickets() {
    return request('GET', '/console/tickets?limit=50').then(function (d) {
      state.tickets = d.items || [];
    }).catch(function () { state.tickets = state.tickets || []; });
  }
  function loadCloudVms() {
    return request('GET', '/console/cloud-vms').then(function (d) {
      state.cloudVms = d.items || [];
    }).catch(function () { state.cloudVms = state.cloudVms || []; });
  }

  function refreshAll() {
    return request('GET', '/console/overview').then(function (d) {
      state.user = d.user;
      state.stats = d.stats;
      state.payment = d.payment || state.payment;
      updateHead();
      return Promise.all([loadOrders(), loadLedger(), loadRecharges(), loadYulan(), loadTickets(), loadCloudVms()]);
    });
  }

  function updateHead() {
    var hu = $('#headUser');
    if (!hu) return;
    if (state.user) {
      hu.hidden = false;
      var b = $('#headBalance');
      if (b) b.textContent = money(state.user.balance_cents);
    } else {
      hu.hidden = true;
    }
  }

  /* =============================================================
   * 路由
   * ============================================================= */
  function parseHash() {
    var h = (location.hash || '').replace(/^#\/?/, '');
    if (['orders', 'yulan', 'cloud', 'tickets', 'balance', 'profile'].indexOf(h) >= 0) return h;
    return 'orders';
  }

  function boot() {
    // 站点文案
    request('GET', '/site').then(applySite).catch(function () {});

    request('GET', '/console/me').then(function (d) {
      state.user = d.user;
      state.payment = d.payment || state.payment;
      state.view = parseHash();
      updateHead();
      return refreshAll();
    }).then(function () {
      state.view = parseHash();
      renderShell();
    }).catch(function (e) {
      // 401 = 未登录 / 会话过期：直接渲染登录表单（不提示「服务器异常」）
      if (e.status === 401) {
        state.user = null;
        updateHead();
        renderAuth('login');
        return;
      }
      // 其他错误：区分「接口没被路由」与「服务端真错误」，给出可操作提示
      var app = $('#app');
      app.textContent = '';
      var box = el('div', 'empty-box');
      if (e.code === 'api_not_routed') {
        box.appendChild(el('div', '', '接口未接通：服务器没有把 /api/* 转发到 PHP。'));
        box.appendChild(el('div', '', '请检查 Nginx 伪静态（try_files $uri /api/index.php）或 Apache .htaccess 是否生效。'));
      } else {
        box.appendChild(el('div', '', '加载失败：' + e.message));
      }
      app.appendChild(box);
    });
  }

  window.addEventListener('hashchange', function () {
    if (!state.user) return;
    state.view = parseHash();
    state.orderDetail = null;
    state.yulanDetail = null;
    state.ticketDetail = null;
    // 切换视图时重新拉取数据，避免看到过期列表
    var jobs = [];
    if (state.view === 'orders') jobs.push(loadOrders());
    if (state.view === 'yulan') jobs.push(loadYulan());
    if (state.view === 'cloud') jobs.push(loadCloudVms());
    if (state.view === 'tickets') jobs.push(loadTickets());
    if (state.view === 'balance') { jobs.push(loadLedger(), loadRecharges()); }
    Promise.all(jobs).then(function () {
      renderShell();
    }).catch(function () {
      renderShell();
    });
  });

  // v1.2.2 通用插件资源加载器：先注入插件脚本（它们注册 ConsoleExt.hooks），再 boot。
  // 动态 script 用 Promise 串行等待 onload，保证 authForm/beforeSubmit 钩子在首屏渲染前就绪。
  function loadPluginAssets() {
    return fetch(new URL('../api/features', location.href).toString(), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : {}; })
      .then(function (j) {
        var d = (j && j.data) || j || {};
        var list = (d.assets && d.assets.console) || [];
        var chain = Promise.resolve();
        list.forEach(function (rel) {
          chain = chain.then(function () {
            return new Promise(function (resolve) {
              var url = new URL(rel, location.href).toString();
              if (/\.css(\?|$)/.test(rel)) {
                var link = document.createElement('link');
                link.rel = 'stylesheet'; link.href = url; document.head.appendChild(link);
                resolve();
              } else {
                var s = document.createElement('script');
                s.src = url;
                s.onload = resolve; s.onerror = resolve;
                document.body.appendChild(s);
              }
            });
          });
        });
        return chain;
      }).catch(function () {});
  }

  document.addEventListener('DOMContentLoaded', function () {
    var lo = $('#btnLogout');
    if (lo) {
      lo.onclick = function () {
        request('POST', '/console/logout', {}).then(function () {
          state.user = null;
          state.orderDetail = null;
          updateHead();
          renderAuth('login');
          toast('已退出登录');
        }).catch(function () { location.reload(); });
      };
    }
    loadPluginAssets().then(boot);
  });
})();
