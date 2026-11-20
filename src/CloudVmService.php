<?php
/**
 * 云主机实例服务（v1.4）
 *
 * 负责把 UpstreamService::provision() 的开通结果持久化到 cloud_vms，
 * 并向用户 / 后台提供查询。密码只在开通时一次性写入发货内容（order_deliveries），
 * cloud_vms 表不长期持有明文密码。
 */
declare(strict_types=1);

final class CloudVmService
{
    public static function createFromProvision(
        PDO $pdo,
        int $orderId,
        int $itemId,
        string $productId,
        ?int $userId,
        int $upstreamId,
        string $externalId,
        array $inst
    ): int {
        $pdo->prepare(
            'INSERT INTO cloud_vms
             (order_id, order_item_id, product_id, user_id, upstream_id, external_id, instance_name,
              ip, os, cpu, mem, disk, bandwidth, region, status, detail, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'running\', ?, datetime(\'now\'), datetime(\'now\'))'
        )->execute([
            $orderId, $itemId, $productId, $userId, $upstreamId, $externalId,
            (string)($inst['instance_name'] ?? ''),
            (string)($inst['ip'] ?? ''),
            (string)($inst['os'] ?? ''),
            (string)($inst['cpu'] ?? ''),
            (string)($inst['mem'] ?? ''),
            (string)($inst['disk'] ?? ''),
            (string)($inst['bandwidth'] ?? ''),
            (string)($inst['region'] ?? ''),
            (string)($inst['detail'] ?? ''),
        ]);
        return (int)$pdo->lastInsertId();
    }

    private static function publicRow(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'product_id' => (string)$r['product_id'],
            'upstream_id' => (int)$r['upstream_id'],
            'external_id' => (string)$r['external_id'],
            'instance_name' => (string)$r['instance_name'],
            'ip' => (string)$r['ip'],
            'os' => (string)$r['os'],
            'cpu' => (string)$r['cpu'],
            'mem' => (string)$r['mem'],
            'disk' => (string)$r['disk'],
            'bandwidth' => (string)$r['bandwidth'],
            'region' => (string)$r['region'],
            'status' => (string)$r['status'],
            'status_label' => [
                'pending' => '待开通', 'creating' => '开通中', 'running' => '运行中',
                'stopped' => '已停止', 'failed' => '开通失败', 'cancelled' => '已取消',
            ][(string)$r['status']] ?? (string)$r['status'],
            'detail' => (string)$r['detail'],
            'order_no' => (string)($r['order_no'] ?? ''),
            'created_at' => (string)$r['created_at'],
        ];
    }

    public static function listForUser(int $userId): array
    {
        $st = Db::conn()->prepare(
            'SELECT v.*, o.order_no FROM cloud_vms v LEFT JOIN orders o ON o.id = v.order_id
             WHERE v.user_id = ? ORDER BY v.id DESC'
        );
        $st->execute([$userId]);
        return ['items' => array_map([self::class, 'publicRow'], $st->fetchAll())];
    }

    public static function listForAdmin(array $q = []): array
    {
        $limit = max(1, min(50, (int)($q['limit'] ?? 30)));
        $offset = max(0, (int)($q['offset'] ?? 0));
        $st = Db::conn()->prepare(
            "SELECT v.*, o.order_no, u.username FROM cloud_vms v
             LEFT JOIN orders o ON o.id = v.order_id LEFT JOIN users u ON u.id = v.user_id
             ORDER BY v.id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $st->execute();
        return ['items' => array_map([self::class, 'publicRow'], $st->fetchAll())];
    }

    /** 用户操作自己的实例：先校验归属，再调上游 */
    public static function actionForUser(int $userId, int $vmId, string $action, array $opts = []): array
    {
        $st = Db::conn()->prepare('SELECT * FROM cloud_vms WHERE id = ? AND user_id = ?');
        $st->execute([$vmId, $userId]);
        $vm = $st->fetch();
        if (!$vm) {
            Response::fail('not_found', '云主机不存在', 404);
        }
        return self::doAction($vm, $action, $opts);
    }

    public static function actionForAdmin(int $vmId, string $action, array $opts = []): array
    {
        $st = Db::conn()->prepare('SELECT * FROM cloud_vms WHERE id = ?');
        $st->execute([$vmId]);
        $vm = $st->fetch();
        if (!$vm) {
            Response::fail('not_found', '云主机不存在', 404);
        }
        return self::doAction($vm, $action, $opts);
    }

    private static function doAction(array $vm, string $action, array $opts): array
    {
        $result = UpstreamService::instanceAction((int)$vm['upstream_id'], (string)$vm['external_id'], $action, $opts);
        // 同步本地状态
        $statusMap = ['start' => 'running', 'stop' => 'stopped', 'restart' => 'running', 'delete' => 'cancelled'];
        if (isset($statusMap[$action])) {
            Db::conn()->prepare("UPDATE cloud_vms SET status = ?, updated_at = datetime('now') WHERE id = ?")
                ->execute([$statusMap[$action], (int)$vm['id']]);
        }
        return $result;
    }
}
