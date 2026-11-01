<?php
/**
 * =============================================================================
 *  Classificação da tensão em regime permanente - PRODIST Módulo 8 (ANEEL)
 * =============================================================================
 *  Faixas de atendimento em relação à Tensão de Referência (TR):
 *
 *  MT (1 kV < Vn < 69 kV):
 *     Adequada : 0,93 ≤ TL ≤ 1,05
 *     Precária : 0,90 ≤ TL < 0,93
 *     Crítica  : TL < 0,90  ou  TL > 1,05
 *
 *  BT 380/220 V (fase-fase):  Adequada 350–399 V | Precária 331–350 / 399–403 V
 *  BT 220/127 V (fase-fase):  Adequada 202–231 V | Precária 191–202 / 231–233 V
 *
 *  Indicadores individuais (PRODIST): DRP = nlp/1008·100 e DRC = nlc/1008·100,
 *  apurados com 1008 leituras de 10 min. Como a simulação é um RETRATO
 *  instantâneo, a ferramenta calcula ESTIMATIVAS ponderadas por número de
 *  unidades consumidoras (UCs) em cada faixa — útil para comparar cenários de
 *  tap, mas não substitui a medição regulatória.
 *
 *  ICC (Índice de UCs com tensão crítica) = UCs em faixa crítica / UCs totais.
 *  >>> Revise os valores abaixo caso a ANEEL publique nova revisão do Módulo 8.
 * =============================================================================
 */
class ProdistTensao
{
    const ADEQUADA = 'ADEQUADA';
    const PRECARIA = 'PRECARIA';
    const CRITICA  = 'CRITICA';

    /**
     * Retorna os limites (em p.u.) para a tensão nominal informada.
     * Formato: array(crit_inf, prec_inf, adeq_sup, prec_sup)
     *   crítica  < crit_inf
     *   precária [crit_inf, prec_inf)
     *   adequada [prec_inf, adeq_sup]
     *   precária (adeq_sup, prec_sup]
     *   crítica  > prec_sup
     */
    public static function limites($vnKv)
    {
        $vnKv = (float) $vnKv;

        if ($vnKv > 1.0 && $vnKv < 69.0) {
            // Média tensão: sem faixa precária superior (acima de 1,05 já é crítica)
            return array(0.90, 0.93, 1.05, 1.05);
        }
        if (abs($vnKv - 0.38) < 0.01) {
            return array(331 / 380, 350 / 380, 399 / 380, 403 / 380);
        }
        if (abs($vnKv - 0.22) < 0.01) {
            return array(191 / 220, 202 / 220, 231 / 220, 233 / 220);
        }
        // Demais níveis (ex.: 69 kV+): adota faixa de AT 0,95–1,05 / 0,90
        return array(0.90, 0.95, 1.05, 1.07);
    }

    /**
     * @return array('classe' => ADEQUADA|PRECARIA|CRITICA, 'sentido' => 'normal'|'subtensao'|'sobretensao')
     */
    public static function classificar($vPu, $vnKv)
    {
        list($critInf, $precInf, $adeqSup, $precSup) = self::limites($vnKv);
        // Arredonda a 4 casas para evitar flutuações numéricas na fronteira
        $v = round($vPu, 4);

        if ($v < $critInf) {
            return array('classe' => self::CRITICA, 'sentido' => 'subtensao');
        }
        if ($v < $precInf) {
            return array('classe' => self::PRECARIA, 'sentido' => 'subtensao');
        }
        if ($v <= $adeqSup) {
            return array('classe' => self::ADEQUADA, 'sentido' => 'normal');
        }
        if ($v <= $precSup) {
            return array('classe' => self::PRECARIA, 'sentido' => 'sobretensao');
        }
        return array('classe' => self::CRITICA, 'sentido' => 'sobretensao');
    }
}
