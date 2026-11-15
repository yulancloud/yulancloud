<?php
/**
 * 后台操作审计
 * 只记「谁在什么时候对什么做了什么」，不含密码、密钥、完整手机号等敏感内容。
 */
declare(strict_types=1);

final class Audit
{
    public static function log(
        ?int $userId,
        string $username,
        string $action,
        string $target = '',
        string $detail = ''
    ): void {
        try {
            Db::conn()->prepare(
                'INSERT INTO admin_audit (user_id, username, action, target, detail, ip_hash)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $userId,
                mb_substr($username, 0, 64, 'UTF-8'),
                mb_substr($action, 0, 64, 'UTF-8'),
                mb_substr($target, 0, 120, 'UTF-8'),
                mb_substr($detail, 0, 500, 'UTF-8'),
                RateLimiter::ipHash(),
            ]);
        } catch (Throwable $e) {
            // 审计写失败不能影响主流程，但必须留痕
            error_log('[shop][audit] ' . $e->getMessage());
        }
    }

    /** 分页读取 */
    public static function page(int $limit, int $offset, string $action = ''): array
    {
        $where = '';
        $params = [];
        if ($action !== '' && preg_match('/^[a-z_]{2,64}$/', $action)) {
            $where = ' WHERE action = :a';
            $params[':a'] = $action;
        }
        $stmt = Db::conn()->prepare(
            'SELECT id, username, action, target, detail, created_at
             FROM admin_audit' . $where . ' ORDER BY id DESC LIMIT :lim OFFSET :off'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $cnt = Db::conn()->prepare('SELECT COUNT(*) FROM admin_audit' . $where);
        $cnt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => (int)$cnt->fetchColumn(),
        ];
    }
}
