<?php
/**
 * 在线更新（v1.3）—— 从 GitHub Release 下载最新系统包并升级，保留用户数据
 *
 * 设计目标（客户建议）：「通过更新来升级，保留原来的主页设计和设置」。
 * 主页设计、站点文案、商品/订单、主题插件全部存在于 data/ 与 config/ 中，
 * 因此在线更新只覆盖「程序文件」，对以下路径完全不动：
 *   - config/             （.env、installed.lock、数据库路径等本机配置）
 *   - data/               （shop.sqlite 数据库、settings 里的主页设计与文案、extensions 主题插件原件）
 *   - public/plugins/     （运行时插件副本）
 *   - public/images/      （店主可能自行替换的商品图）
 * 升级前自动把程序文件备份到 data/.update-backup-<时间>/，失败可回滚。
 *
 * 安全：
 *  - 只允许本站配置的发布源（RELEASE_API / RELEASE_TAG 常量），不接受任意 URL 盲下（run 时校验域名）。
 *  - 升级包解压后逐个文件校验：拒绝 ../ 路径穿越、拒绝 .php 以外的可执行文件在 web 根、拒绝写 data/config。
 *  - 需要 owner 权限（路由层已 requireOwner）。
 */
declare(strict_types=1);

final class UpdateService
{
    /** 官方发布源（多个端点依次尝试，规避 GitHub 未授权限流） */
    private const RELEASE_APIS = [
        'https://api.github.com/repos/yulancloud/yulancloud/releases/latest',
        // 直连 Release 下载页兜底（不计 API 限流）
        'https://github.com/yulancloud/yulancloud/releases/latest',
    ];
    private const RELEASE_HOST = 'github.com';

    /** 当前版本（v1.3） */
    private const CURRENT_VERSION = '1.4.0';
    private const CURRENT_BUILD   = '20261120b';

    /** 升级时“完全保留”的顶层路径前缀 */
    private const PRESERVE_PREFIX = [
        'config/',
        'data/',
        'public/plugins/',
        'public/images/',
    ];

    /** 当前版本信息 */
    public static function current(): array
    {
        return ['version' => self::CURRENT_VERSION, 'build' => self::CURRENT_BUILD];
    }

    /**
     * 检查更新：读 GitHub latest release，与当前版本比较。
     * @return array{current:array,latest:?array,update_available:?bool,error?:string}
     */
    public static function check(): array
    {
        $cur = self::current();
        $out = ['current' => $cur, 'latest' => null, 'update_available' => null];

        // 依次尝试多个端点；GitHub API 未授权每小时仅 60 次，超限则回退网页端点
        $tag = '';
        $d = null;
        foreach (self::RELEASE_APIS as $apiUrl) {
            $json = self::httpGet($apiUrl);
            if ($json === null || $json === '') {
                continue;
            }
            if (strpos($apiUrl, 'api.github.com') !== false) {
                $tmp = json_decode($json, true);
                if (is_array($tmp) && !empty($tmp['tag_name'])) {
                    $d = $tmp;
                    $tag = (string)$tmp['tag_name'];
                    break;
                }
            } else {
                // 网页端点：优先用最终重定向 URL 解析 tag（最可靠）
                $effective = self::effectiveUrl($apiUrl) ?: '';
                $matchSrc = $json;
                if ($effective !== '' && preg_match('#/releases/tag/([A-Za-z0-9._-]+)#', $effective, $em)) {
                    $matchSrc = $effective;   // 正文里没有时用 URL
                }
                if (preg_match('#/releases/tag/([A-Za-z0-9._-]+)#', $matchSrc, $m)) {
                    $tag = $m[1];
                    // 从网页 HTML 里尽量抓下载链接与发布日期
                    $assetUrl = '';
                    if (preg_match('#href="([^"]*yulan-shop-v?\d[^"]*\.zip)"#i', $json, $am)) {
                        $assetUrl = html_entity_decode($am[1], ENT_QUOTES | ENT_HTML5);
                        if (strpos($assetUrl, 'http') !== 0) {
                            $assetUrl = 'https://github.com' . $assetUrl;
                        }
                    }
                    $d = [
                        'tag_name' => $tag,
                        'published_at' => '',
                        'body' => '',
                        'assets' => $assetUrl ? [[
                            'name' => basename(parse_url($assetUrl, PHP_URL_PATH) ?: 'update.zip'),
                            'size' => 0,
                            'browser_download_url' => $assetUrl,
                        ]] : [],
                    ];
                    break;
                }
            }
        }
        if ($tag === '' || !is_array($d)) {
            $out['error'] = '无法连接官方更新服务器（可能暂时限流），请稍后重试或手动下载升级';
            return $out;
        }
        $latestVer = ltrim($tag, 'v');
        $asset = null;
        foreach (($d['assets'] ?? []) as $a) {
            $name = (string)($a['name'] ?? '');
            // 系统包命名：yulan-shop-v*.zip（排除 APP / 文档等）
            if (preg_match('/^yulan-shop-v?\d/i', $name)) {
                $asset = [
                    'name' => $name,
                    'size' => (int)($a['size'] ?? 0),
                    'browser_download_url' => (string)($a['browser_download_url'] ?? ''),
                ];
                break;
            }
        }
        // 兜底：网页端点可能抓不到文件名，按 tag 推断已知的系统包下载地址
        // 命名约定：v1.2.3 → yulan-shop-v123.zip；v1.3.0 → yulan-shop-v130.zip
        if ($asset === null && $tag !== '') {
            $digits = preg_replace('/[^0-9]/', '', $tag);       // v1.3.0 → 130
            $zipName = 'yulan-shop-v' . $digits . '.zip';
            $asset = [
                'name' => $zipName,
                'size' => 0,
                'browser_download_url' => 'https://github.com/yulancloud/yulancloud/releases/download/'
                    . $tag . '/' . $zipName,
            ];
        }
        $out['latest'] = [
            'tag' => $tag,
            'version' => $latestVer,
            'published_at' => (string)($d['published_at'] ?? ''),
            'notes' => (string)($d['body'] ?? ''),
            'asset' => $asset,
        ];
        $out['update_available'] = self::versionCompare($latestVer, self::CURRENT_VERSION) > 0;
        return $out;
    }

