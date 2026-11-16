/* =============================================================
 * admin/assets/views-features.js —— v1.2.1 功能插件配套视图
 *
 * 提供两个后台页面（对应两个功能插件）：
 *  - mailConfig：邮件服务器配置（smtp-login 插件）
 *  - admins：多账号管理（multi-admin 插件）
 *
 * 约定与 views.js 相同：视图导出 { title, render(host) }，
 * 所有服务端文本进 innerHTML 前必须过 U.esc()。
 * 本文件在 views.js 之后、app.js 之前加载，往 window.AdminViews 挂载。
 * 仅使用 admin.css / style.css 里已有的类（panel-card / tbl-wrap / tbl /
 * badge / form-grid / drawer 等），不引入新样式文件。
 * ============================================================= */
(function () {
  'use strict';
  var API = window.AdminAPI;
  var U = window.AdminU;
  if (!API || !U) { return; }

  function esc(s) { return U.esc(s); }
  function stateBox(title, sub) {
    return '<div class="state"><b>' + esc(title) + '</b>' + (sub ? '<br>' + esc(sub) : '') + '</div>';
  }
  function fld(label, inputHtml, hint) {
    return '<div class="fld"><label>' + esc(label) + '</label>' + inputHtml +
           (hint ? '<div class="hint">' + esc(hint) + '</div>' : '') + '</div>';
  }
  function msg(boxId, text, ok) {
    var box = document.getElementById(boxId);
    if (!box) { return; }
    box.style.display = '';
    box.textContent = text;
    box.style.borderLeftColor = ok ? 'var(--accent)' : '#d64545';
  }

  /* =============================================================
   * 邮件服务器配置
   * ============================================================= */
  var mailConfig = {
    title: '邮件服务器配置',
    render: function (host) {
      host.innerHTML = stateBox('加载中', '正在读取邮件配置');
      API.mailConfig().then(function (r) {
        var c = r.data || r;
        var encOptions = [
          { v: 'tls', t: 'STARTTLS（端口 587，推荐）' },
          { v: 'ssl', t: 'SSL / TLS（端口 465）' },
          { v: 'none', t: '不加密（端口 25，不推荐）' }
        ].map(function (o) {
          return '<option value="' + o.v + '"' + (c.encryption === o.v ? ' selected' : '') + '>' + esc(o.t) + '</option>';
        }).join('');
        host.innerHTML =
          '<div class="panel-card">' +
            '<div class="panel-card__head"><h2>SMTP 邮件服务器</h2></div>' +
            '<div class="panel-card__body">' +
              '<div class="notice" style="margin-top:0;margin-bottom:16px"><span>' +
                '用于后台「邮箱验证码登录」发信。密码只写不读：留空表示不修改已保存的密码。' +
                '需在下方登记管理员邮箱，且要与「多账号管理」里管理员的邮箱一致。' +
              '</span></div>' +
              '<form id="mail-form">' +
                '<div class="form-grid" style="grid-template-columns:1fr 1fr">' +
                  fld('SMTP 主机', '<input class="field" id="mail-host" maxlength="253" value="' + esc(c.host || '') + '" placeholder="smtp.example.com">', '如 smtp.qq.com') +
                  fld('端口', '<input class="field" id="mail-port" type="number" min="1" max="65535" value="' + esc(String(c.port || 587)) + '">', 'TLS 587 / SSL 465') +
                  fld('加密方式', '<select class="field" id="mail-enc">' + encOptions + '</select>') +
                  fld('SMTP 账号', '<input class="field" id="mail-user" maxlength="128" autocomplete="off" value="' + esc(c.username || '') + '">', '通常是完整邮箱地址') +
                  fld('SMTP 密码 / 授权码', '<input class="field" id="mail-pass" type="password" maxlength="256" autocomplete="new-password" placeholder="' + (c.password_set ? '已设置，留空保持不变' : '未设置') + '">', 'QQ/163 邮箱请填授权码') +
                  fld('发件人邮箱', '<input class="field" id="mail-from" maxlength="128" value="' + esc(c.from_email || '') + '" placeholder="留空则用 SMTP 主机">') +
                  fld('发件人名称', '<input class="field" id="mail-fromname" maxlength="60" value="' + esc(c.from_name || '') + '" placeholder="如 欲蓝财务">') +
                '</div>' +
                fld('允许登录的邮箱', '<textarea class="field" id="mail-allowed" rows="4" placeholder="每行一个邮箱">' + esc((c.allowed_emails || []).join('\n')) + '</textarea>', '只有这些邮箱能收到验证码并登录后台，每行一个，最多 50 个') +
                '<div class="toolbar" style="margin-top:14px">' +
                  '<button class="btn btn--primary" id="mail-save" type="submit">保存配置</button>' +
                  '<button class="btn btn--ghost" id="mail-test" type="button">发送测试邮件</button>' +
                '</div>' +
              '</form>' +
              '<div id="mail-result" class="notice" style="display:none"></div>' +
            '</div>' +
          '</div>';

        function collect() {
          return {
            host: document.getElementById('mail-host').value.trim(),
            port: parseInt(document.getElementById('mail-port').value, 10) || 587,
            encryption: document.getElementById('mail-enc').value,
            username: document.getElementById('mail-user').value.trim(),
            password: document.getElementById('mail-pass').value,
            from_email: document.getElementById('mail-from').value.trim(),
            from_name: document.getElementById('mail-fromname').value.trim(),
            allowed_emails: document.getElementById('mail-allowed').value.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean)
          };
        }
        document.getElementById('mail-form').addEventListener('submit', function (e) {
          e.preventDefault();
          var btn = document.getElementById('mail-save');
          btn.disabled = true;
          API.saveMailConfig(collect()).then(function () {
            msg('mail-result', '配置已保存', true);
            document.getElementById('mail-pass').value = '';
          }).catch(function (err) {
            msg('mail-result', err.message || '保存失败', false);
          }).then(function () { btn.disabled = false; });
        });
        document.getElementById('mail-test').addEventListener('click', function () {
          var to = (document.getElementById('mail-allowed').value.split(/\r?\n/)[0] || '').trim();
          if (!to) { msg('mail-result', '请先填写一个允许登录的邮箱用于测试', false); return; }
          var btn = document.getElementById('mail-test');
          btn.disabled = true;
          API.sendMailTest(to).then(function () {
            msg('mail-result', '测试邮件已发送至 ' + to, true);
          }).catch(function (err) {
            msg('mail-result', '发送失败：' + (err.message || '未知错误'), false);
          }).then(function () { btn.disabled = false; });
        });
      }).catch(function (err) {
        host.innerHTML = stateBox('无法读取邮件配置', err.message || '请确认 smtp-login 插件已启用');
      });
    }
  };

  /* =============================================================
   * 多账号管理
   * ============================================================= */
  var admins = {
    title: '多账号管理',
    render: function (host) {
      host.innerHTML = stateBox('加载中', '正在读取管理员列表');
      function load() {
        API.admins().then(function (r) {
          var items = (r.data || r).items || [];
          var rows = items.map(function (a) {
            var roleTag = a.role === 'owner'
              ? '<span class="badge badge--accent">店主</span>' : '<span class="badge">店员</span>';
            var statusTag = a.status === 'active'
              ? '<span class="badge badge--ok">启用</span>' : '<span class="badge badge--warn">停用</span>';
            return '<tr>' +
              '<td class="tbl__name"><b>' + esc(a.username) + '</b><div class="tbl__sub">' + esc(a.display_name || '') + '</div></td>' +
              '<td>' + esc(a.email || '—') + '</td>' +
              '<td>' + roleTag + '</td>' +
              '<td>' + statusTag + '</td>' +
              '<td>' + esc((a.last_login_at || '从未登录').replace('T', ' ')) + '</td>' +
              '<td class="tbl__actions">' +
                '<button class="btn btn--sm btn--ghost" data-act="edit" data-id="' + a.id + '">编辑</button> ' +
                '<button class="btn btn--sm btn--ghost" data-act="reset" data-id="' + a.id + '">重置密码</button> ' +
                '<button class="btn btn--sm btn--ghost" data-act="toggle" data-id="' + a.id + '" data-status="' + esc(a.status) + '">' + (a.status === 'active' ? '停用' : '启用') + '</button> ' +
                '<button class="btn btn--sm btn--ghost" data-act="del" data-id="' + a.id + '">删除</button>' +
              '</td>' +
            '</tr>';
          }).join('');
          host.innerHTML =
            '<div class="panel-card">' +
              '<div class="panel-card__head"><h2>后台管理员账号</h2><span class="spacer"></span>' +
                '<button class="btn btn--primary btn--sm" id="admin-add" type="button">新增管理员</button></div>' +
              '<div class="panel-card__body">' +
                '<div class="notice" style="margin-top:0;margin-bottom:16px"><span>' +
                  '店主可新增多个店员账号分担管理。新增 / 重置会得到一次性密码，请立即复制保存——关闭后不再显示。' +
                  '不能删除或停用当前账号与最后一个店主。' +
                '</span></div>' +
                '<div id="admin-msg" class="notice" style="display:none"></div>' +
                '<div class="tbl-wrap"><table class="tbl"><thead><tr>' +
                  '<th>账号</th><th>邮箱</th><th>角色</th><th>状态</th><th>最近登录</th><th>操作</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table></div>' +
              '</div>' +
            '</div>';
          bind(load);
        }).catch(function (err) {
          host.innerHTML = stateBox('无法读取管理员列表', err.message || '请确认 multi-admin 插件已启用');
        });
      }
      function bind(load) {
        document.getElementById('admin-add').addEventListener('click', function () { openEditor(null, load); });
        host.querySelectorAll('button[data-act]').forEach(function (b) {
          b.addEventListener('click', function () {
            var id = parseInt(b.getAttribute('data-id'), 10);
            var act = b.getAttribute('data-act');
            if (act === 'edit') { openEditor(id, load); return; }
            if (act === 'reset') {
              if (!U.confirm('确定重置该管理员密码？其现有会话会全部失效。')) return;
              API.resetAdminPassword(id).then(function (r) {
                var d = r.data || r;
                U.toast('一次性密码：' + (d.new_password || ''), 'success');
                window.alert('密码已重置\n\n账号：' + (d.username || '') + '\n一次性密码：' + (d.new_password || '') +
                  '\n\n请立即复制保存，此密码只显示这一次。首次登录后系统会要求修改。');
                load();
              }).catch(function (e) { msg('admin-msg', e.message || '操作失败', false); });
            } else if (act === 'toggle') {
              var next = b.getAttribute('data-status') === 'active' ? 'disabled' : 'active';
              API.setAdminStatus(id, next).then(function () { load(); })
                .catch(function (e) { msg('admin-msg', e.message || '操作失败', false); });
            } else if (act === 'del') {
              if (!U.confirm('确定删除该管理员？此操作不可恢复。')) return;
              API.deleteAdmin(id).then(function () { load(); })
                .catch(function (e) { msg('admin-msg', e.message || '操作失败', false); });
            }
          });
        });
      }
      function openEditor(id, load) {
        API.admins().then(function (r) {
          var items = (r.data || r).items || [];
          var a = id ? items.find(function (x) { return x.id === id; }) : null;
          var isEdit = !!a;
          var mask = document.createElement('div');
          mask.className = 'drawer-mask';
          mask.innerHTML =
            '<div class="drawer">' +
              '<div class="drawer__head"><h2>' + (isEdit ? '编辑管理员' : '新增管理员') + '</h2>' +
                '<button class="icon-btn" data-close type="button">×</button></div>' +
              '<div class="drawer__body">' +
                (isEdit ? '' : fld('账号', '<input class="field" id="ae-user" maxlength="32" placeholder="3–32 位小写字母/数字/点/下划线/连字符">')) +
                fld('显示名称', '<input class="field" id="ae-display" maxlength="40" value="' + esc(a ? a.display_name : '') + '">') +
                fld('登录邮箱', '<input class="field" id="ae-email" maxlength="128" value="' + esc(a ? (a.email || '') : '') + '" placeholder="用于邮箱验证码登录，可留空">') +
                fld('角色', '<select class="field" id="ae-role">' +
                  '<option value="staff"' + (!a || a.role === 'staff' ? ' selected' : '') + '>店员</option>' +
                  '<option value="owner"' + (a && a.role === 'owner' ? ' selected' : '') + '>店主</option></select>') +
                '<div id="ae-err" class="notice" style="display:none"></div>' +
              '</div>' +
              '<div class="drawer__foot">' +
                '<button class="btn btn--ghost" data-close type="button">取消</button>' +
                '<button class="btn btn--primary" id="ae-save" type="button">保存</button>' +
              '</div>' +
            '</div>';
          document.body.appendChild(mask);
          function close() { mask.remove(); }
          mask.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', close); });
          document.getElementById('ae-save').addEventListener('click', function () {
            var data = {
              display_name: document.getElementById('ae-display').value.trim(),
              email: document.getElementById('ae-email').value.trim(),
              role: document.getElementById('ae-role').value
            };
            if (!isEdit) { data.username = document.getElementById('ae-user').value.trim(); }
            var btn = this; btn.disabled = true;
            var call = isEdit ? API.updateAdmin(id, data) : API.createAdmin(data);
            call.then(function (res) {
              close();
              if (!isEdit) {
                var d = res.data || res;
                window.alert('管理员已创建\n\n账号：' + (d.username || '') + '\n一次性密码：' + (d.initial_password || '') +
                  '\n\n请立即复制保存，此密码只显示这一次。首次登录后系统会要求修改。');
              } else {
                U.toast('已保存', 'success');
              }
              load();
            }).catch(function (e) {
              var box = document.getElementById('ae-err');
              box.style.display = ''; box.textContent = (e && e.message) || '保存失败';
            }).then(function () { btn.disabled = false; });
          });
        }).catch(function (e) { msg('admin-msg', e.message || '打开编辑器失败', false); });
      }
      load();
    }
  };

  window.AdminViews = window.AdminViews || {};
  window.AdminViews.mailConfig = mailConfig;
  window.AdminViews.admins = admins;
})();
