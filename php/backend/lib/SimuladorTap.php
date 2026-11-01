<?php
/**
 * =============================================================================
 *  SIMULADOR DE MUDANÇA DE TAP
 * =============================================================================
 *  Monta o modelo elétrico do alimentador a partir dos dados cadastrados
 *  (nós, trechos, transformador da SE com OLTC e reguladores de linha),
 *  executa o fluxo de carga e calcula os indicadores de conformidade de tensão.
 *
 *  Convenção de tap adotada (documente para os operadores!):
 *     posição POSITIVA  -> ELEVA a tensão no lado regulado (MT / jusante)
 *     posição NEGATIVA  -> REDUZ a tensão no lado regulado
 *     relação a = 1 + (tap - tap_neutro) × passo% / 100
 *
 *  Ex.: OLTC ±8 × 1,25%  -> tap +2 eleva a tensão MT em 2,5%.
 *       Regulador 32 deg. ±10% (16 elevar / 16 reduzir, 0,625%/degrau).
 * =============================================================================
 */
class SimuladorTap
{
    private $sBaseMva;
    private $fluxo;

    public function __construct(array $cfgCalculo)
    {
        $this->sBaseMva = (float) $cfgCalculo['potencia_base_mva'];
        $this->fluxo    = new FluxoCargaRadial($cfgCalculo['tolerancia_pu'], $cfgCalculo['max_iteracoes']);
    }

