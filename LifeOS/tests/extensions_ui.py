"""Real headless Chromium capture/planning/assistant smoke, disposable records only."""
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.request
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
output = ROOT / 'tests/.tmp/extensions-ui'
output.mkdir(parents=True, exist_ok=True)
checks = []
errors = []
metrics = {}

def check(ok, label):
    if not ok:
        raise AssertionError(label)
    checks.append(label)

with tempfile.TemporaryDirectory(prefix='lifeos-extensions-ui-') as temporary:
    sandbox = Path(temporary)
    project = sandbox / 'project'
    data = sandbox / 'data'
    data.mkdir()
    for folder in ['app', 'public']:
        shutil.copytree(ROOT / folder, project / folder)
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    php = os.environ.get('LIFEOS_QA_PHP', 'php')
    with (sandbox / 'server.log').open('w', encoding='utf-8') as log:
        server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'], cwd=project, env=dict(os.environ, LIFEOS_DATA_DIR=str(data), LIFEOS_INSTANCE='extensions-ui-qa'), stdout=log, stderr=log, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        db = None
        try:
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            ready = False
            for _ in range(100):
                try:
                    with opener.open(base + '/health', timeout=2) as response:
                        ready = response.status == 200
                        if ready:
                            break
                except OSError:
                    pass
                time.sleep(.1)
            check(ready, 'Disposable PHP server starts')
            db = sqlite3.connect(data / 'lifeos.db')
            with sync_playwright() as pw:
                browser = pw.chromium.launch(headless=True)
                context = browser.new_context(viewport={'width': 1365, 'height': 900}, reduced_motion='reduce')
                page = context.new_page()
                page.on('pageerror', lambda error: errors.append(str(error)))
                page.on('console', lambda message: errors.append(message.text) if message.type == 'error' else None)
                def snapshot(name, width=1365):
                    page.set_viewport_size({'width': width, 'height': 900})
                    page.evaluate('window.scrollTo(0,0)')
                    page.screenshot(path=str(output / f'{name}.png'), full_page=True)
                    result = page.evaluate('() => ({width:innerWidth,document:document.documentElement.scrollWidth,language:document.documentElement.lang})')
                    metrics[name] = result
                    check(result['document'] <= result['width'] + 1, name + ' fits viewport')
                page.goto(base + '/planner/capture')
                capture = page.locator('form[action="/planner/capture"]')
                capture.locator('textarea[name=text]').fill('Call QA team 2099-01-05 5pm !high ~45m')
                capture.locator('button').click()
                review = page.locator('form[action$="/convert"]')
                expect(review.locator('input[name=title]')).to_have_value('Call QA team')
                expect(review.locator('input[name=due]')).to_have_value('2099-01-05')
                expect(review.locator('select[name=priority]')).to_have_value('high')
                expect(review.locator('input[name=estimate_minutes]')).to_have_value('45')
                check(db.execute('SELECT COUNT(*) FROM tasks').fetchone()[0] == 0, 'Capture review creates no task')
                snapshot('capture-review-desktop')
                snapshot('capture-review-narrow', 390)
                review.get_by_role('button', name='Create task', exact=True).click()
                expect(page.locator('form[action$="/convert"]')).to_have_count(0)
                check(db.execute('SELECT COUNT(*) FROM tasks WHERE title=?', ('Call QA team',)).fetchone()[0] == 1, 'Reviewed capture creates one real task')
                page.goto(base + '/planner/planning')
                prefs = page.locator('form[action="/planner/planning/preferences"]')
                prefs.locator('input[name=start]').fill('09:00')
                prefs.locator('input[name=end]').fill('12:00')
                for checkbox in prefs.locator('input[name="days[]"]').all():
                    checkbox.check()
                prefs.locator('input[name=archive_days]').fill('0')
                prefs.locator('button').click()
                expect(page.get_by_role('status')).to_contain_text('Availability saved.')
                form = page.locator('form[action="/planner/planning/preview"]')
                form.locator('input[name=date]').fill('2099-01-05')
                form.locator('button:not([type=button])').click()
                expect(page.locator('form[action="/planner/planning/apply"]')).to_be_visible()
                expect(page.locator('form[action="/planner/planning/preview"] input[name=date]')).to_have_value('2099-01-05')
                check(db.execute('SELECT COUNT(*) FROM planner_blocks').fetchone()[0] == 0, 'Planning preview creates no saved blocks')
                snapshot('planning-preview-desktop')
                snapshot('planning-preview-narrow', 390)
                page.locator('section.card').filter(has=page.locator('form[action="/planner/planning/preview"]')).screenshot(path=str(output / 'planning-preview-card-narrow.png'))
                page.locator('form[action="/planner/planning/apply"] button').click()
                expect(page.get_by_role('status')).to_contain_text('Plan applied.')
                check(db.execute('SELECT COUNT(*) FROM planner_blocks').fetchone()[0] == 1, 'Browser Apply saves one future time block')
                snapshot('planning-applied-desktop')
                page.locator('form[action$="/undo"] button').click()
                expect(page.get_by_role('status')).to_contain_text('Plan undone.')
                check(db.execute('SELECT COUNT(*) FROM planner_blocks').fetchone()[0] == 0, 'Browser Undo removes generated blocks')
                page.goto(base + '/insights/trends')
                expect(page.locator('h1')).to_have_text('Trends & workload')
                expect(page.get_by_text('No active habits yet.', exact=True)).to_be_visible()
                snapshot('trends-desktop')
                snapshot('trends-narrow', 390)
                page.goto(base + '/intelligence')
                page.get_by_text('Text & local tools', exact=True).click()
                expect(page.locator('form[action="/intelligence/settings"]')).to_be_visible()
                snapshot('assistant-desktop')
                snapshot('assistant-narrow', 390)
                for locale, heading in [('si', 'ඉක්මන් සටහන'), ('ta', 'விரைவுப் பதிவு')]:
                    page.goto(base + '/settings/workspace')
                    language = page.locator('form[action="/settings/workspace/language"]')
                    language.locator('select[name=locale]').select_option(locale)
                    language.locator('button').click()
                    expect(page.locator('html')).to_have_attribute('lang', locale)
                    page.goto(base + '/planner/capture')
                    expect(page.locator('html')).to_have_attribute('lang', locale)
                    expect(page.locator('h1')).to_have_text(heading)
                    check(True, locale + ' capture uses saved language')
                    snapshot('capture-' + locale + '-narrow', 390)
                    page.goto(base + '/intelligence')
                    expect(page.locator('h1')).to_contain_text('LifeOS')
                    snapshot('assistant-' + locale + '-narrow', 390)
                check(not errors, 'No browser console or JavaScript errors')
                application_errors = data / 'php_error.log'
                check(not application_errors.exists() or not application_errors.read_text(encoding='utf-8').strip(), 'No PHP application errors')
                context.close()
                browser.close()
            print(f'{len(checks)} extension browser UI checks passed. Screenshots: {output}')
        finally:
            if db:
                db.close()
            (output / 'results.json').write_text(json.dumps({'checks': checks, 'browserErrors': errors, 'viewports': metrics}, ensure_ascii=False, indent=2), encoding='utf-8')
            server.terminate()
            try:
                server.wait(timeout=10)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
