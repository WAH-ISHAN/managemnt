"""Disposable browser acceptance: queued/error/review, sharing, locales and recurrence."""
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
OUT = ROOT / 'tests/.tmp' / ('workflow-extensions-' + uuid.uuid4().hex)
DATA = OUT / 'data'
DATA.mkdir(parents=True)
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
base = f'http://127.0.0.1:{port}'
checks = []
errors = []
def check(ok, label):
    assert ok, label
    checks.append(label)

with (OUT / 'server.log').open('w', encoding='utf-8') as log:
    server = subprocess.Popen([os.environ.get('LIFEOS_QA_PHP', 'php'), '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'], cwd=ROOT, env=dict(os.environ, LIFEOS_DATA_DIR=str(DATA)), stdout=log, stderr=log, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
    try:
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        for _ in range(100):
            try:
                opener.open(base + '/health', timeout=2).close()
                break
            except OSError:
                time.sleep(.1)
        db = sqlite3.connect(DATA / 'lifeos.db')
        db.execute("INSERT INTO settings(key,value) VALUES ('onboarding_complete','1') ON CONFLICT(key) DO UPDATE SET value='1'")
        db.execute("INSERT INTO intelligence_sources(kind,body) VALUES ('note',?)", ('Discussed the launch. We should email the revised plan tomorrow.',))
        source = db.execute('SELECT id FROM intelligence_sources').fetchone()[0]
        db.commit()
        with sync_playwright() as pw:
            browser = pw.chromium.launch(headless=True)
            page = browser.new_page(viewport={'width': 1365, 'height': 900}, reduced_motion='reduce')
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.goto(base + f'/intelligence?source={source}')
            share = page.locator('form[action="/intelligence/understand"]')
            expect(share.locator('[name=share_source]')).not_to_be_checked()
            check(not list((DATA / 'ai/jobs').glob('*.json')), 'Viewing private note sends no record')
            share.locator('[name=share_source]').check()
            share.locator('button').click()
            page.wait_for_url('**/intelligence/understand/*')
            expect(page.locator('#understand-result')).to_contain_text('Preparing your summary')
            page.screenshot(path=str(OUT / 'summary-loading.png'), full_page=True)
            job_id = page.url.rsplit('/', 1)[-1]
            job_path = DATA / 'ai/jobs' / (job_id + '.json')
            job = json.loads(job_path.read_text(encoding='utf-8'))
            check('open_tasks' not in job['context'] and job['workflow']['source'] == source, 'Explicitly shared note excludes other records')
            job.update(status='complete', answer=json.dumps({'answer': 'The team agreed to revise the plan. <script>throw Error()</script>', 'actions': [{'action': 'add_task', 'title': 'Email revised plan', 'due': '2099-12-01'}]}))
            job_path.write_text(json.dumps(job), encoding='utf-8')
            expect(page.locator('#understand-result')).to_contain_text('The team agreed')
            check(db.execute('SELECT COUNT(*) FROM tasks').fetchone()[0] == 0, 'Summary and proposals do not write tasks')
            check(page.locator('#understand-result script').count() == 0, 'Untrusted model content is escaped')
            action = page.locator('form[action$="/apply"]').last
            action.locator('[name=title]').fill('Reviewed plan task')
            page.screenshot(path=str(OUT / 'summary-action-review.png'), full_page=True)
            action.get_by_role('button', name='Confirm and apply', exact=True).focus()
            page.keyboard.press('Enter')
            expect(page.locator('#understand-result')).to_contain_text('Saved')
            check(db.execute('SELECT title FROM tasks').fetchone()[0] == 'Reviewed plan task', 'Keyboard confirmation saves reviewed task')
            check(db.execute('SELECT source_id FROM intelligence_task_links').fetchone()[0] == source, 'Saved task retains note source')
            for locale in ['en', 'si', 'ta']:
                db.execute("INSERT INTO settings(key,value) VALUES ('locale',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (locale,))
                db.commit()
                for theme in ['light', 'dark']:
                    for width in [390, 1365]:
                        page.set_viewport_size({'width': width, 'height': 900})
                        page.goto(base + '/intelligence/understand/' + job_id)
                        page.evaluate('(theme)=>{localStorage.setItem("lifeos-theme",theme);document.documentElement.dataset.theme=theme}', theme)
                        check(page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), f'Review fits {locale}/{theme}/{width}')
                        if width == 390:
                            page.screenshot(path=str(OUT / f'review-{locale}-{theme}.png'), full_page=True)
            db.execute("UPDATE settings SET value='en' WHERE key='locale'")
            db.commit()
            job.update(status='failed', error='private provider quota diagnostics')
            job_path.write_text(json.dumps(job), encoding='utf-8')
            page.goto(base + '/intelligence/understand/' + job_id)
            expect(page.get_by_role('alert')).to_contain_text('temporarily unavailable')
            page.screenshot(path=str(OUT / 'summary-error.png'), full_page=True)
            check('private provider' not in page.locator('#understand-result').inner_text(), 'Failure hides upstream diagnostics')
            page.goto(base + '/automation')
            page.locator('#rule-builder > summary').click()
            page.get_by_text('Timing and reliability', exact=True).click()
            builder = page.locator('form[action="/automation/rules"]')
            builder.locator('[name=name]').fill('Browser activity routine')
            builder.locator('[name=event]').select_option('task_completed')
            builder.locator('[name=timezone]').select_option('Europe/London')
            builder.locator('[name="actions[0][type]"]').select_option('notify')
            builder.locator('[name="actions[0][title]"]').fill('Finished a task')
            builder.locator('[name="actions[0][body]"]').fill('Choose the next step')
            builder.locator('[name=retry_limit]').fill('2')
            builder.locator('button').last.click()
            expect(page.locator('article.task-row').filter(has_text='Browser activity routine')).to_be_visible()
            conditions = json.loads(db.execute("SELECT conditions FROM automation_rules WHERE name='Browser activity routine'").fetchone()[0])
            check(conditions['event'] == 'task_completed' and conditions['timezone'] == 'Europe/London' and conditions['retry_limit'] == 2, 'Real routine form persists event/timezone/retry settings')
            page.goto(base + '/connectivity')
            form = page.locator('form[action="/connectivity/calendar/preview"]')
            form.locator('[name=ics]').fill('BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nUID:ui-recurring\nSUMMARY:UI weekly session\nDTSTART:20990105T090000Z\nDTEND:20990105T100000Z\nRRULE:FREQ=WEEKLY;COUNT=3\nEND:VEVENT\nEND:VCALENDAR')
            form.locator('[name=from]').fill('2099-01-01')
            form.locator('[name=until]').fill('2099-02-01')
            form.locator('button').last.click()
            expect(page.locator('tbody tr')).to_have_count(3)
            check(db.execute("SELECT COUNT(*) FROM planner_appointments WHERE title='UI weekly session'").fetchone()[0] == 0, 'Recurring calendar waits for review')
            page.locator('form[action="/connectivity/calendar/import"] button').click()
            expect(page.locator('form[action="/connectivity/calendar/preview"]')).to_be_visible()
            check(db.execute("SELECT COUNT(*) FROM planner_appointments WHERE title='UI weekly session'").fetchone()[0] == 3, 'Confirmed recurrence creates three occurrences')
            check(not errors, 'No uncaught browser errors')
            browser.close()
        db.close()
        print(f'{len(checks)} workflow extension browser checks passed. Evidence: {OUT}')
    finally:
        server.terminate()
        server.wait(timeout=15)
        (OUT / 'results.json').write_text(json.dumps({'checks': checks, 'errors': errors}, ensure_ascii=False, indent=2), encoding='utf-8')
