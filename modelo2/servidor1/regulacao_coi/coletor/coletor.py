"""
Coletor - Regulação COI (Modelo 2) | Servidor 1
===============================================
Mesma dinâmica do Controle de Equipes:

  A cada ciclo (padrão 5 min):
    1. ESPELHO: copia do servidor 2 para o servidor 1 os registros gravados
       pelo PHP (data/registros.json, data/ajustes_vigentes.json e
       data/csv/registros.csv) -> espelho/  (para relatórios e MySQL futuro).
  Uma vez por dia (ou com --publicar):
    2. baixa do Snowflake as consultas de sql/ (contrato do Modelo 2);
    3. monta a rede radial reduzida de cada alimentador e o cadastro de
       equipamentos (OLTC, BRT, BC) com a última posição do ADMS;
    4. grava em saida/publicado/ (servidor 1) e copia para
       \\\\servportaloper\\nucleo\\Regulacao_coi\\data\\publicado (servidor 2);
       o manifest.json vai por último.

Uso:
    python coletor.py                    # loop contínuo
    python coletor.py --uma-vez          # um ciclo e sai
    python coletor.py --publicar         # força a publicação neste ciclo
    python coletor.py --demo --uma-vez   # rede fictícia, sem Snowflake (testa a cópia)
"""
from __future__ import annotations

import argparse
import configparser
import json
import logging
import math
import os
import re
import shutil
import sys
import tempfile
import time
from datetime import datetime
from logging.handlers import RotatingFileHandler
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from rede import montar_rede  # noqa: E402

try:
    from zoneinfo import ZoneInfo
    TZ = ZoneInfo("America/Maceio")
except Exception:  # Windows sem tzdata
    TZ = None

BASE_DIR = Path(__file__).resolve().parent
CFG = configparser.ConfigParser()
CFG.read(BASE_DIR / "config.ini", encoding="utf-8")

log = logging.getLogger("coletor")
log.setLevel(logging.INFO)
_fmt = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s", "%d/%m %H:%M:%S")
_sh = logging.StreamHandler(sys.stdout); _sh.setFormatter(_fmt); log.addHandler(_sh)
(BASE_DIR / "logs").mkdir(exist_ok=True)
_fh = RotatingFileHandler(BASE_DIR / "logs" / "coletor.log", maxBytes=2_000_000, backupCount=5, encoding="utf-8")
_fh.setFormatter(_fmt); log.addHandler(_fh)

CONSULTAS = ["d01_subestacao", "d02_transformador_potencia", "d03_alimentador", "d04_segmento_mt", "d05_chave_mt",
             "d06_regulador_brt", "d07_banco_capacitor", "d08_carga_por_trafo", "d09_gd_por_ponto",
             "d10_ajuste_controle", "f02_posicao_tap", "f03_estado_bc"]
OPCIONAIS = {"d01_subestacao", "d05_chave_mt", "d07_banco_capacitor", "d09_gd_por_ponto",
             "d10_ajuste_controle", "f02_posicao_tap", "f03_estado_bc"}


def agora() -> datetime:
    return datetime.now(TZ) if TZ else datetime.now()


def pasta(chave: str, padrao: str) -> Path:
    return (BASE_DIR / CFG.get("saida", chave, fallback=padrao)).resolve()


