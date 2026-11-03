"""Localize source-owned UI copy; never inspect or translate workspace records.

--prepare wraps static view copy, attributes and literal validation messages.
--translate fills missing catalog pairs using machine translation of UI literals.
Existing reviewed pairs always win. Generated translations require language QA.
"""
from pathlib import Path
import argparse, concurrent.futures, html, json, re, time, urllib.parse, urllib.request

ROOT=Path(__file__).resolve().parents[1]
CAT=ROOT/'app/Modules/Onboarding/locales/complete.php'
STATE=ROOT/'qa/localization-catalog.json'
Q=r'''(?:'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")'''
def decode(raw):
    if raw[0]=='"':
        try:return json.loads(raw)
        except ValueError:return raw[1:-1]
    return raw[1:-1].replace("\\'","'").replace('\\\\','\\')
def literal(text):return "'"+text.replace('\\','\\\\').replace("'","\\'")+"'"
def human(text):return bool(re.search('[A-Za-z]{2}',text)) and not bool(re.fullmatch(r'[a-z0-9_./:#{}?&=+%\\-]+',text) and re.search(r'[_/:#{}?&=+%\\]',text))
def catalogs():
    pairs={}
    for p in [ROOT/'app/Modules/Onboarding/src/LocaleService.php',*(ROOT/'app/Modules/Onboarding/locales').glob('*.php')]:
        for m in re.finditer('('+Q+r')\s*=>\s*\[\s*('+Q+r')\s*,\s*('+Q+r')\s*\]',p.read_text(encoding='utf-8')):
            pairs[decode(m[1])]=[decode(m[2]),decode(m[3])]
    return pairs
