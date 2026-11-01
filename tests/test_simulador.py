"""Testes do simulador Flask.

Rodar na raiz do projeto:  python -m pytest -q
"""
import os
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import app as simulador  # noqa: E402


@pytest.fixture
def cliente():
    simulador.app.config["TESTING"] = True
    return simulador.app.test_client()


BASE = {"vm_pu": 1.0, "load_a_kw": 20, "load_b_kw": 20, "load_c_kw": 20, "gd_kw": 0}


def test_pagina_inicial_renderiza(cliente):
    resp = cliente.get("/")
    assert resp.status_code == 200
    assert b"Otimiza" in resp.data


def test_simulacao_retorna_barramentos(cliente):
    dados = cliente.post("/api/simulate", json=BASE).get_json()
    assert len(dados["buses"]) == 5
    assert len(dados["lines"]) == 3
    assert dados["has_reverse_flow"] is False


def test_posicao_de_tap_altera_tensao(cliente):
    """Regressão: no pandapower 3.x, sem tap_changer_type, todos os taps davam o mesmo resultado."""
    r = cliente.post("/api/optimize_tap", json=BASE).get_json()["results"]
    v_min = {x["tap"]: x["v_min"] for x in r}
    assert len(set(v_min.values())) == 5, "cada posição de tap deve gerar tensão diferente"
    # tap no lado de alta: positivo reduz a tensão na BT
    assert v_min[-2] > v_min[0] > v_min[2]


def test_otimizacao_escolhe_tap_sem_violacao(cliente):
    dados = cliente.post("/api/optimize_tap", json=BASE).get_json()
    melhor = dados["best_tap"]
    assert melhor is not None
    linha = next(x for x in dados["results"] if x["tap"] == melhor)
    assert linha["status"] == "OK"
    assert linha["deviation"] == min(x["deviation"] for x in dados["results"] if x["status"] == "OK")


def test_gd_alta_gera_fluxo_reverso(cliente):
    params = dict(BASE, load_a_kw=5, load_b_kw=5, load_c_kw=5, gd_kw=60)
    dados = cliente.post("/api/simulate", json=params).get_json()
    assert dados["has_reverse_flow"] is True
    assert dados["trafos"][0]["reverse_flow"] is True
