"""Build a deployment archive without local credentials or development state."""
from pathlib import Path
import secrets
import stat
import zipfile

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / 'dist'
OUT.mkdir(exist_ok=True)
target = OUT / 'FantasyDream-Apache.zip'
excluded = {'config.local.php', 'content.json', 'setup-token.txt'}
with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
    for folder in ('public_html', 'private', 'tools', 'tests'):
        for path in sorted((ROOT / folder).rglob('*')):
            if not path.is_file():
                continue
            relative = path.relative_to(ROOT)
            if path.name in excluded or any(part in {'runtime', 'backups', '__pycache__'} for part in relative.parts):
                continue
            if 'uploads' in relative.parts and path.name != '.htaccess':
                continue
            entry = zipfile.ZipInfo.from_file(path, relative.as_posix())
            entry.external_attr = (stat.S_IFREG | 0o644) << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(entry, path.read_bytes())
    for name in ('README.ru.md', 'CATALOG-NOTES.ru.md', 'VALIDATION.ru.md'):
        if (ROOT / name).is_file():
            archive.write(ROOT / name, name)
    entry = zipfile.ZipInfo('private/setup-token.txt')
    entry.external_attr = (stat.S_IFREG | 0o600) << 16
    entry.compress_type = zipfile.ZIP_DEFLATED
    archive.writestr(entry, secrets.token_hex(32) + '\n')
print(target)
print(f'{target.stat().st_size:,} bytes')
