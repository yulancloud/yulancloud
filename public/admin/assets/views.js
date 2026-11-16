/* =============================================================
 * admin/assets/views.js —— 后台各视图
 *
 * 约定：每个视图导出 { title, render(host) }
 *  - 所有来自服务端的文本进 innerHTML 前必须过 U.esc()
 *  - 所有写操作成功后重新拉取数据，不做乐观更新（避免与真实状态不一致）
 * ============================================================= */
(function () {
  'use strict';

  var API = window.AdminAPI;
  var U = window.AdminU;

  /* ---------- 通用小工具 ---------- */
  function h(html) { return html; }

  /**
   * 商品图片路径修正：数据库里存的是相对站点根的 images/p1.webp，
   * 后台页面在 /admin/ 下，直接引用会变成 /admin/images/... → 404。
   * 这里统一补成 ../images/...（绝对 URL 与 data: 原样返回）。
   */
  function imgSrc(src) {
    var s = String(src || '');
    if (!s) return '';
    if (/^(https?:)?\/\//i.test(s) || s.indexOf('data:') === 0) return s;
    if (s.charAt(0) === '/') return '..' + s;
    if (s.indexOf('../') === 0) return s;
    return '../' + s.replace(/^\.\//, '');
  }

  function stateBox(title, sub) {
    return '<div class="state"><b>' + U.esc(title) + '</b>' + (sub ? U.esc(sub) : '') + '</div>';
  }

  function loadingBox() {
    return '<div class="state"><div class="skeleton" style="width:120px;margin:0 auto 10px"></div>' +
      '<div class="skeleton" style="width:180px;margin:0 auto"></div></div>';
  }

  function pager(total, limit, offset, onGo) {
    var page = Math.floor(offset / limit) + 1;
    var pages = Math.max(1, Math.ceil(total / limit));
    var el = document.createElement('div');
    el.className = 'pager';
    el.innerHTML =
      '<span>共 <b class="num">' + total + '</b> 条 · 第 ' + page + ' / ' + pages + ' 页</span>' +
      '<span class="spacer"></span>' +
      '<button class="btn btn--sm btn--ghost" data-pg="prev"' + (page <= 1 ? ' disabled' : '') + '>上一页</button>' +
      '<button class="btn btn--sm btn--ghost" data-pg="next"' + (page >= pages ? ' disabled' : '') + '>下一页</button>';
    el.addEventListener('click', function (e) {
      var b = e.target.closest('[data-pg]');
      if (!b) return;
      if (b.getAttribute('data-pg') === 'prev') onGo(Math.max(0, offset - limit));
      else onGo(offset + limit);
    });
    return el;
  }

  /* =============================================================
   * 1. 数据概览
   * ============================================================= */
  var dashboard = {
    title: '数据概览',
    render: function (host) {
      host.innerHTML = loadingBox();
      API.dashboard().then(function (d) {
        var pay = d.payment || {};
        var payNotice = pay.enabled
          ? '<div class="notice"><svg class="ico" viewBox="0 0 24 24"><path d="M5 12.6l4.2 4.2L19 7"/></svg>' +
            '<span>支付已启用，可用渠道：' + U.esc((pay.channels || []).map(function (c) { return c.label; }).join('、')) +
            '。未支付订单 ' + (pay.expire_minutes || 0) + ' 分钟后自动关单。</span></div>'
          : '<div class="notice notice--warn"><svg class="ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.4"/><path d="M12 8v4.6M12 15.6v.4"/></svg>' +
            '<span>支付未启用：' + U.esc(pay.reason || '未配置') +
            '。订单会停在「待付款」，可在 <code>config/.env</code> 里填写 EPAY_PID / EPAY_KEY / EPAY_GATEWAY 后把 EPAY_ENABLED 改为 true。</span></div>';

        var trendMax = 1;
        (d.trend || []).forEach(function (t) { if (t.orders > trendMax) trendMax = t.orders; });
        var bars = (d.trend || []).map(function (t) {
          var pct = Math.round((t.orders / trendMax) * 100);
          return '<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;min-width:0;">' +
            '<div style="width:100%;height:64px;display:flex;align-items:flex-end;">' +
              '<div title="' + U.esc(t.date) + '：' + t.orders + ' 单" style="width:100%;height:' + Math.max(3, pct) + '%;' +
              'background:var(--accent);opacity:' + (t.orders ? '.85' : '.18') + ';border-radius:4px 4px 2px 2px;transition:height .4s var(--ease)"></div>' +
            '</div>' +
            '<span style="font-size:9.5px;color:var(--ink-4);font-variant-numeric:tabular-nums">' + U.esc(t.date.slice(5)) + '</span>' +
          '</div>';
        }).join('');

        var recent = (d.recent_orders || []).map(function (o) {
          return '<tr data-order="' + U.esc(o.order_no) + '" style="cursor:pointer">' +
            '<td class="num">' + U.esc(o.order_no) + '</td>' +
            '<td>' + U.esc(o.buyer_name) + '</td>' +
            '<td class="num">' + U.money(o.total_cents) + '</td>' +
            '<td>' + U.orderBadge(o.status) + '</td>' +
            '<td class="tiny">' + U.ago(o.created_at) + '</td>' +
          '</tr>';
        }).join('') || '<tr><td colspan="5">' + stateBox('还没有订单') + '</td></tr>';

        var low = (d.low_stock || []).map(function (p) {
          return '<tr>' +
            '<td class="tbl__name">' + U.esc(p.name) + '</td>' +
            '<td class="num" style="color:' + (p.stock === 0 ? '#c0463f' : 'inherit') + '">' + p.stock + '</td>' +
            '<td style="text-align:right"><button class="btn btn--sm btn--ghost" data-restock="' + U.esc(p.id) + '">补货</button></td>' +
          '</tr>';
        }).join('') || '<tr><td colspan="3">' + stateBox('库存充足') + '</td></tr>';

        host.innerHTML =
          payNotice +
          '<div class="stats">' +
            '<div class="stat-card stat-card--accent"><div class="stat-card__k">今日营收</div>' +
              '<div class="stat-card__v">' + U.money(d.revenue.today_cents) + '</div>' +
              '<div class="stat-card__s">累计 ' + U.money(d.revenue.total_cents) + '</div></div>' +
            '<div class="stat-card"><div class="stat-card__k">今日订单</div>' +
              '<div class="stat-card__v">' + d.orders.today + '<small>单</small></div>' +
              '<div class="stat-card__s">累计 ' + d.orders.total + ' 单</div></div>' +
            '<div class="stat-card"><div class="stat-card__k">待付款</div>' +
              '<div class="stat-card__v">' + d.orders.pending + '<small>单</small></div>' +
              '<div class="stat-card__s">已付款 ' + d.orders.paid + ' · 已发货 ' + d.orders.shipped + '</div></div>' +
            '<div class="stat-card"><div class="stat-card__k">在售商品</div>' +
              '<div class="stat-card__v">' + d.products.active + '<small>件</small></div>' +
              '<div class="stat-card__s">下架 ' + d.products.hidden + ' · 售罄 ' + d.products.soldout + '</div></div>' +
            '<div class="stat-card"><div class="stat-card__k">库存预警</div>' +
              '<div class="stat-card__v" style="color:' + (d.products.low_stock ? '#b07a1e' : 'inherit') + '">' + d.products.low_stock + '<small>件</small></div>' +
              '<div class="stat-card__s">已售罄 ' + d.products.out_of_stock + ' 件</div></div>' +
            '<div class="stat-card"><div class="stat-card__k">评价</div>' +
              '<div class="stat-card__v">' + d.reviews.total + '<small>条</small></div>' +
              '<div class="stat-card__s">已隐藏 ' + d.reviews.hidden + ' 条</div></div>' +
          '</div>' +

          '<div class="grid-2">' +
            '<div class="panel-card">' +
              '<div class="panel-card__head"><h2>最近订单</h2><span class="spacer"></span>' +
                '<button class="btn btn--sm btn--ghost" data-goto="orders">全部订单</button></div>' +
              '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
                '<th>订单号</th><th>买家</th><th>金额</th><th>状态</th><th>时间</th>' +
              '</tr></thead><tbody>' + recent + '</tbody></table></div>' +
            '</div>' +
            '<div>' +
              '<div class="panel-card">' +
                '<div class="panel-card__head"><h2>近 7 天订单</h2></div>' +
                '<div class="panel-card__body"><div style="display:flex;gap:6px;align-items:flex-end">' + bars + '</div></div>' +
              '</div>' +
              '<div class="panel-card" style="margin-top:14px">' +
                '<div class="panel-card__head"><h2>库存预警</h2><span class="spacer"></span>' +
                  '<button class="btn btn--sm btn--ghost" data-goto="products">商品管理</button></div>' +
                '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
                  '<th>商品</th><th>库存</th><th></th>' +
                '</tr></thead><tbody>' + low + '</tbody></table></div>' +
              '</div>' +
            '</div>' +
          '</div>';

        host.addEventListener('click', function (e) {
          var tr = e.target.closest('[data-order]');
          if (tr) { window.AdminApp.openOrder(tr.getAttribute('data-order')); return; }
          var g = e.target.closest('[data-goto]');
          if (g) { window.AdminApp.go(g.getAttribute('data-goto')); return; }
          var rs = e.target.closest('[data-restock]');
          if (rs) { window.AdminApp.quickRestock(rs.getAttribute('data-restock')); }
        });
      }).catch(function (err) {
        host.innerHTML = stateBox('加载失败', err.message);
      });
    }
  };

  /* =============================================================
   * 2. 商品管理
   * ============================================================= */
  var productState = { q: '', cat: '', status: '', sort: 'sort', limit: 20, offset: 0 };

  var products = {
    title: '商品管理',
    render: function (host) {
      host.innerHTML =
        '<div class="toolbar">' +
          '<input class="field field--grow" id="p-q" type="search" placeholder="搜索商品名 / SKU / 副标题" value="' + U.esc(productState.q) + '">' +
          '<select class="field" id="p-cat"><option value="">全部分类</option></select>' +
          '<select class="field" id="p-status">' +
            '<option value="">全部状态</option>' +
            '<option value="active">在售</option>' +
            '<option value="hidden">已下架</option>' +
            '<option value="soldout">售罄</option>' +
          '</select>' +
          '<select class="field" id="p-sort">' +
            '<option value="sort">默认排序</option>' +
            '<option value="newest">最新创建</option>' +
            '<option value="price-asc">价格从低到高</option>' +
            '<option value="price-desc">价格从高到低</option>' +
            '<option value="stock-asc">库存从少到多</option>' +
            '<option value="sold-desc">销量从高到低</option>' +
          '</select>' +
          '<span class="spacer"></span>' +
          '<button class="btn btn--sm btn--primary" id="p-new">+ 新建商品</button>' +
        '</div>' +
        '<div class="panel-card"><div id="p-list">' + loadingBox() + '</div></div>';

      var qEl = document.getElementById('p-q');
      var catEl = document.getElementById('p-cat');
      var stEl = document.getElementById('p-status');
      var soEl = document.getElementById('p-sort');
      stEl.value = productState.status;
      soEl.value = productState.sort;

      // 分类下拉
      API.categories().then(function (r) {
        var opts = (r.items || []).map(function (c) {
          return '<option value="' + U.esc(c.id) + '">' + U.esc(c.label) + '（' + c.product_count + '）</option>';
        }).join('');
        catEl.innerHTML = '<option value="">全部分类</option>' + opts;
        catEl.value = productState.cat;
      }).catch(function () {});

      var reload = function () {
        var box = document.getElementById('p-list');
        if (!box) return;
        box.innerHTML = loadingBox();
        API.products({
          q: productState.q, cat: productState.cat, status: productState.status,
          sort: productState.sort, limit: productState.limit, offset: productState.offset
        }).then(function (r) {
          if (!r.items.length) {
            box.innerHTML = stateBox('没有匹配的商品', '换个关键词或筛选条件试试');
            return;
          }
          var rows = r.items.map(function (p) {
            return '<tr>' +
              '<td style="width:52px">' +
                (p.img ? '<img class="tbl__thumb" src="' + U.esc(imgSrc(p.img)) + '" alt="" loading="lazy">' : '<div class="tbl__thumb"></div>') +
              '</td>' +
              '<td><div class="tbl__name">' + U.esc(p.name) + '</div>' +
                '<div class="tbl__sub">' + U.esc(p.sku) + ' · ' + U.esc(p.id) + '</div></td>' +
              '<td class="num">' + U.money(p.price_cents) +
                (p.was_cents ? '<div class="tbl__sub" style="text-decoration:line-through">' + U.money(p.was_cents) + '</div>' : '') + '</td>' +
              '<td class="num" style="color:' + (p.stock === 0 ? '#c0463f' : (p.stock <= 3 ? '#b07a1e' : 'inherit')) + '">' + p.stock + '</td>' +
              '<td class="num">' + p.sold + '</td>' +
              '<td>' + U.productBadge(p.status) + '</td>' +
              '<td class="tbl__actions">' +
                '<button class="btn btn--sm btn--ghost" data-edit="' + U.esc(p.id) + '">编辑</button>' +
                (p.status === 'active'
                  ? '<button class="btn btn--sm btn--ghost" data-status="' + U.esc(p.id) + '" data-to="hidden">下架</button>'
                  : '<button class="btn btn--sm btn--ghost" data-status="' + U.esc(p.id) + '" data-to="active">上架</button>') +
                '<button class="btn btn--sm btn--ghost" data-del="' + U.esc(p.id) + '" data-name="' + U.esc(p.name) + '">删除</button>' +
              '</td>' +
            '</tr>';
          }).join('');

          box.innerHTML =
            '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
              '<th></th><th>商品</th><th>售价</th><th>库存</th><th>销量</th><th>状态</th><th style="text-align:right">操作</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div>';
          box.appendChild(pager(r.total, productState.limit, productState.offset, function (off) {
            productState.offset = off;
            reload();
          }));
        }).catch(function (err) {
          box.innerHTML = stateBox('加载失败', err.message);
        });
      };

      qEl.addEventListener('input', U.debounce(function () {
        productState.q = qEl.value.trim();
        productState.offset = 0;
        reload();
      }, 320));
      catEl.addEventListener('change', function () {
        productState.cat = catEl.value; productState.offset = 0; reload();
      });
      stEl.addEventListener('change', function () {
        productState.status = stEl.value; productState.offset = 0; reload();
      });
      soEl.addEventListener('change', function () {
        productState.sort = soEl.value; productState.offset = 0; reload();
      });
      document.getElementById('p-new').addEventListener('click', function () {
        window.AdminApp.openProduct(null);
      });

      host.addEventListener('click', function (e) {
        var ed = e.target.closest('[data-edit]');
        if (ed) { window.AdminApp.openProduct(ed.getAttribute('data-edit')); return; }

        var st = e.target.closest('[data-status]');
        if (st) {
          var id = st.getAttribute('data-status');
          var to = st.getAttribute('data-to');
          st.disabled = true;
          API.setProductStatus(id, to).then(function () {
            U.toast(to === 'active' ? '已上架' : '已下架');
            reload();
          }).catch(function (err) {
            U.toast(err.message, 'error');
            st.disabled = false;
          });
          return;
        }

        var del = e.target.closest('[data-del]');
        if (del) {
          var pid = del.getAttribute('data-del');
          var nm = del.getAttribute('data-name');
          if (!U.confirm('确定删除「' + nm + '」？\n\n如果该商品已有订单记录，系统会拒绝删除，请改用「下架」。')) return;
          del.disabled = true;
          API.deleteProduct(pid).then(function () {
            U.toast('已删除');
            reload();
          }).catch(function (err) {
            U.toast(err.message, 'error');
            del.disabled = false;
          });
        }
      });

      reload();
    }
  };

  /* =============================================================
   * 3. 分类管理
   * ============================================================= */
  var categories = {
    title: '分类管理',
    render: function (host) {
      host.innerHTML =
        '<div class="toolbar">' +
          '<span class="topbar__hint">分类决定前台筛选 chip 的顺序与名称。隐藏的分类不会出现在前台。</span>' +
          '<span class="spacer"></span>' +
          '<button class="btn btn--sm btn--primary" id="c-new">+ 新建分类</button>' +
        '</div>' +
        '<div class="panel-card"><div id="c-list">' + loadingBox() + '</div></div>';

      var reload = function () {
        var box = document.getElementById('c-list');
        if (!box) return;
        API.categories().then(function (r) {
          var rows = (r.items || []).map(function (c) {
            return '<tr>' +
              '<td><div class="tbl__name">' + U.esc(c.label) + '</div>' +
                '<div class="tbl__sub">' + U.esc(c.id) + '</div></td>' +
              '<td class="num">' + c.product_count + '</td>' +
              '<td class="num">' + c.active_count + '</td>' +
              '<td class="num">' + c.sort + '</td>' +
              '<td>' + (c.status === 'active'
                ? '<span class="badge badge--ok">显示</span>'
                : '<span class="badge">已隐藏</span>') + '</td>' +
              '<td class="tbl__actions">' +
                '<button class="btn btn--sm btn--ghost" data-cedit="' + U.esc(c.id) + '">编辑</button>' +
                '<button class="btn btn--sm btn--ghost" data-cdel="' + U.esc(c.id) + '" data-label="' + U.esc(c.label) + '">删除</button>' +
              '</td>' +
            '</tr>';
          }).join('') || '<tr><td colspan="6">' + stateBox('还没有分类') + '</td></tr>';

          box.innerHTML =
            '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
              '<th>分类</th><th>商品数</th><th>在售</th><th>排序</th><th>前台</th><th style="text-align:right">操作</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div>';
        }).catch(function (err) {
          box.innerHTML = stateBox('加载失败', err.message);
        });
      };

      document.getElementById('c-new').addEventListener('click', function () {
        window.AdminApp.openCategory(null);
      });

      host.addEventListener('click', function (e) {
        var ed = e.target.closest('[data-cedit]');
        if (ed) { window.AdminApp.openCategory(ed.getAttribute('data-cedit')); return; }
        var del = e.target.closest('[data-cdel]');
        if (del) {
          var id = del.getAttribute('data-cdel');
          var label = del.getAttribute('data-label');
          if (!U.confirm('确定删除分类「' + label + '」？\n\n分类下还有商品时会被拒绝。')) return;
          del.disabled = true;
          API.deleteCategory(id).then(function () {
            U.toast('已删除');
            reload();
          }).catch(function (err) {
            U.toast(err.message, 'error');
            del.disabled = false;
          });
        }
      });

      reload();
      window.__reloadCategories = reload;
    }
  };

  /* =============================================================
   * 4. 订单管理
   * ============================================================= */
  var orderState = { q: '', status: '', channel: '', sort: 'newest', limit: 20, offset: 0 };

  var orders = {
    title: '订单管理',
    render: function (host) {
      host.innerHTML =
        '<div class="toolbar">' +
          '<input class="field field--grow" id="o-q" type="search" placeholder="订单号 / 买家姓名 / 完整手机号" value="' + U.esc(orderState.q) + '">' +
          '<select class="field" id="o-status">' +
            '<option value="">全部状态</option>' +
            '<option value="pending">待付款</option>' +
            '<option value="paid">已付款</option>' +
            '<option value="shipped">已发货</option>' +
            '<option value="done">已完成</option>' +
            '<option value="cancelled">已取消</option>' +
          '</select>' +
          '<select class="field" id="o-channel">' +
            '<option value="">全部渠道</option>' +
            '<option value="alipay">支付宝</option>' +
            '<option value="wxpay">微信支付</option>' +
            '<option value="manual">人工确认</option>' +
          '</select>' +
          '<select class="field" id="o-sort">' +
            '<option value="newest">最新优先</option>' +
            '<option value="oldest">最早优先</option>' +
            '<option value="amount">金额从高到低</option>' +
          '</select>' +
          '<span class="spacer"></span>' +
          '<button class="btn btn--sm btn--ghost" id="o-close">关闭超时未付订单</button>' +
        '</div>' +
        '<div class="panel-card"><div id="o-list">' + loadingBox() + '</div></div>';

      var qEl = document.getElementById('o-q');
      var stEl = document.getElementById('o-status');
      var chEl = document.getElementById('o-channel');
      var soEl = document.getElementById('o-sort');
      stEl.value = orderState.status;
      chEl.value = orderState.channel;
      soEl.value = orderState.sort;

      var reload = function () {
        var box = document.getElementById('o-list');
        if (!box) return;
        box.innerHTML = loadingBox();
        API.orders({
          q: orderState.q, status: orderState.status, channel: orderState.channel,
          sort: orderState.sort, limit: orderState.limit, offset: orderState.offset
        }).then(function (r) {
          if (!r.items.length) {
            box.innerHTML = stateBox('没有匹配的订单', '换个筛选条件试试');
            return;
          }
          var rows = r.items.map(function (o) {
            return '<tr data-order="' + U.esc(o.order_no) + '" style="cursor:pointer">' +
              '<td><div class="tbl__name num">' + U.esc(o.order_no) + '</div>' +
                '<div class="tbl__sub">' + U.esc(o.buyer_name) + ' · ' + U.esc(o.buyer_phone) + '</div></td>' +
              '<td class="num">' + o.item_qty + ' 件</td>' +
              '<td class="num">' + U.money(o.total_cents) + '</td>' +
              '<td>' + U.orderBadge(o.status) + '</td>' +
              '<td class="tiny">' + U.esc(U.channelLabel(o.pay_channel)) + '</td>' +
              '<td class="tiny">' + U.time(o.created_at) + '</td>' +
              '<td class="tbl__actions"><button class="btn btn--sm btn--ghost" data-open="' + U.esc(o.order_no) + '">详情</button></td>' +
            '</tr>';
          }).join('');

          box.innerHTML =
            '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
              '<th>订单</th><th>数量</th><th>金额</th><th>状态</th><th>渠道</th><th>下单时间</th><th></th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div>';
          box.appendChild(pager(r.total, orderState.limit, orderState.offset, function (off) {
            orderState.offset = off;
            reload();
          }));
        }).catch(function (err) {
          box.innerHTML = stateBox('加载失败', err.message);
        });
      };

      qEl.addEventListener('input', U.debounce(function () {
        orderState.q = qEl.value.trim(); orderState.offset = 0; reload();
      }, 320));
      stEl.addEventListener('change', function () { orderState.status = stEl.value; orderState.offset = 0; reload(); });
      chEl.addEventListener('change', function () { orderState.channel = chEl.value; orderState.offset = 0; reload(); });
      soEl.addEventListener('change', function () { orderState.sort = soEl.value; orderState.offset = 0; reload(); });

      document.getElementById('o-close').addEventListener('click', function (e) {
        var b = e.currentTarget;
        if (!U.confirm('将关闭所有超过支付时限的待付款订单，并归还库存。继续？')) return;
        b.disabled = true;
        API.closeExpired().then(function (r) {
          U.toast(r.closed > 0 ? '已关闭 ' + r.closed + ' 个订单' : '没有需要关闭的订单');
          reload();
        }).catch(function (err) {
          U.toast(err.message, 'error');
        }).then(function () { b.disabled = false; });
      });

      host.addEventListener('click', function (e) {
        var tr = e.target.closest('[data-order]');
        if (tr) window.AdminApp.openOrder(tr.getAttribute('data-order'));
      });

      reload();
      window.__reloadOrders = reload;
    }
  };

  /* =============================================================
   * 5. 评价管理
   * ============================================================= */
  var reviewState = { q: '', status: '', product_id: '', limit: 20, offset: 0 };

  var reviews = {
    title: '评价管理',
    render: function (host) {
      host.innerHTML =
        '<div class="toolbar">' +
          '<input class="field field--grow" id="r-q" type="search" placeholder="搜索评价内容 / 评价人" value="' + U.esc(reviewState.q) + '">' +
          '<select class="field" id="r-status">' +
            '<option value="">全部状态</option>' +
            '<option value="visible">显示中</option>' +
            '<option value="hidden">已隐藏</option>' +
          '</select>' +
          '<span class="spacer"></span>' +
          '<button class="btn btn--sm btn--primary" id="r-new">+ 新增评价</button>' +
        '</div>' +
        '<div class="panel-card"><div id="r-list">' + loadingBox() + '</div></div>';

      var qEl = document.getElementById('r-q');
      var stEl = document.getElementById('r-status');
      stEl.value = reviewState.status;

      var reload = function () {
        var box = document.getElementById('r-list');
        if (!box) return;
        box.innerHTML = loadingBox();
        API.reviews({
          q: reviewState.q, status: reviewState.status,
          limit: reviewState.limit, offset: reviewState.offset
        }).then(function (r) {
          if (!r.items.length) {
            box.innerHTML = stateBox('没有匹配的评价');
            return;
          }
          var rows = r.items.map(function (v) {
            var stars = '';
            for (var i = 0; i < 5; i++) {
              stars += '<svg class="ico" viewBox="0 0 24 24" style="' + (i < v.stars ? '' : 'opacity:.22') + '"><path d="M12 4.6l2.3 4.7 5.2.8-3.8 3.7.9 5.2-4.6-2.4-4.6 2.4.9-5.2L4.5 10l5.2-.8z"/></svg>';
            }
            return '<tr>' +
              '<td><div class="tbl__name">' + U.esc(v.author) + '</div>' +
                '<div class="tbl__sub">' + U.esc(v.product_name || v.product_id) + '</div></td>' +
              '<td><span class="stars">' + stars + '</span></td>' +
              '<td style="max-width:340px"><div style="font-size:12.5px;line-height:1.7">' + U.esc(v.body) + '</div>' +
                (v.reply ? '<div class="tbl__sub">店主回复：' + U.esc(v.reply) + '</div>' : '') + '</td>' +
              '<td class="num">' + v.likes + '</td>' +
              '<td>' + (v.status === 'visible'
                ? '<span class="badge badge--ok">显示</span>'
                : '<span class="badge">已隐藏</span>') + '</td>' +
              '<td class="tbl__actions">' +
                '<button class="btn btn--sm btn--ghost" data-redit="' + v.id + '">编辑</button>' +
                '<button class="btn btn--sm btn--ghost" data-rstatus="' + v.id + '" data-to="' + (v.status === 'visible' ? 'hidden' : 'visible') + '">' +
                  (v.status === 'visible' ? '隐藏' : '显示') + '</button>' +
                '<button class="btn btn--sm btn--ghost" data-rdel="' + v.id + '">删除</button>' +
              '</td>' +
            '</tr>';
          }).join('');

          box.innerHTML =
            '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
              '<th>评价人 / 商品</th><th>评分</th><th>内容</th><th>赞</th><th>状态</th><th style="text-align:right">操作</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div>';
          box.appendChild(pager(r.total, reviewState.limit, reviewState.offset, function (off) {
            reviewState.offset = off;
            reload();
          }));
        }).catch(function (err) {
          box.innerHTML = stateBox('加载失败', err.message);
        });
      };

      qEl.addEventListener('input', U.debounce(function () {
        reviewState.q = qEl.value.trim(); reviewState.offset = 0; reload();
      }, 320));
      stEl.addEventListener('change', function () { reviewState.status = stEl.value; reviewState.offset = 0; reload(); });
      document.getElementById('r-new').addEventListener('click', function () {
        window.AdminApp.openReview(null);
      });

      host.addEventListener('click', function (e) {
        var ed = e.target.closest('[data-redit]');
        if (ed) { window.AdminApp.openReview(parseInt(ed.getAttribute('data-redit'), 10)); return; }

        var st = e.target.closest('[data-rstatus]');
        if (st) {
          var id = parseInt(st.getAttribute('data-rstatus'), 10);
          var to = st.getAttribute('data-to');
          st.disabled = true;
          API.setReviewStatus(id, to).then(function () {
            U.toast(to === 'visible' ? '已显示' : '已隐藏');
            reload();
          }).catch(function (err) {
            U.toast(err.message, 'error');
            st.disabled = false;
          });
          return;
        }

        var del = e.target.closest('[data-rdel]');
        if (del) {
          var rid = parseInt(del.getAttribute('data-rdel'), 10);
          if (!U.confirm('确定删除这条评价？删除后不可恢复。')) return;
          del.disabled = true;
          API.deleteReview(rid).then(function () {
            U.toast('已删除');
            reload();
          }).catch(function (err) {
            U.toast(err.message, 'error');
            del.disabled = false;
          });
        }
      });

      reload();
      window.__reloadReviews = reload;
    }
  };

  /* =============================================================
   * 6. 账号与设置
   * ============================================================= */
  var settings = {
    title: '账号与设置',
    render: function (host) {
      var me = window.AdminApp.me || {};
      host.innerHTML =
        '<div class="grid-2">' +
          '<div class="panel-card">' +
            '<div class="panel-card__head"><h2>修改密码</h2></div>' +
            '<div class="panel-card__body">' +
              '<div class="form-grid" style="grid-template-columns:1fr">' +
                '<div class="fld"><label for="pw-old">当前密码</label>' +
                  '<input class="field" id="pw-old" type="password" autocomplete="current-password" maxlength="200"></div>' +
                '<div class="fld"><label for="pw-new">新密码</label>' +
                  '<input class="field" id="pw-new" type="password" autocomplete="new-password" maxlength="200">' +
                  '<div class="hint">至少 10 个字符，需同时包含字母和数字。</div></div>' +
                '<div class="fld"><label for="pw-new2">确认新密码</label>' +
                  '<input class="field" id="pw-new2" type="password" autocomplete="new-password" maxlength="200"></div>' +
              '</div>' +
              '<div style="margin-top:16px"><button class="btn btn--primary" id="pw-save">更新密码</button></div>' +
              '<div class="notice" style="margin-top:16px;margin-bottom:0">' +
                '<svg class="ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.4"/><path d="M12 8v4.6M12 15.6v.4"/></svg>' +
                '<span>改密后，该账号在其他设备上的登录会全部失效（当前会话保留）。</span></div>' +
            '</div>' +
          '</div>' +
          '<div>' +
            '<div class="panel-card">' +
              '<div class="panel-card__head"><h2>当前账号</h2></div>' +
              '<div class="panel-card__body">' +
                '<dl class="kv">' +
                  '<dt>账号</dt><dd>' + U.esc(me.username || '—') + '</dd>' +
                  '<dt>显示名</dt><dd>' + U.esc(me.display_name || '—') + '</dd>' +
                  '<dt>角色</dt><dd>' + (me.role === 'owner' ? '店主（全部权限）' : '店员（不含日志）') + '</dd>' +
                '</dl>' +
              '</div>' +
            '</div>' +
            '<div class="panel-card" style="margin-top:14px">' +
              '<div class="panel-card__head"><h2>支付配置</h2></div>' +
              '<div class="panel-card__body" id="pay-info">' + loadingBox() + '</div>' +
            '</div>' +
          '</div>' +
        '</div>';

      document.getElementById('pw-save').addEventListener('click', function (e) {
        var b = e.currentTarget;
        var o = document.getElementById('pw-old').value;
        var n = document.getElementById('pw-new').value;
        var n2 = document.getElementById('pw-new2').value;
        if (!o) { U.toast('请输入当前密码', 'error'); return; }
        if (n !== n2) { U.toast('两次输入的新密码不一致', 'error'); return; }
        if (n.length < 10) { U.toast('新密码至少 10 个字符', 'error'); return; }
        b.disabled = true;
        API.changePassword(o, n).then(function () {
          U.toast('密码已更新');
          document.getElementById('pw-old').value = '';
          document.getElementById('pw-new').value = '';
          document.getElementById('pw-new2').value = '';
        }).catch(function (err) {
          U.toast(err.message, 'error');
        }).then(function () { b.disabled = false; });
      });

      host.addEventListener('click', function (e) {
        if (e.target && e.target.id === 'go-epay') window.AdminApp.go('epay');
      });

      API.me().then(function (r) {
        var p = r.payment || {};
        var box = document.getElementById('pay-info');
        if (!box) return;
        box.innerHTML =
          '<dl class="kv">' +
            '<dt>状态</dt><dd>' + (p.enabled
              ? '<span class="badge badge--ok">已启用</span>'
              : '<span class="badge badge--warn">未启用</span>') + '</dd>' +
            '<dt>渠道</dt><dd>' + U.esc((p.channels || []).map(function (c) { return c.label; }).join('、') || '—') + '</dd>' +
            '<dt>关单时限</dt><dd>' + (p.expire_minutes || 0) + ' 分钟</dd>' +
            (p.enabled ? '' : '<dt>原因</dt><dd>' + U.esc(p.reason || '—') + '</dd>') +
          '</dl>' +
          '<div class="notice" style="margin-top:14px;margin-bottom:0">' +
            '<span>商户号、密钥、网关地址等请在左侧菜单「易支付配置」里直接填写，保存后立即生效，无需改 .env 或重启服务。留空的项会沿用服务器 config/.env 的配置。</span>' +
            '<button class="btn btn--ghost" id="go-epay" type="button" style="margin-top:10px">前往易支付配置</button></div>';
      }).catch(function () {});
    }
  };

  /* =============================================================
   * 6b. 站点设置（前台标题/副标题/版权等文案）
   * ============================================================= */
  var SITE_FIELDS = [
    { key: 'site_name',        label: '站点名称',     hint: '顶栏品牌名，如「拾物集」', max: 40, type: 'text' },
    { key: 'site_name_en',     label: '英文名',       hint: '品牌副标，如「SHIWU」', max: 40, type: 'text' },
    { key: 'hero_eyebrow',     label: '首页眉标',     hint: '主标题上方的小字，如「EST. 2019 · 上海静安」', max: 60, type: 'text' },
    { key: 'hero_title',       label: '首页主标题',   hint: '首页流式大字标题', max: 80, type: 'text' },
    { key: 'hero_subtitle',    label: '首页副标题',   hint: '主标题下方的导语', max: 300, type: 'textarea' },
    { key: 'footer_note',      label: '页脚说明',     hint: '页脚左侧的店铺说明', max: 300, type: 'textarea' },
    { key: 'footer_copyright', label: '版权文案',     hint: '如「© 2026 拾物集 SHIWU」；留空则前台隐藏', max: 160, type: 'text' },
    { key: 'footer_icp',       label: '备案号',       hint: '如「沪 ICP 备 0000000 号」；留空则前台整行隐藏', max: 80, type: 'text' },
    { key: 'support_email',    label: '客服邮箱',     hint: '页脚「复制客服邮箱」用', max: 120, type: 'text' },
    { key: 'meta_title',       label: '浏览器标题',   hint: '标签页显示的标题', max: 120, type: 'text' },
    { key: 'meta_description', label: '站点描述',     hint: '搜索引擎摘要（meta description）', max: 200, type: 'textarea' },
    // v5 新增：后端 SettingsService::DEFAULTS 里已有，旧版后台表单漏了这 7 个键，
    // 导致店主在后台根本改不了这些文案（保存时也不会带上）。
    { key: 'site_intro',          label: '站点介绍',     hint: '关于我们，可多行', max: 2000, type: 'textarea' },
    { key: 'home_notice',         label: '首页公告',     hint: '留空则不显示', max: 300, type: 'textarea' },
    { key: 'home_products_title', label: '商品区标题',   hint: '首页「在售」区的大标题（打字动画文案），后台改完前台即时生效', max: 120, type: 'text' },
    { key: 'footer_contact',      label: '页脚联系方式', hint: '留空则不显示', max: 300, type: 'textarea' },
    { key: 'console_welcome',     label: '用户中心欢迎语', hint: '如「欢迎回来」', max: 60, type: 'text' },
    { key: 'support_wechat',      label: '客服微信',     hint: '留空则不显示', max: 60, type: 'text' },
    { key: 'support_qq',          label: '客服 QQ',      hint: '留空则不显示', max: 60, type: 'text' }
  ];

  /* 易支付：独立菜单页，保存后由 PaymentService::config() 立即读取。
   * 商户密钥「只写不读」：GET 不回显，输入框留空即保持原密钥不变。 */
  var EPAY_FIELDS = [
    { key: 'epay_enabled', group: 'epay', label: '在线支付', hint: '留空项沿用 config/.env；也可在此强制开/关', max: 8, type: 'select',
      options: [{ value: '', label: '跟随环境变量（.env）' }, { value: '1', label: '启用' }, { value: '0', label: '关闭' }] },
    { key: 'epay_pid', group: 'epay', label: '商户 ID（PID）', hint: '易支付后台的商户 PID', max: 64, type: 'text' },
    { key: 'epay_key', group: 'epay', label: '商户密钥（KEY）', hint: '仅用于服务端签名验签；出于安全不回显，留空表示不修改', max: 128, type: 'password' },
    { key: 'epay_gateway', group: 'epay', label: '支付网关地址', hint: '必须填到 /submit.php，如 https://pay.xxx.com/submit.php；只填域名会自动补全；必须 https', max: 300, type: 'text' },
    { key: 'epay_channels', group: 'epay', label: '可用支付渠道', hint: '逗号分隔，如 wxpay,alipay；留空则默认两个都开', max: 80, type: 'text' },
    { key: 'epay_notify_url', group: 'epay', label: '异步通知地址', hint: '留空则按站点基址自动生成', max: 300, type: 'text' },
    { key: 'epay_return_url', group: 'epay', label: '同步返回地址', hint: '留空则按站点基址自动生成', max: 300, type: 'text' },
    { key: 'epay_expire_minutes', group: 'epay', label: '未支付自动关单（分钟）', hint: '留空则沿用默认 120，范围 1–1440', max: 6, type: 'text' }
  ];

  /* 运费：独立菜单页。单位「元」，留空 = 沿用 config/config.php 的默认（¥12 / 满 ¥299 包邮）。
   * 服务端 SettingsService::shippingRule() 换算成分后参与下单计算，前台 /api/site 同步下发。 */
  var SHIP_FIELDS = [
    { key: 'ship_fee',            label: '每单运费（元）', hint: '填 0 即全场包邮；留空沿用服务器默认 ¥12', max: 12, type: 'text' },
    { key: 'free_ship_threshold', label: '包邮门槛（元）', hint: '满多少元包邮；填 0 表示不设门槛（每单都收运费）；留空沿用默认 ¥299', max: 12, type: 'text' }
  ];

  /* 虚拟商品自动发货：后台独立菜单，逐个列出虚拟商品并编辑各自发货内容。
   * 每个商品的发货内容存于其自身配置（见 AdminDelivery.mount），互不影响。 */

  /* 设置类表单的公共渲染 / 填充 / 保存。
   * 后端 SettingsService::save() 是「部分更新」：只写传入的键，
   * 因此站点文案页与易支付页各自提交自己的字段，互不覆盖（尤其不会误清密钥）。 */
  function settingsFieldHtml(f) {
    var input;
    if (f.type === 'select') {
      input = '<select class="field" id="st-' + f.key + '">' + f.options.map(function (o) {
        return '<option value="' + U.esc(o.value) + '">' + U.esc(o.label) + '</option>';
      }).join('') + '</select>';
    } else if (f.type === 'textarea') {
      input = '<textarea class="field" id="st-' + f.key + '" rows="' + (f.rows || 3) + '" maxlength="' + f.max + '"></textarea>';
    } else {
      input = '<input class="field" id="st-' + f.key + '" type="' + (f.type === 'password' ? 'password' : 'text') +
              '" maxlength="' + f.max + '"' + (f.type === 'password' ? ' autocomplete="new-password"' : '') + '>';
    }
    return '<div class="fld">' +
             '<label for="st-' + f.key + '">' + U.esc(f.label) + '</label>' +
             input +
             '<div class="hint">' + U.esc(f.hint) + (f.type === 'select' ? '' : ' · 最多 ' + f.max + ' 字') + '</div>' +
           '</div>';
  }

  function fillSettingsFields(fields, s) {
    fields.forEach(function (f) {
      var el = document.getElementById('st-' + f.key);
      if (el) el.value = (s && typeof s[f.key] === 'string') ? s[f.key] : '';
    });
  }

  function saveSettingsFields(btn, fields, okMsg, onSaved) {
    var payload = {};
    fields.forEach(function (f) {
      var el = document.getElementById('st-' + f.key);
      if (el) payload[f.key] = el.value;
    });
    btn.disabled = true;
    API.saveSettings(payload).then(function (r) {
      var s = r.settings || {};
      fillSettingsFields(fields, s);
      U.toast(okMsg);
      // 运费页等需要展示「生效后的计算值」的视图可挂回调，避免二次请求
      if (typeof onSaved === 'function') onSaved(s);
    }).catch(function (err) {
      U.toast(err.message, 'error');
    }).then(function () { btn.disabled = false; });
  }

  /* -------------------------------------------------------------
   * 6b-1. 站点设置：只管文案
   * ------------------------------------------------------------- */
  var siteSettings = {
    title: '站点设置',
    render: function (host) {
      host.innerHTML =
        '<div class="notice"><span>站点文案保存在数据库，保存后立即生效，不需要改 HTML 或重启服务。支付参数请到左侧菜单「易支付配置」单独设置。</span></div>' +
        '<div class="panel-card">' +
          '<div class="panel-card__head"><h2>站点文案</h2>' +
            '<div class="panel-card__actions">' +
              '<button class="btn btn--ghost" id="st-reset" type="button">恢复默认</button>' +
              '<button class="btn btn--primary js-save-site" type="button">保存</button>' +
            '</div>' +
          '</div>' +
          '<div class="panel-card__body">' +
            '<div class="form-grid" style="grid-template-columns:1fr 1fr">' + SITE_FIELDS.map(settingsFieldHtml).join('') + '</div>' +
          '</div>' +
        '</div>';

      API.settings().then(function (r) {
        fillSettingsFields(SITE_FIELDS, r.settings || {});
      }).catch(function (err) { U.toast(err.message, 'error'); });

      Array.prototype.forEach.call(host.querySelectorAll('.js-save-site'), function (b) {
        b.addEventListener('click', function () { saveSettingsFields(b, SITE_FIELDS, '站点文案已保存，立即生效'); });
      });

      document.getElementById('st-reset').addEventListener('click', function (e) {
        if (!window.confirm('确定恢复为默认文案？当前文案修改会丢失（易支付配置不受影响）。')) return;
        var btn = e.currentTarget;
        btn.disabled = true;
        API.resetSettings().then(function (r) {
          fillSettingsFields(SITE_FIELDS, r.settings || {});
          U.toast('已恢复默认文案');
        }).catch(function (err) {
          U.toast(err.message, 'error');
        }).then(function () { btn.disabled = false; });
      });
    }
  };

  /* -------------------------------------------------------------
   * 6b-2. 易支付配置：独立菜单，只管支付参数
   * ------------------------------------------------------------- */
  var epaySettings = {
    title: '易支付配置',
    render: function (host) {
      function statusText(s) {
        return s && s.epay_key_set
          ? '商户密钥：已配置（出于安全不回显；留空即保持原密钥不变）。如需停用支付，把「在线支付」设为「关闭」即可。'
          : '商户密钥：尚未配置。填入 KEY 并保存即可启用在线支付。';
      }

      host.innerHTML =
        '<div class="notice"><span>支付参数保存在数据库，保存后立即生效，无需改 config/.env 或重启服务。留空的项沿用服务器 config/.env 的配置；商户密钥出于安全只写不读（不回显，留空表示不修改）。<b>网关地址必须是收银台提交地址（/submit.php）</b>，只填站点域名会跳到易支付官网首页而不出现付款界面；只填域名时系统会自动补全 /submit.php。</span></div>' +
        '<div class="panel-card">' +
          '<div class="panel-card__head"><h2>易支付参数</h2>' +
            '<div class="panel-card__actions">' +
              '<button class="btn btn--primary js-save-epay" type="button">保存支付配置</button>' +
            '</div>' +
          '</div>' +
          '<div class="panel-card__body">' +
            '<div class="notice" style="margin-bottom:14px;display:flex;align-items:center;gap:12px;justify-content:space-between">' +
              '<span id="epay-status">正在读取支付配置…</span>' +
              '<button class="btn btn--ghost" id="epay-clear-key" type="button" style="padding:5px 10px;font-size:11px;height:auto">清除密钥</button>' +
            '</div>' +
            '<div class="form-grid" style="grid-template-columns:1fr 1fr">' + EPAY_FIELDS.map(settingsFieldHtml).join('') + '</div>' +
          '</div>' +
        '</div>' +
        '<div class="panel-card" style="margin-top:16px">' +
          '<div class="panel-card__head"><h2>当前支付状态</h2></div>' +
          '<div class="panel-card__body" id="epay-live">' + loadingBox() + '</div>' +
        '</div>';

      function fill(s) {
        fillSettingsFields(EPAY_FIELDS, s);
        var box = document.getElementById('epay-status');
        if (box) box.textContent = statusText(s);
      }

      API.settings().then(function (r) { fill(r.settings || {}); })
        .catch(function (err) { U.toast(err.message, 'error'); });

      // 实际生效状态（复用 /api/admin/me 的 payment 段）
      API.me().then(function (r) {
        var p = r.payment || {};
        var box = document.getElementById('epay-live');
        if (!box) return;
        box.innerHTML =
          '<dl class="kv">' +
            '<dt>状态</dt><dd>' + (p.enabled
              ? '<span class="badge badge--ok">已启用</span>'
              : '<span class="badge badge--warn">未启用</span>') + '</dd>' +
            '<dt>渠道</dt><dd>' + U.esc((p.channels || []).map(function (c) { return c.label; }).join('、') || '—') + '</dd>' +
            '<dt>关单时限</dt><dd>' + (p.expire_minutes || 0) + ' 分钟</dd>' +
            (p.gateway ? '<dt>收银台提交地址</dt><dd class="num" style="word-break:break-all">' + U.esc(p.gateway) + '</dd>' : '') +
            (p.notify_url ? '<dt>异步通知地址</dt><dd class="num" style="word-break:break-all">' + U.esc(p.notify_url) + '</dd>' : '') +
            (p.enabled ? '' : '<dt>原因</dt><dd>' + U.esc(p.reason || '—') + '</dd>') +
          '</dl>' +
          '<div class="hint" style="margin-top:10px">提交地址应指向 /submit.php；异步通知地址必须是外网可访问的本站地址，否则付款成功后订单不会更新。保存配置后刷新本页即可看到最新状态。</div>';
      }).catch(function () {});

      Array.prototype.forEach.call(host.querySelectorAll('.js-save-epay'), function (b) {
        b.addEventListener('click', function () { saveSettingsFields(b, EPAY_FIELDS, '支付配置已保存，立即生效'); });
      });

      document.getElementById('epay-clear-key').addEventListener('click', function (e) {
        if (!window.confirm('确定清除已保存的商户密钥？清除后在线支付将无法验签，直到重新填入 KEY。')) return;
        var btn = e.currentTarget;
        btn.disabled = true;
        API.saveSettings({ epay_key: '__CLEAR__' }).then(function (r) {
          fill(r.settings || {});
          U.toast('商户密钥已清除');
        }).catch(function (err) {
          U.toast(err.message, 'error');
        }).then(function () { btn.disabled = false; });
      });
    }
  };

  /* -------------------------------------------------------------
   * 6b-3. 运费设置：独立菜单，只管运费与包邮门槛
   * ------------------------------------------------------------- */
  var shippingSettings = {
    title: '运费设置',
    render: function (host) {
      function effectiveHtml(s) {
        var r = s && s.shipping;
        if (!r) return stateBox('读取失败', '请刷新页面重试');
        var fee = (Number(r.ship_fee_cents) || 0) / 100;
        var free = (Number(r.free_ship_threshold_cents) || 0) / 100;
        return '<dl class="kv">' +
                 '<dt>每单运费</dt><dd>' + (fee === 0
                   ? '<span class="badge badge--ok">全场包邮（¥0）</span>' : '¥' + fee) + '</dd>' +
                 '<dt>包邮门槛</dt><dd>' + (free > 0 ? '满 ¥' + free + ' 包邮' : '不设门槛（每单收运费）') + '</dd>' +
                 '<dt>虚拟商品</dt><dd>订单内全部为虚拟商品时不收运费</dd>' +
               '</dl>' +
               '<div class="hint" style="margin-top:10px">保存后立即生效；已提交的历史订单金额不变。留空的两项沿用服务器 config/config.php 的默认值。</div>';
      }

      host.innerHTML =
        '<div class="notice"><span>运费由服务端按下单时的商品重新计算，前台购物袋与订单金额都以此为准，改这里立刻生效，无需改代码或重启。全虚拟商品订单无需物流，恒不收运费，不受以下两项影响。</span></div>' +
        '<div class="panel-card">' +
          '<div class="panel-card__head"><h2>运费规则</h2>' +
            '<div class="panel-card__actions">' +
              '<button class="btn btn--primary js-save-ship" type="button">保存运费设置</button>' +
            '</div>' +
          '</div>' +
          '<div class="panel-card__body">' +
            '<div class="form-grid" style="grid-template-columns:1fr 1fr">' + SHIP_FIELDS.map(settingsFieldHtml).join('') + '</div>' +
          '</div>' +
        '</div>' +
        '<div class="panel-card" style="margin-top:16px">' +
          '<div class="panel-card__head"><h2>当前生效规则</h2></div>' +
          '<div class="panel-card__body" id="ship-effective">' + loadingBox() + '</div>' +
        '</div>';

      function fillEffective(s) {
        var box = document.getElementById('ship-effective');
        if (box) box.innerHTML = effectiveHtml(s);
      }

      API.settings().then(function (r) {
        var s = r.settings || {};
        fillSettingsFields(SHIP_FIELDS, s);
        fillEffective(s);
      }).catch(function (err) { U.toast(err.message, 'error'); });

      Array.prototype.forEach.call(host.querySelectorAll('.js-save-ship'), function (b) {
        b.addEventListener('click', function () {
          saveSettingsFields(b, SHIP_FIELDS, '运费设置已保存，立即生效', fillEffective);
        });
      });
    }
  };

  /* -------------------------------------------------------------
   * 6b-4. 虚拟商品发货：独立菜单，逐个虚拟商品设置各自的自动发货内容
   * ------------------------------------------------------------- */
  var virtualDeliverySettings = {
    title: '虚拟商品发货',
    render: function (host) {
      host.innerHTML =
        '<div class="notice"><span>这里逐个设置每个虚拟商品支付成功后的自动发货内容。点开某个商品即可编辑它的专属内容，彼此互不影响（如：产品 A 发卡密、产品 B 发网盘链接）。</span></div>' +
        '<div class="panel-card">' +
          '<div class="panel-card__head"><h2>虚拟商品清单</h2>' +
            '<div class="panel-card__actions"><span class="muted" id="vd-count"></span></div>' +
          '</div>' +
          '<div class="panel-card__body" id="vd-list">' + loadingBox() + '</div>' +
        '</div>';

      var listEl = document.getElementById('vd-list');

      API.products({ type: 'virtual', limit: 200, offset: 0 }).then(function (r) {
        var items = (r && r.items) || [];
        if (!items.length) {
          listEl.innerHTML = stateBox('暂无虚拟商品',
            '请先在「商品管理」里把商品类型设为「虚拟商品（支付后自动发货）」，这里才会出现。');
          return;
        }
        document.getElementById('vd-count').textContent = '共 ' + items.length + ' 个';
        listEl.innerHTML = items.map(function (p) {
          return '<div class="vd-item" data-id="' + U.esc(p.id) + '" style="border-bottom:1px solid var(--line,#eee);padding:12px 0">' +
                   '<div class="vd-item__head" style="display:flex;align-items:center;justify-content:space-between;gap:12px">' +
                     '<div class="vd-item__name" style="font-weight:600">' + U.esc(p.name) +
                       (p.sku ? ' <span class="muted">(' + U.esc(p.sku) + ')</span>' : '') + '</div>' +
                     '<button class="btn btn--sm btn--ghost vd-toggle" type="button">设置发货内容</button>' +
                   '</div>' +
                   '<div class="vd-item__body" hidden style="margin-top:12px"></div>' +
                 '</div>';
        }).join('');

        listEl.querySelectorAll('.vd-item').forEach(function (row) {
          var id = row.getAttribute('data-id');
          var body = row.querySelector('.vd-item__body');
          row.querySelector('.vd-toggle').addEventListener('click', function () {
            var opening = body.hidden;
            // 同一时间只展开一个，避免多个编辑器共用相同 id 的表单元素
            listEl.querySelectorAll('.vd-item__body').forEach(function (b) {
              b.hidden = true; b.innerHTML = '';
            });
            if (opening && window.AdminDelivery) {
              body.hidden = false;
              window.AdminDelivery.mount(body, id);
            }
          });
        });
      }).catch(function (err) {
        listEl.innerHTML = stateBox('加载失败', err.message);
      });
    }
  };

  /* =============================================================
   * 7. 操作日志
   * ============================================================= */
  var auditState = { limit: 50, offset: 0 };

  var audit = {
    title: '操作日志',
    render: function (host) {
      host.innerHTML =
        '<div class="notice"><span>记录后台的登录、商品/分类/订单/评价变更。只保留操作类型与目标，不含密码与密钥。</span></div>' +
        '<div class="panel-card"><div id="a-list">' + loadingBox() + '</div></div>';

      var reload = function () {
        var box = document.getElementById('a-list');
        if (!box) return;
        API.audit({ limit: auditState.limit, offset: auditState.offset }).then(function (r) {
          var rows = (r.items || []).map(function (a) {
            return '<tr>' +
              '<td class="tiny">' + U.time(a.created_at) + '</td>' +
              '<td>' + U.esc(a.username || '—') + '</td>' +
              '<td><span class="badge">' + U.esc(a.action) + '</span></td>' +
              '<td class="num tiny">' + U.esc(a.target || '—') + '</td>' +
              '<td class="tiny">' + U.esc(a.detail || '') + '</td>' +
            '</tr>';
          }).join('') || '<tr><td colspan="5">' + stateBox('还没有记录') + '</td></tr>';

          box.innerHTML =
            '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
              '<th>时间</th><th>操作人</th><th>动作</th><th>对象</th><th>详情</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div>';
          box.appendChild(pager(r.total, auditState.limit, auditState.offset, function (off) {
            auditState.offset = off;
            reload();
          }));
        }).catch(function (err) {
          box.innerHTML = stateBox('加载失败', err.message);
        });
      };
      reload();
    }
  };

  /* 欲蓝财务魔方：服务器连接配置（口令只写不读，复用站点设置白名单） */
  var YULAN_FIELDS = [
    { key: 'yulan_server_ip',   label: 'Windows 服务器 IP / 域名', hint: '运行「欲蓝财务魔方 APP」的服务器地址', max: 64, type: 'text' },
    { key: 'yulan_server_port', label: 'APP 监听端口', hint: '默认 8321', max: 6, type: 'text' },
    { key: 'yulan_server_user', label: '连接鉴权账号', hint: 'APP 侧校验用', max: 64, type: 'text' },
    { key: 'yulan_server_pass', label: '连接鉴权口令', hint: '出于安全不回显；留空表示不修改；至少 6 位', max: 128, type: 'password' },
    { key: 'yulan_rdp_host',    label: '交付用连接地址', hint: '留空则用上方 IP；展示给用户的远程连接地址', max: 128, type: 'text' },
    { key: 'yulan_mock', label: '模拟模式', hint: '开启后不真正连接 APP，命令写入 data/yulan_mock_queue.jsonl，便于测试', max: 1, type: 'select',
      options: [{ value: '0', label: '关闭（真实连接 APP）' }, { value: '1', label: '开启（写入队列文件，仅测试）' }] }
  ];

  /* 欲蓝财务魔方后台：服务器配置 + 逐商品账号限购 / IP 限制 */
  var yulan = {
    title: '欲蓝财务魔方',
    render: function (host) {
      host.innerHTML =
        '<div class="notice"><span>欲蓝财务魔方：此处是「全局默认」服务器连接信息。每个欲蓝商品也可以在「商品管理 → 编辑 → 发货配置」里单独指向自己的 Windows 服务器（商品留空则用这里的全局默认）。下单后网站向运行在 Windows 服务器上的「欲蓝财务魔方 APP」下达建号命令，自动创建 Windows 账号（普通或管理员，由 APP 决定）。密码仅在下单时用于建号，平台不存储、不下发。</span></div>' +
        '<div class="panel-card" style="margin-bottom:16px">' +
          '<div class="panel-card__head"><h2>欲蓝服务器配置（全局默认）</h2>' +
            '<div class="panel-card__actions"><button class="btn btn--primary js-save-yulan" type="button">保存服务器配置</button></div>' +
          '</div>' +
          '<div class="panel-card__body">' +
            '<div class="form-grid" style="grid-template-columns:1fr 1fr">' + YULAN_FIELDS.map(settingsFieldHtml).join('') + '</div>' +
            '<div style="margin-top:10px"><button class="btn btn--ghost" id="yulan-clear-pass" type="button">清除口令</button></div>' +
          '</div>' +
        '</div>' +
        '<div class="panel-card">' +
          '<div class="panel-card__head"><h2>欲蓝商品清单</h2><span class="muted" id="yulan-count"></span></div>' +
          '<div class="panel-card__body" id="yulan-list">' + loadingBox() + '</div>' +
        '</div>';

      API.settings().then(function (r) {
        fillSettingsFields(YULAN_FIELDS, r.settings || {});
        var box = document.getElementById('yulan-clear-pass');
        if (box) box.style.display = (r.settings && r.settings.yulan_server_pass_set) ? '' : 'none';
      }).catch(function (err) { U.toast(err.message, 'error'); });

      Array.prototype.forEach.call(host.querySelectorAll('.js-save-yulan'), function (b) {
        b.addEventListener('click', function () { saveSettingsFields(b, YULAN_FIELDS, '欲蓝服务器配置已保存'); });
      });

      document.getElementById('yulan-clear-pass').addEventListener('click', function (e) {
        if (!window.confirm('确定清除已保存的连接口令？清除后网站将无法通过口令连接 APP，直到重新填入。')) return;
        var btn = e.currentTarget; btn.disabled = true;
        API.saveSettings({ yulan_server_pass: '__CLEAR__' }).then(function (r) {
          fillSettingsFields(YULAN_FIELDS, r.settings || {});
          btn.style.display = 'none';
          U.toast('连接口令已清除');
        }).catch(function (err) { U.toast(err.message, 'error'); }).then(function () { btn.disabled = false; });
      });

      var listEl = document.getElementById('yulan-list');
      API.products({ type: 'yulan', limit: 200, offset: 0 }).then(function (r) {
        var items = (r && r.items) || [];
        var cnt = document.getElementById('yulan-count');
        if (cnt) cnt.textContent = '共 ' + items.length + ' 个';
        if (!items.length) {
          listEl.innerHTML = stateBox('暂无欲蓝商品', '请在「商品管理」里把商品类型设为「欲蓝财务魔方」，这里才会出现。');
          return;
        }
        listEl.innerHTML = items.map(function (p) {
          return '<div class="vd-item" data-id="' + U.esc(p.id) + '" style="border-bottom:1px solid var(--line,#eee);padding:12px 0">' +
            '<div class="vd-item__head" style="display:flex;align-items:center;justify-content:space-between;gap:12px">' +
              '<div class="vd-item__name" style="font-weight:600">' + U.esc(p.name) + (p.sku ? ' <span class="muted">(' + U.esc(p.sku) + ')</span>' : '') + '</div>' +
              '<button class="btn btn--sm btn--ghost yulan-toggle" type="button">设置账号限购 / IP 限制</button>' +
            '</div>' +
            '<div class="vd-item__body" hidden style="margin-top:12px"></div>' +
          '</div>';
        }).join('');
        listEl.querySelectorAll('.vd-item').forEach(function (row) {
          var id = row.getAttribute('data-id');
          var body = row.querySelector('.vd-item__body');
          row.querySelector('.yulan-toggle').addEventListener('click', function () {
            var opening = body.hidden;
            listEl.querySelectorAll('.vd-item__body').forEach(function (b) { b.hidden = true; b.innerHTML = ''; });
            if (opening && window.AdminDelivery) { body.hidden = false; window.AdminDelivery.mount(body, id); }
          });
        });
      }).catch(function (err) { listEl.innerHTML = stateBox('加载失败', err.message); });
    }
  };

  /* =============================================================
   * 6c. v1.2 主题与插件（后台上传 zip 安装，零侵入）
   *
   * 与 v1 教程的区别：不用改 style.css、不用改亮暗两套变量、不用 bump ?v=、
   * 不用手工复制文件、升级覆盖 Release 也不会丢。传一个 zip 就装好。
   * ============================================================= */
  var extensions = {
    title: '主题与插件',
    render: function (host) {
      host.innerHTML =
        '<div class="notice">上传一个 <b>.zip</b> 包即可安装主题或插件，装完刷新前台就生效。' +
          '主题只覆盖配色变量，插件只往 <code>public/plugins/</code> 放静态页面，' +
          '<b>不改任何核心文件、不建表、不用跑迁移</b>，卸载即可完全还原。</div>' +

        '<div class="panel-card">' +
          '<div class="panel-card__head"><b>上传扩展包</b></div>' +
          '<div class="panel-card__body">' +
            '<div class="fld">' +
              '<label for="ext-file">选择 .zip 包</label>' +
              '<input class="field" id="ext-file" type="file" accept=".zip,application/zip">' +
              '<div class="hint" id="ext-limits">单个包上限 2 MB，只放行静态资源。</div>' +
            '</div>' +
            '<div class="toolbar">' +
              '<button class="btn btn--sm btn--primary" id="ext-upload">上传并安装</button>' +
              '<span class="topbar__hint" id="ext-msg"></span>' +
            '</div>' +
          '</div>' +
        '</div>' +

        '<div class="sec-title">已装主题</div>' +
        '<div class="panel-card"><div id="ext-themes">' + loadingBox() + '</div></div>' +

        '<div class="sec-title">已装插件</div>' +
        '<div class="panel-card"><div id="ext-plugins">' + loadingBox() + '</div></div>' +

        '<div class="notice notice--warn" style="margin-top:14px">' +
          '<b>包里要有什么？</b><br>' +
          '· <code>plugin.json</code>：写明 <code>id</code>（小写英文+连字符）、<code>name</code>、' +
          '<code>version</code>、<code>type</code>（<code>theme</code> 或 <code>plugin</code>）<br>' +
          '· 主题包再放一个 <code>theme.css</code>，只写 <code>:root{}</code> 和 ' +
          '<code>:root[data-theme=dark]{}</code> 的变量覆盖（写 <code>[data-theme=dark]{}</code> 也行，服务端会自动提升选择器）<br>' +
          '· 插件包再放一个 <code>files/</code> 目录，里面是要装到 <code>/plugins/&lt;id&gt;/</code> 的静态页面<br>' +
          '· 服务端会拒绝含 PHP / 脚本 / 配置文件、路径穿越、超大或加密的包；' +
          '同一 id 再上传视为覆盖更新。' +
        '</div>' +
        '<div class="notice" style="margin-top:10px">' +
          '想找现成的包？去 <a href="https://yulancloud.us.ci/market/" target="_blank" rel="noopener">插件市场</a>；' +
          '怎么自己做包看文档站「v2 主题与插件制作教程」。' +
        '</div>';

      var fileInput = document.getElementById('ext-file');
      var upBtn     = document.getElementById('ext-upload');
      var msgEl     = document.getElementById('ext-msg');
      var themesBox = document.getElementById('ext-themes');
      var pluginBox = document.getElementById('ext-plugins');

      function esc(s) { return U.esc(s == null ? '' : String(s)); }

      function rowHtml(x, type) {
        var active = !!x.active;
        var state = type === 'theme'
          ? (active ? '<span class="badge badge--ok">当前主题</span>'
                    : (x.enabled ? '<span class="badge badge--accent">已启用</span>'
                                 : '<span class="badge">已停用</span>'))
          : (x.enabled ? '<span class="badge badge--ok">已启用</span>'
                       : '<span class="badge">已停用</span>');
        var acts = '';
        if (type === 'theme' && !active && x.enabled) {
          acts += '<button class="btn btn--sm btn--ghost" data-ext-act="activate" data-ext-type="theme"' +
                  ' data-ext-id="' + esc(x.id) + '" data-ext-name="' + esc(x.name) + '">设为当前</button> ';
        }
        if (type === 'plugin' && x.enabled && x.deployed) {
          acts += '<button class="btn btn--sm btn--ghost" data-ext-act="visit"' +
                  ' data-ext-url="' + esc(x.url + (x.entry || 'index.html')) + '">访问</button> ';
        }
        acts += '<button class="btn btn--sm btn--ghost" data-ext-act="toggle" data-ext-type="' + type + '"' +
                ' data-ext-id="' + esc(x.id) + '" data-ext-name="' + esc(x.name) + '"' +
                ' data-ext-enabled="' + (x.enabled ? '0' : '1') + '">' +
                (x.enabled ? '停用' : '启用') + '</button> ';
        acts += '<button class="btn btn--sm btn--ghost" data-ext-act="remove" data-ext-type="' + type + '"' +
                ' data-ext-id="' + esc(x.id) + '" data-ext-name="' + esc(x.name) + '">卸载</button>';

        return '<tr>' +
          '<td><div class="tbl__name">' + esc(x.name) + '</div>' +
            '<div class="tbl__sub">' + esc(x.id) + (x.version ? ' · v' + esc(x.version) : '') + '</div>' +
            (x.description ? '<div class="tbl__sub">' + esc(x.description) + '</div>' : '') + '</td>' +
          '<td>' + esc(x.author || '—') + '</td>' +
          '<td>' + state + '</td>' +
          '<td class="tbl__actions">' + acts + '</td>' +
        '</tr>';
      }

      function tableHtml(items, type, emptyText) {
        var rows = (items || []).map(function (x) { return rowHtml(x, type); }).join('');
        if (!rows) { return stateBox(emptyText); }
        return '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
          '<th>扩展</th><th>作者</th><th>状态</th><th style="text-align:right">操作</th>' +
          '</tr></thead><tbody>' + rows + '</tbody></table></div>';
      }

      function reload() {
        return API.extensions().then(function (d) {
          themesBox.innerHTML = tableHtml(d.themes, 'theme', '还没有主题，上传一个主题包试试');
          pluginBox.innerHTML = tableHtml(d.plugins, 'plugin', '还没有插件，上传一个插件包试试');
          if (d.limits) {
            document.getElementById('ext-limits').textContent =
              '单个包上限 ' + d.limits.max_zip_mb + ' MB · 最多 ' + d.limits.max_entries +
              ' 个文件 · 单文件 ' + d.limits.max_file_kb + ' KB · 只放行静态资源';
          }
        }).catch(function (err) {
          themesBox.innerHTML = stateBox('加载失败', err.message);
          pluginBox.innerHTML = stateBox('加载失败', err.message);
        });
      }

      upBtn.addEventListener('click', function () {
        var f = fileInput.files && fileInput.files[0];
        if (!f) { U.toast('请先选择 .zip 包', 'error'); return; }
        if (!/\.zip$/i.test(f.name)) { U.toast('只接受 .zip 压缩包', 'error'); return; }
        var fd = new FormData();
        fd.append('package', f);
        upBtn.disabled = true;
        msgEl.textContent = '正在上传并安装…';
        API.uploadExtension(fd).then(function (r) {
          var kind = r.type === 'theme' ? '主题' : '插件';
          U.toast(kind + '「' + r.name + '」已安装');
          msgEl.textContent = '已安装 ' + kind + ' ' + r.name + ' v' + (r.version || '') +
                              (r.updated ? '（覆盖更新）' : '') +
                              (r.type === 'theme' ? '，已设为当前主题' : '');
          fileInput.value = '';
          upBtn.disabled = false;
          reload();
        }).catch(function (err) {
          U.toast(err.message, 'error');
          msgEl.textContent = '安装失败：' + err.message;
          upBtn.disabled = false;
        });
      });

      // 用 onclick 覆盖而不是 addEventListener：同一个 #view 节点会被多个视图复用，
      // 累加监听器会导致切换菜单回来后点一次触发多次。
      host.onclick = function (e) {
        var b = e.target.closest ? e.target.closest('[data-ext-act]') : null;
        if (!b) return;
        var act  = b.getAttribute('data-ext-act');
        var type = b.getAttribute('data-ext-type');
        var id   = b.getAttribute('data-ext-id');
        var name = b.getAttribute('data-ext-name') || id;

        if (act === 'visit') {
          window.open('../' + b.getAttribute('data-ext-url'), '_blank', 'noopener,noreferrer');
          return;
        }
        if (act === 'remove') {
          if (!U.confirm('确定卸载「' + name + '」？\n\n会删除这个扩展的所有文件，之后可以重新上传安装。')) return;
          b.disabled = true;
          API.deleteExtension(type, id).then(function () {
            U.toast('已卸载 ' + name);
            msgEl.textContent = '已卸载 ' + name;
            reload();
          }).catch(function (err) {
            U.toast(err.message, 'error');
            b.disabled = false;
          });
          return;
        }
        if (act === 'activate') {
          b.disabled = true;
          API.activateTheme(id).then(function () {
            U.toast('已切换当前主题为 ' + name + '，刷新前台即可看到');
            msgEl.textContent = '当前主题：' + name;
            reload();
          }).catch(function (err) {
            U.toast(err.message, 'error');
            b.disabled = false;
          });
          return;
        }
        if (act === 'toggle') {
          var next = b.getAttribute('data-ext-enabled') === '1';
          b.disabled = true;
          API.toggleExtension(type, id, next).then(function () {
            U.toast((next ? '已启用 ' : '已停用 ') + name);
            msgEl.textContent = (next ? '已启用 ' : '已停用 ') + name;
            reload();
          }).catch(function (err) {
            U.toast(err.message, 'error');
            b.disabled = false;
          });
        }
      };

      reload();
    }
  };

  window.AdminViews = {
    dashboard: dashboard,
    products: products,
    categories: categories,
    orders: orders,
    // v5 用户管理视图定义在 views-v5.js（本文件之后加载），
    // 因此这里必须惰性委托，不能在注册时取值（那时还是 undefined）。
    users: {
      title: '用户管理',
      render: function (host) {
        var v5 = window.AdminViewsV5 && window.AdminViewsV5.users;
        if (v5 && typeof v5.render === 'function') {
          v5.render(host);
          return;
        }
        host.innerHTML = stateBox('用户管理模块未加载', '请刷新页面重试');
      }
    },
    reviews: reviews,
    site: siteSettings,
    shipping: shippingSettings,
    virtual: virtualDeliverySettings,
    yulan: yulan,
    epay: epaySettings,
    extensions: extensions,
    settings: settings,
    audit: audit
  };
})();
