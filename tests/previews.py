"""Render previews from the real default catalog in an isolated temporary copy."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

from playwright.sync_api import sync_playwright


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default=shutil.which("php") or "/workspace/runtime82/bin/php")
    parser.add_argument("--output", type=Path, default=Path(__file__).resolve().parent.parent / "dist/previews")
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="fantasydream-previews-") as temporary:
        root = Path(temporary) / "site"
        source = Path(__file__).resolve().parent.parent
        shutil.copytree(source, root, ignore=shutil.ignore_patterns(".git", "config.local.php", "content.json", "setup-token.txt", "runtime", "dist", "__pycache__"))
        with socket.socket() as available:
            available.bind(("127.0.0.1", 0))
            port = available.getsockname()[1]
        base = f"http://127.0.0.1:{port}"
        php = "require " + json.dumps(str(root / "private/app.php")) + "; fd_install('previewadmin', 'disposable-preview-password', " + json.dumps(base) + ", 'preview-admin-private-2026'); $p=fd_data()['products'][0]; echo $p['slug'];"
        slug = subprocess.check_output([args.php, "-r", php], text=True)
        with open(Path(temporary) / "server.log", "w") as log:
            server = subprocess.Popen([args.php, "-S", f"127.0.0.1:{port}", "-t", str(root / "public_html"), str(root / "tools/router.php")], cwd=root, stdout=log, stderr=log)
            try:
                for _ in range(50):
                    try:
                        urllib.request.urlopen(base + "/he/", timeout=1).close()
                        break
                    except urllib.error.URLError:
                        time.sleep(0.1)
                with sync_playwright() as playwright:
                    browser = playwright.chromium.launch(executable_path="/usr/bin/chromium", args=["--no-sandbox", "--disable-dev-shm-usage"])
                    context = browser.new_context(reduced_motion="reduce")
                    page = context.new_page()
                    for filename, route, width, height in [("home-he-desktop", "/he/", 1440, 1000), ("home-ru-mobile", "/ru/", 375, 812), ("product-en-desktop", f"/en/product/{slug}/", 1440, 1000)]:
                        page.set_viewport_size({"width":width,"height":height})
                        page.goto(base + route, wait_until="networkidle")
                        page.evaluate("""async () => { const images = Array.from(document.images); images.forEach(img => img.loading = 'eager'); await Promise.all(images.map(img => img.decode().catch(() => {}))); }""")
                        page.screenshot(path=str(args.output / (filename + ".png")), full_page=True)
                    page.set_viewport_size({"width":1440,"height":1000})
                    page.goto(base + "/preview-admin-private-2026/", wait_until="networkidle")
                    page.locator("#username").fill("previewadmin")
                    page.locator("#password").fill("disposable-preview-password")
                    page.get_by_role("button", name="Sign in", exact=True).click()
                    page.wait_for_load_state("networkidle")
                    page.screenshot(path=str(args.output / "admin-desktop.png"), full_page=True)
                    context.close()
                    browser.close()
            finally:
                server.terminate()
                server.wait(timeout=5)
    print("Previews created from real catalog defaults: " + str(args.output))


if __name__ == "__main__":
    main()
