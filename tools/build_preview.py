#!/usr/bin/env python3
"""Render a public, static design preview from the authoritative PHP templates.

GitHub Pages cannot run PHP. The exported admin screens are demonstrative: they
never authenticate, save content, upload files or send messages. Installation,
credentials, mutable content and private runtime files are never exported.
"""
from __future__ import annotations

import argparse
import hashlib
import html
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
from urllib.parse import parse_qs, urlparse

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_URL = "https://grenkain1.github.io/GPT"
MANIFEST = ".fantasydream-preview.json"
LANGUAGES = ("he", "ru", "en")
ASSET_SUFFIXES = {".css", ".js", ".svg", ".jpg", ".jpeg", ".png", ".webp", ".avif", ".woff", ".woff2", ".ico"}
EXCLUDED_PRIVATE = {"config.local.php", "content.json", "setup-token.txt"}
EXCLUDED_PARTS = {"runtime", "backups", "uploads", "__pycache__"}

MESSAGES = {
    "he": {
        "heading": "תצוגת עיצוב",
        "notice": "זהו אתר לתצוגה ולבדיקת העיצוב. שליחת הזמנות וניהול התוכן יפעלו באחסון PHP.",
        "admin": "תצוגת ממשק הניהול",
        "form": "תצוגת בקשת הזמנה",
        "feedback": "זוהי תצוגת עיצוב בלבד. הבקשה לא נשלחה ולא נשמרה. באתר המלא היא תישלח בדוא״ל לאחר הגדרת SMTP.",
    },
    "ru": {
        "heading": "Предпросмотр дизайна",
        "notice": "Здесь можно оценить оформление сайта. Отправка заявок и сохранение через админку работают на PHP-хостинге.",
        "admin": "Посмотреть админку",
        "form": "Предпросмотр заявки",
        "feedback": "Это предпросмотр дизайна. Заявка не отправлена и не сохранена. На рабочем сайте она будет отправляться по email после настройки SMTP.",
    },
    "en": {
        "heading": "Design preview",
        "notice": "Explore the website design. Email requests and content management require PHP hosting.",
        "admin": "View admin preview",
        "form": "Order request preview",
        "feedback": "This is a design preview. Your request was not sent or saved. On the production site, email delivery will work after SMTP setup.",
    },
}

PREVIEW_CSS = """/* Design review tools only; excluded from the Apache application. */
.preview-notice{background:#eaf0eb;color:#24473b;border-bottom:1px solid #cddbd1;padding:10px 24px;font:13px/1.55 Arial,Helvetica,sans-serif;display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:6px 18px;text-align:center}
.preview-notice strong{font-size:12px;letter-spacing:.02em}.preview-notice p{margin:0;color:inherit;max-width:800px}.preview-notice a{color:#24473b;font-weight:bold;text-decoration:underline;text-underline-offset:3px;padding:5px 0;min-height:32px;display:inline-flex;align-items:center}
.admin-shell .preview-notice{border:1px solid #cddbd1;border-radius:10px;margin-bottom:26px;text-align:left;justify-content:flex-start;padding:15px 18px}.login-shell .preview-notice{margin-bottom:24px;border:1px solid #cddbd1;border-radius:10px;padding:12px}.preview-feedback{background:#f0f5f1;border:1px solid #cddbd1;border-radius:8px;padding:12px;color:#24473b;font:14px/1.6 Arial,Helvetica,sans-serif;overflow-wrap:anywhere}
.preview-notice:focus{outline:3px solid #946e39;outline-offset:2px}@media(max-width:600px){.preview-notice{padding:9px 16px;gap:2px 12px;font-size:12px}.preview-notice p{width:100%}}@media(prefers-reduced-motion:reduce){.preview-notice{scroll-behavior:auto}}
"""

