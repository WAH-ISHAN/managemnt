"""Actual all-day appointment controls with disposable records and no provider calls."""
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
OUT = ROOT / 'tests/.tmp' / ('calendar-completion-ui-' + uuid.uuid4().hex)
DATA = OUT / 'data'
DATA.mkdir(parents=True)
with socket.socket() as probe:
    probe.bind(('127.0.0.1', 0))
    port = probe.getsockname()[1]
base = f'http://127.0.0.1:{port}'
checks = []
errors = []
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
            page = browser.new_page(viewport={'width': 1280, 'height': 900}, reduced_motion='reduce')
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.goto(base + '/planner?view=appointments')
            add = page.locator('[data-appointment-form]').last
            add.locator('[name=title]').fill('Calendar UI leave')
            add.locator('[type=checkbox][name=all_day]').check()
            expect(add.locator('[name=starts_on]')).to_be_visible()
            check(add.locator('[name=starts_at]').is_disabled(), 'all-day selection disables hidden timed inputs')
            add.locator('[name=starts_on]').fill('2099-06-12')
            add.locator('[name=ends_on]').fill('2099-06-13')
            add.locator('button').last.click()
            page.wait_for_url('**/planner?view=appointments')
            expect(page.get_by_text('Calendar UI leave', exact=True)).to_be_visible()
            row = db.execute("SELECT id,all_day,starts_at,ends_at FROM planner_appointments WHERE title='Calendar UI leave'").fetchone()
            check(row[1:] == (1, '2099-06-12 00:00', '2099-06-14 00:00'), 'actual form creates a multi-day exclusive range')
            entry = page.locator('article.lesson-entry').filter(has=page.get_by_text('Calendar UI leave', exact=True))
            expect(entry).to_contain_text('All day')
            expect(entry).to_contain_text('2099-06-13')
            check(True, 'list labels all-day leave with its inclusive last day')
            page.screenshot(path=str(OUT / 'all-day-desktop.png'), full_page=True)
            entry.locator('summary').click()
            edit = entry.locator('[data-appointment-form]')
            check(edit.locator('[name=ends_on]').input_value() == '2099-06-13', 'edit retains last day without extending the event')
            edit.locator('[type=checkbox][name=all_day]').uncheck()
            expect(edit.locator('[name=starts_at]')).to_be_visible()
            check(edit.locator('[name=starts_on]').is_disabled(), 'timed selection disables hidden date-only fields')
            edit.locator('[name=starts_at]').fill('2099-06-12T09:00')
            edit.locator('[name=ends_at]').fill('2099-06-12T10:00')
            edit.locator('button').last.click()
            expect(page.locator('article.lesson-entry').filter(has=page.get_by_text('Calendar UI leave', exact=True))).to_contain_text('2099-06-12 09:00')
            check(db.execute('SELECT all_day,starts_at,ends_at FROM planner_appointments WHERE id=?', (row[0],)).fetchone() == (0, '2099-06-12 09:00', '2099-06-12 10:00'), 'actual edit can convert an all-day appointment to timed')
            page.goto(base + '/connectivity')
            expect(page.locator('[name=calendar_delete]')).not_to_be_checked()
            check(True, 'remote deletion has a separate default-off control')
            page.locator('[name=calendar_push]').check()
            page.locator('[name=calendar_delete]').check()
            with page.expect_navigation(wait_until='networkidle'):
                page.get_by_role('button', name='Save connection preferences', exact=True).click()
            expect(page.locator('[name=calendar_delete]')).to_be_checked()
            config = json.loads(db.execute("SELECT value FROM settings WHERE key='connections_config'").fetchone()[0])
            check(config['calendar_push'] and config['calendar_delete'], 'explicit calendar deletion preference persists')
            page.goto(base + '/planner?view=appointments')
            page.set_viewport_size({'width': 390, 'height': 900})
            check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), 'appointment form fits narrow view')
            add = page.locator('[data-appointment-form]').last
            add.locator('[type=checkbox][name=all_day]').focus()
            page.keyboard.press('Space')
            expect(add.locator('[name=starts_on]')).to_be_visible()
            check(add.locator('[type=checkbox][name=all_day]').is_checked(), 'keyboard Space switches to calendar-date controls')
            add.scroll_into_view_if_needed()
            page.screenshot(path=str(OUT / 'appointments-narrow.png'))
            page.evaluate("localStorage.setItem('lifeos-theme','dark')")
            page.reload()
            add = page.locator('[data-appointment-form]').last
            add.locator('[type=checkbox][name=all_day]').check()
            add.scroll_into_view_if_needed()
            page.screenshot(path=str(OUT / 'all-day-dark-narrow.png'))
            for locale, label in [('si', '\u0db8\u0dd4\u0dc5\u0dd4 \u0daf\u0dc0\u0dc3\u0db8'), ('ta', '\u0ba8\u0bbe\u0bb3\u0bcd \u0bae\u0bc1\u0bb4\u0bc1\u0bb5\u0ba4\u0bc1\u0bae\u0bcd')]:
                db.execute("INSERT INTO settings(key,value) VALUES ('locale',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (locale,))
                db.commit()
                page.reload()
                add = page.locator('[data-appointment-form]').last
                expect(add).to_contain_text(label)
                check(True, locale + ' all-day field is translated')
                add.locator('[type=checkbox][name=all_day]').check()
                add.scroll_into_view_if_needed()
                page.screenshot(path=str(OUT / (locale + '-appointments-narrow.png')))
            check(not errors, 'no JavaScript errors during calendar controls')
            browser.close()
        (OUT / 'results.json').write_text(json.dumps({'checks': checks, 'errors': errors}, indent=2), encoding='utf-8')
        print(f'Calendar UI: {len(checks)} checks passed. Evidence: {OUT}')
    finally:
        if db is not None:
            db.close()
        server.terminate()
        server.wait(timeout=10)
