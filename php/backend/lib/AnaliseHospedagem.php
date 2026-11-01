<?php
/**
 * =============================================================================
 *  CAPACIDADE DE HOSPEDAGEM DE GD (hosting capacity)
 * =============================================================================
 *  Pergunta respondida: "quantos kW de geração distribuída posso conectar
 *  neste nó antes que a rede saia dos limites?"
 *
 *  Um cenário é VIÁVEL quando, ao mesmo tempo:
 *    - todos os nós estão em faixa ADEQUADA (PRODIST Módulo 8);
 *    - nenhum trecho passa de 100% da ampacidade;
 *    - o transformador da SE não passa de 100% da potência nominal;
 *    - o fluxo de carga converge.
 *
 *  Dois modos de cálculo:
 *    TAP ATUAL   -> todos os comutadores ficam na posição em que estão.
 *    MELHOR TAP  -> para cada valor de GD testado, procura uma posição do
 *                   equipamento selecionado que torne o cenário viável,
 *                   começando pelas posições mais próximas da atual
 *                   (menor manobra).
 *
 *  Busca: duplicação (250, 500, 1000 kW...) até encontrar o primeiro valor
 *  inviável, seguida de bisseção até a resolução configurada (10 kW).
 *  Supõe que, a partir do cenário base viável, mais GD não "conserta" uma
 *  violação causada por GD — válido para sobretensão e sobrecarga, que são
 *  os limitantes típicos em carga leve.
 *
 *  Recomendação de uso: patamar de carga LEVE e despacho pleno da GD
 *  existente (pior caso para sobretensão).
 * =============================================================================
 */
class AnaliseHospedagem
{
    /** @var SimuladorTap */
    private $sim;
    private $resolucaoKw;
    private $passoInicialKw;
    private $maxKw;
    private $avaliacoes = 0;

    public function __construct(SimuladorTap $sim, array $cfg = array())
    {
        $this->sim            = $sim;
        $this->resolucaoKw    = isset($cfg['resolucao_kw']) ? (float) $cfg['resolucao_kw'] : 10.0;
        $this->passoInicialKw = isset($cfg['passo_inicial_kw']) ? (float) $cfg['passo_inicial_kw'] : 250.0;
        $this->maxKw          = isset($cfg['max_kw']) ? (float) $cfg['max_kw'] : 30000.0;
    }

    /**
     * Análise completa de um nó: capacidade nos dois modos, limiar de fluxo
     * reverso na saída do alimentador e curva Vmáx × GD adicional.
     */
    public function avaliarNo(array $al, array $nos, array $eqs, array $params, array $eqAlvo, $noCodigo)
    {
        $this->avaliacoes = 0;
        $fixo   = $this->capacidade($al, $nos, $eqs, $params, $eqAlvo, $noCodigo, 'fixo');
        $melhor = $this->capacidade($al, $nos, $eqs, $params, $eqAlvo, $noCodigo, 'melhor');

        return array(
            'no'              => $noCodigo,
            'equipamento'     => $eqAlvo['codigo'],
            'tap_atual'       => (int) $eqAlvo['tap_atual'],
            'tap_atual_kw'    => $fixo,
            'melhor_tap_kw'   => $melhor,
            'fluxo_reverso'   => $this->limiarFluxoReverso($al, $nos, $eqs, $params, $noCodigo),
            'curva'           => $this->curva($al, $nos, $eqs, $params, $eqAlvo, $noCodigo,
                                              max($fixo['kw'], $melhor['kw'])),
            'resolucao_kw'    => $this->resolucaoKw,
            'avaliacoes'      => $this->avaliacoes,
        );
    }

