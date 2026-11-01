/* =============================================================================
   Regulação de Tensão · Mudança de Tap — lógica do painel (frontend)
   -----------------------------------------------------------------------------
   - JavaScript "vanilla" em estilo ES5 (var/function), sem build nem jQuery.
   - Comunicação com backend/processar_tap.php via fetch() + JSON.
     (Navegadores muito antigos sem fetch: inclua um polyfill "whatwg-fetch".)
   - Todo texto vindo do servidor é escapado antes de ir para o HTML.
   ============================================================================= */
(function () {
    'use strict';

    var ENDPOINT = 'backend/processar_tap.php';
    var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    var estado = {
        alimentadorId: null,
        equipamentos: [],
        equipamento: null,
        ultimaSim: null,
        ultimaOtim: null,
        graficoPerfil: null,
        graficoOtim: null,
        timerSim: null,
        reqSeq: 0
    };

    // -------------------------------------------------------------------------
    // Utilitários
    // -------------------------------------------------------------------------
    function $(id) { return document.getElementById(id); }

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmt(v, casas) {
        if (v === null || v === undefined || isNaN(v)) { return '—'; }
        return Number(v).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas });
    }

    function sinal(v, casas) {
        if (v === null || v === undefined || isNaN(v)) { return '—'; }
        return (v > 0 ? '+' : '') + fmt(v, casas);
    }

    function fmtTap(t) { return (t > 0 ? '+' : '') + t; }

    function cssVar(nome) {
        return getComputedStyle(document.documentElement).getPropertyValue(nome).trim();
    }

    var ROTULO_CLASSE = { ADEQUADA: 'Adequada', PRECARIA: 'Precária', CRITICA: 'Crítica' };
    var PILL_CLASSE = { ADEQUADA: 'pill-ok', PRECARIA: 'pill-warn', CRITICA: 'pill-crit' };
    var ROTULO_MOTIVO = {
        SUBTENSAO: 'Subtensão', SOBRETENSAO: 'Sobretensão', PATAMAR_CARGA: 'Patamar de carga',
        GERACAO_DISTRIBUIDA: 'Geração distribuída', RECOMPOSICAO: 'Recomposição',
        MANUTENCAO: 'Manutenção', OUTRO: 'Outro'
    };

    function corClasse(classe) {
        if (classe === 'CRITICA') { return cssVar('--coi-crit'); }
        if (classe === 'PRECARIA') { return cssVar('--coi-warn'); }
        return cssVar('--coi-ok');
    }

    // -------------------------------------------------------------------------
    // Comunicação com o backend
    // -------------------------------------------------------------------------
    function api(acao, dados) {
        var corpo = dados || {};
        corpo.acao = acao;
        return fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(corpo)
        }).then(function (resp) {
            return resp.json().catch(function () {
                throw new Error('Resposta inválida do servidor (HTTP ' + resp.status + ').');
            });
        }).then(function (json) {
            if (!json.ok) { throw new Error(json.erro || 'Erro desconhecido.'); }
            return json.dados;
        });
    }

    var toastObj = null;
    function toast(msg, tipo) {
        var el = $('toast');
        el.className = 'toast coi-toast toast-' + (tipo || 'info');
        $('toast-body').textContent = msg;
        if (!toastObj) { toastObj = new bootstrap.Toast(el, { delay: 3500 }); }
        toastObj.show();
    }

    function carregando(botao, ativo) {
        if (!botao) { return; }
        if (ativo) { botao.classList.add('carregando'); } else { botao.classList.remove('carregando'); }
    }

    /** Parâmetros atuais do formulário */
    function parametros() {
        return {
            alimentador_id: estado.alimentadorId,
            equipamento_id: estado.equipamento ? estado.equipamento.id : null,
            tap_simulado: parseInt($('rng-tap').value, 10),
            v_fonte_pu: parseFloat($('rng-vfonte').value),
            fator_carga: parseFloat($('rng-carga').value) / 100,
            fator_gd: parseFloat($('rng-gd').value) / 100
        };
    }

    // -------------------------------------------------------------------------
    // Alimentador e equipamento
    // -------------------------------------------------------------------------
    function carregarAlimentador(manterEquipamentoId) {
        estado.alimentadorId = parseInt($('sel-alimentador').value, 10);
        if (!estado.alimentadorId) { return; }

        return api('carregar_alimentador', { alimentador_id: estado.alimentadorId }).then(function (d) {
            estado.equipamentos = d.equipamentos;
            var sel = $('sel-equipamento');
            sel.innerHTML = '';
            d.equipamentos.forEach(function (e) {
                var op = document.createElement('option');
                op.value = e.id;
                op.textContent = (e.tipo === 'TRAFO_SE' ? 'OLTC · ' : 'RT · ') + e.codigo;
                sel.appendChild(op);
            });
            var alvo = manterEquipamentoId || (d.equipamentos.length ? d.equipamentos[0].id : null);
            if (alvo) { sel.value = alvo; }
            selecionarEquipamento(parseInt(sel.value, 10));
            limparOtimizacao();
            carregarHistorico();
            simular();
        }).catch(function (e) { toast(e.message, 'erro'); });
    }

    function selecionarEquipamento(id) {
        var eq = null;
        estado.equipamentos.forEach(function (e) { if (e.id === id) { eq = e; } });
        estado.equipamento = eq;
        if (!eq) { return; }

        $('eq-tipo').textContent = eq.tipo === 'TRAFO_SE' ? 'COMUTADOR SOB CARGA (OLTC) · SE' : 'REGULADOR DE TENSÃO DE LINHA';
        $('eq-desc').textContent = eq.codigo + ' — ' + (eq.descricao || '');
        $('eq-faixa').textContent = fmtTap(eq.tap_min) + ' a ' + fmtTap(eq.tap_max);
        $('eq-passo').textContent = fmt(eq.tap_passo_percent, 3) + '%';
        $('eq-tap-atual').textContent = fmtTap(eq.tap_atual);

        var rng = $('rng-tap');
        rng.min = eq.tap_min;
        rng.max = eq.tap_max;
        rng.value = eq.tap_atual;
        $('tap-min-lbl').textContent = fmtTap(eq.tap_min);
        $('tap-max-lbl').textContent = fmtTap(eq.tap_max);
        atualizarRotuloTap();
    }

    function atualizarRotuloTap() {
        var eq = estado.equipamento;
        var tap = parseInt($('rng-tap').value, 10);
        var out = $('out-tap');
        out.textContent = fmtTap(tap);
        if (eq) {
            out.classList.toggle('alterado', tap !== eq.tap_atual);
            var dv = (tap - eq.tap_atual) * eq.tap_passo_percent;
            $('tap-delta-v').textContent = 'ΔV ' + sinal(dv, 2) + '% vs. atual';
        }
    }

    // -------------------------------------------------------------------------
    // Simulação
    // -------------------------------------------------------------------------
    function agendarSimulacao() {
        clearTimeout(estado.timerSim);
        estado.timerSim = setTimeout(simular, 250);
    }

    function simular() {
        if (!estado.equipamento) { return; }
        var seq = ++estado.reqSeq;          // descarta respostas fora de ordem
        var btn = $('btn-simular');
        carregando(btn, true);
        return api('simular', parametros()).then(function (d) {
            if (seq !== estado.reqSeq) { return; }
            estado.ultimaSim = d;
            renderizarKpis(d);
            renderizarPerfil(d);
            renderizarTabelaNos(d);
            renderizarTabelaTrechos(d);
            renderizarDiagnostico(d);
        }).catch(function (e) {
            if (seq === estado.reqSeq) { toast(e.message, 'erro'); }
        }).then(function () { carregando(btn, false); });
    }

    function classeKpiTensao(v, lim) {
        // lim = [crit_inf, prec_inf, adeq_sup, prec_sup]
        if (v < lim[0] || v > lim[3]) { return 'crit'; }
        if (v < lim[1] || v > lim[2]) { return 'warn'; }
        return 'ok';
    }

    function setKpi(id, valorHtml, subHtml, classe) {
        var el = $(id);
        el.className = 'coi-kpi' + (classe ? ' ' + classe : '');
        el.querySelector('.coi-kpi-value').innerHTML = valorHtml;
        el.querySelector('.coi-kpi-sub').innerHTML = subHtml || '&nbsp;';
    }

    /** Delta colorido: menorMelhor=true -> negativo é bom */
    function deltaHtml(delta, casas, unidade, menorMelhor) {
        if (delta === null || delta === undefined || Math.abs(delta) < Math.pow(10, -casas) / 2) {
            return 'sem alteração vs. tap atual';
        }
        var bom = menorMelhor ? delta < 0 : delta > 0;
        return '<span class="' + (bom ? 'delta-bom' : 'delta-ruim') + '">' + esc(sinal(delta, casas)) + esc(unidade) +
            '</span> vs. tap atual';
    }

    function renderizarKpis(d) {
        var s = d.simulado.indicadores;
        var lim = d.simulado.limites_pu;
        var dl = d.delta;
        var base = d.base.indicadores;

        // Para V mín: subir é bom se estiver abaixo do limite superior
        setKpi('kpi-vmin', esc(fmt(s.v_min_pu, 3)) + '<small>p.u.</small>',
            'nó ' + esc(s.no_v_min) + ' · ' + deltaHtml(dl.v_min_pu, 3, '', false),
            classeKpiTensao(s.v_min_pu, lim));
        setKpi('kpi-vmax', esc(fmt(s.v_max_pu, 3)) + '<small>p.u.</small>',
            'nó ' + esc(s.no_v_max) + ' · ' + deltaHtml(dl.v_max_pu, 3, '', s.v_max_pu > 1.0),
            classeKpiTensao(s.v_max_pu, lim));
        setKpi('kpi-queda', esc(fmt(s.queda_max_percent, 2)) + '<small>%</small>',
            'barra SE ' + esc(fmt(s.v_barra_se_pu, 3)) + ' p.u.', s.queda_max_percent > 7 ? 'warn' : 'ok');
        setKpi('kpi-perdas', esc(fmt(s.perdas_kw, 0)) + '<small>kW</small>',
            esc(fmt(s.perdas_percent, 2)) + '% · ' + deltaHtml(dl.perdas_kw, 1, ' kW', true), '');
        setKpi('kpi-drp', esc(fmt(s.drp_estimado, 2)) + '<small>%</small>',
            esc(s.ucs_precaria) + ' UCs · ' + deltaHtml(dl.drp_estimado, 2, ' p.p.', true),
            s.drp_estimado > 3 ? 'warn' : 'ok');
        setKpi('kpi-drc', esc(fmt(s.drc_estimado, 2)) + '<small>%</small>',
            esc(s.ucs_critica) + ' UCs · ' + deltaHtml(dl.drc_estimado, 2, ' p.p.', true),
            s.drc_estimado > 0.5 ? 'crit' : 'ok');

        var t = d.simulado.trafo;
        if (t) {
            setKpi('kpi-trafo', esc(fmt(t.carregamento_percent, 1)) + '<small>%</small>',
                esc(fmt(t.s_kva / 1000, 2)) + ' MVA · tap ' + esc(fmtTap(t.tap)),
                t.carregamento_percent > 100 ? 'crit' : (t.carregamento_percent > 80 ? 'warn' : 'ok'));
        } else {
            setKpi('kpi-trafo', '—', 'trafo SE não cadastrado', '');
        }

        setKpi('kpi-reverso', s.fluxo_reverso ? 'SIM' : 'NÃO',
            'GD ' + esc(fmt(s.gd_total_kw, 0)) + ' kW · carga ' + esc(fmt(s.carga_total_kw, 0)) + ' kW',
            s.fluxo_reverso ? 'warn' : 'ok');

        // Referência não usada diretamente, mas útil para depuração
        void base;
    }

    // ---- Plugin Chart.js: faixas PRODIST ao fundo do gráfico ----
    var pluginFaixas = {
        id: 'faixasProdist',
        beforeDraw: function (chart, args, opts) {
            if (!opts || !opts.limites) { return; }
            var y = chart.scales.y, area = chart.chartArea, ctx = chart.ctx, l = opts.limites;
            function faixa(v1, v2, cor) {
                var a = y.getPixelForValue(v1), b = y.getPixelForValue(v2);
                var topo = Math.max(Math.min(a, b), area.top), fundo = Math.min(Math.max(a, b), area.bottom);
                if (fundo > topo) { ctx.fillStyle = cor; ctx.fillRect(area.left, topo, area.right - area.left, fundo - topo); }
            }
            ctx.save();
            faixa(l[0], l[1], cssVar('--coi-band-prec'));
            faixa(l[1], l[2], cssVar('--coi-band-adeq'));
            if (l[3] > l[2]) { faixa(l[2], l[3], cssVar('--coi-band-prec')); }
            ctx.restore();
        }
    };

    /** Monta segmentos pai->filho (x = distância, y = tensão), separados por null */
    function segmentos(nos) {
        var porCodigo = {}, pts = [];
        nos.forEach(function (n) { porCodigo[n.codigo] = n; });
        nos.forEach(function (n) {
            if (n.pai && porCodigo[n.pai]) {
                var p = porCodigo[n.pai];
                pts.push({ x: p.distancia_km, y: p.v_pu }, { x: n.distancia_km, y: n.v_pu }, { x: null, y: null });
            }
        });
        return pts;
    }

    function renderizarPerfil(d) {
        var nosB = d.base.nos, nosS = d.simulado.nos, lim = d.simulado.limites_pu;
        var corGrade = cssVar('--coi-grid'), corTexto = cssVar('--coi-text-muted');
        var corAcc = cssVar('--coi-accent'), corBase = cssVar('--coi-base-line');

        var todos = nosB.map(function (n) { return n.v_pu; }).concat(nosS.map(function (n) { return n.v_pu; }));
        // Limites do eixo alinhados a múltiplos de 0,02 p.u. (evita rótulos sobrepostos)
        var yMin = Math.floor(Math.min(0.88, Math.min.apply(null, todos) - 0.01) / 0.02) * 0.02;
        var yMax = Math.ceil(Math.max(1.06, Math.max.apply(null, todos) + 0.01) / 0.02) * 0.02;

        var cfg = {
            type: 'scatter',
            data: {
                datasets: [
                    { label: 'Tap atual', data: segmentos(nosB), showLine: true, spanGaps: false,
                      borderColor: corBase, borderDash: [6, 4], borderWidth: 1.5, pointRadius: 0 },
                    { label: 'Tap proposto', data: segmentos(nosS), showLine: true, spanGaps: false,
                      borderColor: corAcc, borderWidth: 2.5, pointRadius: 0 },
                    { label: 'Nós (proposto)',
                      data: nosS.map(function (n) { return { x: n.distancia_km, y: n.v_pu, no: n }; }),
                      pointRadius: 5, pointHoverRadius: 7,
                      pointBackgroundColor: nosS.map(function (n) { return corClasse(n.classe); }),
                      pointBorderColor: cssVar('--coi-surface'), pointBorderWidth: 1.5 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: { duration: 250 },
                plugins: {
                    legend: { display: false },
                    faixasProdist: { limites: lim },
                    tooltip: {
                        filter: function (item) { return item.datasetIndex === 2; },
                        callbacks: {
                            label: function (ctx) {
                                var n = ctx.raw.no;
                                return [n.codigo + ' — ' + n.nome,
                                    'V = ' + fmt(n.v_pu, 4) + ' p.u. (' + fmt(n.v_kv, 2) + ' kV)',
                                    'Dist. = ' + fmt(n.distancia_km, 1) + ' km · ' + ROTULO_CLASSE[n.classe]];
                            }
                        }
                    }
                },
                scales: {
                    x: { type: 'linear', title: { display: true, text: 'Distância da SE (km)', color: corTexto },
                         grid: { color: corGrade }, ticks: { color: corTexto } },
                    y: { min: yMin, max: yMax, title: { display: true, text: 'Tensão (p.u.)', color: corTexto },
                         grid: { color: corGrade }, ticks: { color: corTexto, stepSize: 0.02, callback: function (v) { return fmt(v, 2); } } }
                }
            },
            plugins: [pluginFaixas]
        };

        if (estado.graficoPerfil) { estado.graficoPerfil.destroy(); }
        estado.graficoPerfil = new Chart($('chart-perfil'), cfg);
    }

    function renderizarTabelaNos(d) {
        var baseMap = {};
        d.base.nos.forEach(function (n) { baseMap[n.codigo] = n; });
        var html = d.simulado.nos.map(function (n) {
            var b = baseMap[n.codigo];
            var marcador = n.regulador ? ' <span class="coi-pill pill-info" title="Regulador a montante">' + esc(n.regulador) + '</span>' : '';
            if (n.gd_kw > 0) { marcador += ' <span class="coi-pill pill-rev">GD ' + esc(fmt(n.gd_kw, 0)) + ' kW</span>'; }
            return '<tr>' +
                '<td><strong>' + esc(n.codigo) + '</strong></td>' +
                '<td>' + esc(n.nome) + marcador + '</td>' +
                '<td class="num">' + esc(fmt(n.distancia_km, 1)) + '</td>' +
                '<td class="num">' + esc(n.consumidores) + '</td>' +
                '<td class="num">' + esc(fmt(b ? b.v_pu : null, 4)) + '</td>' +
                '<td class="num"><strong>' + esc(fmt(n.v_pu, 4)) + '</strong></td>' +
                '<td class="num">' + esc(fmt(n.v_kv, 2)) + '</td>' +
                '<td class="num">' + esc(fmt(n.queda_percent, 2)) + '</td>' +
                '<td><span class="coi-pill ' + PILL_CLASSE[n.classe] + '">' + esc(ROTULO_CLASSE[n.classe]) +
                (n.sentido !== 'normal' ? ' · ' + (n.sentido === 'subtensao' ? '↓' : '↑') : '') + '</span></td>' +
                '</tr>';
        }).join('');
        $('tbl-nos').querySelector('tbody').innerHTML = html;
    }

    function renderizarTabelaTrechos(d) {
        var linhas = [];
        var t = d.simulado.trafo;
        if (t) {
            linhas.push('<tr><td><strong>' + esc(t.codigo) + '</strong> (trafo SE)</td>' +
                '<td class="num">' + esc(fmt(t.p_kw, 0)) + '</td><td class="num">' + esc(fmt(t.q_kvar, 0)) + '</td>' +
                '<td class="num">—</td><td class="num">' + esc(fmt(t.carregamento_percent, 1)) + '</td><td class="num">—</td>' +
                '<td>' + (t.fluxo_reverso ? '<span class="coi-pill pill-rev">← reverso</span>' : '<span class="coi-pill pill-ok">→ normal</span>') + '</td></tr>');
        }
        d.simulado.trechos.forEach(function (tr) {
            var c = tr.carregamento_percent;
            var clsC = c === null ? '' : (c > 100 ? 'pill-crit' : (c > 80 ? 'pill-warn' : 'pill-ok'));
            linhas.push('<tr><td>' + esc(tr.de) + ' → ' + esc(tr.para) + '</td>' +
                '<td class="num">' + esc(fmt(tr.p_kw, 0)) + '</td>' +
                '<td class="num">' + esc(fmt(tr.q_kvar, 0)) + '</td>' +
                '<td class="num">' + esc(fmt(tr.corrente_a, 1)) + '</td>' +
                '<td class="num">' + (c === null ? '—' : '<span class="coi-pill ' + clsC + '">' + esc(fmt(c, 1)) + '</span>') + '</td>' +
                '<td class="num">' + esc(fmt(tr.perdas_kw, 1)) + '</td>' +
                '<td>' + (tr.fluxo_reverso ? '<span class="coi-pill pill-rev">← reverso</span>' : '<span class="coi-pill pill-ok">→ normal</span>') + '</td></tr>');
        });
        $('tbl-trechos').querySelector('tbody').innerHTML = linhas.join('');
    }

    function renderizarDiagnostico(d) {
        var s = d.simulado.indicadores, b = d.base.indicadores, eq = d.equipamento;
        var itens = [];

        function add(tipo, titulo, texto) { itens.push({ tipo: tipo, titulo: titulo, texto: texto }); }

        // Status geral
        var pill = $('status-geral');
        if (s.nos_criticos > 0) { pill.className = 'coi-pill pill-crit'; pill.textContent = 'Tensão crítica'; }
        else if (s.nos_precarios > 0) { pill.className = 'coi-pill pill-warn'; pill.textContent = 'Tensão precária'; }
        else { pill.className = 'coi-pill pill-ok'; pill.textContent = 'Conforme PRODIST'; }

        // Comparação tap atual x proposto
        if (d.tap_simulado !== d.tap_atual) {
            var melhorou = (s.ucs_critica < b.ucs_critica) || (s.ucs_critica === b.ucs_critica && s.ucs_precaria < b.ucs_precaria);
            var piorou = (s.ucs_critica > b.ucs_critica) || (s.ucs_critica === b.ucs_critica && s.ucs_precaria > b.ucs_precaria);
            add(melhorou ? 'ok' : (piorou ? 'crit' : 'info'),
                'Tap ' + fmtTap(d.tap_atual) + ' → ' + fmtTap(d.tap_simulado) + ' em ' + eq.codigo,
                'UCs em faixa crítica: ' + b.ucs_critica + ' → ' + s.ucs_critica +
                ' · precária: ' + b.ucs_precaria + ' → ' + s.ucs_precaria +
                ' · perdas ' + sinal(d.delta.perdas_kw, 1) + ' kW.');
        } else {
            add('info', 'Cenário no tap atual', 'Mova o seletor de tap para comparar o efeito de uma manobra.');
        }

        // Nós fora da faixa
        var crit = d.simulado.nos.filter(function (n) { return n.classe === 'CRITICA'; });
        var prec = d.simulado.nos.filter(function (n) { return n.classe === 'PRECARIA'; });
        if (crit.length) {
            add('crit', crit.length + ' nó(s) em faixa crítica',
                crit.map(function (n) { return n.codigo + ' ' + fmt(n.v_pu, 3) + ' (' + (n.sentido === 'subtensao' ? 'subtensão' : 'sobretensão') + ')'; }).join(' · '));
        }
        if (prec.length) {
            add('warn', prec.length + ' nó(s) em faixa precária',
                prec.map(function (n) { return n.codigo + ' ' + fmt(n.v_pu, 3); }).join(' · '));
        }
        if (!crit.length && !prec.length) {
            add('ok', 'Todos os nós em faixa adequada', 'Faixa MT adequada: ' + fmt(d.simulado.limites_pu[1], 2) + ' a ' + fmt(d.simulado.limites_pu[2], 2) + ' p.u.');
        }

        // Indicadores x limites de referência
        if (s.drp_estimado > 3) { add('warn', 'DRP estimado acima de 3%', 'Estimativa instantânea: ' + fmt(s.drp_estimado, 2) + '% das UCs em faixa precária.'); }
        if (s.drc_estimado > 0.5) { add('crit', 'DRC estimado acima de 0,5%', 'Estimativa instantânea: ' + fmt(s.drc_estimado, 2) + '% das UCs em faixa crítica.'); }

        // Reserva de regulação
        if (d.tap_simulado === eq.tap_max || d.tap_simulado === eq.tap_min) {
            add('warn', 'Comutador no limite da faixa', eq.codigo + ' sem reserva de regulação para ' +
                (d.tap_simulado === eq.tap_max ? 'elevar' : 'reduzir') + ' a tensão.');
        }

        // Fluxo reverso e carregamentos
        if (s.fluxo_reverso) {
            add('warn', 'Fluxo reverso detectado', 'A GD supera a carga local em parte do alimentador. Reguladores sem modo bidirecional/cogeração podem atuar no sentido errado.');
        }
        d.simulado.trechos.forEach(function (tr) {
            if (tr.carregamento_percent !== null && tr.carregamento_percent > 100) {
                add('crit', 'Sobrecarga no trecho ' + tr.de + ' → ' + tr.para, fmt(tr.corrente_a, 0) + ' A (' + fmt(tr.carregamento_percent, 0) + '% da ampacidade).');
            }
        });
        var t = d.simulado.trafo;
        if (t && t.carregamento_percent > 100) {
            add('crit', 'Sobrecarga no transformador da SE', fmt(t.carregamento_percent, 1) + '% da potência nominal.');
        }

        $('lista-alertas').innerHTML = itens.map(function (i) {
            return '<div class="coi-alert ' + i.tipo + '"><strong>' + esc(i.titulo) + '</strong>' + esc(i.texto) + '</div>';
        }).join('');
    }

    // -------------------------------------------------------------------------
    // Otimização
    // -------------------------------------------------------------------------
    function limparOtimizacao() {
        estado.ultimaOtim = null;
        $('tbl-otim').querySelector('tbody').innerHTML = '';
        $('otim-resumo').textContent = 'Clique em “Otimizar tap” para varrer todas as posições do equipamento selecionado.';
        if (estado.graficoOtim) { estado.graficoOtim.destroy(); estado.graficoOtim = null; }
    }

    function otimizar() {
        if (!estado.equipamento) { return; }
        var btn = $('btn-otimizar');
        carregando(btn, true);
        api('otimizar', parametros()).then(function (d) {
            estado.ultimaOtim = d;
            renderizarOtimizacao(d);
            mostrarAba('otimizacao');
            toast('Otimização concluída: ' + d.resultados.length + ' posições avaliadas.', 'ok');
        }).catch(function (e) { toast(e.message, 'erro'); })
          .then(function () { carregando(btn, false); });
    }

    function renderizarOtimizacao(d) {
        var resumo = $('otim-resumo');
        if (d.melhor_tap === null) {
            resumo.innerHTML = '<div class="coi-alert crit"><strong>Sem solução</strong>Nenhuma posição convergiu.</div>';
        } else {
            var tipo = d.solucao_plena ? 'ok' : 'warn';
            var acao = d.acao === 'MANTER' ? 'Manter o tap atual' :
                (d.acao === 'ELEVAR' ? 'Elevar ' : 'Reduzir ') + (d.acao === 'MANTER' ? '' : d.degraus + ' degrau(s)');
            resumo.innerHTML = '<div class="coi-alert ' + tipo + '"><strong>Recomendação para ' + esc(d.equipamento) + ': tap ' +
                esc(fmtTap(d.melhor_tap)) + '</strong>' + esc(acao) + ' (atual ' + esc(fmtTap(d.tap_atual)) + '). ' +
                (d.solucao_plena ? 'Todos os nós em faixa adequada.' :
                    'Nenhuma posição elimina todas as violações — considere atuar em outro equipamento (OLTC/RT), capacitores ou remanejamento de carga.') +
                ' <button type="button" class="btn btn-sm coi-btn-outline ms-2" id="btn-aplicar-otim">Aplicar na simulação</button></div>';
            $('btn-aplicar-otim').addEventListener('click', function () {
                $('rng-tap').value = d.melhor_tap;
                atualizarRotuloTap();
                simular();
                mostrarAba('perfil');
            });
        }

        $('tbl-otim').querySelector('tbody').innerHTML = d.resultados.map(function (r) {
            var cls = r.tap === d.melhor_tap ? 'linha-melhor' : (r.tap === d.tap_atual ? 'linha-atual' : '');
            if (r.status === 'NAO_CONVERGIU') {
                return '<tr class="' + cls + '"><td>' + esc(fmtTap(r.tap)) + '</td><td colspan="5" class="coi-hint">não convergiu</td><td><span class="coi-pill pill-crit">Falha</span></td></tr>';
            }
            return '<tr class="' + cls + '"><td><strong>' + esc(fmtTap(r.tap)) + '</strong>' +
                (r.tap === d.melhor_tap ? ' ★' : '') + (r.tap === d.tap_atual ? ' <span class="coi-hint">(atual)</span>' : '') + '</td>' +
                '<td class="num">' + esc(fmt(r.v_min_pu, 3)) + '</td>' +
                '<td class="num">' + esc(fmt(r.v_max_pu, 3)) + '</td>' +
                '<td class="num">' + esc(fmt(r.drp_estimado, 1)) + '</td>' +
                '<td class="num">' + esc(fmt(r.drc_estimado, 1)) + '</td>' +
                '<td class="num">' + esc(fmt(r.perdas_kw, 0)) + '</td>' +
                '<td><span class="coi-pill ' + (r.status === 'OK' ? 'pill-ok' : 'pill-warn') + '">' + (r.status === 'OK' ? 'OK' : 'Violação') + '</span></td></tr>';
        }).join('');

        renderizarGraficoOtim(d);
    }

    function renderizarGraficoOtim(d) {
        var validos = d.resultados.filter(function (r) { return r.status !== 'NAO_CONVERGIU'; });
        var lim = estado.ultimaSim ? estado.ultimaSim.simulado.limites_pu : [0.90, 0.93, 1.05, 1.05];
        var corGrade = cssVar('--coi-grid'), corTexto = cssVar('--coi-text-muted');

        var cfg = {
            type: 'line',
            data: {
                labels: validos.map(function (r) { return fmtTap(r.tap); }),
                datasets: [
                    { label: 'V mín', data: validos.map(function (r) { return r.v_min_pu; }), borderColor: cssVar('--coi-warn'),
                      pointRadius: validos.map(function (r) { return r.tap === d.melhor_tap ? 7 : 3; }), tension: 0.15 },
                    { label: 'V máx', data: validos.map(function (r) { return r.v_max_pu; }), borderColor: cssVar('--coi-accent'),
                      pointRadius: validos.map(function (r) { return r.tap === d.melhor_tap ? 7 : 3; }), tension: 0.15 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: { duration: 250 },
                plugins: {
                    legend: { labels: { color: corTexto, boxWidth: 12 } },
                    faixasProdist: { limites: lim }
                },
                scales: {
                    x: { title: { display: true, text: 'Posição de tap', color: corTexto }, grid: { color: corGrade }, ticks: { color: corTexto } },
                    y: { title: { display: true, text: 'Tensão (p.u.)', color: corTexto }, grid: { color: corGrade },
                         ticks: { color: corTexto, callback: function (v) { return fmt(v, 2); } } }
                }
            },
            plugins: [pluginFaixas]
        };
        if (estado.graficoOtim) { estado.graficoOtim.destroy(); }
        estado.graficoOtim = new Chart($('chart-otim'), cfg);
    }

    // -------------------------------------------------------------------------
    // Registro de manobra e histórico
    // -------------------------------------------------------------------------
    var modalRegistro = null;

    function abrirRegistro() {
        var eq = estado.equipamento, d = estado.ultimaSim;
        if (!eq || !d) { toast('Execute uma simulação antes de registrar.', 'erro'); return; }
        var tapNovo = parseInt($('rng-tap').value, 10);
        if (tapNovo === eq.tap_atual) {
            toast('O tap proposto é igual ao atual. Ajuste o tap antes de registrar.', 'erro');
            return;
        }
        var s = d.simulado.indicadores, b = d.base.indicadores;
        $('reg-resumo').innerHTML =
            '<div class="coi-label">' + esc(eq.codigo) + '</div>' +
            '<div class="grande">' + esc(fmtTap(eq.tap_atual)) + ' → ' + esc(fmtTap(tapNovo)) + '</div>' +
            '<div>V mín: ' + esc(fmt(b.v_min_pu, 3)) + ' → <strong>' + esc(fmt(s.v_min_pu, 3)) + '</strong> p.u. · ' +
            'V máx: ' + esc(fmt(b.v_max_pu, 3)) + ' → <strong>' + esc(fmt(s.v_max_pu, 3)) + '</strong> p.u.</div>' +
            '<div>DRC est.: ' + esc(fmt(b.drc_estimado, 2)) + '% → <strong>' + esc(fmt(s.drc_estimado, 2)) + '%</strong> · ' +
            'Perdas: ' + esc(fmt(b.perdas_kw, 0)) + ' → <strong>' + esc(fmt(s.perdas_kw, 0)) + '</strong> kW</div>';
        // Sugere o motivo a partir da situação de tensão
        $('reg-motivo').value = s.v_max_pu > d.simulado.limites_pu[2] || b.v_max_pu > d.simulado.limites_pu[2] ? 'SOBRETENSAO' :
            (b.v_min_pu < d.simulado.limites_pu[1] ? 'SUBTENSAO' : 'PATAMAR_CARGA');
        if (!modalRegistro) { modalRegistro = new bootstrap.Modal($('modal-registrar')); }
        modalRegistro.show();
    }

    function confirmarRegistro(ev) {
        ev.preventDefault();
        var btn = $('btn-confirmar-registro');
        var p = parametros();
        p.tap_novo = p.tap_simulado;
        p.operador = $('reg-operador').value;
        p.motivo = $('reg-motivo').value;
        p.observacao = $('reg-obs').value;
        carregando(btn, true);
        api('registrar', p).then(function (d) {
            modalRegistro.hide();
            $('reg-obs').value = '';
            toast(d.mensagem + ' Tap ' + fmtTap(d.registro.tap_anterior) + ' → ' + fmtTap(d.registro.tap_novo) + '.', 'ok');
            // Recarrega equipamentos (tap_atual mudou) mantendo a seleção
            return carregarAlimentador(estado.equipamento.id);
        }).then(function () { mostrarAba('historico'); })
          .catch(function (e) { toast(e.message, 'erro'); })
          .then(function () { carregando(btn, false); });
    }

    function carregarHistorico() {
        api('historico', { alimentador_id: estado.alimentadorId }).then(function (d) {
            var tb = $('tbl-hist').querySelector('tbody');
            if (!d.historico.length) {
                tb.innerHTML = '<tr><td colspan="7" class="coi-hint">Nenhuma manobra registrada para este alimentador.</td></tr>';
                return;
            }
            tb.innerHTML = d.historico.map(function (h) {
                var dt = String(h.data_hora).replace('T', ' ').substring(0, 16).split(' ');
                var data = dt[0].split('-').reverse().join('/') + ' ' + (dt[1] || '');
                return '<tr><td>' + esc(data) + '</td>' +
                    '<td>' + esc(h.equipamento) + '</td>' +
                    '<td class="num"><strong>' + esc(fmtTap(parseInt(h.tap_anterior, 10))) + ' → ' + esc(fmtTap(parseInt(h.tap_novo, 10))) + '</strong></td>' +
                    '<td class="num">' + esc(fmt(parseFloat(h.v_min_antes_pu), 3)) + ' → ' + esc(fmt(parseFloat(h.v_min_depois_pu), 3)) + '</td>' +
                    '<td class="num">' + esc(fmt(parseFloat(h.drc_antes), 2)) + ' → ' + esc(fmt(parseFloat(h.drc_depois), 2)) + '</td>' +
                    '<td>' + esc(ROTULO_MOTIVO[h.motivo] || h.motivo) + (h.observacao ? '<div class="coi-hint">' + esc(h.observacao) + '</div>' : '') + '</td>' +
                    '<td>' + esc(h.operador) + '</td></tr>';
            }).join('');
        }).catch(function (e) { toast(e.message, 'erro'); });
    }

    // -------------------------------------------------------------------------
    // Abas, tema, relógio
    // -------------------------------------------------------------------------
    function mostrarAba(nome) {
        var botoes = document.querySelectorAll('.coi-tabs .nav-link');
        Array.prototype.forEach.call(botoes, function (b) { b.classList.toggle('active', b.getAttribute('data-aba') === nome); });
        Array.prototype.forEach.call(document.querySelectorAll('.coi-aba'), function (a) { a.classList.toggle('active', a.id === 'aba-' + nome); });
        // Chart.js precisa redimensionar quando o canvas fica visível
        if (nome === 'perfil' && estado.graficoPerfil) { estado.graficoPerfil.resize(); }
        if (nome === 'otimizacao' && estado.graficoOtim) { estado.graficoOtim.resize(); }
    }

    function alternarTema() {
        var html = document.documentElement;
        var novo = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-bs-theme', novo);
        try { localStorage.setItem('tap_tema', novo); } catch (e) { /* armazenamento indisponível */ }
        // Recria os gráficos com as cores do novo tema
        if (estado.ultimaSim) { renderizarPerfil(estado.ultimaSim); }
        if (estado.ultimaOtim) { renderizarGraficoOtim(estado.ultimaOtim); }
    }

    function relogio() {
        $('relogio').textContent = new Date().toLocaleTimeString('pt-BR');
    }

    // -------------------------------------------------------------------------
    // Inicialização
    // -------------------------------------------------------------------------
    function vincularEventos() {
        $('sel-alimentador').addEventListener('change', function () { carregarAlimentador(); });
        $('sel-equipamento').addEventListener('change', function () {
            selecionarEquipamento(parseInt(this.value, 10));
            limparOtimizacao();
            simular();
        });

        $('rng-tap').addEventListener('input', function () { atualizarRotuloTap(); agendarSimulacao(); });
        $('btn-tap-menos').addEventListener('click', function () {
            var r = $('rng-tap'); r.value = Math.max(parseInt(r.min, 10), parseInt(r.value, 10) - 1);
            atualizarRotuloTap(); agendarSimulacao();
        });
        $('btn-tap-mais').addEventListener('click', function () {
            var r = $('rng-tap'); r.value = Math.min(parseInt(r.max, 10), parseInt(r.value, 10) + 1);
            atualizarRotuloTap(); agendarSimulacao();
        });

        $('rng-vfonte').addEventListener('input', function () {
            $('out-vfonte').textContent = fmt(parseFloat(this.value), 3); agendarSimulacao();
        });
        $('rng-carga').addEventListener('input', function () {
            $('out-carga').textContent = this.value; marcarPatamar(); agendarSimulacao();
        });
        $('rng-gd').addEventListener('input', function () {
            $('out-gd').textContent = this.value; agendarSimulacao();
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-patamar]'), function (b) {
            b.addEventListener('click', function () {
                $('rng-carga').value = this.getAttribute('data-patamar');
                $('out-carga').textContent = $('rng-carga').value;
                marcarPatamar(); agendarSimulacao();
            });
        });

        $('btn-simular').addEventListener('click', simular);
        $('btn-otimizar').addEventListener('click', otimizar);
        $('btn-registrar').addEventListener('click', abrirRegistro);
        $('form-registrar').addEventListener('submit', confirmarRegistro);
        $('btn-tema').addEventListener('click', alternarTema);

        Array.prototype.forEach.call(document.querySelectorAll('.coi-tabs .nav-link'), function (b) {
            b.addEventListener('click', function () {
                var aba = this.getAttribute('data-aba');
                mostrarAba(aba);
                if (aba === 'historico') { carregarHistorico(); }
            });
        });
    }

    function marcarPatamar() {
        var v = $('rng-carga').value;
        Array.prototype.forEach.call(document.querySelectorAll('[data-patamar]'), function (b) {
            b.classList.toggle('active', b.getAttribute('data-patamar') === v);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        vincularEventos();
        marcarPatamar();
        relogio();
        setInterval(relogio, 1000);
        if ($('sel-alimentador').options.length) { carregarAlimentador(); }
    });
})();
