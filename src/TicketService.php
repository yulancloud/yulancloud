<?php
/**
 * 工单服务（v1.4）
 *
 * 用户：提交工单、查看自己的工单与详情、追加回复、关闭工单。
 * 后台：列出全部工单、查看详情、回复、改状态（open/processing/replied/closed）。
 *
 * 安全：
 *  - 所有用户侧查询强制 user_id 过滤，找不到一律 404（不区分「不存在/不是你的」）。
 *  - 正文转义由前端负责，长度在此限制；状态白名单校验。
 */
declare(strict_types=1);

final class TicketService
{
    private const MAX_BODY = 4000;
    private const MAX_TITLE = 120;

    private static array $CATEGORIES = ['account', 'payment', 'product', 'technical', 'other'];
    private static array $STATUS = ['open', 'processing', 'replied', 'closed'];

    public static function categoryLabel(string $c): string
    {
        return [
            'account' => '账号问题', 'payment' => '支付/充值', 'product' => '商品/交付',
            'technical' => '技术故障', 'other' => '其他',
        ][$c] ?? $c;
    }

    public static function statusLabel(string $s): string
    {
        return [
            'open' => '待处理', 'processing' => '处理中', 'replied' => '已回复', 'closed' => '已关闭',
        ][$s] ?? $s;
    }

    /* ==================== 用户侧 ==================== */

