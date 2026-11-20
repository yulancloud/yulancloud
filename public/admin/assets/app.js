/* =============================================================
 * admin/assets/app.js —— 后台主控
 *
 * 职责：
 *   1. 启动时校验登录态，未登录跳 login.html
 *   2. 视图路由（hash 驱动，可刷新/可分享）
 *   3. 抽屉：商品编辑、分类编辑、订单详情、评价编辑
 *   4. 退出登录
 * ============================================================= */
(function () {
  'use strict';

  var API = window.AdminAPI;
  var U = window.AdminU;
  var V = window.AdminViews;

  var App = {
    me: null,
    view: 'dashboard'
  };

  /* ---------- 抽屉 ---------- */
  var drawer = document.getElementById('drawer');
  var mask = document.getElementById('drawer-mask');
  var dTitle = document.getElementById('drawer-title');
  var dBody = document.getElementById('drawer-body');
  var dFoot = document.getElementById('drawer-foot');

  function openDrawer(title, bodyHtml, footHtml) {
    dTitle.textContent = title;
    dBody.innerHTML = bodyHtml;
    dFoot.innerHTML = footHtml || '';
    drawer.classList.add('is-on');
    mask.classList.add('is-on');
    document.body.style.overflow = 'hidden';
    dBody.scrollTop = 0;
    // 焦点移入抽屉容器本身（tabindex="-1"），而不是第一个输入框：
    // 聚焦输入框会把滚动容器滚到它那里，导致抽屉一打开就看不到顶部的订单信息。
    // 另外抽屉有 340ms 的滑入过渡，过渡期间浏览器会为「保持焦点可见」再滚一次，
    // 所以等过渡结束后再归零一次滚动位置。
    setTimeout(function () {
      try { drawer.focus({ preventScroll: true }); } catch (e) { drawer.focus(); }
      dBody.scrollTop = 0;
    }, 60);
    setTimeout(function () { dBody.scrollTop = 0; }, 380);
  }

  function closeDrawer() {
    drawer.classList.remove('is-on');
    mask.classList.remove('is-on');
    document.body.style.overflow = '';
    dBody.innerHTML = '';
    dFoot.innerHTML = '';
  }

  document.getElementById('drawer-close').addEventListener('click', closeDrawer);
  mask.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && drawer.classList.contains('is-on')) closeDrawer();
  });

  /* ---------- 视图切换 ---------- */
  // 视图白名单改为「以实际注册的视图对象为准」，不再依赖硬编码数组：
  // 以前每加一个菜单都要同步改这里，漏改就会出现「点了菜单却被打回数据概览」。
  function go(name) {
    if (!V || !V[name] || typeof V[name].render !== 'function') name = 'dashboard';
    if (name === 'audit' && App.me && App.me.role !== 'owner') {
      U.toast('操作日志仅店主可见', 'error');
      name = 'dashboard';
    }
    App.view = name;
    if (location.hash !== '#' + name) {
      history.replaceState(null, '', '#' + name);
    }
    var v = V[name];
    document.getElementById('view-title').textContent = v.title;
    document.title = v.title + ' — 欲蓝财务后台';

    // 侧栏高亮
    document.querySelectorAll('.side__link[data-view]').forEach(function (b) {
      if (b.getAttribute('data-view') === name) b.setAttribute('aria-current', 'page');
      else b.removeAttribute('aria-current');
    });

    var host = document.getElementById('view');
    host.innerHTML = '';
    // 每次切换重建容器，避免旧视图的事件监听叠加
    var fresh = document.createElement('div');
    host.appendChild(fresh);
    v.render(fresh);
  }

  App.go = go;
  window.AdminApp = App;   // 供插件调用 go / openDrawer 等

  // v1.2.2 事件委托：插件注入的侧栏按钮（后加载）也能响应点击
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.side__link[data-view]');
    if (b) { go(b.getAttribute('data-view')); }
  });

  window.addEventListener('hashchange', function () {
    var name = (location.hash || '').replace('#', '');
    if (name && name !== App.view) go(name);
  });

  /* =============================================================
   * 商品编辑抽屉
   * ============================================================= */
  App.openProduct = function (id) {
    var isNew = !id;
    var cats = [];

    var load = isNew
      ? Promise.resolve(null)
      : API.product(id);

    Promise.all([API.categories(), load]).then(function (res) {
      cats = res[0].items || [];
      var p = res[1];

      var catOpts = cats.map(function (c) {
        var sel = p && p.cat_id === c.id ? ' selected' : '';
        return '<option value="' + U.esc(c.id) + '"' + sel + '>' + U.esc(c.label) + '</option>';
      }).join('');

      var colors = (p && p.colors) || [];
      var sizes = (p && p.sizes) || [];
      var specs = (p && p.specs) || [];
      var care = (p && p.care) || [];

      var body =
        '<div class="form-grid">' +
          '<div class="fld"><label for="f-id">商品编号<span class="req">*</span></label>' +
            '<input class="field" id="f-id" value="' + U.esc(p ? p.id : '') + '"' + (isNew ? '' : ' readonly') +
            ' placeholder="如 clay-cup-01" maxlength="32">' +
            '<div class="hint">小写字母、数字、连字符。创建后不可修改（前台链接依赖它）。</div></div>' +
          '<div class="fld"><label for="f-sku">SKU<span class="req">*</span></label>' +
            '<input class="field" id="f-sku" value="' + U.esc(p ? p.sku : '') + '" placeholder="如 SHW-CUP-01" maxlength="40"></div>' +

          '<div class="fld full"><label for="f-name">商品名称<span class="req">*</span></label>' +
            '<input class="field" id="f-name" value="' + U.esc(p ? p.name : '') + '" maxlength="80"></div>' +
          '<div class="fld full"><label for="f-sub">副标题</label>' +
            '<input class="field" id="f-sub" value="' + U.esc(p ? p.sub : '') + '" maxlength="120" placeholder="一句话说明，如「手作粗陶 · 单只」"></div>' +

          '<div class="fld"><label for="f-cat">分类<span class="req">*</span></label>' +
            '<select class="field" id="f-cat">' + catOpts + '</select></div>' +
          '<div class="fld"><label for="f-type">商品类型<span class="req">*</span></label>' +
            '<select class="field" id="f-type">' +
              '<option value="physical"' + (!p || p.type === 'physical' ? ' selected' : '') + '>实体商品</option>' +
              '<option value="virtual"' + (p && p.type === 'virtual' ? ' selected' : '') + '>虚拟商品</option>' +
              '<option value="yulan"' + (p && p.type === 'yulan' ? ' selected' : '') + '>欲蓝财务魔方</option>' +
            '</select>' +
            '<div class="hint">虚拟商品自动发货；欲蓝财务魔方下单即创建 Windows 账号（在「发货配置」里设限购与服务器）。</div></div>' +
          '<div class="fld"><label for="f-status">状态</label>' +
            '<select class="field" id="f-status">' +
              '<option value="active"' + (p && p.status === 'active' ? ' selected' : '') + '>在售</option>' +
              '<option value="hidden"' + (p && p.status === 'hidden' ? ' selected' : '') + '>已下架</option>' +
              '<option value="soldout"' + (p && p.status === 'soldout' ? ' selected' : '') + '>售罄</option>' +
            '</select></div>' +

          '<div class="fld"><label for="f-price">售价（元）<span class="req">*</span></label>' +
            '<input class="field" id="f-price" inputmode="decimal" value="' + (p ? U.yuan(p.price_cents) : '') + '" placeholder="188.00"></div>' +
          '<div class="fld"><label for="f-was">划线价（元）</label>' +
            '<input class="field" id="f-was" inputmode="decimal" value="' + (p && p.was_cents ? U.yuan(p.was_cents) : '') + '" placeholder="留空表示不显示">' +
            '<div class="hint">必须 ≥ 售价，否则保存会被拒绝。</div></div>' +

          '<div class="fld"><label for="f-stock">库存<span class="req">*</span></label>' +
            '<input class="field" id="f-stock" inputmode="numeric" value="' + (p ? p.stock : 0) + '"></div>' +
          '<div class="fld"><label for="f-sort">排序权重</label>' +
            '<input class="field" id="f-sort" inputmode="numeric" value="' + (p ? p.sort : 0) + '">' +
            '<div class="hint">数字越小越靠前。</div></div>' +

          '<div class="fld"><label for="f-badge">角标</label>' +
            '<input class="field" id="f-badge" value="' + U.esc(p ? p.badge : '') + '" maxlength="12" placeholder="如「新品」"></div>' +
          '<div class="fld"><label for="f-rating">评分（0-5）</label>' +
            '<input class="field" id="f-rating" inputmode="decimal" value="' + (p ? p.rating : 0) + '"></div>' +

          '<div class="fld full"><label for="f-img">主图地址</label>' +
            '<input class="field" id="f-img" value="' + U.esc(p ? p.img : '') + '" maxlength="300" placeholder="图片地址，如 images/photo.webp 或 https://…">' +
            '<div class="hint">只接受站内相对路径（images/… 或 assets/…）或 https 链接。</div></div>' +

          '<div class="fld full"><label for="f-desc">商品描述</label>' +
            '<textarea class="field" id="f-desc" maxlength="4000">' + U.esc(p ? p.description : '') + '</textarea></div>' +
          '<div class="fld full"><label for="f-story">品牌故事</label>' +
            '<textarea class="field" id="f-story" maxlength="4000">' + U.esc(p ? p.story : '') + '</textarea></div>' +

          '<div class="fld full"><label for="f-flaws">商品缺点 / 已知不足</label>' +
            '<textarea class="field" id="f-flaws" maxlength="2000" placeholder="每行一条，如「手工拉坯，同批次高度会有 3–5mm 差异」">' + U.esc(p ? (p.flaws || '') : '') + '</textarea>' +
            '<div class="hint">每行一条，前台详情页「已知不足」栏逐条展示；留空则整栏自动隐藏。</div></div>' +
        '</div>' +

        '<div class="sec-title">颜色</div>' +
        '<div class="subrows" id="rows-colors"></div>' +
        '<button class="btn btn--sm btn--ghost" type="button" id="add-color" style="margin-top:8px">+ 添加颜色</button>' +

        '<div class="sec-title">规格（含加价）</div>' +
        '<div class="subrows" id="rows-sizes"></div>' +
        '<button class="btn btn--sm btn--ghost" type="button" id="add-size" style="margin-top:8px">+ 添加规格</button>' +

        '<div class="sec-title">参数</div>' +
        '<div class="subrows" id="rows-specs"></div>' +
        '<button class="btn btn--sm btn--ghost" type="button" id="add-spec" style="margin-top:8px">+ 添加参数</button>' +

        '<div class="sec-title">保养说明</div>' +
        '<div class="subrows" id="rows-care"></div>' +
        '<button class="btn btn--sm btn--ghost" type="button" id="add-care" style="margin-top:8px">+ 添加一条</button>' +

        (isNew ? '' :
          '<div class="sec-title">发货配置</div>' +
          '<div id="p-delivery"><div class="state">加载中…</div></div>');

      var foot =
        '<button class="btn btn--ghost" type="button" id="d-cancel">取消</button>' +
        '<span class="spacer"></span>' +
        '<button class="btn btn--primary" type="button" id="d-save">' + (isNew ? '创建商品' : '保存修改') + '</button>';

      openDrawer(isNew ? '新建商品' : '编辑商品', body, foot);

      // v5：已有商品可配置发货模板（新建商品需先保存拿到 id）
      if (!isNew && window.AdminDelivery) {
        var dvHost = document.getElementById('p-delivery');
        if (dvHost) window.AdminDelivery.mount(dvHost, p.id);
      }

      /* ---- 子表行渲染 ---- */
      function colorRow(c) {
        var d = document.createElement('div');
        d.className = 'subrow';
        d.innerHTML =
          '<input class="field" data-k="name" value="' + U.esc(c.name || '') + '" placeholder="颜色名，如「月白」" maxlength="30">' +
          '<input type="color" data-k="hex" value="' + U.esc(c.hex || '#cccccc') + '">' +
          '<button class="subrow__del" type="button" aria-label="删除">' +
            '<svg class="ico" viewBox="0 0 24 24"><path d="M7 7l10 10M17 7L7 17"/></svg></button>';
        return d;
      }
      function sizeRow(s) {
        var d = document.createElement('div');
        d.className = 'subrow';
        d.innerHTML =
          '<input class="field" data-k="name" value="' + U.esc(s.name || '') + '" placeholder="规格名，如「大号」" maxlength="30">' +
          '<input class="field" data-k="extra" inputmode="decimal" value="' + (s.extra_cents ? U.yuan(s.extra_cents) : '0.00') + '" placeholder="加价（元）">' +
          '<button class="subrow__del" type="button" aria-label="删除">' +
            '<svg class="ico" viewBox="0 0 24 24"><path d="M7 7l10 10M17 7L7 17"/></svg></button>';
        return d;
      }
      function specRow(s) {
        var d = document.createElement('div');
        d.className = 'subrow subrow--spec';
        d.innerHTML =
          '<input class="field" data-k="k" value="' + U.esc(s.k || '') + '" placeholder="参数名，如「容量」" maxlength="30">' +
          '<input class="field" data-k="v" value="' + U.esc(s.v || '') + '" placeholder="参数值，如「320ml」" maxlength="200">' +
          '<button class="subrow__del" type="button" aria-label="删除">' +
            '<svg class="ico" viewBox="0 0 24 24"><path d="M7 7l10 10M17 7L7 17"/></svg></button>';
        return d;
      }
      function careRow(c) {
        var d = document.createElement('div');
        d.className = 'subrow subrow--care';
        d.innerHTML =
          '<input class="field" data-k="body" value="' + U.esc(typeof c === 'string' ? c : (c.body || '')) + '" placeholder="如「可用洗碗机清洗」" maxlength="300">' +
          '<button class="subrow__del" type="button" aria-label="删除">' +
            '<svg class="ico" viewBox="0 0 24 24"><path d="M7 7l10 10M17 7L7 17"/></svg></button>';
        return d;
      }

      var rowsColors = document.getElementById('rows-colors');
      var rowsSizes = document.getElementById('rows-sizes');
      var rowsSpecs = document.getElementById('rows-specs');
      var rowsCare = document.getElementById('rows-care');

      colors.forEach(function (c) { rowsColors.appendChild(colorRow(c)); });
      sizes.forEach(function (s) { rowsSizes.appendChild(sizeRow(s)); });
      specs.forEach(function (s) { rowsSpecs.appendChild(specRow(s)); });
      care.forEach(function (c) { rowsCare.appendChild(careRow(c)); });

      document.getElementById('add-color').addEventListener('click', function () {
        rowsColors.appendChild(colorRow({}));
      });
      document.getElementById('add-size').addEventListener('click', function () {
        rowsSizes.appendChild(sizeRow({}));
      });
      document.getElementById('add-spec').addEventListener('click', function () {
        rowsSpecs.appendChild(specRow({}));
      });
      document.getElementById('add-care').addEventListener('click', function () {
        rowsCare.appendChild(careRow(''));
      });

      // 子表删除（事件委托）
      dBody.addEventListener('click', function (e) {
        var del = e.target.closest('.subrow__del');
        if (del) {
          var row = del.closest('.subrow');
          if (row) row.remove();
        }
      });

      /* ---- 收集并保存 ---- */
      function collectRows(container, mapper) {
        var out = [];
        container.querySelectorAll('.subrow').forEach(function (row) {
          var item = mapper(row);
          if (item !== null) out.push(item);
        });
        return out;
      }

      document.getElementById('d-cancel').addEventListener('click', closeDrawer);

      document.getElementById('d-save').addEventListener('click', function (e) {
        var btn = e.currentTarget;

        var priceCents = U.toCents(document.getElementById('f-price').value);
        if (priceCents === null) { U.toast('售价格式不正确（最多两位小数）', 'error'); return; }
        var wasRaw = document.getElementById('f-was').value.trim();
        var wasCents = wasRaw === '' ? null : U.toCents(wasRaw);
        if (wasRaw !== '' && wasCents === null) { U.toast('划线价格式不正确', 'error'); return; }

        var payload = {
          id: document.getElementById('f-id').value.trim(),
          sku: document.getElementById('f-sku').value.trim(),
          name: document.getElementById('f-name').value.trim(),
          sub: document.getElementById('f-sub').value.trim(),
          cat_id: document.getElementById('f-cat').value,
          type: document.getElementById('f-type').value,
          status: document.getElementById('f-status').value,
          price_cents: priceCents,
          was_cents: wasCents,
          stock: parseInt(document.getElementById('f-stock').value, 10) || 0,
          sort: parseInt(document.getElementById('f-sort').value, 10) || 0,
          badge: document.getElementById('f-badge').value.trim(),
          rating: parseFloat(document.getElementById('f-rating').value) || 0,
          img: document.getElementById('f-img').value.trim(),
          description: document.getElementById('f-desc').value,
          story: document.getElementById('f-story').value,
          flaws: document.getElementById('f-flaws').value,
          colors: collectRows(rowsColors, function (row) {
            var n = row.querySelector('[data-k="name"]').value.trim();
            if (!n) return null;
            return { name: n, hex: row.querySelector('[data-k="hex"]').value };
          }),
          sizes: collectRows(rowsSizes, function (row) {
            var n = row.querySelector('[data-k="name"]').value.trim();
            if (!n) return null;
            var ex = U.toCents(row.querySelector('[data-k="extra"]').value || '0');
            return { name: n, extra_cents: ex === null ? 0 : ex };
          }),
          specs: collectRows(rowsSpecs, function (row) {
            var k = row.querySelector('[data-k="k"]').value.trim();
            var v = row.querySelector('[data-k="v"]').value.trim();
            if (!k || !v) return null;
            return { k: k, v: v };
          }),
          care: collectRows(rowsCare, function (row) {
            var b = row.querySelector('[data-k="body"]').value.trim();
            return b ? b : null;
          })
        };

        if (!payload.name) { U.toast('请填写商品名称', 'error'); return; }
        if (!payload.sku) { U.toast('请填写 SKU', 'error'); return; }
        if (isNew && !payload.id) { U.toast('请填写商品编号', 'error'); return; }

        btn.disabled = true;
        var old = btn.textContent;
        btn.textContent = '保存中…';

        var req = isNew ? API.createProduct(payload) : API.updateProduct(id, payload);
        req.then(function () {
          U.toast(isNew ? '商品已创建' : '已保存');
          closeDrawer();
          if (window.__reloadProducts) window.__reloadProducts();
          // 商品列表视图重新渲染以刷新数据
          if (App.view === 'products') go('products');
        }).catch(function (err) {
          U.toast(err.message, 'error');
        }).then(function () {
          btn.disabled = false;
          btn.textContent = old;
        });
      });
    }).catch(function (err) {
      U.toast(err.message || '加载商品失败', 'error');
    });
  };

  /* =============================================================
   * 分类编辑抽屉
   * ============================================================= */
  App.openCategory = function (id) {
    var isNew = !id;
    var load = isNew ? Promise.resolve(null) : API.categories().then(function (r) {
      return (r.items || []).filter(function (c) { return c.id === id; })[0] || null;
    });

    load.then(function (c) {
      if (!isNew && !c) { U.toast('分类不存在', 'error'); return; }

      var body =
        '<div class="form-grid" style="grid-template-columns:1fr">' +
          '<div class="fld"><label for="c-id">分类编号<span class="req">*</span></label>' +
            '<input class="field" id="c-id" value="' + U.esc(c ? c.id : '') + '"' + (isNew ? '' : ' readonly') +
            ' placeholder="如 desk" maxlength="32">' +
            '<div class="hint">小写字母、数字、连字符。创建后不可修改。</div></div>' +
          '<div class="fld"><label for="c-label">分类名称<span class="req">*</span></label>' +
            '<input class="field" id="c-label" value="' + U.esc(c ? c.label : '') + '" maxlength="20" placeholder="如「桌面」"></div>' +
          '<div class="fld"><label for="c-sort">排序</label>' +
            '<input class="field" id="c-sort" inputmode="numeric" value="' + (c ? c.sort : 0) + '">' +
            '<div class="hint">数字越小越靠前。</div></div>' +
          '<div class="fld"><label for="c-status">前台显示</label>' +
            '<select class="field" id="c-status">' +
              '<option value="active"' + (!c || c.status === 'active' ? ' selected' : '') + '>显示</option>' +
              '<option value="hidden"' + (c && c.status === 'hidden' ? ' selected' : '') + '>隐藏</option>' +
            '</select></div>' +
        '</div>';

      var foot =
        '<button class="btn btn--ghost" type="button" id="d-cancel">取消</button>' +
        '<span class="spacer"></span>' +
        '<button class="btn btn--primary" type="button" id="d-save">' + (isNew ? '创建分类' : '保存') + '</button>';

      openDrawer(isNew ? '新建分类' : '编辑分类', body, foot);

      document.getElementById('d-cancel').addEventListener('click', closeDrawer);
      document.getElementById('d-save').addEventListener('click', function (e) {
        var btn = e.currentTarget;
        var payload = {
          id: document.getElementById('c-id').value.trim(),
          label: document.getElementById('c-label').value.trim(),
          sort: parseInt(document.getElementById('c-sort').value, 10) || 0,
          status: document.getElementById('c-status').value
        };
        if (!payload.label) { U.toast('请填写分类名称', 'error'); return; }
        if (isNew && !payload.id) { U.toast('请填写分类编号', 'error'); return; }

        btn.disabled = true;
        var req = isNew ? API.createCategory(payload) : API.updateCategory(id, payload);
        req.then(function () {
          U.toast(isNew ? '分类已创建' : '已保存');
          closeDrawer();
          if (App.view === 'categories') go('categories');
        }).catch(function (err) {
          U.toast(err.message, 'error');
        }).then(function () { btn.disabled = false; });
      });
    }).catch(function (err) {
      U.toast(err.message || '加载分类失败', 'error');
    });
  };

  /* =============================================================
   * 订单详情抽屉
   * ============================================================= */
  App.openOrder = function (orderNo) {
    openDrawer('订单详情', '<div class="state">加载中…</div>', '');

    API.order(orderNo).then(function (o) {
      var items = (o.items || []).map(function (it) {
        return '<div class="line-item">' +
          '<div><div>' + U.esc(it.name) + '</div>' +
            '<div class="line-item__opt">' + U.esc(it.sku) +
            (it.color ? ' · ' + U.esc(it.color) : '') +
            (it.size ? ' · ' + U.esc(it.size) : '') + '</div></div>' +
          '<div class="line-item__q">×' + it.qty + '</div>' +
          '<div class="line-item__p">' + U.money(it.line_cents) + '</div>' +
        '</div>';
      }).join('');

      var pays = (o.payments || []).map(function (p) {
        return '<div class="line-item" style="grid-template-columns:minmax(0,1fr) auto">' +
          '<div><div>' + U.esc(U.channelLabel(p.channel)) + ' · ' + U.money(p.amount_cents) + '</div>' +
            '<div class="line-item__opt">' + U.esc(p.out_trade_no) +
            (p.trade_no ? ' · 网关单号 ' + U.esc(p.trade_no) : '') + '</div></div>' +
          '<div style="text-align:right"><span class="badge ' +
            (p.status === 'success' ? 'badge--ok' : (p.status === 'closed' ? 'badge--bad' : '')) + '">' +
            U.esc(p.status) + '</span>' +
            '<div class="line-item__opt">' + U.time(p.created_at) + '</div></div>' +
        '</div>';
      }).join('') || '<div class="state" style="padding:20px 0">还没有支付记录</div>';

      var body =
        '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px">' +
          '<span class="num" style="font-size:15px;font-weight:var(--w-semi)">' + U.esc(o.order_no) + '</span>' +
          U.orderBadge(o.status) +
          '<span class="spacer" style="flex:1"></span>' +
          '<span class="tiny">' + U.time(o.created_at) + '</span>' +
        '</div>' +

        '<div class="sec-title">收货信息</div>' +
        '<dl class="kv">' +
          '<dt>收货人</dt><dd>' + U.esc(o.buyer_name) + '</dd>' +
          '<dt>手机号</dt><dd class="num">' + U.esc(o.buyer_phone) + '</dd>' +
          '<dt>地址</dt><dd>' + U.esc(o.buyer_address) + '</dd>' +
          (o.note ? '<dt>买家备注</dt><dd>' + U.esc(o.note) + '</dd>' : '') +
          (o.admin_note ? '<dt>后台备注</dt><dd>' + U.esc(o.admin_note) + '</dd>' : '') +
        '</dl>' +

        '<div class="sec-title">商品</div>' +
        items +
        '<div class="totals">' +
          '<div class="totals__row"><span>商品合计</span><b>' + U.money(o.subtotal_cents) + '</b></div>' +
          '<div class="totals__row"><span>运费</span><b>' + (o.ship_cents === 0 ? '包邮' : U.money(o.ship_cents)) + '</b></div>' +
          '<div class="totals__row"><span>会员折扣</span><b>' + (o.discount_cents ? '−' + U.money(o.discount_cents) : '—') + '</b></div>' +
          '<div class="totals__row totals__row--big"><span>应付总额</span><b>' + U.money(o.total_cents) + '</b></div>' +
          (o.paid_cents !== null && o.paid_cents !== undefined
            ? '<div class="totals__row"><span>实收</span><b>' + U.money(o.paid_cents) + '</b></div>' : '') +
        '</div>' +

        '<div class="sec-title">支付</div>' +
        '<dl class="kv">' +
          '<dt>支付渠道</dt><dd>' + U.esc(U.channelLabel(o.pay_channel)) + '</dd>' +
          '<dt>支付时间</dt><dd>' + (o.paid_at ? U.time(o.paid_at) : '—') + '</dd>' +
          (o.pay_trade_no ? '<dt>网关单号</dt><dd class="num">' + U.esc(o.pay_trade_no) + '</dd>' : '') +
        '</dl>' +
        '<div style="margin-top:10px">' + pays + '</div>' +

        (o.shipped_at || o.ship_no
          ? '<div class="sec-title">物流</div><dl class="kv">' +
            '<dt>发货时间</dt><dd>' + U.time(o.shipped_at) + '</dd>' +
            '<dt>快递公司</dt><dd>' + U.esc(o.ship_company || '—') + '</dd>' +
            '<dt>运单号</dt><dd class="num">' + U.esc(o.ship_no || '—') + '</dd>' +
            '</dl>'
          : '') +

        '<div class="sec-title">后台备注</div>' +
        '<textarea class="field" id="o-note" maxlength="200" placeholder="仅后台可见，如「已电话确认地址」">' + U.esc(o.admin_note || '') + '</textarea>' +

        '<div class="sec-title">发货内容</div>' +
        '<div id="o-delivery"><div class="state">加载中…</div></div>';

      /* ---- 底部操作按钮：按状态机给出合法动作 ---- */
      var actions = [];
      if (o.status === 'pending') {
        actions.push('<button class="btn btn--sm btn--ghost" data-act="paid">标记已收款</button>');
        actions.push('<button class="btn btn--sm btn--ghost" data-act="cancelled">取消订单</button>');
      } else if (o.status === 'paid') {
        actions.push('<button class="btn btn--sm btn--primary" data-act="shipped">发货</button>');
        actions.push('<button class="btn btn--sm btn--ghost" data-act="cancelled">取消并退款</button>');
      } else if (o.status === 'shipped') {
        actions.push('<button class="btn btn--sm btn--primary" data-act="done">标记完成</button>');
      }

      var foot =
        '<button class="btn btn--ghost" type="button" id="d-cancel">关闭</button>' +
        '<span class="spacer"></span>' +
        '<button class="btn btn--sm btn--ghost" type="button" id="o-savenote">保存备注</button>' +
        actions.join('');

      openDrawer('订单详情', body, foot);

      // v5：挂载发货内容编辑面板
      if (window.AdminOrderDelivery) {
        var dvHost = document.getElementById('o-delivery');
        if (dvHost) window.AdminOrderDelivery.mount(dvHost, o.order_no);
      }

      document.getElementById('d-cancel').addEventListener('click', closeDrawer);

      document.getElementById('o-savenote').addEventListener('click', function (e) {
        var b = e.currentTarget;
        b.disabled = true;
        API.setOrderStatus(o.order_no, o.status, { admin_note: document.getElementById('o-note').value })
          .then(function () {
            U.toast('备注已保存');
            if (App.view === 'orders') go('orders');
          })
          .catch(function (err) { U.toast(err.message, 'error'); })
          .then(function () { b.disabled = false; });
      });

      dFoot.addEventListener('click', function (e) {
        var b = e.target.closest('[data-act]');
        if (!b) return;
        var to = b.getAttribute('data-act');

        // 实体发货：走专门的 /ship 接口。它会登记快递公司 + 运单号，
        // 并把该订单的实体发货记录标记为「已发货」——否则只改订单状态、
        // 发货记录仍是「待发货」，前台/详情看起来就像「没发货」。
        if (to === 'shipped') {
          var company = window.prompt('快递公司（如：顺丰 / 中通）：', '');
          if (company === null) return;
          var shipNo = window.prompt('运单号：', '');
          if (shipNo === null) return;
          var cn = company.trim(), sn = shipNo.trim();
          if (cn === '' || sn === '') { U.toast('请填写快递公司与运单号', 'error'); return; }
          b.disabled = true;
          API.shipOrder(o.order_no, cn, sn).then(function () {
            U.toast('已发货');
            closeDrawer();
            if (App.view === 'orders') go('orders');
            else if (App.view === 'dashboard') go('dashboard');
          }).catch(function (err) {
            U.toast(err.message, 'error');
            b.disabled = false;
          });
          return;
        }

        var extra = {};
        if (to === 'cancelled') {
          if (!U.confirm('取消订单会归还库存，且不可撤销。确定继续？')) return;
        }
        if (to === 'paid') {
          if (!U.confirm('确认已收到这笔款项？\n\n这会把订单标记为「已付款」并计入营收统计。')) return;
        }

        b.disabled = true;
        API.setOrderStatus(o.order_no, to, extra).then(function () {
          U.toast('订单状态已更新');
          closeDrawer();
          if (App.view === 'orders') go('orders');
          else if (App.view === 'dashboard') go('dashboard');
        }).catch(function (err) {
          U.toast(err.message, 'error');
          b.disabled = false;
        });
      });
    }).catch(function (err) {
      dBody.innerHTML = '<div class="state"><b>加载失败</b>' + U.esc(err.message) + '</div>';
    });
  };

  /* =============================================================
   * 评价编辑抽屉
   * ============================================================= */
  App.openReview = function (id) {
    var isNew = !id;

    Promise.all([
      API.products({ limit: 100, offset: 0 }),
      isNew ? Promise.resolve(null) : API.reviews({ limit: 100, offset: 0 })
    ]).then(function (res) {
      var prods = res[0].items || [];
      var rv = null;
      if (!isNew) {
        rv = (res[1].items || []).filter(function (x) { return x.id === id; })[0] || null;
        if (!rv) { U.toast('评价不存在', 'error'); return; }
      }

      var prodOpts = prods.map(function (p) {
        var sel = rv && rv.product_id === p.id ? ' selected' : '';
        return '<option value="' + U.esc(p.id) + '"' + sel + '>' + U.esc(p.name) + '</option>';
      }).join('');

      var body =
        '<div class="form-grid">' +
          '<div class="fld full"><label for="v-prod">商品<span class="req">*</span></label>' +
            '<select class="field" id="v-prod">' + prodOpts + '</select></div>' +
          '<div class="fld"><label for="v-author">评价人<span class="req">*</span></label>' +
            '<input class="field" id="v-author" value="' + U.esc(rv ? rv.author : '') + '" maxlength="30" placeholder="如「林小满」"></div>' +
          '<div class="fld"><label for="v-days">时间标记</label>' +
            '<input class="field" id="v-days" value="' + U.esc(rv ? rv.days_ago : '') + '" maxlength="20" placeholder="如「3 天前」"></div>' +
          '<div class="fld"><label for="v-stars">评分（1-5）</label>' +
            '<select class="field" id="v-stars">' +
              [5, 4, 3, 2, 1].map(function (n) {
                return '<option value="' + n + '"' + (rv && rv.stars === n ? ' selected' : (!rv && n === 5 ? ' selected' : '')) + '>' + n + ' 星</option>';
              }).join('') +
            '</select></div>' +
          '<div class="fld"><label for="v-status">状态</label>' +
            '<select class="field" id="v-status">' +
              '<option value="visible"' + (!rv || rv.status === 'visible' ? ' selected' : '') + '>显示</option>' +
              '<option value="hidden"' + (rv && rv.status === 'hidden' ? ' selected' : '') + '>隐藏</option>' +
            '</select></div>' +
          '<div class="fld full"><label for="v-body">评价内容<span class="req">*</span></label>' +
            '<textarea class="field" id="v-body" maxlength="600" style="min-height:120px">' + U.esc(rv ? rv.body : '') + '</textarea></div>' +
        '</div>';

      var foot =
        '<button class="btn btn--ghost" type="button" id="d-cancel">取消</button>' +
        '<span class="spacer"></span>' +
        '<button class="btn btn--primary" type="button" id="d-save">' + (isNew ? '创建评价' : '保存') + '</button>';

      openDrawer(isNew ? '新增评价' : '编辑评价', body, foot);

      document.getElementById('d-cancel').addEventListener('click', closeDrawer);
      document.getElementById('d-save').addEventListener('click', function (e) {
        var btn = e.currentTarget;
        var payload = {
          product_id: document.getElementById('v-prod').value,
          author: document.getElementById('v-author').value.trim(),
          days_ago: document.getElementById('v-days').value.trim(),
          stars: parseInt(document.getElementById('v-stars').value, 10),
          status: document.getElementById('v-status').value,
          body: document.getElementById('v-body').value.trim()
        };
        if (!payload.author) { U.toast('请填写评价人', 'error'); return; }
        if (!payload.body) { U.toast('请填写评价内容', 'error'); return; }

        btn.disabled = true;
        var req = isNew ? API.createReview(payload) : API.updateReview(id, payload);
        req.then(function () {
          U.toast(isNew ? '评价已创建' : '已保存');
          closeDrawer();
          if (App.view === 'reviews') go('reviews');
        }).catch(function (err) {
          U.toast(err.message, 'error');
        }).then(function () { btn.disabled = false; });
      });
    }).catch(function (err) {
      U.toast(err.message || '加载失败', 'error');
    });
  };

  /* =============================================================
   * 快捷补货（概览页）
   * ============================================================= */
  App.quickRestock = function (id) {
    var raw = window.prompt('补货数量（正数入库，负数出库）：', '10');
    if (raw === null) return;
    var n = parseInt(raw, 10);
    if (!n || isNaN(n)) { U.toast('请输入有效数字', 'error'); return; }
    API.adjustStock(id, n).then(function (r) {
      U.toast('库存已更新为 ' + r.stock);
      go('dashboard');
    }).catch(function (err) {
      U.toast(err.message, 'error');
    });
  };

  /* =============================================================
   * 启动
   * ============================================================= */
  U.initTheme();

  document.getElementById('btn-logout').addEventListener('click', function () {
    API.logout().then(function () {
      location.replace('login.html');
    }).catch(function () {
      location.replace('login.html');
    });
  });

  // v1.2.2 通用插件资源加载器：注入已启用插件的 admin 资源（脚本注册菜单与视图）
  function loadPluginAssets() {
    fetch(new URL('../api/features', location.href).toString(), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (resp) { return resp.ok ? resp.json() : {}; })
      .then(function (j) {
        var d = (j && j.data) || j || {};
        var list = (d.assets && d.assets.admin) || [];
        list.forEach(function (rel) {
          var url = new URL(rel, location.href).toString();
          if (/\.css(\?|$)/.test(rel)) {
            var link = document.createElement('link');
            link.rel = 'stylesheet'; link.href = url; document.head.appendChild(link);
          } else {
            var s = document.createElement('script');
            s.src = url; document.body.appendChild(s);
          }
        });
      })
      .catch(function () {});
  }

  API.me().then(function (r) {
    App.me = r.user;
    loadPluginAssets();
    document.getElementById('me-name').textContent = r.user.display_name || r.user.username;
    document.getElementById('me-role').textContent = r.user.role === 'owner' ? '店主' : '店员';
    if (r.user.role === 'owner') {
      document.getElementById('nav-audit-link').hidden = false;
    }
    if (r.user.must_change_password) {
      U.toast('建议尽快修改初始密码', 'error');
    }

    // 侧栏计数
    API.dashboard().then(function (d) {
      document.getElementById('nav-products').textContent = d.products.active;
      document.getElementById('nav-orders').textContent = d.orders.pending;
      document.getElementById('nav-reviews').textContent = d.reviews.total;
      document.getElementById('topbar-hint').textContent =
        '待付款 ' + d.orders.pending + ' · 今日 ' + d.orders.today + ' 单';
    }).catch(function () {});

    var start = (location.hash || '').replace('#', '') || 'dashboard';
    go(start);
  }).catch(function () {
    // me() 内部已处理 401 跳转；这里兜底
    location.replace('login.html');
  });

  window.AdminApp = App;
})();
