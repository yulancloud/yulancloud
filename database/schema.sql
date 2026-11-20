-- =============================================================
-- 欲蓝财务 YULAN · SQLite 数据库结构 v2
-- 金额一律以「分」为整数存储（price_cents），避免浮点误差
-- 执行：php bin/init_db.php（全新安装） / php bin/migrate.php（升级已有库）
-- =============================================================

PRAGMA foreign_keys = ON;

-- ---------- 元信息 ----------
CREATE TABLE IF NOT EXISTS schema_meta (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

-- ---------- 分类 ----------
CREATE TABLE IF NOT EXISTS categories (
  id     TEXT PRIMARY KEY,
  label  TEXT NOT NULL,
  sort   INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'hidden'))
);

-- ---------- 商品 ----------
CREATE TABLE IF NOT EXISTS products (
  id          TEXT PRIMARY KEY,
  sku         TEXT NOT NULL UNIQUE,
  name        TEXT NOT NULL,
  sub         TEXT NOT NULL DEFAULT '',
  cat_id      TEXT NOT NULL REFERENCES categories(id) ON UPDATE CASCADE,
  price_cents INTEGER NOT NULL CHECK (price_cents >= 0),
  was_cents   INTEGER CHECK (was_cents IS NULL OR was_cents >= price_cents),
  badge       TEXT NOT NULL DEFAULT '',
  img         TEXT NOT NULL DEFAULT '',
  rating      REAL NOT NULL DEFAULT 0 CHECK (rating >= 0 AND rating <= 5),
  sold        INTEGER NOT NULL DEFAULT 0 CHECK (sold >= 0),
  stock       INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
  description TEXT NOT NULL DEFAULT '',
  story       TEXT NOT NULL DEFAULT '',
  -- v1.1：商品缺点 / 已知不足（多行文本，每行一条；留空则前台详情页整栏隐藏）
  -- 后台「编辑商品」里可逐件填写，不再写死在 product.html 的演示文案里。
  flaws       TEXT NOT NULL DEFAULT '',
  -- v5：商品类型。virtual=虚拟商品（支付后按模板自动发货），physical=实体商品（走待发货流程）
  -- v5.5：新增 yulan=欲蓝财务魔方商品（下单即在 Windows 服务器创建账号，按「连接地址+账号」交付）
  type        TEXT NOT NULL DEFAULT 'physical'
              CHECK (type IN ('virtual', 'physical', 'yulan')),
  -- v5：发货内容模板（多行文本，每行「字段名：取值」；取值支持 {order_no} {date} 占位符）
  delivery_template TEXT NOT NULL DEFAULT '',
  -- v5.5：欲蓝财务魔方专属配置
  -- 账号限购数量：单个用户最多可购买多少件该欲蓝商品（0 = 不限购）
  yulan_account_limit INTEGER NOT NULL DEFAULT 0 CHECK (yulan_account_limit >= 0),
  -- IP 限制开关：1 = 同时按 IP 维度限制购买数量（防止同一人开小号刷单），0 = 关闭
  yulan_ip_restrict   INTEGER NOT NULL DEFAULT 0 CHECK (yulan_ip_restrict IN (0, 1)),
  -- 商品级服务器配置：每个欲蓝商品可以指向自己的 Windows 服务器；
  -- 留空则回退到全局设置（后台「欲蓝服务器」里的默认值）。口令只写不读。
  yulan_server_ip   TEXT NOT NULL DEFAULT '',
  yulan_server_port TEXT NOT NULL DEFAULT '',
  yulan_server_user TEXT NOT NULL DEFAULT '',
  yulan_server_pass TEXT NOT NULL DEFAULT '',
  yulan_rdp_host    TEXT NOT NULL DEFAULT '',
  -- v1.3：商品绑定的「财务魔方 API」ID（指向 yulan_apis.id；0=不使用，回退商品级/全局默认）
  yulan_api_id INTEGER NOT NULL DEFAULT 0,
  status      TEXT NOT NULL DEFAULT 'active'
              CHECK (status IN ('active', 'hidden', 'soldout')),
  sort        INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_products_cat    ON products (cat_id, sort);
CREATE INDEX IF NOT EXISTS idx_products_status ON products (status, sort);

-- ---------- 颜色 / 规格 ----------
CREATE TABLE IF NOT EXISTS product_colors (
  product_id TEXT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  name       TEXT NOT NULL,
  hex        TEXT NOT NULL DEFAULT '#cccccc',
  sort       INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, name)
);

CREATE TABLE IF NOT EXISTS product_sizes (
  product_id  TEXT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  name        TEXT NOT NULL,
  extra_cents INTEGER NOT NULL DEFAULT 0 CHECK (extra_cents >= 0),
  sort        INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, name)
);

