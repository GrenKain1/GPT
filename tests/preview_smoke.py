#!/usr/bin/env python3
"""Check compiled GitHub Pages UI, mobile layout and inert demo controls."""
from __future__ import annotations

import argparse
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import tempfile
from threading import Thread
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent.parent


class QuietHandler(SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass


def check_static_preview(output: Path, chromium: str) -> dict:
    manifest = json.loads((output / '.fantasydream-preview.json').read_text(encoding='utf-8'))
    base_path = urlparse(manifest['site_url']).path.rstrip('/')
    first_product = next(iter(sorted((output / 'en/product').glob('*/index.html')))).parent.name
    with tempfile.TemporaryDirectory(prefix='fantasydream-static-check-') as temporary:
        served = Path(temporary)
        mount = served / base_path.lstrip('/')
        mount.parent.mkdir(parents=True, exist_ok=True)
        if mount == served:
            served = output
        else:
            mount.symlink_to(output, target_is_directory=True)
        server = ThreadingHTTPServer(('127.0.0.1', 0), partial(QuietHandler, directory=str(served)))
        Thread(target=server.serve_forever, daemon=True).start()
        base = f'http://127.0.0.1:{server.server_port}{base_path}'
        errors: list[str] = []
        mutations: list[str] = []
        checked = 0
        try:
            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(executable_path=chromium, headless=True, args=['--no-sandbox', '--disable-dev-shm-usage'])
                page = browser.new_page(viewport={'width': 1280, 'height': 900}, reduced_motion='reduce')
                page.on('pageerror', lambda error: errors.append(str(error)))
                page.on('request', lambda request: mutations.append(request.method + ' ' + request.url) if request.method not in {'GET', 'HEAD'} else None)

                def visit(route: str) -> None:
                    nonlocal checked
                    response = page.goto(base + '/' + route, wait_until='load')
                    assert response and response.status == 200, route
                    assert page.locator('h1').count() == 1, 'One heading required: ' + route
                    assert page.locator('#preview-notice').count() == 1, 'One preview notice required: ' + route
                    assert page.locator('form, input[name=csrf]').count() == 0, 'Server-only form or CSRF input found: ' + route
                    assert page.locator('meta[name=robots]').get_attribute('content') == 'noindex,nofollow,noarchive,nosnippet', 'Preview must be noindex: ' + route
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), 'Horizontal overflow: ' + route
                    assert not errors, (route, errors)
                    checked += 1

                for document in sorted(output.rglob('index.html')):
                    if document == output / 'index.html':
                        continue
                    visit(document.parent.relative_to(output).as_posix() + '/')

                visit('ru/product/' + first_product + '/')
                total = page.locator('[data-order-total]').inner_text()
                page.locator('#order-quantity').fill('2')
                page.locator('#order-quantity').dispatch_event('change')
                assert page.locator('[data-order-total]').inner_text() != total, 'Quantity must recalculate the preview price.'
                page.locator('[data-order-form] details').evaluate('(element) => element.open = true')
                page.locator('[data-order-form] [data-preview-submit]').click()
                assert 'не отправлена' in page.locator('[data-order-feedback]').inner_text(), 'Demo submission must explain that no request was sent.'
                assert '/ru/product/' + first_product + '/' in page.url, 'Demo submission must not navigate.'
                assert not mutations, mutations

                visit('preview/admin/products/' + first_product + '/')
                original_rows = page.locator('[data-repeat-list=sizes] .repeat-row').count()
                page.locator('[data-add-row=sizes]').click()
                assert page.locator('[data-repeat-list=sizes] .repeat-row').count() == original_rows + 1, 'Admin Add size control must work.'
                page.locator('[data-preview-submit]').first.click()
                assert 'not saved' in page.locator('[data-preview-feedback]').inner_text(), 'Admin demo must never imitate a saved change.'
                assert not mutations, mutations

                visit('en/catalog/')
                products = page.locator('[data-product-card]:visible').count()
                page.locator('[data-catalog-search]').fill('nonexistent-preview-model')
                assert page.locator('[data-catalog-empty]').is_visible(), 'Catalog must show the empty filter result.'
                page.locator('[data-catalog-reset]').click()
                assert page.locator('[data-product-card]:visible').count() == products, 'Reset must restore all catalog products.'

                for width in [320, 375, 768]:
                    page.set_viewport_size({'width': width, 'height': 812})
                    for language in ['he', 'ru', 'en']:
                        for route in ['', 'catalog/', 'product/' + first_product + '/', 'contact/']:
                            visit(language + '/' + route)
                assert not errors, errors
                assert not mutations, mutations
                browser.close()
            return {'pages_and_mobile_views': checked, 'javascript_errors': len(errors), 'network_mutations': len(mutations), 'order_and_admin_submission': 'blocked locally', 'catalog_and_quantity_controls': 'passed'}
        finally:
            server.shutdown()
            server.server_close()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'docs')
    parser.add_argument('--chromium', default='/usr/bin/chromium')
    args = parser.parse_args()
    print(json.dumps(check_static_preview(args.output.resolve(), args.chromium), ensure_ascii=False))
