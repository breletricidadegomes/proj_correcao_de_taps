<?php
/**
 * Rede de demonstração (mesmos dados do seed em /sql/*.sql).
 * Usada quando 'modo_demo' => true em config/config.php.
 *
 * Cabos típicos (valores aproximados, sequência positiva):
 *   336,4 MCM CAA : R = 0,190 ohm/km | X = 0,390 ohm/km | 530 A
 *   4/0 AWG CAA   : R = 0,368 ohm/km | X = 0,420 ohm/km | 340 A
 *   1/0 AWG CAA   : R = 0,696 ohm/km | X = 0,450 ohm/km | 230 A
 */
class DadosDemo
{
    public static function alimentadores()
    {
        return array(
            array('id' => 1, 'codigo' => 'CTR-01', 'nome' => 'Alimentador CTR-01 - Distrito Industrial',
                  'subestacao' => 'SE Centro 69/13,8 kV', 'tensao_nominal_kv' => 13.8),
            array('id' => 2, 'codigo' => 'CTR-02', 'nome' => 'Alimentador CTR-02 - Zona Rural / Usina FV',
                  'subestacao' => 'SE Centro 69/13,8 kV', 'tensao_nominal_kv' => 13.8),
        );
    }

    public static function equipamentos()
    {
        return array(
            // OLTC do trafo da SE (um registro por alimentador modelado)
            array('id' => 1, 'alimentador_id' => 1, 'tipo' => 'TRAFO_SE', 'codigo' => 'TR-01 SE CTR',
                  'descricao' => 'Trafo 69/13,8 kV 26,6 MVA - OLTC ±8 x 1,25%', 'no_codigo' => 'BSE-CTR1',
                  'sn_kva' => 26600, 'vk_percent' => 10.0, 'vkr_percent' => 0.5,
                  'tensao_primaria_kv' => 69.0, 'tensao_secundaria_kv' => 13.8,
                  'tap_min' => -8, 'tap_max' => 8, 'tap_neutro' => 0, 'tap_passo_percent' => 1.25, 'tap_atual' => 0),
            // Regulador de tensão de linha no tronco
            array('id' => 2, 'alimentador_id' => 1, 'tipo' => 'REGULADOR', 'codigo' => 'RT-0142',
                  'descricao' => 'Banco de reguladores 3x 167 kVA - 32 degraus ±10%', 'no_codigo' => 'P05',
                  'sn_kva' => 501, 'vk_percent' => 0, 'vkr_percent' => 0,
                  'tensao_primaria_kv' => 13.8, 'tensao_secundaria_kv' => 13.8,
                  'tap_min' => -16, 'tap_max' => 16, 'tap_neutro' => 0, 'tap_passo_percent' => 0.625, 'tap_atual' => 0),
            array('id' => 3, 'alimentador_id' => 2, 'tipo' => 'TRAFO_SE', 'codigo' => 'TR-01 SE CTR',
                  'descricao' => 'Trafo 69/13,8 kV 26,6 MVA - OLTC ±8 x 1,25%', 'no_codigo' => 'BSE-CTR2',
                  'sn_kva' => 26600, 'vk_percent' => 10.0, 'vkr_percent' => 0.5,
                  'tensao_primaria_kv' => 69.0, 'tensao_secundaria_kv' => 13.8,
                  'tap_min' => -8, 'tap_max' => 8, 'tap_neutro' => 0, 'tap_passo_percent' => 1.25, 'tap_atual' => 0),
        );
    }

    /**
     * Nós da rede. Os dados de trecho (r, x, comprimento, ampacidade) referem-se
     * ao trecho que liga o nó ao seu nó PAI.
     * Colunas: codigo, nome, pai, r, x, km, amp, kW, kvar, GD kW, UCs
     */
    public static function nos($alimentadorId)
    {
        $tab = array(
            1 => array(
                array('BSE-CTR1', 'Barra 13,8 kV SE Centro (eqv. demais alim.)', null, 0, 0, 0, 0, 7000, 2800, 0, 0),
                array('P01', 'Saída SE - Av. Brasil',          'BSE-CTR1', 0.190, 0.390, 1.5, 530, 350, 150, 0, 620),
                array('P02', 'Rótula Distrito Industrial',     'P01',      0.190, 0.390, 2.5, 530, 600, 280, 0, 310),
                array('P03', 'Frigorífico (cliente A4)',       'P02',      0.190, 0.390, 2.0, 530, 1000, 480, 0, 1),
                array('P04', 'Bairro Jardim América',          'P03',      0.190, 0.390, 3.0, 530, 500, 210, 0, 1450),
                array('P05', 'Jusante RT-0142',                'P04',      0.368, 0.420, 2.5, 340, 380, 160, 0, 980),
                array('P06', 'Vila São José',                  'P05',      0.368, 0.420, 3.0, 340, 340, 140, 0, 1120),
                array('P07', 'Fim de tronco - Granjas',        'P06',      0.696, 0.450, 4.0, 230, 220, 95, 0, 540),
                array('R01', 'Ramal Cerâmica Santa Rita',      'P03',      0.696, 0.450, 3.5, 230, 400, 190, 0, 2),
                array('R02', 'Ramal Loteamento Novo Horizonte','R01',      0.696, 0.450, 2.5, 230, 260, 110, 0, 860),
            ),
            2 => array(
                array('BSE-CTR2', 'Barra 13,8 kV SE Centro (eqv. demais alim.)', null, 0, 0, 0, 0, 9000, 3600, 0, 0),
                array('Q01', 'Saída SE - Rod. MG-050',         'BSE-CTR2', 0.190, 0.390, 3.0, 530, 400, 170, 0, 380),
                array('Q02', 'Comunidade Ponte Alta',          'Q01',      0.368, 0.420, 4.0, 340, 350, 150, 0, 720),
                array('Q03', 'UFV Sol do Cerrado (GD 3 MW)',   'Q02',      0.368, 0.420, 6.0, 340, 50, 20, 4000, 1),
                array('Q04', 'Assentamento Boa Vista',         'Q03',      0.696, 0.450, 4.0, 230, 250, 110, 0, 610),
                array('Q05', 'Fim de linha - Irrigantes',      'Q04',      0.696, 0.450, 3.0, 230, 300, 140, 0, 95),
            ),
        );

        if (!isset($tab[$alimentadorId])) {
            return array();
        }
        $saida = array();
        foreach ($tab[$alimentadorId] as $i => $l) {
            $saida[] = array(
                'id' => $alimentadorId * 1000 + $i, 'alimentador_id' => $alimentadorId,
                'codigo' => $l[0], 'nome' => $l[1], 'no_pai_codigo' => $l[2],
                'r_ohm_km' => $l[3], 'x_ohm_km' => $l[4], 'comprimento_km' => $l[5],
                'ampacidade_a' => $l[6], 'carga_kw' => $l[7], 'carga_kvar' => $l[8],
                'gd_kw' => $l[9], 'num_consumidores' => $l[10],
            );
        }
        return $saida;
    }
}
