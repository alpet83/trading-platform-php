#!/usr/bin/env python3
"""Generate favicon.ico with letter S in a blue circle.
Pure Python — no PIL/GD needed. Produces 16x16 + 32x32 PNG-in-ICO.
Output: signals-server/favicon.ico and src/web-ui/favicon.ico
"""
import struct, zlib, os

W, H = 32, 32
BG     = (  0,   0,   0,   0)   # transparent
CIRCLE = ( 26,  90, 184, 255)   # blue
LETTER = (255, 255, 255, 255)   # white

pixels = [[BG] * W for _ in range(H)]

# --- circle (cx=15, cy=15, r=14) ---
cx, cy, r = 15, 15, 14
for y in range(H):
    for x in range(W):
        if (x - cx) ** 2 + (y - cy) ** 2 <= r * r:
            pixels[y][x] = CIRCLE

# --- S pixel-art (10 cols x 11 rows) ---
S = [
    " XXXXXX  ",
    "X      X ",
    "X        ",
    "X        ",
    " XXXXXX  ",
    "        X",
    "        X",
    "X      X ",
    " XXXXXX  ",
]

ox, oy = 11, 12   # top-left corner of S within 32x32 canvas
for row_i, row in enumerate(S):
    for col_i, ch in enumerate(row):
        if ch == 'X':
            px, py = ox + col_i, oy + row_i
            if 0 <= px < W and 0 <= py < H:
                pixels[py][px] = LETTER


def build_png(pix, w, h):
    def chunk(tag, data):
        crc = zlib.crc32(tag + data) & 0xffffffff
        return struct.pack('>I', len(data)) + tag + data + struct.pack('>I', crc)

    ihdr = struct.pack('>II', w, h) + bytes([8, 6, 0, 0, 0])   # 8-bit RGBA
    raw  = b''
    for row in pix:
        raw += b'\x00' + b''.join(bytes(p) for p in row)
    idat = zlib.compress(raw, 9)
    return (b'\x89PNG\r\n\x1a\n'
            + chunk(b'IHDR', ihdr)
            + chunk(b'IDAT', idat)
            + chunk(b'IEND', b''))


def nearest_neighbor(pix, sw, sh, dw, dh):
    return [
        [pix[int(y * sh / dh)][int(x * sw / dw)] for x in range(dw)]
        for y in range(dh)
    ]


def build_ico(images):
    """images: list of (width, height, png_bytes)"""
    n = len(images)
    header  = struct.pack('<HHH', 0, 1, n)   # reserved=0, type=1 (ICO), count
    offset  = 6 + n * 16
    entries = data = b''
    for w, h, png in images:
        bw = w if w < 256 else 0
        bh = h if h < 256 else 0
        entries += struct.pack('<BBBBHHII', bw, bh, 0, 0, 1, 32, len(png), offset)
        data    += png
        offset  += len(png)
    return header + entries + data


png32  = build_png(pixels, W, H)
pix16  = nearest_neighbor(pixels, W, H, 16, 16)
png16  = build_png(pix16, 16, 16)
ico    = build_ico([(32, 32, png32), (16, 16, png16)])

root   = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
targets = [
    os.path.join(root, 'signals-server', 'favicon.ico'),
    os.path.join(root, 'src', 'web-ui',   'favicon.ico'),
]

for path in targets:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'wb') as f:
        f.write(ico)
    print(f"OK  {len(ico):5d} bytes  ->  {path}")
