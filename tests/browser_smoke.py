"""Browser checks used by smoke.py --browser (Playwright + system Chromium)."""
from __future__ import annotations

from pathlib import Path
from playwright.sync_api import sync_playwright


def run_browser_checks(base: str, product_slug: str, screenshots: Path | None = None):
    visited = 0
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path="/usr/bin/chromium", headless=True, args=["--no-sandbox", "--disable-dev-shm-usage"])
        context = browser.new_context(reduced_motion="reduce")
        page = context.new_page()
        errors: list[str] = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.on("console", lambda message: errors.append(message.text) if message.type == "error" else None)
        for width, height in [(320, 700), (375, 812), (768, 1024), (1280, 900), (1920, 1080)]:
            page.set_viewport_size({"width":width, "height":height})
            for language in ["he", "ru", "en"]:
                for route in ["", "catalog/", "contact/", f"product/{product_slug}/"]:
                    url = f"{base}/{language}/{route}"
                    errors.clear()
                    response = page.goto(url, wait_until="networkidle")
                    assert response and response.status == 200, url
                    assert page.locator("h1").count() == 1, "One page heading required: " + url
                    assert page.locator("main").count() == 1, "One main landmark required: " + url
                    assert page.locator("html").get_attribute("dir") == ("rtl" if language == "he" else "ltr"), url
                    if not page.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 2"):
                        offenders = page.evaluate("""() => Array.from(document.querySelectorAll('body *')).map(el=>({el,r:el.getBoundingClientRect()})).filter(x=>x.r.width>0&&(x.r.left < -2 || x.r.right > innerWidth+2)).slice(-15).map(x=>({tag:x.el.tagName,classes:x.el.className,width:x.r.width,left:x.r.left,right:x.r.right,text:x.el.textContent.slice(0,90)}))""")
                        if screenshots:
                            screenshots.mkdir(parents=True, exist_ok=True)
                            page.screenshot(path=str(screenshots / f"overflow-{language}-{width}.png"), full_page=True)
                        raise AssertionError(f"Horizontal overflow at {width}px: {url}; {offenders}")
                    assert not page.locator("img:not([alt])").count(), "Image alt attributes required: " + url
                    collapsed_images = page.evaluate("""() => Array.from(document.images).filter(img => !img.closest('[hidden]') && img.getBoundingClientRect().height < 1).map(img => img.getAttribute('src'))""")
                    assert not collapsed_images, "Images must occupy visible space: " + str(collapsed_images) + " at " + url
                    controls = page.evaluate("""() => Array.from(document.querySelectorAll('input:not([type=hidden]),select,textarea,button')).filter(el => el.getClientRects().length && !el.closest('[aria-hidden=true]') && !(el.labels && el.labels.length) && !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby') && !el.textContent.trim()).map(el => el.outerHTML.slice(0,180))""")
                    assert not controls, "Unlabelled controls: " + str(controls)
                    assert not errors, "Browser errors at " + url + ": " + str(errors)
                    visited += 1
                    if screenshots and route == "" and ((width == 375 and language == "he") or (width == 1280 and language == "en")):
                        screenshots.mkdir(parents=True, exist_ok=True)
                        page.screenshot(path=str(screenshots / f"{language}-{width}.png"), full_page=True)

        page.set_viewport_size({"width":375,"height":812})
        page.goto(base + "/en/", wait_until="networkidle")
        menu = page.locator("[data-menu-toggle]")
        assert menu.is_visible(), "Mobile menu button must be visible"
        menu.click()
        assert menu.get_attribute("aria-expanded") == "true", "Menu must expose its open state"
        assert page.locator("#site-navigation").is_visible()
        menu.click()
        assert menu.get_attribute("aria-expanded") == "false"

        opener = page.locator("[data-accessibility-open]")
        opener.click()
        panel = page.locator("#accessibility-panel")
        assert panel.is_visible()
        assert page.evaluate("document.querySelector('#accessibility-panel').contains(document.activeElement)"), "Dialog must receive focus"
        for setting in ["contrast", "large", "motion"]:
            panel.locator(f'[data-accessibility-setting="{setting}"]').check()
        assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 2"), "Accessibility preferences must not cause mobile overflow"
        for _ in range(8):
            page.keyboard.press("Tab")
            assert page.evaluate("document.querySelector('#accessibility-panel').contains(document.activeElement)"), "Modal focus must remain inside the panel"
        page.keyboard.press("Escape")
        assert not panel.is_visible(), "Escape must close accessibility preferences"
        assert page.evaluate("document.activeElement.hasAttribute('data-accessibility-open')"), "Dialog must restore opener focus"
        page.reload(wait_until="networkidle")
        opener.click()
        for setting in ["contrast", "large", "motion"]:
            assert panel.locator(f'[data-accessibility-setting="{setting}"]').is_checked(), "Preferences must survive reload"
        panel.locator("[data-accessibility-reset]").click()
        for setting in ["contrast", "large", "motion"]:
            assert not panel.locator(f'[data-accessibility-setting="{setting}"]').is_checked()
        page.keyboard.press("Escape")

        page.goto(base + "/en/catalog/", wait_until="networkidle")
        search = page.locator("[data-catalog-search]")
        search.fill("definitely-no-mattress-with-this-name")
        assert page.locator("[data-catalog-empty]").is_visible(), "Search must display a no-results message"
        page.locator("[data-catalog-reset]").click()
        assert not page.locator("[data-catalog-empty]").is_visible()
        assert page.locator("[data-product-card]:visible").count() > 0

        page.goto(base + f"/en/product/{product_slug}/", wait_until="networkidle")
        assert page.locator("[data-gallery-slide]:visible").count() == 1
        slides = page.locator("[data-gallery-slide]").count()
        if slides == 1:
            assert not page.locator("[data-gallery-next]").count(), "Single photo must have no carousel controls"
        else:
            next_button = page.locator("[data-gallery-next]")
            next_button.click()
            assert not page.locator("[data-gallery-slide]").first.is_visible()
            assert page.locator("[data-gallery-slide]").nth(1).is_visible()
            page.locator("[data-gallery-prev]").click()
            assert page.locator("[data-gallery-slide]").first.is_visible()
        page.locator("#order-quantity").fill("2")
        page.locator("#order-quantity").dispatch_event("change")
        total = page.locator("[data-order-total]").inner_text().replace(",", "")
        assert "2469.12" in total, "Quantity must update the displayed server-catalog subtotal: " + total
        page.locator("details.order-details > summary").click()
        page.locator("#order-name").fill("QA Browser Customer")
        page.locator("#order-email").fill("browser@example.test")
        page.locator("input[name=consent]").check()
        page.locator(".order-submit").click()
        page.locator("[data-order-feedback]").wait_for(state="visible")
        assert "sent" in page.locator("[data-order-feedback]").inner_text().lower(), "Browser order must be submitted successfully"
        assert not errors, "Browser interaction errors: " + str(errors)
        context.close()

        plain = browser.new_context(java_script_enabled=False, viewport={"width":375,"height":812})
        no_js = plain.new_page()
        no_js.goto(base + "/he/", wait_until="networkidle")
        assert no_js.locator("#site-navigation").is_visible(), "Navigation must work with JavaScript disabled"
        no_js.goto(base + f"/en/product/{product_slug}/", wait_until="networkidle")
        assert no_js.locator("[data-gallery-slide]").first.is_visible()
        assert no_js.locator("[data-order-form]").get_attribute("action").endswith("order.php"), "Order must retain native POST fallback"
        plain.close()
        browser.close()
    print(f"Browser: {visited} responsive page/language checks; menu, keyboard dialog, saved preferences, filters, gallery, subtotal, email form and no-JS fallback passed.")


