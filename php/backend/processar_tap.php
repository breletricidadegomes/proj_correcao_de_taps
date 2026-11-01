<?php
/**
 * =============================================================================
 *  ENDPOINT JSON - processar_tap.php
 * =============================================================================
 *  Recebe requisições AJAX (POST, corpo JSON) do painel e devolve JSON.
 *
 *  Cabeçalhos obrigatórios:
 *     Content-Type: application/json
 *     X-CSRF-Token: <token impresso no index.php>
 *
 *  Ações ("acao" no corpo JSON):
 *     carregar_alimentador  {alimentador_id}
 *     simular               {alimentador_id, equipamento_id, tap_simulado,
 *                            v_fonte_pu, fator_carga, fator_gd}
 *     otimizar              {alimentador_id, equipamento_id, v_fonte_pu,
 *                            fator_carga, fator_gd}
 *     registrar             {... simular ..., tap_novo, operador, motivo, observacao}
 *     historico             {alimentador_id}
 *     hospedagem            {alimentador_id, equipamento_id, no_codigo, v_fonte_pu,
 *                            fator_carga, fator_gd, mapa (bool)}
 *     diaria                {alimentador_id, equipamento_id, perfil_carga, perfil_ceu,
 *                            pico_carga, pico_gd, v_fonte_pu, v_ref_pu, banda_pu}
 *
 *  Resposta: {"ok": true, "dados": {...}}  ou  {"ok": false, "erro": "..."}
 * =============================================================================
 */

require __DIR__ . '/bootstrap.php';

cabecalhos_seguranca();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/** Envia resposta JSON e encerra */
function responder($ok, $conteudo, $httpStatus = 200)
{
    http_response_code($httpStatus);
    $corpo = $ok ? array('ok' => true, 'dados' => $conteudo) : array('ok' => false, 'erro' => $conteudo);
    $json = json_encode($corpo, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        $json = '{"ok":false,"erro":"Falha ao serializar resposta: ' . addslashes(json_last_error_msg()) . '"}';
    }
    echo $json;
    exit;
}

/** Erro de validação de entrada */
class ErroValidacao extends Exception
{
}

/** Lê número dentro de faixa, com valor padrão */
function ler_numero(array $in, $chave, $min, $max, $padrao = null)
{
    if (!isset($in[$chave]) || $in[$chave] === '') {
        if ($padrao === null) {
            throw new ErroValidacao('Parâmetro obrigatório ausente: ' . $chave);
        }
        return $padrao;
    }
    if (!is_numeric($in[$chave])) {
        throw new ErroValidacao('Parâmetro inválido: ' . $chave);
    }
    $v = (float) $in[$chave];
    if ($v < $min || $v > $max) {
        throw new ErroValidacao(sprintf('Parâmetro %s fora da faixa [%s; %s].', $chave, $min, $max));
    }
    return $v;
}

function ler_inteiro(array $in, $chave)
{
    if (!isset($in[$chave]) || filter_var($in[$chave], FILTER_VALIDATE_INT) === false) {
        throw new ErroValidacao('Parâmetro inteiro inválido: ' . $chave);
    }
    return (int) $in[$chave];
}

function ler_texto(array $in, $chave, $maxLen, $obrigatorio)
{
    $v = isset($in[$chave]) ? trim((string) $in[$chave]) : '';
    if ($obrigatorio && $v === '') {
        throw new ErroValidacao('Campo obrigatório: ' . $chave);
    }
    // Comprimento em caracteres UTF-8 (mbstring pode não existir em servidor legado)
    $len = function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen(utf8_decode($v));
    if ($len > $maxLen) {
        throw new ErroValidacao('Campo ' . $chave . ' excede ' . $maxLen . ' caracteres.');
    }
    return $v;
}

/** Carrega alimentador + rede + equipamentos e garante a consistência dos IDs */
function carregar_contexto(RepositorioInterface $repo, array $in, $exigirEquipamento)
{
    $alId = ler_inteiro($in, 'alimentador_id');
    $al = $repo->obterAlimentador($alId);
    if ($al === null) {
        throw new ErroValidacao('Alimentador não encontrado.');
    }
    $ctx = array(
        'alimentador'  => $al,
        'nos'          => $repo->listarNos($alId),
        'equipamentos' => $repo->listarEquipamentos($alId),
        'equipamento'  => null,
    );
    if ($exigirEquipamento) {
        $eqId = ler_inteiro($in, 'equipamento_id');
        foreach ($ctx['equipamentos'] as $e) {
            if ((int) $e['id'] === $eqId) {
                $ctx['equipamento'] = $e;
            }
        }
        if ($ctx['equipamento'] === null) {
            throw new ErroValidacao('Equipamento não pertence ao alimentador selecionado.');
        }
    }
    return $ctx;
}

