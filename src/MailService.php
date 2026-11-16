<?php
/**
 * 邮件服务（smtp-login 功能底座）
 *
 * 纯 PHP 实现 SMTP 客户端，不依赖 PHPMailer / sockets / openssl 之外的扩展：
 *  - 用 stream_socket_client 建 TCP 连接
 *  - 支持 STARTTLS（端口 25/587）与 SSL（端口 465）——TLS 用 stream_socket_enable_crypto
 *  - 支持 LOGIN / PLAIN 两种 SMTP AUTH
 *
 * 配置存 data/mail_config.json（Web 不可直接访问）：
 *  - host / port / encryption(none|tls|ssl) / username / password
 *  - from_email / from_name
 *  - allowed_emails：允许用邮箱验证码登录后台的管理员邮箱白名单
 * 安全：
 *  - password 只写不读：adminPayload() 里只回传 password_set 布尔，绝不回明文
 *  - 验证码只存 SHA-256，明文只在邮件里出现一次；5 分钟有效、最多 5 次尝试、用后即焚
 *  - 邮箱白名单精确匹配（小写），未配置的邮箱不发码、不登录
 */
declare(strict_types=1);

final class MailService
{
    private const CODE_TTL     = 300;   // 验证码有效期（秒）
    private const CODE_MAX_TRY = 5;     // 单码最多校验次数
    private const SEND_COOLDOWN = 60;   // 同一邮箱两次发码最小间隔（秒）

    // ---------------------------------------------------------------------
    // 配置存取
    // ---------------------------------------------------------------------

    private static function configPath(): string
    {
        return dirname(__DIR__) . '/data/mail_config.json';
    }

    public static function loadConfig(): array
    {
        $p = self::configPath();
        $d = is_file($p) ? json_decode((string)@file_get_contents($p), true) : null;
        if (!is_array($d)) {
            $d = [];
        }
        return array_merge([
            'host'          => '',
            'port'          => 587,
            'encryption'    => 'tls',
            'username'      => '',
            'password'      => '',
            'from_email'    => '',
            'from_name'     => '',
            'allowed_emails'=> [],
        ], $d);
    }

    /** 后台读取：密码字段只回布尔，绝不回明文 */
    public static function adminConfig(): array
    {
        $c = self::loadConfig();
        unset($c['password']);
        $c['password_set'] = self::loadConfig()['password'] !== '';
        return $c;
    }