    /**
     * Mapa de hospedagem: capacidade de todos os nós (exceto a barra da SE).
     * Em redes grandes, limita a quantidade de nós para não estourar o tempo.
     */
    public function mapa(array $al, array $nos, array $eqs, array $params, array $eqAlvo, $limiteNos)
    {
        $this->avaliacoes = 0;
        $base = $this->sim->simular($al, $nos, $eqs, $params);
        $linhas = array();
        $truncado = false;
        foreach ($base['nos'] as $n) {
            if ($n['pai'] === null) {
                continue; // barra da SE
            }
            if (count($linhas) >= $limiteNos) {
                $truncado = true;
                break;
            }
            $f = $this->capacidade($al, $nos, $eqs, $params, $eqAlvo, $n['codigo'], 'fixo');
            $m = $this->capacidade($al, $nos, $eqs, $params, $eqAlvo, $n['codigo'], 'melhor');
            $linhas[] = array(
                'codigo'        => $n['codigo'],
                'nome'          => $n['nome'],
                'distancia_km'  => $n['distancia_km'],
                'kw_tap_atual'  => $f['kw'],
                'kw_melhor_tap' => $m['kw'],
                'tap_necessario'=> $m['tap'],
                // Limite que vale ajustando o tap; se nem assim há solução, o do tap atual
                'limitante'     => (!$m['base_viola'] && $m['limitante'] !== null) ? $m['limitante'] : $f['limitante'],
                'acima_maximo'  => $m['acima_maximo'],
            );
        }
        return array('nos' => $linhas, 'truncado' => $truncado, 'avaliacoes' => $this->avaliacoes);
    }

    // -------------------------------------------------------------------------
    //  Busca da capacidade
    // -------------------------------------------------------------------------
    private function capacidade(array $al, array $nos, array $eqs, array $params, array $eqAlvo, $noCodigo, $modo)
    {
        $r0 = $this->cenario($al, $nos, $eqs, $params, $eqAlvo, $noCodigo, 0.0, $modo);
        if (!$r0['viavel']) {
            return array(
                'kw' => 0.0, 'tap' => $r0['tap'], 'base_viola' => true, 'acima_maximo' => false,
                'limitante' => $r0['limitante'], 'tipo' => $r0['tipo'],
            );
        }

        $lo = 0.0;
        $loRes = $r0;
        $hi = null;
        $hiRes = null;
        $x = $this->passoInicialKw;

        // Fase 1: duplicação até achar um valor inviável (ou o máximo)
        while (true) {
            if ($x > $this->maxKw) {
                $x = $this->maxKw;
            }
            $r = $this->cenario($al, $nos, $eqs, $params, $eqAlvo, $noCodigo, $x, $modo);
            if ($r['viavel']) {
                $lo = $x;
                $loRes = $r;
                if ($x >= $this->maxKw) {
                    break;
                }
                $x *= 2;
            } else {
                $hi = $x;
                $hiRes = $r;
                break;
            }
        }

        if ($hi === null) {
            return array(
                'kw' => $lo, 'tap' => $loRes['tap'], 'base_viola' => false, 'acima_maximo' => true,
                'limitante' => null, 'tipo' => null,
            );
        }

        // Fase 2: bisseção até a resolução
        while (($hi - $lo) > $this->resolucaoKw) {
            $meio = round(($lo + $hi) / 2.0 / $this->resolucaoKw) * $this->resolucaoKw;
            if ($meio <= $lo || $meio >= $hi) {
                break;
            }
            $r = $this->cenario($al, $nos, $eqs, $params, $eqAlvo, $noCodigo, $meio, $modo);
            if ($r['viavel']) {
                $lo = $meio;
                $loRes = $r;
            } else {
                $hi = $meio;
                $hiRes = $r;
            }
        }

        return array(
            'kw' => $lo, 'tap' => $loRes['tap'], 'base_viola' => false, 'acima_maximo' => false,
            'limitante' => $hiRes['limitante'], 'tipo' => $hiRes['tipo'],
        );
    }

    /**
     * Avalia um valor de GD adicional no nó.
     * Modo 'melhor': testa as posições do equipamento alvo da mais próxima à
     * mais distante do tap atual e para na primeira viável.
     */
    private function cenario(array $al, array $nos, array $eqs, array $params, array $eqAlvo, $noCodigo, $kw, $modo)
    {
        $p = $params;
        $p['gd_extra'] = array($noCodigo => $kw);

        if ($modo === 'fixo') {
            $r = $this->rodar($al, $nos, $eqs, $p);
            $r['tap'] = (int) $eqAlvo['tap_atual'];
            return $r;
        }

        $pior = null;
        foreach (SimuladorTap::tapsPorProximidade($eqAlvo, $eqAlvo['tap_atual']) as $t) {
            $p['taps'][(int) $eqAlvo['id']] = $t;
            $r = $this->rodar($al, $nos, $eqs, $p);
            $r['tap'] = $t;
            if ($r['viavel']) {
                return $r;
            }
            if ($pior === null || $r['severidade'] < $pior['severidade']) {
                $pior = $r; // guarda a posição "menos ruim" para explicar o limitante
            }
        }
        return $pior;
    }

