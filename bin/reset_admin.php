<?php
/**
 * 重置管理员密码
 *
 *   php bin/reset_admin.php                 # 重置 admin，生成随机密码
 *   php bin/reset_admin.php --user=alice    # 指定用户名
 *   php bin/reset_admin.php --password=xxx  # 指定密码（与后台同一套强度规则）
 *
 * 安全：
 *  - 仅 CLI 可执行
 *  - 随机密码 16 位，含大小写字母与数字
 *  - 指定密码走 AdminAuth::passwordProblem()，与网页安装 / 后台改密一致
 *  - 重置后删除该管理员所有会话，并标记「下次登录必须修改密码」
 *  - 密码只打印一次，不落库明文
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/AdminAuth.php';

$cfg = require dirname(__DIR__) . '/config/config.php';

if (!is_file($cfg['db_path'])) {
    fwrite(STDERR, "数据库不存在：{$cfg['db_path']}\n请先执行 php bin/init_db.php\n");
    exit(1);
}

$username = 'admin';
$password = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--user=')) {
        $username = substr($arg, 7);
    } elseif (str_starts_with($arg, '--password=')) {
        $password = substr($arg, 11);
    }
}

if (!preg_match('/^[A-Za-z0-9_.\-]{3,32}$/', $username)) {
    fwrite(STDERR, "用户名格式不合法\n");
    exit(1);
}

if ($password === null) {
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    for ($i = 0; $i < 16; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
} else {
    // 与网页安装 / 后台改密使用同一套强度规则，不能只查长度就放过 "1234567890"
    $problem = AdminAuth::passwordProblem($password);
    if ($problem !== null) {
        fwrite(STDERR, "指定密码不符合强度要求：{$problem}\n（至少 10 位，且同时包含字母和数字）\n");
        exit(1);
    }
}

$pdo = Db::conn();

$stmt = $pdo->prepare('SELECT id FROM admin_users WHERE username = ?');
$stmt->execute([$username]);
$id = $stmt->fetchColumn();
if ($id === false) {
    fwrite(STDERR, "管理员不存在：{$username}\n");
    exit(1);
}

$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
$hash = password_hash($password, $algo);

$pdo->beginTransaction();
try {
    // 命令行重置属于「管理员代设的临时口令」，强制下次登录改密，与后台重置用户密码一致
    $pdo->prepare("UPDATE admin_users SET password_hash = ?, must_change_password = 1,
                        password_changed_at = datetime('now') WHERE id = ?")
        ->execute([$hash, (int)$id]);
    $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = ?')->execute([(int)$id]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "重置失败：" . $e->getMessage() . "\n");
    exit(1);
}

echo "管理员密码已重置\n";
echo "  用户名：{$username}\n";
echo "  新密码：{$password}\n";
echo "\n请立即保存，此密码只显示这一次。\n";
echo "该管理员的所有登录会话已失效，需重新登录。\n";
echo "已标记为「下次登录必须修改密码」。\n";
exit(0);