# ================================================================== Snowflake
def conectar():
    """Mesmo esquema do Controle de Equipes: SSO (externalbrowser) ou par de chaves."""
    import snowflake.connector
    servidor = CFG.get("snowflake", "servidor")
    conta = re.sub(r"\.snowflakecomputing\.com/?$", "", servidor.replace("https://", ""))
    params = dict(
        user=CFG.get("snowflake", "login", fallback="").strip() or CFG.get("snowflake", "usuario"),
        account=conta,
        warehouse=CFG.get("snowflake", "warehouse"),
        authenticator="externalbrowser",
        client_store_temporary_credential=CFG.getboolean("snowflake", "cache_token", fallback=True),
        client_session_keep_alive=True,
        login_timeout=180,
    )
    role = CFG.get("snowflake", "role", fallback="").strip()
    if role:
        params["role"] = role
    chave = CFG.get("snowflake", "chave_privada", fallback="").strip()
    if chave:
        params.update(authenticator="SNOWFLAKE_JWT", private_key_file=str((BASE_DIR / chave).resolve()))
        senha = CFG.get("snowflake", "senha_chave", fallback="").strip()
        if senha:
            params["private_key_file_pwd"] = senha
    log.info("Conectando ao Snowflake (%s) [%s] ...", conta, params["authenticator"])
    conn = snowflake.connector.connect(**params)
    log.info("Conexão OK")
    return conn


def executar(conn, nome: str) -> list[dict]:
    sql = (BASE_DIR / "sql" / f"{nome}.sql").read_text(encoding="utf-8").strip().rstrip(";")
    t0 = time.time()
    cur = conn.cursor()
    try:
        cur.execute(sql)
        cols = [c[0].lower() for c in cur.description]
        linhas = [dict(zip(cols, l)) for l in cur.fetchall()]
    finally:
        cur.close()
    log.info("%s: %d linhas em %.0f s", nome, len(linhas), time.time() - t0)
    return linhas


def baixar(conn) -> dict[str, list[dict]]:
    dados = {}
    for nome in CONSULTAS:
        try:
            dados[nome] = executar(conn, nome)
        except Exception as e:
            if nome in OPCIONAIS:
                log.warning("%s: não executou (%s) — segue sem ela", nome, e)
                dados[nome] = []
            else:
                raise
    return dados


# ================================================================== montagem
def _f(v, padrao=0.0) -> float:
    try:
        return float(v) if v is not None and v != "" else padrao
    except (TypeError, ValueError):
        return padrao


def _sn(v) -> bool:
    return str(v or "").strip().upper() in ("S", "SIM", "1", "TRUE", "Y")


class Ids:
    """Ids inteiros estáveis por código (o PHP guarda ajustes vigentes por id)."""
    def __init__(self, arquivo: Path):
        self.arquivo = arquivo
        self.mapa = json.loads(arquivo.read_text(encoding="utf-8")) if arquivo.exists() else {}

    def de(self, tipo: str, codigo: str) -> int:
        chave = f"{tipo}:{codigo}"
        if chave not in self.mapa:
            self.mapa[chave] = max(self.mapa.values(), default=0) + 1
        return self.mapa[chave]

    def salvar(self):
        gravar_atomico(self.arquivo, json.dumps(self.mapa, ensure_ascii=False, indent=1))


