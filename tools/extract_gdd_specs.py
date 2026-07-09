#!/usr/bin/env python3
"""
Extrai as tabelas de custo/tempo do GDD (§4.2 e §4.3) para JSON.

A regra de ouro do projeto proíbe digitar valores à mão. Este script é a única
fonte do seed: ele lê o HTML do GDD e emite database/seeders/data/building_specs.json.
O teste em tests/Gdd/ roda de novo e compara com o que está no banco.

Cuidados que o formato do GDD exige:
  - "1.413" é 1413 (ponto = separador de milhar), não 1,413.
  - As tabelas de 10 níveis e as de veículos não têm linha de Tempo.
  - Custo confere com half-up(base * 1.65^(n-1)); tempo NÃO confere com curva alguma.
"""
import html
import json
import re
import sys
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path

TIME_ROW = "Tempo (min)"


def slug(nome: str) -> str:
    s = nome.lower()
    for a, b in [("á", "a"), ("â", "a"), ("ã", "a"), ("é", "e"), ("ê", "e"),
                 ("í", "i"), ("ó", "o"), ("ô", "o"), ("õ", "o"), ("ú", "u"), ("ç", "c")]:
        s = s.replace(a, b)
    s = re.sub(r"[^a-z0-9]+", "_", s)
    return s.strip("_")


def to_int(cell: str) -> int:
    # "1.413" -> 1413. O ponto é separador de milhar no GDD.
    return int(cell.strip().replace(".", ""))


def html_to_rows(path: Path) -> list[str]:
    t = path.read_text(encoding="utf-8", errors="replace")
    t = re.sub(r"(?is)<(script|style)\b.*?</\1>", " ", t)
    t = re.sub(r"(?i)</(p|div|tr|h[1-6]|li|table|section)>", "\n", t)
    t = re.sub(r"(?i)<br\s*/?>", "\n", t)
    t = re.sub(r"(?i)</t[dh]>", " | ", t)
    t = re.sub(r"<[^>]+>", "", t)
    t = html.unescape(t)
    t = re.sub(r"[ \t]+", " ", t)
    return [ln.strip() for ln in t.split("\n") if ln.strip()]


def slice_section(rows, start_pat, end_pat):
    # Cada título aparece duas vezes: uma no índice, outra no corpo. Queremos o corpo,
    # ou seja, a ÚLTIMA ocorrência do início — a primeira devolve um trecho do índice.
    starts = [i for i, r in enumerate(rows) if re.match(start_pat, r)]
    if not starts:
        raise SystemExit(f"secao nao encontrada: {start_pat}")
    a = starts[-1]
    b = next((i for i, r in enumerate(rows) if i > a and re.match(end_pat, r)), len(rows))
    return rows[a + 1:b]


def parse_tables(rows):
    """Um bloco = título (linha sem '|') seguido de linhas 'Rótulo | v1 | v2 | ...'."""
    out, titulo, corpo = [], None, []
    for r in rows:
        if "|" not in r:
            if titulo and corpo:
                out.append((titulo, corpo))
            titulo, corpo = r, []
        elif titulo:
            corpo.append(r)
    if titulo and corpo:
        out.append((titulo, corpo))
    return out


def build(titulo, corpo):
    nome = re.sub(r"\s*—.*$", "", titulo).strip()  # "Central de Transportes — 10 níveis"
    linhas = {}
    for r in corpo:
        partes = [c.strip() for c in r.split("|")]
        rotulo, vals = partes[0], [c for c in partes[1:] if c]
        linhas[rotulo] = vals

    nivel_row = next((k for k in linhas if k.startswith("Nível")), None)
    if not nivel_row:
        return None
    n_niveis = len(linhas[nivel_row])

    tempo_key = next((k for k in linhas if k.startswith(TIME_ROW)), None)
    tempos = [to_int(v) for v in linhas[tempo_key]] if tempo_key else None

    custos = {}
    for rotulo, vals in linhas.items():
        if rotulo.startswith("Nível") or rotulo.startswith(TIME_ROW):
            continue
        if len(vals) != n_niveis:
            continue
        custos[slug(rotulo)] = [to_int(v) for v in vals]

    níveis = []
    for i in range(n_niveis):
        níveis.append({
            "level": i + 1,
            "build_time_seconds": tempos[i] * 60 if tempos else None,
            "cost": {k: v[i] for k, v in custos.items()},
        })
    return {"type": slug(nome), "nome": nome, "max_level": n_niveis, "levels": níveis}


