<?php
/**
 * 极简 .env 读取器
 *
 * 为什么不用 dotenv 依赖：本项目零 Composer 依赖，一个 40 行的读取器足够。
 *
 * 安全要点：
 *  - 只解析 KEY=VALUE，不做任何 shell 求值（绝不用 eval / shell_exec）
 *  - 不覆盖已存在的真实环境变量（容器/面板注入的优先）
 *  - 文件位于 config/ 之下，在 Web 根 public/ 之外，无法通过 HTTP 访问
 *  - 值两端的引号会被去掉，但内部内容原样保留（密钥可能含 # $ & 等字符）
 */
declare(strict_types=1);

final class Env
{
    /**
     * .env 解析结果的进程内副本。
     * 宝塔等面板默认把 putenv 列入 disable_functions，此时 getenv() 读不到
     * 我们自己写入的值，必须由这里兜底，否则 .env 配置会静默丢失。
     */
    private static array $vars = [];

    /**
     * 统一读取入口：真实环境变量 > config/.env。
     * 所有业务代码都应走这里，而不是直接 getenv()。
     */
    public static function get(string $key)
    {
        $v = getenv($key);
        if ($v !== false) {
            return $v;
        }
        return self::$vars[$key] ?? false;
    }

    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
                continue;
            }
            // 已存在的真实环境变量优先，绝不覆盖
            if (getenv($key) !== false) {
                continue;
            }
            $val = trim(substr($line, $pos + 1));

            // 去掉包裹引号（仅当首尾成对出现）
            $len = strlen($val);
            if ($len >= 2) {
                $first = $val[0];
                $last  = $val[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $inner = substr($val, 1, -1);
                    if ($first === '"') {
                        // Installer::envQuote() 把 \ 写成 \\、" 写成 \"，读取端必须对称还原，
                        // 否则 EPAY_KEY / 站点地址里的特殊字符会被原样带上反斜杠。
                        // 只处理「反斜杠 + 任意字符」这一种转义，与写入端一一对应。
                        $inner = preg_replace_callback(
                            '/\\\\(.)/s',
                            static fn(array $m): string => $m[1],
                            $inner
                        ) ?? $inner;
                    }
                    $val = $inner;
                }
            }
            // 先存进程内副本：putenv 被禁用时这是唯一可靠的读取来源
            self::$vars[$key] = $val;
            // 面板禁用 putenv 时 function_exists() 为 false，直接跳过，绝不调用
            if (function_exists('putenv')) {
                putenv($key . '=' . $val);   // 让子进程 / CLI 也能继承
            }
            $_ENV[$key] = $val;
        }
    }
}