-- ---------- 参数 / 保养 ----------
CREATE TABLE IF NOT EXISTS product_specs (
  product_id TEXT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  k          TEXT NOT NULL,
  v          TEXT NOT NULL,
  sort       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_specs_product ON product_specs (product_id, sort);

CREATE TABLE IF NOT EXISTS product_care (
  product_id TEXT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  body       TEXT NOT NULL,
  sort       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_care_product ON product_care (product_id, sort);

-- ---------- 评价 ----------
CREATE TABLE IF NOT EXISTS reviews (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id TEXT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  author     TEXT NOT NULL,
  days_ago   TEXT NOT NULL DEFAULT '',
  stars      INTEGER NOT NULL CHECK (stars BETWEEN 1 AND 5),
  body       TEXT NOT NULL,
  likes      INTEGER NOT NULL DEFAULT 0 CHECK (likes >= 0),
  reply      TEXT NOT NULL DEFAULT '',
  status     TEXT NOT NULL DEFAULT 'visible' CHECK (status IN ('visible', 'hidden')),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_reviews_product ON reviews (product_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_reviews_status  ON reviews (status, id DESC);

-- ---------- 站点文案块（信息条 / 思考过程） ----------
CREATE TABLE IF NOT EXISTS site_blocks (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

-- ---------- 站点设置（后台可改的标题/副标题/版权等） ----------
-- 用 key-value 而不是固定列：以后加一项文案不用改表结构
CREATE TABLE IF NOT EXISTS site_settings (
  key        TEXT PRIMARY KEY,
  value      TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- 订单 ----------
CREATE TABLE IF NOT EXISTS orders (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  order_no         TEXT NOT NULL UNIQUE,
  access_hash      TEXT NOT NULL,              -- 查询凭证的 SHA-256，明文只在创建时返回一次
  -- v5：订单归属用户。必须登录才能下单；每个账号只能看到自己的订单
  user_id          INTEGER REFERENCES users(id) ON DELETE SET NULL,
  buyer_name       TEXT NOT NULL,
  buyer_phone      TEXT NOT NULL,
  buyer_address    TEXT NOT NULL,
  note             TEXT NOT NULL DEFAULT '',
  subtotal_cents   INTEGER NOT NULL CHECK (subtotal_cents >= 0),
  ship_cents       INTEGER NOT NULL CHECK (ship_cents >= 0),
  discount_cents   INTEGER NOT NULL CHECK (discount_cents >= 0),
  total_cents      INTEGER NOT NULL CHECK (total_cents >= 0),
  status           TEXT NOT NULL DEFAULT 'pending'
                   CHECK (status IN ('pending', 'paid', 'shipped', 'done', 'cancelled')),
  idempotency_key  TEXT UNIQUE,
  ip_hash          TEXT NOT NULL DEFAULT '',
  -- 支付相关
  paid_at          TEXT,
  paid_cents       INTEGER,
  pay_channel      TEXT NOT NULL DEFAULT '',
  pay_trade_no     TEXT NOT NULL DEFAULT '',
  -- 物流 / 取消
  shipped_at       TEXT,
  ship_company     TEXT NOT NULL DEFAULT '',
  ship_no          TEXT NOT NULL DEFAULT '',
  closed_at        TEXT,
  done_at          TEXT,
  admin_note       TEXT NOT NULL DEFAULT '',
  created_at       TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at       TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_orders_created ON orders (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_status  ON orders (status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_user    ON orders (user_id, id DESC);

CREATE TABLE IF NOT EXISTS order_items (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id   INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  product_id TEXT NOT NULL,
  sku        TEXT NOT NULL,
  name       TEXT NOT NULL,
  color      TEXT NOT NULL DEFAULT '',
  size       TEXT NOT NULL DEFAULT '',
  unit_cents INTEGER NOT NULL CHECK (unit_cents >= 0),
  qty        INTEGER NOT NULL CHECK (qty > 0),
  line_cents INTEGER NOT NULL CHECK (line_cents >= 0)
);
CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items (order_id);

-- ---------- 欲蓝财务魔方：已创建账号流水 ----------
-- 仅存「连接地址 + 账号（用户名）」，绝不存储用户下单时填写的密码（安全基线）。
-- 下单时网站向 Windows APP 下达建号命令，APP 在服务器上执行 net user / net localgroup；
-- 这里只记录成功创建的账号名与归属，供「我的订单」展示交付信息，并用于账号限购去重。
CREATE TABLE IF NOT EXISTS yulan_accounts (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id        INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  order_item_id   INTEGER NOT NULL,
  product_id      TEXT NOT NULL,
  account         TEXT NOT NULL,
  role            TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
  status          TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'created', 'failed')),
  detail          TEXT NOT NULL DEFAULT '',
  created_at      TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_yulan_accounts_order  ON yulan_accounts (order_id);
CREATE INDEX IF NOT EXISTS idx_yulan_accounts_product ON yulan_accounts (product_id, account);

-- ---------- v1.3：财务魔方 API（多条服务器连接配置，可自定义名称） ----------
-- 后台「财务魔方 API」选项卡里增删改；虚拟/欲蓝商品发货时可指定使用哪一条。
-- 口令 server_pass 只写不读（对外只回传是否配置），与 yulan_server_pass 同一安全基线。
CREATE TABLE IF NOT EXISTS yulan_apis (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL,                       -- 自定义名称，如「电信机房-A」
  server_ip   TEXT NOT NULL DEFAULT '',            -- Windows 服务器 IP / 域名
  server_port TEXT NOT NULL DEFAULT '8321',        -- APP 监听端口
  server_user TEXT NOT NULL DEFAULT '',            -- 连接鉴权账号（可选，APP 侧基本不用）
  server_pass TEXT NOT NULL DEFAULT '',            -- 连接鉴权口令（只写不读）
  rdp_host    TEXT NOT NULL DEFAULT '',            -- 交付给用户的连接地址
  enabled     INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- 支付流水 ----------
-- 每次「发起支付」记一条，回调时更新对应记录；保留原始通知用于对账与排障。
CREATE TABLE IF NOT EXISTS payments (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id     INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  order_no     TEXT NOT NULL,
  -- 提交给网关的商户订单号（order_no + 随机后缀），回调时按它精确定位流水
  out_trade_no TEXT NOT NULL DEFAULT '',
  channel      TEXT NOT NULL DEFAULT '',
  amount_cents INTEGER NOT NULL CHECK (amount_cents >= 0),
  trade_no     TEXT NOT NULL DEFAULT '',
  status       TEXT NOT NULL DEFAULT 'created'
               CHECK (status IN ('created', 'success', 'failed', 'closed')),
  fail_reason  TEXT NOT NULL DEFAULT '',
  raw_notify   TEXT NOT NULL DEFAULT '',
  ip_hash      TEXT NOT NULL DEFAULT '',
  created_at   TEXT NOT NULL DEFAULT (datetime('now')),
  paid_at      TEXT
);
CREATE INDEX IF NOT EXISTS idx_payments_order ON payments (order_id, id DESC);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_payments_out_trade_no
  ON payments (out_trade_no) WHERE out_trade_no <> '';
-- 同一个网关交易号只能落一次（部分索引，允许空串存在多条）
CREATE UNIQUE INDEX IF NOT EXISTS uniq_payments_trade_no
  ON payments (trade_no) WHERE trade_no <> '';

-- ---------- 后台管理员 ----------
CREATE TABLE IF NOT EXISTS admin_users (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  username             TEXT NOT NULL UNIQUE,
  password_hash        TEXT NOT NULL,
  display_name         TEXT NOT NULL DEFAULT '',
  role                 TEXT NOT NULL DEFAULT 'owner' CHECK (role IN ('owner', 'staff')),
  status               TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
  email                TEXT NOT NULL DEFAULT '',
  must_change_password INTEGER NOT NULL DEFAULT 0 CHECK (must_change_password IN (0, 1)),
  created_at           TEXT NOT NULL DEFAULT (datetime('now')),
  last_login_at        TEXT,
  last_login_ip_hash   TEXT NOT NULL DEFAULT '',
  password_changed_at  TEXT
);
CREATE INDEX IF NOT EXISTS idx_admin_users_email ON admin_users (email);

-- ---------- 邮箱验证码（smtp-login 功能底座） ----------
CREATE TABLE IF NOT EXISTS email_codes (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  email       TEXT NOT NULL,
  code_hash   TEXT NOT NULL,
  purpose     TEXT NOT NULL DEFAULT 'admin_login',
  expires_at  INTEGER NOT NULL,
  attempts    INTEGER NOT NULL DEFAULT 0,
  created_at  INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_email_codes_email ON email_codes (email, purpose, created_at);

-- ---------- 后台会话（只存 token 的 SHA-256） ----------
CREATE TABLE IF NOT EXISTS admin_sessions (
  token_hash   TEXT PRIMARY KEY,
  user_id      INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
  created_at   INTEGER NOT NULL,
  last_seen_at INTEGER NOT NULL,
  expires_at   INTEGER NOT NULL,
  ip_hash      TEXT NOT NULL DEFAULT '',
  ua_hash      TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_user ON admin_sessions (user_id, expires_at);

-- ---------- 后台操作审计 ----------
CREATE TABLE IF NOT EXISTS admin_audit (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER,
  username   TEXT NOT NULL DEFAULT '',
  action     TEXT NOT NULL,
  target     TEXT NOT NULL DEFAULT '',
  detail     TEXT NOT NULL DEFAULT '',
  ip_hash    TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_admin_audit_time ON admin_audit (created_at DESC);

-- ---------- 限流（固定窗口计数） ----------
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket       TEXT NOT NULL,
  window_start INTEGER NOT NULL,
  hits         INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start)
);
CREATE INDEX IF NOT EXISTS idx_rate_window ON rate_limits (window_start);
