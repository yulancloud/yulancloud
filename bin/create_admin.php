<?php
/**
 * 后台管理员维护工具
 *
 *   php bin/create_admin.php                      # 新建一个 staff 账号（交互式输入密码）
 *   php bin/create_admin.php --user=alice --role=staff
 *   php bin/create_admin.php --reset              # 重置 owner 密码（随机生成并打印一次）
 *   php bin/create_admin.php --reset --user=admin --password='新密码'
 *   php bin/create_admin.php --list               # 列出所有管理员
 *   php bin/create_admin.php --disable=alice      # 停用账号
 *   php bin/create_admin.php --enable=alice       # 启用账号
 *
 * 安全：密码只接受命令行参数或交互输入，绝不写进代码/日志；一律 password_hash 存储。
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/Response.php';
require_once dirname(__DIR__) . '/src/RateLimiter.php';
require_once dirname(__DIR__) . '/src/Audit.php';
require_once dirname(__DIR__) . '/src/AdminAuth.php';

$cfg = require dirname(__DIR__) . '/config/config.php';

if (!is_file($cfg['db_path'])) {
    fwrite(STDERR, "数据库不存在，请先执行 php bin/init_db.php\n");
    exit(1);
}

/** 解析 --key=value 形式的参数 */
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$pdo = Db::conn();

// ---------- 列表 ----------
if (isset($opts['list'])) {
    $rows = $pdo->query(
        'SELECT id, username, display_name, role, status, last_login_at FROM admin_users ORDER BY id'
    )->fetchAll();
    if (!$rows) {
        echo "还没有管理员账号。执行 php bin/init_db.php 创建初始账号。\n";
        exit(0);
    }
    printf("%-4s %-16s %-10s %-8s %-10s %s\n", 'ID', '账号', '角色', '状态', '最近登录', '显示名');
    foreach ($rows as $r) {
        printf(
            "%-4d %-16s %-10s %-8s %-10s %s\n",
            $r['id'], $r['username'], $r['role'], $r['status'],
            $r['last_login_at'] ?? '—', $r['display_name']
        );
    }
    exit(0);
}

// ---------- 启用 / 停用 ----------
foreach (['disable', 'enable'] as $act) {
    if (isset($opts[$act]) && is_string($opts[$act])) {
        $status = $act === 'disable' ? 'disabled' : 'active';
        $stmt = $pdo->prepare('UPDATE admin_users SET status = ? WHERE username = ?');
        $stmt->execute([$status, $opts[$act]]);
        if ($stmt->rowCount() === 0) {
            fwrite(STDERR, "账号不存在或状态未变：{$opts[$act]}\n");
            exit(1);
        }
        // 停用后立即踢掉所有会话
        if ($status === 'disabled') {
            $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = (SELECT id FROM admin_users WHERE username = ?)')
                ->execute([$opts[$act]]);
        }
        echo "已{$act}账号：{$opts[$act]}\n";
        exit(0);
    }
}

// ---------- 重置密码 ----------
if (isset($opts['reset'])) {
    $username = is_string($opts['user'] ?? null) ? $opts['user'] : $cfg['admin']['initial_user'];
    $password = is_string($opts['password'] ?? null) ? $opts['password'] : '';

    $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE username = ?');
    $stmt->execute([$username]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        fwrite(STDERR, "账号不存在：{$username}\n");
        exit(1);
    }

    $generated = false;
    if ($password === '') {
        $password = bin2hex(random_bytes(9)) . 'Aa1';
        $generated = true;
    }
    $problem = AdminAuth::passwordProblem($password);
    if ($problem !== null) {
        fwrite(STDERR, "密码不符合要求：{$problem}\n");
        exit(1);
    }

    $pdo->prepare(
        "UPDATE admin_users SET password_hash = ?, must_change_password = 1,
             password_changed_at = datetime('now') WHERE id = ?"
    )->execute([AdminAuth::hash($password), (int)$id]);
    // 重置密码后注销该账号全部会话
    $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = ?')->execute([(int)$id]);

    echo "已重置账号 {$username} 的密码。\n";
    if ($generated) {
        echo "  新密码（只显示这一次）：{$password}\n";
    }
    echo "  该账号所有已登录会话已失效。\n";
    exit(0);
}

// ---------- 新建账号 ----------
$username = is_string($opts['user'] ?? null) ? trim($opts['user']) : '';
$role     = is_string($opts['role'] ?? null) ? $opts['role'] : 'staff';
$password = is_string($opts['password'] ?? null) ? $opts['password'] : '';

if ($username === '') {
    fwrite(STDERR, "用法：php bin/create_admin.php --user=账号 [--role=owner|staff] [--password=密码]\n");
    fwrite(STDERR, "      php bin/create_admin.php --list\n");
    fwrite(STDERR, "      php bin/create_admin.php --reset [--user=账号]\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
    fwrite(STDERR, "账号只能包含字母、数字、点、下划线、连字符（3-32 位）\n");
    exit(1);
}
if (!in_array($role, ['owner', 'staff'], true)) {
    fwrite(STDERR, "角色只能是 owner 或 staff\n");
    exit(1);
}

if ($password === '') {
    // 交互式输入（不回显）
    echo "为 {$username} 设置密码（输入不回显）：";
    if (function_exists('shell_exec') && DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty -echo');
        $password = trim((string)fgets(STDIN));
        shell_exec('stty echo');
        echo "\n";
    } else {
        $password = trim((string)fgets(STDIN));
    }
}

$problem = AdminAuth::passwordProblem($password);
if ($problem !== null) {
    fwrite(STDERR, "密码不符合要求：{$problem}\n");
    exit(1);
}

$exists = $pdo->prepare('SELECT 1 FROM admin_users WHERE username = ?');
$exists->execute([$username]);
if ($exists->fetchColumn() !== false) {
    fwrite(STDERR, "账号已存在：{$username}（改密码请用 --reset）\n");
    exit(1);
}

$pdo->prepare(
    "INSERT INTO admin_users (username, password_hash, display_name, role, status, must_change_password)
     VALUES (?, ?, ?, ?, 'active', 1)"
)->execute([$username, AdminAuth::hash($password), $username, $role]);

echo "已创建管理员：{$username}（角色 {$role}）\n";
echo "登录地址：/admin/\n";