    /**
     * Executa uma simulação completa.
     *
     * @param array $alimentador  Registro do alimentador
     * @param array $nosCad       Nós cadastrados (com dados do trecho a montante)
     * @param array $equipamentos Equipamentos de regulação do alimentador
     * @param array $params       v_fonte_pu, fator_carga, fator_gd, taps (id => posição)
     */
    public function simular(array $alimentador, array $nosCad, array $equipamentos, array $params)
    {
        $vnKv  = (float) $alimentador['tensao_nominal_kv'];
        $zBase = ($vnKv * $vnKv) / $this->sBaseMva;                       // ohm
        $iBase = $this->sBaseMva * 1000.0 / (sqrt(3.0) * $vnKv);          // A

        $ordem = $this->ordenarTopologia($nosCad);
        $indicePorCodigo = array();
        foreach ($ordem as $idx => $no) {
            $indicePorCodigo[$no['codigo']] = $idx;
        }

        // Equipamentos indexados pelo nó de instalação
        $equipPorNo = array();
        $tapsAplicados = array();
        foreach ($equipamentos as $eq) {
            $tap = isset($params['taps'][$eq['id']]) ? (int) $params['taps'][$eq['id']] : (int) $eq['tap_atual'];
            $tap = max((int) $eq['tap_min'], min((int) $eq['tap_max'], $tap));
            $eq['tap_aplicado'] = $tap;
            $equipPorNo[$eq['no_codigo']] = $eq;
            $tapsAplicados[$eq['id']] = $tap;
        }

        $fc = (float) $params['fator_carga'];
        $fg = (float) $params['fator_gd'];

        // ---------------- Montagem do modelo em p.u. ----------------
        $modelo = array();
        $distancia = array();
        $trafo = null;
        foreach ($ordem as $idx => $no) {
            $paiIdx = ($no['no_pai_codigo'] === null) ? -1 : $indicePorCodigo[$no['no_pai_codigo']];
            $r = 0.0;
            $x = 0.0;
            $a = 1.0;

            if ($paiIdx < 0) {
                // Ramo raiz = transformador da subestação (se cadastrado)
                $distancia[$idx] = 0.0;
                if (isset($equipPorNo[$no['codigo']]) && $equipPorNo[$no['codigo']]['tipo'] === 'TRAFO_SE') {
                    $trafo = $equipPorNo[$no['codigo']];
                    $snMva = (float) $trafo['sn_kva'] / 1000.0;
                    $zPu   = ((float) $trafo['vk_percent'] / 100.0) * ($this->sBaseMva / $snMva);
                    $r     = ((float) $trafo['vkr_percent'] / 100.0) * ($this->sBaseMva / $snMva);
                    $x     = sqrt(max(0.0, $zPu * $zPu - $r * $r));
                    // Relação nominal (ex.: trafo 69/13,8 kV em rede 13,8 kV -> 1,0)
                    $aNominal = (float) $trafo['tensao_secundaria_kv'] / $vnKv;
                    $a = $aNominal * $this->relacaoTap($trafo, $trafo['tap_aplicado']);
                }
            } else {
                $comp = (float) $no['comprimento_km'];
                $r = (float) $no['r_ohm_km'] * $comp / $zBase;
                $x = (float) $no['x_ohm_km'] * $comp / $zBase;
                $distancia[$idx] = $distancia[$paiIdx] + $comp;
                if (isset($equipPorNo[$no['codigo']]) && $equipPorNo[$no['codigo']]['tipo'] === 'REGULADOR') {
                    $reg = $equipPorNo[$no['codigo']];
                    $a = $this->relacaoTap($reg, $reg['tap_aplicado']);
                }
            }

            $pKw = (float) $no['carga_kw'] * $fc - (float) $no['gd_kw'] * $fg;
            $qKvar = (float) $no['carga_kvar'] * $fc;

            $modelo[$idx] = array(
                'pai' => $paiIdx,
                'r'   => $r,
                'x'   => $x,
                'a'   => $a,
                'p'   => $pKw / 1000.0 / $this->sBaseMva,
                'q'   => $qKvar / 1000.0 / $this->sBaseMva,
            );
        }

        // ---------------- Fluxo de carga ----------------
        $res = $this->fluxo->executar((float) $params['v_fonte_pu'], $modelo);

        // ---------------- Resultados por nó / trecho ----------------
        $vRaiz = $res['tensoes'][0]['v_pu'];
        $nos = array();
        $trechos = array();
        $cont = array(
            'ucs_total' => 0, 'ucs_precaria' => 0, 'ucs_critica' => 0,
            'nos_adequados' => 0, 'nos_precarios' => 0, 'nos_criticos' => 0,
        );
        $vMin = INF; $vMax = -INF; $noVmin = ''; $noVmax = '';
        $quedaMax = 0.0;
        $perdasKw = 0.0;
        $cargaTotalKw = 0.0;
        $gdTotalKw = 0.0;
        $reversoTrecho = false;

        foreach ($ordem as $idx => $no) {
            $v = $res['tensoes'][$idx]['v_pu'];
            $cls = ProdistTensao::classificar($v, $vnKv);
            $ucs = (int) $no['num_consumidores'];
            $queda = ($vRaiz - $v) / $vRaiz * 100.0;

            $cont['ucs_total'] += $ucs;
            if ($cls['classe'] === ProdistTensao::ADEQUADA) {
                $cont['nos_adequados']++;
            } elseif ($cls['classe'] === ProdistTensao::PRECARIA) {
                $cont['nos_precarios']++;
                $cont['ucs_precaria'] += $ucs;
            } else {
                $cont['nos_criticos']++;
                $cont['ucs_critica'] += $ucs;
            }

            if ($v < $vMin) { $vMin = $v; $noVmin = $no['codigo']; }
            if ($v > $vMax) { $vMax = $v; $noVmax = $no['codigo']; }
            if ($queda > $quedaMax) { $quedaMax = $queda; }

            $cargaTotalKw += (float) $no['carga_kw'] * $fc;
            $gdTotalKw    += (float) $no['gd_kw'] * $fg;

            $nos[] = array(
                'codigo'        => $no['codigo'],
                'nome'          => $no['nome'],
                'pai'           => $no['no_pai_codigo'],
                'distancia_km'  => round($distancia[$idx], 2),
                'v_pu'          => round($v, 4),
                'v_kv'          => round($v * $vnKv, 3),
                'angulo_graus'  => round($res['tensoes'][$idx]['ang_graus'], 2),
                'queda_percent' => round($queda, 2),
                'classe'        => $cls['classe'],
                'sentido'       => $cls['sentido'],
                'consumidores'  => $ucs,
                'gd_kw'         => round((float) $no['gd_kw'] * $fg, 1),
                'regulador'     => (isset($equipPorNo[$no['codigo']]) && $equipPorNo[$no['codigo']]['tipo'] === 'REGULADOR')
                                   ? $equipPorNo[$no['codigo']]['codigo'] : null,
            );

            $ramo = $res['ramos'][$idx];
            $perdasKw += $ramo['perda_p'] * $this->sBaseMva * 1000.0;

            if ($idx > 0) {
                $iA = $ramo['i_pu'] * $iBase;
                $amp = (float) $no['ampacidade_a'];
                $pKw = $ramo['p_envio'] * $this->sBaseMva * 1000.0;
                $rev = $pKw < 0;
                if ($rev) { $reversoTrecho = true; }
                $trechos[] = array(
                    'de'                  => $no['no_pai_codigo'],
                    'para'                => $no['codigo'],
                    'p_kw'                => round($pKw, 1),
                    'q_kvar'              => round($ramo['q_envio'] * $this->sBaseMva * 1000.0, 1),
                    'corrente_a'          => round($iA, 1),
                    'carregamento_percent'=> $amp > 0 ? round($iA / $amp * 100.0, 1) : null,
                    'perdas_kw'           => round($ramo['perda_p'] * $this->sBaseMva * 1000.0, 2),
                    'fluxo_reverso'       => $rev,
                );
            }
        }

        // Transformador da SE (ramo raiz)
        $trafoRes = null;
        if ($trafo !== null) {
            $r0 = $res['ramos'][0];
            $pKw = $r0['p_envio'] * $this->sBaseMva * 1000.0;
            $qKvar = $r0['q_envio'] * $this->sBaseMva * 1000.0;
            $sKva = sqrt($pKw * $pKw + $qKvar * $qKvar);
            $trafoRes = array(
                'codigo'               => $trafo['codigo'],
                'tap'                  => $trafo['tap_aplicado'],
                'p_kw'                 => round($pKw, 1),
                'q_kvar'               => round($qKvar, 1),
                's_kva'                => round($sKva, 1),
                'carregamento_percent' => round($sKva / (float) $trafo['sn_kva'] * 100.0, 1),
                'fluxo_reverso'        => $pKw < 0,
            );
        }

        $ucsTot = max(1, $cont['ucs_total']);
        $indicadores = array(
            'v_min_pu'            => round($vMin, 4),
            'v_max_pu'            => round($vMax, 4),
            'no_v_min'            => $noVmin,
            'no_v_max'            => $noVmax,
            'v_barra_se_pu'       => round($vRaiz, 4),
            'queda_max_percent'   => round($quedaMax, 2),
            'perdas_kw'           => round($perdasKw, 1),
            'perdas_percent'      => $cargaTotalKw > 0 ? round($perdasKw / ($cargaTotalKw + $perdasKw) * 100.0, 2) : 0.0,
            'carga_total_kw'      => round($cargaTotalKw, 1),
            'gd_total_kw'         => round($gdTotalKw, 1),
            'drp_estimado'        => round($cont['ucs_precaria'] / $ucsTot * 100.0, 2),
            'drc_estimado'        => round($cont['ucs_critica'] / $ucsTot * 100.0, 2),
            'icc_percent'         => round($cont['ucs_critica'] / $ucsTot * 100.0, 2),
            'ucs_total'           => $cont['ucs_total'],
            'ucs_precaria'        => $cont['ucs_precaria'],
            'ucs_critica'         => $cont['ucs_critica'],
            'nos_adequados'       => $cont['nos_adequados'],
            'nos_precarios'       => $cont['nos_precarios'],
            'nos_criticos'        => $cont['nos_criticos'],
            'desvio'              => round(abs(1.0 - $vMin) + abs(1.0 - $vMax), 4),
            'fluxo_reverso'       => $reversoTrecho || ($trafoRes !== null && $trafoRes['fluxo_reverso']),
            'iteracoes'           => $res['iteracoes'],
        );

        return array(
            'nos'          => $nos,
            'trechos'      => $trechos,
            'trafo'        => $trafoRes,
            'indicadores'  => $indicadores,
            'taps'         => $tapsAplicados,
            'limites_pu'   => ProdistTensao::limites($vnKv),
        );
    }

