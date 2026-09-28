"""Build demand-loaded font subsets for the opportunity embed; preserve every cmap entry."""
import hashlib
import json
from collections import defaultdict
from pathlib import Path

from fontTools import subset
from fontTools.ttLib import TTFont

ROOT = Path(__file__).resolve().parents[2]
OUTPUT = ROOT / 'client/fonts/embedded'
CSS = ROOT / 'client/css/embedded-fonts.css'
FONTS = [
    ('tabler-icons.ttf', 'tabler-icons', 400),
    ('fa-solid-900.ttf', 'Font Awesome 6 Free', 900),
    ('fa-regular-400.ttf', 'Font Awesome 6 Free', 400),
    *[(f'inter/Inter-{name}.woff2', 'Inter', weight) for name, weight in
      [('Regular', 400), ('Medium', 500), ('SemiBold', 600), ('Bold', 700)]],
]


def ranges(points):
    result = []
    start = None
    end = -1
    for point in sorted(points):
        if start is None:
            start = end = point
        elif point == end + 1:
            end = point
        else:
            result.append(f'U+{start:X}' if start == end else f'U+{start:X}-{end:X}')
            start = end = point
    if start is not None:
        result.append(f'U+{start:X}' if start == end else f'U+{start:X}-{end:X}')
    return ','.join(result)


def build():
    inputs = [ROOT / 'client/fonts' / name for name, _, _ in FONTS]
    digest = hashlib.sha256(Path(__file__).read_bytes())
    for file in inputs:
        digest.update(file.read_bytes())
    fingerprint = digest.hexdigest()
    manifest_path = OUTPUT / 'manifest.json'
    if manifest_path.exists() and CSS.exists():
        manifest = json.loads(manifest_path.read_text())
        if manifest['fingerprint'] == fingerprint and all((OUTPUT / name).exists() for name in manifest['files']):
            print('Embedded font subsets are up to date.')
            return

    OUTPUT.mkdir(parents=True, exist_ok=True)
    rules = []
    filenames = []
    for source, (name, family, weight) in zip(inputs, FONTS):
        with TTFont(source, recalcTimestamp=False) as font:
            cmap = font.getBestCmap()
        groups = defaultdict(list)
        for codepoint in sorted(cmap):
            # Latin (including Portuguese accents), punctuation and currency share a file.
            latin = family == 'Inter' and (codepoint <= 0xFF or 0x2000 <= codepoint <= 0x206F or 0x20A0 <= codepoint <= 0x20CF)
            groups['latin' if latin else f'{codepoint // 128:04x}'].append(codepoint)
        for group, codepoints in groups.items():
            with TTFont(source, recalcTimestamp=False) as font:
                options = subset.Options()
                options.recalc_timestamp = False
                subsetter = subset.Subsetter(options=options)
                subsetter.populate(unicodes=codepoints)
                subsetter.subset(font)
                if set(font.getBestCmap()) != set(codepoints):
                    raise RuntimeError(f'Lost Unicode coverage in {name}/{group}')
                font.flavor = 'woff2'
                filename = f'{source.stem}-{group}-{fingerprint[:12]}.woff2'
                font.save(OUTPUT / filename)
            filenames.append(filename)
            display = 'swap' if family == 'Inter' else 'block'
            rules.append(
                f'@font-face{{font-family:"{family}";font-style:normal;font-weight:{weight};'
                f'font-display:{display};src:url("../fonts/embedded/{filename}") format("woff2");'
                f'unicode-range:{ranges(codepoints)};}}'
            )
    # Loaded after the normal theme: later matching faces take precedence, including
    # existing pseudo-elements and user-selected icons. Other font styles retain their fallback.
    CSS.write_text('\n'.join(rules) + '\n')
    manifest_path.write_text(json.dumps({'fingerprint': fingerprint, 'files': filenames}, indent=2) + '\n')
    for file in OUTPUT.glob('*.woff2'):
        if file.name not in filenames:
            file.unlink()
    print(f'Built {len(filenames)} embedded font subsets; complete Unicode coverage verified.')


if __name__ == '__main__':
    build()
