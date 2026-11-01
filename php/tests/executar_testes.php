<?php
/**
 * Testes automatizados do motor (sem PHPUnit, compatível com PHP 5.5+).
 *
 * Executar:  php php/tests/executar_testes.php
 * Saída 0 = todos passaram; 1 = alguma falha.
 */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('Execute pela linha de comando.');
}
define('APP_TAP', true);
$lib = dirname(__DIR__) . '/backend/lib/';
foreach (array('Complexo', 'FluxoCargaRadial', 'ProdistTensao', 'SimuladorTap', 'DadosDemo',
               'AnaliseHospedagem', 'CurvasTipicas', 'SimulacaoDiaria') as $c) {
    require_once $lib . $c . '.php';
}

$falhas = 0;
$total = 0;
function verificar($condicao, $descricao)
{
    global $falhas, $total;
    $total++;
    echo ($condicao ? '  ok    ' : '  FALHA ') . $descricao . "\n";
    if (!$condicao) {
        $falhas++;
    }
}
function contexto($alimentadorId)
{
    $als = DadosDemo::alimentadores();
    $eqs = array();
    foreach (DadosDemo::equipamentos() as $e) {
        if ((int) $e['alimentador_id'] === $alimentadorId) {
            $eqs[] = $e;
        }
    }
    return array($als[$alimentadorId - 1], DadosDemo::nos($alimentadorId), $eqs);
}

$sim = new SimuladorTap(array('potencia_base_mva' => 10.0, 'tolerancia_pu' => 1e-8, 'max_iteracoes' => 60));
$params = array('v_fonte_pu' => 1.0, 'fator_carga' => 1.0, 'fator_gd' => 0.0, 'taps' => array());

echo "Fluxo de carga\n";
list($al, $nos, $eqs) = contexto(1);
$r = $sim->simular($al, $nos, $eqs, $params);
verificar(abs($r['indicadores']['v_min_pu'] - 0.9069) < 0.0002, 'CTR-01 carga pesada: V mín 0,9069 p.u. (referência pandapower)');
verificar(abs($r['indicadores']['perdas_kw'] - 165.4) < 0.5, 'CTR-01 carga pesada: perdas 165,4 kW (referência pandapower)');
$p = $params;
$p['taps'][1] = 2;
$r2 = $sim->simular($al, $nos, $eqs, $p);
verificar($r2['indicadores']['v_min_pu'] > $r['indicadores']['v_min_pu'] + 0.02, 'tap positivo do OLTC eleva a tensão');
$p = $params;
$p['gd_extra'] = array('P07' => 1000);
$r3 = $sim->simular($al, $nos, $eqs, $p);
verificar($r3['indicadores']['gd_total_kw'] == 1000.0, 'GD adicional entra no total de geração');
verificar($r3['indicadores']['v_min_pu'] > $r['indicadores']['v_min_pu'], 'GD adicional eleva a tensão no fim do tronco');

echo "Otimização\n";
$o = $sim->otimizar($al, $nos, $eqs, $params, $eqs[0]);
$melhor = null;
foreach ($o['resultados'] as $l) {
    if ($l['tap'] === $o['melhor_tap']) {
        $melhor = $l;
    }
}
verificar($melhor !== null && $melhor['barra_se_fora'] === 0, 'tap recomendado mantém a barra da SE na faixa adequada');

echo "Capacidade de hospedagem\n";
$leve = array('v_fonte_pu' => 1.0, 'fator_carga' => 0.4, 'fator_gd' => 1.0, 'taps' => array());
$h = new AnaliseHospedagem($sim);
$hp = $h->avaliarNo($al, $nos, $eqs, $leve, $eqs[0], 'P07');
verificar($hp['tap_atual_kw']['kw'] > 0 && $hp['tap_atual_kw']['tipo'] === 'SOBRETENSAO', 'P07: capacidade positiva limitada por sobretensão');
verificar($hp['melhor_tap_kw']['kw'] >= $hp['tap_atual_kw']['kw'], 'ajustar o tap nunca reduz a capacidade');
verificar($hp['melhor_tap_kw']['tap'] < 0, 'mais GD exige baixar o tap do OLTC');
$pk = $leve;
$pk['gd_extra'] = array('P07' => $hp['tap_atual_kw']['kw']);
$rk = $sim->simular($al, $nos, $eqs, $pk);
verificar($rk['indicadores']['nos_precarios'] + $rk['indicadores']['nos_criticos'] === 0, 'na capacidade calculada a rede está conforme');
$pk['gd_extra'] = array('P07' => $hp['tap_atual_kw']['kw'] + 20);
$rk = $sim->simular($al, $nos, $eqs, $pk);
verificar($rk['indicadores']['nos_precarios'] + $rk['indicadores']['nos_criticos'] > 0, '20 kW acima da capacidade já viola');
verificar($hp['fluxo_reverso']['kw'] > 0 && $hp['fluxo_reverso']['kw'] < $hp['tap_atual_kw']['kw'], 'fluxo reverso aparece antes do limite de tensão');

list($al2, $nos2, $eqs2) = contexto(2);
$h2 = $h->avaliarNo($al2, $nos2, $eqs2, $leve, $eqs2[0], 'Q05');
verificar($h2['tap_atual_kw']['base_viola'] === true, 'CTR-02 com usina FV: cenário base já viola com o tap atual');
verificar($h2['melhor_tap_kw']['kw'] > 0, 'CTR-02: ajustando o tap volta a haver capacidade');

$m = $h->mapa($al, $nos, $eqs, $leve, $eqs[0], 60);
verificar(count($m['nos']) === count($nos) - 1, 'mapa cobre todos os nós exceto a barra da SE');

echo "Simulação de 24 h\n";
$d = new SimulacaoDiaria($sim);
$op = array('perfil_carga' => 'misto', 'perfil_ceu' => 'parcial', 'pico_carga' => 1.0, 'pico_gd' => 1.0,
            'v_fonte_pu' => 1.0, 'v_ref_pu' => 1.01, 'banda_pu' => 0.02);
$dia = $d->executar($al2, $nos2, $eqs2, $eqs2[0], $op);
verificar(count($dia['perfil']) === 96 && count($dia['series']['automatico']) === 96, '96 intervalos de 15 min');
verificar($dia['resumo']['fixo']['comutacoes'] === 0, 'tap fixo não comuta');
verificar($dia['resumo']['otimo']['intervalos_violacao'] <= $dia['resumo']['automatico']['intervalos_violacao']
          && $dia['resumo']['otimo']['intervalos_violacao'] <= $dia['resumo']['fixo']['intervalos_violacao'],
          'tap ótimo não tem mais violações que as outras estratégias');
verificar($dia['resumo']['automatico']['v_max_dia'] > 1.05, 'relé na SE não enxerga a sobretensão causada pela GD no fim da linha');
verificar(CurvasTipicas::solar('ensolarado', 3.0) == 0.0 && CurvasTipicas::solar('ensolarado', 12.0) > 0.99, 'curva solar: zero à noite, pico ao meio-dia');

echo "\n" . ($total - $falhas) . "/$total testes passaram\n";
exit($falhas > 0 ? 1 : 0);
