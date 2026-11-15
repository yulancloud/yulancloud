<?php
/**
 * 数据库连接（PDO + SQLite）
 * 安全要点：
 *  - ERRMODE_EXCEPTION：错误不静默
 *  - EMULATE_PREPARES = false：使用真正的预处理语句，杜绝拼接注入
 *  - 打开外键约束
 *  - 数据库文件放在 web 根之外，禁止通过 HTTP 直接下载
 */
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(bool $createIfMissing = false): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg  = require dirname(__DIR__) . '/config/config.php';
        $path = $cfg['db_path'];
        $dir  = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建数据目录');
        }
        // 安装向导需要在这里建库；常规请求仍然拒绝静默建空库
        if (!is_file($path) && !$createIfMissing) {
            throw new RuntimeException('数据库未初始化，请先执行 php bin/init_db.php 或访问 /install');
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        self::$pdo = $pdo;
        return $pdo;
    }

    /** 在事务中执行，异常自动回滚 */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::conn();
        // 复用外层事务：充值回调 Db::tx() 内会调用 BalanceService::credit()（内部也开事务），
        // SQLite/PDO 不支持嵌套 beginTransaction，二次调用会抛 "There is already an active
        // transaction" 导致充值永远不到账。已处于事务中时直接执行，由外层统一 commit/rollback。
        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }
        $pdo->beginTransaction();
        try {
            $out = $fn($pdo);
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
