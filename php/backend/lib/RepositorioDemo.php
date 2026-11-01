<?php
/**
 * Repositório de demonstração: lê a rede de DadosDemo e guarda os taps e o
 * histórico de manobras na SESSÃO PHP (some ao fechar o navegador).
 * Permite avaliar a ferramenta sem banco de dados.
 */
class RepositorioDemo implements RepositorioInterface
{
    public function __construct()
    {
        if (!isset($_SESSION['demo_taps'])) {
            $_SESSION['demo_taps'] = array();
        }
        if (!isset($_SESSION['demo_historico'])) {
            $_SESSION['demo_historico'] = array();
        }
    }

    public function listarAlimentadores()
    {
        return DadosDemo::alimentadores();
    }

    public function obterAlimentador($id)
    {
        foreach (DadosDemo::alimentadores() as $a) {
            if ((int) $a['id'] === (int) $id) {
                return $a;
            }
        }
        return null;
    }

    public function listarNos($alimentadorId)
    {
        return DadosDemo::nos((int) $alimentadorId);
    }

    public function listarEquipamentos($alimentadorId)
    {
        $lista = array();
        foreach (DadosDemo::equipamentos() as $e) {
            if ((int) $e['alimentador_id'] === (int) $alimentadorId) {
                $lista[] = $this->aplicarTapSessao($e);
            }
        }
        return $lista;
    }

    public function obterEquipamento($id)
    {
        foreach (DadosDemo::equipamentos() as $e) {
            if ((int) $e['id'] === (int) $id) {
                return $this->aplicarTapSessao($e);
            }
        }
        return null;
    }

    public function registrarManobra($equipamentoId, $montarRegistro)
    {
        $eq = $this->obterEquipamento($equipamentoId);
        if ($eq === null) {
            throw new RuntimeException('Equipamento não encontrado.');
        }
        $reg = call_user_func($montarRegistro, $eq);
        $reg['id'] = count($_SESSION['demo_historico']) + 1;
        $reg['equipamento'] = $eq['codigo'];
        $reg['tipo'] = $eq['tipo'];
        $_SESSION['demo_historico'][] = $reg;
        $_SESSION['demo_taps'][(int) $equipamentoId] = (int) $reg['tap_novo'];
        return $reg;
    }

    public function listarHistorico($alimentadorId, $limite)
    {
        $saida = array();
        foreach (array_reverse($_SESSION['demo_historico']) as $h) {
            if ((int) $h['alimentador_id'] === (int) $alimentadorId) {
                $saida[] = $h;
                if (count($saida) >= $limite) {
                    break;
                }
            }
        }
        return $saida;
    }

    private function aplicarTapSessao(array $e)
    {
        if (isset($_SESSION['demo_taps'][(int) $e['id']])) {
            $e['tap_atual'] = $_SESSION['demo_taps'][(int) $e['id']];
        }
        return $e;
    }
}
