<?php
/**
 * Entrada automática com o usuário do Windows (SSO: NTLM/Kerberos).
 *
 * Só funciona no IIS com a "Autenticação do Windows" LIGADA e a "Autenticação
 * Anônima" DESLIGADA apenas para ESTE arquivo (o resto do sistema continua
 * aberto, sem login). Veja DEPLOY.md → "Login pela rede".
 * Também precisa de 'sso' => array('ativo' => true) no config.local.php.
 *
 * O botão "Entrar com o usuário do Windows" (popup de login) chama este
 * endereço; o navegador manda o usuário do Windows sozinho (sites da intranet).
 */
require __DIR__ . '/src/inicio.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function ssoResponder($dados, $status)
{
    if (function_exists('http_response_code')) {
        http_response_code($status);
    } else {
        header('X-PHP-Response-Code: ' . $status, true, $status);
    }
    $dados['ok'] = $status < 400;
    echo BancoJson::json($dados);
    exit;
}

try {
    $db = new BancoJson($config['dir_dados']);
    $app = new Regulacao($db, $config);
    $rede = $app->rede();
    if (!$rede->ssoAtivo()) {
        ssoResponder(array('erro' => 'A entrada com o usuário do Windows não está ligada (config: sso → ativo).'), 404);
    }
    Sessao::iniciar($config, $db->dir());
    $me = $app->loginWindows($rede->usuarioWindows($_SERVER));
    Sessao::entrar($me['uid']);
    $csrf = Sessao::csrf();
    Sessao::liberar();
    ssoResponder(array('usuario' => $me, 'csrf' => $csrf, 'permissoes' => Regulacao::permissoes($me)), 200);
} catch (ErroApi $e) {
    ssoResponder(array('erro' => $e->getMessage()), $e->status ? $e->status : 400);
} catch (Exception $e) {
    error_log('[regulacao-coi] sso: ' . $e->getMessage());
    ssoResponder(array('erro' => 'Erro interno no servidor.'), 500);
}
