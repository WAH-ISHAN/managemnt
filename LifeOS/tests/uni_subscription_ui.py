"""Disposable Chromium QA for the real University, Study and Employee screens."""
from pathlib import Path
import os, shutil, socket, sqlite3, subprocess, tempfile, time, urllib.request
from playwright.sync_api import sync_playwright, expect

root = Path(__file__).resolve().parents[1]
checks = []
errors = []
with tempfile.TemporaryDirectory(prefix='lifeos-subscription-ui-') as temporary:
    data = Path(temporary)
    project = data / 'project'
    output = root / 'tests' / '.tmp' / 'uni-subscription-ui'
    output.mkdir(parents=True, exist_ok=True)
    for folder in ('app', 'public'):
        shutil.copytree(root / folder, project / folder)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    with (data / 'server.log').open('w') as log:
        server = subprocess.Popen([os.environ.get('LIFEOS_QA_PHP', 'php'), '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'],
                                  cwd=project, env=dict(os.environ, LIFEOS_DATA_DIR=temporary,
                                                        LIFEOS_INSTANCE='uni-subscription-ui-qa'),
                                  stdout=log, stderr=log, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        try:
            for _ in range(100):
                try:
                    if urllib.request.urlopen(base + '/health', timeout=2).status == 200:
                        break
                except OSError:
                    time.sleep(.1)
            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(headless=True)
                context = browser.new_context(viewport={'width': 1365, 'height': 900}, reduced_motion='reduce')
                page = context.new_page()
                page.on('pageerror', lambda error: errors.append(str(error)))
                page.goto(base + '/welcome')
                page.locator('input[name="roles[]"][value=uni]').check()
                page.locator('[data-wizard-next]').click()
                page.locator('input[name=name]').fill('Calendar UI QA')
                page.locator('input[name=institution]').fill('Disposable university')
                page.locator('[data-wizard-next]').click()
                page.locator('[data-wizard-submit]').click()
                page.wait_for_url(base + '/')
                checks.append('Onboarding through browser form')
                page.goto(base + '/study')
                expect(page.locator('.workspace-navigation__group')).to_have_count(5)
                expect(page.locator('.workspace-navigation a[aria-current=page]')).to_have_count(1)
                page.locator('.workspace-navigation__group').nth(2).locator('summary').click()
                expect(page.locator('.workspace-navigation__group').nth(2).locator('a').first).to_be_visible()
                page.screenshot(path=str(output / 'study-navigation.png'), full_page=True)
                checks.append('Study groups and keyboard accessible disclosure')
                page.goto(base + '/uni/calendar/subscription')
                expect(page.get_by_role('heading', name='University calendar')).to_be_visible()
                expect(page.locator('input[name=calendar_url]')).to_be_visible()
                page.screenshot(path=str(output / 'calendar-empty.png'), full_page=True)
                checks.append('Calendar connection form and empty state')
                db = sqlite3.connect(data / 'lifeos.db')
                appointment = db.execute("INSERT INTO planner_appointments(title,starts_at,ends_at,location,notes,status,all_day,kind) VALUES (?,?,?,?,?,'scheduled',0,'event')",
                                         ('Algorithms', '2026-11-12 15:30', '2026-11-12 17:30', 'Hall A', 'Lecturer: QA tutor')).lastrowid
                db.execute("INSERT INTO uni_ics_events(uid,appointment_id,details_hash,last_seen_at,source_status) VALUES (?,?,?,?,?)",
                           ('qa-lecture', appointment, 'qa-hash', 'qa-marker', 'scheduled'))
                db.commit(); db.close()
                for mode in ('month', 'week', 'day', 'agenda'):
                    page.goto(base + f'/uni/calendar/subscription?view={mode}&date=2026-11-12')
                    expect(page.locator('.uni-calendar-grid a, .lecture-agenda a').filter(has_text='Algorithms').first).to_be_visible()
                    expect(page.locator('.uni-calendar-modes a[aria-current=page]')).to_have_count(1)
                    page.screenshot(path=str(output / f'calendar-{mode}.png'), full_page=True)
                    checks.append(f'{mode} view shows cached lecture')
                page.locator('a[href*="event="]').first.click()
                expect(page.locator('#lecture-detail')).to_contain_text('QA tutor')
                checks.append('Lecture detail link opens real record')
                page.set_viewport_size({'width': 390, 'height': 844})
                page.goto(base + '/uni/calendar/subscription?view=month&date=2026-11-12')
                page.screenshot(path=str(output / 'calendar-mobile.png'), full_page=True)
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
                checks.append('Mobile calendar has no horizontal overflow')
                page.goto(base + '/insights#alerts')
                switch = page.locator('#alerts input[name=enabled]')
                switch.check()
                page.locator('#alerts form[action="/insights/notifications"] button').click()
                page.reload()
                expect(page.locator('#alerts input[name=enabled]')).to_be_checked()
                checks.append('Windows notification switch persists after reload')
                assert not errors, errors
                checks.append('No browser JavaScript exceptions')
                browser.close()
            print(f'{len(checks)} subscribed calendar browser checks passed. Screenshots: {output}')
        finally:
            server.terminate()
            server.wait(timeout=15)
