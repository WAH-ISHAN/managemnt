"""Calendar workflows against an isolated real PHP workspace."""
import json
import os
from pathlib import Path
import socket
import sqlite3
import subprocess
import time
import urllib.request
import uuid
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'tests/.tmp' / ('planner-month-ui-' + uuid.uuid4().hex)
DATA = OUT / 'data'
DATA.mkdir(parents=True)
with socket.socket() as probe:
    probe.bind(('127.0.0.1', 0))
    port = probe.getsockname()[1]
base = f'http://127.0.0.1:{port}'
checks, errors = [], []

def check(ok, label):
    if not ok:
        raise AssertionError(label)
    checks.append(label)

with (OUT / 'server.log').open('w', encoding='utf-8') as log:
    server = subprocess.Popen([os.environ.get('LIFEOS_QA_PHP', 'php'), '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'], cwd=ROOT, env=dict(os.environ, LIFEOS_DATA_DIR=str(DATA)), stdout=log, stderr=log, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    db = None
    try:
        for _ in range(100):
            try:
                urllib.request.build_opener(urllib.request.ProxyHandler({})).open(base + '/health', timeout=2).close()
                break
            except OSError:
                time.sleep(.1)
        db = sqlite3.connect(DATA / 'lifeos.db')
        db.execute("INSERT INTO settings(key,value) VALUES ('onboarding_complete','1') ON CONFLICT(key) DO UPDATE SET value='1'")
        db.commit()
        with sync_playwright() as pw:
            browser = pw.chromium.launch(headless=True)
            page = browser.new_page(viewport={'width': 1440, 'height': 1000}, reduced_motion='reduce')
            page.on('pageerror', lambda error: errors.append(str(error)))
            for kind, title, first, last in [('renewal', 'Domain renewal QA', '2099-06-15', '2099-06-15'), ('exam', 'Examination QA', '2099-06-16', '2099-06-16'), ('event', 'Conference QA', '2099-05-31', '2099-06-02')]:
                page.goto(base + '/planner?view=appointments&date=' + first)
                form = page.locator('[data-appointment-form]').last
                form.locator('[name=title]').fill(title)
                form.locator('[name=kind]').select_option(kind)
                form.locator('[type=checkbox][name=all_day]').check()
                form.locator('[name=starts_on]').fill(first)
                form.locator('[name=ends_on]').fill(last)
                form.locator('button').last.click()
                expect(page.locator('article.lesson-entry').filter(has=page.get_by_text(title, exact=True))).to_be_visible()
                check(db.execute('SELECT kind FROM planner_appointments WHERE title=?', (title,)).fetchone()[0] == kind, kind + ' form persists its type')
            page.goto(base + '/planner?view=calendar&date=2099-06-15')
            expect(page.get_by_role('heading', name='June 2099')).to_be_visible()
            expect(page.locator('.lifeos-month-entry').filter(has_text='Domain renewal QA')).to_be_visible()
            expect(page.locator('.lifeos-month-entry').filter(has_text='Examination QA')).to_be_visible()
            check(page.locator('.lifeos-month-entry').filter(has_text='Conference QA').count() >= 2, 'spanning event appears on both June dates')
            page.screenshot(path=str(OUT / 'calendar-desktop.png'), full_page=True)
            page.get_by_role('link', name='Next month', exact=True).click()
            expect(page.get_by_role('heading', name='July 2099')).to_be_visible()
            page.get_by_role('link', name='Previous month', exact=True).click()
            expect(page.get_by_role('heading', name='June 2099')).to_be_visible()
            check(True, 'month navigation works through boosted page swaps')
            for theme in ['dark', 'light']:
                page.evaluate('(theme) => localStorage.setItem("lifeos-theme", theme)', theme)
                page.reload()
                for width in [390, 320]:
                    page.set_viewport_size({'width': width, 'height': 900})
                    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), f'calendar fits {width}px {theme} viewport')
                    page.screenshot(path=str(OUT / f'calendar-{theme}-{width}.png'), full_page=True)
            page.set_viewport_size({'width': 1440, 'height': 1000})
            page.locator('.lifeos-month-entry').filter(has_text='Domain renewal QA').focus()
            page.keyboard.press('Enter')
            expect(page.locator('article.lesson-entry').filter(has=page.get_by_text('Domain renewal QA', exact=True))).to_be_visible()
            entry = page.locator('article.lesson-entry').filter(has=page.get_by_text('Domain renewal QA', exact=True))
            entry.locator('summary').click()
            edit = entry.locator('[data-appointment-form]')
            expect(edit.locator('[name=kind]')).to_have_value('renewal')
            edit.locator('[name=title]').fill('Renewal moved QA')
            edit.locator('[name=starts_on]').fill('2099-07-15')
            edit.locator('[name=ends_on]').fill('2099-07-15')
            edit.locator('button').last.click()
            expect(page.locator('article.lesson-entry').filter(has=page.get_by_text('Renewal moved QA', exact=True))).to_be_visible()
            page.goto(base + '/planner?view=calendar&date=2099-06-15')
            expect(page.locator('.lifeos-month-entry').filter(has_text='Renewal moved QA')).to_have_count(0)
            page.goto(base + '/planner?view=calendar&date=2099-07-15')
            expect(page.locator('.lifeos-month-entry').filter(has_text='Renewal moved QA')).to_be_visible()
            check(True, 'keyboard opens event and editing moves it to its new month')
            page.locator('.lifeos-month-entry').filter(has_text='Renewal moved QA').click()
            entry = page.locator('article.lesson-entry').filter(has=page.get_by_text('Renewal moved QA', exact=True))
            page.once('dialog', lambda dialog: dialog.accept())
            entry.get_by_role('button', name='Delete appointment', exact=True).click()
            expect(page.locator('article.lesson-entry').filter(has=page.get_by_text('Renewal moved QA', exact=True))).to_have_count(0)
            check(db.execute("SELECT COUNT(*) FROM planner_appointments WHERE title='Renewal moved QA'").fetchone()[0] == 0, 'confirmed delete removes appointment')
            check(not errors, 'no JavaScript errors during calendar workflows')
            browser.close()
        (OUT / 'results.json').write_text(json.dumps({'checks': checks, 'errors': errors}, indent=2), encoding='utf-8')
        print(f'Planner month calendar UI: {len(checks)} checks passed. Evidence: {OUT}')
    finally:
        if db is not None:
            db.close()
        server.terminate()
        server.wait(timeout=10)