/** Parâmetros operativos comuns da simulação */
function ler_parametros(array $in)
{
    return array(
        'v_fonte_pu'  => ler_numero($in, 'v_fonte_pu', 0.85, 1.15, 1.0),
        'fator_carga' => ler_numero($in, 'fator_carga', 0.0, 2.0, 1.0),
        'fator_gd'    => ler_numero($in, 'fator_gd', 0.0, 1.5, 0.0),
        'taps'        => array(),
    );
}

function validar_tap(array $eq, $tap)
{
    if ($tap < (int) $eq['tap_min'] || $tap > (int) $eq['tap_max']) {
        throw new ErroValidacao(sprintf('Tap %d fora da faixa do equipamento (%d a %d).',
            $tap, $eq['tap_min'], $eq['tap_max']));
    }
}

/** Resumo do equipamento para o frontend */
function resumo_equipamento(array $e)
{
    return array(
        'id'                => (int) $e['id'],
        'tipo'              => $e['tipo'],
        'codigo'            => $e['codigo'],
        'descricao'         => $e['descricao'],
        'no_codigo'         => $e['no_codigo'],
        'tap_min'           => (int) $e['tap_min'],
        'tap_max'           => (int) $e['tap_max'],
        'tap_neutro'        => (int) $e['tap_neutro'],
        'tap_passo_percent' => (float) $e['tap_passo_percent'],
        'tap_atual'         => (int) $e['tap_atual'],
        'sn_kva'            => (float) $e['sn_kva'],
    );
}

