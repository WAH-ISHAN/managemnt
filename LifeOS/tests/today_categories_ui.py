"""Verify Today categories against a disposable migrated workspace and real browser."""

import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import time
import urllib.request

from playwright.sync_api import expect, sync_playwright


ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'tests/.tmp/today-categories-ui'
OUT.mkdir(parents=True, exist_ok=True)
FIXTURE = OUT / f'fixture-{time.time_ns()}'
PROJECT = FIXTURE / 'project'
shutil.copytree(ROOT / 'app', PROJECT / 'app')
shutil.copytree(ROOT / 'public', PROJECT / 'public')
MIGRATION = PROJECT / 'app/Modules/Planner/migrations/005_task_categories.sql'
MIGRATION.unlink()  # Create a pre-category workspace before applying the new migration.
DATA = FIXTURE / 'data'
DATA.mkdir()

with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    PORT = sock.getsockname()[1]
BASE = f'http://127.0.0.1:{PORT}'

checks = []


def check(value, label):
    assert value, label
    checks.append(label)


log = (FIXTURE / 'server.log').open('w', encoding='utf-8')
server = subprocess.Popen(
    [os.environ.get('LIFEOS_QA_PHP', 'php'), '-S', f'127.0.0.1:{PORT}', '-t', 'public', 'public/index.php'],
    cwd=PROJECT,
    env=dict(os.environ, LIFEOS_DATA_DIR=str(DATA)),
    stdout=log,
    stderr=log,
    creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0),
)

try:
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    for _ in range(100):
        try:
            with opener.open(BASE + '/health', timeout=2) as response:
                check(response.status == 200, 'Initial workspace migrated')
            break
        except OSError:
            time.sleep(.1)
    else:
        raise RuntimeError('Disposable PHP server did not start')

    db = sqlite3.connect(DATA / 'lifeos.db')
    db.executemany(
        'INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',
        [('name', 'Today category test'), ('onboarding_complete', '1')],
    )
    db.executemany(
        'INSERT INTO tasks(title,due,source) VALUES (?,?,?)',
        [
            ('ML EXAM', '2027-02-17', 'planner'),
            ('ECS exam', '2027-02-22', 'planner'),
            ('Example report', '2027-02-23', 'planner'),
            ('flxware tech web update', None, 'desktop-panel-todo'),
            ('Exam checklist', None, 'desktop-panel-todo'),
        ],
    )
    db.execute('INSERT INTO desktop_panel_notes(title,body) VALUES (?,?)', ('Panel idea', 'Keep this private note in its own group.'))
    db.commit()

    shutil.copy2(ROOT / 'app/Modules/Planner/migrations/005_task_categories.sql', MIGRATION)
    with opener.open(BASE + '/health', timeout=2) as response:
        check(response.status == 200, 'Category migration applied to existing workspace')
    categories = dict(db.execute('SELECT title,category FROM tasks'))
    check(categories == {
        'ML EXAM': 'exam', 'ECS exam': 'exam', 'Example report': 'task',
        'flxware tech web update': 'todo', 'Exam checklist': 'todo',
    }, 'Existing exam names and panel to-dos migrated without classifying Example report')

    errors = []
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        page = browser.new_page(viewport={'width': 1365, 'height': 900}, reduced_motion='reduce')
        page.on('pageerror', lambda error: errors.append(str(error)))
        for locale in ('en', 'si', 'ta'):
            db.execute("INSERT INTO settings(key,value) VALUES ('locale',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (locale,))
            db.commit()
            for theme in ('light', 'dark'):
                page.emulate_media(color_scheme=theme)
                for width in (720, 1365):
                    page.set_viewport_size({'width': width, 'height': 900})
                    response = page.goto(BASE + '/', wait_until='networkidle')
                    check(response.status == 200, f'{locale}/{theme}/{width} Today renders')
                    check(page.locator('html').get_attribute('lang') == locale, f'{locale} language applied')
                    groups = {key: page.locator(f'[data-today-category="{key}"]') for key in ('exams', 'todos', 'tasks', 'notes')}
                    for key, group in groups.items():
                        expect(group.locator('h4')).to_be_visible()
                        check(group.locator('h4').get_attribute('id') == f'today-{key}-title', f'{key} has a visible accessible heading')
                    for title, expected in categories.items():
                        group = {'exam': 'exams', 'todo': 'todos', 'task': 'tasks'}[expected]
                        matches = {key: rows.get_by_text(title, exact=True).count() for key, rows in groups.items()}
                        check(matches[group] == 1 and sum(matches.values()) == 1, f'{title} appears only in {group}')
                    check(groups['notes'].get_by_text('Panel idea', exact=True).count() == 1
                          and sum(g.get_by_text('Panel idea', exact=True).count() for g in groups.values()) == 1,
                          'Panel note appears only in Notes')
                    check(groups['notes'].locator('form').count() == 0, 'A note has no Done task action')
                    check(all(groups[key].locator('form button').count() > 0 for key in ('exams', 'todos', 'tasks')),
                          'Each task group keeps its Done action')
                    check(page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'),
                          f'{locale}/{theme}/{width} has no horizontal overflow')
                    page.locator('.today-categories').screenshot(path=str(OUT / f'today-{locale}-{theme}-{width}.png'))

        db.execute("UPDATE settings SET value='en' WHERE key='locale'")
        db.commit()
        page.goto(BASE + '/', wait_until='networkidle')
        page.locator('[data-today-category="todos"]').get_by_role('button', name='Done').first.click()
        expect(page.locator('[data-today-category="todos"]').get_by_text('flxware tech web update', exact=True)).to_have_count(0)
        check(db.execute("SELECT status FROM tasks WHERE title='flxware tech web update'").fetchone()[0] == 'done',
              'To-do Done action completes only its task')
        check(page.locator('[data-today-category="todos"]').get_by_text('flxware tech web update', exact=True).count() == 0,
              'Completed to-do leaves Today')
        page.locator('[data-today-category="exams"]').get_by_role('button', name='Done').first.click()
        expect(page.locator('[data-today-category="exams"]').get_by_text('ML EXAM', exact=True)).to_have_count(0)
        check(db.execute("SELECT status FROM tasks WHERE title='ML EXAM'").fetchone()[0] == 'done',
              'Exam task Done action completes only its task')
        check(page.locator('[data-today-category="notes"]').get_by_text('Panel idea', exact=True).count() == 1,
              'Completing tasks leaves notes visible')

        page.goto(BASE + '/planner?view=tasks', wait_until='networkidle')
        new_task = page.locator('#new-task form')
        new_task.locator('[name=title]').fill('New practice exam')
        new_task.locator('[name=category]').select_option('exam')
        with page.expect_navigation(wait_until='domcontentloaded'):
            new_task.get_by_role('button', name='Add task').click()
        page.goto(BASE + '/', wait_until='networkidle')
        check(page.locator('[data-today-category="exams"]').get_by_text('New practice exam', exact=True).count() == 1,
              'Planner category selection places a new task in Exams')
        check(not errors, f'No browser script errors: {errors}')
        browser.close()

    db.close()
    (OUT / 'results.json').write_text(json.dumps({'checks': checks, 'errors': errors, 'fixture': str(FIXTURE)}, indent=2), encoding='utf-8')
    print(f'{len(checks)} Today category UI checks passed. Evidence: {OUT}')
finally:
    server.terminate()
    server.wait(timeout=10)
    log.close()