def montar_publicacao(dados: dict, origem: str) -> tuple[dict, list[str]]:
    avisos: list[str] = []
    ids = Ids(BASE_DIR / "estado" / "ids.json")
    fc_medio = CFG.getfloat("rede", "fator_carga_medio", fallback=0.55)
    fp = CFG.getfloat("rede", "fator_potencia", fallback=0.92)
    fu_mt = CFG.getfloat("rede", "fator_utilizacao_mt", fallback=0.7)
    tg = math.tan(math.acos(fp))

    ses = [{k: s.get(k) for k in ("cod_se", "nome_se", "tipo_se", "tensoes_kv", "regional", "municipio")} for s in dados["d01_subestacao"]]
    nome_se = {s["cod_se"]: s["nome_se"] for s in ses}

    alims, por_trafo = [], {}
    for a in dados["d03_alimentador"]:
        aid = ids.de("AL", a["cod_alimentador"])
        alims.append({"id": aid, "codigo": a["cod_alimentador"], "nome": f"{a['cod_alimentador']} · {a.get('nome_alimentador') or ''}".strip(" ·"),
                      "cod_se": a.get("cod_se"), "subestacao": nome_se.get(a.get("cod_se"), a.get("cod_se")),
                      "tensao_nominal_kv": _f(a.get("tensao_nominal_kv"), 13.8), "_pac": a["pac_inicial"]})
        por_trafo.setdefault(a.get("cod_trafo"), []).append(aid)
    id_alim = {a["codigo"]: a["id"] for a in alims}

    # Última posição/estado vindo do ADMS e ajustes do relé
    tap = {}
    for t in sorted(dados["f02_posicao_tap"], key=lambda x: str(x.get("data_hora"))):
        tap[t["cod_equipamento"]] = t
    est_bc = {}
    for b in sorted(dados["f03_estado_bc"], key=lambda x: str(x.get("data_hora"))):
        est_bc[b["cod_bc"]] = b
    ctrl = {c["cod_equipamento"]: c for c in dados["d10_ajuste_controle"]}

    def controle(eq, cod):
        c = ctrl.get(cod)
        if c:
            eq.update(modo=str(c.get("modo") or "AUTO").upper(), v_ref_pu=_f(c.get("tensao_referencia_pu"), 1.0),
                      banda_pu=_f(c.get("banda_percent"), 2.0) / 100, temporizacao_s=int(_f(c.get("temporizacao_s"), 60)),
                      ldc_r_v=_f(c.get("ldc_r_v")), ldc_x_v=_f(c.get("ldc_x_v")))
        else:
            eq.update(modo="AUTO", v_ref_pu=1.0, banda_pu=0.02, temporizacao_s=60, ldc_r_v=0.0, ldc_x_v=0.0)
        t = tap.get(cod)
        if t:
            eq["tap_atual"] = int(_f(t.get("posicao_tap")))
            eq["atualizado_em"] = str(t.get("data_hora"))[:19].replace("T", " ")

    eqs = []
    for t in dados["d02_transformador_potencia"]:
        if not _sn(t.get("possui_oltc")) or t["cod_trafo"] not in por_trafo:
            continue
        mva = _f(t.get("potencia_nominal_mva"), 25)
        z = _f(t.get("impedancia_percent"), 10)
        r = _f(t.get("perdas_cobre_kw")) / (mva * 1000) * 100 if t.get("perdas_cobre_kw") else 0.5
        eq = {"id": ids.de("EQ", t["cod_trafo"]), "tipo": "TRAFO_SE", "codigo": t["cod_trafo"], "cod_se": t.get("cod_se"),
              "alimentadores": por_trafo[t["cod_trafo"]], "no_codigo": "",
              "descricao": f"Trafo {num(t.get('tensao_primaria_kv'))}/{num(t.get('tensao_secundaria_kv'))} kV {num(mva)} MVA · OLTC",
              "sn_kva": mva * 1000, "vk_percent": z, "vkr_percent": round(r, 3),
              "tensao_primaria_kv": _f(t.get("tensao_primaria_kv"), 69), "tensao_secundaria_kv": _f(t.get("tensao_secundaria_kv"), 13.8),
              "tap_min": int(_f(t.get("tap_min"), -8)), "tap_max": int(_f(t.get("tap_max"), 8)), "tap_neutro": int(_f(t.get("tap_neutro"))),
              "tap_passo_percent": _f(t.get("passo_percent"), 1.25), "tap_atual": 0, "telecomandado": True, "atualizado_em": None}
        controle(eq, t["cod_trafo"])
        eqs.append(eq)
    for b in dados["d06_regulador_brt"]:
        if b.get("cod_alimentador") not in id_alim:
            continue
        faixa, deg = _f(b.get("faixa_percent"), 10), int(_f(b.get("n_degraus"), 32))
        meio = deg // 2
        eq = {"id": ids.de("EQ", b["cod_brt"]), "tipo": "REGULADOR", "codigo": b["cod_brt"], "alimentador_id": id_alim[b["cod_alimentador"]],
              "no_codigo": str(b["pac_carga"]), "descricao": f"BRT {int(_f(b.get('n_unidades'), 3))} × {num(b.get('potencia_unidade_kva'))} kVA · {deg} degraus ±{num(faixa)}%",
              "sn_kva": _f(b.get("potencia_unidade_kva")) * _f(b.get("n_unidades"), 3), "vk_percent": 0, "vkr_percent": 0,
              "tensao_primaria_kv": 0, "tensao_secundaria_kv": 0, "tap_min": -meio, "tap_max": meio, "tap_neutro": 0,
              "tap_passo_percent": round(faixa / meio, 4) if meio else 0.625, "tap_atual": 0,
              "modo_reverso": b.get("modo_fluxo_reverso"), "telecomandado": _sn(b.get("telecomandado")), "atualizado_em": None}
        controle(eq, b["cod_brt"])
        eqs.append(eq)
    for c in dados["d07_banco_capacitor"]:
        if c.get("cod_alimentador") not in id_alim:
            continue
        n = max(1, int(_f(c.get("n_estagios"), 1)))
        e = est_bc.get(c["cod_bc"])
        eqs.append({"id": ids.de("EQ", c["cod_bc"]), "tipo": "BC", "codigo": c["cod_bc"], "alimentador_id": id_alim[c["cod_alimentador"]],
                    "no_codigo": str(c["pac"]), "descricao": f"Banco de capacitores {n} × {num(_f(c.get('kvar_total')) / n)} kvar · {c.get('tipo_controle') or ''}".strip(" ·"),
                    "tensao_nominal_kv": _f(c.get("tensao_nominal_kv"), 13.8), "kvar_estagio": _f(c.get("kvar_total")) / n, "n_estagios": n,
                    "estagios_ligados": int(_f(e.get("estagios_ligados"))) if e else 0, "tipo_controle": c.get("tipo_controle"),
                    "telecomandado": _sn(c.get("telecomandado")), "atualizado_em": str(e.get("data_hora"))[:19].replace("T", " ") if e else None})

    # Redes reduzidas
    redes = {}
    for a in alims:
        cod = a["codigo"]
        cargas = {}
        for c in dados["d08_carga_por_trafo"]:
            if c.get("cod_alimentador") != cod:
                continue
            if c.get("demanda_contratada_kw"):
                kw = _f(c["demanda_contratada_kw"]) * fu_mt
            else:
                kw = _f(c.get("energia_media_mensal_kwh")) / 730.0 / fc_medio
            p = cargas.setdefault(str(c["pac_mt"]), {"kw": 0.0, "kvar": 0.0, "ucs": 0})
            p["kw"] += kw; p["kvar"] += kw * tg; p["ucs"] += int(_f(c.get("n_ucs")))
            if c.get("nome"):
                p["nome"] = c["nome"]
        gd = {}
        for g in dados["d09_gd_por_ponto"]:
            if g.get("cod_alimentador") == cod and str(g.get("situacao") or "CONECTADA").upper() == "CONECTADA":
                gd.setdefault(str(g["pac_mt"]), {"kw": 0.0})["kw"] += _f(g.get("potencia_instalada_kw"))
        bcs = [{"pac": e["no_codigo"]} for e in eqs if e["tipo"] == "BC" and e.get("alimentador_id") == a["id"]]
        regs = [{"cod_brt": b["cod_brt"], "pac_fonte": b["pac_fonte"], "pac_carga": b["pac_carga"]}
                for b in dados["d06_regulador_brt"] if b.get("cod_alimentador") == cod]
        barra = dados.get("_carga_barra", {}).get(cod)
        nos, av = montar_rede({"codigo": cod, "pac_inicial": a["_pac"]},
                              [s for s in dados["d04_segmento_mt"] if s.get("cod_alimentador") == cod],
                              [c for c in dados["d05_chave_mt"] if c.get("cod_alimentador") == cod],
                              regs, cargas, gd, bcs, barra)
        avisos += av
        if nos:
            redes[cod] = nos
        else:
            avisos.append(f"{cod}: rede vazia, alimentador fora da publicação")
    for a in alims:
        a.pop("_pac", None)
    alims = [a for a in alims if a["codigo"] in redes]
    ids.salvar()
    pub = {"manifest": {"versao": agora().strftime("%Y%m%d%H%M%S"), "gerado_em": agora().strftime("%Y-%m-%d %H:%M:%S"),
                        "origem": origem, "safra_bdgd": CFG.get("rede", "safra_bdgd", fallback="") or None,
                        "alimentadores": len(alims), "equipamentos": len(eqs), "avisos": avisos[:50]},
           "subestacoes": ses, "alimentadores": alims, "equipamentos": eqs, "redes": redes}
    return pub, avisos