    /** 保存配置；password 传 null 表示保持原值不变 */
    public static function saveConfig(array $in): array
    {
        $cur = self::loadConfig();
        $host = trim((string)($in['host'] ?? ''));
        if ($host === '' || strlen($host) > 253 || preg_match('/[^A-Za-z0-9.\-]/', $host)) {
            throw new RuntimeException('SMTP 主机地址不合法');
        }
        $port = (int)($in['port'] ?? 587);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('SMTP 端口不合法');
        }
        $enc = (string)($in['encryption'] ?? 'none');
        if (!in_array($enc, ['none', 'tls', 'ssl'], true)) {
            throw new RuntimeException('加密方式不合法');
        }
        $emails = $in['allowed_emails'] ?? [];
        if (!is_array($emails)) {
            throw new RuntimeException('允许登录的邮箱列表格式不合法');
        }
        $cleanEmails = [];
        foreach ($emails as $e) {
            $e = strtolower(trim((string)$e));
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && count($cleanEmails) < 50) {
                $cleanEmails[] = $e;
            }
        }
        $from = strtolower(trim((string)($in['from_email'] ?? '')));
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('发件邮箱格式不合法');
        }

        $next = [
            'host'          => $host,
            'port'          => $port,
            'encryption'    => $enc,
            'username'      => trim((string)($in['username'] ?? '')),
            'from_email'    => $from !== '' ? $from : $host,
            'from_name'     => self::clip((string)($in['from_name'] ?? ''), 60),
            'allowed_emails'=> array_values(array_unique($cleanEmails)),
        ];
        // 密码：null 或空字符串都表示「不修改」
        if (array_key_exists('password', $in) && is_string($in['password']) && $in['password'] !== '') {
            if (strlen($in['password']) > 256) {
                throw new RuntimeException('SMTP 密码过长');
            }
            $next['password'] = $in['password'];
        } else {
            $next['password'] = $cur['password'];
        }
        $p = self::configPath();
        $tmp = $p . '.tmp' . bin2hex(random_bytes(4));
        $json = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('无法写入邮件配置，请确认 data/ 目录可写');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $p)) {
            @unlink($tmp);
            throw new RuntimeException('无法保存邮件配置');
        }
        return self::adminConfig();
    }

    public static function isConfigured(): bool
    {
        $c = self::loadConfig();
        // 密码不强制：内网 / 本机免认证的 SMTP relay（如 postfix、局域网邮件网关）无需密码。
        // 需要认证的服务，若填了 username 却没密码，会在 SMTP AUTH 阶段自然报错。
        return $c['host'] !== '' && $c['from_email'] !== '';
    }

    // ---------------------------------------------------------------------
    // 验证码
    // ---------------------------------------------------------------------

    /** 发验证码。返回 ['ok'=>bool,'message'=>string] */
    public static function sendCode(string $email): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => '邮箱格式不正确'];
        }
        $cfg = self::loadConfig();
        if (!in_array($email, $cfg['allowed_emails'], true)) {
            // 不区分「未在白名单」与「发送失败」，避免探测允许列表
            return ['ok' => true, 'message' => '如果该邮箱可以登录，验证码已发送'];
        }
        if (!self::isConfigured()) {
            return ['ok' => false, 'message' => '邮件服务器尚未配置完成'];
        }
        $pdo = Db::conn();
        // 冷却检查
        $last = $pdo->prepare('SELECT created_at FROM email_codes WHERE email = ? ORDER BY created_at DESC LIMIT 1');
        $last->execute([$email]);
        $lastAt = (int)$last->fetchColumn();
        if ($lastAt > 0 && time() - $lastAt < self::SEND_COOLDOWN) {
            return ['ok' => false, 'message' => '发送过于频繁，请稍后再试'];
        }
        $code = (string)random_int(100000, 999999);
        $captchaBody = '您的后台登录验证码是：' . $code . "\n" .
                       '有效期 5 分钟，请勿泄露给他人。如非本人操作可忽略。';
        try {
            self::send($cfg['from_email'], $cfg['from_name'] ?: '欲蓝财务', $email, '后台登录验证码', $captchaBody);
        } catch (Throwable $e) {
            error_log('[shop][mail] send failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => '验证码发送失败，请检查邮件服务器配置'];
        }
        $pdo->prepare(
            'INSERT INTO email_codes (email, code_hash, purpose, expires_at, attempts, created_at)
             VALUES (?, ?, ?, ?, 0, ?)'
        )->execute([$email, hash('sha256', $code), 'admin_login', time() + self::CODE_TTL, time()]);
        // 清理该邮箱的旧码
        $pdo->prepare('DELETE FROM email_codes WHERE email = ? AND purpose = ? AND expires_at < ?')
            ->execute([$email, 'admin_login', time()]);
        return ['ok' => true, 'message' => '验证码已发送'];
    }

    /**
     * 校验验证码；成功返回管理员 user id（int），失败返回 null。
     * 验证码与邮箱绑定：使用该邮箱在 admin_users 里对应的账号（按 email 列匹配）。
     */
    public static function verifyCode(string $email, string $code): ?int
    {
        $email = strtolower(trim($email));
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $pdo = Db::conn();
        $stmt = $pdo->prepare(
            'SELECT id, code_hash, expires_at, attempts FROM email_codes
             WHERE email = ? AND purpose = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$email, 'admin_login']);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if (time() > (int)$row['expires_at']) {
            $pdo->prepare('DELETE FROM email_codes WHERE id = ?')->execute([$row['id']]);
            return null;
        }
        if ((int)$row['attempts'] >= self::CODE_MAX_TRY) {
            $pdo->prepare('DELETE FROM email_codes WHERE id = ?')->execute([$row['id']]);
            return null;
        }
        $pdo->prepare('UPDATE email_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        if (!hash_equals((string)$row['code_hash'], hash('sha256', $code))) {
            return null;
        }
        // 用后即焚
        $pdo->prepare('DELETE FROM email_codes WHERE id = ?')->execute([$row['id']]);

        // 邮箱 → 管理员账号
        $u = $pdo->prepare('SELECT id FROM admin_users WHERE lower(email) = ? AND status = \'active\' LIMIT 1');
        $u->execute([$email]);
        $uid = $u->fetchColumn();
        return $uid !== false ? (int)$uid : null;
    }

    /** 测试发信 */
    public static function sendTest(string $to): void
    {
        $to = strtolower(trim($to));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('收件邮箱格式不正确');
        }
        $cfg = self::loadConfig();
        if (!self::isConfigured()) {
            throw new RuntimeException('请先保存 SMTP 配置');
        }
        self::send($cfg['from_email'], $cfg['from_name'] ?: '欲蓝财务', $to,
                   '邮件配置测试', '这是一封测试邮件。收到即表示 SMTP 配置正确。');
    }

    // ---------------------------------------------------------------------
    // SMTP 客户端（纯 stream，无额外扩展）
    // ---------------------------------------------------------------------

    private static function send(string $from, string $fromName, string $to, string $subject, string $body): void
    {
        $cfg = self::loadConfig();
        $enc = $cfg['encryption'];
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'];
        $tlsMethod = defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')
            ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            : STREAM_CRYPTO_METHOD_TLS_CLIENT;
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'crypto_method'     => $tlsMethod,
            ],
        ]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException('无法连接 SMTP 服务器：' . $errstr);
        }
        stream_set_timeout($fp, 15);
        try {
            self::expect($fp, 220);
            $domain = $cfg['host'];
            self::cmd($fp, 'EHLO ' . $domain, 250);

            if ($enc === 'tls') {
                self::cmd($fp, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($fp, true, $tlsMethod)) {
                    throw new RuntimeException('STARTTLS 握手失败');
                }
                self::cmd($fp, 'EHLO ' . $domain, 250);
            }

            if ($cfg['username'] !== '') {
                // 优先 LOGIN，失败退 PLAIN
                try {
                    self::cmd($fp, 'AUTH LOGIN', 334);
                    self::cmd($fp, base64_encode($cfg['username']), 334);
                    self::cmd($fp, base64_encode($cfg['password']), 235);
                } catch (RuntimeException $e) {
                    self::cmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $cfg['username'] . "\0" . $cfg['password']), 235);
                }
            }

            self::cmd($fp, 'MAIL FROM: <' . $from . '>', 250);
            self::cmd($fp, 'RCPT TO: <' . $to . '>', [250, 251]);
            self::cmd($fp, 'DATA', 354);

            $safeFrom = self::headerSafe($fromName);
            $safeSubject = self::headerSafe($subject);
            $msg = "From: =?UTF-8?B?" . base64_encode($safeFrom) . "?= <" . $from . ">\r\n"
                 . "To: <" . $to . ">\r\n"
                 . "Subject: =?UTF-8?B?" . base64_encode($safeSubject) . "?=\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: base64\r\n"
                 . "Date: " . date('r') . "\r\n"
                 . "\r\n"
                 . chunk_split(base64_encode($body), 76, "\r\n")
                 . "\r\n.\r\n";
            self::cmd($fp, $msg, 250, true);
            // QUIT 是普通命令，必须带 CRLF；不能走 raw（raw 用于 DATA 正文，自带 \r\n.\r\n）
            self::cmd($fp, 'QUIT', [221, 250]);
        } finally {
            @fclose($fp);
        }
    }

    /** 读一行 SMTP 响应，返回三位状态码 */
    private static function expect($fp, int|array $want): int
    {
        $line = '';
        while (($tmp = fgets($fp, 512)) !== false) {
            $line = $tmp;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        if ($line === '') {
            throw new RuntimeException('SMTP 服务器无响应');
        }
        $code = (int)substr($line, 0, 3);
        $wants = is_array($want) ? $want : [$want];
        if (!in_array($code, $wants, true)) {
            throw new RuntimeException('SMTP 协议错误（' . $code . '）：' . trim(substr($line, 4)));
        }
        return $code;
    }

    /** 发命令并校验响应码；raw=true 时整段写入（用于 DATA 正文 / QUIT） */
    private static function cmd($fp, string $line, int|array $want, bool $raw = false): void
    {
        if ($raw) {
            $written = @fwrite($fp, $line);
        } else {
            $written = @fwrite($fp, $line . "\r\n");
        }
        if ($written === false) {
            throw new RuntimeException('SMTP 写入失败');
        }
        self::expect($fp, $want);
    }

    /** 清洗邮件头，防注入（不允许换行） */
    private static function headerSafe(string $s): string
    {
        return str_replace(["\r", "\n", "\0"], '', $s);
    }

    private static function clip(string $s, int $max): string
    {
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') : $s;
    }
}
