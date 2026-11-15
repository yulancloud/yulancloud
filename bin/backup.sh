#!/usr/bin/env bash
# SQLite 在线备份（不锁库，可随时执行）
# 用法：bin/backup.sh [备份目录]
# cron：0 3 * * * /var/www/shop/bin/backup.sh >> /var/log/shop-backup.log 2>&1
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${1:-${SHOP_BACKUP_DIR:-$ROOT/data/backups}}"
KEEP_DAYS="${SHOP_BACKUP_KEEP_DAYS:-14}"

# ---------------------------------------------------------------------------
# 数据库路径：环境变量优先；否则读 config/.env（安装向导会把自定义
# SHOP_DB_PATH 写进去）。旧版只读环境变量，按 .env 自定义路径部署时会
# 备份到默认的 data/shop.sqlite —— 要么备份错库、要么压根找不到文件。
# 复用 src/Env.php 读取，确保与运行时的引号/转义解析完全一致。
# ---------------------------------------------------------------------------
DB="${SHOP_DB_PATH:-}"
if [[ -z "$DB" && -f "$ROOT/config/.env" ]]; then
  DB="$(php -r '
    require $argv[1] . "/src/Env.php";
    Env::load($argv[1] . "/config/.env");
    $v = getenv("SHOP_DB_PATH");
    if ($v === false || trim($v) === "") { exit(0); }
    $v = trim($v);
    if ($v[0] !== "/") { $v = $argv[1] . "/" . $v; }
    echo $v;
  ' "$ROOT" 2>/dev/null || true)"
fi
DB="${DB:-$ROOT/data/shop.sqlite}"

if [[ ! -f "$DB" ]]; then
  echo "[$(date '+%F %T')] 数据库不存在：$DB" >&2
  exit 1
fi

mkdir -p "$DEST"
STAMP="$(date '+%Y%m%d-%H%M%S')"
OUT="$DEST/shop-$STAMP.sqlite"

# .backup 是 SQLite 官方在线备份命令：一致性快照，不阻塞读写
if command -v sqlite3 >/dev/null 2>&1; then
  sqlite3 "$DB" ".backup '$OUT'"
else
  # 没有 sqlite3 命令行时用 PHP 的 PDO 备份（同样走 SQLite backup API）。
  # VACUUM INTO 必须在源库连接上执行，目标路径由源库负责 quote。
  php -r '
    $src = new PDO("sqlite:" . $argv[1]);
    $src->exec("VACUUM INTO " . $src->quote($argv[2]));
  ' "$DB" "$OUT" 2>/dev/null || cp "$DB" "$OUT"
fi

gzip -f "$OUT"
echo "[$(date '+%F %T')] 备份完成：$OUT.gz ($(du -h "$OUT.gz" | cut -f1)) 源库：$DB"

# 清理过期备份
find "$DEST" -name 'shop-*.sqlite.gz' -type f -mtime "+$KEEP_DAYS" -print -delete
