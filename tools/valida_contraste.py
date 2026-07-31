#!/usr/bin/env python3
"""
Valida o contraste do design system (A2.V1) contra a WCAG 2.1.

Não é um relatório: **falha com status 1** se alguma combinação que o sistema
promete usar deixar de passar. Roda em segundo, sem dependência nenhuma, e lê as
cores do próprio `frontend/src/index.css` — se alguém mudar um token lá, o
número aqui muda junto e o teste acusa.

    python3 tools/valida_contraste.py

Por que isto existe: a superfície do FERTWAYS é clara e quente, e nessa faixa é
fácil escolher um par bonito que reprova. O `rust` da marca, por exemplo, passa
por 4,58:1 sobre a areia — passa, mas com folga de 0,08. Não é o tipo de margem
que se defende de memória.
"""
import pathlib
import re
import sys

RAIZ = pathlib.Path(__file__).resolve().parent.parent
CSS = RAIZ / 'frontend' / 'src' / 'index.css'

# ---------------------------------------------------------------- contraste


def _rgb(h):
    h = h.lstrip('#')
    if len(h) == 8:  # #rrggbbaa — a opacidade não entra no cálculo
        h = h[:6]
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def _luminancia(h):
    def canal(v):
        v /= 255
        return v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4

    r, g, b = _rgb(h)
    return 0.2126 * canal(r) + 0.7152 * canal(g) + 0.0722 * canal(b)


def contraste(a, b):
    la, lb = _luminancia(a), _luminancia(b)
    return (max(la, lb) + 0.05) / (min(la, lb) + 0.05)


# ------------------------------------------------------------------ tokens


def tokens():
    """Lê `--color-*` do bloco @theme do index.css."""
    texto = CSS.read_text(encoding='utf-8')
    achados = dict(re.findall(r'--color-([a-z-]+):\s*(#[0-9a-fA-F]{3,8})\s*;', texto))
    if not achados:
        sys.exit(f'ABORTADO: nenhum token de cor encontrado em {CSS}')
    return achados


# -------------------------------------------------------------------- regras
#
# (texto, fundo, mínimo, por que este par existe no produto)
#
# 4.5 = AA para texto normal. 3.0 = AA para texto grande (>=24px) e para
# elementos não textuais, como a borda de um campo.

PARES = [
    ('ink', 'sand', 4.5, 'texto corrido sobre a superfície principal'),
    ('ink', 'sand-light', 4.5, 'texto corrido dentro de cartão'),
    ('ink-soft', 'sand', 4.5, 'texto secundário'),
    ('ink-soft', 'sand-light', 4.5, 'texto secundário dentro de cartão'),
    ('rust', 'sand', 4.5, 'link e rótulo de marca'),
    ('rust', 'sand-light', 4.5, 'link dentro de cartão'),
    ('sucesso', 'sand', 4.5, 'Selo tom claro'),
    ('perigo', 'sand', 4.5, 'Selo tom claro / rótulo de Erro'),
    ('info', 'sand', 4.5, 'Selo tom claro'),
    ('sand-light', 'rust', 4.5, 'Botao variante primária'),
    ('sand-light', 'perigo', 4.5, 'Botao variante perigo'),
    ('sand-light', 'sucesso', 4.5, 'Selo tom forte'),
    ('sand-light', 'info', 4.5, 'Selo tom forte'),
    ('ink', 'ember', 4.5, 'Selo aviso — ember SÓ serve como fundo'),
]

# Combinações que o sistema proíbe. Se um dia passarem, a proibição virou
# folclore e o comentário que a explica precisa sair junto.
PROIBIDOS = [
    ('ink', 'rust', 'fundo rust pede texto claro, não escuro'),
    ('ember', 'sand', 'ember como TEXTO — é decorativo, nunca letra'),
    ('sand-light', 'ember', 'ember com texto claro'),
]


def main():
    cor = tokens()
    falhas = []

    print(f'{"par":42} {"medido":>8} {"mínimo":>8}  para quê')
    print('-' * 100)
    for texto, fundo, minimo, motivo in PARES:
        if texto not in cor or fundo not in cor:
            falhas.append(f'token ausente: {texto} ou {fundo}')
            continue
        c = contraste(cor[texto], cor[fundo])
        ok = c >= minimo
        if not ok:
            falhas.append(f'{texto} sobre {fundo}: {c:.2f} < {minimo} ({motivo})')
        print(f'{texto + " sobre " + fundo:42} {c:>7.2f}{"✓" if ok else "✗"} {minimo:>8.1f}  {motivo}')

    print()
    print('Proibidos (têm que continuar reprovando):')
    for texto, fundo, motivo in PROIBIDOS:
        c = contraste(cor[texto], cor[fundo])
        if c >= 4.5:
            falhas.append(
                f'{texto} sobre {fundo} passou ({c:.2f}) — a proibição "{motivo}" perdeu a razão'
            )
        print(f'  {texto} sobre {fundo}: {c:.2f} — {motivo}')

    print()
    if falhas:
        print(f'REPROVADO — {len(falhas)} problema(s):')
        for f in falhas:
            print(f'  ✗ {f}')
        return 1

    print(f'OK — {len(PARES)} pares aprovados, {len(PROIBIDOS)} proibições intactas.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
