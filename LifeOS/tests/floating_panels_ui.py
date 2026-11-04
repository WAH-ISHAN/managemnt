"""Panel geometry and independent scrolling through real pages and local jobs.

Uses disposable records and an inert native bridge; no online model requests.
"""
import json
import os
from pathlib import Path
import socket
import sqlite3
import subprocess
import time
import urllib.request

from PIL import Image
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'tests/.tmp/floating-panels-ui'
DATA = OUT / str(time.time_ns())
DATA.mkdir(parents=True)
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
base = f'http://127.0.0.1:{port}'
checks, errors = [], []


def check(value, label):
    assert value, label
    checks.append(label)


GEOMETRY = """({shell, content, composer}) => {
 const box = e => { const r=e.getBoundingClientRect(); return {x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:r.width,height:r.height}; };
 const outer=document.querySelector(shell), header=outer.querySelector('header'), scroller=document.querySelector(content);
 const h=box(header), s=box(outer), c=box(scroller);
 const inside=(r,b)=>r.x>=b.x-1&&r.right<=b.right+1&&r.y>=b.y-1&&r.bottom<=b.bottom+1;
 const buttons=[...header.querySelectorAll('button')].map(box);
 const identity=header.querySelector('.assistant-identity,.panel-brand');
 const identityBox=box(identity), actions=box(header.querySelector('.assistant-window-actions,nav'));
 return {
  headerInside:inside(h,s), controlsInside:buttons.every(r=>inside(r,h)&&r.x>=s.x+8&&r.right<=s.right-8),
  controlsClear:identityBox.right<=actions.x+1,
  contentInside:inside(c,s)&&c.y>=h.bottom-1,
  noOverflow:document.documentElement.scrollWidth<=innerWidth+1&&outer.scrollWidth<=outer.clientWidth+1&&scroller.scrollWidth<=scroller.clientWidth+1,
  insetScrollbar:c.x>=s.x+6&&c.right<=s.right-6,
  shellClipped:getComputedStyle(outer).overflow==='hidden',
  composerInside:!composer||inside(box(document.querySelector(composer)),s),
  composerBelow:!composer||c.bottom<=box(document.querySelector(composer)).y+1,
  header:h, composer:composer?box(document.querySelector(composer)):null
 };
}"""

# Composite translucent control surfaces over their actual ancestor surfaces.
# This catches white icons on white fills as well as excessive disabled opacity.
BUTTONS = """() => {
 const rgb=s=>(s.match(/[\\d.]+/g)||[]).map(Number);
 const over=(a,b)=>a.slice(0,3).map((v,i)=>v*(a[3]??1)+b[i]*(1-(a[3]??1)));
 const background=e=>{let c=[255,255,255];for(const n of [...ancestors(e)].reverse())c=over(rgb(getComputedStyle(n).backgroundColor),c);return c;};
 function* ancestors(e){for(;e;e=e.parentElement)yield e;}
 const luminance=c=>c.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}).reduce((v,n,i)=>v+n*[.2126,.7152,.0722][i],0);
 const contrast=(a,b)=>{const x=luminance(a),y=luminance(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);};
 return [...document.querySelectorAll('header button,.assistant-send,.assistant-action button,.assistant-suggestions button,.add-button,.section-add,.note>.icon-button,.refresh-button,.form-actions button')]
 .filter(e=>e.getClientRects().length).map(e=>{
   const style=getComputedStyle(e),bg=background(e),fg=rgb(style.color),icon=e.querySelector('svg');
   fg[3]=(fg[3]??1)*Number(style.opacity);
   const r=e.getBoundingClientRect(),i=icon?.getBoundingClientRect();
   return {name:e.getAttribute('aria-label')||e.innerText||e.className,contrast:contrast(over(fg,bg),bg),
     aligned:!icon||Math.abs((r.x+r.width/2)-(i.x+i.width/2))<1&&Math.abs((r.y+r.height/2)-(i.y+i.height/2))<1,
     iconVisible:!icon||getComputedStyle(icon).color===style.color};
 });
}"""


def corners_clipped(capture):
    if capture.mode != 'RGBA':
        return False
    width, height = capture.size
    radius = 26
    # Allow only the antialiased edge of the curve. Check every outside pixel
    # in all four corners, rather than just the top-left pixel.
    return all(capture.getpixel((x if left else width-1-x, y if top else height-1-y))[3] == 0
               for left in [True, False] for top in [True, False]
               for x in range(radius) for y in range(radius)
               if (radius-x-.5)**2 + (radius-y-.5)**2 > (radius+1)**2)

