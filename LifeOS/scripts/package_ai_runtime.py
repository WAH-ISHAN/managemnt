"""Bundle already-installed native tools. No installation or network downloads."""
import argparse, json, shutil, subprocess, sys, zipfile
from pathlib import Path

def package(executable, destination):
    executable = executable.resolve()
    if not executable.is_file() or executable.name.lower() != 'opencode.exe':
        raise ValueError('Choose the approved installed opencode.exe.')
    destination.mkdir(parents=True, exist_ok=True)
    shutil.copy2(executable, destination / 'opencode.exe')
    source = Path(sys.base_prefix)
    python = destination / 'python'; python.mkdir(exist_ok=True)
    version = f'python{sys.version_info.major}{sys.version_info.minor}'
    for name in ['python.exe', f'{version}.dll', 'python3.dll', 'LICENSE.txt']:
        if not (source / name).is_file():
            raise ValueError(f'The installed Python is missing {name}.')
        shutil.copy2(source / name, python / name)
    for library in source.glob('vcruntime*.dll'):
        shutil.copy2(library, python / library.name)
    dlls = python / 'DLLs'; dlls.mkdir(exist_ok=True)
    for library in (source / 'DLLs').iterdir():
        if library.is_file() and library.suffix.lower() in ['.dll', '.pyd']:
            shutil.copy2(library, dlls / library.name)
    excluded = {'site-packages', '__pycache__', 'test', 'tests', 'tkinter', 'idlelib', 'ensurepip', 'venv', 'turtledemo'}
    with zipfile.ZipFile(python / (version + '.zip'), 'w', zipfile.ZIP_DEFLATED) as archive:
        for item in (source / 'Lib').rglob('*.py'):
            relative = item.relative_to(source / 'Lib')
            if not any(part in excluded for part in relative.parts):
                archive.write(item, relative.as_posix())
    # Prevent registry/PATH/user site packages from changing the private helper.
    (python / (version + '._pth')).write_text(f'{version}.zip\nDLLs\n.\n', encoding='ascii')
    result = subprocess.run([str(python / 'python.exe'), '-I', '-c', 'import ssl,hashlib,json,subprocess,threading,argparse;print("OK")'], capture_output=True, text=True, check=True)
    if result.stdout.strip() != 'OK':
        raise RuntimeError('Bundled Python failed its isolated import check.')
    (destination / 'runtime.json').write_text(json.dumps({'python': sys.version.split()[0], 'opencode': '2.2.5', 'source': 'existing installed runtime', 'model_weights': False}, indent=2), encoding='utf-8')
    print('Bundled installed AI runtime and isolated Python helper. No model weights or downloads.')

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--opencode', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    package(args.opencode, args.output)
