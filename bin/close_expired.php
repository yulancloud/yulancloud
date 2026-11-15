<?php
/**
 * 关闭超时未支付订单（建议 cron 每分钟执行）
 *
 *   php bin/close_expired.php
 *
 * 行为：
 *  - 找出 status = 'pending' 且创建时间超过 payment.expire_minutes 的订单
 *  - 逐单在事务内：归还库存 → 订单置 cancelled → 关闭未完成的 payments
 *  - 幂等：只对 pending 生效，重复执行无副作用
 *
 * 安全：不接收任何外部输入，纯 CLI 脚本。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/Response.php';
require_once dirname(__DIR__) . '/src/RateLimiter.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';
require_once dirname(__DIR__) . '/src/EPay.php';
require_once dirname(__DIR__) . '/src/DeliveryService.php';
require_once dirname(__DIR__) . '/src/BalanceService.php';
require_once dirname(__DIR__) . '/src/OrderStateMachine.php';
require_once dirname(__DIR__) . '/src/PaymentService.php';

$cfg = require dirname(__DIR__) . '/config/config.php';

if (!is_file($cfg['db_path'])) {
    fwrite(STDERR, "数据库不存在：{$cfg['db_path']}\n");
    exit(1);
}

$minutes = (int)$cfg['payment']['expire_minutes'];
if ($minutes <= 0) {
    echo "未启用订单超时（payment.expire_minutes = 0），跳过。\n";
    exit(0);
}

$n = PaymentService::closeAllExpired(200);
echo '[' . date('Y-m-d H:i:s') . "] 关闭超时订单：{$n} 单（超时阈值 {$minutes} 分钟）\n";
exit(0);
