"""Package the generated LifeOS brand source into web and Windows icons.

Development only; requires Pillow. No API call or image generation is needed.
The original artwork and its prompt are in public/assets/brand/.
"""
from pathlib import Path
from PIL import Image

root = Path(__file__).resolve().parents[1]
brand = root / 'public' / 'assets' / 'brand'
source = brand / 'lifeos-source.png'
with Image.open(source) as original:
    image = original.convert('RGBA')
if image.width != image.height or image.width < 512:
    raise ValueError('The brand source must be a square image at least 512 pixels wide.')
if image.getchannel('A').getextrema()[0] != 0:
    raise ValueError('The brand source must retain transparent outer corners.')

for name, size in [('lifeos-mark.png', 256), ('favicon-32.png', 32),
                   ('lifeos-64.png', 64), ('lifeos-128.png', 128),
                   ('lifeos-512.png', 512)]:
    image.resize((size, size), Image.Resampling.LANCZOS).save(brand / name, optimize=True)

sizes = [(size, size) for size in [16, 24, 32, 48, 64, 128, 256]]
image.save(root / 'icon.ico', sizes=sizes)
print('LifeOS web icons and multi-resolution Windows icon created from saved artwork.')
