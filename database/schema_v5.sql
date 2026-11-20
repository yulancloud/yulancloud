-- =============================================================
-- 欲蓝财务 YULAN · SQLite 数据库结构 v5 增量
-- 在 v4 结构（database/schema.sql）之上追加「账号体系 / 余额 / 发货内容 / 充值单」
-- 金额一律以「分」为整数存储
--
-- 执行方式：
--   全新安装：php bin/init_db.php        （先跑 schema.sql，再跑本文件）
--   已有 v4 库升级：php bin/migrate.php  （幂等，可重复执行）
-- =============================================================

PRAGMA foreign_keys = ON;

-- ---------- 前台用户 ----------
-- 与后台 admin_users 完全独立：两套登录、两套会话、两套 Cookie
CREATE TABLE IF NOT EXISTS users (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  username             TEXT NOT NULL UNIQUE,          -- 登录名，唯一
  email                TEXT NOT NULL DEFAULT '',      -- 可选，仅作联系用
  password_hash        TEXT NOT NULL,                 -- Argon2id（不可用时退化 bcrypt）
  display_name         TEXT NOT NULL DEFAULT '',
  balance_cents        INTEGER NOT NULL DEFAULT 0 CHECK (balance_cents >= 0),
  status               TEXT NOT NULL DEFAULT 'active'
                       CHECK (status IN ('active', 'disabled')),
  must_change_password INTEGER NOT NULL DEFAULT 0 CHECK (must_change_password IN (0, 1)),
  created_at           TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at           TEXT NOT NULL DEFAULT (datetime('now')),
  last_login_at        TEXT,
  last_login_ip_hash   TEXT NOT NULL DEFAULT '',
  password_changed_at  TEXT
);
-- 与 UserAuth 的 lower(username) 查重保持一致：大小写变体不能共存，
-- 否则登录 LIMIT 1 会让其中一个账号永远登不进去。
CREATE UNIQUE INDEX IF NOT EXISTS uniq_users_username_nocase ON users (username COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_users_status  ON users (status, id DESC);
CREATE INDEX IF NOT EXISTS idx_users_created ON users (created_at DESC);

-- ---------- 前台会话（只存 token 的 SHA-256） ----------
CREATE TABLE IF NOT EXISTS user_sessions (
  token_hash   TEXT PRIMARY KEY,
  user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at   INTEGER NOT NULL,
  last_seen_at INTEGER NOT NULL,
  expires_at   INTEGER NOT NULL,
  ip_hash      TEXT NOT NULL DEFAULT '',
  ua_hash      TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_user_sessions_user ON user_sessions (user_id, expires_at);

-- ---------- 余额流水 ----------
-- 每一次余额变动都必须落一条流水，记录变动前后余额与关联订单号，便于对账与追责。
-- 幂等：ref_type + ref_id 唯一（同一笔订单/充值单只能产生一条对应类型的流水）
CREATE TABLE IF NOT EXISTS balance_ledger (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  type           TEXT NOT NULL
                 CHECK (type IN ('recharge', 'consume', 'refund', 'admin_adjust')),
  amount_cents   INTEGER NOT NULL,                    -- 正数=入账，负数=出账
  balance_before INTEGER NOT NULL CHECK (balance_before >= 0),
  balance_after  INTEGER NOT NULL CHECK (balance_after >= 0),
  order_no       TEXT NOT NULL DEFAULT '',            -- 关联订单号（消费/退款）
  ref_type       TEXT NOT NULL DEFAULT '',            -- order / recharge / manual
  ref_id         TEXT NOT NULL DEFAULT '',            -- 关联主键（订单号 / 充值单号）
  remark         TEXT NOT NULL DEFAULT '',
  operator       TEXT NOT NULL DEFAULT '',            -- 后台调整时记录管理员
  created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_ledger_user  ON balance_ledger (user_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_ledger_order ON balance_ledger (order_no);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_ledger_ref
  ON balance_ledger (user_id, type, ref_type, ref_id) WHERE ref_id <> '';

-- ---------- 充值单 ----------
-- 用户充值走易支付：先建充值单 → 支付 → 回调入账（幂等）
CREATE TABLE IF NOT EXISTS recharge_orders (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  recharge_no  TEXT NOT NULL UNIQUE,                  -- RC + 日期 + 随机
  user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  amount_cents INTEGER NOT NULL CHECK (amount_cents > 0),
  status       TEXT NOT NULL DEFAULT 'pending'
               CHECK (status IN ('pending', 'paid', 'cancelled')),
  channel      TEXT NOT NULL DEFAULT '',
  out_trade_no TEXT NOT NULL DEFAULT '',              -- 提交给网关的商户单号
  trade_no     TEXT NOT NULL DEFAULT '',
  paid_at      TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_recharge_user ON recharge_orders (user_id, id DESC);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_recharge_out_trade_no
  ON recharge_orders (out_trade_no) WHERE out_trade_no <> '';

-- ---------- 订单发货内容 ----------
-- 虚拟商品：支付成功后按商品模板生成内容写入这里，用户在我的订单里查看
-- 实体商品：不写内容，走「待发货 → 已发货」由管理员处理
CREATE TABLE IF NOT EXISTS order_deliveries (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id     INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  order_no     TEXT NOT NULL,
  order_item_id INTEGER,                              -- 对应 order_items.id（可空）
  product_id   TEXT NOT NULL DEFAULT '',
  product_name TEXT NOT NULL DEFAULT '',
  kind         TEXT NOT NULL DEFAULT 'virtual'
               CHECK (kind IN ('virtual', 'physical', 'yulan')),
  content      TEXT NOT NULL DEFAULT '',              -- 多行文本：每行「字段名：值」
  status       TEXT NOT NULL DEFAULT 'delivered'
               CHECK (status IN ('pending', 'delivered', 'revoked')),
  delivered_at TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_deliveries_order ON order_deliveries (order_id, id);
CREATE INDEX IF NOT EXISTS idx_deliveries_no    ON order_deliveries (order_no);

-- ---------- 商品发货模板 ----------
-- 每个虚拟商品可配多行字段：字段名 + 取值（取值支持 {order_no} {date} 等占位符）
CREATE TABLE IF NOT EXISTS product_delivery_fields (
  product_id TEXT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  label      TEXT NOT NULL,                           -- 字段名，如「IP」「账号」「密码」
  value      TEXT NOT NULL DEFAULT '',                -- 取值或占位符
  sort       INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, label)
);
CREATE INDEX IF NOT EXISTS idx_delivery_fields_product
  ON product_delivery_fields (product_id, sort);

-- ---------- 订单状态流转事件（v5.1） ----------
-- 每次状态推进写一条，用于对账、排障与追责；只追加不修改
CREATE TABLE IF NOT EXISTS order_events (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id    INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  order_no    TEXT NOT NULL DEFAULT '',
  from_status TEXT NOT NULL DEFAULT '',
  to_status   TEXT NOT NULL DEFAULT '',
  actor       TEXT NOT NULL DEFAULT 'system',   -- system / gateway / user:12 / admin:admin
  reason      TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_order_events_order ON order_events (order_id, id);
CREATE INDEX IF NOT EXISTS idx_order_events_no    ON order_events (order_no, id);