def num(v) -> str:
    x = _f(v)
    return str(int(x)) if x == int(x) else str(round(x, 2)).replace(".", ",")


# ================================================================== gravação
def gravar_atomico(destino: Path, corpo: str | bytes, tentativas: int = 6) -> None:
    """Temporário + troca: o PHP nunca lê arquivo pela metade (igual ao Controle de Equipes)."""
    destino.parent.mkdir(parents=True, exist_ok=True)
    dados = corpo.encode("utf-8") if isinstance(corpo, str) else corpo
    fd, tmp = tempfile.mkstemp(dir=destino.parent, prefix=".~" + destino.stem + "_", suffix=".tmp")
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(dados)
        for i in range(tentativas):
            try:
                os.replace(tmp, destino)
                return
            except PermissionError:
                if i == tentativas - 1:
                    raise
                time.sleep(0.5)
    finally:
        if os.path.exists(tmp):
            try:
                os.remove(tmp)
            except OSError:
                pass


def arquivos_publicacao(pub: dict) -> list[tuple[str, str]]:
    """(caminho relativo, conteúdo) — manifest.json sempre por último."""
    j = lambda x: json.dumps(x, ensure_ascii=False, indent=1, default=str)
    saida = [(f"rede/{re.sub(r'[^A-Za-z0-9_.-]', '_', cod)}.json", j(nos)) for cod, nos in pub["redes"].items()]
    saida += [("cadastro/subestacoes.json", j(pub["subestacoes"])), ("cadastro/alimentadores.json", j(pub["alimentadores"])),
              ("cadastro/equipamentos.json", j(pub["equipamentos"])), ("manifest.json", j(pub["manifest"]))]
    return saida


