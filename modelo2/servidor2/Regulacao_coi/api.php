<?php
/**
 * API JSON do Regulação COI (Modelo 2).
 *
 *   GET  api.php?acao=sessao                    quem está logado + token CSRF
 *   GET  api.php?acao=referencia                cadastro publicado, listas, permissões
 *   GET  api.php?acao=sync&v=123                registros que mudaram desde a versão 123
 *   GET  api.php?acao=eventos&id=r...           trilha de um registro
 *   GET  api.php?acao=rede&alimentador_id=1     equipamentos de um alimentador
 *   GET  api.php?acao=usuarios                  (ADM)
 *   GET  api.php?acao=logs&de=&ate=&tipo=&q=&offset=&limite=   (supervisor/ADM)
 *   GET  api.php?acao=csv&arquivo=registros|logs                (logado)
 *   POST login | logout | instalar | senha
 *   POST simular | solicitar | propor | parecer | decidir | executar | verificar | cancelar | retroativo
 *   POST usuario_salvar (ADM)
 *
 * Todo POST exige o cabeçalho X-CSRF-Token devolvido por "sessao".
 */
require __DIR__ . '/src/inicio.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function responder($dados, $status = 200)
{
    if (function_exists('http_response_code')) {
        http_response_code($status);
    } else {
        header('X-PHP-Response-Code: ' . $status, true, $status);
    }
    if (is_array($dados)) {
        $dados['agora'] = BancoJson::agoraMs();
        if (!isset($dados['ok'])) {
            $dados['ok'] = $status < 400;
        }
    }
    echo BancoJson::json($dados);
    exit;
}

function campo(array $a, $k, $padrao = null)
{
    return array_key_exists($k, $a) ? $a[$k] : $padrao;
}

