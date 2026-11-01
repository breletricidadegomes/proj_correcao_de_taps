<?php
/**
 * =============================================================================
 *  FLUXO DE CARGA RADIAL - Método da Varredura Inversa/Direta (Backward/Forward
 *  Sweep), equivalente monofásico (sequência positiva), em p.u.
 * =============================================================================
 *  Substitui o pandapower.runpp() da versão Python/Flask. Para redes de
 *  distribuição radiais (alimentadores MT), o método converge rapidamente e
 *  não precisa de bibliotecas de álgebra linear — ideal para PHP 5.5 legado.
 *
 *  Modelo de cada ramo (nó pai -> nó i):
 *
 *      V_pai ──[ relação ideal a ]── a·V_pai ──[ Z = R + jX ]── V_i
 *
 *  - Para o transformador da SE, "a" é a relação do comutador sob carga (OLTC)
 *    e Z é a impedância de curto-circuito referida ao lado MT.
 *  - Para um regulador de tensão de linha, "a" é a relação do regulador e Z é
 *    a impedância do trecho de rede a jusante dele.
 *  - Para trechos comuns, a = 1.
 *
 *  Conservação de potência no elemento ideal (a real):
 *      corrente drenada do pai = a · J_i   (J_i = corrente que passa por Z)
 *
 *  Cargas: modelo potência constante, S_liquida = (P_carga - P_GD) + jQ.
 *
 *  Entrada ($nos): array indexado 0..n-1 em ORDEM TOPOLÓGICA (pai antes do
 *  filho). Cada item:
 *      'pai' => int   (-1 = conectado à fonte / barra AT)
 *      'r', 'x'       => impedância do ramo em p.u.
 *      'a'            => relação de tap do ramo (1.0 = sem regulação)
 *      'p', 'q'       => potência líquida consumida no nó, em p.u.
 * =============================================================================
 */
class FluxoCargaRadial
{
    private $tolerancia;
    private $maxIteracoes;

    public function __construct($tolerancia = 1e-6, $maxIteracoes = 60)
    {
        $this->tolerancia   = (float) $tolerancia;
        $this->maxIteracoes = (int) $maxIteracoes;
    }

    /**
     * Executa o fluxo de carga.
     *
     * @param float $vFontePu Módulo da tensão da fonte (barra AT) em p.u.
     * @param array $nos      Ver descrição no cabeçalho.
     * @return array          Tensões, correntes, fluxos e perdas por ramo.
     * @throws RuntimeException se não convergir.
     */
    public function executar($vFontePu, array $nos)
    {
        $n = count($nos);
        if ($n === 0) {
            throw new InvalidArgumentException('Rede sem nós.');
        }

        $vFonte = Complexo::criar($vFontePu, 0.0);

        // Lista de filhos e validação da ordem topológica
        $filhos = array_fill(0, $n, array());
        for ($i = 0; $i < $n; $i++) {
            $pai = $nos[$i]['pai'];
            if ($pai >= $i) {
                throw new InvalidArgumentException('Nós fora de ordem topológica (índice ' . $i . ').');
            }
            if ($pai >= 0) {
                $filhos[$pai][] = $i;
            }
        }

        // Estimativa inicial: perfil plano corrigido pelas relações de tap
        $V = array();
        for ($i = 0; $i < $n; $i++) {
            $vPai = ($nos[$i]['pai'] < 0) ? $vFonte : $V[$nos[$i]['pai']];
            $V[$i] = Complexo::escalar($vPai, $nos[$i]['a']);
        }

        $J = array();
        $convergiu = false;
        $iter = 0;

        for ($iter = 1; $iter <= $this->maxIteracoes; $iter++) {

            // ---- Varredura INVERSA: correntes das folhas para a fonte ----
            for ($i = $n - 1; $i >= 0; $i--) {
                $S = Complexo::criar($nos[$i]['p'], $nos[$i]['q']);
                // I_carga = conj(S / V)
                $Ii = Complexo::conj(Complexo::div($S, $V[$i]));
                foreach ($filhos[$i] as $f) {
                    $Ii = Complexo::soma($Ii, Complexo::escalar($J[$f], $nos[$f]['a']));
                }
                $J[$i] = $Ii;
            }

            // ---- Varredura DIRETA: tensões da fonte para as folhas ----
            $maxDelta = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $vPai = ($nos[$i]['pai'] < 0) ? $vFonte : $V[$nos[$i]['pai']];
                $Z    = Complexo::criar($nos[$i]['r'], $nos[$i]['x']);
                $vNovo = Complexo::sub(
                    Complexo::escalar($vPai, $nos[$i]['a']),
                    Complexo::mult($Z, $J[$i])
                );
                $delta = Complexo::modulo(Complexo::sub($vNovo, $V[$i]));
                if ($delta > $maxDelta) {
                    $maxDelta = $delta;
                }
                $V[$i] = $vNovo;
            }

            // Divergência numérica (carga/GD absurdas): aborta cedo
            if (is_nan($maxDelta) || $maxDelta > 10.0) {
                break;
            }
            if ($maxDelta < $this->tolerancia) {
                $convergiu = true;
                break;
            }
        }

        if (!$convergiu) {
            throw new RuntimeException(
                'Fluxo de carga não convergiu. Verifique se carga/geração não estão excessivas para a rede.'
            );
        }

        // ---- Pós-processamento: fluxos e perdas por ramo ----
        $ramos = array();
        for ($i = 0; $i < $n; $i++) {
            $vPai = ($nos[$i]['pai'] < 0) ? $vFonte : $V[$nos[$i]['pai']];
            $vEnvio = Complexo::escalar($vPai, $nos[$i]['a']);
            $sEnvio = Complexo::mult($vEnvio, Complexo::conj($J[$i]));   // entra no ramo
            $sReceb = Complexo::mult($V[$i], Complexo::conj($J[$i]));    // chega ao nó i
            $ramos[$i] = array(
                'i_pu'     => Complexo::modulo($J[$i]),
                'p_envio'  => $sEnvio[0],
                'q_envio'  => $sEnvio[1],
                'p_receb'  => $sReceb[0],
                'q_receb'  => $sReceb[1],
                'perda_p'  => $sEnvio[0] - $sReceb[0],
            );
        }

        $tensoes = array();
        for ($i = 0; $i < $n; $i++) {
            $tensoes[$i] = array(
                'v_pu'      => Complexo::modulo($V[$i]),
                'ang_graus' => rad2deg(Complexo::angulo($V[$i])),
            );
        }

        return array(
            'iteracoes' => $iter,
            'tensoes'   => $tensoes,
            'ramos'     => $ramos,
        );
    }
}