def publicar(pub: dict) -> str:
    """Grava no servidor 1 e copia para o servidor 2. Devolve '' ou a mensagem de erro do servidor 2."""
    arquivos = arquivos_publicacao(pub)
    local = pasta("publicado", "../saida/publicado")
    for rel, corpo in arquivos:
        gravar_atomico(local / rel, corpo)
    log.info("[servidor 1] publicação gravada em %s (%d alimentadores, %d equipamentos)", local,
             pub["manifest"]["alimentadores"], pub["manifest"]["equipamentos"])
    p2 = pasta_servidor2()
    if p2 is None:
        return ""
    try:
        if not p2.parent.exists():
            raise FileNotFoundError(f"pasta de dados do servidor 2 não encontrada ou sem acesso: {p2.parent}")
        for rel, corpo in arquivos:
            gravar_atomico(p2 / "publicado" / rel, corpo)
        log.info("[servidor 2] publicação enviada para %s", p2 / "publicado")
        return ""
    except Exception as e:
        msg = f"Falha ao publicar no servidor 2 ({p2}): {type(e).__name__}: {e}"
        log.error(msg)
        return msg


def pasta_servidor2() -> Path | None:
    if not CFG.getboolean("servidor2", "ativo", fallback=False):
        return None
    p = CFG.get("servidor2", "pasta_dados", fallback="").strip()
    return Path(p) if p else None


