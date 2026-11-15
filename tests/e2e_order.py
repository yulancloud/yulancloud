import asyncio, json
from playwright.async_api import async_playwright

BASE = "http://127.0.0.1:8090"

async def main():
    out = {}
    async with async_playwright() as p:
        b = await p.chromium.launch()
        page = await b.new_page(viewport={"width": 1440, "height": 1000})
        errs = []
        page.on("console", lambda m: errs.append(m.text) if m.type == "error" else None)
        page.on("pageerror", lambda e: errs.append(str(e)))

        # 1. 详情页加购
        await page.goto(BASE + "/product.html?id=p4", wait_until="networkidle")
        await page.wait_for_timeout(1000)
        out["detail_stock"] = await page.locator("#p-stock").inner_text()
        await page.locator("#btn-cart").click()
        await page.wait_for_timeout(400)

        # 2. 购物袋
        await page.goto(BASE + "/cart.html", wait_until="networkidle")
        await page.wait_for_timeout(800)
        out["cart_lines"] = await page.locator(".cart-item").count()
        out["cart_total_before"] = await page.locator("#s-total").inner_text()

        # 3. 空表单提交 → 应被前端拦下
        await page.locator("#checkout").click()
        await page.wait_for_timeout(400)
        out["empty_submit_blocked"] = await page.locator("#order-result").count() == 0

        # 4. 填表提交
        await page.fill("#f-name", "测试用户")
        await page.fill("#f-phone", "13800138000")
        await page.fill("#f-address", "上海市静安区巨鹿路880号2楼")
        await page.fill("#f-note", "浏览器端到端测试")
        await page.locator("#checkout").click()
        await page.wait_for_timeout(2500)

        out["order_result_visible"] = await page.locator("#order-result").count() > 0
        out["order_result_text"] = (await page.locator("#order-result").inner_text())[:200] if out["order_result_visible"] else None
        out["cart_cleared"] = await page.locator(".cart-item").count() == 0
        await page.screenshot(path="/tmp/shot-checkout.png")

        # 5. 跳订单详情
        link = page.locator("#order-result a")
        if await link.count():
            href = await link.get_attribute("href")
            out["order_link"] = href
            await page.goto(BASE + "/" + href, wait_until="networkidle")
            await page.wait_for_timeout(1200)
            out["order_page"] = {
                "head": (await page.locator("#order-panel .cart-list__head").inner_text())[:80] if await page.locator("#order-panel .cart-list__head").count() else None,
                "items": await page.locator("#order-panel .cart-item").count(),
                "total": await page.locator("#o-total").inner_text(),
                "sub": await page.locator("#o-sub").inner_text(),
            }
            await page.screenshot(path="/tmp/shot-order.png")

        # 6. 篡改 token → 应显示失败
        if out.get("order_link"):
            bad = out["order_link"].split("&token=")[0] + "&token=" + "0"*32
            await page.goto(BASE + "/" + bad, wait_until="networkidle")
            await page.wait_for_timeout(1000)
            out["tampered_token_blocked"] = "无法显示订单" in (await page.locator("#order-panel").inner_text())

        out["console_errors"] = errs
        await b.close()

    # 旧版只 print JSON、无断言、无非零退出码：页面全错也算通过。
    failures = []
    if errs:
        failures.append("存在控制台/页面错误: " + "; ".join(map(str, errs[:5])))
    if not out.get("order_result_visible"):
        failures.append("下单后没有显示订单结果")
    if not out.get("cart_cleared"):
        failures.append("下单后购物车未清空")
    if out.get("tampered_token_blocked") is False:
        failures.append("篡改订单 token 未被拦截")
    out["assert_failures"] = failures
    out["ok"] = not failures
    print(json.dumps(out, ensure_ascii=False, indent=2))
    if failures:
        raise SystemExit(1)

asyncio.run(main())