    /**
     * Varre todas as posições de tap do equipamento alvo e recomenda a melhor.
     *
     * Critérios (ordem de prioridade):
     *   1. menor nº de UCs em faixa CRÍTICA
     *   2. menor nº de UCs em faixa PRECÁRIA
     *   3. menor desvio |1 - Vmín| + |1 - Vmáx|  (perfil centrado em 1,0 p.u.)
     *   4. menor nº de degraus a partir do tap atual (menos desgaste do comutador)
     *   5. menores perdas técnicas
     */
    public function otimizar(array $alimentador, array $nosCad, array $equipamentos, array $params, array $equipAlvo)
    {
        $resultados = array();
        $melhor = null;
        $tapAtual = (int) $equipAlvo['tap_atual'];

        for ($t = (int) $equipAlvo['tap_min']; $t <= (int) $equipAlvo['tap_max']; $t++) {
            $p = $params;
            $p['taps'][$equipAlvo['id']] = $t;
            try {
                $sim = $this->simular($alimentador, $nosCad, $equipamentos, $p);
                $ind = $sim['indicadores'];
                $linha = array(
                    'tap'          => $t,
                    'v_min_pu'     => $ind['v_min_pu'],
                    'v_max_pu'     => $ind['v_max_pu'],
                    'desvio'       => $ind['desvio'],
                    'drp_estimado' => $ind['drp_estimado'],
                    'drc_estimado' => $ind['drc_estimado'],
                    'perdas_kw'    => $ind['perdas_kw'],
                    'ucs_precaria' => $ind['ucs_precaria'],
                    'ucs_critica'  => $ind['ucs_critica'],
                    'status'       => ($ind['nos_precarios'] + $ind['nos_criticos']) === 0 ? 'OK' : 'VIOLACAO',
                    'degraus'      => abs($t - $tapAtual),
                );
                if ($melhor === null || $this->comparar($linha, $melhor) < 0) {
                    $melhor = $linha;
                }
            } catch (RuntimeException $e) {
                $linha = array('tap' => $t, 'status' => 'NAO_CONVERGIU');
            }
            $resultados[] = $linha;
        }

        $acao = 'MANTER';
        if ($melhor !== null && $melhor['tap'] > $tapAtual) {
            $acao = 'ELEVAR';
        } elseif ($melhor !== null && $melhor['tap'] < $tapAtual) {
            $acao = 'REDUZIR';
        }

        return array(
            'equipamento'   => $equipAlvo['codigo'],
            'tap_atual'     => $tapAtual,
            'melhor_tap'    => $melhor !== null ? $melhor['tap'] : null,
            'solucao_plena' => $melhor !== null && $melhor['status'] === 'OK',
            'acao'          => $acao,
            'degraus'       => $melhor !== null ? $melhor['degraus'] : 0,
            'resultados'    => $resultados,
        );
    }

