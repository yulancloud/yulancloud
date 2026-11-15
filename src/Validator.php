<?php
/**
 * 输入校验 —— 白名单式，任何不合规输入直接拒绝，不做"尽力修复"
 */
declare(strict_types=1);

final class Validator
{
    /** 商品 ID：只允许小写字母数字与连字符，长度 1-32 */
    public static function productId(mixed $v): string
    {
        if (!is_string($v) || !preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $v)) {
            Response::fail('invalid_product_id', '商品编号格式不正确', 400);
        }
        return $v;
    }

    /** 分类 ID 同上 */
    public static function categoryId(mixed $v): string
    {
        if (!is_string($v) || !preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $v)) {
            Response::fail('invalid_category', '分类参数不正确', 400);
        }
        return $v;
    }

    /** 排序方式：枚举白名单，绝不把用户输入拼进 SQL */
    public static function sort(mixed $v): string
    {
        $allowed = ['featured', 'price-asc', 'price-desc', 'rating', 'newest'];
        $v = is_string($v) ? $v : 'featured';
        return in_array($v, $allowed, true) ? $v : 'featured';
    }

    /** 搜索关键词：限长、去控制字符 */
    public static function keyword(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        if (!is_string($v)) {
            Response::fail('invalid_keyword', '搜索关键词格式不正确', 400);
        }
        $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '';
        $v = trim($v);
        if (mb_strlen($v, 'UTF-8') > 40) {
            Response::fail('keyword_too_long', '搜索关键词过长', 400);
        }
        return $v;
    }

    /** 分页 */
    public static function int(mixed $v, int $min, int $max, int $default): int
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if (!is_numeric($v) || !preg_match('/^-?\d+$/', (string)$v)) {
            Response::fail('invalid_number', '数值参数必须是整数', 400);
        }
        $n = (int)$v;
        if ($n < $min || $n > $max) {
            Response::fail('out_of_range', '数值超出允许范围', 400);
        }
        return $n;
    }

    /** 姓名：1-40 字，禁止控制字符与尖括号（防存储型 XSS 的第一道闸） */
    public static function personName(mixed $v): string
    {
        $v = self::text($v, 1, 40, '收货人姓名');
        if (preg_match('/[<>{}\\\\]/', $v)) {
            Response::fail('invalid_name', '收货人姓名包含不允许的字符', 400);
        }
        return $v;
    }

    /** 手机号：中国大陆 11 位，或国际格式 + 数字 */
    public static function phone(mixed $v): string
    {
        if (!is_string($v)) {
            Response::fail('invalid_phone', '手机号格式不正确', 400);
        }
        $v = preg_replace('/[\s-]/', '', trim($v)) ?? '';
        if (!preg_match('/^(?:\+?86)?1[3-9]\d{9}$/', $v) && !preg_match('/^\+\d{6,15}$/', $v)) {
            Response::fail('invalid_phone', '请填写有效的手机号', 400);
        }
        return $v;
    }

    /** 地址：5-200 字 */
    public static function address(mixed $v): string
    {
        return self::text($v, 5, 200, '收货地址');
    }

    /** 备注：0-200 字，可空 */
    public static function note(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        return self::text($v, 0, 200, '订单备注');
    }

    /** 通用文本：长度校验 + 去控制字符 */
    public static function text(mixed $v, int $min, int $max, string $label): string
    {
        if (!is_string($v)) {
            Response::fail('invalid_text', $label . '格式不正确', 400);
        }
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        $v = trim($v);
        $len = mb_strlen($v, 'UTF-8');
        if ($len < $min) {
            Response::fail('text_too_short', $label . '不能为空', 400);
        }
        if ($len > $max) {
            Response::fail('text_too_long', $label . '不能超过 ' . $max . ' 个字符', 400);
        }
        return $v;
    }

    /** 幂等键：客户端生成的 UUID 风格字符串 */
    public static function idempotencyKey(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $v)) {
            Response::fail('invalid_idempotency_key', '幂等键格式不正确', 400);
        }
        return $v;
    }

    /** 订单号：SH + 日期 + 随机，只允许大写字母数字 */
    public static function orderNo(mixed $v): string
    {
        if (!is_string($v) || !preg_match('/^[A-Z0-9]{8,32}$/', $v)) {
            Response::fail('invalid_order_no', '订单号格式不正确', 400);
        }
        return $v;
    }
}