PREVIEW_JS = """/* Static preview: no requests, authentication or persistent editing. ES5. */
(function () {
    'use strict';
    var doc = document;
    function ancestor(el, attribute) {
        while (el && el !== doc) { if (el.getAttribute && el.hasAttribute(attribute)) return el; el = el.parentNode; }
        return null;
    }
    function feedback(container) {
        var message = container.getAttribute('data-preview-message') || 'Design preview only. Changes are not saved, images are not uploaded and no email is sent. Edit the production site on PHP hosting.';
        var node = container.querySelector('[data-order-feedback], [data-preview-feedback]');
        if (!node) { node = doc.createElement('p'); node.className = 'preview-feedback'; node.setAttribute('data-preview-feedback', ''); node.setAttribute('role', 'status'); node.setAttribute('aria-live', 'polite'); container.appendChild(node); }
        node.textContent = message; node.hidden = false; node.removeAttribute('hidden');
        node.setAttribute('tabindex', '-1'); node.focus();
    }
    doc.addEventListener('click', function (event) {
        var button = ancestor(event.target, 'data-preview-submit');
        if (!button) return;
        event.preventDefault(); event.stopImmediatePropagation();
        var container = ancestor(button, 'data-preview-form');
        if (container) feedback(container);
    }, true);
    doc.addEventListener('submit', function (event) {
        event.preventDefault(); event.stopImmediatePropagation();
        feedback(event.target);
    }, true);
    doc.addEventListener('click', function (event) {
        var blocked = ancestor(event.target, 'data-preview-unavailable');
        if (!blocked) return;
        event.preventDefault(); event.stopImmediatePropagation();
        var notice = doc.getElementById('preview-notice');
        if (notice) notice.focus();
    }, true);
}());
"""

PHP_RENDERER = r"""<?php
declare(strict_types=1);
$root = $argv[1];
$request = $argv[2];
$authenticated = $argv[3] === 'admin';
$_SERVER['REQUEST_URI'] = $request;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = parse_url($argv[4], PHP_URL_PATH) . '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/public_html/index.php';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;
$_SERVER['HTTP_HOST'] = parse_url($argv[4], PHP_URL_HOST);
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
parse_str((string) parse_url($request, PHP_URL_QUERY), $_GET);
putenv('FANTASYDREAM_PRIVATE_DIR=' . $root . '/private');
if ($authenticated) {
    define('FD_PUBLIC_DIR', $root . '/public_html');
    require $root . '/private/app.php';
    fd_session();
    $config = fd_config();
    $_SESSION['admin'] = true;
    $_SESSION['admin_fingerprint'] = hash('sha256', $config['admin_username'] . "\0" . $config['password_hash']);
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] = str_repeat('0', 64);
    require $root . '/private/admin.php';
    fd_admin_render();
} else {
    require $root . '/public_html/index.php';
}
"""


def php_binary(explicit: str | None) -> str:
    candidate = explicit or os.environ.get("PHP_BINARY")
    if candidate:
        return candidate
    cloud = Path("/workspace/runtime82/bin/php")
    if cloud.is_file():
        return str(cloud)
    found = shutil.which("php")
    if not found:
        raise SystemExit("PHP 8.2+ is required to rebuild the preview; set PHP_BINARY or --php.")
    return found


def escaped(value: str) -> str:
    return html.escape(value, quote=True)


def admin_path(base: str, page: str, product: str = "") -> str:
    prefix = base + "/preview/admin/"
    if page == "dashboard":
        return prefix
    if page == "product":
        return prefix + "products/" + (product or "new") + "/"
    names = {"products": "products", "home": "homepage", "contact": "contact", "settings": "business", "translations": "labels", "login": "login"}
    return prefix + names.get(page, "") + "/"


