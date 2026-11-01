<?php
/**
 * Dados de exemplo no MESMO formato que o servidor 1 publica em
 * data/publicado/ (ver Publicado.php e modelo2/README.md).
 *
 * Usados quando 'modo_exemplo' => true e ainda não há publicação do
 * servidor 1. A tela mostra a faixa "Dados de exemplo".
 *
 * Cabos (sequência positiva, valores típicos):
 *   336,4 MCM CAA  R 0,190  X 0,390 ohm/km  530 A
 *   4/0 AWG CAA    R 0,368  X 0,420 ohm/km  340 A
 *   1/0 AWG CAA    R 0,696  X 0,450 ohm/km  230 A
 */
class DadosExemplo
{
    public static function manifest()
    {
        return array(
            'versao'      => 'exemplo',
            'gerado_em'   => date('Y-m-d') . ' 06:00:00',
            'origem'      => 'Dados de exemplo embutidos (sem publicação do servidor 1)',
            'safra_bdgd'  => null,
        );
    }

    public static function subestacoes()
    {
        return array(
            array('cod_se' => 'SE-CTR', 'nome_se' => 'SE Centro (exemplo)', 'tipo_se' => 'DISTRIBUICAO',
                  'tensoes_kv' => '69/13.8', 'regional' => 'Leste', 'municipio' => 'Maceió'),
        );
    }

    public static function alimentadores()
    {
        return array(
            array('id' => 1, 'codigo' => 'CTR-01', 'nome' => 'CTR-01 · Distrito Industrial',
                  'cod_se' => 'SE-CTR', 'subestacao' => 'SE Centro 69/13,8 kV', 'tensao_nominal_kv' => 13.8),
            array('id' => 2, 'codigo' => 'CTR-02', 'nome' => 'CTR-02 · Zona Rural / Usina FV',
                  'cod_se' => 'SE-CTR', 'subestacao' => 'SE Centro 69/13,8 kV', 'tensao_nominal_kv' => 13.8),
        );
    }

    /**
     * Equipamentos de regulação. O trafo da SE atende os dois alimentadores
     * (alimentadores = [1, 2]): uma manobra nele afeta ambos.
     */
    public static function equipamentos()
    {
        $ontem = date('Y-m-d', time() - 86400) . ' 08:15:00';
        return array(
            array('id' => 1, 'tipo' => 'TRAFO_SE', 'codigo' => 'TR-01 SE CTR', 'cod_se' => 'SE-CTR',
                  'alimentadores' => array(1, 2), 'no_codigo' => '',
                  'descricao' => 'Trafo 69/13,8 kV 26,6 MVA · OLTC ±8 × 1,25%',
                  'sn_kva' => 26600, 'vk_percent' => 10.0, 'vkr_percent' => 0.5,
                  'tensao_primaria_kv' => 69.0, 'tensao_secundaria_kv' => 13.8,
                  'tap_min' => -8, 'tap_max' => 8, 'tap_neutro' => 0, 'tap_passo_percent' => 1.25, 'tap_atual' => 0,
                  'modo' => 'AUTO', 'v_ref_pu' => 1.01, 'banda_pu' => 0.02, 'temporizacao_s' => 60,
                  'ldc_r_v' => 0, 'ldc_x_v' => 0, 'telecomandado' => true, 'atualizado_em' => $ontem),
            array('id' => 2, 'tipo' => 'REGULADOR', 'codigo' => 'RT-0142', 'cod_se' => 'SE-CTR',
                  'alimentador_id' => 1, 'no_codigo' => 'P05',
                  'descricao' => 'BRT 3 × 167 kVA · 32 degraus ±10% · estrela aterrada',
                  'sn_kva' => 501, 'vk_percent' => 0, 'vkr_percent' => 0,
                  'tensao_primaria_kv' => 13.8, 'tensao_secundaria_kv' => 13.8,
                  'tap_min' => -16, 'tap_max' => 16, 'tap_neutro' => 0, 'tap_passo_percent' => 0.625, 'tap_atual' => 0,
                  'modo' => 'AUTO', 'v_ref_pu' => 1.00, 'banda_pu' => 0.02, 'temporizacao_s' => 45,
                  'ldc_r_v' => 0, 'ldc_x_v' => 0, 'modo_reverso' => 'BLOQUEIO', 'telecomandado' => false,
                  'atualizado_em' => $ontem),
            array('id' => 3, 'tipo' => 'BC', 'codigo' => 'BC-0310', 'cod_se' => 'SE-CTR',
                  'alimentador_id' => 1, 'no_codigo' => 'P04',
                  'descricao' => 'Banco de capacitores 2 × 600 kvar · controle por tensão',
                  'tensao_nominal_kv' => 13.8, 'kvar_estagio' => 600, 'n_estagios' => 2, 'estagios_ligados' => 0,
                  'tipo_controle' => 'TENSAO', 'telecomandado' => true, 'atualizado_em' => $ontem),
            array('id' => 4, 'tipo' => 'BC', 'codigo' => 'BC-0721', 'cod_se' => 'SE-CTR',
                  'alimentador_id' => 2, 'no_codigo' => 'Q02',
                  'descricao' => 'Banco de capacitores 1 × 600 kvar · fixo',
                  'tensao_nominal_kv' => 13.8, 'kvar_estagio' => 600, 'n_estagios' => 1, 'estagios_ligados' => 1,
                  'tipo_controle' => 'FIXO', 'telecomandado' => false, 'atualizado_em' => $ontem),
        );
    }

