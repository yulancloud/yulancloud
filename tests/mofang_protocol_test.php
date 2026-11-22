<?php
require_once '/workspace/shop-php-v5.5-fixed/src/MofangApi.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ $name $extra\n"; }
    else { $fail++; echo "  ✗ $name $extra\n"; }
}
// 清理 JWT 缓存
@unlink('/workspace/shop-php-v5.5-fixed/data/mofang_jwt_901.json');
@unlink('/workspace/shop-php-v5.5-fixed/data/mofang_jwt_902.json');

echo "=== 测试 1：v10 魔方云协议 ===\n";
$v10 = new MofangApi([
    'id' => 901, 'kind' => 'cloud', 'api_type' => 'v10',
    'base_url' => 'http://127.0.0.1:8123', 'use_https' => 0,
    'api_user' => 'agent01', 'api_key' => 'secret8888',
]);
check('api_type = v10', $v10->apiType() === 'v10');
$t = $v10->test();
check('test() 连通', $t['ok'] === true, json_encode($t, JSON_UNESCAPED_UNICODE));
$prods = $v10->fetchProducts();
check('拉取产品 2 个', is_array($prods) && count($prods) === 2, json_encode($prods, JSON_UNESCAPED_UNICODE));
check('产品字段正确', is_array($prods) && ($prods[0]['external_id'] ?? '') === '11' && ($prods[0]['name'] ?? '') === '云主机 2核4G');
$prov = $v10->provision('11', ['order_item_id' => '555', 'user_id' => '88']);
check('下单开通成功', ($prov['ok'] ?? false) === true, json_encode($prov, JSON_UNESCAPED_UNICODE));
check('交付实例 external_id=7001', ($prov['instance']['external_id'] ?? '') === '7001');
$act = $v10->instanceAction('7001', 'start');
check('开机操作成功', ($act['ok'] ?? false) === true, json_encode($act, JSON_UNESCAPED_UNICODE));

echo "\n=== 测试 2：老财务 finance 协议 ===\n";
$fin = new MofangApi([
    'id' => 902, 'kind' => 'finance', 'api_type' => 'finance',
    'base_url' => 'http://127.0.0.1:8123', 'use_https' => 0,
    'api_user' => 'agent01', 'api_key' => 'secret8888',
]);
check('api_type = finance', $fin->apiType() === 'finance');
$prods2 = $fin->fetchProducts();
check('老财务拉取产品', is_array($prods2) && count($prods2) === 1 && ($prods2[0]['external_id'] ?? '') === '21', json_encode($prods2, JSON_UNESCAPED_UNICODE));

echo "\n=== 测试 3：鉴权失败应明确报错 ===\n";
$bad = new MofangApi([
    'id' => 903, 'kind' => 'cloud', 'api_type' => 'v10',
    'base_url' => 'http://127.0.0.1:8123', 'use_https' => 0,
    'api_user' => 'wrong', 'api_key' => 'wrongpass',
]);
@unlink('/workspace/shop-php-v5.5-fixed/data/mofang_jwt_903.json');
$tbad = $bad->test();
check('错误凭据被拒绝', $tbad['ok'] === false, json_encode($tbad, JSON_UNESCAPED_UNICODE));
check('错误信息含"拒绝/错误"', strpos($bad->lastError(), '拒绝') !== false || strpos($bad->lastError(), '错误') !== false, $bad->lastError());

echo "\n==================================\n";
echo "结果：PASS=$pass FAIL=$fail\n";
exit($fail === 0 ? 0 : 1);
