"""Validate real Apache rewrite/security rules in an isolated temporary installation.

The --runtime directory supplies extracted Debian apache2-bin and libapache2-mod-php
packages. It is a test prerequisite, never part of the hosting distribution.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--runtime', required=True, type=Path)
parser.add_argument('--php', required=True)
args = parser.parse_args()
source = Path(__file__).resolve().parent.parent
runtime = args.runtime.resolve()
with tempfile.TemporaryDirectory(prefix='fantasydream-apache-') as temp:
    root = Path(temp)
    site = root / 'site'
    shutil.copytree(source, site, ignore=shutil.ignore_patterns('.git', 'dist', 'runtime', 'config.local.php', 'content.json', 'setup-token.txt', '__pycache__'))
    with socket.socket() as available:
        available.bind(('127.0.0.1', 0))
        port = available.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    code = 'require ' + json.dumps(str(site / 'private/app.php')) + ';fd_install("apachetest","temporary-test-password-2026",' + json.dumps(base) + ',"apache-private-test-2026");'
    subprocess.run([args.php, '-r', code], check=True)
    ini = root / 'php-ini'
    ini.mkdir()
    (ini / 'php.ini').write_text(f'extension_dir={runtime}/usr/lib/php/20240924\nextension=gd\nextension=mbstring\nextension=fileinfo\ndisplay_errors=Off\nlog_errors=On\n')
    modules = runtime / 'usr/lib/apache2/modules'
    config = [f'ServerRoot "{root}"', f'PidFile "{root}/apache.pid"', f'Listen 127.0.0.1:{port}', 'ServerName 127.0.0.1', f'ErrorLog "{root}/errors.log"', 'LogLevel warn']
    for name in ['mpm_prefork', 'authz_core', 'authz_host', 'dir', 'mime', 'headers', 'rewrite', 'expires', 'filter', 'deflate', 'setenvif']:
        config.append(f'LoadModule {name}_module "{modules}/mod_{name}.so"')
    config.extend([f'LoadModule php_module "{modules}/libphp8.4.so"', 'User agent', 'Group agent', 'TypesConfig /etc/mime.types', f'PHPIniDir "{ini}"', f'DocumentRoot "{site}/public_html"', f'<Directory "{site}/public_html">', 'AllowOverride All', 'Require all granted', '</Directory>', '<FilesMatch "\\.php$">', 'SetHandler application/x-httpd-php', '</FilesMatch>'])
    configuration = root / 'apache.conf'
    configuration.write_text('\n'.join(config) + '\n')
    env = os.environ.copy()
    env['LD_LIBRARY_PATH'] = str(runtime / 'usr/lib/x86_64-linux-gnu')
    executable = str(runtime / 'usr/sbin/apache2')
    subprocess.run([executable, '-f', str(configuration), '-t'], env=env, check=True)
    log = open(root / 'process.log', 'w+')
    server = subprocess.Popen([executable, '-f', str(configuration), '-DFOREGROUND'], env=env, stdout=log, stderr=log, start_new_session=True)
    checks = 0
    try:
        for _ in range(60):
            try:
                urllib.request.urlopen(base + '/en/', timeout=1).close()
                break
            except urllib.error.URLError:
                if server.poll() is not None:
                    raise RuntimeError((root / 'errors.log').read_text() if (root / 'errors.log').exists() else 'Apache stopped')
                time.sleep(0.1)
        else:
            raise RuntimeError('Apache startup timed out')
        # Put representative forbidden files in the public tree to test the real rules.
        (site / 'public_html/secret.json').write_text('{"secret":"test-only"}')
        (site / 'public_html/media/blocked.php').write_text('<?php echo "unsafe";')
        for path, expected in [('/he/',200),('/ru/catalog/',200),('/en/product/milano/',200),('/robots.txt',200),('/sitemap.xml',200),('/llms.txt',200),('/assets/site.css',200),('/media/milano.webp',200),('/secret.json',403),('/media/blocked.php',403),('/media/',403),('/private/config.local.php',404),('/.htaccess',403),('/not-a-page',404)]:
            try:
                response = urllib.request.urlopen(base + path, timeout=5)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                body = response.read()
                assert response.status == expected, (path, response.status, expected)
                assert response.headers.get('X-Content-Type-Options') == 'nosniff', path
                if path == '/en/product/milano/':
                    assert b'Product' in body and b'ILS' in body and b'Fatal error' not in body
                    assert 'Content-Security-Policy' in response.headers
                checks += 1
        print(json.dumps({'apache': '2.4', 'checks': checks, 'status': 'passed'}))
    finally:
        server.terminate()
        server.wait(timeout=5)
        log.close()
