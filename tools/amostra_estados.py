#!/usr/bin/env python3
"""
Procura no deck as cores de ESTADO que a paleta atual não tem: verde (sucesso),
vermelho (perigo) e âmbar (aviso).

Mesmo princípio do tools/sample_png.py: cor não se escolhe a olho. Se o deck usa
um verde, é ele que entra no design system — não um verde qualquer do Tailwind.
"""
import colorsys
import glob
import sys
from collections import Counter

sys.path.insert(0, '/home/fertways/apps/fertways/tools')
from sample_png import decode, pixel, hexa

# Famílias de matiz que a paleta atual não cobre. rust/ember vivem em 10°-35°.
FAMILIAS = {
    'sucesso (verde)': (75, 175),
    'perigo (vermelho)': (345, 361),   # e 0-9, tratado abaixo
    'aviso (âmbar/ouro)': (36, 60),
    'informação (azul/ciano)': (176, 260),
}

contagens = {nome: Counter() for nome in FAMILIAS}

for caminho in sorted(glob.glob('/home/fertways/pitch/*.png')):
    w, h, canais, ctype, pal, buf = decode(caminho)
    passo_y = max(1, h // 200)
    passo_x = max(1, w // 200)
    for y in range(0, h, passo_y):
        for x in range(0, w, passo_x):
            r, g, b = pixel(w, canais, ctype, pal, buf, x, y)
            mat, lum, sat = colorsys.rgb_to_hls(r / 255, g / 255, b / 255)
            mat *= 360
            # Só cor de verdade: saturada o bastante para ser intencional e não
            # um cinza levemente colorido, e nem quase-preta nem quase-branca.
            if sat < 0.30 or not (0.20 < lum < 0.75):
                continue
            for nome, (lo, hi) in FAMILIAS.items():
                dentro = lo <= mat < hi or (nome.startswith('perigo') and mat < 9)
                if dentro:
                    # Quantiza para agrupar tons vizinhos do mesmo gradiente.
                    q = (r // 12 * 12, g // 12 * 12, b // 12 * 12)
                    contagens[nome][q] += 1

print(f'{"família":26} {"achados":>9}   cores mais frequentes')
print('-' * 78)
for nome in FAMILIAS:
    c = contagens[nome]
    total = sum(c.values())
    topo = ', '.join(f'{hexa(k)}({v})' for k, v in c.most_common(4))
    print(f'{nome:26} {total:>9}   {topo or "— nenhuma —"}')
