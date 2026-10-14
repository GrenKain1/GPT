"""Exercise the actual ZIP installer using disposable local data."""
from pathlib import Path
import http.cookiejar
import re
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
import argparse

parser = argparse.ArgumentParser()
parser.add_argument('--php', required=True)
args = parser.parse_args()
root = Path(__file__).resolve().parent.parent
archive_path = root / 'dist/FantasyDream-Apache.zip'
with tempfile.TemporaryDirectory(prefix='fantasydream-release-') as temporary:
    site = Path(temporary)
    with zipfile.ZipFile(archive_path) as archive:
        assert archive.testzip() is None
        names = archive.namelist()
        assert not any('runtime/' in name or name.endswith(('config.local.php', 'content.json')) for name in names)
        assert 'public_html/assets/site.css' in names and 'public_html/assets/site.js' in names
        archive.extractall(site)
    token = (site / 'private/setup-token.txt').read_text().strip()
    assert re.fullmatch('[a-f0-9]{64}', token)
    with socket.socket() as available:
        available.bind(('127.0.0.1', 0))
        port = available.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    cookies = http.cookiejar.CookieJar()
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
    log = open(site / 'test-server.log', 'w+')
    server = subprocess.Popen([args.php, '-S', f'127.0.0.1:{port}', '-t', str(site / 'public_html')], stdout=log, stderr=log)
    try:
        for _ in range(60):
            try:
                with client.open(base + '/setup.php', timeout=1) as response:
                    html = response.read().decode()
                break
            except urllib.error.URLError:
                time.sleep(.1)
        else:
            raise RuntimeError('Release PHP server did not start')
        csrf = re.search(r'name="csrf" value="([^"]+)"', html).group(1)
        values = {'csrf':csrf,'token':token,'username':'releaseadmin','password':'temporary-test-only-password','password_confirmation':'temporary-test-only-password','site_url':base,'admin_path':'release-private-admin-2026'}
        request = urllib.request.Request(base + '/setup.php', data=urllib.parse.urlencode(values).encode(), headers={'Origin':base})
        with client.open(request, timeout=10) as response:
            body = response.read().decode()
            assert response.status == 200 and 'Installation completed' in body
            assert 'noindex' in response.headers.get('X-Robots-Tag','')
        assert not (site / 'private/setup-token.txt').exists()
        assert (site / 'private/config.local.php').is_file()
        assert (site / 'private/content.json').is_file()
        try:
            client.open(base + '/setup.php', timeout=5)
            raise AssertionError('Repeated installation was allowed')
        except urllib.error.HTTPError as error:
            assert error.code == 404
        output = subprocess.check_output([args.php,'-r','require '+repr(str(site/'private/app.php'))+'; echo json_encode([count(fd_data()["products"]),array_sum(array_map(fn($p)=>count($p["sizes"]),fd_data()["products"])),fd_config()["password_hash"]!=="temporary-test-only-password"]);'],text=True)
        assert output == '[8,128,true]', output
        print('Release ZIP: web installation, one-time token, password hashing and 8 products / 128 prices passed.')
    finally:
        server.terminate()
        server.wait(timeout=5)
        log.close()