def run_carousel_check(base: str, product_slug: str):
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path="/usr/bin/chromium", args=["--no-sandbox", "--disable-dev-shm-usage"])
        context = browser.new_context(viewport={"width":375,"height":812}, reduced_motion="reduce")
        page = context.new_page()
        page.goto(base + f"/he/product/{product_slug}/", wait_until="networkidle")
        slides = page.locator("[data-gallery-slide]")
        assert slides.count() == 2
        assert slides.first.is_visible()
        assert not slides.nth(1).is_visible()
        page.locator("[data-gallery-next]").click()
        assert not slides.first.is_visible()
        assert slides.nth(1).is_visible()
        assert page.locator('[data-gallery-index="1"]').get_attribute("aria-pressed") == "true"
        page.locator("[data-gallery-prev]").click()
        assert slides.first.is_visible()
        assert page.locator('[data-gallery-index="0"]').get_attribute("aria-pressed") == "true"
        context.close()
        plain = browser.new_context(java_script_enabled=False, viewport={"width":375,"height":812})
        page = plain.new_page()
        page.goto(base + f"/he/product/{product_slug}/", wait_until="networkidle")
        assert page.locator("[data-gallery-slide]:visible").count() == 2, "All product photos must remain accessible without JavaScript"
        plain.close()
        browser.close()
    print("Browser: two-photo carousel navigation and no-JS photo fallback passed.")
