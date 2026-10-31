"""Compare HTTP bootstrap costs on disposable loaded data; no desktop/user instance."""
import argparse
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import statistics
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--baseline-modules', type=Path)
parser.add_argument('--output', type=Path, required=True)
parser.add_argument('--samples', type=int, default=20)
args = parser.parse_args()
php = os.environ.get('LIFEOS_QA_PHP', 'php')


def percentile(values, fraction):
    return round(sorted(values)[min(len(values) - 1, int(len(values) * fraction))], 3)


def benchmark(project, data):
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
        urllib.request.ProxyHandler({}))

    def request(route, fields=None, headers=None):
        body = urllib.parse.urlencode(fields).encode() if fields else None
        started = time.perf_counter()
        with opener.open(urllib.request.Request(f'http://127.0.0.1:{port}' + route, body, headers or {}), timeout=15) as response:
            result = response.read()
            return result, response.headers, (time.perf_counter() - started) * 1000

    with (data / 'benchmark-server.log').open('w') as log:
        server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'],
                                  cwd=project, env=dict(os.environ, LIFEOS_DATA_DIR=str(data)), stdout=log, stderr=log)
        try:
            for attempt in range(100):
                try:
                    request('/health')
                    break
                except OSError:
                    time.sleep(.05)
            routes = ['/', '/planner?view=tasks', '/work', '/uni/calendar/config', '/insights/alerts']
            for route in routes:
                request(route, headers={'HX-Request': 'true'})
            results = {route: {'http_ms': [], 'modules_ms': [], 'migrations_ms': [], 'bootstrap_ms': []} for route in routes}
            for _ in range(args.samples):
                for route in routes:
                    _, headers, elapsed = request(route, headers={'HX-Request': 'true'})
                    timing = json.loads(headers['X-LifeOS-QA-Timing'])
                    for key in results[route]:
                        results[route][key].append(elapsed if key == 'http_ms' else timing[key])
            return {route: {key: {'median': round(statistics.median(values), 3), 'p95': percentile(values, .95)}
                            for key, values in timings.items()} for route, timings in results.items()}
        finally:
            server.terminate()
            server.wait(timeout=15)


with tempfile.TemporaryDirectory(prefix='lifeos-request-performance-') as temporary:
    work = Path(temporary)
    project = work / 'project'
    for folder in ['app', 'public']:
        shutil.copytree(ROOT / folder, project / folder)
    bootstrap = project / 'app/Core/bootstrap.php'
    source = bootstrap.read_text(encoding='utf-8')
    source = source.replace("define('ROOT',", "$qaStarted=hrtime(true);\ndefine('ROOT',", 1)
    source = source.replace('Migrator::run($dirs);', "$qaMigrationStarted=hrtime(true);Migrator::run($dirs);$qaMigrationMs=(hrtime(true)-$qaMigrationStarted)/1e6;", 1)
    source = source.replace('Modules::register($mods,$router);', "$qaModuleStarted=hrtime(true);Modules::register($mods,$router);$qaModuleMs=(hrtime(true)-$qaModuleStarted)/1e6;", 1)
    source = source.replace("$router->dispatch($_SERVER['REQUEST_METHOD'],$path);", "header('X-LifeOS-QA-Timing: '.json_encode(['modules_ms'=>$qaModuleMs,'migrations_ms'=>$qaMigrationMs,'bootstrap_ms'=>(hrtime(true)-$qaStarted)/1e6]));$router->dispatch($_SERVER['REQUEST_METHOD'],$path);", 1)
    bootstrap.write_text(source, encoding='utf-8')
    seed = work / 'seed'
    seed.mkdir()
    seeded = subprocess.run([php, str(ROOT / 'tests/seed_load.php'), str(seed)], capture_output=True, text=True, check=True)
    with sqlite3.connect(seed / 'lifeos.db') as connection:
        connection.execute("INSERT INTO settings(key,value) VALUES ('onboarding_complete','1') ON CONFLICT(key) DO UPDATE SET value=excluded.value")
        connection.execute("INSERT INTO settings(key,value) VALUES ('name','Disposable performance owner') ON CONFLICT(key) DO UPDATE SET value=excluded.value")
    connection.close()
    variants = [('current', (ROOT / 'app/Core/Modules.php').read_bytes())]
    if args.baseline_modules:
        variants.insert(0, ('baseline', args.baseline_modules.read_bytes()))
    report = {'timestamp': time.strftime('%Y-%m-%d %H:%M:%S'), 'scope': 'HTTP transfer and disposable instrumented bootstrap; no browser paint/native measurements',
              'samples_per_route_variant': args.samples, 'seed_rows': json.loads(seeded.stdout), 'variants': {}}
    for name, module_source in variants:
        (project / 'app/Core/Modules.php').write_bytes(module_source)
        data = work / name
        shutil.copytree(seed, data)
        report['variants'][name] = benchmark(project, data)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps({name: {route: timings['http_ms'] for route, timings in results.items()} for name, results in report['variants'].items()}, indent=2))