    public static function listForUser(int $userId, array $q = []): array
    {
        $limit = max(1, min(50, (int)($q['limit'] ?? 20)));
        $offset = max(0, (int)($q['offset'] ?? 0));
        $status = (string)($q['status'] ?? '');
        $where = 'user_id = ?';
        $args = [$userId];
        if (in_array($status, self::$STATUS, true)) {
            $where .= ' AND status = ?';
            $args[] = $status;
        }
        $st = Db::conn()->prepare("SELECT * FROM tickets WHERE {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
        $st->execute($args);
        $items = array_map([self::class, 'publicRow'], $st->fetchAll());

        $cnt = Db::conn()->prepare("SELECT COUNT(*) FROM tickets WHERE {$where}");
        $cnt->execute($args);

        return ['items' => $items, 'total' => (int)$cnt->fetchColumn()];
    }

    public static function create(int $userId, string $displayName, array $in): array
    {
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title, 'UTF-8') > self::MAX_TITLE) {
            Response::fail('invalid_title', '标题需为 1-' . self::MAX_TITLE . ' 个字符', 400);
        }
        $category = (string)($in['category'] ?? 'other');
        if (!in_array($category, self::$CATEGORIES, true)) {
            $category = 'other';
        }
        $priority = (string)($in['priority'] ?? 'normal');
        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            $priority = 'normal';
        }
        $body = self::cleanBody((string)($in['body'] ?? ''));
        if ($body === '') {
            Response::fail('invalid_body', '请填写问题描述', 400);
        }
        $orderNo = trim((string)($in['order_no'] ?? ''));
        if (mb_strlen($orderNo, 'UTF-8') > 60) {
            $orderNo = '';
        }

        $pdo = Db::conn();
        return Db::tx(function (PDO $pdo) use ($userId, $displayName, $title, $category, $priority, $body, $orderNo): array {
            $ticketNo = 'TK' . date('Ymd') . strtoupper(bin2hex(random_bytes(4)));
            $pdo->prepare(
                'INSERT INTO tickets (ticket_no, user_id, order_no, category, title, priority, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, \'open\', datetime(\'now\'), datetime(\'now\'))'
            )->execute([$ticketNo, $userId, $orderNo, $category, $title, $priority]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO ticket_replies (ticket_id, role, author, body, created_at) VALUES (?, \'user\', ?, ?, datetime(\'now\'))'
            )->execute([$id, $displayName, $body]);
            return self::detailForUser($userId, $id);
        });
    }

    public static function detailForUser(int $userId, int $ticketId): array
    {
        $st = Db::conn()->prepare('SELECT * FROM tickets WHERE id = ? AND user_id = ?');
        $st->execute([$ticketId, $userId]);
        $t = $st->fetch();
        if (!$t) {
            Response::fail('not_found', '工单不存在', 404);
        }
        return self::publicRow($t) + ['messages' => self::messages($ticketId)];
    }

    public static function replyAsUser(int $userId, int $ticketId, string $body): array
    {
        $t = self::ownedRow($userId, $ticketId);
        if ((string)$t['status'] === 'closed') {
            Response::fail('ticket_closed', '工单已关闭，无法回复', 409);
        }
        $body = self::cleanBody($body);
        if ($body === '') {
            Response::fail('invalid_body', '回复内容不能为空', 400);
        }
        $pdo = Db::conn();
        Db::tx(function (PDO $pdo) use ($ticketId, $t, $body): void {
            $name = UserAuth::publicUser((int)$t['user_id'])['display_name'] ?? '';
            $pdo->prepare(
                'INSERT INTO ticket_replies (ticket_id, role, author, body, created_at) VALUES (?, \'user\', ?, ?, datetime(\'now\'))'
            )->execute([$ticketId, $name, $body]);
            $pdo->prepare("UPDATE tickets SET status = 'open', last_reply_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")
                ->execute([$ticketId]);
        });
        return self::detailForUser($userId, $ticketId);
    }

    public static function closeAsUser(int $userId, int $ticketId): array
    {
        self::ownedRow($userId, $ticketId);
        Db::conn()->prepare("UPDATE tickets SET status = 'closed', updated_at = datetime('now') WHERE id = ?")
            ->execute([$ticketId]);
        return self::detailForUser($userId, $ticketId);
    }

    private static function ownedRow(int $userId, int $ticketId): array
    {
        $st = Db::conn()->prepare('SELECT * FROM tickets WHERE id = ? AND user_id = ?');
        $st->execute([$ticketId, $userId]);
        $t = $st->fetch();
        if (!$t) {
            Response::fail('not_found', '工单不存在', 404);
        }
        return $t;
    }

    /* ==================== 后台侧 ==================== */

    public static function listForAdmin(array $q = []): array
    {
        $limit = max(1, min(50, (int)($q['limit'] ?? 20)));
        $offset = max(0, (int)($q['offset'] ?? 0));
        $status = (string)($q['status'] ?? '');
        $where = '1=1';
        $args = [];
        if (in_array($status, self::$STATUS, true)) {
            $where .= ' AND t.status = ?';
            $args[] = $status;
        }
        $st = Db::conn()->prepare(
            "SELECT t.*, u.username, u.display_name FROM tickets t LEFT JOIN users u ON u.id = t.user_id
             WHERE {$where} ORDER BY t.id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $st->execute($args);
        $items = array_map(static function ($r) {
            $row = TicketService::publicRow($r);
            $row['username'] = (string)($r['username'] ?? '');
            return $row;
        }, $st->fetchAll());
        $cnt = Db::conn()->prepare("SELECT COUNT(*) FROM tickets t WHERE {$where}");
        $cnt->execute($args);
        return ['items' => $items, 'total' => (int)$cnt->fetchColumn()];
    }

    public static function detailForAdmin(int $ticketId): array
    {
        $st = Db::conn()->prepare(
            'SELECT t.*, u.username, u.display_name FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?'
        );
        $st->execute([$ticketId]);
        $t = $st->fetch();
        if (!$t) {
            Response::fail('not_found', '工单不存在', 404);
        }
        $row = self::publicRow($t);
        $row['username'] = (string)($t['username'] ?? '');
        $row['messages'] = self::messages($ticketId);
        return $row;
    }

    public static function replyAsAdmin(int $ticketId, string $adminName, string $body): array
    {
        $st = Db::conn()->prepare('SELECT id FROM tickets WHERE id = ?');
        $st->execute([$ticketId]);
        if (!$st->fetchColumn()) {
            Response::fail('not_found', '工单不存在', 404);
        }
        $body = self::cleanBody($body);
        if ($body === '') {
            Response::fail('invalid_body', '回复内容不能为空', 400);
        }
        $pdo = Db::conn();
        Db::tx(function (PDO $pdo) use ($ticketId, $adminName, $body): void {
            $pdo->prepare(
                'INSERT INTO ticket_replies (ticket_id, role, author, body, created_at) VALUES (?, \'admin\', ?, ?, datetime(\'now\'))'
            )->execute([$ticketId, $adminName, $body]);
            $pdo->prepare("UPDATE tickets SET status = 'replied', last_reply_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")
                ->execute([$ticketId]);
        });
        return self::detailForAdmin($ticketId);
    }

    public static function setStatus(int $ticketId, string $status): array
    {
        if (!in_array($status, self::$STATUS, true)) {
            Response::fail('invalid_status', '状态不合法', 400);
        }
        $st = Db::conn()->prepare("UPDATE tickets SET status = ?, updated_at = datetime('now') WHERE id = ?");
        $st->execute([$status, $ticketId]);
        if ($st->rowCount() !== 1) {
            Response::fail('not_found', '工单不存在', 404);
        }
        return self::detailForAdmin($ticketId);
    }

    /* ==================== 公共 ==================== */

    private static function messages(int $ticketId): array
    {
        $st = Db::conn()->prepare('SELECT role, author, body, created_at FROM ticket_replies WHERE ticket_id = ? ORDER BY id ASC');
        $st->execute([$ticketId]);
        return array_map(static fn($r) => [
            'role' => (string)$r['role'],
            'author' => (string)($r['author'] ?? ''),
            'body' => (string)$r['body'],
            'created_at' => (string)$r['created_at'],
        ], $st->fetchAll());
    }

    private static function publicRow(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'ticket_no' => (string)$r['ticket_no'],
            'user_id' => (int)$r['user_id'],
            'order_no' => (string)$r['order_no'],
            'category' => (string)$r['category'],
            'category_label' => self::categoryLabel((string)$r['category']),
            'title' => (string)$r['title'],
            'priority' => (string)$r['priority'],
            'status' => (string)$r['status'],
            'status_label' => self::statusLabel((string)$r['status']),
            'last_reply_at' => $r['last_reply_at'],
            'created_at' => (string)$r['created_at'],
            'updated_at' => (string)$r['updated_at'],
        ];
    }

    private static function cleanBody(string $body): string
    {
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? '';
        $body = trim($body);
        if (mb_strlen($body, 'UTF-8') > self::MAX_BODY) {
            $body = mb_substr($body, 0, self::MAX_BODY, 'UTF-8');
        }
        return $body;
    }
}
