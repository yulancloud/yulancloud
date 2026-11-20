/* =============================================================
 * admin/assets/views-v140.js —— v1.4 管理视图
 *   1. 魔方财务 · 上游管理（upstreams）
 *   2. 魔方云 · 云主机（cloudVms）
 *   3. 工单管理（tickets）
 *
 * 在 views.js 之后、app.js 之前加载，向 window.AdminViews 注册新视图。
 * 所有请求走 window.AdminAPI；DOM 构建沿用 views.js 的 U 工具函数。
 * ============================================================= */
(function () {
  'use strict';
  var API = window.AdminAPI;
  var U = window.AdminU;
  var V = window.AdminViews || (window.AdminViews = {});

  function loadingBox() {
    return '<div class="state-box"><div class="state-box__title">加载中…</div></div>';
  }
  function stateBox(title, desc) {
    return '<div class="state-box"><div class="state-box__title">' + U.esc(title) + '</div>'
      + (desc ? '<div class="state-box__desc">' + U.esc(desc) + '</div>' : '') + '</div>';
  }
  function fld(id, label, val, hint, max, type) {
    return '<div class="fld"><label for="' + id + '">' + U.esc(label) + '</label>'
      + '<input class="field" id="' + id + '" type="' + (type || 'text') + '" value="' + U.esc(val || '') + '"'
      + (max ? ' maxlength="' + max + '"' : '') + '>'
      + (hint ? '<div class="hint">' + hint + '</div>' : '') + '</div>';
  }
  function statusBadge(status, map) {
    var m = map || {};
    var cls = status === 'open' || status === 'failed' || status === 'pending' ? 'badge--warn'
      : (status === 'running' || status === 'replied' || status === 'delivered' || status === 'active' ? 'badge--ok' : '');
    return '<span class="badge ' + cls + '">' + U.esc(m[status] || status) + '</span>';
  }

  /* ============================================================
   * 1. 上游管理（魔方财务对接上游 + 魔方云）
   * ============================================================ */
  var upstreams = {
    title: '魔方财务 · 上游管理',
    render: function (host) {
      host.innerHTML =
        '<div class="notice"><span>在这里维护「对接上游」与「魔方云」接口。'
        + '魔方财务通过上游 API 拉取产品、加价销售，下单时在上游开通；魔方云（KVM）通过上游 API 创建并管理云主机。'
        + '密钥只写不读，留空表示不修改。</span></div>'
        + '<div class="panel-card" style="margin-bottom:16px">'
        + '<div class="panel-card__head"><h2>上游列表</h2>'
        + '<div class="panel-card__actions">'
        + '<select class="field" id="up-kind-filter" style="display:inline-block;width:auto;margin-right:8px">'
        + '<option value="">全部类型</option><option value="finance">魔方财务（上游）</option><option value="cloud">魔方云 KVM</option>'
        + '</select>'
        + '<button class="btn btn--primary" id="up-add" type="button">+ 新增上游</button>'
        + '</div></div>'
        + '<div class="panel-card__body" id="up-list">' + loadingBox() + '</div></div>'
        + '<div class="panel-card" id="up-editor-card" hidden>'
        + '<div class="panel-card__head"><h2 id="up-editor-title">新增上游</h2></div>'
        + '<div class="panel-card__body" id="up-editor"></div></div>'
        + '<div class="panel-card" id="up-products-card" hidden>'
        + '<div class="panel-card__head"><h2 id="up-products-title">上游产品</h2>'
        + '<div class="panel-card__actions"><button class="btn btn--ghost" id="up-sync" type="button">同步产品</button></div></div>'
        + '<div class="panel-card__body" id="up-products"></div></div>';

      var listEl = document.getElementById('up-list');
      var card = document.getElementById('up-editor-card');
      var editor = document.getElementById('up-editor');
      var prodCard = document.getElementById('up-products-card');
      var prodEl = document.getElementById('up-products');
      var filter = document.getElementById('up-kind-filter');
      var currentId = 0;

      function load() {
        var kind = filter.value;
        API.upstreams(kind).then(function (r) {
          var items = (r && r.items) || [];
          if (!items.length) {
            listEl.innerHTML = stateBox('还没有上游', '点右上角「+ 新增上游」创建第一条接口配置。');
            return;
          }
          listEl.innerHTML = '<div class="tbl-wrap"><table class="tbl"><thead><tr>'
            + '<th>名称</th><th>类型</th><th>接口地址</th><th>密钥</th><th>状态</th><th>操作</th>'
            + '</tr></thead><tbody>' + items.map(function (u) {
              return '<tr>'
                + '<td>' + U.esc(u.name) + '</td>'
                + '<td>' + U.esc(u.kind_label) + '</td>'
                + '<td>' + U.esc(u.base_url) + '</td>'
                + '<td>' + (u.api_key_set ? '<span class="badge badge--ok">已配置</span>' : '<span class="badge badge--warn">未设置</span>') + '</td>'
                + '<td>' + (u.enabled ? '<span class="badge badge--ok">启用</span>' : '<span class="badge">停用</span>') + '</td>'
                + '<td>'
                + '<button class="btn btn--sm btn--ghost up-products" data-id="' + u.id + '" data-name="' + U.esc(u.name) + '" type="button">产品</button> '
                + '<button class="btn btn--sm btn--ghost up-edit" data-id="' + u.id + '" type="button">编辑</button> '
                + '<button class="btn btn--sm btn--ghost up-del" data-id="' + u.id + '" data-name="' + U.esc(u.name) + '" type="button">删除</button>'
                + '</td></tr>';
            }).join('') + '</tbody></table></div>';

          listEl.querySelectorAll('.up-edit').forEach(function (b) {
            b.addEventListener('click', function () { openEditor(parseInt(b.getAttribute('data-id'), 10)); });
          });
          listEl.querySelectorAll('.up-products').forEach(function (b) {
            b.addEventListener('click', function () {
              currentId = parseInt(b.getAttribute('data-id'), 10);
              document.getElementById('up-products-title').textContent = '上游产品 · ' + b.getAttribute('data-name');
              prodCard.hidden = false;
              prodCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
              loadProducts(currentId);
            });
          });
          listEl.querySelectorAll('.up-del').forEach(function (b) {
            b.addEventListener('click', function () {
              var id = parseInt(b.getAttribute('data-id'), 10);
              var name = b.getAttribute('data-name');
              if (!window.confirm('确定删除上游「' + name + '」？若仍被商品使用会先提示。')) return;
              b.disabled = true;
              API.deleteUpstream(id).then(function () { U.toast('已删除'); load(); })
                .catch(function (err) { U.toast(err.message, 'error'); })
                .then(function () { b.disabled = false; });
            });
          });
        }).catch(function (err) { listEl.innerHTML = stateBox('加载失败', err.message); });
      }

      function loadProducts(id) {
        prodEl.innerHTML = loadingBox();
        API.upstreamProducts(id).then(function (r) {
          var items = (r && r.items) || [];
          if (!items.length) {
            prodEl.innerHTML = stateBox('暂无产品', '点右上角「同步产品」从上游拉取，或检查上游配置是否正确。');
            return;
          }
          prodEl.innerHTML = '<div class="tbl-wrap"><table class="tbl"><thead><tr>'
            + '<th>上游ID</th><th>名称</th><th>规格</th><th>成本价</th><th>状态</th><th>操作</th>'
            + '</tr></thead><tbody>' + items.map(function (p) {
              return '<tr>'
                + '<td>' + U.esc(p.external_id || '—') + '</td>'
                + '<td><input class="field up-p-name" data-id="' + p.id + '" value="' + U.esc(p.name) + '" style="min-width:140px"></td>'
                + '<td><input class="field up-p-spec" data-id="' + p.id + '" value="' + U.esc(p.spec) + '" style="min-width:160px"></td>'
                + '<td>' + U.money(p.price_cents) + '</td>'
                + '<td>' + (p.status === 'active' ? '<span class="badge badge--ok">上架</span>' : '<span class="badge">隐藏</span>') + '</td>'
                + '<td><button class="btn btn--sm btn--primary up-p-save" data-id="' + p.id + '" type="button">保存</button></td>'
                + '</tr>';
            }).join('') + '</tbody></table></div>';
          prodEl.querySelectorAll('.up-p-save').forEach(function (b) {
            b.addEventListener('click', function () {
              var id = parseInt(b.getAttribute('data-id'), 10);
              var nameEl = prodEl.querySelector('.up-p-name[data-id="' + id + '"]');
              var specEl = prodEl.querySelector('.up-p-spec[data-id="' + id + '"]');
              b.disabled = true;
              API.saveUpstreamProduct(id, {
                upstream_id: currentId,
                name: nameEl ? nameEl.value : '',
                spec: specEl ? specEl.value : '',
              }).then(function () { U.toast('已保存'); loadProducts(currentId); })
                .catch(function (err) { U.toast(err.message, 'error'); })
                .then(function () { b.disabled = false; });
            });
          });
        }).catch(function (err) { prodEl.innerHTML = stateBox('加载失败', err.message); });
      }

      document.getElementById('up-sync').addEventListener('click', function () {
        if (!currentId) { U.toast('请先选择一个上游', 'error'); return; }
        var btn = this; btn.disabled = true;
        API.syncUpstreamProducts(currentId).then(function (r) {
          U.toast('同步了 ' + r.count + ' 个产品');
          loadProducts(currentId);
        }).catch(function (err) { U.toast(err.message, 'error'); })
          .then(function () { btn.disabled = false; });
      });
      filter.addEventListener('change', load);
      document.getElementById('up-add').addEventListener('click', function () { openEditor(0); });

      function openEditor(id) {
        var isNew = !id;
        var task = isNew ? Promise.resolve({ item: null })
          : API.upstreams().then(function (r) {
            return { item: (r.items || []).filter(function (x) { return x.id === id; })[0] || null };
          });
        task.then(function (res) {
          var u = res.item || {};
          document.getElementById('up-editor-title').textContent = isNew ? '新增上游' : '编辑上游 #' + id;
          editor.innerHTML =
            '<div class="form-grid" style="grid-template-columns:1fr 1fr">'
            + fld('up-name', '名称', u.name || '', '自定义，如「电信机房-魔方云」', 40)
            + '<div class="fld"><label for="up-kind">类型</label>'
            + '<select class="field" id="up-kind">'
            + '<option value="finance"' + (u.kind !== 'cloud' ? ' selected' : '') + '>魔方财务（对接上游）</option>'
            + '<option value="cloud"' + (u.kind === 'cloud' ? ' selected' : '') + '>魔方云 KVM</option>'
            + '</select><div class="hint">决定开通时调用的 API 类型</div></div>'
            + fld('up-base', '接口地址', u.base_url || '', 'IP 或域名，可带 http:// 或 https://', 200)
            + fld('up-user', '账号', u.api_user || '', '上游注册的账号（手机/邮箱）', 64)
            + fld('up-fin', '财务标识', u.financial_id || '', '魔方云接口的财务标识，可留空', 128)
            + '<div class="fld"><label for="up-acct">账号类型</label>'
            + '<select class="field" id="up-acct">'
            + '<option value="agent"' + (u.account_type !== 'normal' ? ' selected' : '') + '>代理商</option>'
            + '<option value="normal"' + (u.account_type === 'normal' ? ' selected' : '') + '>普通</option>'
            + '</select></div>'
            + '<div class="fld"><label for="up-https">HTTPS</label>'
            + '<select class="field" id="up-https">'
            + '<option value="0"' + (!u.use_https ? ' selected' : '') + '>否（HTTP）</option>'
            + '<option value="1"' + (u.use_https ? ' selected' : '') + '>是（HTTPS）</option>'
            + '</select></div>'
            + '<div class="fld"><label for="up-enabled">状态</label>'
            + '<select class="field" id="up-enabled">'
            + '<option value="1"' + (u.enabled !== 0 ? ' selected' : '') + '>启用</option>'
            + '<option value="0"' + (u.enabled === 0 ? ' selected' : '') + '>停用</option>'
            + '</select></div>'
            + '<div class="fld"><label for="up-key">API 密钥' + (!isNew && u.api_key_set ? ' <span class="badge badge--ok">已配置</span>' : '') + '</label>'
            + '<input class="field" id="up-key" type="password" maxlength="128" autocomplete="new-password" placeholder="'
            + (!isNew && u.api_key_set ? '留空不修改；__CLEAR__ 清空' : '至少 4 位') + '">'
            + '<div class="hint">只写不读；留空保持不变</div></div>'
            + fld('up-remark', '备注', u.remark || '', '可留空', 200)
            + '</div>'
            + '<div style="margin-top:14px;display:flex;gap:8px">'
            + '<button class="btn btn--ghost" id="up-test" type="button">测试连接</button>'
            + '<button class="btn btn--primary" id="up-save" type="button">保存</button>'
            + '<button class="btn btn--ghost" id="up-cancel" type="button">取消</button>'
            + '</div>';
          card.hidden = false;
          card.scrollIntoView({ behavior: 'smooth', block: 'start' });
          document.getElementById('up-cancel').addEventListener('click', function () { card.hidden = true; });
          document.getElementById('up-test').addEventListener('click', function () {
            var btn = this; btn.disabled = true;
            API.testUpstream(collect()).then(function (r) {
              U.toast((r.ok ? '连接成功：' : '连接失败：') + r.detail, r.ok ? 'success' : 'error');
            }).catch(function (err) { U.toast(err.message, 'error'); })
              .then(function () { btn.disabled = false; });
          });
          document.getElementById('up-save').addEventListener('click', function (e) {
            var btn = e.currentTarget; btn.disabled = true;
            var payload = collect();
            var p = isNew ? API.createUpstream(payload) : API.updateUpstream(id, payload);
            p.then(function () { U.toast(isNew ? '已创建' : '已保存'); card.hidden = true; load(); })
              .catch(function (err) { U.toast(err.message, 'error'); })
              .then(function () { btn.disabled = false; });
          });

          function collect() {
            return {
              name: document.getElementById('up-name').value.trim(),
              kind: document.getElementById('up-kind').value,
              base_url: document.getElementById('up-base').value.trim(),
              api_user: document.getElementById('up-user').value.trim(),
              financial_id: document.getElementById('up-fin').value.trim(),
              account_type: document.getElementById('up-acct').value,
              use_https: parseInt(document.getElementById('up-https').value, 10),
              enabled: parseInt(document.getElementById('up-enabled').value, 10),
              api_key: document.getElementById('up-key').value,
              remark: document.getElementById('up-remark').value.trim(),
            };
          }
        }).catch(function (err) { U.toast(err.message, 'error'); });
      }

      load();
    }
  };

  /* ============================================================
   * 2. 魔方云 · 云主机
   * ============================================================ */
  var cloudVms = {
    title: '魔方云 · 云主机',
    render: function (host) {
      host.innerHTML =
        '<div class="notice"><span>已开通的云主机实例列表。开机 / 关机 / 重启 / 重置密码等操作会通过实例绑定的上游接口下发到魔方云。</span></div>'
        + '<div class="panel-card"><div class="panel-card__head"><h2>云主机实例</h2></div>'
        + '<div class="panel-card__body" id="vm-list">' + loadingBox() + '</div></div>';
      var listEl = document.getElementById('vm-list');
      function load() {
        API.cloudVms().then(function (r) {
          var items = (r && r.items) || [];
          if (!items.length) {
            listEl.innerHTML = stateBox('暂无云主机', '用户购买魔方云商品并支付后，云主机实例会自动出现在这里。');
            return;
          }
          listEl.innerHTML = '<div class="tbl-wrap"><table class="tbl"><thead><tr>'
            + '<th>实例</th><th>IP</th><th>配置</th><th>订单</th><th>状态</th><th>操作</th>'
            + '</tr></thead><tbody>' + items.map(function (v) {
              var spec = [v.cpu, v.mem, v.disk, v.bandwidth].filter(Boolean).join(' / ');
              return '<tr>'
                + '<td>' + U.esc(v.instance_name || v.external_id || ('#' + v.id)) + '<div class="hint">' + U.esc(v.os || '') + '</div></td>'
                + '<td>' + U.esc(v.ip || '—') + '</td>'
                + '<td>' + U.esc(spec || '—') + '</td>'
                + '<td>' + U.esc(v.order_no || '—') + '</td>'
                + '<td>' + statusBadge(v.status, { running: '运行中', stopped: '已停止', pending: '待开通', creating: '开通中', failed: '失败', cancelled: '已取消' }) + '</td>'
                + '<td>'
                + '<button class="btn btn--sm btn--ghost vm-act" data-id="' + v.id + '" data-act="start" type="button">开机</button> '
                + '<button class="btn btn--sm btn--ghost vm-act" data-id="' + v.id + '" data-act="stop" type="button">关机</button> '
                + '<button class="btn btn--sm btn--ghost vm-act" data-id="' + v.id + '" data-act="restart" type="button">重启</button> '
                + '<button class="btn btn--sm btn--ghost vm-act" data-id="' + v.id + '" data-act="reset-password" type="button">重置密码</button>'
                + '</td></tr>';
            }).join('') + '</tbody></table></div>';
          listEl.querySelectorAll('.vm-act').forEach(function (b) {
            b.addEventListener('click', function () {
              var id = parseInt(b.getAttribute('data-id'), 10);
              var act = b.getAttribute('data-act');
              var opts = {};
              if (act === 'reset-password') {
                var np = window.prompt('输入新密码（留空由系统生成）：', '');
                if (np === null) return;
                if (np) opts.password = np;
              }
              if (!window.confirm('确定执行「' + act + '」？')) return;
              b.disabled = true;
              API.cloudVmAction(id, act, opts).then(function (r) {
                U.toast(r.detail || '操作成功', r.ok ? 'success' : 'error');
                load();
              }).catch(function (err) { U.toast(err.message, 'error'); })
                .then(function () { b.disabled = false; });
            });
          });
        }).catch(function (err) { listEl.innerHTML = stateBox('加载失败', err.message); });
      }
      load();
    }
  };

  /* ============================================================
   * 3. 工单管理
   * ============================================================ */
  var tickets = {
    title: '工单管理',
    render: function (host) {
      host.innerHTML =
        '<div class="panel-card"><div class="panel-card__head"><h2>工单列表</h2>'
        + '<div class="panel-card__actions"><select class="field" id="tk-filter" style="display:inline-block;width:auto">'
        + '<option value="">全部状态</option><option value="open">待处理</option><option value="processing">处理中</option>'
        + '<option value="replied">已回复</option><option value="closed">已关闭</option>'
        + '</select></div></div>'
        + '<div class="panel-card__body" id="tk-list">' + loadingBox() + '</div></div>'
        + '<div class="panel-card" id="tk-detail-card" hidden>'
        + '<div class="panel-card__head"><h2 id="tk-detail-title">工单详情</h2></div>'
        + '<div class="panel-card__body" id="tk-detail"></div></div>';
      var listEl = document.getElementById('tk-list');
      var detailCard = document.getElementById('tk-detail-card');
      var detailEl = document.getElementById('tk-detail');
      var filter = document.getElementById('tk-filter');

      function load() {
        API.tickets(filter.value ? { status: filter.value } : {}).then(function (r) {
          var items = (r && r.items) || [];
          if (!items.length) {
            listEl.innerHTML = stateBox('暂无工单', '用户提交的工单会显示在这里。');
            return;
          }
          listEl.innerHTML = '<div class="tbl-wrap"><table class="tbl"><thead><tr>'
            + '<th>编号</th><th>标题</th><th>用户</th><th>分类</th><th>状态</th><th>时间</th><th>操作</th>'
            + '</tr></thead><tbody>' + items.map(function (t) {
              return '<tr>'
                + '<td>' + U.esc(t.ticket_no) + '</td>'
                + '<td>' + U.esc(t.title) + '</td>'
                + '<td>' + U.esc(t.username || ('#' + t.user_id)) + '</td>'
                + '<td>' + U.esc(t.category_label) + '</td>'
                + '<td>' + statusBadge(t.status, { open: '待处理', processing: '处理中', replied: '已回复', closed: '已关闭' }) + '</td>'
                + '<td>' + U.esc((t.created_at || '').slice(0, 16)) + '</td>'
                + '<td><button class="btn btn--sm btn--primary tk-open" data-id="' + t.id + '" type="button">查看</button></td>'
                + '</tr>';
            }).join('') + '</tbody></table></div>';
          listEl.querySelectorAll('.tk-open').forEach(function (b) {
            b.addEventListener('click', function () {
              var id = parseInt(b.getAttribute('data-id'), 10);
              API.ticket(id).then(function (r) { renderDetail(r.item || r); })
                .catch(function (err) { U.toast(err.message, 'error'); });
            });
          });
        }).catch(function (err) { listEl.innerHTML = stateBox('加载失败', err.message); });
      }

      function renderDetail(t) {
        detailCard.hidden = false;
        document.getElementById('tk-detail-title').textContent = '工单 ' + U.esc(t.ticket_no) + ' · ' + U.esc(t.title);
        var msgs = (t.messages || []).map(function (m) {
          var isAdmin = m.role === 'admin';
          return '<div style="margin:10px 0;padding:12px;border-radius:10px;background:'
            + (isAdmin ? 'var(--brand-wash, rgba(0,0,0,.04))' : 'var(--paper)') + '">'
            + '<div class="hint">' + (isAdmin ? '客服' : '用户') + ' · ' + U.esc(m.author || '') + ' · ' + U.esc((m.created_at || '').slice(0, 16)) + '</div>'
            + '<div style="white-space:pre-wrap;margin-top:6px">' + U.esc(m.body) + '</div></div>';
        }).join('');
        detailEl.innerHTML =
          '<div class="hint" style="margin-bottom:10px">'
          + '分类：' + U.esc(t.category_label) + ' · 优先级：' + U.esc(t.priority)
          + ' · 状态：' + (t.status_label || t.status)
          + (t.order_no ? ' · 关联订单：' + U.esc(t.order_no) : '')
          + '</div>'
          + '<div>' + (msgs || '<div class="hint">暂无消息</div>') + '</div>'
          + '<div style="margin-top:14px">'
          + '<textarea class="field" id="tk-reply" rows="4" placeholder="输入回复内容…"></textarea>'
          + '<div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
          + '<button class="btn btn--primary" id="tk-send" type="button">回复</button>'
          + '<select class="field" id="tk-status" style="display:inline-block;width:auto">'
          + ['open', 'processing', 'replied', 'closed'].map(function (s) {
            return '<option value="' + s + '"' + (t.status === s ? ' selected' : '') + '>'
              + ({ open: '待处理', processing: '处理中', replied: '已回复', closed: '已关闭' }[s]) + '</option>';
          }).join('') + '</select>'
          + '<button class="btn btn--ghost" id="tk-setstatus" type="button">更新状态</button>'
          + '</div></div>';
        detailCard.scrollIntoView({ behavior: 'smooth', block: 'start' });

        document.getElementById('tk-send').addEventListener('click', function () {
          var body = document.getElementById('tk-reply').value.trim();
          if (!body) { U.toast('请填写回复内容', 'error'); return; }
          var btn = this; btn.disabled = true;
          API.ticketReply(t.id, body).then(function () {
            U.toast('已回复'); renderDetail(Object.assign({}, t, { status: 'replied' }));
            // 重新拉取最新详情
            API.ticket(t.id).then(function (r2) { renderDetail(r2.item || r2); });
            load();
          }).catch(function (err) { U.toast(err.message, 'error'); })
            .then(function () { btn.disabled = false; });
        });
        document.getElementById('tk-setstatus').addEventListener('click', function () {
          var status = document.getElementById('tk-status').value;
          var btn = this; btn.disabled = true;
          API.ticketStatus(t.id, status).then(function (r) { U.toast('状态已更新'); renderDetail(r.item || r); load(); })
            .catch(function (err) { U.toast(err.message, 'error'); })
            .then(function () { btn.disabled = false; });
        });
      }

      filter.addEventListener('change', load);
      load();
    }
  };

  V.upstreams = upstreams;
  V.cloudVms = cloudVms;
  V.tickets = tickets;
})();
