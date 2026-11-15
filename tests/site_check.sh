#!/usr/bin/env bash
# 全站路由体检：逐个请求页面与 API，输出状态码，标出异常项
set -uo pipefail
BASE="${1:-http://127.0.0.1:8090}"
BAD=0

chk() { # path expect
  local p="$1" exp="$2" code
  code=$(curl -sS -o /tmp/_b -w '%{http_code}' "$BASE$p")
  if [[ "$code" == "$exp" ]]; then
    printf '  \033[32m✓\033[0m %-34s %s\n' "$p" "$code"
  else
    printf '  \033[31m✗\033[0m %-34s %s (期望 %s)\n' "$p" "$code" "$exp"
    BAD=$((BAD+1))
  fi
}

echo "== 前台页面 =="
for p in / /index.html /cart.html /order.html /product.html /pay-result.html; do chk "$p" 200; done

echo "== 后台 / 用户中心 / 安装 =="
for p in /admin /admin/ /admin/login.html /console /console/ /install /install.html; do chk "$p" 200; done

echo "== 公开 API =="
for p in /api/health /api/site /api/catalog /api/products; do chk "$p" 200; done

echo "== 未登录应 401 =="
for p in /api/console/me /api/admin/me; do chk "$p" 401; done

echo "== 敏感文件应 404（先造一个真实存在的文件，避免恒真断言） =="
# 旧版对 public/ 下本就不存在的 6 个路径断言 404，无论拦截规则是否漏配都会通过。
# 这里先在 Web 根写入真实文件，能挡住才说明 Nginx / Apache / router.php 的拦截生效。
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROBE_DIR="$ROOT/public/config"
PROBE_FILE="$PROBE_DIR/.env"
mkdir -p "$PROBE_DIR"
printf 'SHOP_SECRET=probe-value-must-not-leak\n' > "$PROBE_FILE"
trap 'rm -f "$PROBE_FILE"; rmdir "$PROBE_DIR" 2>/dev/null || true' EXIT
if [[ ! -f "$PROBE_FILE" ]]; then
  printf '  \033[31m✗\033[0m 探测文件创建失败（无法验证拦截规则）\n'; BAD=$((BAD+1))
fi
chk "/config/.env" 404
chk "/config/.env.example" 404

echo "== PHP 源码绝不能被当静态文件返回 =="
for p in /api/index.php /preflight.php /router.php; do
  code=$(curl -sS -o /tmp/_b -w '%{http_code}' "$BASE$p")
  if grep -q '<?php' /tmp/_b; then
    printf '  \033[31m✗\033[0m %-34s 返回了 PHP 源码 (%s)\n' "$p" "$code"; BAD=$((BAD+1))
  else
    printf '  \033[32m✓\033[0m %-34s 未泄露源码 (%s)\n' "$p" "$code"
  fi
done

echo
if [[ $BAD -eq 0 ]]; then echo "全部通过"; else echo "异常 $BAD 项"; fi
exit $BAD