def transform(source: str, base: str, lang: str, admin: bool = False) -> str:
    messages = MESSAGES[lang]
    source = re.sub(r'\snonce="[^"]*"', "", source)
    source = re.sub(r'<input\b(?=[^>]*\bname="csrf")[^>]*>', "", source, flags=re.I)
    source = re.sub(r'<meta\b(?=[^>]*\bname="robots")[^>]*>', "", source, flags=re.I)
    head = (
        '<meta name="robots" content="noindex,nofollow,noarchive,nosnippet">\n'
        '<link rel="stylesheet" href="' + escaped(base + '/assets/preview.css') + '">\n'
        '<script src="' + escaped(base + '/assets/preview.js') + '" defer></script>\n'
    )
    source = source.replace("</head>", head + "</head>", 1)

    def replace_admin_link(match: re.Match[str]) -> str:
        url = html.unescape(match.group(1))
        parsed = urlparse(url)
        if parsed.path.rstrip("/") != (base + "/preview/admin"):
            return match.group(0)
        query = parse_qs(parsed.query)
        page = query.get("page", ["dashboard"])[0]
        if page == "export":
            return 'href="#preview-notice" data-preview-unavailable'
        return 'href="' + escaped(admin_path(base, page, query.get("id", [""])[0])) + '"'

    source = re.sub(r'href="([^"]*)"', replace_admin_link, source)
    if admin:
        notice = (
            '<aside class="preview-notice" id="preview-notice" tabindex="-1" aria-label="Preview information">'
            '<strong>Admin design preview</strong><p>Browse and try the editing controls. Changes are not saved, images are not uploaded and no email is sent. The private admin panel will run on PHP hosting.</p>'
            '<a href="' + escaped(base + '/he/') + '">View website</a>'
            '<a href="' + escaped(admin_path(base, 'login')) + '">Login design</a></aside>'
        )
        source = re.sub(r'(<main\b[^>]*\bid="main"[^>]*>)', lambda match: match.group(1) + notice, source, count=1)
        source = source.replace('Changes appear on the site after saving.', 'Preview only — changes are not saved.')
        source = source.replace('FantasyDream · Private administration · No online payments', 'FantasyDream · Administration design preview · No changes saved')
        source = re.sub(r'(name="admin_username"[^>]*\bvalue=")[^"]*(")', r'\1\2', source)
    else:
        notice = (
            '<aside class="preview-notice" id="preview-notice" tabindex="-1" aria-label="' + escaped(messages['heading']) + '">'
            '<strong>' + escaped(messages['heading']) + '</strong><p>' + escaped(messages['notice']) + '</p>'
            '<a href="' + escaped(admin_path(base, 'dashboard')) + '" dir="' + ('rtl' if lang == 'he' else 'ltr') + '">' + escaped(messages['admin']) + '</a></aside>'
        )
        source = re.sub(r'(<body\b[^>]*>)', lambda match: match.group(1) + notice, source, count=1)

    # Replace real form tags entirely so no native submission is possible even
    # without JavaScript. Keep selectors used by size/quantity enhancement.
    def replace_form(match: re.Match[str]) -> str:
        attributes = match.group(1)
        attributes = re.sub(r'\s(?:action|method|enctype)="[^"]*"', '', attributes, flags=re.I)
        message = 'Design preview only. Changes are not saved, images are not uploaded and no email is sent. Edit the production site on PHP hosting.' if admin else messages['feedback']
        return '<div' + attributes + ' data-preview-form role="group" data-preview-message="' + escaped(message) + '">'

    source = re.sub(r'<form\b([^>]*)>', replace_form, source, flags=re.I)
    source = re.sub(r'</form\s*>', '</div>', source, flags=re.I)
    source = re.sub(r'<button\b([^>]*?)\btype="submit"([^>]*)>', r'<button\1type="button" data-preview-submit\2>', source, flags=re.I)
    # A source form's endpoint must not survive as an accidentally active URL.
    source = source.replace(base + '/order.php', '#preview-notice')
    if re.search(r'<(?:form)\b|\bname="csrf"|/order\.php|/setup\.php|preview-only-not-a-valid-password-hash', source, re.I):
        raise RuntimeError('Server-only content remained in a compiled preview page.')
    return source


