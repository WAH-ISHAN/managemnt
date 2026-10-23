"""Exercise the Windows updater against a local release feed."""
import hashlib
import http.server
import io
import json
import subprocess
import tempfile
import threading
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE_ZIP = ROOT / 'dist' / 'crab-windows.zip'
assert SOURCE_ZIP.exists(), 'Run scripts/Build-Local.ps1 first.'

with tempfile.TemporaryDirectory(prefix='crab-update-') as folder:
    temp = Path(folder)
    package = temp / 'package'
    package.mkdir()
    with zipfile.ZipFile(SOURCE_ZIP) as archive:
        archive.extractall(package)
    installed = temp / 'installed'
    subprocess.run(['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', str(package / 'Install-Crab.ps1'), '-Destination', str(installed), '-SkipShortcut', '-SkipScheduledTask'], check=True, stdout=subprocess.DEVNULL)
    assert (installed / 'current.txt').read_text().strip() == 'v0-0000000'
    sentinel = installed / 'data' / 'keep.txt'
    sentinel.write_text('user data', encoding='utf-8')

    release_zip = temp / 'update.zip'
    with zipfile.ZipFile(SOURCE_ZIP) as old, zipfile.ZipFile(release_zip, 'w', compression=zipfile.ZIP_DEFLATED) as new:
        for entry in old.infolist():
            contents = old.read(entry.filename)
            if entry.filename == 'crab-release.json':
                contents = json.dumps({'version':'v1-abcdef1','buildNumber':1,'repository':'WAH-ISHAN/managemnt'}).encode()
            new.writestr(entry, contents)
    binary = release_zip.read_bytes()
    digest = hashlib.sha256(binary).hexdigest()
    feed = {'invalid': True}

    class Handler(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            if self.path.endswith('/releases/latest'):
                body = json.dumps({'tag_name':'v1-abcdef1','assets':[{'name':'crab-windows.zip','digest':'sha256:' + ('0'*64 if feed['invalid'] else digest),'size':len(binary),'browser_download_url':f'http://127.0.0.1:{self.server.server_port}/crab-windows.zip'}]}).encode()
                self.send_response(200)
                self.send_header('Content-Type','application/json')
            elif self.path == '/crab-windows.zip':
                body = binary
                self.send_response(200)
                self.send_header('Content-Type','application/zip')
            else:
                body = b''
                self.send_response(404)
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *_):
            pass

    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        command = ['powershell.exe','-NoProfile','-ExecutionPolicy','Bypass','-File',str(installed / 'Update-Crab.ps1'),'-ApiBase',f'http://127.0.0.1:{server.server_port}/repos']
        subprocess.run(command, check=True, timeout=60)
        assert (installed / 'current.txt').read_text().strip() == 'v0-0000000', 'Bad digest must not activate'
        feed['invalid'] = False
        subprocess.run(command, check=True, timeout=60)
        assert (installed / 'current.txt').read_text().strip() == 'v1-abcdef1', (installed / 'update.log').read_text()
        assert (installed / 'versions' / 'v1-abcdef1' / 'app' / 'public' / 'index.php').exists()
        assert sentinel.read_text(encoding='utf-8') == 'user data'
        print('PASS: rejected bad SHA-256, installed release, switched version, preserved data')
    finally:
        server.shutdown()