def prepare():
    keys=set()
    for p in (ROOT/'app').rglob('*.php'):
        if '/locales/' in p.as_posix():continue
        source=p.read_text(encoding='utf-8')
        isview='/views/' in p.as_posix()
        if isview:
            def source_attr(m):
                label=html.unescape(m[2])
                if '<?' in label or not human(label):return m[0]
                keys.add(label);return m[1]+'"<?= e(t('+literal(label)+')) ?>"'
            source=re.sub(r'((?:placeholder|title|aria-label|alt|data-confirm)\s*=\s*)"([^"]*)"',source_attr,source)
            for m in re.finditer(r'\$(?:workspaceTitle|description)\s*=\s*('+Q+')',source):keys.add(decode(m[1]))
        parts=re.split(r'(<\?(?:php|=)[\s\S]*?\?>)',source) if isview else [source]
        output=[];special=None;in_tag=False;quote=None
        for part in parts:
            if part.startswith('<?') or not isview:
                # Only literal arguments whose API explicitly owns display copy.
                patterns=[r'(new\s+(?:InvalidArgumentException|RuntimeException)\(\s*)('+Q+')',r"('(?:title|message|label|description)'\s*=>\s*)("+Q+')',r'(\be\(\s*)('+Q+')']
                if isview:patterns.append(r'(=>\s*)('+Q+')')
                for pattern in patterns:
                    def wrap(m):
                        label=decode(m[2])
                        if not human(label) or '$' in label or '<' in label or label.startswith(('/', '#')):return m[0]
                        if not isview and label.islower() and not re.search(r'\s',label):return m[0]
                        if m[1].strip()=='=>' and (label.islower() or not re.search('[A-Z ]',label)):return m[0]
                        keys.add(label);return m[1]+'t('+m[2]+')'
                    # e(literal) is UI output only in view files.
                    if pattern.startswith(r'(\be') and (not isview or in_tag):continue
                    part=re.sub(pattern,wrap,part)
                for m in re.finditer(r'\bt\(\s*('+Q+')',part):keys.add(decode(m[1]))
                if isview or p.name=='helpers.php':
                    def markup(m):
                        raw=m[0];label=decode(raw)
                        if not re.search(r'<[A-Za-z/!][^>]*>',label) or '$' in label:return raw
                        before=part[max(0,m.start()-12):m.start()]
                        if before.endswith('ui_markup('):return raw
                        for text in re.findall(r'>([^<>]+)(?=<|$)',label)+re.findall(r'(?:title|aria-label|placeholder|data-confirm)="([^"]+)"',label):
                            text=html.unescape(text.strip())
                            if human(text):keys.add(text)
                        return 'ui_markup('+raw+')'
                    part=re.sub(Q,markup,part)
                if isview and part.startswith('<?=') and not in_tag:
                    def choice(m):
                        label=decode(m[2])
                        if not human(label) or not re.match('[A-Z]',label) or any(c in label for c in '<>='):return m[0]
                        keys.add(label);return m[1]+'t('+m[2]+')'
                    part=re.sub(r'((?:\?\?|\?|:)\s*)('+Q+')',choice,part)
                output.append(part);continue
            # Keep PHP sections opaque and retain tag context across sections.
            i=0;out=[]
            while i<len(part):
                if part[i]=='<' and not in_tag:
                    end=part.find('>',i)
                    if end!=-1:
                        tag=part[i:end+1]
                        m=re.match(r'<(/?)(script|style|pre|code)\b',tag,re.I)
                        if m:special=None if m[1] else m[2].lower()
                        def attr(m):
                            label=html.unescape(m[2])
                            if not human(label) or '<?' in label:return m[0]
                            keys.add(label)
                            return m[1]+'"<?= e(t('+literal(label)+')) ?>"'
                        tag=re.sub(r'((?:placeholder|title|aria-label|alt|data-confirm)\s*=\s*)"([^"]*)"',attr,tag)
                        out.append(tag);i=end+1;continue
                    in_tag=True
                if in_tag:
                    ch=part[i];out.append(ch)
                    if quote:
                        if ch==quote:quote=None
                    elif ch in "\"'":quote=ch
                    elif ch=='>':in_tag=False
                    i+=1;continue
                end=part.find('<',i)
                if end<0:end=len(part)
                text=part[i:end];label=html.unescape(text.strip())
                if label and human(label) and not special:
                    keys.add(label)
                    text=text[:len(text)-len(text.lstrip())]+'<?= e(t('+literal(label)+')) ?>'+text[len(text.rstrip()):]
                out.append(text);i=end
            output.append(''.join(out))
        updated=''.join(output)
        if updated!=source:p.write_text(updated,encoding='utf-8')
    # JavaScript only: translate literal display strings, preserving selectors,
    # protocols, property names and every expression containing user data.
    for p in (ROOT/'public/assets').glob('*.js'):
        if p.name.startswith(('locale-','vendor','htmx','chart','alpine')) or p.name=='i18n.js':continue
        source=p.read_text(encoding='utf-8')
        source=re.sub(r'window\.lifeos(?:T|HTML)\(('+Q+r')\)',lambda m:m[1],source)
        def js(m):
            raw=m[0];label=decode(raw)
            if raw.startswith(('//','/*','`')):return raw
            before=source[max(0,m.start()-32):m.start()];after=source[m.end():].lstrip()
            if 'lifeosT(' in before[-10:] or (after.startswith(':') and (before.rstrip().endswith(('{',',')) or re.search(r'\bcase\s*$',before))):return raw
            if '<' in label and '>' in label:
                for text in re.findall(r'>([^<>]+)(?=<|$)',label)+re.findall(r'(?:title|aria-label|placeholder)="([^"]+)"',label):
                    text=html.unescape(text.strip())
                    if human(text):keys.add(text)
                return 'window.lifeosHTML('+raw+')'
            if not human(label) or not re.search('[A-Z ]',label) or re.search(r'[<>#{}\\\n\r]|://|\[data-|querySelector|application/|text/',label):return raw
            if label in {'Content-Type','Accept','Origin','POST','GET','DELETE','PUT','PATCH','UTC','JSON','SVG','HTML','INPUT','BUTTON','SELECT','TEXTAREA','OPTION','TABLE','TR','TD','TH','DIV','SPAN','A','FORM','SCRIPT','STYLE','LINK','HEAD','BODY','EUC-KR'}:return raw
            if re.match(r'^[.\[/:@]',label) or (re.fullmatch('[A-Z_0-9-]+',label) and len(label)>2):return raw
            if not label.strip():return raw
            if not re.search(r'\s',label) and label not in {'Today','Tomorrow','Yesterday','System','Dark','Light','Save','Cancel','Close','Open','Delete','Remove','Restore','Retry','Loading','Error','Start','Stop','Pause','Resume','Done','Notifications','Calendar','Unread','All','Settings'}:return raw
            if not re.match(r'^[A-Za-z“]',label) or re.search(r'(?:querySelector(?:All)?|matches|closest|matchMedia|addEventListener|removeEventListener|replace|includes)\s*\([^)]*$',before):return raw
            if re.search(r'(?:className|classList|\.style\.[a-zA-Z]+)\s*[=(.,]?\s*$',before):return raw
            keys.add(label);return 'window.lifeosT('+raw+')'
        source=re.sub(r'//[^\n]*|/\*[\s\S]*?\*/|`(?:[^`\\]|\\.)*`|'+Q,js,source)
        p.write_text(source,encoding='utf-8')
    # Catalog definitions include validation types; translate their displayed
    # headings/field names at the view boundary, never the type identifiers.
    for folder in ['Employee','StudyTools','School','LifePlanning']:
        for p in (ROOT/'app/Modules'/folder/'src').glob('*.php'):
            source=p.read_text(encoding='utf-8')
            if 'function catalog(' not in source:continue
            for m in re.finditer(r'=>\s*\[\s*('+Q+r')\s*,\s*\[',source):keys.add(decode(m[1]))
            for m in re.finditer('('+Q+r')\s*=>\s*('+Q+')',source):
                field,kind=decode(m[1]),decode(m[2]);keys.add(field.replace('_',' ').capitalize())
                if kind.startswith('enum:'):keys.update(v.replace('_',' ').capitalize() for v in kind[5:].split(','))
    for m in re.finditer(r'RuntimeError\(('+Q+')',(ROOT/'scripts/opencode_worker.py').read_text(encoding='utf-8')):keys.add(decode(m[1]))
    for m in re.finditer('('+Q+r')\s*=>\s*\d+',(ROOT/'app/Modules/WorkRoles/src/WorkRoleService.php').read_text(encoding='utf-8')):keys.add(decode(m[1]))
    STATE.write_text(json.dumps({'keys':sorted(keys)},ensure_ascii=False,indent=2),encoding='utf-8')
    print(f'{len(keys)} source-owned translation keys',flush=True)
