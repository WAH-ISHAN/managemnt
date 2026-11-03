"""Wrap known static HTML copy in t(); never translate database/user values.
Run with --write to update, without arguments to report remaining literal copy.
"""
from pathlib import Path
import html,json,re,sys
ROOT=Path(__file__).resolve().parents[1]
# English catalog keys from PHP locale pair files; these are JSON-compatible literals.
keys=set()
for p in (ROOT/'app/Modules/Onboarding/locales').glob('*.php'):
    for m in re.finditer(r'("(?:[^"\\]|\\.)*"|\'(?:[^\'\\]|\\.)*\')\s*=>',p.read_text(encoding='utf-8')):
        raw=m.group(1)
        keys.add(json.loads(raw) if raw[0]=='"' else raw[1:-1].replace("\\'","'").replace('\\\\','\\'))
missing={};wrapped=0
for p in (ROOT/'app').rglob('*.php'):
    if '/views/' not in p.as_posix(): continue
    source=p.read_text(encoding='utf-8');out=[];in_tag=False;quote=None;special=None
    # PHP sections must be opaque: values and user text are not translation keys.
    for part in re.split(r'(<\?(?:php|=)[\s\S]*?\?>)',source):
        if part.startswith('<?'):out.append(part);continue
        i=0
        while i<len(part):
            ch=part[i]
            if in_tag:
                out.append(ch)
                if quote:
                    if ch==quote:quote=None
                elif ch in "\"'":quote=ch
                elif ch=='>':in_tag=False
                i+=1
            elif ch=='<':
                m=re.match(r'<(/?)(script|style|pre|code)\b',part[i:],re.I)
                if m:special=None if m[1] else m[2].lower()
                in_tag=True;out.append(ch);i+=1
            else:
                j=part.find('<',i)
                if j<0:j=len(part)
                text=part[i:j];label=html.unescape(text.strip())
                if label and re.search('[A-Za-z]{2}',label) and not special:
                    if label in keys:
                        if '--write' in sys.argv:
                            literal="'"+label.replace('\\','\\\\').replace("'","\\'")+"'"
                            text=text[:len(text)-len(text.lstrip())]+'<?= e(t('+literal+')) ?>'+text[len(text.rstrip()):]
                            wrapped+=1
                    else:missing[label]=missing.get(label,0)+1
                out.append(text);i=j
    if '--write' in sys.argv:p.write_text(''.join(out),encoding='utf-8')
report={'known_keys':len(keys),'wrapped':wrapped,'untranslated_static_nodes':sum(missing.values()),'unique_untranslated':len(missing),'missing':sorted(missing)}
if '--write' in sys.argv:
    print(json.dumps({k:v for k,v in report.items() if k!='missing'}))
else:print(json.dumps(report,ensure_ascii=False,indent=2))
