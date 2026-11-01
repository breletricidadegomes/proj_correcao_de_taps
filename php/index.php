<?php
/**
 * =============================================================================
 *  PAINEL PRINCIPAL - Simulação e Registro de Mudança de Tap
 * =============================================================================
 *  Renderiza a estrutura da página. Todos os cálculos são feitos via AJAX em
 *  backend/processar_tap.php (ver assets/js/app.js).
 * =============================================================================
 */
require __DIR__ . '/backend/bootstrap.php';
cabecalhos_seguranca();
header('Content-Type: text/html; charset=utf-8');

$erroInicial = null;
$alimentadores = array();
try {
    $repo = criar_repositorio();
    $alimentadores = $repo->listarAlimentadores();
} catch (Exception $e) {
    error_log('[TAP] Falha ao carregar alimentadores: ' . $e->getMessage());
    $erroInicial = 'Não foi possível conectar ao banco de dados. Verifique config/config.php.';
}

$cfg = app_config();
$operadorServidor = ($cfg['usar_remote_user'] && !empty($_SERVER['REMOTE_USER'])) ? $_SERVER['REMOTE_USER'] : '';
$versaoAssets = '1.0.0'; // incremente para forçar recarga do cache do navegador
?>
<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo h(csrf_token()); ?>">
    <title>Regulação de Tensão · Mudança de Tap</title>

    <!-- Aplica o tema salvo ANTES de pintar a tela (evita "piscar" claro/escuro) -->
    <script>
        (function () {
            try {
                var t = localStorage.getItem('tap_tema');
                if (t === 'light' || t === 'dark') { document.documentElement.setAttribute('data-bs-theme', t); }
            } catch (e) {}
        })();
    </script>

    <!-- Bootstrap 5.3.3 (modo escuro nativo via data-bs-theme), servido LOCALMENTE
         de assets/vendor/ para funcionar em intranet sem acesso à internet.
         Alternativa CDN: https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo h($versaoAssets); ?>">
</head>
<body>