    /** Roda o fluxo e verifica os critérios de viabilidade */
    private function rodar(array $al, array $nos, array $eqs, array $p)
    {
        $this->avaliacoes++;
        try {
            $sim = $this->sim->simular($al, $nos, $eqs, $p);
        } catch (RuntimeException $e) {
            return array('viavel' => false, 'tipo' => 'NAO_CONVERGIU', 'severidade' => INF,
                         'limitante' => 'Fluxo de carga não converge', 'v_max' => null);
        }
        return $this->verificar($sim);
    }

    /** Identifica a violação mais severa de uma simulação */
    private function verificar(array $sim)
    {
        $lim = $sim['limites_pu'];
        $piorV = null;
        $nViol = 0;
        foreach ($sim['nos'] as $n) {
            if ($n['classe'] === ProdistTensao::ADEQUADA) {
                continue;
            }
            $nViol++;
            $excesso = $n['sentido'] === 'sobretensao' ? $n['v_pu'] - $lim[2] : $lim[1] - $n['v_pu'];
            if ($piorV === null || $excesso > $piorV['excesso']) {
                $piorV = array('no' => $n, 'excesso' => $excesso);
            }
        }

        $piorTrecho = null;
        foreach ($sim['trechos'] as $t) {
            if ($t['carregamento_percent'] !== null && $t['carregamento_percent'] > 100.0
                && ($piorTrecho === null || $t['carregamento_percent'] > $piorTrecho['carregamento_percent'])) {
                $piorTrecho = $t;
            }
        }
        $trafo = $sim['trafo'];
        $trafoSobrecarga = $trafo !== null && $trafo['carregamento_percent'] > 100.0;

        $resultado = array(
            'viavel'     => true,
            'tipo'       => null,
            'limitante'  => null,
            'severidade' => 0.0,
            'v_max'      => $sim['indicadores']['v_max_pu'],
        );

        if ($piorV !== null) {
            $n = $piorV['no'];
            $resultado['viavel'] = false;
            $resultado['tipo'] = $n['sentido'] === 'sobretensao' ? 'SOBRETENSAO' : 'SUBTENSAO';
            $resultado['limitante'] = sprintf('%s no nó %s (%s p.u.)',
                $n['sentido'] === 'sobretensao' ? 'Sobretensão' : 'Subtensão',
                $n['codigo'], number_format($n['v_pu'], 3, ',', '.'));
            $resultado['severidade'] = $nViol * 1000 + $piorV['excesso'] * 100;
        }
        if ($piorTrecho !== null) {
            if ($resultado['viavel']) {
                $resultado['tipo'] = 'SOBRECARGA_TRECHO';
                $resultado['limitante'] = sprintf('Sobrecarga no trecho %s → %s (%s%% da ampacidade)',
                    $piorTrecho['de'], $piorTrecho['para'], number_format($piorTrecho['carregamento_percent'], 1, ',', '.'));
            }
            $resultado['viavel'] = false;
            $resultado['severidade'] += $piorTrecho['carregamento_percent'];
        }
        if ($trafoSobrecarga) {
            if ($resultado['viavel']) {
                $resultado['tipo'] = 'SOBRECARGA_TRAFO';
                $resultado['limitante'] = sprintf('Sobrecarga no transformador da SE (%s%%)',
                    number_format($trafo['carregamento_percent'], 1, ',', '.'));
            }
            $resultado['viavel'] = false;
            $resultado['severidade'] += $trafo['carregamento_percent'];
        }
        return $resultado;
    }

