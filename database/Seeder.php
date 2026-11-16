<?php
/**
 * 种子数据写入
 *
 * 从 bin/init_db.php 抽出来，让「命令行初始化」和「网页安装向导」共用同一份逻辑，
 * 避免两处实现漂移（以前安装向导试图 exec 调用 CLI，遇到 disable_functions 就崩）。
 *
 * 全部使用 UPSERT，幂等：重复执行不会产生重复分类/商品，也不会重复灌评价。
 *
 * 【v1.2 起】全新安装默认「干净」：不再预置演示分类与商品，店主打开后台即可
 * 直接添加自己的商品，不用先删一堆示例数据。需要演示数据时显式传 $withDemo=true
 * （CLI: php bin/init_db.php --with-demo），两条安装路径行为一致。
 */
declare(strict_types=1);

final class Seeder
{
    /**
     * @param PDO  $pdo      已连接且已建表的数据库
     * @param bool $isNew    是否全新库（保留参数以兼容旧调用；评价早已不重灌）
     * @param bool $withDemo 是否灌入演示分类/商品，默认 false（v1.2 干净安装）
     * @return bool          是否成功
     */
    public static function run(PDO $pdo, bool $isNew = false, bool $withDemo = false): bool
    {
        $seedFile = dirname(__DIR__) . '/database/seed.php';
        if (!is_file($seedFile)) {
            throw new RuntimeException('缺少 database/seed.php');
        }
        $seed = require $seedFile;
        if (!is_array($seed)) {
            throw new RuntimeException('seed.php 返回值不是数组');
        }

        $pdo->beginTransaction();
        try {
            // ---------- 演示分类 / 商品（v1.2 起仅显式要求时灌入） ----------
            // 干净安装时这里整段跳过：建表已完成，分类表、商品表均为空。
            if ($withDemo) {
                // ---------- 分类 ----------
                $catStmt = $pdo->prepare(
                    'INSERT INTO categories (id, label, sort) VALUES (?, ?, ?)
                     ON CONFLICT(id) DO UPDATE SET label = excluded.label, sort = excluded.sort'
                );
                foreach ($seed['categories'] as $c) {
                    $catStmt->execute([$c['id'], $c['label'], $c['sort']]);
                }

                // ---------- 商品 ----------
                $pStmt = $pdo->prepare(
                    'INSERT INTO products
                     (id, sku, name, sub, cat_id, price_cents, was_cents, badge, img, rating, sold, stock, description, story, flaws, status, sort)
                     VALUES (:id, :sku, :name, :sub, :cat, :price, :was, :badge, :img, :rating, :sold, :stock, :desc, :story, :flaws, \'active\', :sort)
                     ON CONFLICT(id) DO UPDATE SET
                        sku = excluded.sku, name = excluded.name, sub = excluded.sub, cat_id = excluded.cat_id,
                        price_cents = excluded.price_cents, was_cents = excluded.was_cents,
                        badge = excluded.badge, img = excluded.img, rating = excluded.rating,
                        sold = excluded.sold, description = excluded.description, story = excluded.story,
                        sort = excluded.sort'
                );

                $delColors = $pdo->prepare('DELETE FROM product_colors WHERE product_id = ?');
                $delSizes  = $pdo->prepare('DELETE FROM product_sizes WHERE product_id = ?');
                $delSpecs  = $pdo->prepare('DELETE FROM product_specs WHERE product_id = ?');
                $delCare   = $pdo->prepare('DELETE FROM product_care WHERE product_id = ?');

                $insColor = $pdo->prepare('INSERT INTO product_colors (product_id, name, hex, sort) VALUES (?, ?, ?, ?)');
                $insSize  = $pdo->prepare('INSERT INTO product_sizes (product_id, name, extra_cents, sort) VALUES (?, ?, ?, ?)');
                $insSpec  = $pdo->prepare('INSERT INTO product_specs (product_id, k, v, sort) VALUES (?, ?, ?, ?)');
                $insCare  = $pdo->prepare('INSERT INTO product_care (product_id, body, sort) VALUES (?, ?, ?)');

                foreach ($seed['products'] as $i => $p) {
                    $pStmt->execute([
                        ':id' => $p['id'], ':sku' => $p['sku'], ':name' => $p['name'], ':sub' => $p['sub'],
                        ':cat' => $p['cat'], ':price' => $p['price'] * 100,
                        ':was' => $p['was'] === null ? null : $p['was'] * 100,
                        ':badge' => $p['badge'], ':img' => $p['img'], ':rating' => $p['rating'],
                        ':sold' => $p['sold'], ':stock' => $p['stock'], ':desc' => $p['desc'],
                        ':story' => $p['story'], ':flaws' => $p['flaws'] ?? '',
                        ':sort' => ($i + 1) * 10,
                    ]);

                    $delColors->execute([$p['id']]);
                    $delSizes->execute([$p['id']]);
                    $delSpecs->execute([$p['id']]);
                    $delCare->execute([$p['id']]);

                    foreach ($p['colors'] as $k => $c) {
                        $insColor->execute([$p['id'], $c[0], $c[1], $k]);
                    }
                    foreach ($p['sizes'] as $k => $s) {
                        $insSize->execute([$p['id'], $s[0], $s[1] * 100, $k]);
                    }
                    foreach ($p['specs'] as $k => $s) {
                        $insSpec->execute([$p['id'], $s[0], $s[1], $k]);
                    }
                    foreach ($p['care'] as $k => $c) {
                        $insCare->execute([$p['id'], $c, $k]);
                    }
                }
            }

            // ---------- 评价 ----------
            // v5.5：评价只来自真实用户发表，安装/重置不再灌入演示假评价。
            // 否则店主清空评价后，任何一次初始化都会把假评价（以及假的 4.9 分）重新灌回来。

            // ---------- 站点文案块 ----------
            $blockStmt = $pdo->prepare(
                'INSERT INTO site_blocks (key, value) VALUES (?, ?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value'
            );
            $blockStmt->execute(['marquee', json_encode($seed['marquee'], JSON_UNESCAPED_UNICODE)]);
            $blockStmt->execute(['thinking', json_encode($seed['thinking'], JSON_UNESCAPED_UNICODE)]);

            $pdo->prepare(
                'INSERT INTO schema_meta (key, value) VALUES (?, ?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value'
            )->execute(['version', '5.5.0']);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
