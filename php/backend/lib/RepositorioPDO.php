<?php
/**
 * Repositório MySQL / PostgreSQL via PDO.
 *
 * Segurança:
 *  - TODAS as consultas usam prepared statements com parâmetros nomeados
 *    (proteção contra SQL Injection).
 *  - PDO::ATTR_EMULATE_PREPARES = false -> preparo real no servidor.
 *  - Erros lançam PDOException (capturada no endpoint; detalhes não vazam).
 */
class RepositorioPDO implements RepositorioInterface
{
    /** @var PDO */
    private $pdo;
    private $driver;

    public function __construct(array $cfg)
    {
        $this->driver = $cfg['driver'];

        if ($this->driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'], $cfg['porta'], $cfg['banco'], $cfg['charset']);
        } elseif ($this->driver === 'pgsql') {
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s',
                $cfg['host'], $cfg['porta'], $cfg['banco']);
        } else {
            throw new InvalidArgumentException('Driver de banco não suportado: ' . $this->driver);
        }

        $this->pdo = new PDO($dsn, $cfg['usuario'], $cfg['senha'], array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ));

        if ($this->driver === 'pgsql') {
            $this->pdo->exec("SET NAMES 'UTF8'");
        }
    }

    public function listarAlimentadores()
    {
        $st = $this->pdo->query(
            'SELECT id, codigo, nome, subestacao, tensao_nominal_kv
               FROM alimentadores WHERE ativo = 1 ORDER BY codigo'
        );
        return $st->fetchAll();
    }

    public function obterAlimentador($id)
    {
        $st = $this->pdo->prepare(
            'SELECT id, codigo, nome, subestacao, tensao_nominal_kv
               FROM alimentadores WHERE id = :id AND ativo = 1'
        );
        $st->execute(array(':id' => (int) $id));
        $r = $st->fetch();
        return $r ? $r : null;
    }

    public function listarNos($alimentadorId)
    {
        $st = $this->pdo->prepare(
            'SELECT id, alimentador_id, codigo, nome, no_pai_codigo, r_ohm_km, x_ohm_km,
                    comprimento_km, ampacidade_a, carga_kw, carga_kvar, gd_kw, num_consumidores
               FROM nos_rede
              WHERE alimentador_id = :al
              ORDER BY ordem, id'
        );
        $st->execute(array(':al' => (int) $alimentadorId));
        return $st->fetchAll();
    }

    public function listarEquipamentos($alimentadorId)
    {
        $st = $this->pdo->prepare(
            'SELECT id, alimentador_id, tipo, codigo, descricao, no_codigo, sn_kva, vk_percent,
                    vkr_percent, tensao_primaria_kv, tensao_secundaria_kv, tap_min, tap_max,
                    tap_neutro, tap_passo_percent, tap_atual
               FROM equipamentos_regulacao
              WHERE alimentador_id = :al AND ativo = 1
              ORDER BY tipo DESC, codigo'
        );
        $st->execute(array(':al' => (int) $alimentadorId));
        return $st->fetchAll();
    }

    public function obterEquipamento($id)
    {
        $st = $this->pdo->prepare(
            'SELECT id, alimentador_id, tipo, codigo, descricao, no_codigo, sn_kva, vk_percent,
                    vkr_percent, tensao_primaria_kv, tensao_secundaria_kv, tap_min, tap_max,
                    tap_neutro, tap_passo_percent, tap_atual
               FROM equipamentos_regulacao WHERE id = :id AND ativo = 1'
        );
        $st->execute(array(':id' => (int) $id));
        $r = $st->fetch();
        return $r ? $r : null;
    }

    public function registrarManobra($equipamentoId, $montarRegistro)
    {
        $this->pdo->beginTransaction();
        try {
            // Trava a linha do equipamento: evita que dois operadores registrem
            // manobras simultâneas partindo do mesmo tap anterior.
            $st = $this->pdo->prepare(
                'SELECT id, alimentador_id, tipo, codigo, descricao, no_codigo, sn_kva, vk_percent,
                        vkr_percent, tensao_primaria_kv, tensao_secundaria_kv, tap_min, tap_max,
                        tap_neutro, tap_passo_percent, tap_atual
                   FROM equipamentos_regulacao WHERE id = :id AND ativo = 1 FOR UPDATE'
            );
            $st->execute(array(':id' => (int) $equipamentoId));
            $eq = $st->fetch();
            if (!$eq) {
                throw new RuntimeException('Equipamento não encontrado.');
            }

            $reg = call_user_func($montarRegistro, $eq);

            $ins = $this->pdo->prepare(
                'INSERT INTO historico_manobras_tap
                    (equipamento_id, alimentador_id, data_hora, operador, tap_anterior, tap_novo,
                     v_fonte_pu, fator_carga, fator_gd,
                     v_min_antes_pu, v_min_depois_pu, v_max_antes_pu, v_max_depois_pu,
                     drp_antes, drp_depois, drc_antes, drc_depois,
                     perdas_antes_kw, perdas_depois_kw, motivo, observacao, ip_origem)
                 VALUES
                    (:equipamento_id, :alimentador_id, :data_hora, :operador, :tap_anterior, :tap_novo,
                     :v_fonte_pu, :fator_carga, :fator_gd,
                     :v_min_antes_pu, :v_min_depois_pu, :v_max_antes_pu, :v_max_depois_pu,
                     :drp_antes, :drp_depois, :drc_antes, :drc_depois,
                     :perdas_antes_kw, :perdas_depois_kw, :motivo, :observacao, :ip_origem)'
            );
            $params = array();
            foreach ($reg as $k => $v) {
                $params[':' . $k] = $v;
            }
            $ins->execute($params);

            $up = $this->pdo->prepare(
                'UPDATE equipamentos_regulacao SET tap_atual = :tap WHERE id = :id'
            );
            $up->execute(array(':tap' => (int) $reg['tap_novo'], ':id' => (int) $equipamentoId));

            $this->pdo->commit();
            return $reg;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function listarHistorico($alimentadorId, $limite)
    {
        // LIMIT com parâmetro inteiro vinculado explicitamente (compatível MySQL/PG)
        $st = $this->pdo->prepare(
            'SELECT h.id, h.data_hora, h.operador, e.codigo AS equipamento, e.tipo,
                    h.tap_anterior, h.tap_novo, h.v_min_antes_pu, h.v_min_depois_pu,
                    h.v_max_antes_pu, h.v_max_depois_pu, h.drp_antes, h.drp_depois,
                    h.drc_antes, h.drc_depois, h.perdas_antes_kw, h.perdas_depois_kw,
                    h.motivo, h.observacao
               FROM historico_manobras_tap h
               JOIN equipamentos_regulacao e ON e.id = h.equipamento_id
              WHERE h.alimentador_id = :al
              ORDER BY h.data_hora DESC, h.id DESC
              LIMIT :lim'
        );
        $st->bindValue(':al', (int) $alimentadorId, PDO::PARAM_INT);
        $st->bindValue(':lim', (int) $limite, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}