    /**
     * 执行更新：下载 zip → 校验 → 备份 → 覆盖程序文件（保留 data/config）。
     * @param array $opts {asset_url:string, version:string}
     * @return array{ok:bool,version:string,files:int,backup:string}
     */
    public static function run(array $opts): array
    {
        $url = trim((string)($opts['asset_url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('缺少升级包地址');
        }
        // 只接受 GitHub 官方域名，杜绝 SSRF / 任意 URL 下载
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        $okHost = ($host === self::RELEASE_HOST
            || $host === 'objects.githubusercontent.com'
            || str_ends_with($host, '.githubusercontent.com'));
        if (!$okHost || stripos($url, 'yulan-shop-') === false) {
            throw new RuntimeException('升级包地址不是官方发布源，已拒绝');
        }

        $root = dirname(__DIR__);              // 项目根
        $tmpDir = sys_get_temp_dir() . '/yulan_update_' . bin2hex(random_bytes(4));
        if (!@mkdir($tmpDir, 0755, true)) {
            throw new RuntimeException('无法创建临时目录');
        }
        $zipPath = $tmpDir . '/update.zip';

        try {
            $zipData = self::httpGet($url, true);
            if ($zipData === null || $zipData === '' || strlen($zipData) < 1000) {
                throw new RuntimeException('升级包下载失败或内容异常');
            }
            if (@file_put_contents($zipPath, $zipData) === false) {
                throw new RuntimeException('升级包写入失败');
            }

            // 解压到临时目录（ZipArchive 优先，回退系统 unzip），再统一收集文件
            $extractDir = $tmpDir . '/extracted';
            @mkdir($extractDir, 0755, true);
            self::extractZip($zipPath, $extractDir);

            $entries = [];
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                $rel = self::normalizeEntry(
                    substr($file->getPathname(), strlen($extractDir) + 1)
                );
                if ($rel === null || $rel === '' || self::isPreserved($rel)) {
                    continue;
                }
                $data = @file_get_contents($file->getPathname());
                if ($data !== false) {
                    $entries[$rel] = $data;
                }
            }
            if (!$entries) {
                throw new RuntimeException('升级包解压后没有可用文件');
            }

            // 必须包含主入口，防止误用错包把站点清空
            if (!isset($entries['public/api/index.php']) || !isset($entries['database/schema.sql'])) {
                throw new RuntimeException('升级包内容不完整（缺少核心文件），已中止');
            }

            // 备份将被覆盖的现有文件
            $backup = $root . '/data/.update-backup-' . date('YmdHis');
            $madeBackup = false;
            foreach (array_keys($entries) as $rel) {
                $target = $root . '/' . $rel;
                if (is_file($target)) {
                    $dst = $backup . '/' . $rel;
                    @mkdir(dirname($dst), 0755, true);
                    @copy($target, $dst);
                    $madeBackup = true;
                }
            }

            // 覆盖写入
            $written = 0;
            foreach ($entries as $rel => $data) {
                $target = $root . '/' . $rel;
                $dir = dirname($target);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException('无法创建目录：' . $rel);
                }
                if (@file_put_contents($target, $data) === false) {
                    throw new RuntimeException('写入文件失败：' . $rel . '（请检查目录写权限）');
                }
                $written++;
            }

            // 升级后自动跑迁移（新增表/列，幂等）——保留数据
            self::runMigration($root);

            return [
                'ok' => true,
                'version' => (string)($opts['version'] ?? self::CURRENT_VERSION),
                'files' => $written,
                'backup' => $madeBackup ? str_replace($root . '/', '', $backup) : '',
            ];
        } finally {
            self::rmrf($tmpDir);
        }
    }

