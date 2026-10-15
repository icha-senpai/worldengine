"""Draw the story medals on a 48px grid; no dependencies or downloaded art."""
from pathlib import Path
import struct
import zlib

ROOT = Path(__file__).resolve().parent.parent
SIZE = 48


def badge(achievement_id, river=False):
    variant = achievement_id - 9001
    pixels = [[(0, 0, 0, 0) for _ in range(SIZE)] for _ in range(SIZE)]

    def rect(x, y, width, height, color):
        rgba = tuple(bytes.fromhex(color)) + (255,)
        for py in range(y, y + height):
            for px in range(x, x + width):
                pixels[py][px] = rgba

    def circle(cx, cy, radius, color):
        for y in range(cy - radius, cy + radius + 1):
            for x in range(cx - radius, cx + radius + 1):
                if (x - cx) ** 2 + (y - cy) ** 2 <= radius ** 2:
                    rect(x, y, 1, 1, color)

    colors = ['8f4840','326b76','667743','6b558c','326b83','66a5b5','514d83','966442','7d6940']
    ribbon = colors[variant]
    rect(13, 31, 8, 11, '302b30')
    rect(27, 31, 8, 11, '302b30')
    rect(14, 32, 6, 8, ribbon)
    rect(28, 32, 6, 8, ribbon)
    circle(24, 21, 18, '302b30')
    circle(24, 21, 16, 'ba8744')
    circle(24, 20, 14, 'f5d68b')
    circle(24, 20, 12, '426e72' if river else '4c6550')
    if variant >= 2:
        # Large, distinct emblems on the same stepped medallion grid.
        light, gold, dark = 'fff0c7', 'f3d58c', '302b30'
        if variant == 2:  # Reeds and the missing crate.
            rect(18, 21, 13, 9, 'ba8744')
            rect(20, 23, 9, 2, dark)
            for x, y in [(15, 14), (23, 10), (32, 13)]:
                rect(x, y, 2, 17, 'bcd1ad')
                rect(x-1, y, 4, 6, gold)
        elif variant == 3:  # Moon above an open journal.
            circle(26, 13, 6, light)
            circle(29, 11, 5, ribbon)
            rect(15, 23, 19, 8, gold)
            rect(23, 23, 2, 8, dark)
            rect(17, 25, 4, 1, dark)
            rect(27, 25, 4, 1, dark)
        elif variant == 4:  # Anchor.
            circle(24, 12, 4, light)
            circle(24, 12, 2, ribbon)
            rect(23, 15, 3, 15, light)
            rect(18, 19, 13, 2, light)
            rect(17, 28, 15, 3, light)
            rect(14, 24, 4, 5, light)
            rect(31, 24, 4, 5, light)
        elif variant == 5:  # Crystal snowflake.
            rect(23, 10, 3, 22, light)
            rect(14, 20, 21, 3, light)
            for x, y in [(17,14),(28,14),(17,27),(28,27)]:
                rect(x,y,4,4,'a6e8f0')
            rect(21,17,7,9,'a6e8f0')
            rect(23,19,3,5,light)
        elif variant == 6:  # Sealed letter over a vent.
            rect(15, 13, 19, 13, light)
            for n in range(8):
                rect(16+n,14+n,1,1,'ba8744')
                rect(32-n,14+n,1,1,'ba8744')
            rect(22,20,5,4,'8f4840')
            rect(18,29,13,3,dark)
            rect(22,26,2,3,'7cc4c0')
            rect(27,27,2,2,'7cc4c0')
        elif variant == 7:  # Hammer and spanner.
            for n in range(13):
                rect(17+n,15+n,3,3,gold)
                rect(29-n,15+n,3,3,light)
            rect(13,12,11,5,light)
            rect(28,11,3,6,light)
            rect(33,11,3,6,light)
        else:  # Magnifier around a star.
            circle(22,18,8,gold)
            circle(22,18,5,ribbon)
            rect(21,14,2,8,light)
            rect(18,17,8,2,light)
            for n in range(7): rect(28+n,24+n,3,3,gold)
    elif river:
        for x, y in [(14, 25), (22, 27), (29, 25)]:
            rect(x, y, 6, 2, '7cc4c0')
        rect(16, 16, 14, 7, 'f3d58c')
        rect(19, 14, 8, 11, 'f3d58c')
        rect(30, 17, 3, 5, 'ba8744')
        rect(13, 14, 3, 4, 'e8b96c')
        rect(13, 22, 3, 4, 'e8b96c')
        rect(27, 17, 2, 2, '302b30')
        rect(23, 8, 2, 4, 'fff0c7')
        rect(22, 9, 4, 2, 'fff0c7')
    else:
        rect(13, 24, 22, 4, '302b30')
        rect(14, 24, 20, 2, 'dcad6c')
        rect(16, 28, 3, 4, 'ba8744')
        rect(29, 28, 3, 4, 'ba8744')
        rect(19, 13, 10, 9, 'fff0c7')
        rect(21, 11, 6, 2, 'fff0c7')
        rect(29, 14, 4, 6, 'ba8744')
        rect(29, 16, 2, 2, '4c6550')
        rect(21, 7, 2, 3, 'bcd1ad')
        rect(25, 6, 2, 4, 'bcd1ad')

    # Scale each logical pixel 4x, preserving the stepped edges and true alpha.
    rows = []
    for row in pixels:
        scanline = b'\0' + b''.join(bytes(pixel) * 4 for pixel in row)
        rows.extend([scanline] * 4)

    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))

    png = b'\x89PNG\r\n\x1a\n'
    png += chunk(b'IHDR', struct.pack('>IIBBBBB', SIZE * 4, SIZE * 4, 8, 6, 0, 0, 0))
    png += chunk(b'IDAT', zlib.compress(b''.join(rows))) + chunk(b'IEND', b'')
    (ROOT / f'assets/achievements/badge-{achievement_id}.png').write_bytes(png)


badge(9001)
badge(9002, river=True)
for achievement_id in range(9003, 9010):
    badge(achievement_id)
print('Built nine quest story medals.')
