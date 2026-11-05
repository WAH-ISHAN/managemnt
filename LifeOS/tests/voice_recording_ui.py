"""Real Chromium fake-device capture -> local PCM WAV -> explicit reviewed transcript.

Uses no physical microphone or real transcription model. Success/error transcripts
are explicit HTTP fixtures; real media/model recognition is separate acceptance.
"""
import io,json,os,socket,sqlite3,subprocess,time,urllib.request,wave
from pathlib import Path
from playwright.sync_api import sync_playwright,expect

ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'tests/.tmp'/('voice-ui-'+str(time.time_ns()));OUT.mkdir(parents=True)
DATA=OUT/'data';DATA.mkdir();checks=[];errors=[]
with socket.socket() as probe:probe.bind(('127.0.0.1',0));port=probe.getsockname()[1]
base=f'http://127.0.0.1:{port}'
def check(ok,label):
    if not ok:raise AssertionError(label)
    checks.append(label)
log=(OUT/'server.log').open('w',encoding='utf-8')
server=subprocess.Popen([os.environ.get('LIFEOS_QA_PHP','php'),'-S',f'127.0.0.1:{port}','-t','public','public/index.php'],cwd=ROOT,env=dict(os.environ,LIFEOS_DATA_DIR=str(DATA)),stdout=log,stderr=log,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
try:
    for _ in range(100):
        try:urllib.request.build_opener(urllib.request.ProxyHandler({})).open(base+'/health',timeout=2).close();break
        except OSError:time.sleep(.1)
    db=sqlite3.connect(DATA/'lifeos.db')
    db.executemany('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[('name','Voice QA'),('onboarding_complete','1')]);db.commit()
    with sync_playwright() as pw:
        browser=pw.chromium.launch(headless=True,args=['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream'])
        context=browser.new_context(viewport={'width':1280,'height':900},reduced_motion='reduce')
        context.add_init_script('''window.__voiceStreams=[];const originalGetUserMedia=navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);navigator.mediaDevices.getUserMedia=async options=>{const stream=await originalGetUserMedia(options);window.__voiceStreams.push(stream);return stream;};''')
        page=context.new_page();page.on('pageerror',lambda error:errors.append(str(error)))
        page.goto(base+'/intelligence');page.locator('summary').filter(has_text='Text & local tools').click()
        panel=page.locator('[data-voice-recorder]');start=panel.locator('[data-voice-start]')
        check(panel.is_visible(),'Recording controls are available in local tools')
        check(panel.locator('[data-voice-transcribe]').is_disabled(),'Transcription requires configured local tool/model')
        start.focus();page.keyboard.press('Enter');expect(panel).to_have_attribute('data-state','recording')
        start.evaluate('element=>element.dispatchEvent(new Event("click"))')
        check(page.evaluate('window.__voiceStreams.length===1&&window.__voiceStreams[0].getAudioTracks()[0].readyState==="live"'),'Keyboard action starts one real synthetic microphone stream')
        page.wait_for_timeout(1250);page.screenshot(path=str(OUT/'recording.png'))
        panel.locator('[data-voice-stop]').click();expect(panel).to_have_attribute('data-state','ready',timeout=15000)
        check(page.evaluate('window.__voiceStreams.every(s=>s.getTracks().every(t=>t.readyState==="ended"))'),'Stop releases every microphone track')
        check(panel.locator('[data-voice-playback]').get_attribute('src').startswith('blob:'),'Recording stays in browser memory for local playback')
        check(db.execute('SELECT COUNT(*) FROM intelligence_sources').fetchone()[0]==0 and db.execute('SELECT COUNT(*) FROM tasks').fetchone()[0]==0,'Recording creates no source or task without transcription/review')
        with page.expect_download() as downloaded:panel.locator('[data-voice-download]').click()
        audio_path=OUT/'synthetic-recording.wav';downloaded.value.save_as(audio_path)
        with wave.open(str(audio_path),'rb') as audio:
            check(audio.getnchannels()==1 and audio.getsampwidth()==2 and audio.getframerate()==16000,'Download is valid mono 16 kHz 16-bit PCM WAV')
            check(.2<audio.getnframes()/audio.getframerate()<5,'WAV contains the short captured duration')
            check(any(audio.readframes(audio.getnframes())),'Synthetic WAV has actual decoded audio samples')
        page.screenshot(path=str(OUT/'review.png'))
        page.emulate_media(color_scheme='dark');page.screenshot(path=str(OUT/'review-dark.png'));page.emulate_media(color_scheme='light')
        page.keyboard.press('Escape');expect(panel).to_have_attribute('data-state','idle')
        check(panel.locator('[data-voice-playback]').get_attribute('src') is None,'Escape discards local audio and playback URL')
        start.click();expect(panel).to_have_attribute('data-state','recording');page.wait_for_timeout(400)
        page.evaluate('window.dispatchEvent(new Event("blur"))');expect(panel).to_have_attribute('data-state','ready',timeout=15000)
        check(page.evaluate('window.__voiceStreams.every(s=>s.getTracks().every(t=>t.readyState==="ended"))'),'Switching windows stops recording and releases tracks')
        panel.locator('[data-voice-cancel]').click()
        page.evaluate('''()=>{const current=navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);navigator.mediaDevices.getUserMedia=async options=>{const stream=await current(options);await new Promise(resolve=>window.__releaseMic=resolve);return stream;};}''')
        start.click();expect(panel).to_have_attribute('data-state','requesting');page.wait_for_function('typeof window.__releaseMic==="function"')
        panel.locator('[data-voice-cancel]').click();page.evaluate('window.__releaseMic()')
        page.wait_for_function('window.__voiceStreams.every(s=>s.getTracks().every(t=>t.readyState==="ended"))')
        check(panel.get_attribute('data-state')=='idle','Cancelling a pending permission releases a later granted stream')
        page.evaluate('()=>{navigator.mediaDevices.getUserMedia=()=>Promise.reject(new DOMException("Denied fixture","NotAllowedError"));}')
        start.click();expect(panel).to_have_attribute('data-state','error')
        check('not allowed' in panel.locator('[data-voice-status]').inner_text(),'Denied permission has a useful inline error')
        page.screenshot(path=str(OUT/'permission-error.png'))
        # Existing-file readiness fixtures only: never executed, and no model recognition is claimed.
        tool=DATA/'fixture-voice-tool.exe';model=DATA/'fixture-voice-model.bin'
        tool.write_bytes(b'Explicit UI readiness fixture, not an executable');model.write_bytes(b'Explicit UI readiness fixture, not model weights')
        db.executemany('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',[('intelligence_whisper',str(tool)),('intelligence_whisper_model',str(model))]);db.commit()
        page.reload();page.locator('summary').filter(has_text='Text & local tools').click();panel=page.locator('[data-voice-recorder]');start=panel.locator('[data-voice-start]')
        start.click();expect(panel).to_have_attribute('data-state','recording')
        page.locator('aside a.nav-link[href="/planner"]').click();expect(page.locator('[data-voice-recorder]')).to_have_count(0)
        check(page.evaluate('window.__voiceStreams.every(s=>s.getTracks().every(t=>t.readyState==="ended"))'),'HTMX page navigation releases microphone tracks')
        page.goto(base+'/intelligence');page.locator('summary').filter(has_text='Text & local tools').click();panel=page.locator('[data-voice-recorder]');start=panel.locator('[data-voice-start]')
        start.click();expect(panel).to_have_attribute('data-state','recording');page.wait_for_timeout(550)
        panel.locator('[data-voice-stop]').click();expect(panel).to_have_attribute('data-state','ready',timeout=15000)
        # Explicit local-model contract fixture: test upload, loading/error/retry and review navigation.
        requests=[]
        def failed_transcript(route):
            requests.append(route.request.post_data_buffer);route.fulfill(status=422,content_type='application/json',body=json.dumps({'error':'Synthetic unavailable-model fixture'}))
        page.route('**/intelligence/voice',failed_transcript)
        panel.locator('[data-voice-transcribe]').click();expect(panel).to_have_attribute('data-state','ready')
        check('still here' in panel.locator('[data-voice-status]').inner_text() and panel.locator('[data-voice-playback]').get_attribute('src'),'Failed transcription preserves audio for retry')
        check(len(requests)==1 and b'RIFF' in requests[0] and b'WAVE' in requests[0] and b'name="_csrf"' in requests[0],'Only explicit transcription uploads WAV and CSRF to local endpoint')
        page.screenshot(path=str(OUT/'transcription-error.png'));page.unroute('**/intelligence/voice')
        body='Action: Review synthetic voice task'
        source=db.execute('INSERT INTO intelligence_sources(kind,body) VALUES (?,?)',('voice',body)).lastrowid;db.commit()
        pending=[]
        def successful_transcript(route):
            pending.append(route)
        page.route('**/intelligence/voice',successful_transcript)
        panel.locator('[data-voice-transcribe]').click();expect(panel).to_have_attribute('data-state','transcribing');page.screenshot(path=str(OUT/'transcribing.png'))
        check(len(pending)==1 and panel.locator('[data-voice-start]').is_hidden(),'Transcription has a visible loading state and prevents duplicate recording')
        pending[0].fulfill(status=200,content_type='application/json',body=json.dumps({'review':f'/intelligence?source={source}'}))
        page.wait_for_url(f'**/intelligence?source={source}')
        check(db.execute('SELECT COUNT(*) FROM tasks').fetchone()[0]==0,'Successful transcript reaches editable source without creating tasks')
        form=page.locator(f'form[action="/intelligence/source/{source}/preview"]');form.locator('textarea').fill('Action: User corrected synthetic task');form.locator('button').click()
        expect(page.get_by_text('Confirm and apply',exact=True)).to_be_visible()
        check(db.execute('SELECT COUNT(*) FROM tasks').fetchone()[0]==0,'Task extraction remains a review-only preview')
        page.get_by_role('button',name='Confirm and apply',exact=True).click()
        expect(page.get_by_text('Reviewed action applied.',exact=True)).to_be_visible()
        check(db.execute('SELECT COUNT(*) FROM tasks WHERE title=?',('User corrected synthetic task',)).fetchone()[0]==1,'Only reviewed confirmation creates the voice task')
        for lang in ['si','ta']:
            db.execute("INSERT INTO settings(key,value) VALUES ('locale',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",(lang,));db.commit()
            page.goto(base+'/intelligence');page.locator('details.card > summary').first.click()
            panel=page.locator('[data-voice-recorder]');page.set_viewport_size({'width':390,'height':900});panel.scroll_into_view_if_needed()
            check(page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),lang+' recording layout fits narrow width')
            check(panel.locator('[data-voice-start]').inner_text()!='Start recording',lang+' recording controls are translated')
            page.screenshot(path=str(OUT/f'recording-{lang}-narrow.png'))
        check(not errors,'No browser script errors: '+str(errors));browser.close()
    (OUT/'results.json').write_text(json.dumps({'checks':checks,'errors':errors,'mediaSource':'Chromium synthetic microphone','transcription':'Explicit response fixtures; no real speech recognition'},indent=2),encoding='utf-8')
    print(f'{len(checks)} voice recording UI checks passed. Evidence: {OUT}')
finally:
    server.terminate();server.wait(timeout=10);log.close()
