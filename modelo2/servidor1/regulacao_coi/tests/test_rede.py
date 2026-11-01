"""Testes da montagem da rede reduzida. Rodar: python -m pytest -q modelo2/servidor1"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "coletor"))
from rede import montar_rede  # noqa: E402

AL = {"codigo": "AL-1", "pac_inicial": "SE"}


def seg(a, b, m, r=0.2, x=0.4, amp=500):
    return {"pac_1": a, "pac_2": b, "comprimento_m": m, "r1_ohm_km": r, "x1_ohm_km": x, "ampacidade_a": amp}


def test_trechos_em_serie_sem_carga_viram_um_so():
    s = [seg("SE", "a", 1000), seg("a", "b", 500, amp=300), seg("b", "C1", 500)]
    nos, avisos = montar_rede(AL, s, [], [], {"C1": {"kw": 100, "kvar": 40, "ucs": 10}}, {}, [])
    assert [n["codigo"] for n in nos] == ["SE", "C1"]
    c1 = nos[1]
    assert c1["no_pai_codigo"] == "SE" and c1["comprimento_km"] == 2.0
    assert abs(c1["r_ohm_km"] * c1["comprimento_km"] - 0.4) < 1e-9   # R total = 0,2 Ω/km × 2 km
    assert c1["ampacidade_a"] == 300                                   # o menor cabo do caminho
    assert not avisos


def test_orienta_a_partir_da_se_mesmo_com_pacs_invertidos():
    s = [seg("a", "SE", 1000), seg("C1", "a", 1000)]
    nos, _ = montar_rede(AL, s, [], [], {"C1": {"kw": 1}}, {}, [])
    assert nos[1]["no_pai_codigo"] == "SE"


def test_chave_na_fica_fora_e_nf_entra():
    s = [seg("SE", "a", 1000), seg("x", "C2", 1000)]
    ch = [{"pac_1": "a", "pac_2": "x", "estado_normal": "NA"}]
    nos, avisos = montar_rede(AL, s, ch, [], {"C2": {"kw": 1}}, {}, [])
    assert "C2" not in [n["codigo"] for n in nos] and avisos   # sem ligação: avisa
    ch[0]["estado_normal"] = "NF"
    nos, _ = montar_rede(AL, s, ch, [], {"C2": {"kw": 1}}, {}, [])
    assert "C2" in [n["codigo"] for n in nos]


def test_malha_e_descartada_com_aviso():
    s = [seg("SE", "a", 1000), seg("a", "b", 1000), seg("b", "SE", 1000)]
    nos, avisos = montar_rede(AL, s, [], [], {}, {}, [])
    assert any("malha" in a for a in avisos)
    assert len(nos) >= 2


def test_regulador_preserva_os_dois_lados_com_trecho_nulo():
    s = [seg("SE", "a", 1000), seg("a", "RF", 1000), seg("RC", "C1", 1000)]
    regs = [{"cod_brt": "RT", "pac_fonte": "RF", "pac_carga": "RC"}]
    nos, _ = montar_rede(AL, s, [], regs, {"C1": {"kw": 1}}, {}, [])
    por = {n["codigo"]: n for n in nos}
    assert por["RC"]["no_pai_codigo"] == "RF" and por["RC"]["comprimento_km"] == 0
    assert por["RF"]["comprimento_km"] == 2.0
