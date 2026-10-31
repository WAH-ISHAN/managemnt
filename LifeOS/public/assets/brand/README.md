# LifeOS brand artwork

The active brand is the user-supplied `icon.ico`, added on 31 October 2026.
The Windows launcher and Inno Setup installer use that file directly.

Run `python scripts/create-icon.py` (requires Pillow) from LifeOS to export
`lifeos-mark.png`, `favicon-32.png`, and the 64, 128 and 512 pixel web assets.
This preserves the supplied ICO unchanged. Its largest frame is 256 pixels;
the 512 pixel web asset is an upscale.

The older `lifeos-source.png` and other source artwork are historical references,
not inputs to the active icon exporter.