    /** 解压 zip 到目录：ZipArchive 优先，回退系统 unzip 命令 */
    private static function extractZip(string $zipPath, string $dest): void
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('升级包不是有效的 zip 文件');
            }
            // 逐条目解压，跳过../穿越（正常包不会有，防御性处理）
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || preg_match('#(?:\.\./|^/)#', $name)) {
                    continue;
                }
                $zip->extractTo($dest, [$name]);
            }
            $zip->close();
            return;
        }
        // 回退：系统 unzip 命令
        $cmd = 'unzip -o -qq ' . escapeshellarg($zipPath) . ' -d ' . escapeshellarg($dest) . ' 2>&1';
        @exec($cmd, $out, $code);
        if ($code !== 0 && !is_file($dest . '/public/api/index.php')
            && !is_dir($dest . '/public')) {
            throw new RuntimeException('服务器缺少 zip 解压能力（需 PHP zip 扩展或 unzip 命令），可手动下载覆盖升级');
        }
    }

    /** 归一化 zip 条目名；返回相对路径或 null（跳过） */
    private static function normalizeEntry(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        // 拒绝路径穿越
        if (preg_match('#(?:\.\./|^/|://)#', $name)) {
            return null;
        }
        // zip 顶层可能带一层目录包裹（如 yulan-shop-v123/public/...），剥掉
        $parts = explode('/', $name);
        if (count($parts) > 1 && preg_match('/^yulan.?shop/i', $parts[0])) {
            array_shift($parts);
        }
        $rel = implode('/', $parts);
        if ($rel === '' || str_ends_with($rel, '/')) {
            return null;   // 目录条目
        }
        // 只接受已知的安全后缀，杜绝可执行文件落到 web 根
        if (!preg_match('/\.(php|html|js|css|json|sql|md|htaccess|txt|svg|webp|png|jpg|ico|example)$/i', basename($rel))
            && basename($rel) !== '.htaccess') {
            return null;
        }
        return $rel;
    }

    /** 是否属于保留路径（升级时不动） */
    private static function isPreserved(string $rel): bool
    {
        foreach (self::PRESERVE_PREFIX as $prefix) {
            if (strpos($rel, $prefix) === 0) {
                return true;
            }
        }
        // 关键本机配置文件
        if (in_array($rel, ['config/.env', 'config/installed.lock', 'config/config.php'], true)) {
            return true;
        }
        return false;
    }

    /** 升级后跑数据库迁移（保留数据，幂等） */
    private static function runMigration(string $root): void
    {
        $migrate = $root . '/bin/migrate.php';
        if (!is_file($migrate)) {
            return;
        }
        $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($migrate) . ' 2>&1';
        @exec($cmd, $out, $code);
        // 迁移失败不阻断更新主流程（幂等脚本一般可靠），仅记日志
        if ($code !== 0) {
            error_log('[update] migration exit=' . $code . ': ' . implode(' ', $out));
        }
    }

    /** 版本比较：a > b 返回正数 */
    private static function versionCompare(string $a, string $b): int
    {
        return version_compare($a, $b);
    }

    /** 取跟随重定向后的最终 URL（用于解析 release tag） */
    private static function effectiveUrl(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_NOBODY         => true,   // 只要响应头/URL，不下载正文
                CURLOPT_USERAGENT      => 'YulanFinance-Updater/1.3',
            ]);
            curl_exec($ch);
            $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 400 && is_string($final)) {
                return $final;
            }
        }
        // 回退：系统 curl 命令取最终 URL
        if (function_exists('exec')) {
            $cmd = 'curl -sL -o /dev/null -w "%{url_effective}" --max-time 20 '
                 . escapeshellarg($url) . ' 2>/dev/null';
            $out = [];
            $code2 = 0;
            @exec($cmd, $out, $code2);
            $u = implode('', $out);
            if ($code2 === 0 && strpos($u, 'http') === 0) {
                return $u;
            }
        }
        return null;
    }

    /** HTTP GET（curl 优先，支持二进制） */
    private static function httpGet(string $url, bool $binary = false): ?string
    {
        $ua = 'YulanFinance-Updater/1.3';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_HTTPHEADER     => ['Accept: application/octet-stream'],
            ]);
            $res = curl_exec($ch);
            $err = curl_errno($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($err === 0 && $code >= 200 && $code < 300 && is_string($res)) ? $res : null;
        }
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $binary ? 120 : 20,
                       'header' => "User-Agent: {$ua}\r\n"],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        if (is_string($res) && $res !== '') {
            return $res;
        }
        // 最后回退：系统 curl 命令（部分主机禁用了 PHP 的 URL fopen，但装有 curl）
        if (function_exists('exec')) {
            $bin = escapeshellarg($url);
            $out = [];
            $code = 0;
            @exec("curl -sL --max-time " . ($binary ? 180 : 20) . " -A " . escapeshellarg($ua) . " {$bin} 2>/dev/null", $out, $code);
            if ($code === 0 && $out) {
                return implode("\n", $out);
            }
        }
        return null;
    }

    /** 递归删除目录 */
    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
