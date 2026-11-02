"""Headless Chromium acceptance and screenshots for the persona update.

Requires an already-installed Python Playwright package and Chromium browser.
Uses a disposable copied app/database; screenshots remain in tests/.tmp/persona-ui.
"""
import argparse
import json
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
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', type=Path, default=ROOT / 'tests' / '.tmp' / 'persona-ui')
args = parser.parse_args()
output = args.output.resolve()
output.mkdir(parents=True, exist_ok=True)
checks = []
errors = []
metrics = {}


def check(condition, label):
    if not condition:
        raise AssertionError(label)
    checks.append(label)


with tempfile.TemporaryDirectory(prefix='lifeos-persona-ui-') as temporary:
    sandbox = Path(temporary)
    project = sandbox / 'project'
    data = sandbox / 'data'
    for folder in ['app', 'public']:
        shutil.copytree(ROOT / folder, project / folder)
    data.mkdir()
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    php = os.environ.get('LIFEOS_QA_PHP', 'php')
    with (sandbox / 'server.log').open('w', encoding='utf-8') as log:
        server = subprocess.Popen(
            [php, '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'],
            cwd=project, env=dict(os.environ, LIFEOS_DATA_DIR=str(data),
                                 LIFEOS_INSTANCE='persona-ui-qa', LIFEOS_AUTOMATION_KEY='a' * 64),
            stdout=log, stderr=log, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        try:
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            ready = False
            for _ in range(100):
                if server.poll() is not None:
                    break
                try:
                    with opener.open(base + '/health', timeout=2) as response:
                        ready = response.status == 200
                        if ready:
                            break
                except OSError:
                    pass
                time.sleep(.1)
            check(ready, 'Disposable PHP server starts')
            with sync_playwright() as playwright:
                browser = playwright.chromium.launch(headless=True)
                context = browser.new_context(viewport={'width': 1365, 'height': 900},
                                              reduced_motion='reduce')
                page = context.new_page()
                page.on('pageerror', lambda error: errors.append(str(error)))

                def snapshot(name, width=None):
                    if width:
                        page.set_viewport_size({'width': width, 'height': 900})
                    page.evaluate('window.scrollTo(0, 0)')
                    page.screenshot(path=str(output / f'{name}.png'), full_page=True)
                    result = page.evaluate('''() => ({
                        width: innerWidth, document: document.documentElement.scrollWidth,
                        body: document.body.scrollWidth, language: document.documentElement.lang
                    })''')
                    metrics[name] = result
                    check(result['document'] <= result['width'] + 1,
                          f'{name}: content fits viewport without horizontal overflow')

                page.goto(base + '/welcome')
                expect(page.locator('[data-wizard-step]')).to_have_count(3)
                expect(page.locator('[data-wizard-step]').nth(0)).to_be_visible()
                expect(page.locator('[data-wizard-step]').nth(1)).to_be_hidden()
                expect(page.locator('[data-wizard-step]').nth(2)).to_be_hidden()
                check(page.locator('[data-wizard-submit]').is_hidden(), 'Only next control appears on first wizard step')
                snapshot('onboarding-desktop', 1365)
                snapshot('onboarding-narrow', 390)
                page.locator('[data-wizard-next]').click()
                expect(page.locator('[data-wizard-step]').nth(0)).to_be_visible()
                check(True, 'Next refuses a missing persona selection')
                page.locator('input[name=persona][value=employee]').check()
                page.locator('[data-wizard-next]').click()
                expect(page.locator('input[name=name]')).to_be_visible()
                page.locator('[data-wizard-next]').click()
                expect(page.locator('input[name=name]')).to_be_visible()
                check(True, 'Next refuses a missing profile name')
                page.locator('input[name=name]').fill('Headless persona QA')
                page.locator('input[name=institution]').fill('Disposable QA workspace')
                page.locator('select[name=template]').select_option('software_engineer')
                page.locator('[data-wizard-next]').click()
                expect(page.locator('select[name=locale]')).to_be_visible()
                expect(page.locator('[data-wizard-submit]')).to_be_visible()
                snapshot('onboarding-preferences-narrow')
                # Click the real submit button so browser native validation must
                # accept already-filled required controls in the hidden steps.
                page.locator('[data-wizard-submit]').click()
                page.wait_for_url(base + '/')
                expect(page.locator('.sidebar')).to_be_attached()
                check(True, 'Three-step wizard submits through native browser validation')
                check(page.locator('a[href="/uni"],a[href^="/uni/"]').count() == 0,
                      'Employee workspace hides University navigation and quick actions')
                snapshot('employee-dashboard-narrow')
                page.set_viewport_size({'width': 1365, 'height': 900})
                page.goto(base + '/settings/workspace')
                language = page.locator('form[action="/settings/workspace/language"]')
                language.locator('select[name=locale]').select_option('si')
                language.locator('button').click()
                expect(page.locator('html')).to_have_attribute('lang', 'si')
                expect(page.locator('h1')).to_have_text('ඔබේ වැඩ අවකාශය')
                check(True, 'Sinhala preference reloads localized workspace controls')
                modules = page.locator('form[action="/settings/workspace/modules"]')
                university = modules.locator('input[name="enabled[]"][value=Uni]')
                expect(university).not_to_be_checked()
                university.check()
                modules.locator('input[name="order[Uni]"]').fill('0')
                modules.locator('button').click()
                expect(page.locator('.sidebar a[href="/uni"]')).to_be_attached()
                expect(page.locator('form[action="/settings/workspace/modules"] input[value=Uni]')).to_be_checked()
                check(True, 'Sinhala module controls enable University and refresh the sidebar')
                snapshot('workspace-sinhala-desktop', 1365)
                snapshot('workspace-sinhala-narrow', 390)
                page.set_viewport_size({'width': 1365, 'height': 900})
                page.goto(base + '/automation')
                builder = page.locator('form[action="/automation/rules"]')
                expect(builder).to_be_visible()
                builder.locator('input[name=name]').fill('Headless QA routine')
                builder.locator('select[name=trigger]').select_option('daily')
                builder.locator('input[name=time]').fill('09:00')
                builder.locator('select[name="actions[0][type]"]').select_option('notify')
                builder.locator('input[name="actions[0][title]"]').fill('QA reminder')
                builder.locator('textarea[name="actions[0][body]"]').fill('Disposable UI smoke check')
                builder.get_by_text('Additional actions (optional)', exact=True).click()
                builder.locator('select[name="actions[1][type]"]').select_option('evening_review')
                builder.get_by_text('Additional actions (optional)', exact=True).click()
                check(not builder.locator('input[name=enabled]').is_checked(),
                      'New custom routine remains disabled until explicitly enabled')
                snapshot('automation-builder-desktop')
                builder.locator('button').click()
                expect(page.locator('article.task-row').filter(has_text='Headless QA routine')).to_be_visible()
                expect(page.locator('article.task-row').filter(has_text='Headless QA routine')).to_contain_text('Paused')
                check(True, 'Trigger-condition-action builder saves a paused custom routine')
                snapshot('automation-saved-narrow', 390)
                page.locator('article.task-row').filter(has_text='Headless QA routine').get_by_text('Edit', exact=True).click()
                expect(page.locator('select[name="actions[0][type]"]')).to_have_value('notify')
                expect(page.locator('input[name="actions[0][title]"]')).to_have_value('QA reminder')
                expect(page.locator('select[name="actions[1][type]"]')).to_have_value('evening_review')
                check(True, 'Saved custom and additional actions round-trip through the editor')
                page.goto(base + '/salary/expense-rules')
                rule_form = page.locator('form[action="/salary/expense-rules"]')
                rule_form.locator('input[name=keyword]').fill('bus fare')
                rule_form.locator('select[name=category]').select_option(label='Transport')
                rule_form.locator('input[name=priority]').fill('10')
                rule_form.get_by_role('button', name='Add rule').click()
                expect(page.locator('table')).to_contain_text('bus fare')
                preview_form = page.locator('form[action="/salary/expense-rules/preview"]')
                preview_form.locator('textarea[name=description]').fill('Paid BUS fare today')
                preview_form.get_by_role('button', name='Find category').click()
                expect(page.get_by_role('status')).to_contain_text('Matched the rule')
                expect(page.locator('form[action="/expenses"] select[name=category] option:checked')).to_have_text('Transport')
                check(True, 'Expense keyword rule previews a reviewable category in the browser')
                snapshot('expense-rules-desktop')
                snapshot('expense-rules-narrow', 390)
                page.goto(base + '/salary/alert-import')
                page.locator('form[action="/salary/alert-import/preview"] textarea[name=alert]').fill(
                    'Your card was debited LKR 1,250.00 on 02/11/2026 at Market. Available balance LKR 9,999.00')
                page.get_by_role('button', name='Preview alert').click()
                expect(page.locator('form[action="/salary/alert-import/post"] input[name=amount]')).to_have_value('1250.00')
                expect(page.locator('form[action="/salary/alert-import/post"] input[name=date]')).to_have_value('2026-11-02')
                check(True, 'Pasted debit alert displays an editable review form without posting')
                snapshot('alert-import-narrow', 390)
                check(not errors, 'No uncaught browser JavaScript errors')
                application_errors = data / 'php_error.log'
                check(not application_errors.exists() or not application_errors.read_text(encoding='utf-8').strip(),
                      'UI flows produce no PHP application errors')
                context.close()
                browser.close()
            print(f'{len(checks)} persona browser UI checks passed. Screenshots: {output}')
        finally:
            (output / 'results.json').write_text(json.dumps(
                {'checks': checks, 'browserErrors': errors, 'viewports': metrics}, ensure_ascii=False, indent=2),
                encoding='utf-8')
            server.terminate()
            try:
                server.wait(timeout=15)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
