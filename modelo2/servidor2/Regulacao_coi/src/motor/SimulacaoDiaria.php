<?php
/**
 * =============================================================================
 *  SIMULAÇÃO DE 24 HORAS (série temporal quase-estática, 96 intervalos de 15 min)
 * =============================================================================
 *  Em cada intervalo, carga e GD seguem curvas típicas (CurvasTipicas) e o
 *  fluxo de carga é resolvido. Três estratégias para o equipamento selecionado:
 *
 *   FIXO        -> tap parado na posição atual o dia inteiro.
 *   AUTOMATICO  -> controle de tensão como um relé 90 real: mede a tensão no
 *                  nó onde o equipamento está instalado e comuta um degrau
 *                  por vez até voltar para dentro de V_ref ± banda/2.
 *                  Ele só "enxerga" a própria barra; o fim do alimentador
 *                  pode sair da faixa sem que ele perceba (efeito clássico
 *                  da GD distante do regulador).
 *   OTIMO       -> em cada intervalo escolhe a melhor posição pelo mesmo
 *                  critério da otimização (UCs críticas, precárias, desvio,
 *                  menos degraus a partir do tap anterior). É o limite
 *                  teórico de desempenho, útil para comparar.
 *
 *  Indicadores diários:
 *   - comutações (soma dos degraus percorridos) -> desgaste do comutador;
 *   - % de intervalos com algum nó fora da faixa adequada;
 *   - DRP/DRC diários estimados: média, ao longo do dia, da fração de UCs em
 *     faixa precária/crítica (análogo temporal ao DRP/DRC do PRODIST);
 *   - energia de perdas (kWh) e horas com fluxo reverso.
 * =============================================================================
 */
class SimulacaoDiaria
{
    const INTERVALOS = 96;
    const DT_HORAS = 0.25;

    /** @var SimuladorTap */
    private $sim;

    public function __construct(SimuladorTap $sim)
    {
        $this->sim = $sim;
    }

    /**
     * @param array $opcoes perfil_carga, perfil_ceu, pico_carga (fator),
     *                      pico_gd (fator), v_fonte_pu, v_ref_pu, banda_pu
     */
    public function executar(array $al, array $nos, array $eqs, array $eqAlvo, array $opcoes)
    {
        $eqId = (int) $eqAlvo['id'];
        $base = array('v_fonte_pu' => $opcoes['v_fonte_pu'], 'taps' => array());

        // Potência cadastrada do alimentador (sem a carga equivalente da barra da SE)
        $cargaAlimKw = 0.0;
        $gdAlimKw = 0.0;
        foreach ($nos as $n) {
            if ($n['no_pai_codigo'] !== null && $n['no_pai_codigo'] !== '') {
                $cargaAlimKw += (float) $n['carga_kw'];
            }
            $gdAlimKw += (float) $n['gd_kw'];
        }

        $estrategias = array('fixo' => array(), 'automatico' => array(), 'otimo' => array());
        $tapAuto = (int) $eqAlvo['tap_atual'];
        $tapOtimo = (int) $eqAlvo['tap_atual'];
        $perfil = array();

        for ($k = 0; $k < self::INTERVALOS; $k++) {
            $t = $k * self::DT_HORAS;
            $fc = $opcoes['pico_carga'] * CurvasTipicas::carga($opcoes['perfil_carga'], $t);
            $fg = $opcoes['pico_gd'] * CurvasTipicas::solar($opcoes['perfil_ceu'], $t);
            $p = $base;
            $p['fator_carga'] = $fc;
            $p['fator_gd'] = $fg;

            $perfil[] = array(
                'hora'     => sprintf('%02d:%02d', (int) floor($t), (int) round(($t - floor($t)) * 60)),
                'carga_mw' => round($cargaAlimKw * $fc / 1000.0, 3),
                'gd_mw'    => round($gdAlimKw * $fg / 1000.0, 3),
            );

            // --- FIXO ---
            $estrategias['fixo'][] = $this->registro($this->rodar($al, $nos, $eqs, $p, $eqId, (int) $eqAlvo['tap_atual']),
                                                     (int) $eqAlvo['tap_atual'], 0);

            // --- AUTOMATICO (relé de tensão com banda morta) ---
            $tapAnterior = $tapAuto;
            $sim = $this->rodar($al, $nos, $eqs, $p, $eqId, $tapAuto);
            $limite = (int) $eqAlvo['tap_max'] - (int) $eqAlvo['tap_min'];
            for ($i = 0; $i < $limite && $sim !== null; $i++) {
                $v = $this->tensaoNo($sim, $eqAlvo['no_codigo']);
                if ($v === null || abs($v - $opcoes['v_ref_pu']) <= $opcoes['banda_pu'] / 2.0) {
                    break;
                }
                $novo = $tapAuto + ($v < $opcoes['v_ref_pu'] ? 1 : -1);
                if ($novo < (int) $eqAlvo['tap_min'] || $novo > (int) $eqAlvo['tap_max']) {
                    break; // fim de curso do comutador
                }
                $tapAuto = $novo;
                $sim = $this->rodar($al, $nos, $eqs, $p, $eqId, $tapAuto);
            }
            $estrategias['automatico'][] = $this->registro($sim, $tapAuto, abs($tapAuto - $tapAnterior));

            // --- OTIMO ---
            $tapAnterior = $tapOtimo;
            $eqRef = $eqAlvo;
            $eqRef['tap_atual'] = $tapOtimo; // desempate por menos degraus a partir do tap anterior
            $eqsRef = $this->substituirEquipamento($eqs, $eqRef);
            $o = $this->sim->otimizar($al, $nos, $eqsRef, $p, $eqRef);
            if ($o['melhor_tap'] !== null) {
                $tapOtimo = (int) $o['melhor_tap'];
            }
            $estrategias['otimo'][] = $this->registro($this->rodar($al, $nos, $eqs, $p, $eqId, $tapOtimo),
                                                      $tapOtimo, abs($tapOtimo - $tapAnterior));
        }

        $resumo = array();
        foreach ($estrategias as $nome => $serie) {
            $resumo[$nome] = $this->resumir($serie);
        }

        return array(
            'equipamento'  => $eqAlvo['codigo'],
            'no_regulado'  => $eqAlvo['no_codigo'],
            'tap_inicial'  => (int) $eqAlvo['tap_atual'],
            'opcoes'       => $opcoes,
            'perfil'       => $perfil,
            'series'       => $estrategias,
            'resumo'       => $resumo,
        );
    }