<!-- ========================= BARRA SUPERIOR ========================= -->
<nav class="navbar coi-navbar px-3 py-2">
    <div class="d-flex align-items-center gap-3 flex-wrap w-100">
        <div class="coi-brand">
            <span class="coi-brand-mark" aria-hidden="true"></span>
            <div>
                <div class="coi-brand-title">Regulação de Tensão</div>
                <div class="coi-brand-sub">Simulação e registro de mudança de tap · MT</div>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap ms-lg-4">
            <label for="sel-alimentador" class="coi-label mb-0">Alimentador</label>
            <select id="sel-alimentador" class="form-select form-select-sm coi-select">
                <?php foreach ($alimentadores as $a): ?>
                    <option value="<?php echo (int) $a['id']; ?>">
                        <?php echo h($a['codigo'] . ' — ' . $a['subestacao']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="sel-equipamento" class="coi-label mb-0 ms-2">Equipamento</label>
            <select id="sel-equipamento" class="form-select form-select-sm coi-select"></select>
        </div>

        <div class="ms-auto d-flex align-items-center gap-3">
            <?php if ($cfg['modo_demo']): ?>
                <span class="badge coi-badge-demo" title="Dados de exemplo; histórico salvo apenas na sessão">MODO DEMONSTRAÇÃO</span>
            <?php endif; ?>
            <span id="relogio" class="coi-clock" aria-label="Hora local">--:--:--</span>
            <button id="btn-tema" type="button" class="btn btn-sm coi-btn-ghost" title="Alternar tema claro/escuro">
                <span class="tema-icone-escuro">☾ Escuro</span><span class="tema-icone-claro">☀ Claro</span>
            </button>
        </div>
    </div>
</nav>

<?php if ($erroInicial !== null): ?>
    <div class="alert alert-danger m-3"><?php echo h($erroInicial); ?></div>
<?php endif; ?>

<main class="container-fluid py-3">
    <div class="row g-3">

        <!-- ===================== COLUNA ESQUERDA: PARÂMETROS ===================== -->
        <aside class="col-12 col-xl-3">
            <div class="card coi-card h-100">
                <div class="card-header coi-card-header">Parâmetros da simulação</div>
                <div class="card-body">

                    <!-- Dados do equipamento selecionado -->
                    <div id="info-equipamento" class="coi-equip mb-3">
                        <div class="coi-equip-tipo" id="eq-tipo">—</div>
                        <div class="coi-equip-desc" id="eq-desc">Selecione um equipamento</div>
                        <div class="coi-equip-grid">
                            <div><span>Faixa</span><strong id="eq-faixa">—</strong></div>
                            <div><span>Passo</span><strong id="eq-passo">—</strong></div>
                            <div><span>Tap atual</span><strong id="eq-tap-atual">—</strong></div>
                        </div>
                    </div>

                    <!-- Tap proposto -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-end">
                            <label for="rng-tap" class="coi-label">Tap proposto</label>
                            <span class="coi-tap-delta" id="tap-delta-v">ΔV 0,00%</span>
                        </div>
                        <div class="coi-tap-control">
                            <button type="button" class="btn coi-btn-step" id="btn-tap-menos" aria-label="Reduzir tap">−</button>
                            <output id="out-tap" class="coi-tap-value" for="rng-tap">0</output>
                            <button type="button" class="btn coi-btn-step" id="btn-tap-mais" aria-label="Elevar tap">+</button>
                        </div>
                        <input type="range" class="form-range" id="rng-tap" min="-8" max="8" step="1" value="0">
                        <div class="d-flex justify-content-between coi-hint"><span id="tap-min-lbl">-8</span><span>reduz ← → eleva</span><span id="tap-max-lbl">+8</span></div>
                    </div>

                    <!-- Tensão na barra AT -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <label for="rng-vfonte" class="coi-label">Tensão na barra AT (fonte)</label>
                            <span class="coi-val"><span id="out-vfonte">1,000</span> p.u.</span>
                        </div>
                        <input type="range" class="form-range" id="rng-vfonte" min="0.95" max="1.05" step="0.005" value="1.00">
                    </div>

                    <!-- Patamar de carga -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <label for="rng-carga" class="coi-label">Patamar de carga</label>
                            <span class="coi-val"><span id="out-carga">100</span>%</span>
                        </div>
                        <div class="btn-group btn-group-sm w-100 mb-1" role="group" aria-label="Patamares de carga">
                            <button type="button" class="btn coi-btn-ghost" data-patamar="40">Leve</button>
                            <button type="button" class="btn coi-btn-ghost" data-patamar="70">Média</button>
                            <button type="button" class="btn coi-btn-ghost" data-patamar="100">Pesada</button>
                        </div>
                        <input type="range" class="form-range" id="rng-carga" min="0" max="150" step="5" value="100">
                    </div>

                    <!-- Despacho de GD -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between">
                            <label for="rng-gd" class="coi-label">Despacho da GD (irradiância)</label>
                            <span class="coi-val"><span id="out-gd">0</span>%</span>
                        </div>
                        <input type="range" class="form-range" id="rng-gd" min="0" max="100" step="5" value="0">
                    </div>

                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-primary coi-btn-main" id="btn-simular">▶ Simular</button>
                        <button type="button" class="btn coi-btn-outline" id="btn-otimizar">⚙ Otimizar tap</button>
                        <button type="button" class="btn coi-btn-warn" id="btn-registrar">✎ Registrar manobra</button>
                    </div>
                    <p class="coi-hint mt-3 mb-0">
                        Convenção: posição positiva eleva a tensão do lado regulado.
                        Indicadores DRP/DRC são estimativas instantâneas ponderadas por UCs.
                    </p>
                </div>
            </div>
        </aside>

        <!-- ===================== COLUNA DIREITA: RESULTADOS ===================== -->
        <section class="col-12 col-xl-9">

            <!-- KPIs -->
            <div class="row g-2 mb-3" id="kpis">
                <div class="col-6 col-md-3"><div class="coi-kpi" id="kpi-vmin"><div class="coi-kpi-label">V mínima</div><div class="coi-kpi-value">—</div><div class="coi-kpi-sub">&nbsp;</div></div></div>
                <div class="col-6 col-md-3"><div class="coi-kpi" id="kpi-vmax"><div class="coi-kpi-label">V máxima</div><div class="coi-kpi-value">—</div><div class="coi-kpi-sub">&nbsp;</div></div></div>
                <div class="col-6 col-md-3"><div class="coi-kpi" id="kpi-queda"><div class="coi-kpi-label">Queda máx. desde a SE</div><div class="coi-kpi-value">—</div><div class="coi-kpi-sub">&nbsp;</div></div></div>
                <div class="col-6 col-md-3"><div class="coi-kpi" id="kpi-perdas"><div class="coi-kpi-label">Perdas técnicas</div><div class="coi-kpi-value">—</div><div class="coi-kpi-sub">&nbsp;</div></div></div>
                <div class="col-6 col-md-3"><div class="coi-kpi" id="kpi-drp"><div class="coi-kpi-label">DRP estimado</div><div class="coi-kpi-value">—</div><div class="coi-kpi-sub">&nbsp;</div></div></div>
                <div class="col-6 col-md-3"><div class="coi-kpi" id="kpi-drc"><div class="coi-kpi-label">DRC / ICC estimado</div><div class="coi-kpi-value">—</div><div class="coi-kpi-sub">&nbsp;</div></div></div>
                <div class="col-6 col-md-3"><div class="coi-kpi" id="kpi-trafo"><div class="coi-kpi-label">Carregamento trafo SE</div><div class="coi-kpi-value">—</div><div class="coi-kpi-sub">&nbsp;</div></div></div>
                <div class="col-6 col-md-3"><div class="coi-kpi" id="kpi-reverso"><div class="coi-kpi-label">Fluxo reverso</div><div class="coi-kpi-value">—</div><div class="coi-kpi-sub">&nbsp;</div></div></div>
            </div>

            <div class="row g-3">
                <!-- Abas de resultados -->
                <div class="col-12 col-xxl-8">
                    <div class="card coi-card">
                        <div class="card-header coi-card-header p-0">
                            <ul class="nav coi-tabs" role="tablist">
                                <li class="nav-item"><button class="nav-link active" data-aba="perfil" type="button">Perfil de tensão</button></li>
                                <li class="nav-item"><button class="nav-link" data-aba="nos" type="button">Nós</button></li>
                                <li class="nav-item"><button class="nav-link" data-aba="trechos" type="button">Trechos</button></li>
                                <li class="nav-item"><button class="nav-link" data-aba="otimizacao" type="button">Otimização</button></li>
                                <li class="nav-item"><button class="nav-link" data-aba="historico" type="button">Histórico</button></li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="coi-aba active" id="aba-perfil">
                                <div class="coi-legend mb-2">
                                    <span><i class="lg lg-base"></i>Tap atual</span>
                                    <span><i class="lg lg-sim"></i>Tap proposto</span>
                                    <span><i class="lg lg-adeq"></i>Faixa adequada</span>
                                    <span><i class="lg lg-prec"></i>Faixa precária</span>
                                </div>
                                <div class="coi-chart-box"><canvas id="chart-perfil" aria-label="Perfil de tensão ao longo do alimentador"></canvas></div>
                            </div>

                            <div class="coi-aba" id="aba-nos">
                                <div class="table-responsive coi-table-wrap">
                                    <table class="table table-sm coi-table" id="tbl-nos">
                                        <thead><tr><th>Nó</th><th>Descrição</th><th class="num">Dist. (km)</th><th class="num">UCs</th><th class="num">V atual (p.u.)</th><th class="num">V proposta (p.u.)</th><th class="num">V (kV)</th><th class="num">Queda (%)</th><th>PRODIST</th></tr></thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="coi-aba" id="aba-trechos">
                                <div class="table-responsive coi-table-wrap">
                                    <table class="table table-sm coi-table" id="tbl-trechos">
                                        <thead><tr><th>Trecho</th><th class="num">P (kW)</th><th class="num">Q (kvar)</th><th class="num">I (A)</th><th class="num">Carreg. (%)</th><th class="num">Perdas (kW)</th><th>Fluxo</th></tr></thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="coi-aba" id="aba-otimizacao">
                                <div id="otim-resumo" class="coi-hint mb-2">Clique em “Otimizar tap” para varrer todas as posições do equipamento selecionado.</div>
                                <div class="row g-3">
                                    <div class="col-12 col-lg-6">
                                        <div class="table-responsive coi-table-wrap">
                                            <table class="table table-sm coi-table" id="tbl-otim">
                                                <thead><tr><th>Tap</th><th class="num">V mín</th><th class="num">V máx</th><th class="num">DRP</th><th class="num">DRC</th><th class="num">Perdas</th><th>Status</th></tr></thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="col-12 col-lg-6">
                                        <div class="coi-chart-box coi-chart-sm"><canvas id="chart-otim" aria-label="Tensão mínima e máxima por posição de tap"></canvas></div>
                                    </div>
                                </div>
                            </div>

                            <div class="coi-aba" id="aba-historico">
                                <div class="table-responsive coi-table-wrap">
                                    <table class="table table-sm coi-table" id="tbl-hist">
                                        <thead><tr><th>Data/hora</th><th>Equipamento</th><th class="num">Tap</th><th class="num">V mín antes → depois</th><th class="num">DRC antes → depois</th><th>Motivo</th><th>Operador</th></tr></thead>
                                        <tbody><tr><td colspan="7" class="coi-hint">Nenhuma manobra registrada.</td></tr></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Diagnóstico -->
                <div class="col-12 col-xxl-4">
                    <div class="card coi-card h-100">
                        <div class="card-header coi-card-header d-flex justify-content-between">
                            <span>Diagnóstico</span>
                            <span id="status-geral" class="coi-pill">—</span>
                        </div>
                        <div class="card-body">
                            <div id="lista-alertas" class="coi-alerts"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</main>

<!-- ===================== MODAL: REGISTRAR MANOBRA ===================== -->
<div class="modal fade" id="modal-registrar" tabindex="-1" aria-labelledby="modal-registrar-titulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content coi-card" id="form-registrar" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-registrar-titulo">Registrar manobra de tap</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="coi-manobra-resumo mb-3" id="reg-resumo"></div>
                <div class="mb-2">
                    <label for="reg-operador" class="form-label coi-label">Operador</label>
                    <input type="text" class="form-control" id="reg-operador" maxlength="80" required
                           value="<?php echo h($operadorServidor); ?>" <?php echo $operadorServidor !== '' ? 'readonly' : ''; ?>>
                </div>
                <div class="mb-2">
                    <label for="reg-motivo" class="form-label coi-label">Motivo</label>
                    <select id="reg-motivo" class="form-select" required>
                        <option value="SUBTENSAO">Correção de subtensão</option>
                        <option value="SOBRETENSAO">Correção de sobretensão</option>
                        <option value="PATAMAR_CARGA">Ajuste de patamar de carga</option>
                        <option value="GERACAO_DISTRIBUIDA">Influência de geração distribuída</option>
                        <option value="RECOMPOSICAO">Recomposição / transferência de carga</option>
                        <option value="MANUTENCAO">Manutenção programada</option>
                        <option value="OUTRO">Outro</option>
                    </select>
                </div>
                <div>
                    <label for="reg-obs" class="form-label coi-label">Observação</label>
                    <textarea id="reg-obs" class="form-control" rows="3" maxlength="500" placeholder="Nº da ordem de manobra, reclamação associada, etc."></textarea>
                </div>
                <p class="coi-hint mt-2 mb-0">Os indicadores antes/depois são recalculados no servidor no momento do registro.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn coi-btn-ghost" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn coi-btn-warn" id="btn-confirmar-registro">Confirmar registro</button>
            </div>
        </form>
    </div>
</div>

<!-- Toast de mensagens -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="toast" class="toast coi-toast" role="status" aria-live="polite" aria-atomic="true">
        <div class="toast-body" id="toast-body">—</div>
    </div>
</div>

<!-- Bibliotecas locais (MIT): Bootstrap 5.3.3 e Chart.js 4.4.1 -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/chartjs/chart.umd.min.js"></script>
<script src="assets/js/app.js?v=<?php echo h($versaoAssets); ?>"></script>
</body>
</html>
