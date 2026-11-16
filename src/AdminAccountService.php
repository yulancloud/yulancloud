<?php
/**
 * 多管理员账号服务（multi-admin 功能底座）
 *
 * 基于已有 admin_users 表（role: owner / staff）。店主（owner）可：
 *  - 列出所有管理员
 *  - 新增管理员（生成一次性初始密码，强制首次登录修改）
 *  - 启用 / 停用
 *  - 重置密码（生成一次性密码）
 *  - 删除（禁止删除自己、禁止删除最后一个 owner）
 *  - 设置登录邮箱（供 smtp-login 邮箱验证码登录映射）
 *
 * 所有密码用 AdminAuth::hash（argon2id/bcrypt），绝不存明文；
 * 生成的一次性密码只在本次响应返回一次。
 */
declare(strict_types=1);

final class AdminAccountService
{
    /** 管理员列表（不含任何哈希） */
    public static function list(): array
    {
        $rows = Db::conn()->query(
            'SELECT id, username, display_name, role, status, email, last_login_at, created_at
             FROM admin_users ORDER BY id ASC'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'           => (int)$r['id'],
                'username'     => (string)$r['username'],
                'display_name' => (string)$r['display_name'],
                'role'         => (string)$r['role'],
                'status'       => (string)$r['status'],
                'email'        => (string)($r['email'] ?? ''),
                'last_login_at'=> $r['last_login_at'] !== null ? (string)$r['last_login_at'] : '',
                'created_at'   => (string)$r['created_at'],
            ];
        }
        return ['items' => $out, 'total' => count($out)];
    }

    /** 新增管理员；返回一次性初始密码 */
    public static function create(array $in, int $operatorId): array
    {
        $username = strtolower(trim((string)($in['username'] ?? '')));
        if (!preg_match('/^[a-z0-9_.-]{3,32}$/', $username)) {
            throw new RuntimeException('账号需 3–32 位小写字母、数字、点、下划线或连字符');
        }
        $display = self::clip(trim((string)($in['display_name'] ?? $username)), 40);
        $role = (string)($in['role'] ?? 'staff');
        if (!in_array($role, ['owner', 'staff'], true)) {
            throw new RuntimeException('角色不合法');
        }
        $email = strtolower(trim((string)($in['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('邮箱格式不正确');
        }
        if ($email !== '' && self::emailTaken($email)) {
            throw new RuntimeException('该邮箱已被其他管理员使用');
        }
        $exists = Db::conn()->prepare('SELECT 1 FROM admin_users WHERE username = ?');
        $exists->execute([$username]);
        if ($exists->fetchColumn() !== false) {
            throw new RuntimeException('账号已存在');
        }
        $password = self::genPassword();
        Db::conn()->prepare(
            "INSERT INTO admin_users (username, password_hash, display_name, role, status, email, must_change_password)
             VALUES (?, ?, ?, ?, 'active', ?, 1)"
        )->execute([$username, AdminAuth::hash($password), $display, $role, $email]);
        // 必须在 Audit::log（也会写库）之前取 lastInsertId，否则拿到的是审计记录 id
        $newId = (int)Db::conn()->lastInsertId();
        Audit::log($operatorId, '', 'admin_create', $username, 'role=' . $role);
        return ['id' => $newId, 'username' => $username, 'initial_password' => $password];
    }

    /** 启用 / 停用 */
    public static function setStatus(int $id, string $status, int $operatorId): array
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new RuntimeException('状态不合法');
        }
        $u = self::find($id);
        if ($u === null) {
            throw new RuntimeException('管理员不存在');
        }
        if ($id === $operatorId) {
            throw new RuntimeException('不能停用当前登录的账号');
        }
        if ($status === 'disabled' && $u['role'] === 'owner' && self::activeOwnerCount() <= 1) {
            throw new RuntimeException('不能停用最后一个店主账号');
        }
        Db::conn()->prepare('UPDATE admin_users SET status = ? WHERE id = ?')->execute([$status, $id]);
        if ($status === 'disabled') {
            Db::conn()->prepare('DELETE FROM admin_sessions WHERE user_id = ?')->execute([$id]);
        }
        Audit::log($operatorId, '', 'admin_status', (string)$id, $status);
        return ['id' => $id, 'status' => $status];
    }

    /** 重置密码，返回一次性密码 */
    public static function resetPassword(int $id, int $operatorId): array
    {
        $u = self::find($id);
        if ($u === null) {
            throw new RuntimeException('管理员不存在');
        }
        $password = self::genPassword();
        Db::tx(function (PDO $pdo) use ($id, $password): void {
            $pdo->prepare(
                "UPDATE admin_users SET password_hash = ?, must_change_password = 1,
                     password_changed_at = datetime('now') WHERE id = ?"
            )->execute([AdminAuth::hash($password), $id]);
            // 注销该账号所有会话，强制用新密码重新登录
            $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = ?')->execute([$id]);
        });
        Audit::log($operatorId, '', 'admin_reset_password', (string)$id, '');
        return ['id' => $id, 'username' => $u['username'], 'new_password' => $password];
    }

    /** 设置显示名 / 角色 / 邮箱 */
    public static function update(int $id, array $in, int $operatorId): array
    {
        $u = self::find($id);
        if ($u === null) {
            throw new RuntimeException('管理员不存在');
        }
        $display = self::clip(trim((string)($in['display_name'] ?? $u['display_name'])), 40);
        $role = (string)($in['role'] ?? $u['role']);
        if (!in_array($role, ['owner', 'staff'], true)) {
            throw new RuntimeException('角色不合法');
        }
        // 降级最后一个 owner 要拦
        if ($u['role'] === 'owner' && $role !== 'owner' && self::activeOwnerCount() <= 1) {
            throw new RuntimeException('不能降级最后一个店主账号');
        }
        $email = strtolower(trim((string)($in['email'] ?? ($u['email'] ?? ''))));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('邮箱格式不正确');
        }
        if ($email !== '' && self::emailTaken($email, $id)) {
            throw new RuntimeException('该邮箱已被其他管理员使用');
        }
        Db::conn()->prepare('UPDATE admin_users SET display_name = ?, role = ?, email = ? WHERE id = ?')
            ->execute([$display, $role, $email, $id]);
        Audit::log($operatorId, '', 'admin_update', (string)$id, 'role=' . $role);
        return ['id' => $id, 'display_name' => $display, 'role' => $role, 'email' => $email];
    }

    /** 删除管理员 */
    public static function delete(int $id, int $operatorId): array
    {
        $u = self::find($id);
        if ($u === null) {
            throw new RuntimeException('管理员不存在');
        }
        if ($id === $operatorId) {
            throw new RuntimeException('不能删除当前登录的账号');
        }
        if ($u['role'] === 'owner' && self::activeOwnerCount() <= 1) {
            throw new RuntimeException('不能删除最后一个店主账号');
        }
        Db::tx(function (PDO $pdo) use ($id): void {
            $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        });
        Audit::log($operatorId, '', 'admin_delete', (string)$id, $u['username']);
        return ['id' => $id, 'deleted' => true];
    }

    // ---------------------------------------------------------------------

    private static function find(int $id): ?array
    {
        $s = Db::conn()->prepare('SELECT * FROM admin_users WHERE id = ?');
        $s->execute([$id]);
        $r = $s->fetch();
        return $r !== false ? $r : null;
    }

    private static function activeOwnerCount(): int
    {
        return (int)Db::conn()->query(
            "SELECT COUNT(*) FROM admin_users WHERE role = 'owner' AND status = 'active'"
        )->fetchColumn();
    }

    private static function emailTaken(string $email, int $exceptId = 0): bool
    {
        $s = Db::conn()->prepare('SELECT 1 FROM admin_users WHERE lower(email) = ? AND id <> ? LIMIT 1');
        $s->execute([$email, $exceptId]);
        return $s->fetchColumn() !== false;
    }

    /** 生成一次性初始密码：足够长、含大小写与数字 */
    private static function genPassword(): string
    {
        return bin2hex(random_bytes(6)) . 'Aa1!';
    }

    private static function clip(string $s, int $max): string
    {
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') : $s;
    }
}