def half_up(x: float) -> int:
    return int(Decimal(str(x)).quantize(Decimal("1"), rounding=ROUND_HALF_UP))


def is_num(cell: str) -> bool:
    return bool(re.fullmatch(r"[\d.,]+", cell.strip()))


def to_micro(cell: str) -> int:
    """'0,0253' Fert$ -> 25300 micro-Fert$. Vírgula é separador decimal no GDD."""
    d = Decimal(cell.strip().replace(".", "").replace(",", "."))
    return int((d * 1_000_000).quantize(Decimal("1"), rounding=ROUND_HALF_UP))


# Classe tributária. §8.3 dá as alíquotas; §22.2 dá os tiers de primário/secundário.
# Metal Bruto e os 8 minerais eletrônicos NÃO são classificados pelo GDD:
# decisão do usuário em 2026-07-08, registrada em docs/decisoes.md D-04.
TAX_BPS = {"primario": 300, "secundario": 200, "raro": 100}


def mina_local_prod_max(rows) -> int:
    """Produção/hora do Metal Bruto no nível máximo da Mina Local (§19)."""
    corpo = slice_section(rows, r"^Mina Local — Produção", r"^Mina Governamental")
    linha = next(r for r in corpo if r.startswith("Produção/hora"))
    vals = [c.strip() for c in linha.split("|")[1:] if c.strip()]
    return to_int(vals[-1])


def parse_resources(rows):
    recursos = {}

    def tabela(inicio, fim):
        return slice_section(rows, inicio, fim)

    # §22.2 traz o tier explícito na 2ª coluna.
    for r in tabela(r"^22\.2 Preços-Base", r"^22\.3 Preços-Base"):
        p = [c.strip() for c in r.split("|") if c.strip()]
        if len(p) != 4 or p[1] not in ("Primário", "Secundário"):
            continue
        if not (is_num(p[2]) and is_num(p[3])):
            continue
        recursos[slug(p[0])] = {
            "code": slug(p[0]), "nome": p[0],
            "tax_class": "primario" if p[1] == "Primário" else "secundario",
            "producao_max_hora": to_int(p[2]), "preco_base_micro": to_micro(p[3]),
        }

    for r in tabela(r"^22\.3 Preços-Base", r"^22\.4 Preços-Base"):
        p = [c.strip() for c in r.split("|") if c.strip()]
        if len(p) != 3 or p[0] == "Mineral" or not (is_num(p[1]) and is_num(p[2])):
            continue
        recursos[slug(p[0])] = {
            "code": slug(p[0]), "nome": p[0], "tax_class": "secundario",  # D-04
            "producao_max_hora": to_int(p[1]), "preco_base_micro": to_micro(p[2]),
        }

    for r in tabela(r"^22\.4 Preços-Base", r"^22\.5 "):
        p = [c.strip() for c in r.split("|") if c.strip()]
        if len(p) != 3 or p[0].startswith("Recurso") or not (is_num(p[1]) and is_num(p[2])):
            continue
        recursos[slug(p[0])] = {
            "code": slug(p[0]), "nome": p[0], "tax_class": "raro",
            "producao_max_hora": to_int(p[1]), "preco_base_micro": to_micro(p[2]),
        }

    # Metal Bruto não aparece em nenhuma tabela de preço-base (§22.2/22.3/22.4).
    # §24.8 dá a fórmula para primários e brutos, e lista Metal Bruto entre os aplicáveis:
    #     Preço(r) = Preço(Oxigênio) × (ProdMáx/h Oxigênio ÷ ProdMáx/h r)
    # "ProdMáx/h" é o valor de nível 5 das tabelas de §19. A fórmula reproduz Água,
    # Biomassa e Energia exatamente como o GDD as publica — daí a confiança nela.
    # O preço resultante é DERIVADO, não publicado: fica marcado como tal. Ver D-04.
    ox = recursos["oxigenio"]
    prod_metal = mina_local_prod_max(rows)
    preco = (Decimal(ox["preco_base_micro"]) / 1_000_000) * Decimal(ox["producao_max_hora"]) / Decimal(prod_metal)
    preco_4c = preco.quantize(Decimal("0.0001"), rounding=ROUND_HALF_UP)  # o GDD publica 4 casas
    recursos["metal_bruto"] = {
        "code": "metal_bruto", "nome": "Metal Bruto", "tax_class": "primario",
        "producao_max_hora": prod_metal,
        "preco_base_micro": int(preco_4c * 1_000_000),
        "preco_base_derivado": True,
    }

    for r in recursos.values():
        r.setdefault("preco_base_derivado", False)

    for r in recursos.values():
        r["tax_bps"] = TAX_BPS[r["tax_class"]]
    return sorted(recursos.values(), key=lambda x: x["code"])


