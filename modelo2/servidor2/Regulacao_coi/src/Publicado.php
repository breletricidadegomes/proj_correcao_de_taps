<?php
/**
 * Leitura do que o servidor 1 publica em data/publicado/ (somente leitura
 * para o PHP). Formato:
 *
 *   publicado/manifest.json              {versao, gerado_em, origem, safra_bdgd, arquivos{...}}
 *   publicado/cadastro/subestacoes.json  [ {cod_se, nome_se, tipo_se, tensoes_kv, regional, municipio} ]
 *   publicado/cadastro/alimentadores.json[ {id, codigo, nome, cod_se, subestacao, tensao_nominal_kv} ]
 *   publicado/cadastro/equipamentos.json [ OLTC / REGULADOR / BC — ver DadosExemplo::equipamentos() ]
 *   publicado/rede/<codigo_alimentador>.json  [ nós reduzidos — ver DadosExemplo::nos() ]
 *
 * O servidor 1 grava tudo numa pasta temporária e troca de uma vez; o
 * manifest.json é o último arquivo escrito. Sem manifest, e com
 * 'modo_exemplo' ligado, usa DadosExemplo.
 */
class Publicado
{
    private $dir;
    private $exemploPermitido;
    private $cache = array();

    public function __construct($dirDados, $exemploPermitido)
    {
        $this->dir = rtrim(str_replace('\\', '/', $dirDados), '/') . '/publicado';
        $this->exemploPermitido = (bool) $exemploPermitido;
    }

    /** true quando há publicação válida do servidor 1 */
    public function disponivel()
    {
        return is_file($this->dir . '/manifest.json');
    }

    public function usandoExemplo()
    {
        return !$this->disponivel() && $this->exemploPermitido;
    }

    public function manifest()
    {
        if ($this->disponivel()) {
            return $this->lerJson('manifest.json', array());
        }
        if ($this->exemploPermitido) {
            return DadosExemplo::manifest();
        }
        return null;
    }

    public function subestacoes()
    {
        return $this->lista('cadastro/subestacoes.json', 'subestacoes');
    }

    public function alimentadores()
    {
        return $this->lista('cadastro/alimentadores.json', 'alimentadores');
    }

    public function alimentador($id)
    {
        foreach ($this->alimentadores() as $a) {
            if ((int) $a['id'] === (int) $id) {
                return $a;
            }
        }
        return null;
    }

    /** Todos os equipamentos, como publicados (sem os ajustes vigentes do Modelo 2) */
    public function equipamentos()
    {
        return $this->lista('cadastro/equipamentos.json', 'equipamentos');
    }

    public function nos($alimentadorId)
    {
        $a = $this->alimentador($alimentadorId);
        if ($a === null) {
            return array();
        }
        if ($this->disponivel()) {
            $cod = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $a['codigo']);
            return $this->lerJson('rede/' . $cod . '.json', array());
        }
        return $this->exemploPermitido ? DadosExemplo::nos((int) $alimentadorId) : array();
    }

    /** Alimentadores atendidos por um equipamento (trafo da SE pode atender vários) */
    public static function alimentadoresDo(array $eq)
    {
        if (isset($eq['alimentadores']) && is_array($eq['alimentadores'])) {
            return array_map('intval', $eq['alimentadores']);
        }
        return isset($eq['alimentador_id']) ? array((int) $eq['alimentador_id']) : array();
    }

    // -------------------------------------------------------------------------

    private function lista($arquivo, $metodoExemplo)
    {
        if (isset($this->cache[$arquivo])) {
            return $this->cache[$arquivo];
        }
        if ($this->disponivel()) {
            $v = $this->lerJson($arquivo, array());
        } elseif ($this->exemploPermitido) {
            $v = call_user_func(array('DadosExemplo', $metodoExemplo));
        } else {
            $v = array();
        }
        $this->cache[$arquivo] = $v;
        return $v;
    }

    private function lerJson($rel, $padrao)
    {
        $arq = $this->dir . '/' . $rel;
        if (!is_file($arq)) {
            return $padrao;
        }
        $txt = @file_get_contents($arq);
        if ($txt === false || $txt === '') {
            return $padrao;
        }
        if (substr($txt, 0, 3) === "\xEF\xBB\xBF") {
            $txt = (string) substr($txt, 3);
        }
        $d = json_decode($txt, true);
        if (!is_array($d)) {
            throw new ErroApi('Arquivo publicado inválido: ' . $rel . '. Aguarde a próxima publicação do servidor 1.', 500);
        }
        return $d;
    }
}
