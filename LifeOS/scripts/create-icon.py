"""Export web assets from the user-supplied Windows icon. Requires Pillow.

Keep icon.ico byte-for-byte unchanged; it is the authoritative brand source.
"""
from pathlib import Path
from PIL import Image

root = Path(__file__).resolve().parents[1]
brand = root / 'public' / 'assets' / 'brand'
with Image.open(root / 'icon.ico') as original:
    image = original.convert('RGBA')
for name, size in [('lifeos-mark.png', 256), ('favicon-32.png', 32),
                   ('lifeos-64.png', 64), ('lifeos-128.png', 128),
                   ('lifeos-512.png', 512)]:
    image.resize((size, size), Image.Resampling.LANCZOS).save(brand / name, optimize=True)
print('LifeOS web icons exported from icon.ico; Windows icon preserved.')