def main():
    gdd = Path(sys.argv[1])
    dest = Path(sys.argv[2])
    dest_res = Path(sys.argv[3]) if len(sys.argv) > 3 else None
    rows = html_to_rows(gdd)

    corpo = slice_section(rows, r"^4\.2 Construções essenciais", r"^1\. Matriz de cobertura")
    tabelas = [t for t in (build(n, c) for n, c in parse_tables(corpo)) if t]
    # descarta blocos de prosa que não são tabela de nível
    tabelas = [t for t in tabelas if t["levels"] and t["levels"][0]["cost"]]

    # Conferência da curva de custo: half-up(base * 1.65^(n-1)).
    divergencias = []
    for t in tabelas:
        for recurso in t["levels"][0]["cost"]:
            base = t["levels"][0]["cost"][recurso]
            for lv in t["levels"]:
                esperado = half_up(base * 1.65 ** (lv["level"] - 1))
                real = lv["cost"][recurso]
                if esperado != real:
                    divergencias.append(f'{t["type"]}.{recurso} n{lv["level"]}: GDD={real} formula={esperado}')

    sem_tempo = [t["type"] for t in tabelas if t["levels"][0]["build_time_seconds"] is None]

    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_text(json.dumps(tabelas, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"tabelas extraidas: {len(tabelas)}")
    print(f"divergencias custo x curva 1.65: {len(divergencias)}")
    for d in divergencias[:10]:
        print("  ! " + d)
    print(f"tabelas SEM linha de tempo: {len(sem_tempo)}")
    for s in sem_tempo:
        print("  - " + s)

    if dest_res:
        recursos = parse_resources(rows)
        dest_res.write_text(json.dumps(recursos, ensure_ascii=False, indent=2), encoding="utf-8")
        print(f"\nrecursos extraidos: {len(recursos)}")
        for cls in ("primario", "secundario", "raro"):
            nomes = [r["code"] for r in recursos if r["tax_class"] == cls]
            print(f"  {cls:11} ({TAX_BPS[cls]/100:.0f}%): {len(nomes)} -> {', '.join(nomes)}")

        # Todo recurso citado nas tabelas de custo precisa existir no catálogo,
        # senão as FKs de resources/ledger/tax_events quebram em runtime.
        usados = {k for t in tabelas for k in t["levels"][0]["cost"]}
        faltando = sorted(usados - {r["code"] for r in recursos})
        print(f"  recursos usados em custos e AUSENTES do catalogo: {faltando or 'nenhum'}")


if __name__ == "__main__":
    main()
