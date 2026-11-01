<?php
/**
 * Copie para config.local.php e ajuste para este servidor.
 * Este arquivo NÃO vai para o repositório (logins e hash de senha).
 */
return array(
    'debug' => false,

    // Pasta do banco na rede (barra normal):
    // 'dir_dados' => '//servportaloper/nucleo/Regulacao_coi/data',

    // Depois da primeira publicação do servidor 1:
    // 'modo_exemplo' => false,

    // Mesmos ADMs master dos outros sistemas do COI (mesma senha):
    // 'master' => array(
    //     'usuarios'   => array('U0000000', 'U0000001'),
    //     'nomes'      => array('U0000000' => 'ADM Master'),
    //     'senha_hash' => '$2y$10$...',
    // ),

    // Senha de rede (AD) — ver DEPLOY.md:
    // 'ldap' => array('ativo' => true, 'servidor' => 'ldaps://dc01.empresa.local:636',
    //                 'dominio' => 'empresa.local', 'dominio_netbios' => 'EMPRESA'),

    // Entrar com o usuário do Windows (IIS, só no sso.php):
    // 'sso' => array('ativo' => true, 'dominios' => array('EMPRESA')),
);
