"""
Montagem da rede radial reduzida a partir dos segmentos da BDGD.

Entrada (por alimentador): segmentos MT (SSDMT + SEGCON), chaves (UNSEMT),
reguladores (UNREMT), cargas por ponto (UCBT/UCMT agregadas), GD por ponto
e bancos de capacitores.

Saída: lista de nós no formato que o PHP do servidor 2 lê
(data/publicado/rede/<alimentador>.json) — cada nó carrega o trecho que o
liga ao nó pai já com R e X equivalentes.

Passos:
  1. grafo não orientado: segmentos + chaves normalmente fechadas (impedância
     nula) + reguladores (impedância nula; a relação de tap entra no PHP);
     chaves normalmente abertas ficam de fora;
  2. busca em largura a partir do PAC inicial do alimentador (CTMT.PAC_INI),
     o que orienta cada trecho da SE para a carga; arestas que fechariam
     malha são descartadas e contadas como aviso;
  3. redução: só ficam como nós a barra da SE, pontos com carga, GD ou BC,
     os dois lados de cada regulador, derivações e fins de linha. Trechos em
     série entre eles viram um só (R e X somados, ampacidade = menor).
"""
from __future__ import annotations

from collections import defaultdict, deque


def montar_rede(alimentador: dict, segmentos: list[dict], chaves: list[dict], reguladores: list[dict],
                cargas: dict, gd: dict, capacitores: list[dict], carga_barra: dict | None = None) -> tuple[list[dict], list[str]]:
    avisos: list[str] = []
    raiz = str(alimentador["pac_inicial"])
    adj: dict[str, list[tuple[str, dict]]] = defaultdict(list)

    def ligar(a, b, dados):
        a, b = str(a), str(b)
        if not a or not b or a == b:
            return
        adj[a].append((b, dados))
        adj[b].append((a, dados))

    for s in segmentos:
        comp = float(s.get("comprimento_m") or 0) / 1000.0
        ligar(s["pac_1"], s["pac_2"], {"r": float(s.get("r1_ohm_km") or 0) * comp, "x": float(s.get("x1_ohm_km") or 0) * comp,
                                       "km": comp, "amp": float(s.get("ampacidade_a") or 0)})
    for c in chaves:
        if str(c.get("estado_normal", "NF")).upper() == "NF":
            ligar(c["pac_1"], c["pac_2"], {"r": 0.0, "x": 0.0, "km": 0.0, "amp": 0.0})
    for r in reguladores:
        ligar(r["pac_fonte"], r["pac_carga"], {"r": 0.0, "x": 0.0, "km": 0.0, "amp": 0.0, "regulador": r["cod_brt"]})

    if raiz not in adj:
        return [], [f"{alimentador['codigo']}: PAC inicial {raiz} não aparece nos segmentos"]

    # ---- 2. busca em largura (orientação SE -> carga) ----
    pai: dict[str, str | None] = {raiz: None}
    trecho: dict[str, dict] = {}
    filhos: dict[str, list[str]] = defaultdict(list)
    fila = deque([raiz])
    malhas = 0
    while fila:
        u = fila.popleft()
        for v, d in adj[u]:
            if v == pai[u]:
                continue
            if v in pai:
                malhas += 1
                continue
            pai[v] = u
            trecho[v] = d
            filhos[u].append(v)
            fila.append(v)
    if malhas:
        avisos.append(f"{alimentador['codigo']}: {malhas // 2 or 1} ligação(ões) fechando malha foram ignoradas (confira o estado das chaves)")

    # ---- 3. nós que ficam ----
    importantes = {raiz}
    for p in list(cargas) + list(gd):
        if p in pai:
            importantes.add(p)
    for c in capacitores:
        if str(c["pac"]) in pai:
            importantes.add(str(c["pac"]))
    for r in reguladores:
        importantes.update({str(r["pac_fonte"]), str(r["pac_carga"])})
    for n in pai:
        if len(filhos[n]) != 1:   # derivação (>=2) ou fim de linha (0)
            importantes.add(n)

    nos: list[dict] = []
    ordem = deque([raiz])
    visitados = set()
    while ordem:
        n = ordem.popleft()
        if n in visitados:
            continue
        visitados.add(n)
        if n in importantes:
            if n == raiz:
                nos.append(_no(n, None, 0, 0, 0, 0, cargas.get(n) or carga_barra, gd.get(n)))
            else:
                r = x = km = 0.0
                amp = float("inf")
                cur = n
                while True:
                    d = trecho[cur]
                    r += d["r"]; x += d["x"]; km += d["km"]
                    if d["amp"] > 0:
                        amp = min(amp, d["amp"])
                    cur = pai[cur]
                    if cur in importantes:
                        break
                nos.append(_no(n, cur, r, x, km, 0 if amp == float("inf") else amp, cargas.get(n), gd.get(n)))
        ordem.extend(filhos[n])

    sem_ligacao = [p for p in list(cargas) + list(gd) if p not in pai]
    if sem_ligacao:
        avisos.append(f"{alimentador['codigo']}: {len(sem_ligacao)} ponto(s) com carga/GD fora da árvore (PAC sem segmento ligado)")
    return nos, avisos


def _no(pac, pai, r, x, km, amp, carga, gd):
    carga = carga or {}
    # O PHP calcula R = r_ohm_km × comprimento_km: guarda a média por km
    return {
        "codigo": pac,
        "nome": carga.get("nome") or (gd or {}).get("nome") or pac,
        "no_pai_codigo": pai,
        "r_ohm_km": round(r / km, 5) if km > 0 else 0.0,
        "x_ohm_km": round(x / km, 5) if km > 0 else 0.0,
        "comprimento_km": round(km, 4),
        "ampacidade_a": round(amp, 1),
        "carga_kw": round(float(carga.get("kw", 0)), 1),
        "carga_kvar": round(float(carga.get("kvar", 0)), 1),
        "gd_kw": round(float((gd or {}).get("kw", 0)), 1),
        "num_consumidores": int(carga.get("ucs", 0)),
    }