def espelhar_registros() -> str:
    """Traz do servidor 2 o que os usuários registraram (somente leitura do lado de lá)."""
    p2 = pasta_servidor2()
    if p2 is None:
        return ""
    destino = pasta("espelho", "../espelho")
    copiados = 0
    try:
        for rel in ("registros.json", "ajustes_vigentes.json", "csv/registros.csv", "csv/logs.csv"):
            origem = p2 / rel
            if origem.exists():
                gravar_atomico(destino / Path(rel).name, origem.read_bytes())
                copiados += 1
        log.info("[espelho] %d arquivo(s) de registros copiados do servidor 2", copiados)
        return ""
    except Exception as e:
        msg = f"Falha ao espelhar registros do servidor 2: {type(e).__name__}: {e}"
        log.error(msg)
        return msg


def gravar_status(ok: bool, mensagem: str = "", aviso: str = "") -> None:
    corpo = json.dumps({"ok": ok, "mensagem": mensagem[:500], "aviso": aviso[:500] or None,
                        "em": agora().strftime("%Y-%m-%d %H:%M:%S"), "epoch": int(time.time())}, ensure_ascii=False)
    try:
        gravar_atomico(pasta("publicado", "../saida/publicado") / "status_coletor.json", corpo)
        p2 = pasta_servidor2()
        if p2 is not None and p2.parent.exists():
            gravar_atomico(p2 / "publicado" / "status_coletor.json", corpo)
    except Exception as e:
        log.warning("Não foi possível gravar status_coletor.json: %s", e)


