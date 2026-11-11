"""Real browser dashboard QA using an isolated LifeOS copy and database."""
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request

from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'tests' / '.tmp' / 'dashboard-ui'
OUT.mkdir(parents=True, exist_ok=True)

with tempfile.TemporaryDirectory(prefix='lifeos-dashboard-') as tmp:
    target = Path(tmp)
    for folder in ('app', 'public'):
        shutil.copytree(ROOT / folder, target / folder)
    (target / 'data').mkdir()
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    env = dict(os.environ, LIFEOS_DATA_DIR=str(target / 'data'), LIFEOS_INSTANCE='dashboard-ui-qa')
    with (OUT / 'server.log').open('w') as log:
        server = subprocess.Popen([os.environ.get('LIFEOS_QA_PHP', 'php'), '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'], cwd=target, env=env, stdout=log, stderr=log, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        try:
            for _ in range(100):
                try:
                    with urllib.request.build_opener(urllib.request.ProxyHandler({})).open(base + '/health', timeout=1) as reply:
                        if reply.status == 200:
                            break
                except OSError:
                    time.sleep(.1)
            else:
                raise RuntimeError('Disposable LifeOS did not start')
            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(headless=True)
                page = browser.new_page(viewport={'width': 1440, 'height': 900}, reduced_motion='reduce')
                errors = []
                page.on('pageerror', lambda error: errors.append(str(error)))
                page.goto(base + '/welcome')
                page.locator('input[name="roles[]"][value=employee]').check()
                page.locator('[data-wizard-next]').click()
                page.locator('input[name=name]').fill('Dashboard QA')
                page.locator('[data-wizard-next]').click()
                page.locator('[data-wizard-submit]').click()
                page.wait_for_url(base + '/')
                expect(page.locator('.dashboard-trends .chart-empty')).to_have_count(2)
                expect(page.locator('.dashboard-week-metrics dd').nth(0)).to_contain_text('0')
                expect(page.locator('.dashboard-period-comparison').first).to_contain_text('0 tasks')
                page.screenshot(path=str(OUT / 'dashboard-empty-desktop.png'), full_page=True)
                for width in (1440, 1024, 390, 320):
                    page.set_viewport_size({'width': width, 'height': 900})
                    page.wait_for_timeout(100)
                    dimensions = page.evaluate('({viewport: innerWidth, content: document.documentElement.scrollWidth})')
                    assert dimensions['content'] <= width + 1, f'Horizontal overflow at {width}px: {dimensions}'
                    if width == 390:
                        page.screenshot(path=str(OUT / 'dashboard-empty-mobile.png'), full_page=True)
                page.set_viewport_size({'width': 1440, 'height': 900})
                page.locator('.dashboard-action-primary').click()
                page.wait_for_url('**/planner?view=tasks#new-task')
                form = page.locator('#new-task form')
                form.locator('[name=title]').fill('Dashboard QA task')
                form.get_by_role('button', name='Add task').click()
                page.wait_for_timeout(500)
                page.goto(base + '/')
                expect(page.locator('.dashboard-week-metrics dd').nth(2)).to_contain_text('0%')
                expect(page.locator('.dashboard-week-metrics>div').nth(2)).to_contain_text('0 / 1')
                page.locator('.task-row', has_text='Dashboard QA task').first.locator('button').click()
                page.wait_for_timeout(500)
                page.goto(base + '/')
                expect(page.locator('.dashboard-week-metrics dd').nth(0)).to_contain_text('1')
                expect(page.locator('.dashboard-period-comparison').first).to_contain_text('0 tasks')
                expect(page.locator('.dashboard-week-metrics dd').nth(2)).to_contain_text('100%')
                expect(page.locator('.dashboard-trends canvas')).to_have_count(1)
                page.wait_for_function('window.Chart && document.querySelector(".dashboard-trends canvas") && Chart.getChart(document.querySelector(".dashboard-trends canvas"))')
                chart = page.evaluate('Chart.getChart(document.querySelector(".dashboard-trends canvas")).data.datasets[0].data')
                assert sum(chart) == 1, f'Task chart does not match saved completion: {chart}'
                page.screenshot(path=str(OUT / 'dashboard-task-desktop.png'), full_page=True)
                page.locator('.day-links a').first.click()
                expect(page.locator('.dashboard-week-metrics dd').nth(0)).to_contain_text('0')
                expect(page.locator('.dashboard-trends .chart-empty')).to_have_count(2)
                page.locator('.day-links a', has_text='Today').click()
                expect(page.locator('.dashboard-week-metrics dd').nth(0)).to_contain_text('1')
                page.locator('[data-theme-toggle]').click()
                page.locator('[data-theme-toggle]').click()
                expect(page.locator('html')).to_have_attribute('data-theme', 'dark')
                page.screenshot(path=str(OUT / 'dashboard-task-dark.png'), full_page=True)
                page.locator('[data-command-open]').click()
                expect(page.locator('#command-dialog')).to_be_visible()
                page.locator('#command-search').fill('planner')
                expect(page.locator('[data-command-result]:visible').first).to_be_visible()
                page.keyboard.press('Escape')
                page.locator('[data-assistant-open]').first.click()
                page.wait_for_url('**/assistant')
                expect(page.locator('main')).to_be_visible()
                failed = browser.new_context(viewport={'width': 1440, 'height': 900}, reduced_motion='reduce')
                failed.route('**/assets/chart.min.js', lambda route: route.abort())
                offline_page = failed.new_page()
                offline_page.goto(base + '/')
                expect(offline_page.locator('.dashboard-trends .chart-load-error')).to_have_count(1)
                failed.unroute('**/assets/chart.min.js')
                offline_page.locator('.chart-load-error button').click()
                offline_page.wait_for_function('window.Chart && Chart.getChart(document.querySelector(".dashboard-trends canvas"))')
                expect(offline_page.locator('.chart-load-error')).to_have_count(0)
                failed.close()
                assert not errors, f'Browser exceptions: {errors}'
                browser.close()
                print('Dashboard browser QA passed: onboarding, empty charts, 4 breakpoints, task create/complete, persisted metrics and chart, date navigation, dark theme, search, assistant route, chart asset failure/retry, console.')
        finally:
            server.terminate()
            server.wait(timeout=10)