def translate_batch(items,lang):
    text='\n'.join(items)
    url='https://translate.googleapis.com/translate_a/single?'+urllib.parse.urlencode({'client':'gtx','sl':'en','tl':lang,'dt':'t','q':text})
    for attempt in range(4):
        try:
            with urllib.request.urlopen(url,timeout=30) as r:data=json.load(r)
            result=''.join(x[0] or '' for x in data[0]).split('\n')
            if len(result)==len(items):return dict(zip(items,result))
            if len(items)==1:return {items[0]:' '.join(result)}
            mid=len(items)//2
            return translate_batch(items[:mid],lang)|translate_batch(items[mid:],lang)
        except Exception:
            if attempt==3:raise
            time.sleep(attempt+1)
def write_catalog(pairs):
    CAT.write_text('<?php\ndeclare(strict_types=1);\n// Source UI copy only. Machine translation additions; see qa/localization-catalog.json.\nreturn [\n'+''.join('    '+literal(k)+'=>['+literal(v[0])+','+literal(v[1])+'],\n' for k,v in sorted(pairs.items()))+'];\n',encoding='utf-8')
def translate():
    pairs=catalogs();keys=json.loads(STATE.read_text(encoding='utf-8'))['keys']
    pending=[k for k in keys if k not in pairs and k.strip()]
    batches=[];batch=[];size=0
    for key in pending:
        if batch and (size+len(key)>2400 or len(batch)>=30):batches.append(batch);batch=[];size=0
        batch.append(key);size+=len(key)+1
    if batch:batches.append(batch)
    generated={k:['',''] for k in pending}
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        futures={pool.submit(translate_batch,batch,lang):(i,lang) for i,lang in enumerate(['si','ta']) for batch in batches}
        done=0
        for future in concurrent.futures.as_completed(futures):
            i,lang=futures[future]
            for key,value in future.result().items():generated[key][i]=value
            done+=1
            if done%10==0:print(f'Translation batches {done}/{len(futures)}',flush=True)
    # Preserve existing curated entries in their original files.
    write_catalog(pairs|generated)
    STATE.write_text(json.dumps({'keys':keys,'catalog_keys':len(pairs)+len(generated),'machine_translated_additions':len(generated),'missing_pairs':[k for k in keys if k not in pairs and not all(generated.get(k,[]))]},ensure_ascii=False,indent=2),encoding='utf-8')
    print(f'Added {len(generated)} Sinhala/Tamil pairs',flush=True)
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--prepare',action='store_true');parser.add_argument('--translate',action='store_true');args=parser.parse_args()
    if args.prepare:prepare()
    if args.translate:translate()
