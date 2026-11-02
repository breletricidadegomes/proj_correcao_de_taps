<?php
/**
 * Copie para config.local.php e preencha neste servidor.
 * O config.local.php está no .gitignore: senhas nunca vão para o repositório.
 * Só precisa conter o que muda em relação a config.php.
 */
if (!defined('APP_TAP')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Acesso negado.');
}

return array(
    'modo_demo' => false,

    'db' => array(
        'driver'  => 'mysql',
        'host'    => '127.0.0.1',
        'banco'   => 'regulacao_tap',
        'usuario' => 'app_tap',
        'senha'   => 'senha-forte-deste-servidor',
    ),

    // Registro de manobra só com usuário autenticado pelo servidor (AD/Windows)
    'exigir_remote_user' => true,
);