log = (OUT / 'server.log').open('w', encoding='utf-8')
server = subprocess.Popen(
    [os.environ.get('LIFEOS_QA_PHP', 'php'), '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/index.php'],
    cwd=ROOT, env=dict(os.environ, LIFEOS_DATA_DIR=str(DATA)), stdout=log, stderr=log,
    creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0),
)
try:
    for _ in range(100):
        try:
            urllib.request.build_opener(urllib.request.ProxyHandler({})).open(base + '/health', timeout=2).close()
            break
        except OSError:
            time.sleep(.1)
    db = sqlite3.connect(DATA / 'lifeos.db')
    db.executemany('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',
                   [('name', 'Panel test'), ('onboarding_complete', '1')])
    for index in range(6):
        db.execute('INSERT INTO desktop_panel_notes(title,body) VALUES (?,?)',
                   ('Panel note ' + str(index), 'Long synthetic text ' * 50))
    db.commit()
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        page = browser.new_page(viewport={'width':450, 'height':720}, reduced_motion='reduce')
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.add_init_script("""window.chrome=window.chrome||{};window.__bridge=[];
          window.chrome.webview={postMessage:m=>window.__bridge.push(m),addEventListener:()=>{},removeEventListener:()=>{}};""")
        page.goto(base + '/assistant')
        expect(page.locator('[data-welcome]')).to_be_visible()
        page.locator('[name=message]').fill('Panel layout fixture')
        page.locator('[name=message]').press('Enter')
        expect(page.locator('.chat-pending')).to_be_visible()
        path = next((DATA / 'ai/jobs').glob('*.json'))
        job = json.loads(path.read_text(encoding='utf-8'))
        answer = ('A long answer with **emphasis** and a link-like string ' + 'x' * 300 + '\n\n') * 35
        answer += '\n```\n' + 'long_code_token_' * 60 + '\n```\n'
        job.update(status='complete', answer=json.dumps({'answer':answer, 'actions':[
            {'action':'add_task', 'title':'Review a synthetic proposed task', 'due':'2099-01-05'}]}))
        path.write_text(json.dumps(job), encoding='utf-8')
        expect(page.locator('[data-action-form]')).to_be_visible()

        for locale in ['en', 'si', 'ta']:
            db.execute("INSERT INTO settings(key,value) VALUES ('locale',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", [locale])
            db.commit()
            for theme in ['light', 'dark']:
                page.emulate_media(color_scheme=theme)
                for route, shell, content, composer, sizes in [
                    ('/assistant', '.assistant-shell', '.assistant-conversation', '.assistant-compose-area', [(320,360),(360,460),(450,720),(760,900)]),
                    ('/desktop/panel', '.panel-shell', '.panel-content', None, [(320,360),(440,760),(700,900)]),
                ]:
                    page.goto(base + route)
                    expect(page.locator(content)).to_be_visible()
                    if route == '/desktop/panel':
                        expect(page.locator('[data-notes] .note')).to_have_count(6)
                    else:
                        expect(page.locator('[data-action-form]')).to_be_visible()
                    for width, height in sizes:
                        page.set_viewport_size({'width':width, 'height':height})
                        shape = dict(shell=shell, content=content, composer=composer)
                        before = page.evaluate(GEOMETRY, shape)
                        for key in ['headerInside','controlsInside','controlsClear','contentInside','noOverflow','insetScrollbar','shellClipped','composerInside','composerBelow']:
                            check(before[key], f'{route} {locale}/{theme} {width}x{height}: {key}')
                        page.locator(content).evaluate('e=>e.scrollTop=e.scrollHeight')
                        after = page.evaluate(GEOMETRY, shape)
                        check(before['header']==after['header'] and before['composer']==after['composer'],
                              f'{route} scrolling leaves header/input fixed at {width}x{height}')
                        check(page.locator(content).evaluate('e=>e.scrollHeight>e.clientHeight&&e.scrollTop>0'),
                              f'{route} content scrolls independently at {width}x{height}')
                        image = OUT / f'{route.rsplit("/",1)[-1]}-{locale}-{theme}-{width}.png'
                        page.screenshot(path=str(image), omit_background=True)
                        with Image.open(image) as capture:
                            check(corners_clipped(capture),
                                  f'{route} all four corners are clipped at {width}x{height}')

        for theme in ['light', 'dark']:
            # An explicit theme must work even when Windows uses the opposite one.
            page.emulate_media(color_scheme='dark' if theme=='light' else 'light')
            for route in ['/assistant', '/desktop/panel']:
                page.set_viewport_size({'width':450, 'height':720})
                page.goto(base + route)
                expect(page.locator('[data-action-form]' if route=='/assistant' else '[data-notes] .note').first).to_be_visible()
                page.evaluate('(theme)=>document.documentElement.dataset.theme=theme', theme)
                if route=='/desktop/panel':
                    page.locator('[data-add-toggle]').click()
                    expect(page.locator('[data-add-form]')).to_be_visible()
                    page.locator('[name=kind]').select_option('todo')
                    todo='Visible panel to-do '+theme
                    page.locator('[name=title]').fill(todo)
                    page.locator('[data-add-form] [type=submit]').click()
                    expect(page.locator('[data-todos]')).to_contain_text(todo)
                    check(True,f'{theme} panel add immediately renders the saved to-do')
                    page.locator('[data-preferences-toggle]').click()
                    size=page.locator('[data-panel-width]')
                    size.fill('500');size.focus();box=size.bounding_box();page.mouse.move(box['x']+box['width']/2,box['y']+box['height']/2);page.mouse.wheel(0,-120)
                    check(size.input_value()=='500',f'{theme} panel scrolling does not alter numeric values')
                    check(page.locator('.resize-grip').evaluate("""e=>{
                      const s=e.closest('.panel-shell'),r=s.getBoundingClientRect(),b=e.getBoundingClientRect(),radius=parseFloat(getComputedStyle(s).borderBottomRightRadius);
                      return Math.hypot(b.right-(r.right-radius),b.bottom-(r.bottom-radius))<radius;
                    }"""), f'{theme} resize control is inside the bottom corner')
                for button in page.evaluate(BUTTONS):
                    check(button['contrast']>=3, f"{route} {theme} {button['name']}: visible icon/text ({button['contrast']:.2f})")
                    check(button['aligned'] and button['iconVisible'], f"{route} {theme} {button['name']}: centered icon inherits its color")
                if route=='/assistant':
                    page.locator('[name=message]').fill('Check send button visibility')
                    expect(page.locator('.assistant-send')).to_be_enabled()
                    button=next(b for b in page.evaluate(BUTTONS) if b['name']==page.locator('.assistant-send').get_attribute('aria-label'))
                    check(button['contrast']>=3 and button['aligned'], f'{theme} enabled send icon is visible and centered')
                    page.locator('[name=message]').fill('A longer draft\n' * 20)
                    shape=dict(shell='.assistant-shell',content='.assistant-conversation',composer='.assistant-compose-area')
                    geometry=page.evaluate(GEOMETRY,shape)
                    check(geometry['composerBelow'] and geometry['composerInside'] and geometry['noOverflow'], f'{theme} multiline draft stays inside the composer')
                    page.locator('[name=message]').fill('Check send button visibility')
                page.screenshot(path=str(OUT/f'{route.rsplit("/",1)[-1]}-controls-{theme}.png'), omit_background=True)

        db.execute("UPDATE settings SET value='en' WHERE key='locale'")
        db.commit()
        page.goto(base + '/')
        for width, height in [(720,600),(1024,768),(1365,900)]:
            page.set_viewport_size({'width':width,'height':height})
            check(page.locator('#desktop-titlebar').evaluate("""e=>{
              const r=e.getBoundingClientRect(),brand=e.querySelector('.window-chrome-brand').getBoundingClientRect();
              const controls=e.querySelector('.window-controls').getBoundingClientRect();
              return r.x===0&&Math.abs(r.width-innerWidth)<1&&controls.right<=brand.x&&
                [...e.querySelectorAll('button')].every(b=>{const q=b.getBoundingClientRect();return q.y>=0&&q.bottom<=r.bottom&&q.x>=8&&q.right<=r.right-8});
            }"""), f'Main titlebar fits and controls do not collide at {width}x{height}')
            page.screenshot(path=str(OUT / f'main-titlebar-{width}.png'))
        for action in ['minimize','maximize','close']:
            page.locator(f'[data-window-action={action}]').click()
            check(page.evaluate('(action)=>window.__bridge.some(m=>m.type==="lifeos-window"&&m.action===action)', action),
                  action + ' still dispatches the native action')
        check(not errors, f'No browser errors: {errors}')
        browser.close()
    db.close()
    (OUT / 'results.json').write_text(json.dumps({'checks':checks,'errors':errors}, indent=2), encoding='utf-8')
    print(f'{len(checks)} floating-panel layout checks passed. Evidence: {OUT}')
finally:
    server.terminate()
    server.wait(timeout=10)
    log.close()