    // -------------------------------------------------------------------------

    private function comparar($a, $b)
    {
        $chaves = array('ucs_critica', 'ucs_precaria', 'desvio', 'degraus', 'perdas_kw');
        foreach ($chaves as $k) {
            $va = round($a[$k], 3);
            $vb = round($b[$k], 3);
            if ($va < $vb) { return -1; }
            if ($va > $vb) { return 1; }
        }
        return 0;
    }

    /** Relação de transformação do comutador para a posição informada */
    private function relacaoTap(array $eq, $tap)
    {
        return 1.0 + ((int) $tap - (int) $eq['tap_neutro']) * (float) $eq['tap_passo_percent'] / 100.0;
    }

    /**
     * Ordena os nós em ordem topológica (busca em largura a partir da barra da SE)
     * e valida radialidade: exatamente uma raiz, sem nós órfãos ou malhas.
     */
    private function ordenarTopologia(array $nosCad)
    {
        $porCodigo = array();
        $filhos = array();
        $raiz = null;

        foreach ($nosCad as $no) {
            if ($no['no_pai_codigo'] === '' ) {
                $no['no_pai_codigo'] = null;
            }
            $porCodigo[$no['codigo']] = $no;
            if ($no['no_pai_codigo'] === null) {
                if ($raiz !== null) {
                    throw new RuntimeException('Topologia inválida: mais de uma barra raiz (sem nó pai).');
                }
                $raiz = $no['codigo'];
            } else {
                $filhos[$no['no_pai_codigo']][] = $no['codigo'];
            }
        }
        if ($raiz === null) {
            throw new RuntimeException('Topologia inválida: nenhuma barra raiz (barra da SE) cadastrada.');
        }

        $ordem = array();
        $fila = array($raiz);
        $visitado = array();
        while (!empty($fila)) {
            $cod = array_shift($fila);
            if (isset($visitado[$cod])) {
                throw new RuntimeException('Topologia inválida: malha detectada no nó ' . $cod . '.');
            }
            $visitado[$cod] = true;
            $ordem[] = $porCodigo[$cod];
            if (isset($filhos[$cod])) {
                foreach ($filhos[$cod] as $f) {
                    $fila[] = $f;
                }
            }
        }

        if (count($ordem) !== count($porCodigo)) {
            throw new RuntimeException('Topologia inválida: existem nós órfãos (pai inexistente).');
        }
        return $ordem;
    }
}