    // -------------------------------------------------------------------------

    private function rodar(array $al, array $nos, array $eqs, array $p, $eqId, $tap)
    {
        $p['taps'][$eqId] = $tap;
        try {
            return $this->sim->simular($al, $nos, $eqs, $p);
        } catch (RuntimeException $e) {
            return null;
        }
    }

    private function tensaoNo(array $sim, $codigo)
    {
        foreach ($sim['nos'] as $n) {
            if ($n['codigo'] === $codigo) {
                return $n['v_pu'];
            }
        }
        return null;
    }

    private function substituirEquipamento(array $eqs, array $eqNovo)
    {
        foreach ($eqs as $i => $e) {
            if ((int) $e['id'] === (int) $eqNovo['id']) {
                $eqs[$i] = $eqNovo;
            }
        }
        return $eqs;
    }

    private function registro($sim, $tap, $degraus)
    {
        if ($sim === null) {
            return array('tap' => $tap, 'degraus' => $degraus, 'convergiu' => false);
        }
        $ind = $sim['indicadores'];
        return array(
            'tap'           => $tap,
            'degraus'       => $degraus,
            'convergiu'     => true,
            'v_min_pu'      => $ind['v_min_pu'],
            'v_max_pu'      => $ind['v_max_pu'],
            'no_v_min'      => $ind['no_v_min'],
            'no_v_max'      => $ind['no_v_max'],
            'nos_fora'      => $ind['nos_precarios'] + $ind['nos_criticos'],
            'ucs_precaria'  => $ind['ucs_precaria'],
            'ucs_critica'   => $ind['ucs_critica'],
            'ucs_total'     => $ind['ucs_total'],
            'perdas_kw'     => $ind['perdas_kw'],
            'fluxo_reverso' => $ind['fluxo_reverso'],
        );
    }

    private function resumir(array $serie)
    {
        $n = count($serie);
        $comut = 0;
        $violacao = 0;
        $somaPrec = 0.0;
        $somaCrit = 0.0;
        $perdas = 0.0;
        $reverso = 0;
        $falhas = 0;
        $vMax = -INF;
        $vMin = INF;
        $hMax = null;
        $hMin = null;

        foreach ($serie as $k => $r) {
            $comut += $r['degraus'];
            if (!$r['convergiu']) {
                $falhas++;
                $violacao++;
                continue;
            }
            if ($r['nos_fora'] > 0) {
                $violacao++;
            }
            $tot = max(1, $r['ucs_total']);
            $somaPrec += $r['ucs_precaria'] / $tot;
            $somaCrit += $r['ucs_critica'] / $tot;
            $perdas += $r['perdas_kw'] * self::DT_HORAS;
            if ($r['fluxo_reverso']) {
                $reverso++;
            }
            if ($r['v_max_pu'] > $vMax) { $vMax = $r['v_max_pu']; $hMax = $k; }
            if ($r['v_min_pu'] < $vMin) { $vMin = $r['v_min_pu']; $hMin = $k; }
        }

        $hora = function ($k) {
            return $k === null ? null : sprintf('%02d:%02d', (int) floor($k / 4), ($k % 4) * 15);
        };

        return array(
            'comutacoes'            => $comut,
            'intervalos_violacao'   => $violacao,
            'violacao_percent'      => round($violacao / $n * 100.0, 1),
            'drp_diario'            => round($somaPrec / $n * 100.0, 2),
            'drc_diario'            => round($somaCrit / $n * 100.0, 2),
            'perdas_kwh'            => round($perdas, 0),
            'horas_fluxo_reverso'   => $reverso * self::DT_HORAS,
            'v_max_dia'             => $vMax === -INF ? null : $vMax,
            'hora_v_max'            => $hora($hMax),
            'v_min_dia'             => $vMin === INF ? null : $vMin,
            'hora_v_min'            => $hora($hMin),
            'falhas_convergencia'   => $falhas,
        );
    }
}