# ================================================================== demo
def dados_demo() -> dict:
    """Rede fictícia em segmentos 'crus' (com pontos intermediários sem carga e
    uma chave NA entre os alimentadores) para exercitar a redução. Depois de
    reduzida, fica igual à rede de exemplo embutida no PHP (DadosExemplo)."""
    ramos = {
        "CTR-01": ("BSE-CTR1", [("P01", "BSE-CTR1", .190, .390, 1.5, 530), ("P02", "P01", .190, .390, 2.5, 530), ("P03", "P02", .190, .390, 2.0, 530),
                                ("P04", "P03", .190, .390, 3.0, 530), ("P05", "P04", .368, .420, 2.5, 340), ("P06", "P05", .368, .420, 3.0, 340),
                                ("P07", "P06", .696, .450, 4.0, 230), ("R01", "P03", .696, .450, 3.5, 230), ("R02", "R01", .696, .450, 2.5, 230)]),
        "CTR-02": ("BSE-CTR2", [("Q01", "BSE-CTR2", .190, .390, 3.0, 530), ("Q02", "Q01", .368, .420, 4.0, 340), ("Q03", "Q02", .368, .420, 6.0, 340),
                                ("Q04", "Q03", .696, .450, 4.0, 230), ("Q05", "Q04", .696, .450, 3.0, 230)]),
    }
    cargas = {"P01": (350, 620, "Saída SE · Av. Brasil"), "P02": (600, 310, "Rótula Distrito Industrial"), "P03": (1000, 1, "Frigorífico (cliente A4)"),
              "P04": (500, 1450, "Bairro Jardim América"), "P05": (380, 980, "Jusante do RT-0142"), "P06": (340, 1120, "Vila São José"),
              "P07": (220, 540, "Fim de tronco · Granjas"), "R01": (400, 2, "Ramal Cerâmica Santa Rita"), "R02": (260, 860, "Ramal Loteamento Novo Horizonte"),
              "Q01": (400, 380, "Saída SE · Rodovia"), "Q02": (350, 720, "Comunidade Ponte Alta"), "Q03": (50, 1, "UFV Sol do Cerrado (GD 4 MW)"),
              "Q04": (250, 610, "Assentamento Boa Vista"), "Q05": (300, 95, "Fim de linha · Irrigantes")}
    seg, n = [], 0
    for alim, (raiz, lista) in ramos.items():
        for cod, pai, r, x, km, amp in lista:
            partes = 3 if km >= 3 else 2          # pontos intermediários sem carga
            fim = "P05_F" if cod == "P05" else cod   # o RT-0142 liga P05_F (fonte) a P05 (carga)
            pontos = [pai] + [f"{cod}_{i}" for i in range(1, partes)] + [fim]
            for a, b in zip(pontos, pontos[1:]):
                n += 1
                seg.append({"cod_segmento": f"S{n:04d}", "cod_alimentador": alim, "pac_1": a if n % 2 else b, "pac_2": b if n % 2 else a,
                            "comprimento_m": km * 1000 / partes, "r1_ohm_km": r, "x1_ohm_km": x, "ampacidade_a": amp, "fases": "ABC"})
    fp_tg = math.tan(math.acos(CFG.getfloat("rede", "fator_potencia", fallback=0.92)))
    fc = CFG.getfloat("rede", "fator_carga_medio", fallback=0.55)
    d08 = [{"cod_trafo_dist": f"T-{p}", "cod_alimentador": "CTR-01" if p[0] in "PR" else "CTR-02", "pac_mt": p, "n_ucs": u,
            "energia_media_mensal_kwh": kw * 730 * fc, "nome": nome} for p, (kw, u, nome) in cargas.items()]
    return {
        "d01_subestacao": [{"cod_se": "SE-CTR", "nome_se": "SE Centro 69/13,8 kV (demo)", "tipo_se": "DISTRIBUICAO", "tensoes_kv": "69/13.8",
                            "regional": "Leste", "municipio": "Maceió"}],
        "d02_transformador_potencia": [{"cod_trafo": "TR-01 SE CTR", "cod_se": "SE-CTR", "tensao_primaria_kv": 69, "tensao_secundaria_kv": 13.8,
                                        "potencia_nominal_mva": 26.6, "impedancia_percent": 10, "perdas_cobre_kw": 133, "possui_oltc": "S",
                                        "tap_min": -8, "tap_max": 8, "tap_neutro": 0, "passo_percent": 1.25}],
        "d03_alimentador": [{"cod_alimentador": "CTR-01", "nome_alimentador": "Distrito Industrial", "cod_se": "SE-CTR", "cod_trafo": "TR-01 SE CTR",
                             "tensao_nominal_kv": 13.8, "pac_inicial": "BSE-CTR1"},
                            {"cod_alimentador": "CTR-02", "nome_alimentador": "Zona Rural / Usina FV", "cod_se": "SE-CTR", "cod_trafo": "TR-01 SE CTR",
                             "tensao_nominal_kv": 13.8, "pac_inicial": "BSE-CTR2"}],
        "d04_segmento_mt": seg,
        "d05_chave_mt": [{"cod_chave": "CH-NA-01", "cod_alimentador": "CTR-01", "pac_1": "P07", "pac_2": "Q05", "estado_normal": "NA"}],
        "d06_regulador_brt": [{"cod_brt": "RT-0142", "cod_alimentador": "CTR-01", "pac_fonte": "P05_F", "pac_carga": "P05",
                               "potencia_unidade_kva": 167, "n_unidades": 3, "ligacao": "Estrela aterrada", "faixa_percent": 10,
                               "n_degraus": 32, "telecomandado": "N", "modo_fluxo_reverso": "BLOQUEIO"}],
        "d07_banco_capacitor": [{"cod_bc": "BC-0310", "local": "REDE", "cod_alimentador": "CTR-01", "pac": "P04", "tensao_nominal_kv": 13.8,
                                 "kvar_total": 1200, "n_estagios": 2, "tipo_controle": "TENSAO", "telecomandado": "S"},
                                {"cod_bc": "BC-0721", "local": "REDE", "cod_alimentador": "CTR-02", "pac": "Q02", "tensao_nominal_kv": 13.8,
                                 "kvar_total": 600, "n_estagios": 1, "tipo_controle": "FIXO", "telecomandado": "N"}],
        "d08_carga_por_trafo": d08,
        "d09_gd_por_ponto": [{"pac_mt": "Q03", "cod_alimentador": "CTR-02", "potencia_instalada_kw": 4000, "fonte": "SOLAR", "situacao": "CONECTADA"}],
        "d10_ajuste_controle": [{"cod_equipamento": "TR-01 SE CTR", "modo": "AUTO", "tensao_referencia_pu": 1.01, "banda_percent": 2, "temporizacao_s": 60},
                                {"cod_equipamento": "RT-0142", "modo": "AUTO", "tensao_referencia_pu": 1.00, "banda_percent": 2, "temporizacao_s": 45}],
        "f02_posicao_tap": [{"data_hora": agora().strftime("%Y-%m-%d 06:00:00"), "cod_equipamento": "TR-01 SE CTR", "posicao_tap": 0, "modo": "AUTO"}],
        "f03_estado_bc": [{"data_hora": agora().strftime("%Y-%m-%d 06:00:00"), "cod_bc": "BC-0721", "estagios_ligados": 1, "modo": "MANUAL"}],
        "_carga_barra": {"CTR-01": {"kw": 7000, "kvar": 2800, "ucs": 0, "nome": "Barra 13,8 kV SE Centro (eqv. demais alimentadores)"},
                         "CTR-02": {"kw": 9000, "kvar": 3600, "ucs": 0, "nome": "Barra 13,8 kV SE Centro (eqv. demais alimentadores)"}},
    }


