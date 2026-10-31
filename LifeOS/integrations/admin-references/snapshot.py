"""Read selected admin source from the two authorized references; write only here.

The resulting source archive is a sanitized reference, not a runnable application.
No environment files, runtime records, uploaded media or dependencies are copied.
"""
from pathlib import Path
import hashlib
import json
import re

DEST = Path(__file__).resolve().parent
PROJECTS = {
    'creativefx': Path(r'D:\OFZ\creative-_6'),
    'tenderhub': Path(r'D:\OFZ\tender'),
}
CODE_EXTENSIONS = {'.ts', '.tsx', '.js', '.mjs', '.cjs', '.css', '.php'}
IMPORT = re.compile(r'''(?:from\s*|import\s*|require\s*\(\s*)['"]([^'"]+)['"]''')


def sha(data):
    return hashlib.sha256(data).hexdigest()


def initial_files(kind, root):
    selected = set()
    folders = ['src/admin'] if kind == 'creativefx' else [
        'web/src/app/(portal)/console', 'web/src/components/console',
        'web/src/app/admin', 'web/src/app/api/admin',
        'apps/api/app/Controllers/Api/V1/Admin', 'apps/api/app/Controllers/Api/V1/Auth',
        'apps/api/app/Models', 'apps/api/app/Filters',
    ]
    files = ['package.json', 'server.cjs', 'src/types.ts'] if kind == 'creativefx' else [
        'package.json', 'web/package.json', 'apps/api/composer.json',
        'apps/api/app/Config/Routes.php', 'apps/api/app/Controllers/Api/V1/BaseApiController.php',
        'web/src/lib/api.ts', 'web/src/lib/admin-access.ts', 'web/src/lib/session.ts',
        'web/src/app/api/workspace/[...path]/route.ts', 'web/src/app/globals.css',
    ]
    for folder in folders:
        base = root / folder
        selected.update(p for p in base.rglob('*') if p.is_file() and p.suffix in CODE_EXTENSIONS)
    selected.update(root / p for p in files if (root / p).is_file())
    return selected


def follow_imports(kind, root, selected):
    pending = list(selected)
    excluded = set()
    while pending:
        path = pending.pop()
        if path.suffix not in {'.ts', '.tsx', '.js', '.mjs', '.cjs'}:
            continue
        for name in IMPORT.findall(path.read_text(encoding='utf-8-sig')):
            if name.startswith('.'):
                base = (path.parent / name).resolve()
            elif kind == 'tenderhub' and name.startswith('@/'):
                base = (root / 'web/src' / name[2:]).resolve()
            else:
                continue
            if not base.is_relative_to(root):
                continue
            choices = [base] + [Path(str(base) + ext) for ext in CODE_EXTENSIONS] + [base / ('index' + ext) for ext in CODE_EXTENSIONS]
            match = next((candidate for candidate in choices if candidate.is_file()), None)
            if not match:
                continue
            relative = match.relative_to(root).as_posix()
            if match.suffix not in CODE_EXTENSIONS or any(part in {'data', 'uploads', 'node_modules', 'dist', '.git'} for part in match.relative_to(root).parts):
                excluded.add(relative)
                continue
            if match not in selected:
                selected.add(match)
                pending.append(match)
    return selected, excluded


def sanitize(relative, content):
    changes = []
    if relative == 'src/context/ContentContext.tsx':
        start = content.find('export const DEFAULT_CONTENT = {')
        end = content.find('export type SiteContent = typeof DEFAULT_CONTENT;')
        if start >= 0 and end > start:
            content = content[:start] + (
                '// REFERENCE SNAPSHOT: site-specific default content and team/business data omitted.\n'
                '// Supply the target site schema/defaults before using this implementation.\n'
                'export const DEFAULT_CONTENT = {} as Record<string, unknown>;\n\n'
            ) + content[end:]
            changes.append({'kind': 'site-default-content-block-omitted', 'count': 1})
    if relative == 'server.cjs':
        for variable, replacement in [
            ('INSECURE_PASSWORDS', 'const INSECURE_PASSWORDS = new Set([]); // Legacy values omitted in reference snapshot.'),
            ('INSECURE_SECRETS', 'const INSECURE_SECRETS = new Set([]); // Legacy values omitted in reference snapshot.'),
            ('DEFAULT_AUTH_HASH', "const DEFAULT_AUTH_HASH = ''; // Embedded legacy credential hash omitted; configure authentication explicitly."),
        ]:
            content, count = re.subn(r'^const ' + variable + r'\s*=.*$', replacement, content, flags=re.M)
            if count:
                changes.append({'kind': 'legacy-credential-definition-omitted', 'count': count})
    patterns = [
        ('private-key', r'-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----', '[PRIVATE KEY OMITTED]', re.S),
        ('email-address', r'[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}', 'redacted@example.invalid', 0),
        ('google-api-key', r'AIza[0-9A-Za-z_-]{30,}', '[API KEY OMITTED]', 0),
        ('aws-access-key', r'\b(?:AKIA|ASIA)[A-Z0-9]{16}\b', '[ACCESS KEY OMITTED]', 0),
        ('literal-jwt', r'eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}', '[JWT OMITTED]', 0),
        ('url-credentials', r'(https?://)[^\s/\x22\x27]+:[^\s/\x22\x27]+@', r'\1[USERINFO OMITTED]@', 0),
    ]
    for name, expression, replacement, flags in patterns:
        content, count = re.subn(expression, replacement, content, flags=flags)
        if count:
            changes.append({'kind': name, 'count': count})
    # Conservative omission of literal credential assignments. References to env
    # variables and field-name strings do not contain a credential value.
    assignment = re.compile(r'''(?im)((?:password|passwd|secret|api_?key|access_?token|refresh_?token)\s*[:=]\s*)(["'])([^"'\r\n]{8,})\2''')
    content, count = assignment.subn(lambda m: m[1] + m[2] + '[CREDENTIAL LITERAL OMITTED]' + m[2], content)
    if count:
        changes.append({'kind': 'literal-credential-assignment', 'count': count})
    return content, changes


for kind, source in PROJECTS.items():
    root = source.resolve(strict=True)
    target = (DEST / kind / 'source').resolve()
    if not target.is_relative_to(DEST) or target.is_relative_to(root):
        raise RuntimeError('Unsafe snapshot destination')
    target.mkdir(parents=True, exist_ok=True)
    selected, excluded = follow_imports(kind, root, initial_files(kind, root))
    entries = []
    for original in sorted(selected):
        relative = original.relative_to(root).as_posix()
        raw = original.read_bytes()
        sanitized, redactions = sanitize(relative, raw.decode('utf-8-sig'))
        copied = sanitized.encode('utf-8')
        out = target / relative
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_bytes(copied)
        after = sha(original.read_bytes())
        if after != sha(raw):
            raise RuntimeError('Reference changed during capture: ' + relative)
        entries.append({'path': relative, 'original_sha256': sha(raw), 'original_after_sha256': after,
                        'copied_sha256': sha(copied), 'sanitized': bool(redactions), 'redactions': redactions})
    manifest = {'kind': 'sanitized-source-reference', 'project': kind, 'source_root': str(root),
                'standalone_application': False, 'source_modified': False, 'files': entries,
                'excluded_imports': sorted(excluded),
                'excluded_categories': ['environment/config secrets', 'runtime/user records', 'uploads/media', 'dependencies', 'builds/caches/logs', 'site default content']}
    (DEST / kind / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    print(kind + ': ' + str(len(entries)) + ' selected source files captured; original hashes unchanged; ' +
          str(sum(bool(e['redactions']) for e in entries)) + ' files sanitized.')
