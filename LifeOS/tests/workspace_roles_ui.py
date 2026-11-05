"""First-start role combinations and role changes with disposable local data."""
import itertools
import json
import os
from pathlib import Path
import socket
import sqlite3
import subprocess
import time
import urllib.request
from playwright.sync_api import sync_playwright, expect

ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'tests/.tmp/workspace-roles-ui'
DATA=OUT/str(time.time_ns());DATA.mkdir(parents=True)
with socket.socket()as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
base=f'http://127.0.0.1:{port}';checks=[];errors=[]
def check(value,label):
    assert value,label
    checks.append(label)
log=(OUT/'server.log').open('w',encoding='utf-8')
server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/index.php'],cwd=ROOT,
    env=dict(os.environ,LIFEOS_DATA_DIR=str(DATA)),stdout=log,stderr=log,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
try:
    for _ in range(100):
        try:urllib.request.build_opener(urllib.request.ProxyHandler({})).open(base+'/health',timeout=2).close();break
        except OSError:time.sleep(.1)
    db=sqlite3.connect(DATA/'lifeos.db')
    with sync_playwright()as pw:
        browser=pw.chromium.launch(headless=True)
        page=browser.new_page(viewport={'width':1365,'height':900},reduced_motion='reduce')
        page.on('pageerror',lambda error:errors.append(str(error)))
        roles=['uni','employee']
        for length in range(1,4):
            for selection in itertools.combinations(roles,length):
                db.execute('DELETE FROM local_profiles')
                db.execute("INSERT INTO settings(key,value)VALUES('onboarding_complete','0')ON CONFLICT(key)DO UPDATE SET value='0'")
                db.execute("UPDATE settings SET value='' WHERE key='name'")
                db.commit()
                page.goto(base+'/')
                expect(page).to_have_url(base+'/welcome')
                page.locator('[data-wizard-next]').click()
                expect(page.locator('[data-role-choices]')).to_be_visible()
                for role in selection:page.locator(f'input[name="roles[]"][value={role}]').check()
                expect(page.locator('[data-role-preview]')).to_be_visible()
                check(page.locator('[data-role-feature-list] li').count()>=3,'Task preview appears for '+'+'.join(selection))
                if length==2:
                    page.set_viewport_size({'width':390,'height':900})
                    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'First-start combined roles fit a narrow viewport')
                    page.screenshot(path=str(OUT/('-'.join(selection)+'-onboarding.png')),full_page=True)
                    page.set_viewport_size({'width':1365,'height':900})
                page.locator('[data-wizard-next]').click()
                page.locator('input[name=name]').fill('Combined roles QA')
                if 'uni'in selection:page.locator('[name=template]').select_option('hnd')
                page.locator('[data-wizard-next]').click()
                page.locator('[data-wizard-submit]').click()
                expect(page).to_have_url(base+'/')
                expect(page.locator('.sidebar')).to_be_attached()
                saved={r[0]for r in db.execute('SELECT role FROM profile_roles')}
                check(saved==set(selection),'First-start stores '+'+'.join(selection))
                for role,route,module in [('uni','/uni','Uni'),('employee','/employee','Employee')]:
                    shown=page.locator(f'.sidebar a[href="{route}"]').count()>0
                    check(shown==(role in selection),module+' sidebar relevance for '+'+'.join(selection))
                page.goto(base+'/settings')
                check(page.locator('form[action="/settings/grades"]').count()==int('uni'in selection),'Grade settings match roles '+'+'.join(selection))
                check(page.locator('form[action="/settings/rules"]').count()==1,'Money settings match roles '+'+'.join(selection))
                expect(page.locator('form[action="/settings/pin"]')).to_be_visible()
                expect(page.locator('#updates')).to_be_visible()
                page.goto(base+'/settings/workspace/packs')
                packs=set(page.locator('input[name=pack]').evaluate_all('items=>items.map(input=>input.value)'))
                expected_packs={pack for role,pack in [('uni','hnd'),('employee','software_engineer')]if role in selection}
                check(packs==expected_packs,'Only starter plans for selected roles are offered: '+'+'.join(selection))
                page.goto(base+'/settings/workspace')
                for role,module in [('uni','Uni'),('employee','Employee')]:
                    check(page.locator(f'input[name="enabled[]"][value={module}]').count()==int(role in selection),'Only eligible tool switches shown for '+'+'.join(selection))
                for width in [1365,390]:
                    page.set_viewport_size({'width':width,'height':900})
                    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'Role settings fit '+str(width))
                if length==2:page.screenshot(path=str(OUT/('-'.join(selection)+'-settings.png')),full_page=True)
                page.set_viewport_size({'width':1365,'height':900})

        # Persisted records survive removing/re-adding a role and direct URLs
        # remain gated; neither hidden navigation nor module payloads bypass it.
        db.execute("INSERT INTO uni_modules(code,name,credits,semester)VALUES('QA','Preserved course',3,'1')")
        db.commit()
        role_form=page.locator('form[data-role-picker]')
        role_form.locator('input[value=uni]').uncheck()
        role_form.locator('[name=template]').select_option('')
        role_form.locator('button').click()
        check(page.goto(base+'/uni').status==403,'Removed University role blocks direct URL')
        check(db.execute('SELECT name FROM uni_modules').fetchone()[0]=='Preserved course','Removing a role keeps personal course records')
        page.goto(base+'/settings/workspace')
        role_form=page.locator('form[data-role-picker]')
        role_form.locator('input[value=uni]').check();role_form.locator('button').click()
        check(page.goto(base+'/uni').status==200,'Re-adding University restores its saved page')

        for locale in ['si','ta']:
            page.goto(base+'/settings/workspace')
            language=page.locator('form[action="/settings/workspace/language"]')
            language.locator('select').select_option(locale);language.locator('button').click()
            expect(page.locator('html')).to_have_attribute('lang',locale)
            page.set_viewport_size({'width':390,'height':900})
            page.screenshot(path=str(OUT/('roles-'+locale+'.png')),full_page=True)
            check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'Localized role controls fit '+locale)
        check(not errors,f'No browser errors: {errors}')
        browser.close()
    db.close()
    (OUT/'results.json').write_text(json.dumps({'checks':checks,'errors':errors},indent=2),encoding='utf-8')
    print(f'{len(checks)} combined-role UI checks passed. Evidence: {OUT}')
finally:
    server.terminate();server.wait(timeout=10);log.close()