# ================================================================== laço
def precisa_publicar() -> bool:
    m = pasta("publicado", "../saida/publicado") / "manifest.json"
    if not m.exists():
        return True
    horas = CFG.getfloat("coleta", "publicar_a_cada_horas", fallback=24)
    return time.time() - m.stat().st_mtime > horas * 3600


def ciclo(conn, demo: bool, forcar: bool) -> str:
    aviso = espelhar_registros()
    if forcar or precisa_publicar():
        dados = dados_demo() if demo else baixar(conn)
        if not demo:
            dados["_carga_barra"] = {}
        pub, avisos = montar_publicacao(dados, "demo" if demo else "snowflake")
        for a in avisos:
            log.warning(a)
        aviso = publicar(pub) or aviso
    return aviso


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--uma-vez", action="store_true", help="executa um ciclo e encerra")
    ap.add_argument("--demo", action="store_true", help="rede fictícia, sem Snowflake")
    ap.add_argument("--publicar", action="store_true", help="publica neste ciclo mesmo sem ter vencido o prazo")
    a = ap.parse_args()
    intervalo = CFG.getint("coleta", "intervalo", fallback=300)
    p2 = pasta_servidor2()
    log.info("[servidor 1] publicação local: %s", pasta("publicado", "../saida/publicado"))
    log.info("[servidor 2] %s", "desativado" if p2 is None else f"{p2} ({'acessível' if p2.exists() else 'NÃO ACESSÍVEL agora'})")
    conn, forcar = None, a.publicar or a.demo
    while True:
        inicio = time.time()
        try:
            if not a.demo and conn is None and (forcar or precisa_publicar()):
                conn = conectar()
            aviso = ciclo(conn, a.demo, forcar)
            forcar = False
            gravar_status(True, aviso=aviso)
        except KeyboardInterrupt:
            break
        except Exception as e:
            log.exception("Falha no ciclo: %s", e)
            gravar_status(False, f"{type(e).__name__}: {e}")
            try:
                conn and conn.close()
            except Exception:
                pass
            conn = None
        if a.uma_vez:
            break
        espera = max(5, intervalo - (time.time() - inicio))
        log.info("Próximo ciclo em %d s", espera)
        try:
            time.sleep(espera)
        except KeyboardInterrupt:
            break
    if conn:
        conn.close()
    log.info("Coletor encerrado.")


if __name__ == "__main__":
    main()
