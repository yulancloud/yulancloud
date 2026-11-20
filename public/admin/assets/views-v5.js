/* =============================================================
 * admin/assets/views-v5.js —— v5 新增后台视图
 *
 *  - users：前台用户管理（余额调整 / 停用 / 重置密码 / 流水）
 *  - 商品发货模板编辑（挂在商品抽屉里，由 app.js 调用）
 *  - 订单发货内容编辑（挂在订单抽屉里）
 *
 * 安全：所有来自数据库的文本进 innerHTML 前一律 U.esc()。
 * ============================================================= */
(function () {
  'use strict';

  var API = window.AdminAPI;
  var U = window.AdminU;

  function h(html) { return html; }
  function stateBox(title, sub) {
    return '<div class="state"><b>' + U.esc(title) + '</b>' +
      (sub ? '<span>' + U.esc(sub) + '</span>' : '') + '</div>';
  }
  function loadingBox() { return '<div class="state"><b>加载中…</b></div>'; }

  function pager(total, limit, offset, onGo) {
    var page = Math.floor(offset / limit) + 1;
    var pages = Math.max(1, Math.ceil(total / limit));
    var el = document.createElement('div');
    el.className = 'pager';
    el.innerHTML =
      '<button class="btn btn--sm btn--ghost" data-pg="prev"' + (page <= 1 ? ' disabled' : '') + '>上一页</button>' +
      '<span class="tiny">第 ' + page + ' / ' + pages + ' 页 · 共 ' + total + ' 条</span>' +
      '<button class="btn btn--sm btn--ghost" data-pg="next"' + (page >= pages ? ' disabled' : '') + '>下一页</button>';
    el.addEventListener('click', function (e) {
      var b = e.target.closest('[data-pg]');
      if (!b) return;
      if (b.getAttribute('data-pg') === 'prev') onGo(Math.max(0, offset - limit));
      else onGo(offset + limit);
    });
    return el;
  }

  var userState = { q: '', status: '', limit: 20, offset: 0 };

  var users = {
    title: '用户管理',
    render: function (host) {
      host.innerHTML =
        '<div class="toolbar">' +
          '<input class="field field--grow" id="u-q" type="search" placeholder="用户名 / 昵称 / 邮箱" value="' + U.esc(userState.q) + '">' +
          '<select class="field" id="u-status">' +
            '<option value="">全部状态</option>' +
            '<option value="active">正常</option>' +
            '<option value="disabled">已停用</option>' +
          '</select>' +
        '</div>' +
        '<div class="panel-card"><div id="u-list">' + loadingBox() + '</div></div>';

      var qEl = document.getElementById('u-q');
      var stEl = document.getElementById('u-status');
      stEl.value = userState.status;

      var reload = function () {
        var box = document.getElementById('u-list');
        if (!box) return;
        box.innerHTML = loadingBox();
        API.users({
          q: userState.q, status: userState.status,
          limit: userState.limit, offset: userState.offset
        }).then(function (r) {
          if (!r.items.length) {
            box.innerHTML = stateBox('还没有前台用户', '用户在前台 /console 注册后出现在这里');
            return;
          }
          var rows = r.items.map(function (u) {
            return '<tr data-uid="' + u.id + '" style="cursor:pointer">' +
              '<td><div class="tbl__name">' + U.esc(u.display_name || u.username) + '</div>' +
                '<div class="tbl__sub">' + U.esc(u.username) + (u.email ? ' · ' + U.esc(u.email) : '') + '</div></td>' +
              '<td class="num">' + U.money(u.balance_cents) + '</td>' +
              '<td class="num">' + u.order_count + '</td>' +
              '<td class="num">' + U.money(u.spent_cents) + '</td>' +
              '<td>' + (u.status === 'active'
                ? '<span class="badge badge--ok">正常</span>'
                : '<span class="badge badge--bad">已停用</span>') + '</td>' +
              '<td class="tiny">' + U.time(u.created_at) + '</td>' +
              '<td class="tbl__actions"><button class="btn btn--sm btn--ghost" data-open="' + u.id + '">详情</button></td>' +
            '</tr>';
          }).join('');

          box.innerHTML =
            '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
              '<th>用户</th><th>余额</th><th>订单数</th><th>累计消费</th><th>状态</th><th>注册时间</th><th></th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div>';
          box.appendChild(pager(r.total, userState.limit, userState.offset, function (off) {
            userState.offset = off;
            reload();
          }));
        }).catch(function (err) {
          box.innerHTML = stateBox('加载失败', err.message);
        });
      };

      qEl.addEventListener('input', U.debounce(function () {
        userState.q = qEl.value.trim(); userState.offset = 0; reload();
      }, 320));
      stEl.addEventListener('change', function () {
        userState.status = stEl.value; userState.offset = 0; reload();
      });

      // 用 onclick 覆盖赋值：render 可能被反复调用，addEventListener 会在同一容器上累积，
      // 导致点一次行打开 N 个抽屉 / 发 N 次请求。
      host.onclick = function (e) {
        var tr = e.target.closest('[data-uid]');
        if (tr) openUser(parseInt(tr.getAttribute('data-uid'), 10));
      };

      reload();
    }
  };

  /* =============================================================
   * 用户详情抽屉
   * ============================================================= */
  function openUser(id) {
    var drawer = document.getElementById('drawer');
    var body = document.getElementById('drawer-body');
    var title = document.getElementById('drawer-title');
    if (!drawer || !body) return;

    title.textContent = '用户详情';
    body.innerHTML = loadingBox();
    drawer.classList.add('is-on');
    var mask = document.getElementById('drawer-mask');
    if (mask) mask.classList.add('is-on');
    document.body.style.overflow = 'hidden';

    API.user(id).then(function (d) {
      var u = d.user;
      var orders = (d.orders || []).map(function (o) {
        return '<tr><td class="num">' + U.esc(o.order_no) + '</td>' +
          '<td>' + U.orderBadge(o.status) + '</td>' +
          '<td class="num">' + U.money(o.total) + '</td>' +
          '<td class="tiny">' + U.time(o.created_at) + '</td></tr>';
      }).join('') || '<tr><td colspan="4">' + stateBox('暂无订单') + '</td></tr>';

      var ledger = (d.ledger || []).map(function (r) {
        return '<tr><td>' + U.esc(r.type_label) + (r.remark ? '<div class="tbl__sub">' + U.esc(r.remark) + '</div>' : '') + '</td>' +
          '<td class="num" style="color:' + (r.amount > 0 ? '#2f7d5b' : 'inherit') + '">' +
            (r.amount > 0 ? '+' : '') + U.money(r.amount) + '</td>' +
          '<td class="num tiny">' + U.money(r.balance_after) + '</td>' +
          '<td class="tiny">' + U.time(r.created_at) + '</td></tr>';
      }).join('') || '<tr><td colspan="4">' + stateBox('暂无流水') + '</td></tr>';

      body.innerHTML =
        '<div class="form-grid">' +
          '<div class="fld"><label>用户名</label><div class="field" style="background:var(--surface-3)">' + U.esc(u.username) + '</div></div>' +
          '<div class="fld"><label>昵称</label><div class="field" style="background:var(--surface-3)">' + U.esc(u.display_name || '—') + '</div></div>' +
          '<div class="fld"><label>邮箱</label><div class="field" style="background:var(--surface-3)">' + U.esc(u.email || '—') + '</div></div>' +
          '<div class="fld"><label>当前余额</label><div class="field" style="background:var(--surface-3)"><b>' + U.money(u.balance_cents) + '</b></div></div>' +
          '<div class="fld"><label>状态</label><div class="field" style="background:var(--surface-3)">' +
            (u.status === 'active' ? '正常' : '已停用') + '</div></div>' +
          '<div class="fld"><label>注册时间</label><div class="field" style="background:var(--surface-3)">' + U.time(u.created_at) + '</div></div>' +
        '</div>' +

        '<hr class="rule" style="margin:20px 0">' +
        '<h4 style="font-size:13px;font-weight:600;margin-bottom:10px">余额调整</h4>' +
        '<div class="form-grid">' +
          '<div class="fld"><label for="ub-amt">金额（元，负数为扣款）</label>' +
            '<input class="field" id="ub-amt" placeholder="如 100 或 -50" inputmode="decimal"></div>' +
          '<div class="fld"><label for="ub-remark">调整原因<span class="req">*</span></label>' +
            '<input class="field" id="ub-remark" placeholder="必填，用于对账" maxlength="200"></div>' +
        '</div>' +
        '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">' +
          '<button class="btn btn--sm btn--primary" id="ub-save">确认调整</button>' +
          '<button class="btn btn--sm btn--ghost" id="ub-reset">重置密码</button>' +
          '<button class="btn btn--sm btn--ghost" id="ub-toggle">' +
            (u.status === 'active' ? '停用账号' : '恢复账号') + '</button>' +
        '</div>' +

        '<hr class="rule" style="margin:20px 0">' +
        '<h4 style="font-size:13px;font-weight:600;margin-bottom:10px">最近订单</h4>' +
        '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>订单号</th><th>状态</th><th>金额</th><th>时间</th></tr></thead>' +
        '<tbody>' + orders + '</tbody></table></div>' +

        '<hr class="rule" style="margin:20px 0">' +
        '<h4 style="font-size:13px;font-weight:600;margin-bottom:10px">余额流水</h4>' +
        '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>类型</th><th>变动</th><th>余额</th><th>时间</th></tr></thead>' +
        '<tbody>' + ledger + '</tbody></table></div>';

      // 余额调整
      document.getElementById('ub-save').addEventListener('click', function (e) {
        var btn = e.currentTarget;
        var amtEl = document.getElementById('ub-amt');
        var rmEl = document.getElementById('ub-remark');
        var cents = U.toCents(amtEl.value, true);
        if (cents === null) { U.toast('金额格式不正确', 'error'); return; }
        if (cents === 0) { U.toast('金额不能为 0', 'error'); return; }
        if (!rmEl.value.trim()) { U.toast('请填写调整原因', 'error'); return; }
        if (!U.confirm('确认对用户「' + u.username + '」调整余额 ' + U.money(cents) + '？')) return;
        btn.disabled = true;
        API.adjustBalance(id, cents, rmEl.value.trim()).then(function (r) {
          U.toast('调整成功，当前余额 ' + U.money(r.balance));
          openUser(id);
        }).catch(function (err) {
          U.toast(err.message, 'error');
          btn.disabled = false;
        });
      });

      // 重置密码
      document.getElementById('ub-reset').addEventListener('click', function (e) {
        var btn = e.currentTarget;
        if (!U.confirm('将为该用户生成一次性临时密码，并踢掉其所有登录会话。继续？')) return;
        btn.disabled = true;
        API.resetUserPassword(id).then(function (r) {
          // 临时密码只显示一次，用 alert 保证用户能看到并复制
          window.alert('临时密码（只显示这一次）：\n\n' + r.temp_password +
            '\n\n请立即转交用户。用户下次登录后需修改密码。');
          U.toast('已重置密码');
        }).catch(function (err) {
          U.toast(err.message, 'error');
        }).then(function () { btn.disabled = false; });
      });

      // 停用 / 恢复
      document.getElementById('ub-toggle').addEventListener('click', function (e) {
        var btn = e.currentTarget;
        var next = u.status === 'active' ? 'disabled' : 'active';
        if (!U.confirm(next === 'disabled' ? '停用后该用户将无法登录，且现有会话立即失效。继续？' : '恢复该账号？')) return;
        btn.disabled = true;
        API.setUserStatus(id, next).then(function () {
          U.toast(next === 'disabled' ? '已停用' : '已恢复');
          openUser(id);
        }).catch(function (err) {
          U.toast(err.message, 'error');
          btn.disabled = false;
        });
      });
    }).catch(function (err) {
      body.innerHTML = stateBox('加载失败', err.message);
    });
  }

  /* =============================================================
   * 商品发货模板编辑（由 app.js 的商品抽屉调用）
   * ============================================================= */
  window.AdminDelivery = {
    /** 在给定容器里渲染发货配置表单 */
    mount: function (container, productId) {
      container.innerHTML = loadingBox();
      API.productDelivery(productId).then(function (d) {
        var fields = d.fields || [];
        var rowsHtml = fields.map(function (f, i) {
          return fieldRow(f.label, f.value, i);
        }).join('');

        container.innerHTML =
          '<div class="form-grid">' +
            '<div class="fld"><label for="dv-type">商品类型</label>' +
              '<select class="field" id="dv-type">' +
                '<option value="physical"' + (d.type === 'physical' ? ' selected' : '') + '>实体商品（人工发货）</option>' +
                '<option value="virtual"' + (d.type === 'virtual' ? ' selected' : '') + '>虚拟商品（支付后自动发货）</option>' +
                '<option value="yulan"' + (d.type === 'yulan' ? ' selected' : '') + '>魔方财务（对接上游开通）</option>' +
                '<option value="cloud"' + (d.type === 'cloud' ? ' selected' : '') + '>魔方云（KVM 云服务器，支付后开通）</option>' +
              '</select>' +
              '<div class="hint">实体商品人工发货；虚拟商品按模板自动发货；魔方财务 / 魔方云绑定上游后，支付时自动在上游开通并交付连接信息（密码平台不长期存储）。</div>' +
            '</div>' +
          '</div>' +
          '<div id="dv-yulan" style="display:' + (d.type === 'yulan' ? 'block' : 'none') + ';margin-top:14px;padding:14px;border-radius:12px;background:var(--paper);box-shadow:inset 0 0 0 1px var(--hair-2)">' +
            '<div style="font-weight:var(--w-med);margin-bottom:10px">欲蓝财务魔方 · 限购与防刷</div>' +
            '<div class="form-grid" style="grid-template-columns:1fr 1fr">' +
              '<div class="fld"><label for="dv-yulan-limit">账号限购数量</label>' +
                '<input class="field" id="dv-yulan-limit" type="number" min="0" max="1000000" value="' + (d.yulan_account_limit || 0) + '">' +
                '<div class="hint">单个账号最多可购买多少件（0 = 不限购）</div>' +
              '</div>' +
              '<div class="fld"><label for="dv-yulan-ip">IP 限制</label>' +
                '<select class="field" id="dv-yulan-ip">' +
                  '<option value="0"' + ((d.yulan_ip_restrict || 0) === 0 ? ' selected' : '') + '>关闭</option>' +
                  '<option value="1"' + ((d.yulan_ip_restrict || 0) === 1 ? ' selected' : '') + '>开启（按 IP 维度限制，防开小号）</option>' +
                '</select>' +
                '<div class="hint">开启后，同一 IP 也受「账号限购数量」约束，防止同一人开多个账号刷单。</div>' +
              '</div>' +
            '</div>' +
            '<div style="font-weight:var(--w-med);margin:14px 0 4px">财务魔方 API（v1.3）</div>' +
            '<div class="fld" style="margin-bottom:6px"><label for="dv-yulan-api">使用哪个 API</label>' +
              '<select class="field" id="dv-yulan-api">' + buildApiOptions(d.apis || [], d.yulan_api_id || 0) + '</select>' +
              '<div class="hint">在后台「财务魔方 API」选项卡里可新增多条命名 API（不同服务器 / 口令）。选择后本商品建号与改密都走该 API，优先级高于下方「本商品服务器」和全局默认。留空则回退下面的配置。</div>' +
            '</div>' +
            '<div style="font-weight:var(--w-med);margin:14px 0 4px">欲蓝财务魔方 · 本商品服务器（留空则用「欲蓝服务器」里的全局默认）</div>' +
            '<div class="form-grid" style="grid-template-columns:1fr 1fr">' +
              '<div class="fld"><label for="dv-yulan-sip">服务器 IP</label>' +
                '<input class="field" id="dv-yulan-sip" value="' + U.esc(d.yulan_server_ip || '') + '" maxlength="64" placeholder="留空 = 用全局默认">' +
              '</div>' +
              '<div class="fld"><label for="dv-yulan-sport">端口</label>' +
                '<input class="field" id="dv-yulan-sport" inputmode="numeric" value="' + U.esc(d.yulan_server_port || '') + '" maxlength="5" placeholder="留空 = 8321（全局默认）">' +
              '</div>' +
              '<div class="fld"><label for="dv-yulan-suser">连接账号（可选）</label>' +
                '<input class="field" id="dv-yulan-suser" value="' + U.esc(d.yulan_server_user || '') + '" maxlength="64" placeholder="留空 = 用全局默认">' +
              '</div>' +
              '<div class="fld"><label for="dv-yulan-spass">连接口令' + (d.yulan_server_pass_set ? '<span class="badge badge--ok">已配置</span>' : '') + '</label>' +
                '<input class="field" id="dv-yulan-spass" type="password" maxlength="128" placeholder="' + (d.yulan_server_pass_set ? '已配置，留空表示不修改；填 __CLEAR__ 清除' : '留空 = 用全局默认口令') + '">' +
                '<div class="hint">出于安全不回显：留空保持不变，填 <code>__CLEAR__</code> 清除（清除后回退全局口令）。</div>' +
              '</div>' +
              '<div class="fld full"><label for="dv-yulan-rdp">连接地址（交付给用户，可选）</label>' +
                '<input class="field" id="dv-yulan-rdp" value="' + U.esc(d.yulan_rdp_host || '') + '" maxlength="128" placeholder="如 203.0.113.10 或 rdp.example.com；留空则用服务器 IP / 全局默认">' +
              '</div>' +
            '</div>' +
          '</div>' +
          // v1.4：魔方财务（对接上游）/ 魔方云 —— 绑定上游 + 上游产品
          '<div id="dv-upstream" style="display:' + (d.type === 'cloud' || d.type === 'yulan' ? 'block' : 'none') + ';margin-top:14px;padding:14px;border-radius:12px;background:var(--paper);box-shadow:inset 0 0 0 1px var(--hair-2)">' +
            '<div style="font-weight:var(--w-med);margin-bottom:10px">上游对接配置（v1.4）</div>' +
            '<div class="notice" style="margin-bottom:10px"><span>绑定上游后，支付时自动在上游开通并交付连接信息。'
            + '魔方财务选类型「魔方财务」+ finance 上游；魔方云选类型「魔方云」+ cloud 上游。未绑定上游时，魔方财务回退旧的全局默认配置。</span></div>' +
            '<div class="form-grid" style="grid-template-columns:1fr 1fr">' +
              '<div class="fld"><label for="dv-upstream-id">使用哪个上游</label>' +
                '<select class="field" id="dv-upstream-id">' + buildUpstreamOptions(d.upstreams || [], d.upstream_id || 0) + '</select>' +
                '<div class="hint">在「魔方财务 · 上游管理」里创建。留空则不走上游。</div>' +
              '</div>' +
              '<div class="fld"><label for="dv-upstream-product">上游产品</label>' +
                '<select class="field" id="dv-upstream-product"></select>' +
                '<div class="hint">选中上游后自动列出其产品；也可留空用本商品 ID 开通。</div>' +
              '</div>' +
            '</div>' +
          '</div>' +
          '<div class="fld full" style="margin-top:14px">' +
            '<label>发货字段（虚拟商品）</label>' +
            '<div id="dv-fields">' + rowsHtml + '</div>' +
            '<button class="btn btn--sm btn--ghost" id="dv-add" type="button" style="margin-top:8px">+ 添加一行</button>' +
            '<div class="hint">例如卖服务器：IP / 账号 / 密码；卖网页：网页链接。取值支持占位符 ' +
              '<code>{order_no}</code> <code>{date}</code> <code>{random:12}</code> <code>{uuid}</code>。</div>' +
          '</div>' +
          '<div class="fld full" style="margin-top:14px">' +
            '<label for="dv-template">补充模板文本（可选，每行一条）</label>' +
            '<textarea class="field" id="dv-template" rows="4" placeholder="使用说明：登录后请立即修改密码">' +
              U.esc(d.template || '') + '</textarea>' +
          '</div>' +
          '<div class="fld full" style="margin-top:14px">' +
            '<button class="btn btn--sm btn--primary" id="dv-save" type="button">保存发货配置</button>' +
          '</div>';

        // v1.4：上游产品下拉随所选上游联动
        var upstreamSel = document.getElementById('dv-upstream-id');
        var upProductSel = document.getElementById('dv-upstream-product');
        var upstreamsData = d.upstreams || [];
        var initUpProduct = U.esc(d.upstream_product_id || '');
        function loadUpProducts(upId, selected) {
          if (!upProductSel) return;
          upProductSel.innerHTML = '<option value="">不使用（用本商品 ID 开通）</option>';
          if (!upId) return;
          API.upstreamProducts(upId).then(function (r) {
            (r.items || []).forEach(function (p) {
              var sel = p.external_id === selected ? ' selected' : '';
              upProductSel.innerHTML += '<option value="' + U.esc(p.external_id) + '"' + sel + '>' + U.esc(p.name) + '</option>';
            });
          }).catch(function () {});
        }
        if (upstreamSel) {
          loadUpProducts(parseInt(upstreamSel.value, 10) || 0, initUpProduct);
          upstreamSel.addEventListener('change', function () {
            loadUpProducts(parseInt(upstreamSel.value, 10) || 0, '');
          });
        }

        // 切换类型时显示 / 隐藏配置面板
        document.getElementById('dv-type').addEventListener('change', function (e) {
          var t = e.target.value;
          var yulanBox = document.getElementById('dv-yulan');
          if (yulanBox) yulanBox.style.display = (t === 'yulan') ? 'block' : 'none';
          var upBox = document.getElementById('dv-upstream');
          if (upBox) upBox.style.display = (t === 'yulan' || t === 'cloud') ? 'block' : 'none';
        });

        var list = document.getElementById('dv-fields');
        document.getElementById('dv-add').addEventListener('click', function () {
          var tmp = document.createElement('div');
          tmp.innerHTML = fieldRow('', '', list.children.length);
          list.appendChild(tmp.firstChild);
        });
        list.addEventListener('click', function (e) {
          var del = e.target.closest('[data-dv-del]');
          if (del) del.closest('.dv-row').remove();
        });

        document.getElementById('dv-save').addEventListener('click', function (e) {
          var btn = e.currentTarget;
          var type = document.getElementById('dv-type').value;
          var payload = {
            type: type,
            template: document.getElementById('dv-template').value,
            fields: []
          };
          if (type === 'yulan') {
            payload.yulan_account_limit = parseInt(document.getElementById('dv-yulan-limit').value, 10) || 0;
            payload.yulan_ip_restrict = parseInt(document.getElementById('dv-yulan-ip').value, 10) || 0;
            // v1.3：绑定的财务魔方 API（0=不使用，回退商品级/全局）
            payload.yulan_api_id = parseInt(document.getElementById('dv-yulan-api').value, 10) || 0;
            // 商品级服务器配置：留空 = 全局默认；口令留空 = 不修改
            payload.yulan_server_ip = document.getElementById('dv-yulan-sip').value.trim();
            payload.yulan_server_port = document.getElementById('dv-yulan-sport').value.trim();
            payload.yulan_server_user = document.getElementById('dv-yulan-suser').value.trim();
            var passRaw = document.getElementById('dv-yulan-spass').value;
            if (passRaw !== '') payload.yulan_server_pass = passRaw;   // 只写不读
            payload.yulan_rdp_host = document.getElementById('dv-yulan-rdp').value.trim();
          }
          // v1.4：上游绑定（魔方财务 / 魔方云都适用）
          if (type === 'yulan' || type === 'cloud') {
            var upSel = document.getElementById('dv-upstream-id');
            var upProdSel = document.getElementById('dv-upstream-product');
            payload.upstream_id = upSel ? (parseInt(upSel.value, 10) || 0) : 0;
            payload.upstream_product_id = upProdSel ? upProdSel.value.trim() : '';
          }
          list.querySelectorAll('.dv-row').forEach(function (row) {
            var label = row.querySelector('[data-dv-label]').value.trim();
            var value = row.querySelector('[data-dv-value]').value;
            if (label) payload.fields.push({ label: label, value: value });
          });
          btn.disabled = true;
          API.saveProductDelivery(productId, payload).then(function (r) {
            U.toast('已保存（' + r.fields + ' 个字段）');
          }).catch(function (err) {
            U.toast(err.message, 'error');
          }).then(function () { btn.disabled = false; });
        });
      }).catch(function (err) {
        container.innerHTML = stateBox('加载失败', err.message);
      });
    }
  };

  /** v1.4：商品发货配置里的「使用哪个上游」下拉选项 */
  function buildUpstreamOptions(ups, selectedId) {
    var html = '<option value="0"' + (selectedId === 0 ? ' selected' : '') + '>不使用上游</option>';
    (ups || []).forEach(function (u) {
      var sel = (u.id === selectedId) ? ' selected' : '';
      var dis = u.enabled ? '' : '（已停用）';
      html += '<option value="' + u.id + '"' + sel + '>' + U.esc(u.name) + ' · ' + U.esc(u.kind_label) + dis + '</option>';
    });
    return html;
  }

  /** v1.3：商品发货配置里的「使用哪个 API」下拉选项 */
  function buildApiOptions(apis, selectedId) {
    var html = '<option value="0"' + (selectedId === 0 ? ' selected' : '') + '>不使用（回退商品级 / 全局默认）</option>';
    (apis || []).forEach(function (a) {
      var sel = (a.id === selectedId) ? ' selected' : '';
      var dis = a.enabled ? '' : '（已停用）';
      html += '<option value="' + a.id + '"' + sel + '>' + U.esc(a.name) + dis + ' · ' + U.esc(a.server_ip) + '</option>';
    });
    return html;
  }

  function fieldRow(label, value, i) {
    return '<div class="dv-row" style="display:grid;grid-template-columns:1fr 1.6fr 34px;gap:8px;margin-bottom:8px">' +
      '<input class="field" data-dv-label placeholder="字段名，如 IP" maxlength="30" value="' + U.esc(label) + '">' +
      '<input class="field" data-dv-value placeholder="取值，如 10.0.0.8" maxlength="500" value="' + U.esc(value) + '">' +
      '<button class="btn btn--sm btn--ghost" type="button" data-dv-del title="删除">×</button>' +
    '</div>';
  }

  /* =============================================================
   * 订单发货内容编辑（由 app.js 的订单抽屉调用）
   * ============================================================= */
  window.AdminOrderDelivery = {
    mount: function (container, orderNo) {
      container.innerHTML = loadingBox();
      API.orderDeliveries(orderNo).then(function (d) {
        var list = d.deliveries || [];
        if (!list.length) {
          container.innerHTML = stateBox('该订单暂无发货记录', '虚拟商品在支付成功后自动生成；实体商品在支付后登记为待发货');
          return;
        }
        container.innerHTML = list.map(function (x) {
          return '<div class="panel-card" style="margin-bottom:12px;padding:14px">' +
            '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap">' +
              '<b style="font-size:13px">' + U.esc(x.product_name) + '</b>' +
              '<span class="badge">' + U.esc(x.kind_label) + '</span>' +
              '<span class="badge ' + (x.status === 'delivered' ? 'badge--ok' : 'badge--warn') + '">' +
                U.esc(x.status_label) + '</span>' +
            '</div>' +
            '<textarea class="field" rows="4" data-dv-content="' + x.id + '" ' +
              'placeholder="发货内容，每行一条，如：IP：10.0.0.8">' + U.esc(x.content) + '</textarea>' +
            '<div style="display:flex;gap:8px;margin-top:8px">' +
              '<button class="btn btn--sm btn--primary" data-dv-save="' + x.id + '">保存并标记已发货</button>' +
              '<button class="btn btn--sm btn--ghost" data-dv-pending="' + x.id + '">标记待发货</button>' +
            '</div>' +
          '</div>';
        }).join('');

        // mount 会被成功后递归调用，改用覆盖赋值避免监听器逐次累积
        container.onclick = function (e) {
          var save = e.target.closest('[data-dv-save]');
          var pend = e.target.closest('[data-dv-pending]');
          if (!save && !pend) return;
          var id = (save || pend).getAttribute(save ? 'data-dv-save' : 'data-dv-pending');
          var ta = container.querySelector('[data-dv-content="' + id + '"]');
          var status = save ? 'delivered' : 'pending';
          var btn = save || pend;
          btn.disabled = true;
          API.updateDelivery(parseInt(id, 10), ta ? ta.value : '', status).then(function () {
            U.toast('已保存');
            window.AdminOrderDelivery.mount(container, orderNo);
          }).catch(function (err) {
            U.toast(err.message, 'error');
            btn.disabled = false;
          });
        };
      }).catch(function (err) {
        container.innerHTML = stateBox('加载失败', err.message);
      });
    }
  };

  window.AdminViewsV5 = { users: users };
})();
