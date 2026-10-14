"""Run route/security/mail tests in a temporary checkout with disposable fixtures.

Usage: python3 tests/smoke.py --php /path/to/php [--browser]
The live checkout, catalog, credentials and installed configuration are untouched.
"""
from __future__ import annotations

import argparse
import email
import email.policy
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

from smtp_fixture import SMTPFixture


class FormParser:
    @staticmethod
    def csrf(html: str) -> str:
        for tag in re.findall(r"<input\b[^>]*>", html, re.I):
            if re.search(r'name=["\']csrf["\']', tag):
                match = re.search(r'value=["\']([^"\']+)', tag)
                if match:
                    return match.group(1)
        raise AssertionError("Page has no hidden CSRF field")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


class SiteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory(prefix="fantasydream-qa-")
        cls.root = Path(cls.temporary.name) / "site"
        source = Path(__file__).resolve().parent.parent
        shutil.copytree(source, cls.root, ignore=shutil.ignore_patterns(".git", "config.local.php", "content.json", "setup-token.txt", "runtime", "*.zip", "__pycache__"))
        cls.php = OPTIONS.php
        for path in sorted(cls.root.rglob("*.php")):
            subprocess.run([cls.php, "-l", str(path)], check=True, stdout=subprocess.DEVNULL)
        for path in sorted((cls.root / "public_html").rglob("*.js")):
            subprocess.run(["node", "--check", str(path)], check=True, stdout=subprocess.DEVNULL)
        security = cls.run_php_file("tests/security.php")
        cls.security_checks = json.loads(security)["checks"]
        with socket.socket() as available:
            available.bind(("127.0.0.1", 0))
            cls.port = available.getsockname()[1]
        cls.base = f"http://127.0.0.1:{cls.port}"
        cls.admin_path = "qa-private-admin-2026"
        cls.password = "temporary-test-password-2026"
        cls.run_php("fd_install('qaadmin', 'temporary-test-password-2026', " + json.dumps(cls.base) + ", 'qa-private-admin-2026');")
        cls.certificate = Path(cls.temporary.name) / "smtp-ca.pem"
        key = Path(cls.temporary.name) / "smtp-key.pem"
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1", "-keyout", str(key), "-out", str(cls.certificate), "-subj", "/CN=localhost", "-addext", "subjectAltName=DNS:localhost,IP:127.0.0.1", "-addext", "basicConstraints=critical,CA:TRUE"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        cls.smtp = SMTPFixture(cls.certificate, key)
        cls.run_php("$config=fd_config(); $config['order_recipient']='orders@example.test'; $config['smtp']=['host'=>'localhost','port'=>" + str(cls.smtp.port) + ",'encryption'=>'starttls','username'=>'qa-fixture','password'=>'disposable-fixture-only','from_email'=>'site@example.test','from_name'=>'FantasyDream']; fd_atomic_write(FD_PRIVATE_DIR.'/config.local.php', '<?php return '.var_export($config,true).';'); $data=fd_data(); $data['products'][0]['visible']=true; $data['products'][0]['sizes']=[['id'=>'qa-size','label'=>'90 × 190 cm','price'=>1234.56]]; fd_save_data($data); echo json_encode(['id'=>$data['products'][0]['id'],'slug'=>$data['products'][0]['slug']]);")
        info = json.loads(cls.run_php("$p=fd_data()['products'][0]; echo json_encode(['id'=>$p['id'],'slug'=>$p['slug']]);"))
        cls.product_id, cls.product_slug = info["id"], info["slug"]
        cls.product_path = f"/en/product/{cls.product_slug}/"
        cls.log = open(Path(cls.temporary.name) / "php.log", "w+")
        cls.server = subprocess.Popen([cls.php, "-d", "openssl.cafile=" + str(cls.certificate), "-S", f"127.0.0.1:{cls.port}", "-t", str(cls.root / "public_html"), str(cls.root / "tools/router.php")], cwd=cls.root, stdout=cls.log, stderr=cls.log)
        for _ in range(60):
            try:
                urllib.request.urlopen(cls.base + "/en/", timeout=1).close()
                break
            except (urllib.error.URLError, ConnectionError):
                if cls.server.poll() is not None:
                    cls.log.seek(0)
                    raise AssertionError("PHP server failed: " + cls.log.read())
                time.sleep(0.1)
        else:
            raise AssertionError("PHP server did not start")

    @classmethod
    def run_php(cls, code: str) -> str:
        return subprocess.check_output([cls.php, "-r", "require " + json.dumps(str(cls.root / "private/app.php")) + ";" + code], cwd=cls.root, text=True)

    @classmethod
    def run_php_file(cls, path: str) -> str:
        return subprocess.check_output([cls.php, str(cls.root / path)], cwd=cls.root, text=True)

    @classmethod
    def tearDownClass(cls):
        if hasattr(cls, "server"):
            cls.server.terminate()
            cls.server.wait(timeout=5)
            cls.log.close()
        if hasattr(cls, "smtp"):
            cls.smtp.close()
        cls.temporary.cleanup()

    def setUp(self):
        self.cookies = http.cookiejar.CookieJar()
        self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies), NoRedirect())
        (self.root / "private/runtime/rates.json").unlink(missing_ok=True)

    def request(self, path, values=None, headers=None, body=None):
        request_headers = {key:value for key,value in {"Origin": self.base, **(headers or {})}.items() if value is not None}
        if body is None:
            body = None if values is None else urllib.parse.urlencode(values).encode()
        request = urllib.request.Request(self.base + path, data=body, headers=request_headers)
        try:
            response = self.client.open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read().decode("utf-8")

    def multipart(self, path, values, name, filename, mime, payload):
        boundary = "fd-qa-disposable-boundary-862748127"
        parts = []
        for key, value in values.items():
            parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n').encode())
        parts.append((f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n').encode() + payload + b"\r\n")
        parts.append(f"--{boundary}--\r\n".encode())
        return self.request(path, headers={"Content-Type":"multipart/form-data; boundary=" + boundary}, body=b"".join(parts))

    def order_values(self):
        status, _, html = self.request(self.product_path)
        self.assertEqual(status, 200)
        return {"lang":"en", "product_id":self.product_id, "size_id":"qa-size", "quantity":"2", "name":"QA Customer", "email":"customer@example.test", "phone":"+972501234567", "message":"A disposable test request.\n.Second line", "consent":"1", "csrf":FormParser.csrf(html), "website":""}

    def login(self, password=None):
        status, headers, html = self.request("/" + self.admin_path)
        self.assertEqual(status, 200)
        token = FormParser.csrf(html)
        before = {cookie.value for cookie in self.cookies}
        status, headers, html = self.request("/" + self.admin_path, {"csrf":token,"username":"qaadmin","password":self.password if password is None else password,"action":"login"})
        self.assertIn(status, [200, 302, 303])
        if status in [302, 303]:
            status, headers, html = self.request(urllib.parse.urlsplit(headers["Location"]).path)
        self.assertEqual(status, 200)
        self.assertNotEqual(before, {cookie.value for cookie in self.cookies}, "Authentication must rotate the session identifier")
        return headers, html

    def test_01_security_helpers(self):
        self.assertGreaterEqual(self.security_checks, 22)

    def test_02_languages_routes_and_seo(self):
        for language in ["he", "ru", "en"]:
            for route in ["", "catalog/", "contact/", "privacy/", "accessibility/", f"product/{self.product_slug}/"]:
                with self.subTest(language=language, route=route):
                    status, headers, html = self.request(f"/{language}/{route}")
                    self.assertEqual(status, 200)
                    self.assertRegex(html, rf'<html\b[^>]*lang=["\']{language}["\']')
                    self.assertRegex(html, rf'<html\b[^>]*dir=["\']{"rtl" if language == "he" else "ltr"}["\']')
                    self.assertIn('rel="canonical"', html)
                    self.assertIn('hreflang="he"', html)
                    self.assertIn('hreflang="ru"', html)
                    self.assertIn('hreflang="en"', html)
                    self.assertIn("Content-Security-Policy", headers)
                    self.assertEqual(headers.get("X-Content-Type-Options"), "nosniff")

    def test_03_private_routes_and_unknown_paths(self):
        for path in ["/private/content.json", "/private/config.local.php", "/private/app.php", "/tests/security.php", "/tools/router.php", "/.git/config", "/admin", "/setup.php", "/en/not-a-real-page/"]:
            with self.subTest(path=path):
                status, _, html = self.request(path)
                self.assertEqual(status, 404)
                self.assertNotIn("password_hash", html)
                self.assertNotIn("disposable-fixture-only", html)

    def test_04_robots_and_sitemap_do_not_advertise_admin(self):
        for path in ["/robots.txt", "/sitemap.xml"]:
            status, _, body = self.request(path)
            self.assertEqual(status, 200)
            self.assertNotIn(self.admin_path, body)
            if path == "/sitemap.xml":
                self.assertNotIn("setup.php", body)
        _, _, body = self.request("/sitemap.xml")
        self.assertIn("/he/", body)
        self.assertIn("/ru/", body)
        self.assertIn("/en/", body)

    def test_05_admin_login_headers_and_cookie(self):
        status, headers, html = self.request("/" + self.admin_path)
        self.assertEqual(status, 200)
        self.assertIn("noindex", headers.get("X-Robots-Tag", ""))
        self.assertIn("no-store", headers.get("Cache-Control", ""))
        self.assertIn("HttpOnly", headers.get("Set-Cookie", ""))
        self.assertIn("SameSite=Strict", headers.get("Set-Cookie", ""))
        self.assertNotIn('value="' + self.password, html)
        headers, html = self.login()
        self.assertIn("Sign out", html)
        self.assertRegex(html, r'<html\b[^>]*lang=["\']en["\']')

    def test_06_admin_login_rejects_csrf_and_bad_password(self):
        _, _, html = self.request("/" + self.admin_path)
        valid = {"csrf":FormParser.csrf(html),"username":"qaadmin","password":"wrong-password","action":"login"}
        status, _, html = self.request("/" + self.admin_path, valid)
        self.assertNotIn("Sign out", html)
        valid.update(password=self.password, csrf="0"*64)
        status, _, html = self.request("/" + self.admin_path, valid)
        self.assertNotIn("Sign out", html)
        self.assertIn(status, [200, 403])

    def test_06b_admin_mutations_require_login_origin_and_csrf(self):
        original = (self.root / "private/content.json").read_bytes()
        _, _, html = self.request("/" + self.admin_path)
        values = {"csrf":FormParser.csrf(html),"action":"save_translations", "translations[en][nav_home]":"UNAUTHORIZED"}
        self.request("/" + self.admin_path, values)
        self.assertEqual((self.root / "private/content.json").read_bytes(), original)
        _, html = self.login()
        values["csrf"] = "0" * 64
        self.request("/" + self.admin_path, values)
        self.assertEqual((self.root / "private/content.json").read_bytes(), original)
        values["csrf"] = FormParser.csrf(html)
        self.request("/" + self.admin_path, values, {"Origin":"https://attacker.example"})
        self.assertEqual((self.root / "private/content.json").read_bytes(), original)

    def test_06c_admin_save_and_export_do_not_contain_secrets(self):
        original = (self.root / "private/content.json").read_bytes()
        try:
            _, html = self.login()
            status, _, body = self.request("/" + self.admin_path, {"csrf":FormParser.csrf(html),"action":"save_translations", "translations[en][nav_home]":"QA updated home"})
            self.assertEqual(status, 303, body)
            self.assertEqual(json.loads((self.root / "private/content.json").read_text())["translations"]["en"]["nav_home"], "QA updated home")
            status, headers, body = self.request("/" + self.admin_path + "/?page=export")
            self.assertEqual(status, 200)
            self.assertIn("attachment", headers.get("Content-Disposition", ""))
            self.assertEqual(json.loads(body)["schema"], 1)
            self.assertNotIn("password_hash", body)
            self.assertNotIn("disposable-fixture-only", body)
            _, _, settings = self.request("/" + self.admin_path + "/?page=settings")
            self.assertNotIn("disposable-fixture-only", settings)
            self.assertNotIn(self.password, settings)
        finally:
            (self.root / "private/content.json").write_bytes(original)

    def test_06e_password_change_preserves_spaces_and_invalidates_other_sessions(self):
        original = (self.root / "private/config.local.php").read_bytes()
        try:
            self.login()
            other_session = self.client
            other_cookies = self.cookies
            self.cookies = http.cookiejar.CookieJar()
            self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies), NoRedirect())
            _, html = self.login()
            new_password = " leading and trailing spaces 2026 "
            status, _, body = self.request("/" + self.admin_path, {"csrf":FormParser.csrf(html), "action":"change_password", "admin_username":"qaadmin", "current_password":self.password, "new_password":new_password, "confirm_password":new_password})
            self.assertEqual(status, 303, body)
            self.client = other_session
            self.cookies = other_cookies
            _, _, html = self.request("/" + self.admin_path)
            self.assertNotIn("Sign out", html, "An older session must be invalidated after credentials change")
            _, _, html = self.request("/" + self.admin_path, {"csrf":FormParser.csrf(html), "action":"login", "username":"qaadmin", "password":self.password})
            self.assertNotIn("Sign out", html, "The previous password must stop working")
            _, html = self.login(new_password)
            self.assertIn("Sign out", html, "Password bytes including boundary spaces must be preserved")
        finally:
            (self.root / "private/config.local.php").write_bytes(original)

    def test_06f_vat_unknown_and_custom_page_seo_are_saved_and_rendered(self):
        original = (self.root / "private/content.json").read_bytes()
        try:
            _, html = self.login()
            token = FormParser.csrf(html)
            data = json.loads(original)
            values = {"csrf":token, "action":"save_settings", "settings[vat_status]":"unknown"}
            for field, value in data["settings"].items():
                if isinstance(value, str):
                    values[f"settings[{field}]"] = value
                elif isinstance(value, dict):
                    for lang, text in value.items():
                        values[f"settings[{field}][{lang}]"] = text
            status, _, body = self.request("/" + self.admin_path, values)
            self.assertEqual(status, 303, body)
            self.assertIsNone(json.loads((self.root / "private/content.json").read_text())["settings"]["vat_included"])
            for section, route in [("home", "/en/"), ("contact", "/en/contact/")]:
                values = {"csrf":token, "action":"save_" + section}
                for lang, localized in data[section].items():
                    for field, value in localized.items():
                        if isinstance(value, str):
                            values[f"{section}[{lang}][{field}]"] = value.replace("\n", " ") if field in ["title", "about_title"] else value
                values[f"{section}[en][seo_title]"] = "QA custom " + section + " title"
                values[f"{section}[en][seo_description]"] = "QA custom " + section + " description"
                status, _, body = self.request("/" + self.admin_path, values)
                self.assertEqual(status, 303, body)
                _, _, html = self.request(route)
                self.assertIn("<title>QA custom " + section + " title</title>", html)
                self.assertIn('name="description" content="QA custom ' + section + ' description"', html)
        finally:
            (self.root / "private/content.json").write_bytes(original)

    def test_06d_raster_upload_is_reencoded_and_svg_rejected(self):
        original = (self.root / "private/content.json").read_bytes()
        try:
            _, html = self.login()
            data = json.loads(original)
            values = {"csrf":FormParser.csrf(html), "action":"save_home"}
            for lang in ["he", "ru", "en"]:
                for field, value in data["home"][lang].items():
                    if isinstance(value, str):
                        values[f"home[{lang}][{field}]"] = value.replace("\n", " ") if field in ["title", "about_title"] else value
            payload = (self.root / "public_html/media/napoli.webp").read_bytes() + b"<?php echo 'QA dangerous trailing payload'; ?>"
            status, _, body = self.multipart("/" + self.admin_path, values, "hero_image_en", "../../malicious.php", "image/webp", payload)
            self.assertEqual(status, 303, body)
            updated = json.loads((self.root / "private/content.json").read_text())
            path = updated["home"]["en"]["hero_image"]
            self.assertRegex(path, r"^/media/uploads/[a-f0-9]{40}\.(webp|png|jpg)$")
            saved = (self.root / "public_html" / path.lstrip("/")).read_bytes()
            self.assertNotIn(b"<?php", saved)
            self.assertNotEqual(saved, payload)
            fallback = self.root / "public_html" / (path.lstrip("/")[:-5] + ".jpg")
            if path.endswith(".webp"):
                self.assertTrue(fallback.is_file(), "Uploaded WebP must have a JPEG fallback for older browsers")
                self.assertTrue(fallback.read_bytes().startswith(b"\xff\xd8"))
            baseline = (self.root / "private/content.json").read_bytes()
            status, _, body = self.multipart("/" + self.admin_path, values, "hero_image_en", "picture.png", "image/png", b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')
            self.assertNotEqual(status, 303)
            self.assertEqual((self.root / "private/content.json").read_bytes(), baseline)
            values["home[en][remove_hero]"] = "1"
            status, _, body = self.request("/" + self.admin_path, values)
            self.assertEqual(status, 303, body)
            self.assertFalse((self.root / "public_html" / path.lstrip("/")).exists())
            self.assertFalse(fallback.exists(), "Removing an unreferenced photo must also clean up its fallback")
        finally:
            (self.root / "private/content.json").write_bytes(original)

    def test_07_order_rejects_missing_or_cross_origin_csrf(self):
        values = self.order_values()
        values["csrf"] = "0" * 64
        before = len(self.smtp.messages)
        status, _, _ = self.request("/order.php", values, {"Accept":"application/json"})
        self.assertIn(status, [403, 419])
        values = self.order_values()
        status, _, _ = self.request("/order.php", values, {"Origin":"https://attacker.example", "Accept":"application/json"})
        self.assertIn(status, [403, 419])
        for origin in [self.base + "/untrusted", self.base + "?untrusted=1", self.base + "#untrusted"]:
            status, _, _ = self.request("/order.php", values, {"Origin":origin, "Accept":"application/json"})
            self.assertEqual(status, 403)
        for origin in ["https://attacker.example", "null", ""]:
            status, _, _ = self.request("/order.php", values, {"Origin":origin, "Referer":self.base+self.product_path, "Accept":"application/json"})
            self.assertEqual(status, 403)
        status, _, _ = self.request("/order.php", values, {"Origin":None, "Accept":"application/json"})
        self.assertEqual(status, 403)
        self.assertEqual(len(self.smtp.messages), before)

    def test_07b_old_browser_without_origin_uses_same_origin_referer(self):
        values = self.order_values()
        before = len(self.smtp.messages)
        status, _, body = self.request("/order.php", values, {"Origin":None, "Referer":self.base+self.product_path, "Accept":"application/json"})
        self.assertEqual(status, 200, body)
        self.assertTrue(json.loads(body)["ok"])
        self.assertEqual(len(self.smtp.messages), before+1)

    def test_07c_http_host_cannot_spoof_the_local_https_exception(self):
        status, _, _ = self.request("/" + self.admin_path, headers={"Host":"attacker.example"})
        self.assertEqual(status, 403)
        values = self.order_values()
        before = len(self.smtp.messages)
        status, _, _ = self.request("/order.php", values, {"Host":"attacker.example", "Accept":"application/json"})
        self.assertEqual(status, 403)
        self.assertEqual(len(self.smtp.messages), before)

    def test_08_order_uses_server_price_and_verified_smtp(self):
        values = self.order_values()
        values.update(price="0.01", total="0.02")
        before = len(self.smtp.messages)
        status, _, body = self.request("/order.php", values, {"Accept":"application/json"})
        self.assertEqual(status, 200, body)
        result = json.loads(body)
        self.assertTrue(result.get("ok"), body)
        self.assertEqual(len(self.smtp.messages), before + 1)
        message = email.message_from_bytes(self.smtp.messages[-1], policy=email.policy.default)
        text = str(message.get_body(preferencelist=("plain",)).get_content()) if message.is_multipart() else str(message.get_content())
        self.assertRegex(text.replace(",", ""), r"2469\.12")
        self.assertNotIn("0.02", text)
        self.assertEqual(str(message["Reply-To"]), "customer@example.test")
        self.assertEqual(str(message["To"]), "orders@example.test")
        self.assertFalse(self.smtp.errors)

    def test_09_invalid_size_quantity_consent_and_header_injection(self):
        for mutation in [{"size_id":"unknown"}, {"quantity":"0"}, {"quantity":"101"}, {"quantity":"1.5"}, {"consent":""}, {"email":"customer@example.test\r\nBcc:evil@example.test"}]:
            with self.subTest(mutation=mutation):
                (self.root / "private/runtime/rates.json").unlink(missing_ok=True)
                values = self.order_values()
                values.update(mutation)
                before = len(self.smtp.messages)
                status, _, body = self.request("/order.php", values, {"Accept":"application/json"})
                self.assertIn(status, [400, 422], body)
                self.assertEqual(len(self.smtp.messages), before)

    def test_10_smtp_rejection_cannot_report_success(self):
        self.smtp.fail_delivery = True
        try:
            values = self.order_values()
            status, _, body = self.request("/order.php", values, {"Accept":"application/json"})
            self.assertIn(status, [500, 502, 503], body)
            self.assertFalse(json.loads(body).get("ok"))
        finally:
            self.smtp.fail_delivery = False

    def test_10b_hidden_product_cannot_be_ordered(self):
        values = self.order_values()
        self.run_php("$data=fd_data(); $data['products'][0]['visible']=false; fd_save_data($data);")
        try:
            before = len(self.smtp.messages)
            status, _, body = self.request("/order.php", values, {"Accept":"application/json"})
            self.assertEqual(status, 422, body)
            self.assertEqual(len(self.smtp.messages), before)
            status, _, _ = self.request(self.product_path)
            self.assertEqual(status, 404)
        finally:
            self.run_php("$data=fd_data(); $data['products'][0]['visible']=true; fd_save_data($data);")

    def test_10c_order_method_and_body_size(self):
        status, headers, _ = self.request("/order.php")
        self.assertEqual(status, 405)
        self.assertEqual(headers.get("Allow"), "POST")
        values = self.order_values()
        values["message"] = "x" * 33000
        status, _, _ = self.request("/order.php", values, {"Accept":"application/json"})
        self.assertEqual(status, 413)

    def test_10d_order_abuse_is_rate_limited(self):
        values = self.order_values()
        values["size_id"] = "invalid-size"
        before = len(self.smtp.messages)
        for attempt in range(6):
            status, headers, body = self.request("/order.php", values, {"Accept":"application/json"})
            self.assertEqual(status, 422 if attempt < 5 else 429, body)
        self.assertIn("Retry-After", headers)
        self.assertEqual(len(self.smtp.messages), before)

    def test_11_content_markup_is_escaped(self):
        marker = '<img src=x onerror="window.qaInjected=1">'
        self.run_php("$data=fd_data(); $old=$data['products'][0]['content']['en']['name']; $data['products'][0]['content']['en']['name']=" + json.dumps(marker) + "; fd_save_data($data); fd_atomic_write(FD_PRIVATE_DIR.'/runtime/old-name', $old);")
        try:
            status, _, html = self.request(self.product_path)
            self.assertEqual(status, 200)
            self.assertNotIn(marker, html)
            self.assertIn("&lt;img", html)
        finally:
            self.run_php("$data=fd_data(); $data['products'][0]['content']['en']['name']=file_get_contents(FD_PRIVATE_DIR.'/runtime/old-name'); fd_save_data($data);")

    def test_12_browser(self):
        if not OPTIONS.browser:
            self.skipTest("Browser checks run with --browser")
        from browser_smoke import run_browser_checks
        run_browser_checks(self.base, self.product_slug, OPTIONS.screenshots)

    def test_12b_multi_photo_carousel(self):
        if not OPTIONS.browser:
            self.skipTest("Browser checks run with --browser")
        from browser_smoke import run_carousel_check
        original = (self.root / "private/content.json").read_bytes()
        data = json.loads(original)
        data["products"][0]["images"].append(data["products"][1]["images"][0])
        (self.root / "private/content.json").write_text(json.dumps(data))
        try:
            run_carousel_check(self.base, self.product_slug)
        finally:
            (self.root / "private/content.json").write_bytes(original)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default=shutil.which("php") or "/workspace/runtime/bin/php")
    parser.add_argument("--browser", action="store_true")
    parser.add_argument("--screenshots", type=Path)
    OPTIONS = parser.parse_args()
    unittest.main(argv=[__file__], verbosity=2)
