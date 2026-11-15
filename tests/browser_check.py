import asyncio, json, sys
from playwright.async_api import async_playwright

BASE = "http://127.0.0.1:8090"

async def main():
    out = {}
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        page = await browser.new_page(viewport={"width": 1440, "height": 1000})
        errors, api_calls = [], []

        page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
        page.on("pageerror", lambda e: errors.append(str(e)))
        page.on("response", lambda r: api_calls.append((r.url.replace(BASE, ""), r.status)) if "/api/" in r.url else None)

        # --- 首页 ---
        await page.goto(BASE + "/index.html", wait_until="networkidle")
        await page.wait_for_timeout(1200)
        out["index"] = {
            "source": await page.get_attribute("html", "data-api-source"),
            "cards": await page.locator("#grid .card").count(),
            "result_text": await page.locator("#result-count").inner_text(),
            "chips": await page.locator("#chips .chip").count(),
            "first_name": await page.locator("#grid .card .card__name").first.inner_text(),
            "img_ok": await page.evaluate("""() => {
                const imgs=[...document.querySelectorAll('#grid img')];
                return imgs.filter(i=>i.complete && i.naturalWidth>0).length + '/' + imgs.length;
            }"""),
            "overflow": await page.evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth"),
        }
        await page.screenshot(path="/tmp/shot-index.png", full_page=False)

        # 分类筛选（点"随行"）
        await page.locator('#chips .chip[data-cat="carry"]').click()
        await page.wait_for_timeout(400)
        out["filter_carry"] = {
            "cards": await page.locator("#grid .card").count(),
            "result": await page.locator("#result-count").inner_text(),
        }
        # 搜索（关键词只应命中真实数据）
        await page.locator('#chips .chip[data-cat="all"]').click()
        box = page.locator("input[type=search], #search, .head__search input").first
        if await box.count():
            await box.fill("耳机")
            await page.wait_for_timeout(500)
            out["search_earphone"] = {
                "cards": await page.locator("#grid .card").count(),
                "result": await page.locator("#result-count").inner_text(),
            }

        # --- 详情页 ---
        await page.goto(BASE + "/product.html?id=p3", wait_until="networkidle")
        await page.wait_for_timeout(1200)
        out["product_p3"] = {
            "source": await page.get_attribute("html", "data-api-source"),
            "title": await page.title(),
            "name": await page.locator("#p-name").inner_text(),
            "price": await page.locator("#p-price").inner_text(),
            "stock": await page.locator("#p-stock").inner_text(),
            "sku": await page.locator("#sku").inner_text(),
            "swatches": await page.locator("#color-swatches .swatch").count(),
            "sizes": await page.locator("#size-swatches .swatch").count(),
            "specs": await page.locator("#spec-list li").count(),
            "care": await page.locator("#care-list li").count(),
            "main_img": await page.evaluate("(() => {const i=document.getElementById('main-img'); return i.complete && i.naturalWidth>0;})()"),
            "overflow": await page.evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth"),
        }
        await page.screenshot(path="/tmp/shot-product.png")

        # --- 加购 → 购物车 ---
        await page.locator("#btn-cart").click()
        await page.wait_for_timeout(500)
        await page.goto(BASE + "/cart.html", wait_until="networkidle")
        await page.wait_for_timeout(900)
        out["cart"] = {
            "source": await page.get_attribute("html", "data-api-source"),
            "lines": await page.locator(".cart-item").count(),
            "subtotal": await page.locator("#s-sub").inner_text(),
            "ship": await page.locator("#s-ship").inner_text(),
            "total": await page.locator("#s-total").inner_text(),
            "name": await page.locator(".cart-item__name").first.inner_text() if await page.locator(".cart-item__name").count() else None,
            "img_ok": await page.evaluate("""() => {
                const imgs=[...document.querySelectorAll('.cart-item img')];
                return imgs.filter(i=>i.complete && i.naturalWidth>0).length + '/' + imgs.length;
            }"""),
        }
        await page.screenshot(path="/tmp/shot-cart.png")

        # --- 移动端 390px ---
        m = await browser.new_page(viewport={"width": 390, "height": 844})
        await m.goto(BASE + "/index.html", wait_until="networkidle")
        await m.wait_for_timeout(900)
        out["mobile"] = {
            "source": await m.get_attribute("html", "data-api-source"),
            "overflow": await m.evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth"),
            "scroll_w": await m.evaluate("document.documentElement.scrollWidth"),
        }
        await m.screenshot(path="/tmp/shot-mobile.png")

        out["api_calls"] = api_calls
        out["console_errors"] = errors
        await browser.close()

    # 旧版只 print JSON、不做任何断言：页面全错也 exit 0（假绿灯）。
    failures = []
    check = lambda ok, msg: None if ok else failures.append(msg)
    check(not errors, "存在控制台/页面错误: " + "; ".join(map(str, errors[:5])))
    check(out.get("index", {}).get("cards", 0) > 0, "首页没有渲染出商品卡")
    check(out.get("index", {}).get("overflow") is False, "首页出现横向溢出")
    check(out.get("product_p3", {}).get("swatches", 0) > 0, "商品页没有颜色选项")
    check(out.get("product_p3", {}).get("sizes", 0) > 0, "商品页没有规格选项")
    check(out.get("product_p3", {}).get("specs", 0) > 0, "商品页没有参数列表")
    check(out.get("cart", {}).get("lines", 0) > 0, "加购后购物车为空")
    bad_api = [x for x in api_calls if x[1] >= 400]
    check(not bad_api, "存在失败的 API 请求: " + str(bad_api[:5]))

    out["assert_failures"] = failures
    out["ok"] = not failures
    print(json.dumps(out, ensure_ascii=False, indent=2))
    if failures:
        raise SystemExit(1)

asyncio.run(main())