try {
    $db = new BancoJson($config['dir_dados']);
    $app = new Regulacao($db, $config);
    Sessao::iniciar($config, $db->dir());

    $acao = isset($_GET['acao']) ? (string) $_GET['acao'] : '';
    $metodo = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    $me = $app->perfil(Sessao::uid());
    if (Sessao::uid() && !$me) {
        Sessao::sair(); // usuário desativado ou removido
    }

    $corpo = array();
    if ($metodo === 'POST') {
        Sessao::conferirCsrf();
        $bruto = file_get_contents('php://input');
        if (strlen($bruto) > 262144) {
            throw new ErroApi('Requisição grande demais.', 413);
        }
        $corpo = $bruto === '' ? array() : json_decode($bruto, true);
        if (!is_array($corpo)) {
            throw new ErroApi('Corpo JSON inválido.', 400);
        }
    }

    $exigirLeitura = function () use ($me, $config) {
        if (!$me && empty($config['leitura_publica'])) {
            throw new ErroApi('Entre no sistema para ver os registros.', 401);
        }
    };
    $exigirLogin = function () use ($me) {
        if (!$me) {
            throw new ErroApi('Entre no sistema para fazer isso.', 401);
        }
    };

    if ($metodo === 'GET') {
        switch ($acao) {
            case 'sessao':
                $csrf = Sessao::csrf();
                $expirou = !empty($_SESSION['expirou']);
                Sessao::liberar();
                responder(array('usuario' => $me, 'csrf' => $csrf, 'expirou' => $expirou,
                    'instalar' => $app->precisaInstalar(), 'rede' => $app->rede()->estado(),
                    'leitura_publica' => !empty($config['leitura_publica']),
                    'permissoes' => Regulacao::permissoes($me)));
                break;
            case 'referencia':
                Sessao::liberar();
                $exigirLeitura();
                responder($app->referencia($me));
                break;
            case 'sync':
                Sessao::liberar();
                $exigirLeitura();
                responder($app->sincronizar($me, isset($_GET['v']) ? $_GET['v'] : 0));
                break;
            case 'eventos':
                Sessao::liberar();
                $exigirLeitura();
                responder(array('eventos' => $app->eventos(isset($_GET['id']) ? (string) $_GET['id'] : '')));
                break;
            case 'rede':
                Sessao::liberar();
                $exigirLeitura();
                responder($app->redeAlimentador(isset($_GET['alimentador_id']) ? (int) $_GET['alimentador_id'] : 0));
                break;
            case 'usuarios':
                Sessao::liberar();
                $exigirLogin();
                responder(array('usuarios' => $app->listarUsuarios($me)));
                break;
            case 'logs':
                Sessao::liberar();
                $exigirLogin();
                responder($app->lerLogs($me, $_GET, isset($_GET['offset']) ? (int) $_GET['offset'] : 0,
                    isset($_GET['limite']) ? (int) $_GET['limite'] : 200));
                break;
            case 'csv':
                Sessao::liberar();
                $exigirLogin();
                $nome = isset($_GET['arquivo']) && $_GET['arquivo'] === 'logs' ? 'logs' : 'registros';
                if ($nome === 'logs') {
                    $p = Regulacao::permissoes($me);
                    if (!$p['logs']) {
                        throw new ErroApi('Apenas supervisores e administradores baixam os logs.', 403);
                    }
                }
                $arq = $app->caminhoCsv($nome);
                header_remove('Pragma');
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="regulacao-coi_' . $nome . '_' . date('Y-m-d_His') . '.csv"');
                header('Content-Length: ' . filesize($arq));
                readfile($arq);
                exit;
            default:
                throw new ErroApi('Ação desconhecida.', 404);
        }
    }

    if ($metodo !== 'POST') {
        throw new ErroApi('Método não permitido.', 405);
    }

    switch ($acao) {
        case 'login':
            $u = $app->login(campo($corpo, 'usuario', ''), campo($corpo, 'senha', ''));
            Sessao::entrar($u['uid']);
            responder(array('usuario' => $u, 'csrf' => Sessao::csrf(), 'permissoes' => Regulacao::permissoes($u)));
            break;
        case 'logout':
            if ($me) {
                $app->logAvulso($me, 'acesso.logout', 'Saiu do sistema');
            }
            Sessao::sair();
            responder(array('csrf' => Sessao::csrf()));
            break;
        case 'instalar':
            $u = $app->instalar(campo($corpo, 'nome', ''), campo($corpo, 'usuario', ''), campo($corpo, 'senha', ''));
            Sessao::entrar($u['uid']);
            responder(array('usuario' => $u, 'csrf' => Sessao::csrf(), 'permissoes' => Regulacao::permissoes($u)));
            break;
        case 'senha':
            $exigirLogin();
            $app->trocarSenha($me, (string) campo($corpo, 'atual', ''), (string) campo($corpo, 'nova', ''));
            responder(array());
            break;
    }

    // Demais ações: libera a sessão (cálculo pode levar alguns segundos)
    Sessao::liberar();
    $exigirLogin();
    $id = (string) campo($corpo, 'id', '');

    switch ($acao) {
        case 'simular':
            if (!Regulacao::podeSimular($me)) {
                throw new ErroApi('Apenas engenheiro ou supervisor rodam simulações.', 403);
            }
            @set_time_limit(60);
            responder($app->simularProposta((int) campo($corpo, 'alimentador_id', 0),
                (array) campo($corpo, 'ajustes', array()), (array) campo($corpo, 'cenario', array())));
            break;
        case 'solicitar':
            responder(array('registro' => $app->solicitar($me, $corpo)));
            break;
        case 'propor':
            @set_time_limit(60);
            responder(array('registro' => $app->propor($me, (string) campo($corpo, 'registro_id', ''), $corpo)));
            break;
        case 'parecer':
            responder(array('registro' => $app->parecer($me, $id, $corpo)));
            break;
        case 'decidir':
            responder(array('registro' => $app->decidir($me, $id, (string) campo($corpo, 'decisao', ''), campo($corpo, 'comentario', ''))));
            break;
        case 'executar':
            responder(array('registro' => $app->executar($me, $id, $corpo)));
            break;
        case 'verificar':
            responder(array('registro' => $app->verificar($me, $id, $corpo)));
            break;
        case 'cancelar':
            responder(array('registro' => $app->cancelar($me, $id, campo($corpo, 'motivo', ''))));
            break;
        case 'retroativo':
            responder(array('registro' => $app->retroativo($me, $corpo)));
            break;
        case 'usuario_salvar':
            responder(array('uid' => $app->salvarUsuario($me, $id, $corpo)));
            break;
        default:
            throw new ErroApi('Ação desconhecida.', 404);
    }
} catch (ErroApi $e) {
    responder(array('erro' => $e->getMessage()), $e->status ? $e->status : 400);
} catch (RuntimeException $e) {
    // Ex.: fluxo de carga não convergiu ou topologia publicada inválida
    responder(array('erro' => $e->getMessage()), 422);
} catch (Exception $e) {
    error_log('[regulacao-coi] ' . $e->getMessage());
    responder(array('erro' => empty($config['debug']) ? 'Erro interno no servidor.' : $e->getMessage()), 500);
}
