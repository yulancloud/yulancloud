/* 创新主题控制台逻辑 —— 全部对接真实 API，与经典 console 共用后端，仅界面不同 */
(function () {
  'use strict';
  var API = (function () {
    var base = document.querySelector('meta[name="shop-api-base"]')?.content || '';
    return base || (location.pathname.replace(/\/innovative\/.*$/, '') + '/api');
  })();
  var view = 'home';

  function req(method, path, body) {
    var opt = { method: method, headers: {}, credentials: 'same-origin' };
    if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    return fetch(API + path, opt).then(function (r) { return r.text().then(function (t) {
      var j = null; try { j = JSON.parse(t); } catch (e) {}
      if (r.status === 401) { location.href = 'login.html'; throw new Error('未登录'); }
      if (!r.ok || !j || !j.ok) { var e2 = new Error((j && j.error && j.error.message) || ('HTTP ' + r.status)); e2.code = j && j.error && j.error.code; throw e2; }
      return j.data;
    }); });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(cents) { return '¥' + (Number(cents || 0) / 100).toFixed(2); }
  function toast(msg, err) {
    var d = document.createElement('div'); d.className = 'iv-toast' + (err ? ' iv-toast--err' : '');
    d.textContent = msg; document.getElementById('ivToast').appendChild(d); setTimeout(function () { d.remove(); }, 2800);
  }
  function stateBadge(s) {
    var map = {
      pending: ['待支付', 'red'], paid: ['已支付', 'blue'], shipped: ['已发货', 'blue'],
      done: ['已完成', 'green'], cancelled: ['已取消', 'gray'], open: ['处理中', 'blue'], closed: ['已关闭', 'gray']
    };
    var m = map[s] || [s, 'gray'];
    return '<span class="iv-badge iv-badge--' + m[1] + '">' + esc(m[0]) + '</span>';
  }

  // 路由
  window.go = function (v) {
    view = v;
    document.querySelectorAll('.iv-side__item').forEach(function (n) { n.classList.toggle('is-active', n.getAttribute('data-view') === v); });
    var titles = { home: '控制台首页', orders: '我的订单', cloud: '我的云主机', yulan: '财务魔方账号', tickets: '工单支持', balance: '余额与充值', profile: '账号设置' };
    document.getElementById('ivViewTitle').textContent = titles[v] || '控制台';
    if (location.hash !== '#/' + v) { history.replaceState(null, '', '#/' + v); }
    render();
  };
  window.logout = function () { req('POST', '/console/logout', {}).then(function () { location.href = 'login.html'; }).catch(function () { location.href = 'login.html'; }); };
  window.closeModal = function () { document.getElementById('ivModal').style.display = 'none'; };
  window.addEventListener('hashchange', function () {
    var v = (location.hash || '').replace(/^#\/?/, '') || 'home';
    if (v !== view) go(v);
  });

  function render() {
    var host = document.getElementById('ivView');
    host.innerHTML = '<div class="iv-loading">正在加载…</div>';
    var map = {
      home: renderHome, orders: renderOrders, cloud: renderCloud, yulan: renderYulan,
      tickets: renderTickets, balance: renderBalance, profile: renderProfile
    };
    (map[view] || renderHome)(host);
  }

  // ---------- 首页 ----------
  function renderHome(host) {
    req('GET', '/console/overview').then(function (d) {
      var s = d.stats || {}, u = d.user || {};
      var pct = Math.min(100, Math.round((Number(s.paid_orders || 0) / Math.max(1, Number(s.total_orders || 0))) * 100));
      host.innerHTML =
        '<div class="iv-panel">' +
          '<div class="iv-userbar">' +
            '<div class="iv-avatar" style="width:48px;height:48px;font-size:20px">' + esc((u.username || '用').charAt(0)) + '</div>' +
            '<div class="iv-userbar__hello"><b>你好，' + esc(u.display_name || u.username || '用户') + '</b><p>欢迎回到控制台，以下是你的账户概览</p></div>' +
          '</div>' +
        '</div>' +
        '<div class="iv-stat-grid" style="margin-bottom:20px">' +
          stat('账户余额', money(d.balance || 0), '') +
          stat('订单总数', s.total_orders || 0, '') +
          stat('已支付订单', s.paid_orders || 0, '') +
          stat('累计消费', money(s.spent_cents || 0), '') +
        '</div>' +
        '<div class="iv-panel">' +
          '<div class="iv-panel__head"><h3>账户概览</h3></div>' +
          '<div class="iv-balance-row">' +
            '<div class="iv-ring" style="--p:' + pct + '%"><div class="iv-ring__in">' + pct + '%</div></div>' +
            '<div style="flex:1">' +
              '<div class="iv-balance-main">' + money(d.balance || 0) + '</div>' +
              '<div class="iv-muted" style="margin-bottom:16px">当前可用余额</div>' +
              '<div class="iv-consume-row">' +
                '<div><span class="dot" style="background:#50CD89"></span>已支付订单 <b>' + (s.paid_orders || 0) + '</b></div>' +
                '<div><span class="dot" style="background:#F1416C"></span>待支付订单 <b>' + (s.pending_orders || 0) + '</b></div>' +
                '<div><span class="dot" style="background:#96bbf8"></span>已交付商品 <b>' + (s.delivered_items || 0) + '</b></div>' +
              '</div>' +
            '</div>' +
            '<div><button class="iv-btn iv-btn--primary" onclick="go(\'balance\')">充值</button></div>' +
          '</div>' +
        '</div>' +
        '<div class="iv-panel">' +
          '<div class="iv-panel__head"><h3>快捷入口</h3></div>' +
          '<div class="iv-prod-grid" style="grid-template-columns:repeat(3,1fr)">' +
            quick('云主机', '查看与管理云服务器', 'cloud') +
            quick('财务魔方', '管理你的财务魔方账号', 'yulan') +
            quick('工单支持', '提交问题与查看进度', 'tickets') +
          '</div>' +
        '</div>';
    }).catch(function (e) { host.innerHTML = state(e.message); });
  }
  function stat(title, num, extra) {
    return '<div class="iv-stat"><div class="iv-stat__title">' + esc(title) + '</div><div class="iv-stat__num iv-num">' + esc(num) + '</div></div>';
  }
  function quick(title, sub, v) {
    return '<div class="iv-card" style="padding:18px;cursor:pointer" onclick="go(\'' + v + '\')"><b>' + esc(title) + '</b><p class="iv-muted" style="margin:6px 0 0;font-size:13px">' + esc(sub) + '</p><span class="iv-link" style="font-size:13px">进入 →</span></div>';
  }

  // ---------- 订单 ----------
  function renderOrders(host) {
    req('GET', '/console/orders?limit=50').then(function (d) {
      var items = d.items || [];
      if (!items.length) { host.innerHTML = state('暂无订单', '去首页选购产品吧'); return; }
      var rows = items.map(function (o) {
        return '<tr>' +
          '<td class="iv-num">' + esc(o.order_no) + '</td>' +
          '<td>' + esc((o.items && o.items[0] && o.items[0].name) || ('订单 ' + o.order_no)) + '</td>' +
          '<td class="iv-num">' + money(o.total_cents) + '</td>' +
          '<td>' + stateBadge(o.status) + '</td>' +
          '<td class="iv-muted">' + esc((o.created_at || '').replace('T', ' ').slice(0, 16)) + '</td>' +
          '<td><button class="iv-btn iv-btn--light iv-btn--sm" onclick="viewOrder(\'' + esc(o.order_no) + '\')">详情</button></td>' +
        '</tr>';
      }).join('');
      host.innerHTML =
        '<div class="iv-panel"><div class="iv-panel__head"><h3>我的订单</h3><span class="iv-muted">共 ' + items.length + ' 条</span></div>' +
        '<table class="iv-table"><thead><tr><th>订单号</th><th>商品</th><th>金额</th><th>状态</th><th>下单时间</th><th>操作</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
        '<div id="ivOrderDetail"></div>';
    }).catch(function (e) { host.innerHTML = state(e.message); });
  }
  window.viewOrder = function (no) {
    req('GET', '/console/orders/' + encodeURIComponent(no)).then(function (o) {
      var box = document.getElementById('ivOrderDetail');
      var deliver = (o.deliveries || o.delivery || o.delivery_items || []);
      var deliverHtml = Array.isArray(deliver) && deliver.length
        ? deliver.map(function (d) {
            var text = d.content || d.body || d.value || '';
            return '<div class="iv-panel" style="box-shadow:none;background:#fafbfd"><b>' + esc(d.name || d.label || '交付内容') + '</b><pre style="white-space:pre-wrap;margin:8px 0 0;font-size:13px">' + esc(text) + '</pre></div>';
          }).join('')
        : '';
      box.innerHTML =
        '<div class="iv-panel">' +
          '<div class="iv-panel__head"><h3>订单详情 · <span class="iv-num">' + esc(o.order_no) + '</span></h3>' + stateBadge(o.status) + '</div>' +
          '<p>订单金额：<b style="color:#0052d9;font-size:18px">' + money(o.total_cents) + '</b></p>' +
          '<p class="iv-muted">状态：' + esc(o.status) + '　下单时间：' + esc(o.created_at || '') + '</p>' +
          (o.status === 'pending' ? '<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap" id="ivPayOpts"></div>' : '') +
          deliverHtml +
        '</div>';
      if (o.status === 'pending') loadPayOptions(o.order_no);
      box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }).catch(function (e) { toast(e.message, true); });
  };
  function loadPayOptions(orderNo) {
    req('GET', '/payment/methods').then(function (d) {
      var box = document.getElementById('ivPayOpts'); if (!box) return;
      var methods = (d.methods || []).filter(function (m) { return m.ready; });
      if (!methods.length) { box.innerHTML = '<span class="iv-muted">暂无可用支付方式，请联系店主配置</span>'; return; }
      box.innerHTML = methods.map(function (m) {
        return '<button class="iv-btn ' + (m.id === 'balance' ? 'iv-btn--ghost' : 'iv-btn--primary') + ' iv-btn--sm" data-mid="' + esc(m.id) + '" onclick="payOrder(\'' + esc(orderNo) + '\',\'' + esc(m.id) + '\')">' + esc(m.label) + '</button>';
      }).join('');
    }).catch(function () {});
  }
  window.payOrder = function (no, mid) {
    if (mid === 'balance') {
      req('POST', '/console/orders/' + encodeURIComponent(no) + '/pay-balance', {}).then(function () {
        toast('支付成功'); go('orders');
      }).catch(function (e) { toast(e.message, true); });
      return;
    }
    // 在线支付：把方式解析为实际通道（未配置的直连自动回退易支付）
    req('GET', '/payment/methods').then(function (d) {
      var m = (d.methods || []).filter(function (x) { return x.id === mid; })[0];
      var channel = m ? m.channel : (mid.indexOf('ali') >= 0 ? 'alipay' : 'wxpay');
      return req('POST', '/console/orders/' + encodeURIComponent(no) + '/pay', { channel: channel });
    }).then(function (r) {
      if (r.gateway && r.fields) {
        var f = document.createElement('form'); f.method = r.method || 'POST'; f.action = r.gateway;
        Object.keys(r.fields).forEach(function (k) { var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = r.fields[k]; f.appendChild(i); });
        document.body.appendChild(f); f.submit();
      } else { toast('支付已提交，请稍后刷新查看订单状态'); go('orders'); }
    }).catch(function (e) { toast(e.message, true); });
  };

  // ---------- 云主机 ----------
  function renderCloud(host) {
    req('GET', '/console/cloud-vms').then(function (d) {
      var items = (d.items || d.vms || []);
      if (!items.length) { host.innerHTML = state('暂无云主机', '购买魔方云产品后会自动开通'); return; }
      var rows = items.map(function (v) {
        var acts = ['start', 'stop', 'restart'].map(function (a) {
          return '<button class="iv-btn iv-btn--ghost iv-btn--sm" onclick="vmAction(' + v.id + ',\'' + a + '\')">' + ({ start: '开机', stop: '关机', restart: '重启' }[a]) + '</button>';
        }).join(' ');
        return '<tr><td>' + esc(v.instance_name || v.name || ('#' + v.id)) + '</td><td class="iv-num">' + esc(v.ip || '-') + '</td><td>' + esc(v.os || '-') + '</td><td>' + esc(v.status || '-') + '</td><td>' + acts + '</td></tr>';
      }).join('');
      host.innerHTML = '<div class="iv-panel"><div class="iv-panel__head"><h3>我的云主机</h3></div><table class="iv-table"><thead><tr><th>实例</th><th>IP</th><th>系统</th><th>状态</th><th>操作</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    }).catch(function (e) { host.innerHTML = state(e.message); });
  }
  window.vmAction = function (id, action) {
    req('POST', '/console/cloud-vms/' + id + '/action', { action: action }).then(function () { toast('操作已提交'); go('cloud'); }).catch(function (e) { toast(e.message, true); });
  };

  // ---------- 财务魔方账号 ----------
  function renderYulan(host) {
    req('GET', '/console/yulan/accounts').then(function (d) {
      var items = d.items || [];
      if (!items.length) { host.innerHTML = state('暂无财务魔方账号', '购买魔方财务产品后会自动创建'); return; }
      var rows = items.map(function (a) {
        return '<tr><td class="iv-num">' + esc(a.account || a.username || ('#' + a.id)) + '</td><td>' + esc(a.product_name || '-') + '</td><td>' + stateBadge(a.status || 'active') + '</td><td class="iv-muted">' + esc(a.created_at || '') + '</td><td><button class="iv-btn iv-btn--light iv-btn--sm" onclick="yulanDetail(' + a.id + ')">详情</button></td></tr>';
      }).join('');
      host.innerHTML = '<div class="iv-panel"><div class="iv-panel__head"><h3>财务魔方账号</h3></div><table class="iv-table"><thead><tr><th>账号</th><th>产品</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody>' + rows + '</tbody></table></div><div id="ivYulanDetail"></div>';
    }).catch(function (e) { host.innerHTML = state(e.message); });
  }
  window.yulanDetail = function (id) {
    req('GET', '/console/yulan/accounts/' + id).then(function (a) {
      document.getElementById('ivYulanDetail').innerHTML =
        '<div class="iv-panel"><div class="iv-panel__head"><h3>账号详情</h3></div>' +
        '<div class="iv-form-row">' +
          field('账号', a.account || a.username || '') +
          field('角色', a.role_label || a.role || '') +
          field('连接地址', a.connection || a.rdp_host || a.url || '') +
          field('所属产品', a.product_name || '') +
        '</div><p class="iv-hint">请勿将账号密码分享给他人。</p></div>';
    }).catch(function (e) { toast(e.message, true); });
  };

  // ---------- 工单 ----------
  function renderTickets(host) {
    req('GET', '/console/tickets?limit=50').then(function (d) {
      var items = d.items || [];
      var rows = items.map(function (t) {
        return '<tr><td class="iv-num">#' + t.id + '</td><td>' + esc(t.title || t.subject || '-') + '</td><td>' + stateBadge(t.status) + '</td><td class="iv-muted">' + esc(t.updated_at || t.created_at || '') + '</td><td><button class="iv-btn iv-btn--light iv-btn--sm" onclick="ticketDetail(' + t.id + ')">查看</button></td></tr>';
      }).join('');
      host.innerHTML =
        '<div class="iv-panel"><div class="iv-panel__head"><h3>工单支持</h3><button class="iv-btn iv-btn--primary iv-btn--sm" onclick="openTicketForm()">提交工单</button></div>' +
        (rows ? '<table class="iv-table"><thead><tr><th>编号</th><th>标题</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead><tbody>' + rows + '</tbody></table>' : state('暂无工单', '有问题可点击右上角提交')) +
        '</div><div id="ivTicketBox"></div>';
    }).catch(function (e) { host.innerHTML = state(e.message); });
  }
  window.openTicketForm = function () {
    document.getElementById('ivTicketBox').innerHTML =
      '<div class="iv-panel"><div class="iv-panel__head"><h3>提交工单</h3></div>' +
      '<div class="iv-field"><label>标题</label><input id="tkTitle" placeholder="简要描述问题"></div>' +
      '<div class="iv-field"><label>内容</label><textarea id="tkBody" rows="5" placeholder="详细描述遇到的问题"></textarea></div>' +
      '<button class="iv-btn iv-btn--primary" onclick="submitTicket()">提交</button></div>';
  };
  window.submitTicket = function () {
    var title = document.getElementById('tkTitle').value.trim(), body = document.getElementById('tkBody').value.trim();
    if (!title || !body) { toast('请填写标题和内容', true); return; }
    req('POST', '/console/tickets', { title: title, body: body, content: body }).then(function () { toast('工单已提交'); renderTickets(document.getElementById('ivView')); }).catch(function (e) { toast(e.message, true); });
  };
  window.ticketDetail = function (id) {
    req('GET', '/console/tickets/' + id).then(function (t) {
      var replies = (t.replies || []).map(function (r) {
        return '<div style="border-left:3px solid ' + (r.role === 'admin' ? '#0052d9' : '#cbd5e1') + ';padding:10px 14px;margin-bottom:10px;background:#fafbfd"><b>' + esc(r.author || r.role) + '</b><p style="margin:4px 0 0">' + esc(r.body) + '</p></div>';
      }).join('');
      document.getElementById('ivTicketBox').innerHTML =
        '<div class="iv-panel"><div class="iv-panel__head"><h3>工单 #' + id + ' · ' + esc(t.title || '') + '</h3>' + stateBadge(t.status) + '</div>' + replies +
        '<div class="iv-field" style="margin-top:16px"><label>回复</label><textarea id="tkReply" rows="3"></textarea></div>' +
        '<button class="iv-btn iv-btn--primary iv-btn--sm" onclick="replyTicket(' + id + ')">发送回复</button></div>';
    }).catch(function (e) { toast(e.message, true); });
  };
  window.replyTicket = function (id) {
    var body = document.getElementById('tkReply').value.trim();
    if (!body) { toast('请输入回复内容', true); return; }
    req('POST', '/console/tickets/' + id + '/reply', { body: body }).then(function () { toast('已回复'); ticketDetail(id); }).catch(function (e) { toast(e.message, true); });
  };

  // ---------- 余额 ----------
  function renderBalance(host) {
    req('GET', '/console/balance/ledger?limit=50').then(function (d) {
      var items = d.items || [];
      var rows = items.map(function (l) {
        var plus = Number(l.amount) > 0;
        return '<tr><td class="iv-num">' + esc(l.type_label || l.type) + '</td><td style="color:' + (plus ? '#F1416C' : '#50CD89') + '">' + (plus ? '+' : '') + money(l.amount) + '</td><td class="iv-num">' + money(l.balance_after) + '</td><td class="iv-muted">' + esc(l.remark || '') + '</td><td class="iv-muted">' + esc((l.created_at || '').slice(0, 16)) + '</td></tr>';
      }).join('');
      host.innerHTML =
        '<div class="iv-panel"><div class="iv-panel__head"><h3>余额与充值</h3><button class="iv-btn iv-btn--primary iv-btn--sm" onclick="openRecharge()">充值</button></div>' +
        '<p>当前余额：<b id="ivCurBal" style="color:#0052d9;font-size:24px">—</b></p></div>' +
        '<div class="iv-panel"><div class="iv-panel__head"><h3>余额流水</h3></div>' +
        (rows ? '<table class="iv-table"><thead><tr><th>类型</th><th>变动</th><th>变动后余额</th><th>备注</th><th>时间</th></tr></thead><tbody>' + rows + '</tbody></table>' : state('暂无流水记录')) + '</div>';
      req('GET', '/console/overview').then(function (o) { var el = document.getElementById('ivCurBal'); if (el) el.textContent = money(o.balance); }).catch(function () {});
    }).catch(function (e) { host.innerHTML = state(e.message); });
  }
  window.openRecharge = function () {
    req('GET', '/payment/methods').then(function (d) {
      var methods = (d.methods || []).filter(function (m) { return m.ready && m.id !== 'balance'; });
      document.getElementById('ivModal').style.display = 'flex';
      document.getElementById('ivModalTitle').textContent = '账户充值';
      document.getElementById('ivModalBody').innerHTML =
        '<div class="iv-field"><label>充值金额（元）</label><input id="rcAmount" inputmode="decimal" placeholder="如 100.00"></div>' +
        '<div class="iv-field"><label>支付方式</label><div class="iv-pay-list" id="rcMethods">' +
        (methods.length ? methods.map(function (m, i) {
          return '<div class="iv-pay-opt ' + (i === 0 ? 'is-active' : '') + '" data-mid="' + esc(m.id) + '" onclick="pickPay(this)"><div class="iv-pay-opt__ic" style="background:' + (m.group === 'alipay' ? '#1677ff' : (m.group === 'wechat' ? '#07c160' : '#0052d9')) + '">' + esc(m.label.charAt(0)) + '</div><div><div class="iv-pay-opt__t">' + esc(m.label) + '</div><div class="iv-pay-opt__n">' + esc(m.note || '') + '</div></div></div>';
        }).join('') : '<span class="iv-muted">暂无可用支付方式</span>') + '</div></div>' +
        '<button class="iv-btn iv-btn--primary iv-btn--block" onclick="doRecharge()">确认充值</button>';
      window.__rcPay = methods[0] ? methods[0].id : '';
    }).catch(function (e) { toast(e.message, true); });
  };
  window.pickPay = function (el) {
    document.querySelectorAll('#rcMethods .iv-pay-opt').forEach(function (n) { n.classList.remove('is-active'); });
    el.classList.add('is-active'); window.__rcPay = el.getAttribute('data-mid');
  };
  window.doRecharge = function () {
    var raw = document.getElementById('rcAmount').value.trim();
    if (!/^\d{1,7}(\.\d{1,2})?$/.test(raw)) { toast('请输入正确的金额', true); return; }
    var cents = Math.round(parseFloat(raw) * 100);
    var mid = window.__rcPay || 'epay_wxpay';
    req('GET', '/payment/methods').then(function (d) {
      var m = (d.methods || []).filter(function (x) { return x.id === mid; })[0];
      return req('POST', '/console/balance/recharge', { amount_cents: cents, channel: m ? m.channel : 'wxpay' });
    }).then(function (r) {
      if (r.gateway && r.fields) {
        var f = document.createElement('form'); f.method = r.method || 'POST'; f.action = r.gateway;
        Object.keys(r.fields).forEach(function (k) { var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = r.fields[k]; f.appendChild(i); });
        document.body.appendChild(f); f.submit();
      } else { toast('充值已提交'); closeModal(); renderBalance(document.getElementById('ivView')); }
    }).catch(function (e) { toast(e.message, true); });
  };

  // ---------- 账号设置 ----------
  function renderProfile(host) {
    req('GET', '/console/me').then(function (d) {
      var u = d.user || {};
      host.innerHTML =
        '<div class="iv-panel"><div class="iv-panel__head"><h3>个人资料</h3></div>' +
        '<div class="iv-form-row">' + field('账号', u.username || '', true) + field('昵称', u.display_name || '') + field('邮箱', u.email || '', true) + field('余额', money(u.balance_cents)) + '</div></div>' +
        '<div class="iv-panel"><div class="iv-panel__head"><h3>修改密码</h3></div>' +
        '<div class="iv-form-row">' +
        '<div class="iv-field"><label>当前密码</label><input id="pwOld" type="password"></div>' +
        '<div class="iv-field"><label>新密码</label><input id="pwNew" type="password"></div>' +
        '</div><button class="iv-btn iv-btn--primary" onclick="changePw()">修改密码</button></div>';
    }).catch(function (e) { host.innerHTML = state(e.message); });
  }
  window.changePw = function () {
    var old = document.getElementById('pwOld').value, nw = document.getElementById('pwNew').value;
    if (!old || !nw) { toast('请填写完整', true); return; }
    req('POST', '/console/password', { old_password: old, new_password: nw }).then(function () { toast('密码已修改，请重新登录'); setTimeout(function () { location.href = 'login.html'; }, 800); }).catch(function (e) { toast(e.message, true); });
  };

  // ---------- 工具 ----------
  function field(label, value, readonly) {
    return '<div class="iv-field"><label>' + esc(label) + '</label><input value="' + esc(value) + '"' + (readonly ? ' readonly' : '') + '></div>';
  }
  function state(title, sub) {
    return '<div class="iv-state"><b>' + esc(title) + '</b>' + esc(sub || '') + '</div>';
  }

  // 站点名/品牌
  req('GET', '/site').then(function (s) {
    document.querySelectorAll('[data-site-name]').forEach(function (n) { if (s.site_name) n.textContent = s.site_name; });
    var mark = document.querySelector('[data-brand-mark]'); if (mark && s.site_name) mark.textContent = s.site_name.charAt(0);
  }).catch(function () {});
  req('GET', '/console/me').then(function (d) {
    var u = d.user || {};
    document.getElementById('ivTopName').textContent = u.display_name || u.username || '';
    var av = document.getElementById('ivAvatar'); if (av) av.textContent = (u.username || '用').charAt(0);
  }).catch(function () {});

  // 启动
  var initView = (location.hash || '').replace(/^#\/?/, '') || 'home';
  go(['home','orders','cloud','yulan','tickets','balance','profile'].indexOf(initView) >= 0 ? initView : 'home');
})();
