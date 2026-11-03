"""Two independent PHP/database/device-key fixtures; no personal files or network."""
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
checks=0
def check(ok,label):
    global checks
    if not ok: raise AssertionError(label)
    checks+=1

with tempfile.TemporaryDirectory(prefix='lifeos-device-test-') as folder:
    devices={name:Path(folder)/name for name in ('a','b')}
    for path in devices.values(): path.mkdir()
    def call(device,op,**data):
        r=subprocess.run([os.environ.get('LIFEOS_QA_PHP','php'),str(ROOT/'tests/device_sync_fixture.php'),str(devices[device])],input=json.dumps(dict(op=op,**data)),text=True,capture_output=True,cwd=ROOT,timeout=30)
        if r.returncode: raise AssertionError(r.stderr)
        return json.loads(r.stdout)
    a,b=call('a','identity'),call('b','identity')
    check(a['id']!=b['id'],'Separate device identities')
    call('a','pair',public=b['public']);call('b','pair',public=a['public'])
    seed=call('a','seed')
    packet=call('a','tasks_export',peer=b['id'])
    check('Original task' not in packet,'Task packet is ciphertext')
    check(call('b','tasks_apply',packet=packet)=={'ok':True},'First task transfer')
    check('error' in call('b','tasks_apply',packet=packet),'Replay refused')
    back=call('b','tasks_export',peer=a['id']);check(call('a','tasks_apply',packet=back)=={'ok':True},'Reverse sync keeps native identity')
    check(len(call('a','list')['tasks'])==1,'Reverse sync does not duplicate original')
    bid=call('b','list')['tasks'][0]['id']
    call('b','edit_task',id=bid,title='B edit');call('a','edit_task',id=seed['task'],title='A edit')
    conflict=call('b','tasks_export',peer=a['id']);preview=call('a','tasks_preview',packet=conflict)
    check(preview['rows'][0]['conflict'],'Concurrent task edits require resolution')
    check('error' in call('a','tasks_apply',packet=conflict),'Unresolved conflict applies nothing')
    uuid=preview['rows'][0]['id'];call('a','tasks_apply',packet=conflict,choices={uuid:'local'})
    check(call('a','list')['tasks'][0]['title']=='A edit','Local choice preserved')
    fresh=call('b','tasks_export',peer=a['id']);call('a','tasks_apply',packet=fresh,choices={uuid:'remote'})
    check(call('a','list')['tasks'][0]['title']=='B edit','Incoming choice explicitly applied')
    records=call('a','objects_export',peer=b['id'],contacts=[seed['contact']],documents=[seed['document']])
    check('Original attachment bytes' not in records and 'Contact' not in records,'Record packet hides attachment and metadata')
    check(call('b','objects_apply',packet=records)=={'ok':True},'Contact and attachment transfer')
    state=call('b','list')
    check(state['contacts'][0]['name']=='Contact','Contact fields preserved')
    check(state['documents'][0]['sha256']==state['documents'][0]['actual_sha256'],'Received attachment bytes match verified hash')
    check('error' in call('b','objects_apply',packet=records),'Record replay refused')
    call('b','edit_document',id=state['documents'][0]['id'],title='Updated remote metadata')
    back=call('b','objects_export',peer=a['id'],contacts=[state['contacts'][0]['id']],documents=[state['documents'][0]['id']])
    preview=call('a','objects_preview',packet=back)
    choices={r['id']:'remote' for r in preview['rows'] if r['conflict']}
    check(call('a','objects_apply',packet=back,choices=choices)=={'ok':True},'Incoming document version accepted explicitly')
    check(len(call('a','list')['documents'])==2,'Previous local document remains available')
    call('a','revoke',peer=b['id']);check('error' in call('a','objects_preview',packet=back),'Revoked device refused before merge')
print(f'{checks} two-device sync checks passed.')