// =============================================================================
//  ROTEAMENTO
// =============================================================================
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responder(false, 'Método não permitido. Use POST.', 405);
    }

    // CSRF: obrigatório em todas as ações
    $tokenRecebido = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if (empty($_SESSION['csrf_token']) || !comparar_seguro($_SESSION['csrf_token'], $tokenRecebido)) {
        responder(false, 'Sessão expirada ou token inválido. Recarregue a página (F5).', 403);
    }

    $bruto = file_get_contents('php://input');
    if (strlen($bruto) > 65536) {
        responder(false, 'Requisição muito grande.', 413);
    }
    $in = json_decode($bruto, true);
    if (!is_array($in) || empty($in['acao'])) {
        responder(false, 'Corpo JSON inválido ou ação ausente.', 400);
    }

    $repo = criar_repositorio();
    $sim  = new SimuladorTap(app_config('calculo'));
    $acao = (string) $in['acao'];

    switch ($acao) {

        // ---------------------------------------------------------------------
        case 'carregar_alimentador':
            $ctx = carregar_contexto($repo, $in, false);
            $eqs = array();
            foreach ($ctx['equipamentos'] as $e) {
                $eqs[] = resumo_equipamento($e);
            }
            responder(true, array(
                'alimentador'  => $ctx['alimentador'],
                'equipamentos' => $eqs,
                'total_nos'    => count($ctx['nos']),
            ));
            break;

        // ---------------------------------------------------------------------
        case 'simular':
            $ctx = carregar_contexto($repo, $in, true);
            $eq  = $ctx['equipamento'];
            $tap = ler_inteiro($in, 'tap_simulado');
            validar_tap($eq, $tap);
            $p = ler_parametros($in);

            // Cenário BASE: taps atuais de todos os equipamentos
            $base = $sim->simular($ctx['alimentador'], $ctx['nos'], $ctx['equipamentos'], $p);
            // Cenário SIMULADO: tap proposto no equipamento selecionado
            $p['taps'][(int) $eq['id']] = $tap;
            $simulado = $sim->simular($ctx['alimentador'], $ctx['nos'], $ctx['equipamentos'], $p);

            $bi = $base['indicadores'];
            $si = $simulado['indicadores'];
            responder(true, array(
                'equipamento' => resumo_equipamento($eq),
                'tap_atual'   => (int) $eq['tap_atual'],
                'tap_simulado'=> $tap,
                'base'        => $base,
                'simulado'    => $simulado,
                'delta'       => array(
                    'v_min_pu'          => round($si['v_min_pu'] - $bi['v_min_pu'], 4),
                    'v_max_pu'          => round($si['v_max_pu'] - $bi['v_max_pu'], 4),
                    'queda_max_percent' => round($si['queda_max_percent'] - $bi['queda_max_percent'], 2),
                    'perdas_kw'         => round($si['perdas_kw'] - $bi['perdas_kw'], 1),
                    'drp_estimado'      => round($si['drp_estimado'] - $bi['drp_estimado'], 2),
                    'drc_estimado'      => round($si['drc_estimado'] - $bi['drc_estimado'], 2),
                ),
            ));
            break;

        // ---------------------------------------------------------------------
        case 'otimizar':
            $ctx = carregar_contexto($repo, $in, true);
            $p = ler_parametros($in);
            $res = $sim->otimizar($ctx['alimentador'], $ctx['nos'], $ctx['equipamentos'], $p, $ctx['equipamento']);
            responder(true, $res);
            break;

        // ---------------------------------------------------------------------
        case 'registrar':
            $ctx = carregar_contexto($repo, $in, true);
            $tapNovo = ler_inteiro($in, 'tap_novo');
            validar_tap($ctx['equipamento'], $tapNovo);
            $p = ler_parametros($in);

            $motivosValidos = array(
                'SUBTENSAO', 'SOBRETENSAO', 'PATAMAR_CARGA', 'GERACAO_DISTRIBUIDA',
                'RECOMPOSICAO', 'MANUTENCAO', 'OUTRO',
            );
            $motivo = ler_texto($in, 'motivo', 30, true);
            if (!in_array($motivo, $motivosValidos, true)) {
                throw new ErroValidacao('Motivo inválido.');
            }
            $observacao = ler_texto($in, 'observacao', 500, false);

            // Operador: usuário autenticado no servidor (AD/LDAP) tem prioridade
            $cfg = app_config();
            if ($cfg['usar_remote_user'] && !empty($_SERVER['REMOTE_USER'])) {
                $operador = substr((string) $_SERVER['REMOTE_USER'], 0, 80);
            } else {
                $operador = ler_texto($in, 'operador', 80, true);
            }

            $alimentador = $ctx['alimentador'];
            $nos = $ctx['nos'];
            $equipamentos = $ctx['equipamentos'];
            $ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '';

            // Closure executada com o equipamento TRAVADO no banco: recalcula
            // antes/depois no servidor (não confia em números vindos do cliente).
            $registro = $repo->registrarManobra(
                (int) $ctx['equipamento']['id'],
                function ($eqTravado) use ($sim, $alimentador, $nos, $equipamentos, $p, $tapNovo,
                                           $operador, $motivo, $observacao, $ip) {
                    $tapAnterior = (int) $eqTravado['tap_atual'];
                    if ($tapAnterior === $tapNovo) {
                        throw new ErroValidacao('O tap novo é igual ao tap atual (' . $tapAnterior . '). Nada a registrar.');
                    }
                    // Garante que a simulação "antes" use o tap travado (mais recente)
                    foreach ($equipamentos as $i => $e) {
                        if ((int) $e['id'] === (int) $eqTravado['id']) {
                            $equipamentos[$i]['tap_atual'] = $tapAnterior;
                        }
                    }
                    $antes = $sim->simular($alimentador, $nos, $equipamentos, $p);
                    $p['taps'][(int) $eqTravado['id']] = $tapNovo;
                    $depois = $sim->simular($alimentador, $nos, $equipamentos, $p);
                    $a = $antes['indicadores'];
                    $d = $depois['indicadores'];

                    return array(
                        'equipamento_id'   => (int) $eqTravado['id'],
                        'alimentador_id'   => (int) $alimentador['id'],
                        'data_hora'        => date('Y-m-d H:i:s'),
                        'operador'         => $operador,
                        'tap_anterior'     => $tapAnterior,
                        'tap_novo'         => $tapNovo,
                        'v_fonte_pu'       => $p['v_fonte_pu'],
                        'fator_carga'      => $p['fator_carga'],
                        'fator_gd'         => $p['fator_gd'],
                        'v_min_antes_pu'   => $a['v_min_pu'],
                        'v_min_depois_pu'  => $d['v_min_pu'],
                        'v_max_antes_pu'   => $a['v_max_pu'],
                        'v_max_depois_pu'  => $d['v_max_pu'],
                        'drp_antes'        => $a['drp_estimado'],
                        'drp_depois'       => $d['drp_estimado'],
                        'drc_antes'        => $a['drc_estimado'],
                        'drc_depois'       => $d['drc_estimado'],
                        'perdas_antes_kw'  => $a['perdas_kw'],
                        'perdas_depois_kw' => $d['perdas_kw'],
                        'motivo'           => $motivo,
                        'observacao'       => $observacao,
                        'ip_origem'        => $ip,
                    );
                }
            );
            responder(true, array('registro' => $registro, 'mensagem' => 'Manobra registrada com sucesso.'));
            break;

        // ---------------------------------------------------------------------
        case 'hospedagem':
            @set_time_limit(120); // busca com centenas de fluxos de carga
            $ctx = carregar_contexto($repo, $in, true);
            $p = ler_parametros($in);
            $noCodigo = ler_texto($in, 'no_codigo', 30, true);
            $existe = false;
            foreach ($ctx['nos'] as $n) {
                if ($n['codigo'] === $noCodigo) {
                    $existe = true;
                }
            }
            if (!$existe) {
                throw new ErroValidacao('Nó não pertence ao alimentador selecionado.');
            }
            $cfgHosp = app_config('hospedagem');
            if (!is_array($cfgHosp)) {
                $cfgHosp = array(); // config.php antigo: usa os padrões da classe
            }
            $analise = new AnaliseHospedagem($sim, $cfgHosp);
            $resp = $analise->avaliarNo($ctx['alimentador'], $ctx['nos'], $ctx['equipamentos'], $p,
                                        $ctx['equipamento'], $noCodigo);
            if (!empty($in['mapa'])) {
                $limite = isset($cfgHosp['limite_nos_mapa']) ? (int) $cfgHosp['limite_nos_mapa'] : 60;
                $resp['mapa'] = $analise->mapa($ctx['alimentador'], $ctx['nos'], $ctx['equipamentos'], $p,
                                               $ctx['equipamento'], $limite);
            }
            responder(true, $resp);
            break;

        // ---------------------------------------------------------------------
        case 'diaria':
            @set_time_limit(120);
            $ctx = carregar_contexto($repo, $in, true);
            $perfisCarga = CurvasTipicas::perfisCarga();
            $perfisCeu = CurvasTipicas::perfisCeu();
            $perfilCarga = ler_texto($in, 'perfil_carga', 20, true);
            $perfilCeu = ler_texto($in, 'perfil_ceu', 20, true);
            if (!isset($perfisCarga[$perfilCarga]) || !isset($perfisCeu[$perfilCeu])) {
                throw new ErroValidacao('Perfil de carga ou de céu inválido.');
            }
            $opcoes = array(
                'perfil_carga' => $perfilCarga,
                'perfil_ceu'   => $perfilCeu,
                'pico_carga'   => ler_numero($in, 'pico_carga', 0.0, 1.5, 1.0),
                'pico_gd'      => ler_numero($in, 'pico_gd', 0.0, 1.5, 1.0),
                'v_fonte_pu'   => ler_numero($in, 'v_fonte_pu', 0.85, 1.15, 1.0),
                'v_ref_pu'     => ler_numero($in, 'v_ref_pu', 0.95, 1.05, 1.01),
                'banda_pu'     => ler_numero($in, 'banda_pu', 0.005, 0.05, 0.02),
            );
            $diaria = new SimulacaoDiaria($sim);
            responder(true, $diaria->executar($ctx['alimentador'], $ctx['nos'], $ctx['equipamentos'],
                                              $ctx['equipamento'], $opcoes));
            break;

        // ---------------------------------------------------------------------
        case 'historico':
            $alId = ler_inteiro($in, 'alimentador_id');
            responder(true, array('historico' => $repo->listarHistorico($alId, 50)));
            break;

        default:
            responder(false, 'Ação desconhecida.', 400);
    }

} catch (ErroValidacao $e) {
    responder(false, $e->getMessage(), 422);
} catch (PDOException $e) {
    error_log('[TAP] Erro de banco: ' . $e->getMessage());
    $msg = app_config('debug') ? $e->getMessage() : 'Erro de banco de dados. Consulte o log do servidor.';
    responder(false, $msg, 500);
} catch (RuntimeException $e) {
    // Ex.: fluxo não convergiu, topologia inválida -> mensagem útil ao operador
    responder(false, $e->getMessage(), 422);
} catch (Exception $e) {
    error_log('[TAP] Erro: ' . $e->getMessage());
    $msg = app_config('debug') ? $e->getMessage() : 'Erro interno. Consulte o log do servidor.';
    responder(false, $msg, 500);
}