    /**
     * Nós reduzidos de cada alimentador. Trecho = ligação do nó ao seu pai.
     * Colunas: codigo, nome, pai, r, x, km, amp, kW, kvar, GD kW, UCs
     */
    public static function nos($alimentadorId)
    {
        $tab = array(
            1 => array(
                array('BSE-CTR1', 'Barra 13,8 kV SE Centro (eqv. demais alimentadores)', null, 0, 0, 0, 0, 7000, 2800, 0, 0),
                array('P01', 'Saída SE · Av. Brasil',           'BSE-CTR1', 0.190, 0.390, 1.5, 530, 350, 150, 0, 620),
                array('P02', 'Rótula Distrito Industrial',      'P01',      0.190, 0.390, 2.5, 530, 600, 280, 0, 310),
                array('P03', 'Frigorífico (cliente A4)',        'P02',      0.190, 0.390, 2.0, 530, 1000, 480, 0, 1),
                array('P04', 'Bairro Jardim América',           'P03',      0.190, 0.390, 3.0, 530, 500, 210, 0, 1450),
                array('P05', 'Jusante do RT-0142',              'P04',      0.368, 0.420, 2.5, 340, 380, 160, 0, 980),
                array('P06', 'Vila São José',                   'P05',      0.368, 0.420, 3.0, 340, 340, 140, 0, 1120),
                array('P07', 'Fim de tronco · Granjas',         'P06',      0.696, 0.450, 4.0, 230, 220, 95, 0, 540),
                array('R01', 'Ramal Cerâmica Santa Rita',       'P03',      0.696, 0.450, 3.5, 230, 400, 190, 0, 2),
                array('R02', 'Ramal Loteamento Novo Horizonte', 'R01',      0.696, 0.450, 2.5, 230, 260, 110, 0, 860),
            ),
            2 => array(
                array('BSE-CTR2', 'Barra 13,8 kV SE Centro (eqv. demais alimentadores)', null, 0, 0, 0, 0, 9000, 3600, 0, 0),
                array('Q01', 'Saída SE · Rodovia',              'BSE-CTR2', 0.190, 0.390, 3.0, 530, 400, 170, 0, 380),
                array('Q02', 'Comunidade Ponte Alta',           'Q01',      0.368, 0.420, 4.0, 340, 350, 150, 0, 720),
                array('Q03', 'UFV Sol do Cerrado (GD 4 MW)',    'Q02',      0.368, 0.420, 6.0, 340, 50, 20, 4000, 1),
                array('Q04', 'Assentamento Boa Vista',          'Q03',      0.696, 0.450, 4.0, 230, 250, 110, 0, 610),
                array('Q05', 'Fim de linha · Irrigantes',       'Q04',      0.696, 0.450, 3.0, 230, 300, 140, 0, 95),
            ),
        );
        if (!isset($tab[(int) $alimentadorId])) {
            return array();
        }
        $saida = array();
        foreach ($tab[(int) $alimentadorId] as $l) {
            $saida[] = array(
                'codigo' => $l[0], 'nome' => $l[1], 'no_pai_codigo' => $l[2],
                'r_ohm_km' => $l[3], 'x_ohm_km' => $l[4], 'comprimento_km' => $l[5],
                'ampacidade_a' => $l[6], 'carga_kw' => $l[7], 'carga_kvar' => $l[8],
                'gd_kw' => $l[9], 'num_consumidores' => $l[10],
            );
        }
        return $saida;
    }
}