def audit(output: Path, base: str) -> dict[str, int]:
    pages = list(output.rglob('*.html'))
    if not pages:
        raise RuntimeError('Preview contains no pages.')
    for path in pages:
        document = path.read_text(encoding='utf-8')
        if '<meta name="robots" content="noindex,nofollow,noarchive,nosnippet">' not in document:
            raise RuntimeError(f'Preview is missing noindex: {path.relative_to(output)}')
        if re.search(r'\bname="csrf"|<form\b|\$(?:2[aby]|argon2)\$|BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY|fantasydream_session=', document):
            raise RuntimeError(f'Private/server content found in {path.relative_to(output)}')
        for match in re.finditer(r'\b(?:href|src|srcset)="([^"]+)"', document):
            value = html.unescape(match.group(1)).split('?', 1)[0].split('#', 1)[0]
            if value.startswith(base + '/'):
                relative = value[len(base) + 1:]
                destination = output / relative
                if value.endswith('/'):
                    destination = destination / 'index.html'
                if not destination.is_file():
                    raise RuntimeError(f'Broken preview link in {path.relative_to(output)}: {value}')
    for path in output.rglob('*'):
        if not path.is_file():
            continue
        if (path.suffix.lower() in {'.php', '.json', '.zip', '.pdf'} and path.name != MANIFEST) or any(part in {'private', 'runtime', 'backups'} for part in path.relative_to(output).parts):
            raise RuntimeError('A server/private file is present in static output: ' + str(path))
    return {'html_pages': len(pages), 'files': len(list(path for path in output.rglob('*') if path.is_file()))}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', help='Path to PHP 8.2+ binary')
    parser.add_argument('--site-url', default=DEFAULT_URL)
    parser.add_argument('--output', type=Path, default=ROOT / 'docs')
    args = parser.parse_args()
    url = args.site_url.rstrip('/')
    parsed = urlparse(url)
    if parsed.scheme != 'https' or not parsed.hostname or parsed.query or parsed.fragment:
        raise SystemExit('--site-url must be an HTTPS website address without query or fragment.')
    base = parsed.path.rstrip('/')
    php = php_binary(args.php)
    version = subprocess.run([php, '-r', 'echo PHP_VERSION_ID;'], check=True, capture_output=True, text=True)
    if int(version.stdout) < 80200:
        raise SystemExit('Preview rendering requires PHP 8.2 or newer.')
    output = args.output.resolve()
    if output == ROOT or output in {ROOT / 'private', ROOT / 'public_html'}:
        raise SystemExit('Refusing to replace a source directory.')
    with tempfile.TemporaryDirectory(prefix='fantasydream-preview-') as temporary:
        working = Path(temporary)
        isolated = working / 'source'
        staging = working / 'static'
        staging.mkdir()
        for folder in ('private', 'public_html'):
            for path in (ROOT / folder).rglob('*'):
                relative = path.relative_to(ROOT)
                if not path.is_file() or path.name in EXCLUDED_PRIVATE or any(part in EXCLUDED_PARTS for part in relative.parts):
                    continue
                target = isolated / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(path, target)
        php_url = "'" + url.replace('\\', '\\\\').replace("'", "\\'") + "'"
        config = "<?php return ['site_url'=>" + php_url + ",'admin_path'=>'preview/admin','admin_username'=>'design-preview','password_hash'=>'preview-only-not-a-valid-password-hash'];\n"
        (isolated / 'private/config.local.php').write_text(config, encoding='utf-8')
        renderer = working / 'render.php'
        renderer.write_text(PHP_RENDERER, encoding='utf-8')
        catalog = subprocess.run([php, '-r', 'echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);', str(isolated / 'private/catalog.php')], check=True, capture_output=True, text=True)
        products = json.loads(catalog.stdout)
        routes = ['', 'catalog/', 'contact/', 'privacy/', 'accessibility/'] + [f'product/{p["slug"]}/' for p in products if p.get('visible')]

        def render(request: str, target: str, lang: str, admin: bool = False, authenticated: bool | None = None) -> None:
            authenticate = admin if authenticated is None else authenticated
            result = subprocess.run([php, str(renderer), str(isolated), request, 'admin' if authenticate else 'public', url], capture_output=True, text=True)
            if result.returncode or result.stderr.strip() or not result.stdout.lstrip().lower().startswith('<!doctype html>'):
                raise RuntimeError('Could not render preview route: ' + request + '\n' + result.stderr[:2000] + '\n' + result.stdout[:1500])
            destination = staging / target
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_text(transform(result.stdout, base, lang, admin=admin), encoding='utf-8')

        for lang in LANGUAGES:
            for route in routes:
                render(base + '/' + lang + '/' + route, lang + '/' + route + 'index.html', lang)
        for page in ('dashboard', 'products', 'home', 'contact', 'settings', 'translations', 'product'):
            query = '' if page == 'dashboard' else '?page=' + page
            target = admin_path('', page).lstrip('/') + 'index.html'
            render(base + '/preview/admin/' + query, target, 'en', admin=True)
        for product in products:
            identifier = str(product['id'])
            if not re.fullmatch(r'[a-zA-Z0-9_-]+', identifier):
                raise RuntimeError('A product ID cannot be used as a safe preview path.')
            render(base + '/preview/admin/?page=product&id=' + identifier, 'preview/admin/products/' + identifier + '/index.html', 'en', admin=True)
        # The login design is illustrative and has no authentication endpoint.
        render(base + '/preview/admin/', 'preview/admin/login/index.html', 'en', admin=True, authenticated=False)
        for folder in ('assets', 'media'):
            for source in (isolated / 'public_html' / folder).rglob('*'):
                if not source.is_file() or source.suffix.lower() not in ASSET_SUFFIXES or 'uploads' in source.parts:
                    continue
                target = staging / source.relative_to(isolated / 'public_html')
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source, target)
        (staging / 'assets/preview.css').write_text(PREVIEW_CSS, encoding='utf-8')
        (staging / 'assets/preview.js').write_text(PREVIEW_JS, encoding='utf-8')
        (staging / 'robots.txt').write_text('User-agent: *\nDisallow: /\n', encoding='utf-8')
        (staging / '.nojekyll').write_text('', encoding='utf-8')
        root_page = '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive,nosnippet"><meta http-equiv="refresh" content="0;url=' + escaped(base + '/he/') + '"><title>FantasyDream · Design preview</title></head><body><a href="' + escaped(base + '/he/') + '">FantasyDream</a></body></html>\n'
        (staging / 'index.html').write_text(root_page, encoding='utf-8')
        (staging / '404.html').write_text('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive,nosnippet"><title>Page not found · FantasyDream</title></head><body><h1>Page not found</h1><p><a href="' + escaped(base + '/he/') + '">Open FantasyDream preview</a></p></body></html>\n', encoding='utf-8')
        results = audit(staging, base)
        files = sorted(path.relative_to(staging).as_posix() for path in staging.rglob('*') if path.is_file())
        previous = []
        if (output / MANIFEST).is_file():
            previous = json.loads((output / MANIFEST).read_text(encoding='utf-8'))['files']
        for name in files:
            existing = output / name
            if existing.exists() and name not in previous:
                raise RuntimeError('Refusing to overwrite a file not owned by the preview builder: ' + str(existing))
        output.mkdir(parents=True, exist_ok=True)
        for name in previous:
            candidate = output / name
            if candidate.is_file() and name not in files:
                candidate.unlink()
        for name in files:
            destination = output / name
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(staging / name, destination)
        sources = {}
        for folder in ('private', 'public_html'):
            for source in sorted((isolated / folder).rglob('*')):
                relative = source.relative_to(isolated)
                if source.is_file() and source.name != 'config.local.php' and not any(part in EXCLUDED_PARTS for part in relative.parts):
                    sources[relative.as_posix()] = hashlib.sha256(source.read_bytes()).hexdigest()
        manifest = {'format': 1, 'site_url': url, 'files': files, 'source_sha256': sources, 'checks': results}
        (output / MANIFEST).write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
        print(json.dumps({'output': str(output), 'site_url': url, **results}, ensure_ascii=False))


if __name__ == '__main__':
    main()
