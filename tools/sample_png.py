#!/usr/bin/env python3
"""Decodificador PNG mínimo (não-interlaçado, 8 bits) só para amostrar cores."""
import struct, sys, zlib
from collections import Counter


def decode(path):
    d = open(path, 'rb').read()
    assert d[:8] == b'\x89PNG\r\n\x1a\n', 'não é PNG'
    pos, idat, pal = 8, b'', None
    w = h = depth = ctype = interlace = None
    while pos < len(d):
        ln, typ = struct.unpack('>I4s', d[pos:pos + 8])
        body = d[pos + 8:pos + 8 + ln]
        if typ == b'IHDR':
            w, h, depth, ctype, _, _, interlace = struct.unpack('>IIBBBBB', body)
        elif typ == b'PLTE':
            pal = body
        elif typ == b'IDAT':
            idat += body
        elif typ == b'IEND':
            break
        pos += 12 + ln
    assert depth == 8 and interlace == 0, f'depth={depth} interlace={interlace}'
    canais = {0: 1, 2: 3, 3: 1, 4: 2, 6: 4}[ctype]
    raw = zlib.decompress(idat)
    stride = w * canais
    out = bytearray(w * h * canais)
    prev = bytearray(stride)
    p = 0
    for y in range(h):
        f = raw[p]; p += 1
        linha = bytearray(raw[p:p + stride]); p += stride
        for i in range(stride):
            a = linha[i - canais] if i >= canais else 0
            b = prev[i]
            c = prev[i - canais] if i >= canais else 0
            x = linha[i]
            if f == 1: x += a
            elif f == 2: x += b
            elif f == 3: x += (a + b) // 2
            elif f == 4:
                pa, pb, pc = abs(b - c), abs(a - c), abs(a + b - 2 * c)
                x += a if (pa <= pb and pa <= pc) else (b if pb <= pc else c)
            linha[i] = x & 0xFF
        out[y * stride:(y + 1) * stride] = linha
        prev = linha
    return w, h, canais, ctype, pal, out


def pixel(w, canais, ctype, pal, buf, x, y):
    i = (y * w + x) * canais
    if ctype == 3:
        j = buf[i] * 3
        return pal[j], pal[j + 1], pal[j + 2]
    if ctype in (0, 4):
        v = buf[i]
        return v, v, v
    return buf[i], buf[i + 1], buf[i + 2]


def hexa(rgb):
    return '#%02x%02x%02x' % rgb


if __name__ == '__main__':
    caminho = sys.argv[1]
    w, h, canais, ctype, pal, buf = decode(caminho)
    print(f'{caminho.split("/")[-1]}  {w}x{h}')
    for nome, fx, fy in [(a, b, c) for a, b, c in
                         [(s, float(x), float(y)) for s, x, y in
                          (arg.split(':') for arg in sys.argv[2:])]]:
        x, y = int(w * fx), int(h * fy)
        print(f'  {nome:22} ({fx:.2f},{fy:.2f}) -> {hexa(pixel(w, canais, ctype, pal, buf, x, y))}')

    # cores dominantes, amostradas em grade
    c = Counter()
    for y in range(0, h, max(1, h // 120)):
        for x in range(0, w, max(1, w // 120)):
            c[pixel(w, canais, ctype, pal, buf, x, y)] += 1
    print('  dominantes:', ', '.join(hexa(k) for k, _ in c.most_common(6)))
