<?php
/**
 * Contrato de acesso a dados. Há duas implementações:
 *   - RepositorioPDO  : MySQL / PostgreSQL (produção)
 *   - RepositorioDemo : dados embutidos + histórico na sessão (demonstração)
 */
interface RepositorioInterface
{
    public function listarAlimentadores();

    public function obterAlimentador($id);

    public function listarNos($alimentadorId);

    public function listarEquipamentos($alimentadorId);

    public function obterEquipamento($id);

    /**
     * Registra uma manobra de tap de forma atômica:
     *   1. trava o equipamento (SELECT ... FOR UPDATE)
     *   2. chama $montarRegistro($equipamentoTravado) -> array com os campos
     *   3. grava o histórico e atualiza o tap_atual do equipamento
     *
     * @param int      $equipamentoId
     * @param callable $montarRegistro
     * @return array   Registro gravado
     */
    public function registrarManobra($equipamentoId, $montarRegistro);

    public function listarHistorico($alimentadorId, $limite);
}