    // -------------------------------------------------------------------------
    //  Fluxo reverso na saída do alimentador
    // -------------------------------------------------------------------------
    /**
     * Menor GD adicional no nó que inverte o fluxo no primeiro trecho do
     * caminho SE → nó (o alimentador passa a exportar para a barra da SE).
     * Se o nó for a própria barra da SE, avalia o transformador.
     */
    private function limiarFluxoReverso(array $al, array $nos, array $eqs, array $params, $noCodigo)
    {
        $pai = array();
        $raiz = null;
        foreach ($nos as $n) {
            $pc = ($n['no_pai_codigo'] === '' ? null : $n['no_pai_codigo']);
            $pai[$n['codigo']] = $pc;
            if ($pc === null) {
                $raiz = $n['codigo'];
            }
        }
        $cabeca = $noCodigo;
        while ($cabeca !== null && $pai[$cabeca] !== null && $pai[$cabeca] !== $raiz) {
            $cabeca = $pai[$cabeca];
        }
        $noTrafo = ($noCodigo === $raiz);
        $descricao = $noTrafo ? 'transformador da SE' : 'trecho ' . $raiz . ' → ' . $cabeca;

        // Closures herdam $this e o escopo da classe desde o PHP 5.4
        $potencia = function ($kw) use ($al, $nos, $eqs, $params, $noCodigo, $cabeca, $noTrafo) {
            return $this->potenciaNaCabeca($al, $nos, $eqs, $params, $noCodigo, $kw, $cabeca, $noTrafo);
        };

        $p0 = $potencia(0.0);
        if ($p0 === null) {
            return null;
        }
        if ($p0 < 0) {
            return array('kw' => 0.0, 'onde' => $descricao, 'ja_reverso' => true, 'p_base_kw' => round($p0, 1));
        }

        // A potência na cabeça cai quase 1:1 com a GD: parte da estimativa linear
        $lo = 0.0;
        $hi = min($this->maxKw, max($this->passoInicialKw, $p0 * 1.1));
        $ph = $potencia($hi);
        while ($ph !== null && $ph >= 0 && $hi < $this->maxKw) {
            $lo = $hi;
            $hi = min($this->maxKw, $hi * 2);
            $ph = $potencia($hi);
        }
        if ($ph === null || $ph >= 0) {
            return array('kw' => null, 'onde' => $descricao, 'ja_reverso' => false, 'p_base_kw' => round($p0, 1));
        }
        while (($hi - $lo) > $this->resolucaoKw) {
            $meio = ($lo + $hi) / 2.0;
            $pm = $potencia($meio);
            if ($pm !== null && $pm >= 0) {
                $lo = $meio;
            } else {
                $hi = $meio;
            }
        }
        return array(
            'kw' => round(ceil($hi / $this->resolucaoKw) * $this->resolucaoKw, 0),
            'onde' => $descricao, 'ja_reverso' => false, 'p_base_kw' => round($p0, 1),
        );
    }

    /** Potência ativa (kW) que sai da SE pela cabeça do caminho até o nó */
    private function potenciaNaCabeca(array $al, array $nos, array $eqs, array $params, $noCodigo, $kw, $cabeca, $noTrafo)
    {
        $p = $params;
        $p['gd_extra'] = array($noCodigo => $kw);
        $this->avaliacoes++;
        try {
            $sim = $this->sim->simular($al, $nos, $eqs, $p);
        } catch (RuntimeException $e) {
            return null;
        }
        if ($noTrafo) {
            return $sim['trafo'] !== null ? $sim['trafo']['p_kw'] : null;
        }
        foreach ($sim['trechos'] as $t) {
            if ($t['para'] === $cabeca) {
                return $t['p_kw'];
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    //  Curva V máx × GD adicional (para o gráfico)
    // -------------------------------------------------------------------------
    private function curva(array $al, array $nos, array $eqs, array $params, array $eqAlvo, $noCodigo, $kwRef)
    {
        $xMax = min($this->maxKw, max(500.0, ceil($kwRef * 1.4 / 100.0) * 100.0));
        $pontos = array();
        $n = 14;
        for ($i = 0; $i <= $n; $i++) {
            $kw = round($xMax * $i / $n, 0);
            $p = $params;
            $p['gd_extra'] = array($noCodigo => $kw);

            $vFixo = null;
            try {
                $this->avaliacoes++;
                $s = $this->sim->simular($al, $nos, $eqs, $p);
                $vFixo = $s['indicadores']['v_max_pu'];
            } catch (RuntimeException $e) {
                $vFixo = null;
            }

            $vMelhor = null;
            $tapMelhor = null;
            $this->avaliacoes += (int) $eqAlvo['tap_max'] - (int) $eqAlvo['tap_min'] + 1;
            $o = $this->sim->otimizar($al, $nos, $eqs, $p, $eqAlvo);
            if ($o['melhor_tap'] !== null) {
                $tapMelhor = $o['melhor_tap'];
                foreach ($o['resultados'] as $r) {
                    if ($r['tap'] === $tapMelhor) {
                        $vMelhor = $r['v_max_pu'];
                    }
                }
            }
            $pontos[] = array('kw' => $kw, 'v_max_tap_atual' => $vFixo, 'v_max_melhor_tap' => $vMelhor, 'tap_melhor' => $tapMelhor);
        }
        return $pontos;
    }
}
